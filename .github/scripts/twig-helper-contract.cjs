#!/usr/bin/env node

// Characterization only: differing expectations require an explicit reason.
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const { createRequire } = require('node:module');
const { spawnSync } = require('node:child_process');
const { pathToFileURL } = require('node:url');
const { isDeepStrictEqual } = require('node:util');

async function main() {
  assert.ok(process.argv.length <= 3, 'Usage: node twig-helper-contract.cjs [installed-theme-directory]');
  const project = path.resolve(process.argv[2] || path.join(__dirname, '../../whisk'));
  const requireProject = createRequire(path.join(project, 'package.json'));
  const core = requireProject('@emulsify/core/package.json');
  const version = core.version.match(/^(\d+)\.(\d+)\.(\d+)$/);
  assert.ok(version && (Number(version[1]) > 4 || Number(version[1]) === 4 && Number(version[2]) >= 5), `Core ${core.version} is below the 4.5.0 helper baseline.`);
  const { bemAttributes, addAttributes } = await import(pathToFileURL(requireProject.resolve('@emulsify/core/extensions/twig')));
  const corpus = JSON.parse(fs.readFileSync(path.join(__dirname, '../fixtures/twig-helper-contract.json'), 'utf8'));
  assert.equal(corpus.schemaVersion, 1, 'Unsupported Twig helper fixture schema.');
  assert.ok(Array.isArray(corpus.cases) && corpus.cases.length > 0, 'Twig helper cases must be a nonempty array.');
  const ids = new Set();

  for (const fixture of corpus.cases) {
    assert.ok(fixture.id && !ids.has(fixture.id), `Duplicate or missing fixture ID: ${fixture.id}`);
    ids.add(fixture.id);
    assert.ok(['bem', 'add_attributes'].includes(fixture.helper), `${fixture.id}: unknown helper`);
    assert.ok(Array.isArray(fixture.args), `${fixture.id}: helper arguments must be an array.`);
    const differences = fixture.differences || [];
    for (const field of ['attributes', 'html', 'contextAfter']) {
      const notes = differences.filter((entry) => entry.field === field);
      const same = isDeepStrictEqual(fixture.php[field], fixture.js[field]);
      assert.equal(notes.length, same ? 0 : 1, `${fixture.id}: ${field} needs exactly one reason when different, and none when equal.`);
      assert.ok(notes.every((entry) => typeof entry.reason === 'string' && entry.reason.trim()), `${fixture.id}: empty difference reason`);
    }
    assert.ok(differences.every((entry) => ['attributes', 'html', 'contextAfter'].includes(entry.field)), `${fixture.id}: unknown difference field`);

    const values = structuredClone(fixture.context || {});
    const invocation = { context: { ...fixture.contextVars, attributes: fixture.contextKind === 'bag' ? addAttributes(values) : values } };
    const args = fixture.args;
    const html = [];
    let attributes;
    for (let index = 0; index < (fixture.repeat || 1); index += 1) {
      attributes = fixture.helper === 'bem'
        ? bemAttributes(args[0], args[1], args[2], args[3], args[4], invocation)
        : addAttributes(args[0], invocation);
      html.push(String(attributes));
    }
    const result = { attributes: attributes.toObject(), html: html.join('|'), contextAfter: invocation.context.attributes };
    assert.deepEqual(result, fixture.js, `${fixture.id}: Core helper output changed; review the exact attributes, HTML, and remaining context.`);
  }
  console.log(`PASS ${corpus.cases.length} JavaScript Twig helper cases on Core ${core.version}.`);

  const php = spawnSync('php', [path.join(__dirname, 'twig-helper-contract.php')], { stdio: 'inherit' });
  if (php.error) throw php.error;
  assert.equal(php.status, 0, 'PHP Twig helper contract failed.');
}

main().catch((error) => { console.error(error); process.exitCode = 1; });
