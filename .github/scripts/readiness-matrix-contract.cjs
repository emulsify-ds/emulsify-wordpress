const assert = require('node:assert/strict');
const yaml = require('js-yaml');

function validateReadinessMatrix(source, { minimumWordPress = '6.8', requiredPhpVersions = ['8.3', '8.4', '8.5'] } = {}) {
  const workflow = yaml.load(source);

  function entriesFor(jobName, versionKey) {
    const job = workflow.jobs?.[jobName];
    assert.ok(job, `theme-readiness.yml must keep the ${jobName} job.`);
    assert.equal(job['continue-on-error'], '${{ matrix.experimental }}', `${jobName} must make only matrix.experimental legs advisory.`);
    assert.ok(!job.strategy?.matrix?.exclude, `${jobName} must not exclude required readiness legs.`);
    const entries = job.strategy?.matrix?.include;
    assert.ok(Array.isArray(entries) && entries.length, `${jobName} must declare its readiness matrix with include.`);
    const versions = new Set();
    for (const entry of entries) {
      assert.equal(typeof entry[versionKey], 'string', `${jobName} must declare each ${versionKey} as a string.`);
      assert.equal(typeof entry.experimental, 'boolean', `${jobName} must explicitly mark each leg's experimental status.`);
      assert.ok(!versions.has(entry[versionKey]), `${jobName} must not duplicate ${entry[versionKey]} readiness legs.`);
      versions.add(entry[versionKey]);
    }
    return { job, entries };
  }

  function requireLeg(entries, versionKey, version, experimental, jobName) {
    assert.ok(entries.some((entry) => entry[versionKey] === version && entry.experimental === experimental),
      `${jobName} must keep ${version} ${experimental ? 'advisory' : 'blocking'}.`);
  }

  function requireCommand(job, command, jobName) {
    const steps = (job.steps || []).filter((step) => step.run?.trim() === command);
    assert.ok(steps.length, `${jobName} must run ${command}.`);
    for (const step of steps) {
      assert.ok(step['continue-on-error'] === undefined || step['continue-on-error'] === false,
        `${jobName}: ${command} must not allow failures.`);
      assert.equal(step.if, undefined, `${jobName}: ${command} must run on every matrix leg.`);
    }
    return steps;
  }

  const practical = entriesFor('practical', 'php-version');
  for (const version of requiredPhpVersions) requireLeg(practical.entries, 'php-version', version, false, 'practical');
  requireCommand(practical.job, 'npm run pr:check', 'practical');
  requireCommand(practical.job, 'npm run release:check', 'practical');

  const fixture = entriesFor('wordpress-fixture', 'wordpress-version');
  requireLeg(fixture.entries, 'wordpress-version', minimumWordPress, false, 'wordpress-fixture');
  requireLeg(fixture.entries, 'wordpress-version', 'latest', false, 'wordpress-fixture');
  requireLeg(fixture.entries, 'wordpress-version', 'nightly', true, 'wordpress-fixture');
  for (const step of requireCommand(fixture.job, 'npm run release:check', 'wordpress-fixture')) {
    assert.equal(step.env?.WP_SMOKE_WORDPRESS_VERSION, '${{ matrix.wordpress-version }}',
      'WordPress fixture smoke must resolve its version from matrix.wordpress-version, not a hardcoded version.');
    assert.equal(step.env?.WP_SMOKE_REQUIRED, '1', 'WordPress fixture matrix must fail when smoke prerequisites are missing.');
    assert.equal(step.env?.WP_SMOKE_A11Y, '1', 'WordPress fixture matrix must audit rendered accessibility.');
    const env = { ...workflow.env, ...fixture.job.env, ...step.env };
    assert.notEqual(String(env.WP_SMOKE_SKIP), '1', 'WordPress fixture matrix must not skip its smoke test.');
  }
  return { practical: practical.entries, wordpress: fixture.entries };
}

const extendedConditions = {
  'wordpress-fixture': "inputs.release || (github.event_name == 'pull_request' && (github.base_ref == 'main' || github.base_ref == 'release-2.x')) || github.event_name == 'push' || github.event_name == 'schedule' || (github.event_name == 'workflow_dispatch' && inputs.wordpress_fixture)",
  'extended-whisk': "inputs.release || (github.event_name == 'pull_request' && (github.base_ref == 'main' || github.base_ref == 'release-2.x')) || github.event_name == 'push' || github.event_name == 'schedule' || (github.event_name == 'workflow_dispatch' && inputs.extended_checks)",
};

