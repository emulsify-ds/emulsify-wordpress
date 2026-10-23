const assert = require('node:assert/strict');
const { execFileSync } = require('node:child_process');
const fs = require('node:fs');
const os = require('node:os');
const path = require('node:path');
const { test } = require('node:test');
const { assertPublishedStableTags, main, releaseOptions } = require('./semantic-release.cjs');
const releaseConfig = require('../../release.config.js');
const guard = releaseConfig.plugins.find((plugin) => typeof plugin.verifyRelease === 'function');

function fixture(t, version = '2.1.0') {
  const workRoot = fs.mkdtempSync(path.join(os.tmpdir(), 'emulsify-publish-contract-'));
  t.after(() => fs.rmSync(workRoot, { recursive: true, force: true }));
  const repoRoot = path.join(workRoot, 'checkout');
  const remote = path.join(workRoot, 'origin.git');
  fs.mkdirSync(repoRoot);
  const git = (...args) => execFileSync('git', args, { cwd: repoRoot, encoding: 'utf8', stdio: 'pipe' }).trim();
  // Keep remote HEAD valid even when the runner defaults to another branch.
  git('-c', 'init.defaultBranch=legacy', 'init', '--bare', '-b', 'main', remote);
  git('init', '-b', 'main');
  git('-c', 'user.name=Contract Test', '-c', 'user.email=contract@example.invalid', 'commit', '--allow-empty', '-m', 'chore: initial release');
  git('-c', 'user.name=Contract Test', '-c', 'user.email=contract@example.invalid', 'tag', '-a', '2.0.0', '-m', '2.0.0');
  git('remote', 'add', 'origin', remote);
  git('push', 'origin', 'main', '--tags');
  fs.mkdirSync(path.join(repoRoot, 'docs'));
  for (const file of ['README.md', 'docs/release-process.md', 'docs/release-notes-next.md']) {
    fs.writeFileSync(path.join(repoRoot, file), '');
  }
  fs.writeFileSync(path.join(repoRoot, 'package.json'), JSON.stringify({ name: 'release-contract', version }));
  return { repoRoot, remote, git };
}

test('publish guard accepts the exact prepared version on every subsequent release', (t) => {
  const { repoRoot } = fixture(t);
  guard.verifyRelease({}, { cwd: repoRoot, nextRelease: { version: '2.1.0' } });
  assert.throws(() => guard.verifyRelease({}, { cwd: repoRoot, nextRelease: { version: '2.0.1' } }), /Prepared package.json version 2\.1\.0.*computed 2\.0\.1.*release PR/);
  fs.writeFileSync(path.join(repoRoot, 'package.json'), JSON.stringify({ version: '3.0.0' }));
  assert.throws(() => guard.verifyRelease({}, { cwd: repoRoot, lastRelease: { version: '2.1.0' }, nextRelease: { version: '2.2.0' } }), /Prepared package.json version 3\.0\.0.*computed 2\.2\.0/);
});

test('skips publishing successfully when package.json equals the latest tag', async (t) => {
  const { repoRoot } = fixture(t, '2.0.0');
  const status = await main({ repoRoot, args: ['--dry-run', '--debug', '--no-ci'], runRelease: () => assert.fail('An unprepared release must not invoke semantic-release.') });
  assert.equal(status, 0);
});

test('prepared release accepts a matching semantic-release result and forwards documented flags', async (t) => {
  const { repoRoot } = fixture(t);
  const status = await main({ repoRoot, args: ['--tag-format', '${version}', '--dry-run', '--debug', '--no-ci'], runRelease: async (options, context) => {
    assert.equal(context.cwd, repoRoot);
    assert.equal(options.tagFormat, '${version}');
    assert.equal(options.dryRun, true);
    assert.equal(options.debug, true);
    assert.equal(options.ci, false);
    guard.verifyRelease({}, { ...context, nextRelease: { version: '2.1.0' } });
    return { nextRelease: { version: '2.1.0' } };
  } });
  assert.equal(status, 0);
});

