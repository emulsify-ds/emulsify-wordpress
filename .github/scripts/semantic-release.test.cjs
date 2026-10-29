const assert = require('node:assert/strict');
const { execFileSync } = require('node:child_process');
const fs = require('node:fs');
const os = require('node:os');
const path = require('node:path');
const { test } = require('node:test');
const { assertPublishedStableTags, main, releaseOptions } = require('./semantic-release.cjs');
const releaseConfig = require('../../release.config.js');
const guard = releaseConfig.plugins.find((plugin) => typeof plugin.verifyRelease === 'function');

const githubEnv = {
  CI: 'true',
  GITHUB_TOKEN: 'fixture-token',
  GITHUB_REPOSITORY: 'emulsify-ds/emulsify-wordpress',
};

function publishedRelease(overrides = {}) {
  return {
    draft: false,
    prerelease: false,
    published_at: '2026-10-28T12:00:00Z',
    assets: [{ name: 'emulsify.zip', state: 'uploaded', size: 42 }],
    ...overrides,
  };
}

function releaseResponse(status = 200, release = publishedRelease()) {
  return { ok: status >= 200 && status < 300, status, json: async () => release };
}

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
  fs.mkdirSync(path.join(repoRoot, 'dist-artifact'));
  fs.writeFileSync(path.join(repoRoot, 'dist-artifact/emulsify.zip'), 'fixture release archive');
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
  const status = await main({ repoRoot, env: {}, args: ['--dry-run', '--debug', '--no-ci'], runRelease: () => assert.fail('An unprepared release must not invoke semantic-release.') });
  assert.equal(status, 0);
});