function requireBlockingRun(job, command, label) {
  const step = job.steps?.find((entry) => entry.run?.trim() === command);
  assert.ok(step, `${label} must run ${command}.`);
  assert.equal(step.if, undefined, `${label}: ${command} must not be conditional.`);
  assert.ok(step['continue-on-error'] === undefined || step['continue-on-error'] === false,
    `${label}: ${command} must not allow failures.`);
  return step;
}

function validateReleaseWorkflow(releaseSource, readinessSource) {
  const release = yaml.load(releaseSource);
  const readiness = yaml.load(readinessSource);
  validateReadinessMatrix(readinessSource);

  assert.equal(readiness.on?.workflow_call?.inputs?.release?.type, 'boolean', 'Full readiness must be callable with a release input.');
  assert.equal(readiness.permissions?.contents, 'read', 'Readiness credentials must remain read-only.');
  assert.equal(readiness.concurrency?.group, 'theme-readiness-${{ github.workflow }}-${{ github.event.pull_request.number || github.ref }}', 'Reusable release readiness must not share a concurrency group with standalone runs.');
  const gate = release.jobs?.['release-readiness'];
  const publisher = release.jobs?.['semantic-release'];
  const state = release.jobs?.['release-state'];
  assert.ok(gate && publisher && state, 'Release must keep publication state, full readiness and publishing jobs.');
  assert.equal(gate.uses, './.github/workflows/theme-readiness.yml', 'Release must reuse the full readiness workflow.');
  assert.equal(gate.with?.release, true, 'Release must force all reusable readiness jobs.');
  assert.equal(gate.needs, 'release-state', 'Full readiness must follow the publication recovery check.');
  assert.equal(publisher.needs, 'release-readiness', 'Publishing must wait for full readiness success.');
  for (const [name, job] of Object.entries(release.jobs)) {
    assert.equal(job.if, undefined, `${name} must use the default success dependency condition.`);
    assert.ok(job['continue-on-error'] === undefined || job['continue-on-error'] === false, `${name} must not allow failures.`);
    if (!job.uses) assert.ok(Number.isInteger(job['timeout-minutes']) && job['timeout-minutes'] > 0 && job['timeout-minutes'] <= 30, `${name} must have a bounded timeout.`);
  }
  assert.equal(release.permissions?.contents, 'read', 'Release workflow credentials must default to read-only.');
  assert.equal(state.permissions?.contents, 'read', 'Publication recovery must use read-only credentials.');
  requireBlockingRun(state, 'node .github/scripts/semantic-release.cjs --check-publication', 'Publication recovery');
  for (const [name, job] of Object.entries(readiness.jobs)) {
    assert.ok(Number.isInteger(job['timeout-minutes']) && job['timeout-minutes'] > 0 && job['timeout-minutes'] <= 30, `${name} must have a bounded timeout.`);
    if (extendedConditions[name]) {
      assert.equal(job.if?.replace(/\s+/g, ' ').trim(), extendedConditions[name], `${name} must run for every reusable release invocation.`);
    } else {
      assert.equal(job.if, undefined, `${name} must not skip required readiness.`);
    }
  }
  const lint = readiness.jobs['php-lint'];
  const whisk = readiness.jobs['extended-whisk'];
  assert.ok(lint && whisk, 'Release readiness must include PHP analysis and Whisk checks.');
  for (const [name, job] of [['php-lint', lint], ['extended-whisk', whisk]]) {
    assert.ok(job['continue-on-error'] === undefined || job['continue-on-error'] === false, `${name} must not allow failures.`);
  }
  requireBlockingRun(lint, 'npm run lint:php', 'PHP analysis');
  requireBlockingRun(readiness.jobs.practical, 'npm run test:generated-theme', 'Practical readiness');
  assert.equal(requireBlockingRun(readiness.jobs.practical, 'npm run smoke:generation-parity', 'Practical readiness').env?.EMULSIFY_PARITY_REQUIRED, '1', 'Generation parity must not skip missing prerequisites.');
  requireBlockingRun(whisk, 'npm --prefix whisk run test -- --runInBand', 'Whisk readiness');
  requireBlockingRun(whisk, 'npm --prefix whisk run a11y', 'Whisk readiness');
  return { release, readiness };
}

module.exports = { validateReadinessMatrix, validateReleaseWorkflow };
