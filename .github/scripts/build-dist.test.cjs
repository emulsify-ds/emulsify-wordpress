const assert = require('node:assert/strict');
const { execFileSync, spawnSync } = require('node:child_process');
const fs = require('node:fs');
const os = require('node:os');
const path = require('node:path');
const { test } = require('node:test');

const builder = fs.readFileSync(path.resolve(__dirname, '../../scripts/build-dist.sh'), 'utf8');

function fixture(t) {
  const root = fs.mkdtempSync(path.join(os.tmpdir(), 'emulsify-build-dist-'));
  t.after(() => fs.rmSync(root, { recursive: true, force: true }));
  const repo = path.join(root, 'checkout');
  const bin = path.join(root, 'bin');
  const composerLog = path.join(root, 'composer.json');
  fs.mkdirSync(repo);
  fs.mkdirSync(bin);
  const write = (relative, contents) => {
    const file = path.join(repo, relative);
    fs.mkdirSync(path.dirname(file), { recursive: true });
    fs.writeFileSync(file, contents);
  };
  const git = (...args) => execFileSync('git', [
    '-c', 'commit.gpgsign=false', '-c', 'tag.gpgSign=false',
    '-c', 'user.name=Distribution Test', '-c', 'user.email=dist@example.invalid',
    ...args,
  ], { cwd: repo, encoding: 'utf8', stdio: 'pipe' }).trim();

  for (const relative of [
    '404.php', 'AGENTS.md', 'LICENSE', 'README.md', 'UPGRADE.md', 'archive.php',
    'author.php', 'functions.php', 'index.php', 'page.php', 'screenshot.png',
    'search.php', 'single.php', 'style.css', 'theme.json',
    'docs/wp-cli-child-theme-generation.md', 'includes/sentinel.php',
    'src/sentinel.txt', 'templates/sentinel.twig', 'whisk/README.md',
    'whisk/AGENTS.md', 'whisk/.agents/skills/emulsify-wordpress-integration/SKILL.md',
    'whisk/docs/wordpress-integration.md', 'whisk/package.json',
    'whisk/project.emulsify.json', 'whisk/style.css', 'whisk/.cli/init.js',
  ]) {
    write(relative, `committed ${relative}\n`);
  }
  write('composer.json', '{"name":"fixture/production"}\n');
  write('composer.lock', '{"committed":true}\n');
  // The release builder must read these Git objects even though git archive
  // would omit them. Root Composer metadata is needed only during installation.
  write('.gitattributes', '/composer.lock export-ignore\n/whisk/package.json export-ignore\n');
  write('.gitignore', 'dist-artifact/\n');
  write('scripts/build-dist.sh', builder);
  git('init', '-b', 'main');
  git('add', '.');
  git('commit', '-m', 'chore: create distribution fixture');

  const composer = path.join(bin, 'composer');
  fs.writeFileSync(composer, `#!/usr/bin/env node
const fs = require('node:fs');
const path = require('node:path');
const root = process.argv.find((argument) => argument.startsWith('--working-dir=')).slice('--working-dir='.length);
fs.writeFileSync(process.env.EMULSIFY_BUILD_TEST_COMPOSER_LOG, JSON.stringify({
  lock: fs.readFileSync(path.join(root, 'composer.lock'), 'utf8'),
  manifest: fs.readFileSync(path.join(root, 'composer.json'), 'utf8'),
}));
fs.mkdirSync(path.join(root, 'vendor'), { recursive: true });
fs.writeFileSync(path.join(root, 'vendor/autoload.php'), '<?php // production fixture\\n');
`);
  fs.chmodSync(composer, 0o755);
  const archive = path.join(repo, 'dist-artifact/emulsify.zip');
  const run = () => spawnSync('bash', ['scripts/build-dist.sh'], {
    cwd: repo,
    encoding: 'utf8',
    env: {
      ...process.env,
      PATH: `${bin}${path.delimiter}${process.env.PATH}`,
      EMULSIFY_BUILD_TEST_COMPOSER_LOG: composerLog,
    },
  });
  const contents = (relative) => execFileSync('unzip', ['-p', archive, `emulsify/${relative}`], { encoding: 'utf8' });
  const list = () => execFileSync('unzip', ['-Z1', archive], { encoding: 'utf8' }).trim().split('\n');
  return { repo, write, git, run, archive, contents, list, composerLog };
}

