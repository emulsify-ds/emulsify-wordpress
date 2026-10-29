#!/usr/bin/env node

const fs = require('node:fs');
const path = require('node:path');
const { execFileSync } = require('node:child_process');

function stableVersionParts(version) {
  if (typeof version !== 'string') return null;
  const match = /^(0|[1-9]\d*)\.(0|[1-9]\d*)\.(0|[1-9]\d*)$/.exec(version);
  if (!match || match[0] !== version) return null;
  const parts = match.slice(1).map(Number);
  return parts.every(Number.isSafeInteger) ? parts : null;
}

function compareVersions(left, right) {
  const leftParts = stableVersionParts(left);
  const rightParts = stableVersionParts(right);
  for (let index = 0; index < 3; index += 1) {
    if (leftParts[index] !== rightParts[index]) return rightParts[index] - leftParts[index];
  }
  return 0;
}

function validateReleaseVersions({ tags = [], latestStableTag, packageVersion, documents = {} }) {
  const errors = [];
  const stableTags = tags.filter((tag) => stableVersionParts(tag)).sort(compareVersions);
  latestStableTag ??= stableTags[0];
  const result = { errors, packageVersion, latestStableTag, stableTags, releasePrepared: false };

  if (!stableVersionParts(latestStableTag)) {
    errors.push('No stable release tag is available; fetch the full Git history and tags (fetch-depth: 0).');
    return result;
  }

  if (!stableVersionParts(packageVersion)) {
    errors.push('package.json version must use a canonical stable x.y.z version.');
  } else {
    const [major, minor, patch] = stableVersionParts(latestStableTag);
    const nextVersions = [`${major}.${minor}.${patch + 1}`, `${major}.${minor + 1}.0`, `${major + 1}.0.0`];
    if (packageVersion !== latestStableTag && (tags.includes(packageVersion) || !nextVersions.includes(packageVersion))) {
      errors.push(`package.json version must equal the latest stable tag ${latestStableTag} or an untagged next patch, minor, or major version (${nextVersions.join(', ')}).`);
    }
  }

  const releasedVersions = new Set(tags);
  const draft = documents['docs/release-notes-next.md'] || '';
  for (const line of draft.split(/\r?\n/)) {
    // Draft headings and release titles claim a version; historical prose and
    // compatibility requirements elsewhere in the draft do not.
    if (!/^\s*(?:#{1,6}\s|Title\s*:)/i.test(line)) continue;
    for (const match of line.matchAll(/\bv?(\d+\.\d+\.\d+(?:-[0-9A-Za-z.-]+)?(?:\+[0-9A-Za-z.-]+)?)\b/g)) {
      if (releasedVersions.has(match[1])) {
        errors.push(`The next-release draft names already-tagged release ${match[1]}; keep the draft unreleased.`);
      }
    }
  }

  for (const [file, text] of Object.entries(documents)) {
    for (const match of text.matchAll(/(?:latest|current)\s+(?:(?:published|stable)\s+)?(?:release|tag|version)\s*:\s*[`*]*v?(\d+\.\d+\.\d+(?:-[0-9A-Za-z.-]+)?(?:\+[0-9A-Za-z.-]+)?)\b/gi)) {
      if (match[1] !== latestStableTag) {
        errors.push(`${file} names a stale current release (${match[1]}); use the latest stable tag ${latestStableTag} or remove the claim.`);
      }
    }
  }

  result.releasePrepared = errors.length === 0 && packageVersion !== latestStableTag;
  return result;
}

function validateReleaseVersionContract({ repoRoot = path.resolve(__dirname, '../..') } = {}) {
  try {
    const git = (...args) => execFileSync('git', args, { cwd: repoRoot, encoding: 'utf8', stdio: ['ignore', 'pipe', 'pipe'] }).trim();
    if (git('rev-parse', '--is-shallow-repository') !== 'false') {
      return { errors: ['Release version checks require full Git history and tags (fetch-depth: 0).'], releasePrepared: false };
    }
    const tags = git('tag', '--list').split(/\r?\n/).filter(Boolean);
    if (!tags.some((tag) => stableVersionParts(tag))) return validateReleaseVersions({ tags });
    const read = (file) => fs.readFileSync(path.join(repoRoot, file), 'utf8');
    const documents = Object.fromEntries([
      'README.md',
      'docs/release-notes-next.md',
      'docs/release-process.md',
    ].map((file) => [file, read(file)]));
    return validateReleaseVersions({ tags, packageVersion: JSON.parse(read('package.json')).version, documents });
  } catch (error) {
    return { errors: [`Unable to validate the release version contract: ${error.message}`], releasePrepared: false };
  }
}

if (require.main === module) {
  const result = validateReleaseVersionContract();
  if (result.errors.length > 0) {
    for (const error of result.errors) console.error(`FAIL ${error}`);
    process.exitCode = 1;
  } else {
    console.log(`PASS Release version policy and unreleased draft (latest tag: ${result.latestStableTag}; package: ${result.packageVersion}).`);
  }
}

module.exports = { validateReleaseVersionContract, validateReleaseVersions };
