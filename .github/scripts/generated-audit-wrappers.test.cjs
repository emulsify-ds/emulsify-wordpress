#!/usr/bin/env node

// Opt-in integration: generate the real child theme and install a packed Core
// artifact. No network installation is added to the ordinary contract tests.
const assert = require('node:assert/strict');
const { spawnSync } = require('node:child_process');
const { createHash } = require('node:crypto');
const fs = require('node:fs');
const os = require('node:os');
const path = require('node:path');
const { test } = require('node:test');

const repoRoot = path.resolve(__dirname, '../..');
const excluded = new Set(['.git', 'node_modules', 'dist', '.out', '.coverage', '.cache']);
const npm = process.platform === 'win32' ? 'npm.cmd' : 'npm';
const npx = process.platform === 'win32' ? 'npx.cmd' : 'npx';

function writeJson(file, value) {
  fs.writeFileSync(file, `${JSON.stringify(value, null, 2)}\n`);
}

function run(command, args, cwd, env) {
  const result = spawnSync(command, args, {
    cwd,
    env,
    encoding: 'utf8',
    timeout: 180000,
    maxBuffer: 8 * 1024 * 1024,
  });
  assert.ifError(result.error);
  assert.equal(result.signal, null, `Unexpected signal: ${result.signal}`);
  return result;
}

function assertWrapper(result, direct, footer) {
  assert.equal(result.status, direct.status, 'Wrapper must preserve the exact Core exit status.');
  let report;
  assert.doesNotThrow(() => {
    // Parsing a substring or dropping a footer would conceal this regression.
    report = JSON.parse(result.stdout);
  }, 'The entire stdout stream must be valid JSON.');
  assert.deepEqual(report, JSON.parse(direct.stdout), 'Wrapper must forward every argument unchanged.');
  assert.equal(report.schemaVersion, 1);
  assert.equal(report.tool.name, '@emulsify/core');
  assert.equal(result.stderr, `${direct.stderr}\n${footer}\n`, 'The exact documentation footer belongs on stderr.');
  assert.ok(!result.stdout.includes(footer), 'Documentation footer must not enter stdout.');
}