test('prepared release accepts a matching semantic-release result and forwards documented flags', async (t) => {
  const { repoRoot } = fixture(t);
  const status = await main({ repoRoot, env: {}, args: ['--tag-format', '${version}', '--dry-run', '--debug', '--no-ci'], runRelease: async (options, context) => {
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
    await assert.rejects(main({ repoRoot, env: {}, args: [], runRelease: async () => result }), /Prepared package.json version 2\.1\.0.*(?:computed 2\.0\.1|did not compute a release).*Conventional Commits/);
  }
});

test('refuses local-only stable tags before any publishing or skip', async (t) => {
  const { repoRoot, git } = fixture(t, '2.0.1');
  assert.doesNotThrow(() => assertPublishedStableTags(repoRoot));
  git('tag', '2.0.1');
  assert.throws(() => assertPublishedStableTags(repoRoot), /Refusing to publish local-only stable tag\(s\): 2\.0\.1/);
  await assert.rejects(main({ repoRoot, env: {}, args: [], runRelease: () => assert.fail('Local-only stable tags must block semantic-release.') }), /Refusing to publish local-only stable tag\(s\): 2\.0\.1/);
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

function advanceRemoteMain({ repoRoot, remote }) {
  const otherCheckout = path.join(path.dirname(repoRoot), 'main-mover');
  execFileSync('git', ['clone', '--quiet', remote, otherCheckout], { stdio: 'pipe' });
  const git = (...args) => execFileSync('git', args, { cwd: otherCheckout, encoding: 'utf8', stdio: 'pipe' }).trim();
  git('-c', 'user.name=Contract Test', '-c', 'user.email=contract@example.invalid', 'commit', '--allow-empty', '-m', 'docs: update contributors');
  git('push', 'origin', 'main');
  return git('rev-parse', 'HEAD');
}

test('CI refuses a moved remote main before semantic-release, even with --no-ci and a stale tracking ref', async (t) => {
  const repository = fixture(t);
  const { repoRoot, git } = repository;
  const checkedOut = git('rev-parse', 'HEAD');
  const remoteHead = advanceRemoteMain(repository);
  assert.notEqual(remoteHead, checkedOut);
  assert.equal(git('rev-parse', 'origin/main'), checkedOut, 'The regression must not refresh the stale tracking ref.');
  let released = false;
  await assert.rejects(main({
    repoRoot, env: { CI: 'true' }, args: ['--dry-run', '--no-ci'],
    runRelease: async () => { released = true; return { nextRelease: { version: '2.1.0' } }; },
  }), /main moved.*Semantic release.*workflow_dispatch.*main/i);
  assert.equal(released, false, 'The preflight must stop before semantic-release can run.');
});

test('CI accepts current main and a fresh detached workflow_dispatch after main moved', async (t) => {
  const repository = fixture(t);
  const { repoRoot, git } = repository;
  const runRelease = async (options) => {
    assert.equal(options.branches, undefined, 'CI must keep the configured main branch even with --no-ci.');
    return { nextRelease: { version: '2.1.0' } };
  };
  assert.equal(await main({ repoRoot, env: { CI: 'true' }, args: [], runRelease }), 0);
  const currentMain = advanceRemoteMain(repository);
  git('fetch', 'origin', 'main');
  git('switch', '--detach', 'origin/main');
  assert.equal(git('rev-parse', 'HEAD'), currentMain);
  assert.equal(await main({
    repoRoot, env: { CI: 'true', GITHUB_EVENT_NAME: 'workflow_dispatch', GITHUB_REF: 'refs/heads/main' },
    args: ['--dry-run', '--no-ci'], runRelease,
  }), 0);
});

test('local dry runs on a release branch do not require HEAD to equal remote main', async (t) => {
  const { repoRoot, git } = fixture(t);
  git('switch', '-c', 'release-2.1.x');
  git('-c', 'user.name=Contract Test', '-c', 'user.email=contract@example.invalid', 'commit', '--allow-empty', '-m', 'feat: prepare the next release');
  for (const env of [{}, { CI: 'false' }]) {
    assert.equal(await main({
      repoRoot, env, args: ['--dry-run', '--no-ci'],
      runRelease: async (options) => {
        assert.deepEqual(options.branches, ['release-2.1.x']);
        return { nextRelease: { version: '2.1.0' } };
      },
    }), 0);
  }
});

test('CI still skips a valid unprepared release without invoking semantic-release', async (t) => {
  const repository = fixture(t, '2.0.0');
  advanceRemoteMain(repository);
  let checked = 0;
  assert.equal(await main({
    repoRoot: repository.repoRoot, env: githubEnv, args: [],
    fetchRelease: async (url, options) => {
      checked += 1;
      assert.equal(url, 'https://api.github.com/repos/emulsify-ds/emulsify-wordpress/releases/tags/2.0.0');
      assert.equal(options.headers.Authorization, 'Bearer fixture-token');
      assert.equal(options.redirect, 'error', 'Release credentials must not follow redirects.');
      return releaseResponse();
    },
    runRelease: () => assert.fail('An unprepared release must not invoke semantic-release.'),
  }), 0);
  assert.equal(checked, 1, 'CI must verify publication health before treating the tagged version as a successful skip.');
});

test('CI refuses a missing GitHub release for a tag and reports safe recovery commands', async (t) => {
  const { repoRoot } = fixture(t, '2.0.0');
  await assert.rejects(main({
    repoRoot, env: githubEnv, args: [], fetchRelease: async () => releaseResponse(404),
    runRelease: () => assert.fail('A partial release must never invoke semantic-release.'),
  }), /release 2\.0\.0.*missing or still a draft.*gh release view 2\.0\.0.*gh release create 2\.0\.0.*--verify-tag.*--draft[\s\S]*gh release upload 2\.0\.0[\s\S]*gh release edit 2\.0\.0.*--draft=false/i);
});

test('CI refuses unpublished releases and missing or incomplete release ZIP assets', async (t) => {
  const { repoRoot } = fixture(t, '2.0.0');
  for (const release of [
    publishedRelease({ draft: true, published_at: null }),
    publishedRelease({ prerelease: true }),
    publishedRelease({ published_at: null }),
    publishedRelease({ assets: [] }),
    publishedRelease({ assets: [{ name: 'source.zip', state: 'uploaded', size: 42 }] }),
    publishedRelease({ assets: [{ name: 'emulsify.zip', state: 'starter', size: 42 }] }),
    publishedRelease({ assets: [{ name: 'emulsify.zip', state: 'uploaded', size: 0 }] }),
  ]) {
    await assert.rejects(main({
      repoRoot, env: githubEnv, args: [], fetchRelease: async () => releaseResponse(200, release),
      runRelease: () => assert.fail('A partial release must never invoke semantic-release.'),
    }), /gh release (?:upload|edit) 2\.0\.0/);
  }
});

test('CI recovery check fails closed on absent credentials, API failures, and invalid release responses', async (t) => {
  const { repoRoot } = fixture(t, '2.0.0');
  for (const env of [{ CI: 'true' }, { CI: 'true', GITHUB_TOKEN: 'fixture-token' }]) {
    await assert.rejects(main({
      repoRoot, env, args: [], fetchRelease: () => assert.fail('Missing CI configuration must fail before requesting GitHub.'),
    }), /GITHUB_TOKEN|GITHUB_REPOSITORY/);
  }
  for (const status of [401, 403, 500]) {
    await assert.rejects(main({ repoRoot, env: githubEnv, args: [], fetchRelease: async () => releaseResponse(status) }), new RegExp(`HTTP ${status}`));
  }
  await assert.rejects(main({ repoRoot, env: githubEnv, args: [], fetchRelease: async () => { throw new Error('network unavailable'); } }), /Unable to verify GitHub release.*network unavailable/);
  await assert.rejects(main({ repoRoot, env: githubEnv, args: [], fetchRelease: async () => releaseResponse(200, null) }), /Unable to verify GitHub release/);
});

test('CI diagnoses partial tagged releases before stale-draft errors but keeps the draft contract strict', async (t) => {
  const { repoRoot } = fixture(t, '2.0.0');
  fs.writeFileSync(path.join(repoRoot, 'docs/release-notes-next.md'), '# 2.0.0 Release Notes Draft');
  await assert.rejects(main({ repoRoot, env: githubEnv, args: [], fetchRelease: async () => releaseResponse(404) }), /gh release create 2\.0\.0/);
  await assert.rejects(main({ repoRoot, env: githubEnv, args: [], fetchRelease: async () => releaseResponse() }), /already-tagged release 2\.0\.0/);
});

test('local unprepared runs remain tokenless and do not query GitHub', async (t) => {
  const { repoRoot } = fixture(t, '2.0.0');
  assert.equal(await main({
    repoRoot, env: {}, args: ['--dry-run', '--no-ci'],
    fetchRelease: () => assert.fail('Local skips do not require GitHub API access.'),
    runRelease: () => assert.fail('An unprepared release must not invoke semantic-release.'),
  }), 0);
});

test('publication preflight never invokes semantic-release and leaves draft validation to readiness', async (t) => {
  const { repoRoot } = fixture(t);
  fs.writeFileSync(path.join(repoRoot, 'README.md'), 'Latest release: 1.9.9');
  assert.equal(await main({
    repoRoot, env: githubEnv, args: ['--check-publication'],
    fetchRelease: () => assert.fail('An upcoming untagged release has no GitHub release to check.'),
    runRelease: () => assert.fail('Publication preflight must never publish.'),
  }), 0);
  fs.writeFileSync(path.join(repoRoot, 'package.json'), JSON.stringify({ version: '2.0.0' }));
  await assert.rejects(main({
    repoRoot, env: githubEnv, args: ['--check-publication'], fetchRelease: async () => releaseResponse(404),
    runRelease: () => assert.fail('Publication preflight must never publish.'),
  }), /gh release create 2\.0\.0/);
  assert.equal(await main({
    repoRoot, env: githubEnv, args: ['--check-publication'], fetchRelease: async () => releaseResponse(),
    runRelease: () => assert.fail('Publication preflight must never publish.'),
  }), 0);
});

test('parses the existing publish and publish-test command options', () => {
  assert.deepEqual(releaseOptions(['--tag-format', '${version}', '--dry-run', '--debug', '--no-ci']), { tagFormat: '${version}', dryRun: true, debug: true, ci: false });
  assert.deepEqual(releaseOptions(['-t', '${version}', '-d']), { tagFormat: '${version}', dryRun: true });
  assert.deepEqual(releaseOptions(['--check-publication']), { checkPublication: true });
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
      const options = { repoRoot: process.cwd(), env: {}, args: ['--dry-run', '--no-ci'], runRelease };
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
