#!/usr/bin/env node

// Resolve the same fixture trees through installed Core and the WordPress loader.
const assert = require('node:assert/strict');
const fs = require('node:fs');
const os = require('node:os');
const path = require('node:path');
const { createRequire } = require('node:module');
const { spawnSync } = require('node:child_process');
const { pathToFileURL } = require('node:url');

async function main() {
  assert(process.argv.length <= 3, 'Usage: component-resolution-contract.cjs [installed-theme-directory]');
  const project = path.resolve(process.argv[2] || path.join(__dirname, '../../whisk'));
  const requireProject = createRequire(path.join(project, 'package.json'));
  const corePackage = requireProject.resolve('@emulsify/core/package.json');
  const core = JSON.parse(fs.readFileSync(corePackage, 'utf8'));
  assert.equal(core.version, '4.5.0', 'Review component-resolution expectations before changing the Core 4.5.0 baseline.');
  const coreRoot = path.dirname(corePackage);
  const loadCore = (file) => import(pathToFileURL(path.join(coreRoot, file)));
  const { resolveComponentReference } = await loadCore('config/vite/utils/twig-component-resolver.js');
  const { resolveProjectConfig } = await loadCore('config/vite/project-config.js');
  const { makeTwigPluginOptions, emulsifyTwigModulePlugin } = await loadCore('config/vite/plugins/twig/twig-module.js');
  const temporary = fs.mkdtempSync(path.join(os.tmpdir(), 'emulsify-component-contract-'));
  const fixtures = [];
  const write = (root, relative, text = relative) => {
    const file = path.join(root, relative);
    fs.mkdirSync(path.dirname(file), { recursive: true });
    fs.writeFileSync(file, text);
    return file;
  };
  const canonical = (file) => file ? fs.realpathSync(file) : null;
  const add = (id, implementations = null, extra = {}) => {
    const child = path.join(temporary, id, 'child');
    const parent = path.join(temporary, id, 'parent');
    fs.mkdirSync(child, { recursive: true });
    fs.mkdirSync(parent, { recursive: true });
    const config = { project: { machineName: 'whisk', platform: 'wordpress' } };
    if (implementations) config.variant = { structureImplementations: implementations };
    if (!extra.missingConfig) write(child, 'project.emulsify.json', JSON.stringify(config));
    const fixture = { id, child, parent, namespaces: {}, references: [], differences: {}, ...extra };
    fixtures.push(fixture);
    return fixture;
  };
  const aliases = ['@components/button/button.twig', '@components/button', 'project:button', '@organisms/button', 'anything:button', '@anything:button', 'whisk:button', '@whisk/button', 'components:button', 'components::button', 'components/button'];
  try {
    const ordinary = add('ordinary');
    const componentRoot = path.join(ordinary.child, 'src/components');
    const files = [
      'atoms/button/button.twig', 'ui/controls/deep/deep.twig',
      '01-first/duplicate/duplicate.twig', '02-second/duplicate/duplicate.twig',
      'a-depth/more/breadth/breadth.twig', 'z-depth/breadth/breadth.twig',
      '\u{1F600}/unicode/unicode.twig', '\u{E000}/unicode/unicode.twig',
      'flat.twig', 'flat.html.twig', 'flat/flat.twig', 'nested/nested.html.twig',
      'exact', 'exact.twig', 'html.html.twig', 'html/html.twig',
      'direct.twig', 'atoms/direct/direct.twig', 'badge.twig',
      'atoms/override/override.twig', 'missing.twig',
    ];
    for (const file of files) write(componentRoot, file.replace('\\u{1F600}', '\u{1F600}').replace('\\u{E000}', '\u{E000}'));
    write(ordinary.parent, 'src/components/override.twig');
    write(ordinary.parent, 'src/components/parent-only.twig');
    write(ordinary.child, 'components/legacy-only.twig');
    ordinary.references.push(...aliases,
      ...['deep', 'duplicate', 'breadth', 'unicode', 'flat', 'nested', 'exact', 'html', 'direct', 'override', 'badge.twig', './badge', 'missing/../badge', 'atoms\\button', 'atoms//button', 'atoms/button/'].map((name) => `whisk:${name}`),
      '@components/deep', '@components/missing/button', 'whisk:missing/button',
      'whisk:missing-component', '@components/../outside', 'whisk:../../outside',
      'whisk:external', 'whisk:escape-file', 'whisk:inside-file', 'whisk:inside-directory/button.twig',
      'whisk:parent-only', 'whisk:legacy-only',
      'flat', 'atoms/button/button.twig', 'missing/button', '@flat', 'whisk:../flat', 'whisk:bad\0name',
      'whisk:escape-directory/external/external.twig');
    const outside = path.join(temporary, 'outside');
    write(outside, 'external/external.twig');
    write(outside, 'escape.twig');
    fs.symlinkSync(outside, path.join(componentRoot, 'escape-directory'));
    fs.symlinkSync(path.join(outside, 'escape.twig'), path.join(componentRoot, 'escape-file.twig'));
    fs.symlinkSync(path.join(componentRoot, 'badge.twig'), path.join(componentRoot, 'inside-file.twig'));
    fs.symlinkSync(path.join(componentRoot, 'atoms/button'), path.join(componentRoot, 'inside-directory'));
    for (const [index, directory] of ['node_modules', '.git', '.cache', '.vite', '.out', '.coverage', 'dist'].entries()) {
      write(componentRoot, `${directory}/skip-${index}/skip-${index}.twig`);
      write(componentRoot, `atoms/${directory}/nested-skip-${index}/nested-skip-${index}.twig`);
      ordinary.references.push(`whisk:skip-${index}`, `@components/nested-skip-${index}`);
    }
    ordinary.differences['whisk:parent-only'] = {
      reason: 'WordPress extends Core with parent-theme fallback roots after every child candidate.',
      wordpress: canonical(path.join(ordinary.parent, 'src/components/parent-only.twig')),
    };
    ordinary.differences['whisk:legacy-only'] = {
      reason: 'WordPress retains the secondary child components root for existing generated themes.',
      wordpress: canonical(path.join(ordinary.child, 'components/legacy-only.twig')),
    };
    for (const reference of ['flat', 'atoms/button/button.twig', '@flat']) {
      ordinary.differences[reference] = {
        reason: 'WordPress leaves bare paths and @name without a slash to native Twig rather than shadowing theme templates with component files.',
        wordpress: null,
      };
    }
    ordinary.differences['whisk:../flat'] = {
      reason: 'WordPress rejects leading traversal before grouped lookup, even when Core finds a final candidate contained by the root.',
      wordpress: null,
    };

    const custom = add('custom-first', [{ name: 'custom', directory: 'custom-twig' }, { name: '@unsafe', directory: '../outside' }]);
    write(custom.child, 'custom-twig/teaser.twig');
    write(custom.child, 'custom-twig/atoms/button/button.twig');
    write(custom.child, 'src/components/example-card/example-card.twig');
    custom.references.push(...aliases, 'whisk:teaser', '@components/teaser.twig', 'whisk:example-card', '@components/example-card/example-card.twig');
    custom.pluginReferences = ['@custom/teaser.twig', '@custom/button.twig'];

    const explicit = add('explicit-components', [{ name: 'custom', directory: 'custom-twig' }, { name: 'components', directory: 'design-system' }]);
    write(explicit.child, 'custom-twig/teaser.twig');
    write(explicit.child, 'design-system/atoms/button/button.twig');
    write(explicit.child, 'src/components/button/button.twig');
    explicit.references.push(...aliases, 'whisk:teaser', '@components/teaser.twig');
    explicit.pluginReferences = ['@custom/teaser.twig'];

    const safeFirst = add('unsafe-first', [{ name: '@unsafe', directory: '../outside' }, { name: 'custom', directory: 'custom-twig' }]);
    write(safeFirst.child, 'custom-twig/atoms/button/button.twig');
    write(safeFirst.child, 'src/components/button/button.twig');
    safeFirst.references.push(...aliases);

    const normalized = add('normalized-components', [{ name: ' Custom ', directory: 'custom-twig' }, { name: ' COMPONENTS ', directory: 'design-system' }]);
    write(normalized.child, 'custom-twig/teaser.twig');
    write(normalized.child, 'design-system/atoms/button/button.twig');
    write(normalized.child, 'src/components/button/button.twig');
    normalized.references.push(...aliases);
    normalized.pluginReferences = ['@custom/teaser.twig'];

    const unnamed = add('unnamed-first', [{ directory: 'custom-twig' }]);
    write(unnamed.child, 'custom-twig/atoms/button/button.twig');
    write(unnamed.child, 'custom-twig/teaser.twig');
    write(unnamed.child, 'src/components/button/button.twig');
    unnamed.references.push(...aliases);
    unnamed.pluginReferences = ['@structure-1/teaser.twig'];

    const legacy = add('legacy-only');
    write(legacy.child, 'components/atoms/button/button.twig');
    legacy.references.push(...aliases);

    const literal = add('literal-prefixes');
    write(literal.child, 'src/components/literal:button.twig');
    write(literal.child, 'src/components/@literal/button.twig');
    write(literal.child, 'src/components/atoms/button/button.twig');
    literal.references.push('literal:button', '@literal/button');

    const missing = add('missing-config', null, { missingConfig: true });
    write(missing.child, 'src/components/atoms/button/button.twig');
    missing.references.push('@components/button', 'project:button', '@organisms/button');

    const registered = add('registered-namespaces');
    write(registered.child, 'src/components/button.twig');
    write(registered.child, 'templates/button.twig');
    const pluginRoot = path.join(registered.child, 'plugin');
    write(pluginRoot, 'present.twig');
    registered.namespaces = { plugin: pluginRoot, templates: path.join(registered.child, 'templates'), __main__: path.join(registered.child, 'templates') };
    registered.pluginReferences = ['@templates/button.twig', '@plugin/present.twig', '@plugin/button.twig', 'plugin:button.twig', 'button.twig'];
    registered.differences['button.twig'] = {
      reason: 'Bare WordPress references retain native Twig controller/template paths even when Core would choose a component file.',
      wordpress: canonical(path.join(registered.child, 'templates/button.twig')),
    };

    const collision = add('registered-project-name');
    write(collision.child, 'src/components/button.twig');
    write(collision.child, 'plugin/button.twig');
    collision.namespaces = { whisk: path.join(collision.child, 'plugin') };
    collision.pluginReferences = ['@whisk/button.twig'];

    const registeredComponents = add('registered-components');
    write(registeredComponents.child, 'src/components/atoms/button/button.twig');
    const registeredComponentRoot = path.join(registeredComponents.child, 'plugin-components');
    write(registeredComponentRoot, 'atoms/button/button.twig');
    registeredComponents.namespaces = { components: registeredComponentRoot };
    registeredComponents.references.push(...aliases);

    const filtered = add('filtered-components');
    filtered.componentRootOverride = path.join(temporary, filtered.id, 'filtered-components');
    write(filtered.componentRootOverride, 'atoms/button/button.twig');
    for (const theme of [filtered.child, filtered.parent]) {
      for (const relative of ['src/components', 'components']) {
        assert(!fs.existsSync(path.join(theme, relative)), 'The filtered-root fixture must not register a native components namespace.');
      }
    }
    filtered.references.push(...aliases);

    const expected = {};
    for (const fixture of fixtures) {
      const env = resolveProjectConfig(fixture.child, {});
      const options = makeTwigPluginOptions(env);
      options.namespaces = { ...options.namespaces, ...fixture.namespaces, ...(fixture.componentRootOverride ? { components: fixture.componentRootOverride } : {}) };
      const groups = new Map();
      expected[fixture.id] = Object.fromEntries(fixture.references.map((reference) => [reference, canonical(resolveComponentReference(reference, options.namespaces, groups))]));
      if (fixture.pluginReferences) {
        const plugin = emulsifyTwigModulePlugin(options);
        plugin.buildStart();
        for (const [index, reference] of fixture.pluginReferences.entries()) {
          const entry = write(fixture.child, `contract-entry-${index}.twig`, `{% include ${JSON.stringify(reference)} %}`);
          const dependencies = [];
          const transformed = await plugin.transform.call({ addWatchFile(file) { dependencies.push(file); } }, fs.readFileSync(entry, 'utf8'), entry);
          assert(transformed && typeof transformed.code === 'string');
          assert(dependencies.length <= 1, `${fixture.id}: fixture templates must have no transitive includes.`);
          expected[fixture.id][reference] = canonical(dependencies[0]);
          fixture.references.push(reference);
        }
      }
    }
    const php = spawnSync('php', [path.join(__dirname, 'component-resolution-contract.php')], {
      encoding: 'utf8', input: JSON.stringify(fixtures), maxBuffer: 1024 * 1024 * 10,
    });
    if (php.error) throw php.error;
    assert.equal(php.status, 0, `WordPress resolution helper failed:\n${php.stderr}\n${php.stdout}`);
    const actual = JSON.parse(php.stdout);
    const failures = [];
    let cases = 0;
    let differences = 0;
    for (const fixture of fixtures) {
      for (const reference of fixture.references) {
        cases++;
        const corePath = expected[fixture.id][reference];
        const wordpressPath = actual[fixture.id][reference];
        const difference = fixture.differences[reference];
        if (difference) {
          differences++;
          assert(difference.reason.trim(), 'Every intentional difference needs a reason.');
          assert.notEqual(corePath, difference.wordpress, 'Remove obsolete intentional differences.');
          if (wordpressPath !== difference.wordpress) failures.push(`${fixture.id} ${reference}: expected intentional WordPress result ${difference.wordpress}; got ${wordpressPath}`);
          console.log(`DIFFERENCE ${fixture.id} ${reference}: ${difference.reason}`);
        } else if (corePath !== wordpressPath) {
          failures.push(`${fixture.id} ${reference}: Core ${corePath}; WordPress ${wordpressPath}`);
        }
      }
    }
    assert.equal(failures.length, 0, `Component resolution differs from Core ${core.version} across ${cases} references and ${fixtures.length} trees:\n${failures.join('\n')}`);
    console.log(`PASS ${cases} component references across ${fixtures.length} trees on Core ${core.version}; ${differences} documented WordPress differences.`);
  } finally {
    fs.rmSync(temporary, { recursive: true, force: true });
  }
}

main().catch((error) => { console.error(error); process.exitCode = 1; });
