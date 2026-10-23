#!/usr/bin/env node

// Exercise the real Core runner with a controlled Pa11y boundary. Browser-based
// coverage uses the CI Storybook fixture and the generated theme's a11y command.
const assert = require('node:assert/strict');
const { spawnSync } = require('node:child_process');
const fs = require('node:fs');
const os = require('node:os');
const path = require('node:path');
const { test } = require('node:test');

const repoRoot = path.resolve(__dirname, '../..');
const coreRoot =
  process.env.EMULSIFY_CORE_ROOT ||
  path.join(repoRoot, 'whisk/node_modules/@emulsify/core');

function write(file, contents) {
  fs.mkdirSync(path.dirname(file), { recursive: true });
  fs.writeFileSync(file, contents);
}

function runFixture(storyIds) {
  const root = fs.mkdtempSync(path.join(os.tmpdir(), 'emulsify-a11y-runner-'));
  try {
    write(path.join(root, 'package.json'), '{"type":"module"}');
    const installedCore = path.join(root, 'node_modules/@emulsify/core');
    write(path.join(installedCore, 'package.json'), '{"type":"module"}');
    for (const file of ['scripts/a11y.js', 'config/a11y.config.js']) {
      write(
        path.join(installedCore, file),
        fs.readFileSync(path.join(coreRoot, file)),
      );
    }
    write(
      path.join(root, 'config/emulsify-core/run-a11y.js'),
      fs.readFileSync(
        path.join(repoRoot, 'whisk/config/emulsify-core/run-a11y.js'),
      ),
    );
    write(
      path.join(root, 'config/emulsify-core/a11y.config.js'),
      `export default ${JSON.stringify({
        concurrency: 2,
        storybookBuildDir: '.out',
        components: [],
        ignore: { codes: ['fixture.ignored'] },
        pa11y: { wait: 321 },
      })};\n`,
    );
    write(
      path.join(root, '.out/index.json'),
      JSON.stringify({
        entries: Object.fromEntries(
          storyIds.map((id) => [id, { id, type: 'story' }]),
        ),
      }),
    );
    write(
      path.join(root, '.out/iframe.html'),
      '<main>Served from the configured .out directory.</main>',
    );
    write(
      path.join(root, 'node_modules/pa11y/package.json'),
      '{"type":"module","exports":"./index.js"}',
    );
    write(
      path.join(root, 'node_modules/pa11y/index.js'),
      `
import fs from 'node:fs';
let active = 0;
const record = (entry) => fs.appendFileSync('pa11y-calls.jsonl', JSON.stringify(entry) + '\\n');
export default async function pa11y(url, options) {
  const storyId = new URL(url).searchParams.get('id');
  active += 1;
  record({ storyId, active, options });
  try {
    const response = await fetch(url);
    if (!response.ok || !(await response.text()).includes('configured .out directory')) {
      throw new Error('The configured Storybook build was not served.');
    }
    await new Promise((resolve) => setTimeout(resolve, storyId === 'clean-first' ? 60 : 20));
    if (storyId.startsWith('failed-')) throw new Error('fixture navigation failure: ' + storyId);
    return { pageUrl: url, issues: [
      { code: 'fixture.ignored', type: 'error', message: 'Ignored fixture issue' },
      ...(storyId === 'findings' ? [{ code: 'fixture.reported', type: 'error', message: 'Reported fixture issue' }] : []),
    ] };
  } finally {
    active -= 1;
  }
}
`,
    );
    const result = spawnSync(
      process.execPath,
      ['config/emulsify-core/run-a11y.js'],
      {
        cwd: root,
        encoding: 'utf8',
        timeout: 15000,
        env: { ...process.env, CI: 'true' },
      },
    );
    assert.ifError(result.error);
    assert.equal(
      result.signal,
      null,
      'The runner must close its HTTP server after every outcome.',
    );
    const callsPath = path.join(root, 'pa11y-calls.jsonl');
    const calls = fs.existsSync(callsPath)
      ? fs.readFileSync(callsPath, 'utf8').trim().split('\n').map(JSON.parse)
      : [];
    return { ...result, calls };
  } finally {
    fs.rmSync(root, { recursive: true, force: true });
  }
}

test('accessibility runner rejects zero discovered stories before starting scans', () => {
  const result = runFixture([]);
  assert.equal(result.status, 1);
  assert.match(
    result.stderr,
    /No Storybook stories were discovered; accessibility checks did not run/,
  );
  assert.deepEqual(result.calls, []);
});

test('accessibility runner delegates configured HTTP scans and bounded workers to Core', () => {
  const result = runFixture(['clean-first', 'clean-second', 'clean-third']);
  assert.equal(result.status, 0, result.stderr);
  assert.deepEqual(
    result.calls.map(({ storyId }) => storyId),
    ['clean-first', 'clean-second', 'clean-third'],
  );
  assert.equal(Math.max(...result.calls.map(({ active }) => active)), 2);
  for (const { options } of result.calls) {
    assert.equal(
      options.wait,
      321,
      'The project Pa11y wait must reach every scan.',
    );
    assert.deepEqual(options.runners, ['axe']);
    assert.ok(
      !options.chromeLaunchConfig?.args?.some((arg) =>
        ['--no-sandbox', '--disable-setuid-sandbox'].includes(arg),
      ),
    );
  }
  assert.match(
    result.stdout,
    /Accessibility summary: 3 attempted, 3 clean, 0 with findings, 0 failed to execute/,
  );
  assert.ok(
    result.stdout.indexOf('?id=clean-first') <
      result.stdout.indexOf('?id=clean-second'),
    'Reports retain selected-story order despite completion order.',
  );
});

test('accessibility runner reports every finding and execution failure before closing', () => {
  const result = runFixture([
    'failed-first',
    'findings',
    'clean-last',
    'failed-last',
  ]);
  assert.equal(result.status, 1);
  assert.deepEqual(
    result.calls.map(({ storyId }) => storyId),
    ['failed-first', 'findings', 'clean-last', 'failed-last'],
  );
  assert.match(result.stdout, /Reported fixture issue/);
  assert.doesNotMatch(result.stdout, /Ignored fixture issue/);
  assert.match(
    result.stdout,
    /Accessibility summary: 4 attempted, 1 clean, 1 with findings, 2 failed to execute/,
  );
  assert.match(
    result.stderr,
    /AggregateError: Accessibility execution failed for 2 stories/,
  );
  assert.match(result.stdout, /fixture navigation failure: failed-first/);
  assert.match(result.stdout, /fixture navigation failure: failed-last/);
});