test('real generated audit wrappers retain the packed Core CLI contract', { timeout: 240000 }, async (t) => {
  assert.ok(process.env.EMULSIFY_CORE_TARBALL, 'Set EMULSIFY_CORE_TARBALL to a local npm pack .tgz artifact.');
  const tarball = path.resolve(process.env.EMULSIFY_CORE_TARBALL);
  const tarballHash = createHash('sha256').update(fs.readFileSync(tarball)).digest('hex');
  const workRoot = fs.mkdtempSync(path.join(os.tmpdir(), 'emulsify-generated-audit-'));
  const consumer = path.join(workRoot, 'generated child with spaces');
  const env = {
    ...process.env,
    CI: '1',
    FORCE_COLOR: '0',
    PUPPETEER_SKIP_DOWNLOAD: 'true',
    npm_config_cache: process.env.npm_config_cache || path.join(workRoot, 'npm-cache'),
  };

  try {
    fs.cpSync(path.join(repoRoot, 'whisk'), consumer, {
      recursive: true,
      filter: (source) => !excluded.has(path.basename(source)),
    });
    const projectPath = path.join(consumer, 'project.emulsify.json');
    const project = JSON.parse(fs.readFileSync(projectPath, 'utf8'));
    Object.assign(project.project, {
      name: 'Audit Fixture', machineName: 'audit-fixture', description: 'Generated audit wrapper contract.',
    });
    writeJson(projectPath, project);
    const generation = run(process.execPath, [path.join(consumer, '.cli/init.js')], consumer, env);
    assert.equal(generation.status, 0, generation.stderr);
    const packagePath = path.join(consumer, 'package.json');
    const generated = JSON.parse(fs.readFileSync(packagePath, 'utf8'));
    const source = JSON.parse(fs.readFileSync(path.join(repoRoot, 'whisk/package.json'), 'utf8'));
    assert.deepEqual(generated.scripts, source.scripts, 'Generation must retain the real starter scripts.');
    const install = run(npm, ['install', '--ignore-scripts', '--no-audit', '--no-fund', '--package-lock=false', '--save-exact', tarball], consumer, env);
    assert.equal(install.status, 0, install.stderr);
    const installed = JSON.parse(fs.readFileSync(packagePath, 'utf8'));
    assert.deepEqual(installed.scripts, generated.scripts);
    const coreRoot = path.join(consumer, 'node_modules/@emulsify/core');
    assert.equal(fs.lstatSync(coreRoot).isSymbolicLink(), false, 'Use an installed package, not a source symlink.');
    const coreVersion = JSON.parse(fs.readFileSync(path.join(coreRoot, 'package.json'), 'utf8')).version;
    t.diagnostic(JSON.stringify({ coreVersion, tarballSha256: tarballHash, generatedScripts: { audit: generated.scripts.audit, 'audit:twig-stories': generated.scripts['audit:twig-stories'] } }));

    const auditRoot = path.join(workRoot, 'audit root with spaces');
    const component = path.join(auditRoot, 'src/components/card');
    fs.mkdirSync(component, { recursive: true });
    writeJson(path.join(auditRoot, 'project.emulsify.json'), { project: { platform: 'none' } });
    fs.writeFileSync(path.join(component, 'card.twig'), '<p>{{ title }}</p>');
    fs.writeFileSync(path.join(component, 'card.stories.js'), 'import cardTwig from "./card.twig";\nexport const Card = (args) => cardTwig(args);\n');
    const cleanRoot = path.join(workRoot, 'clean root with spaces');
    const cleanComponent = path.join(cleanRoot, 'src/components/clean');
    fs.mkdirSync(cleanComponent, { recursive: true });
    writeJson(path.join(cleanRoot, 'project.emulsify.json'), { project: { platform: 'none' } });
    fs.writeFileSync(path.join(cleanComponent, 'clean.stories.js'), 'export default { title: "Audit/Clean" };\nexport const Clean = () => "<p>Clean</p>";\n');

    for (const [script, executable, footer, threshold] of [
      ['audit', 'audit.js', 'Audit docs: https://github.com/emulsify-ds/emulsify-core/blob/4.x/docs/migration-4x.md#storybook-migration', ['--fail-on', 'warn']],
      ['audit:twig-stories', 'audit-twig-stories.js', 'Migration docs: https://github.com/emulsify-ds/emulsify-core/blob/4.x/docs/storybook.md#legacy-twig-story-compatibility', ['--fail-on-found']],
    ]) {
      const args = ['--root', auditRoot, '--json'];
      const directRun = (options) => run(path.join(coreRoot, 'scripts', executable), options, consumer, env);
      const wrapperRun = (options) => run(npm, ['run', script, '--silent', '--', ...options], consumer, env);
      for (const [scenario, options, expectedStatus] of [
        ['clean scan', ['--root', cleanRoot, '--json', ...threshold], 0],
        ['advisory findings', args, 0],
        ['findings fail threshold', [...args, ...threshold], 1],
        ['invalid argument', [...args, '--unknown-wrapper-option'], 2],
      ]) {
        await t.test(`${script}: ${scenario}`, () => {
          const direct = directRun(options);
          assert.equal(direct.status, expectedStatus, direct.stderr);
          const report = JSON.parse(direct.stdout);
          if (scenario === 'clean scan') {
            assert.deepEqual(report.findings, []);
          } else if (expectedStatus !== 2) {
            assert.ok(report.findings.some((finding) => finding.id === 'legacy-twig-story' && finding.path === 'src/components/card/card.stories.js' && finding.line === 2 && finding.severity === 'warn'));
          }
          const wrapped = wrapperRun(options);
          assertWrapper(wrapped, direct, footer);
          const executableName = executable === 'audit.js' ? 'emulsify-audit' : 'emulsify-audit-twig-stories';
          const directAlias = run(npx, ['--no-install', executableName, ...options], consumer, env);
          assert.equal(directAlias.status, expectedStatus);
          assert.deepEqual(JSON.parse(directAlias.stdout), report);
          assert.equal(directAlias.stderr, direct.stderr);
          t.diagnostic(JSON.stringify({ script, scenario, options, directStatus: direct.status, wrapperStatus: wrapped.status, npxNoInstallStatus: directAlias.status, wholeStdoutJson: true, footerOnStderr: true }));
        });
      }

      for (const [scenario, mutate, expectedFailure] of [
        ['rejects stdout footer', (command) => command.replace(' >&2;', ';'), /entire stdout stream/],
        ['rejects masked status', (command) => command.replace('exit $status', 'exit 0'), /exact Core exit status/],
        ['rejects dropped arguments', (command) => command.replace('"$@"', ''), /exact Core exit status|forward every argument/],
      ]) {
        await t.test(`${script}: ${scenario}`, () => {
          const modified = structuredClone(installed);
          modified.scripts[script] = mutate(modified.scripts[script]);
          assert.notEqual(modified.scripts[script], installed.scripts[script]);
          writeJson(packagePath, modified);
          try {
            const options = [...args, ...threshold];
            assert.throws(() => assertWrapper(wrapperRun(options), directRun(options), footer), expectedFailure);
          } finally {
            writeJson(packagePath, installed);
          }
        });
      }
    }
  } finally {
    fs.rmSync(workRoot, { recursive: true, force: true });
  }
});
