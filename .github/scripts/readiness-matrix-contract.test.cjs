const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const test = require('node:test');
const yaml = require('js-yaml');
const { validateReadinessMatrix, validateReleaseWorkflow } = require('./readiness-matrix-contract.cjs');

function fixture() {
  return {
    jobs: {
      practical: {
        strategy: { matrix: { include: [
          { 'php-version': '8.3', experimental: false },
          { 'php-version': '8.4', experimental: false },
          { 'php-version': '8.5', experimental: false },
        ] } },
        'continue-on-error': '${{ matrix.experimental }}',
        steps: [{ run: 'npm run pr:check' }, { run: 'npm run release:check' }],
      },
      'wordpress-fixture': {
        strategy: { matrix: { include: [
          { 'wordpress-version': '6.8', experimental: false },
          { 'wordpress-version': 'latest', experimental: false },
          { 'wordpress-version': 'nightly', experimental: true },
        ] } },
        'continue-on-error': '${{ matrix.experimental }}',
        steps: [{
          run: 'npm run release:check',
          env: { WP_SMOKE_WORDPRESS_VERSION: '${{ matrix.wordpress-version }}', WP_SMOKE_REQUIRED: '1', WP_SMOKE_A11Y: '1' },
        }],
      },
    },
  };
}

function validate(workflow = fixture(), options) {
  return validateReadinessMatrix(yaml.dump(workflow), options);
}

test('accepts minimum and current stable blocking, with an advisory preview', () => {
  assert.equal(validate().wordpress.length, 3);
});

test('validates the committed workflow and declared theme minimum', () => {
  const repoRoot = path.resolve(__dirname, '../..');
  const workflow = fs.readFileSync(path.join(repoRoot, '.github/workflows/theme-readiness.yml'), 'utf8');
  const minimumWordPress = fs.readFileSync(path.join(repoRoot, 'style.css'), 'utf8').match(/Requires at least:\s*(\S+)/)[1];
  validateReadinessMatrix(workflow, { minimumWordPress });
});

test('rejects removed or advisory minimum, latest, and baseline PHP legs', () => {
  for (const [jobName, versionKey, version] of [
    ['wordpress-fixture', 'wordpress-version', '6.8'],
    ['wordpress-fixture', 'wordpress-version', 'latest'],
    ['practical', 'php-version', '8.3'],
    ['practical', 'php-version', '8.4'],
    ['practical', 'php-version', '8.5'],
  ]) {
    for (const remove of [false, true]) {
      const workflow = fixture();
      const entries = workflow.jobs[jobName].strategy.matrix.include;
      if (remove) entries.splice(entries.findIndex((entry) => entry[versionKey] === version), 1);
      else entries.find((entry) => entry[versionKey] === version).experimental = true;
      assert.throws(() => validate(workflow), /must keep .* blocking/);
    }
  }
});

test('requires the nightly preview to remain advisory', () => {
  const workflow = fixture();
  workflow.jobs['wordpress-fixture'].strategy.matrix.include[2].experimental = false;
  assert.throws(() => validate(workflow), /must keep nightly advisory/);
});

test('rejects job-wide failure bypasses and matrix exclusions', () => {
  for (const jobName of ['practical', 'wordpress-fixture']) {
    const bypass = fixture();
    bypass.jobs[jobName]['continue-on-error'] = true;
    assert.throws(() => validate(bypass), /only matrix.experimental legs advisory/);
    const excluded = fixture();
    excluded.jobs[jobName].strategy.matrix.exclude = [{ experimental: false }];
    assert.throws(() => validate(excluded), /must not exclude/);
  }
});

test('rejects string experimental flags, numeric versions, and duplicate legs', () => {
  const flag = fixture();
  flag.jobs.practical.strategy.matrix.include[0].experimental = 'false';
  assert.throws(() => validate(flag), /explicitly mark/);
  const numeric = fixture();
  numeric.jobs.practical.strategy.matrix.include[0]['php-version'] = 8.3;
  assert.throws(() => validate(numeric), /as a string/);
  const duplicate = fixture();
  duplicate.jobs.practical.strategy.matrix.include.push({ 'php-version': '8.3', experimental: true });
  assert.throws(() => validate(duplicate), /must not duplicate/);
});

test('rejects missing, conditional, skipped, or failure-tolerant readiness commands', () => {
  for (const jobName of ['practical', 'wordpress-fixture']) {
    for (const mutation of ['remove', 'if', 'continue-on-error', 'skip-smoke']) {
      const workflow = fixture();
      const step = workflow.jobs[jobName].steps.find((entry) => entry.run === 'npm run release:check');
      if (mutation === 'remove') workflow.jobs[jobName].steps = [];
      else if (mutation === 'skip-smoke') step.run += ' -- --skip-smoke';
      else step[mutation] = true;
      assert.throws(() => validate(workflow), /must (run|not allow failures)/);
    }
  }
});

test('rejects hardcoded fixture versions or disabled fixture checks', () => {
  for (const [key, value] of [['WP_SMOKE_WORDPRESS_VERSION', '6.8'], ['WP_SMOKE_REQUIRED', '0'], ['WP_SMOKE_A11Y', '0'], ['WP_SMOKE_SKIP', '1']]) {
    const workflow = fixture();
    workflow.jobs['wordpress-fixture'].steps[0].env[key] = value;
    assert.throws(() => validate(workflow), /WordPress fixture/);
  }
  for (const scope of ['workflow', 'job']) {
    const workflow = fixture();
    (scope === 'workflow' ? workflow : workflow.jobs['wordpress-fixture']).env = { WP_SMOKE_SKIP: '1' };
    assert.throws(() => validate(workflow), /must not skip/);
  }
});