function assertBuilt(result) {
  assert.equal(result.status, 0, `${result.stdout}\n${result.stderr}`);
}

test('distribution uses committed bytes and excludes staged or untracked additions', (t) => {
  const build = fixture(t);
  build.write('includes/sentinel.php', 'uncommitted runtime edit\n');
  build.write('includes/staged-secret.php', 'staged secret\n');
  build.write('whisk/untracked-secret.txt', 'untracked secret\n');
  build.git('add', 'includes/sentinel.php', 'includes/staged-secret.php');
  assertBuilt(build.run());
  assert.equal(build.contents('includes/sentinel.php'), 'committed includes/sentinel.php\n');
  assert.ok(!build.list().includes('emulsify/includes/staged-secret.php'));
  assert.ok(!build.list().includes('emulsify/whisk/untracked-secret.txt'));
});

test('distribution installs committed Composer metadata without export-ignore losses', (t) => {
  const build = fixture(t);
  build.write('composer.lock', '{"uncommitted":true}\n');
  build.write('composer.json', '{"name":"uncommitted/production"}\n');
  assertBuilt(build.run());
  assert.deepEqual(JSON.parse(fs.readFileSync(build.composerLog, 'utf8')), {
    lock: '{"committed":true}\n', manifest: '{"name":"fixture/production"}\n',
  });
  assert.equal(build.contents('whisk/package.json'), 'committed whisk/package.json\n');
  const entries = build.list();
  assert.ok(entries.includes('emulsify/whisk/.agents/skills/emulsify-wordpress-integration/SKILL.md'));
  assert.ok(entries.includes('emulsify/vendor/autoload.php'));
  assert.ok(!entries.some((entry) => /(?:^|\/)composer\.(?:json|lock)$|\/\.cli\//.test(entry)));
});

test('distribution refuses committed symlinks instead of copying external contents', (t) => {
  const build = fixture(t);
  const external = path.join(path.dirname(build.repo), 'private.txt');
  fs.writeFileSync(external, 'must never ship\n');
  fs.symlinkSync(external, path.join(build.repo, 'includes/external.txt'));
  build.git('add', 'includes/external.txt');
  build.git('commit', '-m', 'test: add symlink fixture');
  const result = build.run();
  assert.notEqual(result.status, 0, 'A tracked symlink must abort packaging before its target is copied.');
  assert.match(result.stderr, /Refusing.*(?:tracked|committed).*symlink/i);
  assert.match(result.stderr, /includes\/external\.txt/);
  assert.ok(!fs.existsSync(build.archive));
  assert.ok(!fs.existsSync(build.composerLog), 'Symlink rejection must precede Composer installation.');
});

test('distribution stays on its starting commit if HEAD moves during staging', (t) => {
  const build = fixture(t);
  const actualGit = execFileSync('sh', ['-c', 'command -v git'], { encoding: 'utf8' }).trim();
  const bin = path.join(path.dirname(build.repo), 'bin');
  const marker = path.join(bin, 'moved-head');
  const gitShim = path.join(bin, 'git');
  fs.writeFileSync(gitShim, `#!/usr/bin/env node
const { execFileSync, spawnSync } = require('node:child_process');
const fs = require('node:fs');
const args = process.argv.slice(2);
const git = ${JSON.stringify(actualGit)};
const root = ${JSON.stringify(build.repo)};
const marker = ${JSON.stringify(marker)};
if (args.includes('cat-file') && !fs.existsSync(marker)) {
  fs.writeFileSync(marker, 'moved');
  fs.writeFileSync(root + '/includes/sentinel.php', 'new commit during staging\\n');
  execFileSync(git, ['-C', root, 'add', 'includes/sentinel.php']);
  execFileSync(git, ['-C', root, '-c', 'commit.gpgsign=false', '-c', 'user.name=Distribution Test', '-c', 'user.email=dist@example.invalid', 'commit', '-m', 'test: simulate concurrent commit']);
}
const result = spawnSync(git, args, { stdio: 'inherit' });
process.exit(result.status ?? 1);
`);
  fs.chmodSync(gitShim, 0o755);
  const original = build.git('rev-parse', 'HEAD');
  assertBuilt(build.run());
  assert.ok(fs.existsSync(marker), 'The fixture must move HEAD before the remaining payload is staged.');
  assert.notEqual(build.git('rev-parse', 'HEAD'), original);
  assert.equal(build.contents('includes/sentinel.php'), 'committed includes/sentinel.php\n');
});
