#!/usr/bin/env node

const assert = require('node:assert/strict');
const { spawnSync } = require('node:child_process');
const fs = require('node:fs');
const os = require('node:os');
const path = require('node:path');
const { test } = require('node:test');

const starterPackage = require('../../whisk/package.json');
const npm = process.platform === 'win32' ? 'npm.cmd' : 'npm';

for (const [wrapper, checks] of Object.entries({
  lint: ['lint-js', 'lint-styles'],
  'lint-fix': ['lint-js', 'lint-styles'],
  format: ['lint-fix', 'prettier-fix'],
})) {
  for (const failures of [[], [0], [1], [0, 1]]) {
    test(`${wrapper} runs every check and propagates failures ${JSON.stringify(failures)}`, (t) => {
      const cwd = fs.mkdtempSync(path.join(os.tmpdir(), 'emulsify-lint-'));
      t.after(() => fs.rmSync(cwd, { recursive: true, force: true }));
      const scripts = { [wrapper]: starterPackage.scripts[wrapper] };
      fs.writeFileSync(path.join(cwd, 'check.cjs'), 'require("node:fs").appendFileSync("ran", process.argv[2] + "\\n"); process.exit(Number(process.argv[3]));\n');
      checks.forEach((check, index) => {
        scripts[check] = `node check.cjs ${check} ${failures.includes(index) ? 23 + index : 0}`;
      });
      fs.writeFileSync(path.join(cwd, 'package.json'), JSON.stringify({ scripts }));
      const result = spawnSync(npm, ['run', wrapper], { cwd, encoding: 'utf8', timeout: 30000 });
      assert.ifError(result.error);
      assert.equal(result.status === 0, failures.length === 0, result.stdout + result.stderr);
      assert.deepEqual(fs.readFileSync(path.join(cwd, 'ran'), 'utf8').trim().split('\n'), checks);
    });
  }
}