test('keeps the validated PHP 8.5 lane blocking', () => {
  const workflow = fixture();
  workflow.jobs.practical.strategy.matrix.include[2].experimental = true;
  assert.throws(() => validate(workflow), /must keep 8.5 blocking/);
});

function releaseFixture() {
  const repoRoot = path.resolve(__dirname, '../..');
  return {
    release: yaml.load(fs.readFileSync(path.join(repoRoot, '.github/workflows/semantic-release.yml'), 'utf8')),
    readiness: yaml.load(fs.readFileSync(path.join(repoRoot, '.github/workflows/theme-readiness.yml'), 'utf8')),
  };
}

function validateRelease({ release, readiness } = releaseFixture()) {
  return validateReleaseWorkflow(yaml.dump(release), yaml.dump(readiness));
}

test('release publishing waits for the complete callable readiness workflow', () => {
  validateRelease();
});

test('rejects bypassed reusable readiness or publish dependencies', () => {
  for (const mutation of [
    ({ release }) => { delete release.jobs['release-readiness']; },
    ({ release }) => { release.jobs['release-readiness'].uses = './.github/workflows/other.yml'; },
    ({ release }) => { release.jobs['release-readiness'].with.release = false; },
    ({ release }) => { release.jobs['semantic-release'].needs = []; },
    ({ release }) => { release.jobs['release-readiness'].if = 'always()'; },
    ({ release }) => { release.jobs['semantic-release'].if = 'always()'; },
    ({ release }) => { release.jobs['release-readiness']['continue-on-error'] = true; },
    ({ readiness }) => { delete readiness.on.workflow_call; },
    ({ readiness }) => { readiness.concurrency.group = 'theme-readiness-${{ github.ref }}'; },
    ({ readiness }) => { readiness.jobs['wordpress-fixture'].if = 'always()'; },
    ({ readiness }) => { readiness.jobs['extended-whisk'].if = 'false'; },
    ({ readiness }) => { readiness.jobs['php-lint']['continue-on-error'] = true; },
    ({ readiness }) => { readiness.jobs.practical.if = 'false'; },
  ]) {
    const workflow = releaseFixture();
    mutation(workflow);
    assert.throws(() => validateRelease(workflow));
  }
});

test('rejects skipped required fixture, PHP, parity and Whisk release gates', () => {
  for (const [jobName, command] of [
    ['wordpress-fixture', 'npm run release:check'],
    ['php-lint', 'npm run lint:php'],
    ['practical', 'npm run smoke:generation-parity'],
    ['practical', 'npm run pr:check'],
    ['extended-whisk', 'npm --prefix whisk run test -- --runInBand'],
    ['extended-whisk', 'npm --prefix whisk run a11y'],
  ]) {
    for (const bypass of ['remove', 'if', 'continue-on-error', 'skip-smoke']) {
      const workflow = releaseFixture();
      const job = workflow.readiness.jobs[jobName];
      const step = job.steps.find((entry) => entry.run === command);
      if (bypass === 'remove') job.steps.splice(job.steps.indexOf(step), 1);
      else if (bypass === 'skip-smoke') step.run += ' -- --skip-smoke';
      else step[bypass] = true;
      assert.throws(() => validateRelease(workflow));
    }
  }
  for (const envKey of ['WP_SMOKE_REQUIRED', 'WP_SMOKE_A11Y']) {
    const workflow = releaseFixture();
    const step = workflow.readiness.jobs['wordpress-fixture'].steps.find((entry) => entry.run === 'npm run release:check');
    delete step.env[envKey];
    assert.throws(() => validateRelease(workflow));
  }
});

test('rejects release credential persistence and rebuilding instead of reusing the tested ZIP', () => {
  for (const mutation of [
    ({ release }) => { release.jobs['semantic-release'].steps.find((step) => step.name === 'Checkout').with['persist-credentials'] = true; },
    ({ release }) => { release.jobs['semantic-release'].steps.push({ run: 'npm run build:dist' }); },
    ({ release }) => { release.jobs['semantic-release'].env = { GITHUB_TOKEN: '${{ secrets.GITHUB_TOKEN }}' }; },
    ({ release }) => { delete release.jobs['semantic-release']['timeout-minutes']; },
    ({ release }) => { release.jobs['semantic-release'].steps.find((step) => step.name === 'Verify tested archive checksum').run = 'true'; },
    ({ release }) => { release.jobs['semantic-release'].steps.find((step) => step.name === 'Download tested release bundle').with.name = 'untested'; },
    ({ readiness }) => { readiness.jobs['wordpress-fixture'].needs = []; },
    ({ readiness }) => { readiness.jobs['wordpress-fixture'].steps.find((step) => step.name === 'Download tested release bundle').with.name = 'untested'; },
    ({ readiness }) => { delete readiness.jobs.practical['timeout-minutes']; },
  ]) {
    const workflow = releaseFixture();
    mutation(workflow);
    assert.throws(() => validateRelease(workflow));
  }
});
