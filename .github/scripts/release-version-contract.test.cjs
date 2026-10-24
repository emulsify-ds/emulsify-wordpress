const assert = require('node:assert/strict');
const { execFileSync } = require('node:child_process');
const fs = require('node:fs');
const os = require('node:os');
const path = require('node:path');
const { pathToFileURL } = require('node:url');
const test = require('node:test');
const { validateReleaseVersionContract, validateReleaseVersions } = require('./release-version-contract.cjs');

function fixture(overrides = {}) {
  return {
    tags: ['1.0.0-alpha.4', '2.0.0', '1.9.9'],
    packageVersion: '2.0.0',
    documents: {
      'README.md': 'Emulsify WordPress is the parent theme. Current release: `2.0.0`.',
      'docs/release-notes-next.md': '# 2.1.0 Release Notes Draft\n\nRequires Core 4.3.2. Latest published tag: 2.0.0.',
      'docs/release-process.md': 'Review commits since the latest tag.',
    },
    ...overrides,
  };
}

function validate(overrides) {
  return validateReleaseVersions(fixture(overrides));
}

test('accepts the latest tag and exact untagged next patch, minor, or major', () => {
  for (const packageVersion of ['2.0.0', '2.0.1', '2.1.0', '3.0.0']) {
    const result = validate({ packageVersion });
    assert.deepEqual(result.errors, []);
    assert.equal(result.latestStableTag, '2.0.0');
    assert.equal(result.releasePrepared, packageVersion !== '2.0.0');
  }
});

test('rejects old versions and skipped increments', () => {
  for (const packageVersion of ['1.9.9', '1.9.8', '2.0.2', '2.1.1', '2.2.0', '3.0.1', '4.0.0']) {
    const result = validate({ packageVersion });
    assert.match(result.errors.join('\n'), /untagged next patch, minor, or major/);
    assert.equal(result.releasePrepared, false);
  }
});

test('rejects a proposed next version that is already tagged', () => {
  for (const packageVersion of ['2.0.1', '2.1.0', '3.0.0']) {
    assert.match(validate({
      tags: ['2.0.0', packageVersion],
      latestStableTag: '2.0.0',
      packageVersion,
    }).errors.join('\n'), /untagged next patch, minor, or major/);
  }
});

test('rejects prereleases and noncanonical package versions', () => {
  for (const packageVersion of [
    undefined, null, 2.1, '', '2', '2.1', 'v2.1.0', '2.1.0-beta.1',
    '2.1.0+build.1', '2.1.0.1', '02.1.0', '2.01.0', '2.1.00', ' 2.1.0', '2.1.0\n',
    '9007199254740992.0.0',
  ]) {
    assert.match(validate({ packageVersion }).errors.join('\n'), /canonical stable x.y.z/);
  }
});

test('finds the latest stable unprefixed tag using numeric version order', () => {
  const result = validate({
    tags: ['2.9.0', 'v10.0.0', '2.10.0-beta.1', '2.10.0', '02.20.0'],
    packageVersion: '2.10.0',
    documents: {},
  });
  assert.deepEqual(result.errors, []);
  assert.equal(result.latestStableTag, '2.10.0');
  assert.deepEqual(result.stableTags, ['2.10.0', '2.9.0']);
});

test('fails clearly when full tags contain no stable release', () => {
  for (const tags of [[], ['v2.0.0', '2.1.0-beta.1']]) {
    assert.match(validate({ tags }).errors.join('\n'), /No stable release tag.*full Git history and tags/);
  }
});

test('rejects already-shipped draft headings and release titles, including prereleases', () => {
  for (const draft of [
    '# 2.0.0 Release Notes Draft',
    '## 1.9.9',
    '# Unreleased\nTitle: `emulsify 2.0.0`',
    '# Unreleased\nTitle: `v1.9.9`',
    '# 1.0.0-alpha.4 Release Notes',
  ]) {
    assert.match(validate({ documents: { 'docs/release-notes-next.md': draft } }).errors.join('\n'), /already-tagged release/);
  }
});

