#!/usr/bin/env node

const { execFileSync } = require('node:child_process');
const path = require('node:path');
const { parseArgs } = require('node:util');
const { validateReleaseVersionContract } = require('./release-version-contract.cjs');

function git(repoRoot, args) {
  return execFileSync('git', args, { cwd: repoRoot, encoding: 'utf8' }).trim();
}

function assertPublishedStableTags(repoRoot) {
  const stableTags = git(repoRoot, ['tag', '--list'])
    .split(/\r?\n/).filter((tag) => /^\d+\.\d+\.\d+$/.test(tag));
  const remoteTags = new Set(git(repoRoot, ['ls-remote', '--tags', 'origin'])
    .split(/\r?\n/).map((line) => (line.split(/\s+/)[1] || '')
      .replace(/^refs\/tags\//, '').replace(/\^\{\}$/, '')));
  const localOnlyTags = stableTags.filter((tag) => !remoteTags.has(tag));

  if (localOnlyTags.length > 0) {
    throw new Error(
      `Refusing to publish local-only stable tag(s): ${localOnlyTags.join(', ')}. Remove them or publish them intentionally first.`,
    );
  }
}

function assertCurrentMain(repoRoot) {
  const head = git(repoRoot, ['rev-parse', 'HEAD']);
  let remoteMain;
  try {
    remoteMain = git(repoRoot, ['ls-remote', 'origin', 'refs/heads/main']).split(/\s+/)[0];
  } catch (error) {
    throw new Error(`Unable to verify origin/main before releasing; check origin connectivity and retry. ${error.message}`);
  }
  if (!remoteMain) {
    throw new Error('Unable to verify origin/main before releasing: origin has no refs/heads/main.');
  }
  if (head !== remoteMain) {
    throw new Error(
      `Main moved during the release: HEAD ${head} differs from origin/main ${remoteMain}. The Semantic release job is stale. Start a new workflow_dispatch run of WordPress Release (semantic-release.yml) on main instead of re-running the old job.`,
    );
  }
}

async function assertPublishedRelease(tag, env, fetchRelease) {
  const token = env.GITHUB_TOKEN || env.GH_TOKEN;
  const repository = env.GITHUB_REPOSITORY;
  if (!token) throw new Error('GITHUB_TOKEN is required in CI to verify the tagged GitHub release and emulsify.zip.');
  if (!/^[\w.-]+\/[\w.-]+$/.test(repository || '')) {
    throw new Error('GITHUB_REPOSITORY must identify owner/repo to verify the tagged GitHub release in CI.');
  }
  const api = (env.GITHUB_API_URL || 'https://api.github.com').replace(/\/$/, '');
  const upload = `gh release upload ${tag} dist-artifact/emulsify.zip --repo ${repository} --clobber`;
  const publish = `gh release edit ${tag} --repo ${repository} --draft=false --prerelease=false`;
  let response;
  let release;
  try {
    response = await fetchRelease(`${api}/repos/${repository}/releases/tags/${encodeURIComponent(tag)}`, {
      headers: { Authorization: `Bearer ${token}`, Accept: 'application/vnd.github+json', 'X-GitHub-Api-Version': '2022-11-28' },
      redirect: 'error',
      signal: AbortSignal.timeout(15000),
    });
    if (response.ok) release = await response.json();
  } catch (error) {
    throw new Error(`Unable to verify GitHub release ${tag}: ${error.message}. Restore API connectivity and rerun the release workflow.`);
  }
  if (response.status === 404) {
    // The tag endpoint exposes published releases; read-only callers may not
    // see a remaining draft. Keep recovery conditional instead of adding writes.
    throw new Error(
      `Published GitHub release ${tag} is missing or still a draft. With a maintainer token, inspect: gh release view ${tag} --repo ${repository}. If no release exists, create a draft from the tag's tested ZIP: gh release create ${tag} dist-artifact/emulsify.zip --repo ${repository} --verify-tag --draft --title ${tag} --generate-notes. If a draft already exists, restore its ZIP instead: ${upload}. Review the release notes, then publish: ${publish}`,
    );
  }
  if (!response.ok) throw new Error(`Unable to verify GitHub release ${tag}: HTTP ${response.status}. Check GITHUB_TOKEN repository permissions and GitHub API availability, then rerun the release workflow.`);
  if (!release || typeof release !== 'object' || Array.isArray(release) || !Array.isArray(release.assets)) {
    throw new Error(`Unable to verify GitHub release ${tag}: invalid GitHub API response. Rerun after checking API availability.`);
  }
  const zip = release.assets.find((asset) => asset?.name === 'emulsify.zip' && asset.state === 'uploaded' && typeof asset.size === 'number' && asset.size > 0);
  const unpublished = release.draft !== false || release.prerelease !== false || !release.published_at;
  if (!zip || unpublished) {
    const commands = [!zip && upload, unpublished && publish].filter(Boolean).join('; ');
    throw new Error(`Tagged release ${tag} is incomplete: ${!zip ? 'emulsify.zip is missing, empty, or not uploaded' : 'the GitHub release is not published as stable'}. Recover using the tag's tested ZIP and review the release notes before publishing: ${commands}`);
  }
  console.log(`Verified published GitHub release ${tag} with emulsify.zip.`);
}

function releaseOptions(args) {
  const { values } = parseArgs({
    args,
    allowNegative: true,
    options: {
      'tag-format': { type: 'string', short: 't' },
      'dry-run': { type: 'boolean', short: 'd' },
      debug: { type: 'boolean' },
      ci: { type: 'boolean' },
      'check-publication': { type: 'boolean' },
    },
  });
  return {
    ...(values['tag-format'] !== undefined && { tagFormat: values['tag-format'] }),
    ...(values['dry-run'] !== undefined && { dryRun: values['dry-run'] }),
    ...(values.ci !== undefined && { ci: values.ci }),
    ...(values.debug !== undefined && { debug: values.debug }),
    ...(values['check-publication'] !== undefined && { checkPublication: values['check-publication'] }),
  };
}

async function runSemanticRelease(options, context) {
  if (options.debug) {
    (await import('debug')).default.enable('semantic-release:*');
  }
  return (await import('semantic-release')).default(options, context);
}

async function main({
  repoRoot = path.resolve(__dirname, '../..'),
  args = process.argv.slice(2),
  runRelease = runSemanticRelease,
  env = process.env,
  fetchRelease = globalThis.fetch,
} = {}) {
  const { checkPublication = false, ...options } = releaseOptions(args);
  const isCI = Boolean(env.CI) && env.CI !== 'false';
  const contract = validateReleaseVersionContract({ repoRoot });
  if (!contract.latestStableTag || !Object.hasOwn(contract, 'packageVersion')) {
    throw new Error(contract.errors.join('\n'));
  }

  // Check before skipping too: semantic-release must never push an unpublished
  // stable tag from a developer's checkout.
  assertPublishedStableTags(repoRoot);
  // Diagnose a partial tag-first release before stale draft metadata masks it.
  if (isCI && contract.packageVersion === contract.latestStableTag) {
    await assertPublishedRelease(contract.latestStableTag, env, fetchRelease);
  }
  // The early workflow preflight is read-only. Full readiness and the normal
  // runner still enforce all document and version rules before publishing.
  if (checkPublication) return 0;
  if (contract.errors.length > 0) throw new Error(contract.errors.join('\n'));
  if (!contract.releasePrepared) {
    console.log(`No release prepared: package.json matches latest stable tag ${contract.latestStableTag}. Skipping publishing.`);
    return 0;
  }

  if (isCI) assertCurrentMain(repoRoot);

  // A release PR can verify its version before merging to main. This override
  // is only for an explicit local dry run, which cannot tag or publish.
  if (!isCI && options.dryRun && options.ci === false) {
    options.branches = [git(repoRoot, ['branch', '--show-current'])];
  }

  const result = await runRelease(options, { cwd: repoRoot });
  if (result?.nextRelease?.version !== contract.packageVersion) {
    throw new Error(
      `Prepared package.json version ${contract.packageVersion}, but semantic-release ${result?.nextRelease ? `computed ${result.nextRelease.version}` : 'did not compute a release'}. Run on main with releasable Conventional Commits matching the prepared version, then rerun npm run release:check.`,
    );
  }
  return 0;
}

if (require.main === module) {
  main().then((status) => { process.exitCode = status; }).catch((error) => {
    console.error(`Semantic release runner failed: ${error.message}`);
    process.exitCode = 1;
  });
}

module.exports = { assertPublishedStableTags, main, releaseOptions };