test('prepared release rejects mismatched or absent semantic-release results', async (t) => {
  const { repoRoot } = fixture(t);
  for (const result of [false, { nextRelease: { version: '2.0.1' } }]) {
    await assert.rejects(main({ repoRoot, args: [], runRelease: async () => result }), /Prepared package.json version 2\.1\.0.*(?:computed 2\.0\.1|did not compute a release).*Conventional Commits/);
  }
});

test('refuses local-only stable tags before any publishing or skip', async (t) => {
  const { repoRoot, git } = fixture(t, '2.0.1');
  assert.doesNotThrow(() => assertPublishedStableTags(repoRoot));
  git('tag', '2.0.1');
  assert.throws(() => assertPublishedStableTags(repoRoot), /Refusing to publish local-only stable tag\(s\): 2\.0\.1/);
  await assert.rejects(main({ repoRoot, args: [], runRelease: () => assert.fail('Local-only stable tags must block semantic-release.') }), /Refusing to publish local-only stable tag\(s\): 2\.0\.1/);
});

test('also refuses local-only stable tags on unmerged branches', (t) => {
  const { repoRoot, git } = fixture(t);
  git('switch', '-c', 'unmerged');
  git('-c', 'user.name=Contract Test', '-c', 'user.email=contract@example.invalid', 'commit', '--allow-empty', '-m', 'chore: unmerged work');
  git('tag', '2.0.1');
  git('switch', 'main');
  assert.equal(git('tag', '--merged', 'HEAD'), '2.0.0');
  assert.throws(() => assertPublishedStableTags(repoRoot), /Refusing to publish local-only stable tag\(s\): 2\.0\.1/);
});

test('parses the existing publish and publish-test command options', () => {
  assert.deepEqual(releaseOptions(['--tag-format', '${version}', '--dry-run', '--debug', '--no-ci']), { tagFormat: '${version}', dryRun: true, debug: true, ci: false });
  assert.deepEqual(releaseOptions(['-t', '${version}', '-d']), { tagFormat: '${version}', dryRun: true });
});

test('real semantic-release dry run analyzes a pushed release PR branch and blocks mismatches before tagging', (t) => {
  const { repoRoot, remote, git } = fixture(t);
  fs.symlinkSync(path.resolve(__dirname, '../../node_modules'), path.join(repoRoot, 'node_modules'), 'dir');
  git('switch', '-c', 'release-2.1.x');
  git('-c', 'user.name=Contract Test', '-c', 'user.email=contract@example.invalid', 'commit', '--allow-empty', '-m', 'feat: add a project capability');
  git('push', 'origin', 'release-2.1.x');
  // semantic-release hooks stdout, so run it outside node:test's event stream.
  const script = `
    const assert = require('node:assert/strict');
    const fs = require('node:fs');
    const { main } = require(${JSON.stringify(path.join(__dirname, 'semantic-release.cjs'))});
    const config = require(${JSON.stringify(path.resolve(__dirname, '../../release.config.js'))});
    (async () => {
      const { default: semanticRelease } = await import(${JSON.stringify(require.resolve('semantic-release'))});
      const guard = config.plugins.find((plugin) => typeof plugin.verifyRelease === 'function');
      const runRelease = (options, context) => semanticRelease({
        ...config, ...options, repositoryUrl: ${JSON.stringify(remote)}, plugins: [config.plugins[0], guard],
      }, context);
      const options = { repoRoot: process.cwd(), args: ['--dry-run', '--no-ci'], runRelease };
      assert.equal(await main(options), 0);
      fs.writeFileSync('package.json', JSON.stringify({ version: '2.0.1' }));
      await assert.rejects(main(options), /Prepared package.json version 2\\.0\\.1.*computed 2\\.1\\.0/);
    })().catch((error) => { console.error(error); process.exitCode = 1; });
  `;
  // CI provider branch variables and node:test IPC belong to the outer repo.
  const env = { PATH: process.env.PATH, HOME: process.env.HOME };
  execFileSync(process.execPath, ['-e', script], { cwd: repoRoot, env, stdio: 'pipe' });
  assert.equal(git('tag', '--list'), '2.0.0');
});
