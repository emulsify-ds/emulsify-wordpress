const assert = require('node:assert/strict');
const fs = require('node:fs');
const os = require('node:os');
const path = require('node:path');
const { test } = require('node:test');
const releaseConfig = require('../../release.config.js');

test('release conditions require a nonempty installable ZIP before tagging', (t) => {
  const cwd = fs.mkdtempSync(path.join(os.tmpdir(), 'emulsify-release-zip-'));
  t.after(() => fs.rmSync(cwd, { recursive: true, force: true }));
  const guard = releaseConfig.plugins.find((plugin) => typeof plugin.verifyConditions === 'function');
  assert.ok(guard, 'A configured verifyConditions hook must require the release ZIP.');
  const verify = () => guard.verifyConditions({}, { cwd });
  assert.throws(verify, /dist-artifact\/emulsify\.zip.*npm run build:dist/);
  const archive = path.join(cwd, 'dist-artifact/emulsify.zip');
  fs.mkdirSync(archive, { recursive: true });
  assert.throws(verify, /dist-artifact\/emulsify\.zip/);
  fs.rmdirSync(archive);
  fs.writeFileSync(archive, '');
  assert.throws(verify, /dist-artifact\/emulsify\.zip/);
  fs.writeFileSync(archive, 'tested archive fixture');
  assert.doesNotThrow(verify);
});

test('maintainer npm commands cannot bypass the guarded release runner', () => {
  const pkg = require('../../package.json');
  assert.equal(pkg.private, true);
  assert.equal(pkg.scripts['semantic-release'], undefined);
  assert.match(pkg.scripts.publish, /^node \.github\/scripts\/semantic-release\.cjs\b/);
  assert.match(pkg.scripts['publish-test'], /semantic-release\.cjs.*--dry-run/);
});
