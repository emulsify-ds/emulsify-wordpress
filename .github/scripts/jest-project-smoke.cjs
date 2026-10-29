#!/usr/bin/env node

const assert = require('node:assert/strict');
const { spawnSync } = require('node:child_process');
const fs = require('node:fs');
const os = require('node:os');
const path = require('node:path');

const repoRoot = path.resolve(__dirname, '../..');
const starter = path.join(repoRoot, 'whisk');
const modules = path.join(starter, 'node_modules');
assert.ok(fs.existsSync(path.join(modules, 'jest/bin/jest.js')), 'Install Whisk dependencies before running the project Jest smoke.');
const work = fs.realpathSync(fs.mkdtempSync(path.join(os.tmpdir(), 'emulsify-jest-project-')));
const theme = path.join(work, 'generated-theme');
const results = path.join(work, 'results');
const resultFile = path.join(results, 'tests.json');
const component = path.join(theme, 'src/components/ci-jest');
const sourceFile = path.join(component, 'jest-value.js');
const testFile = path.join(component, 'jest-value.test.js');
const untestedFile = path.join(component, 'jest-untested.mjs');
const npm = process.platform === 'win32' ? 'npm.cmd' : 'npm';

function run(script = 'test', extra = []) {
  fs.rmSync(resultFile, { force: true });
  const result = spawnSync(npm, ['run', script, '--', '--runInBand', '--json', `--outputFile=${resultFile}`, `--coverageDirectory=${results}`, ...extra], {
    cwd: theme,
    encoding: 'utf8',
    env: { ...process.env, CI: '1', FORCE_COLOR: '0' },
    timeout: 60000,
  });
  assert.ifError(result.error);
  assert.equal(result.signal, null, `Jest exited on signal ${result.signal}.`);
  return {
    status: result.status,
    output: result.stdout + result.stderr,
    report: fs.existsSync(resultFile) ? JSON.parse(fs.readFileSync(resultFile, 'utf8')) : null,
  };
}

try {
  const excluded = new Set(['.git', 'node_modules', 'dist', '.out', '.coverage', '.cache']);
  fs.cpSync(starter, theme, { recursive: true, filter: (file) => !excluded.has(path.basename(file)) });
  const projectPath = path.join(theme, 'project.emulsify.json');
  const project = JSON.parse(fs.readFileSync(projectPath, 'utf8'));
  Object.assign(project.project, { name: 'Project Jest Fixture', machineName: 'project-jest-fixture' });
  fs.writeFileSync(projectPath, JSON.stringify(project));
  const generation = spawnSync(process.execPath, [path.join(theme, '.cli/init.js')], { cwd: theme, encoding: 'utf8' });
  assert.equal(generation.status, 0, generation.stdout + generation.stderr);
  fs.symlinkSync(modules, path.join(theme, 'node_modules'), 'junction');
  fs.mkdirSync(results);
  fs.cpSync(path.join(repoRoot, '.github/fixtures/whisk-a11y/ci-readiness'), component, { recursive: true });

  let result = run();
  assert.equal(result.status, 0, result.output);
  assert.ok(result.report.testResults.some((entry) => entry.name === testFile && entry.status === 'passed'), 'Jest must execute the colocated project ESM test.');
  const coverage = JSON.parse(fs.readFileSync(path.join(results, 'coverage-final.json'), 'utf8'));
  assert.ok(coverage[sourceFile], 'Coverage must include executed project source.');
  assert.ok(Object.values(coverage[sourceFile].s).some((count) => count > 0), 'Executed source must have covered statements.');
  assert.ok(coverage[untestedFile], 'Coverage must include untested project source.');
  assert.ok(Object.values(coverage[untestedFile].s).every((count) => count === 0), 'Untested project statements must remain uncovered.');
  assert.ok(!Object.keys(coverage).some((file) => /\/(?:node_modules|config)\/|\.(?:test|stories)\./.test(file)), 'Coverage must exclude dependencies, configuration, tests, and stories.');

  result = run('twatch', ['--watch=false']);
  assert.equal(result.status, 0, result.output);
  assert.ok(result.report.testResults.some((entry) => entry.name === testFile && entry.status === 'passed'), 'The watch wrapper must also execute native ESM.');

  const passing = fs.readFileSync(testFile, 'utf8');
  const failing = passing.replace('toBe(42)', 'toBe(0)');
  assert.notEqual(failing, passing, 'The deliberate failure must change the fixture assertion.');
  fs.writeFileSync(testFile, failing);
  result = run();
  assert.notEqual(result.status, 0, 'A failing project test must fail npm test.');
  assert.ok(result.report.testResults.some((entry) => entry.name === testFile && entry.status === 'failed'), 'The deliberate failure must come from the discovered project test.');

  fs.rmSync(component, { recursive: true, force: true });
  result = run();
  assert.notEqual(result.status, 0, 'An empty generated project must fail npm test.');
  assert.match(result.output, /No tests found/, 'The empty project must fail because no tests exist.');
  console.log('PASS Generated project Jest ESM, coverage, watch wrapper, failure propagation, and empty-test handling.');
} finally {
  fs.rmSync(work, { recursive: true, force: true });
}