test('accepts historical upgrade headings, compatibility text, and current published-tag labels', () => {
  assert.deepEqual(validate({ documents: {
    'UPGRADE.md': '## Upgrading from 1.9.9 to 2.0.0',
    'docs/release-notes-next.md': '# Unreleased\nRequires Core 4.3.2. Follow the upgrade notes from 2.0.0.\nLatest published tag: `2.0.0`.',
  } }).errors, []);
});

test('rejects stale latest/current release claims in each release document', () => {
  for (const file of ['README.md', 'docs/release-notes-next.md', 'docs/release-process.md']) {
    for (const claim of [
      'Latest release: 1.9.9', 'Current release: **1.9.9**', 'Latest stable tag: 1.9.9',
      'Latest published tag: `1.9.9`', 'current version: v1.9.9',
      'Latest release: 2.0.0-beta.1',
    ]) {
      assert.match(validate({ documents: { [file]: claim } }).errors.join('\n'), /stale current release/);
    }
  }
});

test('keeps a prepared package valid after its tag is published', () => {
  assert.deepEqual(validate({ packageVersion: '2.1.0', documents: {} }).errors, []);
  const result = validate({ tags: ['2.1.0', '2.0.0'], packageVersion: '2.1.0', documents: {} });
  assert.deepEqual(result.errors, []);
  assert.equal(result.releasePrepared, false);
  assert.match(validate({ tags: ['2.1.0', '2.0.0'], packageVersion: '2.0.0', documents: {} }).errors.join('\n'), /latest stable tag 2.1.0/);
});

function git(repoRoot, ...args) {
  return execFileSync('git', args, { cwd: repoRoot, encoding: 'utf8', stdio: ['ignore', 'pipe', 'pipe'] }).trim();
}

function repositoryFixture(t) {
  const workRoot = fs.mkdtempSync(path.join(os.tmpdir(), 'emulsify-release-version-'));
  t.after(() => fs.rmSync(workRoot, { recursive: true, force: true }));
  const repoRoot = path.join(workRoot, 'repository');
  fs.mkdirSync(path.join(repoRoot, 'docs'), { recursive: true });
  const { documents, packageVersion } = fixture();
  for (const [file, contents] of Object.entries(documents)) fs.writeFileSync(path.join(repoRoot, file), contents);
  fs.writeFileSync(path.join(repoRoot, 'package.json'), JSON.stringify({ version: packageVersion }));
  git(repoRoot, 'init', '--quiet');
  git(repoRoot, 'add', '.');
  git(repoRoot, '-c', 'user.name=Release Test', '-c', 'user.email=release@example.test', 'commit', '--quiet', '-m', 'test: create release fixture');
  return { workRoot, repoRoot };
}

test('reads package metadata, real Git tags, and release documents from a repository', (t) => {
  const { repoRoot } = repositoryFixture(t);
  git(repoRoot, 'tag', '2.0.0');
  let result = validateReleaseVersionContract({ repoRoot });
  assert.deepEqual(result.errors, []);
  assert.equal(result.latestStableTag, '2.0.0');
  assert.equal(result.packageVersion, '2.0.0');
  assert.equal(result.releasePrepared, false);

  fs.writeFileSync(path.join(repoRoot, 'README.md'), 'Latest release: 1.9.9');
  result = validateReleaseVersionContract({ repoRoot });
  assert.match(result.errors.join('\n'), /README.md names a stale current release/);
});

test('rejects a real Git repository with no stable tag', (t) => {
  const { repoRoot } = repositoryFixture(t);
  git(repoRoot, 'tag', '2.0.0-beta.1');
  fs.rmSync(path.join(repoRoot, 'docs'), { recursive: true });
  assert.match(validateReleaseVersionContract({ repoRoot }).errors.join('\n'), /No stable release tag/);
});

test('rejects a shallow clone even when its stable tag is available', (t) => {
  const { repoRoot, workRoot } = repositoryFixture(t);
  git(repoRoot, 'tag', '2.0.0');
  const shallowRoot = path.join(workRoot, 'shallow');
  git(workRoot, 'clone', '--quiet', '--depth=1', pathToFileURL(repoRoot).href, shallowRoot);
  assert.equal(git(shallowRoot, 'rev-parse', '--is-shallow-repository'), 'true');
  assert.match(validateReleaseVersionContract({ repoRoot: shallowRoot }).errors.join('\n'), /require full Git history and tags \(fetch-depth: 0\)/);
});
