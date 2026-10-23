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

module.exports = { validateReadinessMatrix };
