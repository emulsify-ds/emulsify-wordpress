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

function releaseOptions(args) {
  const { values } = parseArgs({
    args,
    allowNegative: true,
    options: {
      'tag-format': { type: 'string', short: 't' },
      'dry-run': { type: 'boolean', short: 'd' },
      debug: { type: 'boolean' },
      ci: { type: 'boolean' },
    },
  });
  return {
    ...(values['tag-format'] !== undefined && { tagFormat: values['tag-format'] }),
    ...(values['dry-run'] !== undefined && { dryRun: values['dry-run'] }),
    ...(values.ci !== undefined && { ci: values.ci }),
    ...(values.debug !== undefined && { debug: values.debug }),
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
} = {}) {
  const options = releaseOptions(args);
  const isCI = Boolean(env.CI) && env.CI !== 'false';
  const contract = validateReleaseVersionContract({ repoRoot });
  if (contract.errors.length > 0) {
    throw new Error(contract.errors.join('\n'));
  }

  // Check before skipping too: semantic-release must never push an unpublished
  // stable tag from a developer's checkout.
  assertPublishedStableTags(repoRoot);
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
