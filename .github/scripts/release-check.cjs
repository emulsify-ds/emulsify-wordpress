#!/usr/bin/env node

// Static release contract checks plus optional WordPress fixture smoke coverage.
// This script makes release assumptions executable so broad refactors do not
// silently drop a parent-theme hook, generated child convention, or CI guarantee.

const fs = require('fs');
const path = require('path');
const childProcess = require('child_process');
const yaml = require('js-yaml');
const { validateReleaseVersionContract } = require('./release-version-contract.cjs');
const { validateReadinessMatrix } = require('./readiness-matrix-contract.cjs');
const { validateThemeJsonStyles } = require('./theme-json-style-contract.cjs');

const repoRoot = path.resolve(__dirname, '../..');
const skipSmoke = process.argv.slice(2).includes('--skip-smoke');
const results = [];

function addResult(status, name, detail) {
  results.push({ status, name, detail });
}

function readFile(relativePath) {
  return fs.readFileSync(path.join(repoRoot, relativePath), 'utf8');
}

function readJson(relativePath) {
  return JSON.parse(readFile(relativePath));
}

function ensure(condition, message) {
  if (!condition) {
    throw new Error(message);
  }
}

function semver(value) {
  return /^\d+\.\d+\.\d+(?:[-+][0-9A-Za-z.-]+)?$/.test(String(value));
}

function wordpressVersionAtLeast(version, minimum) {
  const actualParts = version.split('.').map(Number);
  const minimumParts = minimum.split('.').map(Number);
  for (let index = 0; index < Math.max(actualParts.length, minimumParts.length); index += 1) {
    const difference = (actualParts[index] || 0) - (minimumParts[index] || 0);
    if (difference !== 0) return difference > 0;
  }
  return true;
}

function runStaticCheck(name, callback) {
  // Static checks intentionally inspect files as text. They catch packaging and
  // documentation regressions without needing a full WordPress install.
  try {
    const detail = callback();
    addResult('PASS', name, detail);
  }
  catch (error) {
    addResult('FAIL', name, error.message);
  }
}

function runCommandCheck(name, command, args) {
  // Focused contract tests always run. Runtime smoke commands can also report
  // missing local prerequisites such as WP-CLI or MySQL as an explicit skip.
  const result = childProcess.spawnSync(command, args, {
    cwd: repoRoot,
    encoding: 'utf8',
    env: process.env,
    maxBuffer: 1024 * 1024 * 20,
  });
  const output = `${result.stdout || ''}${result.stderr || ''}`.trim();

  if (result.status !== 0) {
    addResult('FAIL', name, output || `${command} ${args.join(' ')} failed with exit code ${result.status}.`);
    return;
  }

  if (output.includes('WORDPRESS_SMOKE_SKIPPED')) {
    addResult('SKIP', name, output.split(/\r?\n/).filter(Boolean).pop());
    return;
  }

  addResult('PASS', name, output.split(/\r?\n/).filter(Boolean).pop() || `${command} ${args.join(' ')} passed.`);
}

function listFilesRecursive(relativePath, predicate) {
  const absolutePath = path.join(repoRoot, relativePath);
  if (!fs.existsSync(absolutePath)) {
    return [];
  }

  const files = [];
  for (const entry of fs.readdirSync(absolutePath, { withFileTypes: true })) {
    const childPath = path.join(relativePath, entry.name);
    if (entry.isDirectory()) {
      if (childPath === '.git') {
        continue;
      }
      files.push(...listFilesRecursive(childPath, predicate));
    }
    else if (predicate(childPath)) {
      files.push(childPath);
    }
  }

  return files;
}

function extractJsonObjectSegment(text, key) {
  const keyPattern = new RegExp(`"${key}"\\s*:\\s*\\{`);
  const match = keyPattern.exec(text);
  if (!match) {
    return null;
  }

  const start = text.indexOf('{', match.index);
  let depth = 0;
  let inString = false;
  let escaped = false;

  for (let index = start; index < text.length; index += 1) {
    const character = text[index];

    if (inString) {
      if (escaped) {
        escaped = false;
      }
      else if (character === '\\') {
        escaped = true;
      }
      else if (character === '"') {
        inString = false;
      }
      continue;
    }

    if (character === '"') {
      inString = true;
      continue;
    }

    if (character === '{') {
      depth += 1;
      continue;
    }

    if (character === '}') {
      depth -= 1;
      if (depth === 0) {
        return text.slice(start, index + 1);
      }
    }
  }

  return null;
}

function findDuplicatePackageScripts(relativePath) {
  const raw = readFile(relativePath);
  const scriptsBlock = extractJsonObjectSegment(raw, 'scripts');
  ensure(scriptsBlock, `Unable to locate the scripts block in ${relativePath}.`);

  const keys = [...scriptsBlock.matchAll(/^\s*"([^"]+)"\s*:/gm)].map((match) => match[1]);
  const seen = new Set();
  const duplicates = new Set();

  for (const key of keys) {
    if (seen.has(key)) {
      duplicates.add(key);
    }
    seen.add(key);
  }

  return [...duplicates].sort();
}

function parseWordPressThemeHeader(relativePath) {
  const header = {};
  const contents = readFile(relativePath);

  for (const line of contents.split(/\r?\n/)) {
    const match = line.match(/^\s*\*\s*([^:]+):\s*(.*?)\s*$/);
    if (match) {
      header[match[1].trim()] = match[2].trim();
    }
  }

  return header;
}

function ensureNoTitleCaseBuildPhrase(label, value) {
  ensure(!/Webpack Build|Vite Build/.test(value), `${label} should not use title-case build workflow phrases.`);
}

const incorrectWordPressPattern = new RegExp('Word' + 'press');

function ensureWordPressLanguage(label, value) {
  ensure(value.includes('WordPress'), `${label} should use the canonical WordPress spelling.`);
  ensure(!incorrectWordPressPattern.test(value), `${label} should use the canonical WordPress spelling.`);
}

function ensureViteLanguage(label, value) {
  ensure(value.includes('Emulsify Core 4'), `${label} should mention Emulsify Core 4.`);
  ensure(value.includes('Vite'), `${label} should mention Vite.`);
  ensure(value.includes('Storybook'), `${label} should mention Storybook.`);
  ensure(value.includes('Twig'), `${label} should mention Twig.`);
  ensure(!/Webpack/i.test(value), `${label} should not mention Webpack.`);
}

function ensureParentThemeLanguage(label, value) {
  ensureWordPressLanguage(label, value);
  ensureViteLanguage(label, value);
  ensure(value.includes('Timber-first'), `${label} should use Timber-first parent theme language.`);
  ensure(value.includes('parent theme'), `${label} should describe the parent theme.`);
  ensure(
    value.includes('generated child themes') || value.includes('generates child themes'),
    `${label} should mention generated child themes.`
  );
}

function ensureGeneratedChildThemeLanguage(label, value) {
  ensureWordPressLanguage(label, value);
  ensureViteLanguage(label, value);
  ensure(value.includes('generated') || value.includes('Generated'), `${label} should describe Whisk as generated.`);
  ensure(value.includes('child theme'), `${label} should use child theme language.`);
}

function ensureGpl2LicenseText(label, value) {
  ensure(value.includes('GNU GENERAL PUBLIC LICENSE'), `${label} should contain the GNU GPL license text.`);
  ensure(value.includes('Version 2, June 1991'), `${label} should contain GPL version 2 text.`);
  ensure(!/MIT License/i.test(value), `${label} should not contain MIT license text.`);
}

function getReleasePluginOptions(releaseConfig, pluginName) {
  const plugin = releaseConfig.plugins.find((candidate) => {
    if (Array.isArray(candidate)) {
      return candidate[0] === pluginName;
    }

    return candidate === pluginName;
  });

  ensure(plugin, `release.config.js must include ${pluginName}.`);
  return Array.isArray(plugin) ? plugin[1] || {} : {};
}

function ensureBreakingParser(label, parserOpts) {
  ensure(parserOpts, `${label} parserOpts are required.`);
  ensure(parserOpts.breakingHeaderPattern, `${label} must define breakingHeaderPattern.`);
  ensure(parserOpts.breakingHeaderPattern.test('feat!: remove legacy API'), `${label} should parse feat!: as breaking.`);
  ensure(parserOpts.breakingHeaderPattern.test('feat(theme)!: remove legacy API'), `${label} should parse feat(scope)!: as breaking.`);
  ensure(parserOpts.noteKeywords.includes('BREAKING CHANGE'), `${label} should parse BREAKING CHANGE notes.`);
  ensure(parserOpts.noteKeywords.includes('BREAKING CHANGES'), `${label} should parse BREAKING CHANGES notes.`);
}

function runStaticChecks() {
  const rootPackage = readJson('package.json');
  const whiskPackage = readJson('whisk/package.json');
  const composer = readJson('composer.json');
  const rootThemeHeader = parseWordPressThemeHeader('style.css');
  const whiskThemeHeader = parseWordPressThemeHeader('whisk/style.css');
  const whiskProject = readJson('whisk/project.emulsify.json');
  const releaseConfig = require(path.join(repoRoot, 'release.config.js'));
  // Root package.json is the single source of truth for the release version.
  // Every other version surface is compared against it rather than against a
  // literal, so a missed bump fails here instead of shipping inconsistent
  // generated-theme lineage metadata.
  const releaseVersion = rootPackage.version;
  const semanticReleaseRunner = readFile('.github/scripts/semantic-release.cjs');
  const semanticReleaseWorkflow = readFile('.github/workflows/semantic-release.yml');
  const themeReadinessWorkflow = readFile('.github/workflows/theme-readiness.yml');
  const distBuilder = readFile('scripts/build-dist.sh');
  const gitAttributes = readFile('.gitattributes');
  const docsCommandCheck = readFile('.github/scripts/docs-command-check.cjs');
  const starterInitSmoke = readFile('.github/scripts/wordpress-starter-init-smoke.cjs');
  const whiskReadme = readFile('whisk/README.md');
  const whiskDevelopment = readFile('whisk/docs/development.md');
  const whiskSupportInformation = readFile('whisk/docs/support-information.md');
  const whiskUpgrading = readFile('whisk/docs/upgrading.md');
  const generatedThemeContract = readFile('.github/scripts/generated-theme-contract.cjs');
  const generatedThemeContractTests = readFile('.github/scripts/generated-theme-contract.test.cjs');
  const generatedChildThemeContractDoc = readFile('docs/generated-child-theme-contract.md');
  const generationParitySmoke = readFile('.github/scripts/generation-parity-smoke.cjs');
  const wordpressFixtureSmoke = readFile('.github/scripts/wordpress-fixture-smoke.cjs');
  const whiskA11yConfig = readFile('whisk/config/emulsify-core/a11y.config.js');
  const whiskA11yRunner = readFile('whisk/config/emulsify-core/run-a11y.js');
  const whiskA11yEntry = readFile('.github/fixtures/whisk-a11y/ci-readiness/ci-readiness.js');
  const whiskA11yStory = readFile('.github/fixtures/whisk-a11y/ci-readiness/ci-readiness.stories.js');
  const whiskA11yTemplate = readFile('.github/fixtures/whisk-a11y/ci-readiness/ci-readiness.twig');
  const readme = readFile('README.md');
  const docs = {
    generatedThemeUpgrade: readFile('UPGRADE.md'),
    index: readFile('docs/README.md'),
    upgrading: readFile('docs/upgrading-1x-to-2x.md'),
    parity: readFile('docs/sister-project-parity.md'),
    architecture: readFile('docs/parent-child-architecture.md'),
    twig: readFile('docs/timber-and-twig-authoring.md'),
    workflow: readFile('docs/core-4-vite-workflow.md'),
    componentRecipes: readFile('docs/component-recipes.md'),
    acfJson: readFile('docs/acf-local-json.md'),
    acfBlocks: readFile('docs/acf-twig-blocks.md'),
    coreBlockTwig: readFile('docs/core-block-twig-rendering.md'),
    nativeBlocks: readFile('docs/native-gutenberg-blocks.md'),
    blockPatterns: readFile('docs/block-patterns.md'),
    editorEnhancements: readFile('docs/editor-enhancements.md'),
    editorPolicy: readFile('docs/editor-policy.md'),
    assets: readFile('docs/asset-loading.md'),
    cli: readFile('docs/wp-cli-child-theme-generation.md'),
    release: readFile('docs/release-process.md'),
    post2xRoadmap: readFile('docs/post-2x-optimization-roadmap.md'),
  };
  const docsText = Object.values(docs).join('\n');
  const license = readFile('LICENSE');
  const issueTemplate = readFile('.github/ISSUE_TEMPLATE.md');
  const pullRequestTemplate = readFile('.github/PULL_REQUEST_TEMPLATE.md');
  runStaticCheck('Required files', () => {
    const requiredFiles = [
      '.github/scripts/release-check.cjs',
      '.github/scripts/release-version-contract.cjs',
      '.github/scripts/release-version-contract.test.cjs',
      '.github/scripts/readiness-matrix-contract.cjs',
      '.github/scripts/readiness-matrix-contract.test.cjs',
      '.github/scripts/theme-json-style-contract.cjs',
      '.github/scripts/theme-json-style-contract.test.cjs',
      '.github/scripts/semantic-release.test.cjs',
      '.github/scripts/fallback-navigation-smoke.php',
      '.github/scripts/rendered-a11y.cjs',
      '.github/scripts/rendered-a11y.test.cjs',
      '.github/scripts/media-embed-smoke.php',
      '.github/scripts/media-embed-browser.cjs',
      '.github/scripts/docs-command-check.cjs',
      '.github/scripts/pr-validation.cjs',
      '.github/scripts/semantic-release.cjs',
      '.github/fixtures/whisk-a11y/ci-readiness/ci-readiness.js',
      '.github/fixtures/whisk-a11y/ci-readiness/ci-readiness.stories.js',
      '.github/fixtures/whisk-a11y/ci-readiness/ci-readiness.twig',
      '.github/workflows/semantic-release.yml',
      '.github/workflows/theme-readiness.yml',
      '.gitignore',
      '.nvmrc',
      'AGENTS.md',
      'README.md',
      'UPGRADE.md',
      'docs/README.md',
      'docs/acf-local-json.md',
      'docs/accessibility.md',
      'docs/acf-twig-blocks.md',
      'docs/asset-loading.md',
      'docs/block-patterns.md',
      'docs/component-recipes.md',
      'docs/core-block-twig-rendering.md',
      'docs/core-4-vite-workflow.md',
      'docs/editor-enhancements.md',
      'docs/native-gutenberg-blocks.md',
      'docs/editor-policy.md',
      'docs/parent-child-architecture.md',
      'docs/post-2x-optimization-roadmap.md',
      'docs/release-process.md',
      'docs/sister-project-parity.md',
      'docs/timber-and-twig-authoring.md',
      'docs/upgrading-1x-to-2x.md',
      'docs/wp-cli-child-theme-generation.md',
      'composer.json',
      'composer.lock',
      'phpcs.xml.dist',
      'phpstan.neon.dist',
      'functions.php',
      'includes/Bootstrap.php',
      'includes/Acf/LocalJson.php',
      'includes/Blocks/AcfBlocks.php',
      'includes/Blocks/ComponentLocator.php',
      'includes/Blocks/CoreBlockTwigRenderer.php',
      'includes/Blocks/NativeBlocks.php',
      'includes/Blocks/Patterns.php',
      'includes/Blocks/Registry.php',
      'includes/Cli/GenerateChildThemeCommand.php',
      'includes/Editor/AllowedBlockTypes.php',
      'includes/Editor/BlockNames.php',
      'includes/Editor/BlockSupportOverrides.php',
      'includes/Editor/Enhancements.php',
      'includes/Editor/PatternGovernance.php',
      'includes/Editor/Policy.php',
      'includes/Editor/PolicyOptions.php',
      'includes/Editor/UserPatternPermissions.php',
      'includes/Runtime/Assets.php',
      'includes/Runtime/Context.php',
      'includes/Runtime/MissingTimber.php',
      'includes/Runtime/Setup.php',
      'includes/Runtime/TimberIntegration.php',
      'includes/Runtime/Twig.php',
      'includes/Support/AssetManifest.php',
      'includes/Support/AttributeBag.php',
      'includes/Support/FileDiscovery.php',
      'includes/Twig/SwitchExtension.php',
      'includes/Twig/SwitchNode.php',
      'includes/Twig/SwitchTokenParser.php',
      'package.json',
      'release.config.js',
      'scripts/build-dist.sh',
      'style.css',
      'templates/404.twig',
      'templates/archive.twig',
      'templates/author.twig',
      'templates/index.twig',
      'templates/page.twig',
      'templates/search.twig',
      'templates/single-password.twig',
      'templates/single.twig',
      'docs/template-extension.md',
      '.github/scripts/template-extension-smoke.php',
      '.github/fixtures/template-extension/README.md',
      '.github/scripts/acf-local-json-smoke.php',
      '.github/scripts/asset-manifest-smoke.php',
      '.github/scripts/attribute-helper-smoke.php',
      '.github/scripts/twig-helper-contract.cjs',
      '.github/scripts/twig-helper-contract.php',
      '.github/fixtures/twig-helper-contract.json',
      '.github/scripts/block-scoped-assets-smoke.php',
      '.github/scripts/bootstrap-loader-smoke.php',
      '.github/scripts/child-theme-generator-smoke.php',
      '.github/scripts/component-locator-smoke.php',
      '.github/scripts/editor-enhancements-smoke.php',
      '.github/scripts/editor-policy-smoke.php',
      '.github/scripts/pattern-registry-smoke.php',
      '.github/scripts/wordpress-starter-init-smoke.cjs',
      '.github/scripts/lint-wrappers.test.cjs',
      '.github/scripts/jest-project-smoke.cjs',
      '.github/scripts/a11y-runner.test.cjs',
      '.github/scripts/theme-filters-smoke.php',
      '.github/scripts/twig-autoescape-smoke.php',
      '.github/scripts/twig-project-namespace-smoke.php',
      '.github/scripts/twig-switch-smoke.php',
      '.github/scripts/wordpress-fixture-smoke.cjs',
      'whisk/.cli/init.js',
      'whisk/.gitignore',
      'whisk/.nvmrc',
      'whisk/AGENTS.md',
      'whisk/docs/wordpress-integration.md',
      'whisk/.agents/skills/emulsify-wordpress-integration/SKILL.md',
      'whisk/config/emulsify-core/a11y.config.js',
      'whisk/config/emulsify-core/run-a11y.js',
      'whisk/config/jest.config.js',
      'whisk/functions.php',
      'whisk/package.json',
      'whisk/project.emulsify.json',
      'whisk/style.css',
      'whisk/config/acf-json/.gitkeep',
      'whisk/patterns/.gitkeep',
      'whisk/src/components/.gitkeep',
    ];
    const missingFiles = requiredFiles.filter((file) => !fs.existsSync(path.join(repoRoot, file)));
    ensure(missingFiles.length === 0, `Missing required files: ${missingFiles.join(', ')}.`);
    return `Found ${requiredFiles.length} required release files.`;
  });

  runStaticCheck('Release version contract', () => {
    const { errors, packageVersion, latestStableTag, releasePrepared } = validateReleaseVersionContract({ repoRoot });
    ensure(errors.length === 0, errors.join('\n'));
    return releasePrepared
      ? `Prepared version ${packageVersion} follows latest stable tag ${latestStableTag}.`
      : `Package version ${packageVersion} matches latest stable tag ${latestStableTag}; no release is prepared.`;
  });

  runStaticCheck('Root release metadata', () => {
    ensure(rootPackage.name === 'emulsify-wordpress', 'package.json name should be emulsify-wordpress.');
    ensure(semver(rootPackage.version), 'package.json version must be a valid semver string.');
    ensure(rootPackage.description, 'package.json description is required.');
    ensureParentThemeLanguage('package.json description', rootPackage.description);
    ensureNoTitleCaseBuildPhrase('package.json description', rootPackage.description);
    ensure(rootPackage.license === 'GPL-2.0-only', 'package.json license should be GPL-2.0-only.');
    ensure(rootPackage.engines && rootPackage.engines.node === '>=24.10', 'package.json engines.node should be >=24.10.');
    ensure(rootPackage.repository.url === 'git+https://github.com/emulsify-ds/emulsify-wordpress.git', 'package.json repository.url should target emulsify-wordpress.');
    ensure(rootPackage.bugs.url === 'https://github.com/emulsify-ds/emulsify-wordpress/issues', 'package.json bugs.url should target emulsify-wordpress.');
    ensure(rootPackage.scripts['pr:check'] === 'node .github/scripts/pr-validation.cjs', 'package.json should expose npm run pr:check.');
    ensure(rootPackage.scripts['release:check'] === 'node .github/scripts/release-check.cjs', 'package.json should expose npm run release:check.');
    ensure(rootPackage.scripts['docs:check-commands'] === 'node .github/scripts/docs-command-check.cjs', 'package.json should expose npm run docs:check-commands.');
    ensure(rootPackage.scripts['test:lint-wrappers'] === 'node --test .github/scripts/lint-wrappers.test.cjs', 'package.json should expose the lint wrapper regression tests.');
    ensure(rootPackage.scripts['smoke:jest-project'] === 'node .github/scripts/jest-project-smoke.cjs', 'package.json should expose generated project ESM/coverage smoke coverage.');
    ensure(rootPackage.scripts['test:a11y-runner'] === 'node --test .github/scripts/a11y-runner.test.cjs', 'package.json should expose the Core accessibility wrapper tests.');
    ensure(rootPackage.scripts['build:dist'] === 'bash scripts/build-dist.sh', 'package.json should expose npm run build:dist.');
    ensure(rootPackage.scripts['lint:php'].includes('vendor/bin/phpcs -q'), 'package.json lint:php should run PHPCS.');
    ensure(rootPackage.scripts['lint:php'].includes('vendor/bin/phpstan analyse --no-progress'), 'package.json lint:php should run PHPStan.');
    ensure(rootPackage.scripts['lint:php:fix'] === 'vendor/bin/phpcbf', 'package.json should expose npm run lint:php:fix.');
    ensure(rootPackage.scripts['smoke:acf-json'] === 'php .github/scripts/acf-local-json-smoke.php', 'package.json should expose npm run smoke:acf-json.');
    ensure(rootPackage.scripts['smoke:asset-manifest'] === 'php .github/scripts/asset-manifest-smoke.php', 'package.json should expose npm run smoke:asset-manifest.');
    ensure(rootPackage.scripts['smoke:attributes'] === 'php .github/scripts/attribute-helper-smoke.php', 'package.json should expose npm run smoke:attributes.');
    ensure(rootPackage.scripts['smoke:block-assets'] === 'php .github/scripts/block-scoped-assets-smoke.php', 'package.json should expose npm run smoke:block-assets.');
    ensure(rootPackage.scripts['smoke:bootstrap-loader'] === 'php .github/scripts/bootstrap-loader-smoke.php', 'package.json should expose npm run smoke:bootstrap-loader.');
    ensure(rootPackage.scripts['smoke:child-theme-generator'] === 'php .github/scripts/child-theme-generator-smoke.php', 'package.json should expose npm run smoke:child-theme-generator.');
    ensure(rootPackage.scripts['smoke:component-locator'] === 'php .github/scripts/component-locator-smoke.php', 'package.json should expose npm run smoke:component-locator.');
    ensure(rootPackage.scripts['smoke:editor-enhancements'] === 'php .github/scripts/editor-enhancements-smoke.php', 'package.json should expose npm run smoke:editor-enhancements.');
    ensure(rootPackage.scripts['smoke:editor-policy'] === 'php .github/scripts/editor-policy-smoke.php', 'package.json should expose npm run smoke:editor-policy.');
    ensure(rootPackage.scripts['smoke:patterns'] === 'php .github/scripts/pattern-registry-smoke.php', 'package.json should expose npm run smoke:patterns.');
    ensure(rootPackage.scripts['smoke:starter-init'] === 'node .github/scripts/wordpress-starter-init-smoke.cjs', 'package.json should expose npm run smoke:starter-init.');
    ensure(rootPackage.scripts['smoke:theme-filters'] === 'php .github/scripts/theme-filters-smoke.php', 'package.json should expose npm run smoke:theme-filters.');
    ensure(rootPackage.scripts['smoke:twig-switch'] === 'php .github/scripts/twig-switch-smoke.php', 'package.json should expose npm run smoke:twig-switch.');
    ensure(rootPackage.scripts['smoke:twig-project-namespace'] === 'php .github/scripts/twig-project-namespace-smoke.php', 'package.json should expose npm run smoke:twig-project-namespace.');
    ensure(rootPackage.scripts['smoke:template-extension'] === 'php .github/scripts/template-extension-smoke.php', 'package.json should expose the exact-byte template extension smoke.');
    ensure(rootPackage.scripts['whisk:install'], 'package.json should expose npm run whisk:install.');
    ensure(rootPackage.scripts['whisk:build'] === 'npm --prefix whisk run build', 'package.json should expose npm run whisk:build.');
    ensure(!rootPackage.devDependencies['@semantic-release/changelog'], 'package.json should not declare unused @semantic-release/changelog tooling.');
    ensure(!rootPackage.devDependencies['@semantic-release/git'], 'package.json should not declare unused @semantic-release/git tooling.');
    ensure(!rootPackage.devDependencies['@semantic-release/npm'], 'package.json should not declare unused @semantic-release/npm tooling.');
    ensure(!Object.hasOwn(rootPackage, 'overrides'), 'package.json should not need semantic-release npm overrides.');
    ensure(composer.name === 'emulsify-ds/emulsify-wordpress-theme', 'composer.json should retain the published emulsify-ds/emulsify-wordpress-theme package name.');
    ensure(composer.type === 'wordpress-theme', 'composer.json type should be wordpress-theme.');
    ensure(composer.license === 'GPL-2.0-only', 'composer.json license should be GPL-2.0-only.');
    ensure(composer.homepage === 'https://www.emulsify.info', 'composer.json homepage should use the canonical HTTPS URL.');
    ensure(!Object.hasOwn(composer, 'minimum-stability'), 'composer.json should not lower release stability for a stable parent theme.');
    ensure(!Object.hasOwn(composer, 'prefer-stable'), 'composer.json should not keep prefer-stable when stable-only constraints are sufficient.');
    ensure(composer.require && typeof composer.require.php === 'string' && composer.require.php.startsWith('>=8.3'), 'composer.json should enforce the PHP 8.3 runtime floor.');
    ensure(composer.require && composer.require['composer/installers'] === '^2.3', 'composer.json should require Composer Installers for WordPress theme placement.');
    ensure(composer.require && composer.require['timber/timber'] === '^2.3', 'composer.json should keep the Timber 2 dependency constraint.');
    ensure(composer.extra && composer.extra['installer-name'] === 'emulsify', 'composer.json should install the parent theme under the required emulsify slug.');
    ensure(composer['require-dev'] && composer['require-dev']['wp-coding-standards/wpcs'], 'composer.json should provide WordPress Coding Standards for PHP linting.');
    ensure(composer['require-dev'] && composer['require-dev']['phpstan/phpstan'], 'composer.json should provide PHPStan for static analysis.');
    ensure(composer.config && composer.config['allow-plugins'] && composer.config['allow-plugins']['dealerdirect/phpcodesniffer-composer-installer'], 'composer.json should allow the PHPCS standards installer plugin.');
    ensure(composer.autoload && composer.autoload['psr-4'] && composer.autoload['psr-4']['Emulsify\\Theme\\'] === 'includes/', 'composer.json should expose the runtime namespace through PSR-4 autoloading.');
    ensure(!Object.hasOwn(composer.autoload, 'classmap'), 'composer.json should rely on PSR-4 runtime paths instead of classmap loading.');
    ensure(!Object.hasOwn(composer.autoload, 'files'), 'composer.json should not load removed compatibility files.');
    ensureParentThemeLanguage('composer.json description', composer.description);
    return `Validated root package ${rootPackage.version} and composer metadata.`;
  });

  runStaticCheck('WordPress theme headers', () => {
    ensure(rootThemeHeader['Theme Name'] === 'Emulsify', 'style.css Theme Name should be Emulsify.');
    ensure(rootThemeHeader['Text Domain'] === 'emulsify', 'style.css Text Domain should be emulsify.');
    ensure(rootThemeHeader.Version === rootPackage.version, 'style.css Version should match package.json version.');
    ensure(rootThemeHeader.Version === releaseVersion, `style.css Version should match the ${releaseVersion} release line.`);
    ensure(rootThemeHeader.License === 'GPL-2.0-only', 'style.css License should be GPL-2.0-only.');
    ensure(rootThemeHeader['License URI'] === 'https://www.gnu.org/licenses/old-licenses/gpl-2.0.html', 'style.css License URI should point to GPLv2.');
    ensure(rootThemeHeader['Requires PHP'] === '8.3', 'style.css Requires PHP should stay aligned to the release baseline.');
    ensureParentThemeLanguage('style.css Description', rootThemeHeader.Description);
    ensure(whiskThemeHeader['Theme Name'] === 'Whisk', 'whisk/style.css Theme Name should be Whisk.');
    ensure(whiskThemeHeader.Template === 'emulsify', 'whisk/style.css Template should be emulsify.');
    ensure(whiskThemeHeader['Text Domain'] === 'whisk', 'whisk/style.css Text Domain should be whisk.');
    ensure(whiskThemeHeader.Version === whiskPackage.version, 'whisk/style.css Version should match whisk/package.json version.');
    ensure(whiskThemeHeader.Version === releaseVersion, `whisk/style.css Version should match the ${releaseVersion} release line.`);
    ensure(whiskThemeHeader.License === 'GPL-2.0-only', 'whisk/style.css License should be GPL-2.0-only.');
    ensure(whiskThemeHeader['License URI'] === 'https://www.gnu.org/licenses/old-licenses/gpl-2.0.html', 'whisk/style.css License URI should point to GPLv2.');
    ensureGeneratedChildThemeLanguage('whisk/style.css Description', whiskThemeHeader.Description);
    return 'Parent and Whisk WordPress theme headers are coherent with package metadata.';
  });

  runStaticCheck('WordPress minimum compatibility', () => {
    const minimum = rootThemeHeader['Requires at least'];
    ensure(minimum === '6.8', 'style.css Requires at least must be 6.8 for this release.');
    const timberPath = 'vendor/timber/timber/src/Timber.php';
    ensure(fs.existsSync(path.join(repoRoot, timberPath)), 'Installed Timber source is required to verify its WordPress minimum; run composer install.');
    const timberMinimum = readFile(timberPath).match(/\bMINIMUM_WP_VERSION\s*=\s*['"](\d+\.\d+(?:\.\d+)?)['"]/);
    ensure(timberMinimum, 'Unable to read installed Timber MINIMUM_WP_VERSION; run composer install and review Timber compatibility.');
    ensure(wordpressVersionAtLeast(minimum, timberMinimum[1]), `style.css Requires at least (${minimum}) is below installed Timber MINIMUM_WP_VERSION (${timberMinimum[1]}); raise the theme minimum before releasing.`);
    return `Theme minimum ${minimum} supports installed Timber's WordPress minimum ${timberMinimum[1]}.`;
  });

  runStaticCheck('WordPress tested compatibility', () => {
    const tested = rootThemeHeader['Tested up to'];
    const minimum = rootThemeHeader['Requires at least'];
    ensure(/^\d+\.\d+(?:\.\d+)?$/.test(tested), 'style.css Tested up to must name a stable WordPress version verified by the blocking fixture.');
    ensure(wordpressVersionAtLeast(tested, minimum), 'style.css Tested up to must not be lower than Requires at least.');
    return `Tested up to ${tested} covers theme minimum ${minimum}; the current stable WordPress fixture is blocking.`;
  });

  runStaticCheck('PHP analysis scope', () => {
    const phpcsConfig = readFile('phpcs.xml.dist');
    const phpstanConfig = readFile('phpstan.neon.dist');
    const runtimePaths = [
      'includes',
      '404.php',
      'archive.php',
      'author.php',
      'functions.php',
      'index.php',
      'page.php',
      'search.php',
      'single.php',
    ];

    for (const runtimePath of runtimePaths) {
      ensure(phpcsConfig.includes(`<file>${runtimePath}</file>`), `phpcs.xml.dist should analyze ${runtimePath}.`);
      const phpstanPath = runtimePath === 'includes' ? 'includes/' : runtimePath;
      ensure(phpstanConfig.includes(`- ${phpstanPath}`), `phpstan.neon.dist should analyze ${phpstanPath}.`);
    }

    return 'PHPCS and PHPStan cover the parent runtime directory and root PHP entry points.';
  });

  runStaticCheck('Timber attribute helpers', () => {
    const bootstrap = readFile('includes/Bootstrap.php');
    const twig = readFile('includes/Runtime/Twig.php');
    const attributeBag = readFile('includes/Support/AttributeBag.php');
    const smoke = readFile('.github/scripts/attribute-helper-smoke.php');

    ensure(bootstrap.includes('load_vendor_autoload();') && bootstrap.includes('load_classes();'), 'Bootstrap should load Composer before registering fallback runtime loading.');
    ensure(bootstrap.indexOf('load_vendor_autoload();') < bootstrap.indexOf('load_classes();'), 'Bootstrap should try Composer autoloading before fallback runtime loading.');
    ensure(bootstrap.includes('spl_autoload_register'), 'Bootstrap should register a fallback runtime autoloader.');
    ensure(bootstrap.includes('runtime_class_file'), 'Bootstrap should resolve runtime classes through a fallback file mapper.');
    ensure(attributeBag.includes('implements \\Stringable'), 'AttributeBag should serialize safely in Twig string contexts.');
    ensure(attributeBag.includes('function addClass'), 'AttributeBag should support Core-style class merging.');
    ensure(attributeBag.includes('function toString'), 'AttributeBag should expose explicit serialization.');
    ensure(twig.includes("'needs_context' => true"), 'Timber helper functions should accept Twig context.');
    ensure(twig.includes('new AttributeBag'), 'Twig helpers should return AttributeBag objects.');
    ensure(smoke.includes('{{ bem("example-card", ["featured"]) }}'), 'Attribute helper smoke script should render a bem() Twig fixture.');
    ensure(smoke.includes('{{ add_attributes({ class: ["foo"] }) }}'), 'Attribute helper smoke script should render an add_attributes() Twig fixture.');
    return 'Attribute helper runtime and smoke fixture are wired.';
  });

  runStaticCheck('Twig helper characterization', () => {
    ensure(rootPackage.scripts['test:twig-helpers'] === 'node .github/scripts/twig-helper-contract.cjs', 'Root package should expose the PHP/JavaScript Twig helper characterization.');
    const prValidation = readFile('.github/scripts/pr-validation.cjs');
    const install = prValidation.indexOf("run('Install Whisk dependencies'");
    const compare = prValidation.indexOf("['run', 'test:twig-helpers']");
    ensure(install >= 0 && compare > install, 'PR validation must compare both helper runtimes after installing Whisk/Core.');
    ensure(docs.twig.includes('## Helper characterization'), 'Twig authoring docs must explain the characterization and recorded differences.');
    return 'The PR gate compares exact PHP and installed Core helper output against one explicit-difference corpus.';
  });

  runStaticCheck('Twig HTML autoescaping', () => {
    const twig = readFile('includes/Runtime/Twig.php');
    const smoke = readFile('.github/scripts/twig-autoescape-smoke.php');

    ensure(twig.includes("timber/twig/environment/options"), 'Timber integration should register the Twig environment options filter.');
    ensure(twig.includes("$options['autoescape'] = 'html'"), 'Timber integration should enable HTML autoescaping.');
    ensure(smoke.includes('{{ fields.heading }}'), 'Twig autoescape smoke should render a field value.');
    ensure(smoke.includes('<script>alert(1)</script>'), 'Twig autoescape smoke should use a hostile script fixture.');
    ensure(smoke.includes("$definition['options']"), 'Twig autoescape smoke should register the same helper options as Timber.');
    return 'Twig HTML autoescaping is registered and covered by a behavioral smoke test.';
  });

  runStaticCheck('Twig switch tags', () => {
    const twig = readFile('includes/Runtime/Twig.php');
    const extension = readFile('includes/Twig/SwitchExtension.php');
    const parser = readFile('includes/Twig/SwitchTokenParser.php');
    const node = readFile('includes/Twig/SwitchNode.php');
    const smoke = readFile('.github/scripts/twig-switch-smoke.php');

    ensure(twig.includes("add_filter( 'timber/twig', array( $this, 'extensions' ) )"), 'Timber integration should register custom Twig extensions.');
    ensure(twig.includes('new SwitchExtension()'), 'Timber integration should add the switch extension.');
    ensure(extension.includes('new SwitchTokenParser()'), 'Switch extension should expose the switch token parser.');
    ensure(parser.includes("return 'switch';") && parser.includes("case 'case':") && parser.includes("case 'default':") && parser.includes("case 'endswitch':"), 'Switch parser should support switch, case, default, and endswitch tags.');
    ensure(node.includes("->write( 'switch (' )") && node.includes('->write( "break;\\n" )'), 'Switch node should compile native switch branches with automatic breaks.');
    ensure(smoke.includes("{% case 'alpha' or 'beta' %}") && smoke.includes('automatic case break'), 'Twig switch smoke should cover multiple values and no fall-through.');
    ensure(docs.twig.includes('## Switch statements') && docs.twig.includes('{% switch variant %}'), 'Twig authoring docs should document switch statements.');
    return 'Twig switch tags are registered, documented, and covered by a runtime smoke test.';
  });

  runStaticCheck('Bootstrap runtime autoloading', () => {
    const bootstrap = readFile('includes/Bootstrap.php');
    const smoke = readFile('.github/scripts/bootstrap-loader-smoke.php');

    ensure(bootstrap.includes("str_replace( '\\\\', '/', $relative_class )"), 'Bootstrap fallback loader should resolve PSR-4-shaped runtime paths.');
    ensure(smoke.includes("require_once \\$repo_root . '/vendor/autoload.php'"), 'Bootstrap loader smoke should verify Composer autoloading.');
    ensure(smoke.includes("require_once \\$repo_root . '/includes/Bootstrap.php'"), 'Bootstrap loader smoke should verify fallback loading without Composer.');
    ensure(smoke.includes('Emulsify\\\\Theme\\\\Blocks\\\\Registry'), 'Bootstrap loader smoke should cover nested block runtime classes.');
    ensure(smoke.includes('Fallback loader did not load'), 'Bootstrap loader smoke should fail clearly when fallback loading breaks.');
    return 'Composer autoloading and Bootstrap fallback loading are covered.';
  });

  runStaticCheck('Child theme generator', () => {
    const cli = readFile('includes/Cli/GenerateChildThemeCommand.php');
    const smoke = readFile('.github/scripts/child-theme-generator-smoke.php');

    ensure(cli.includes('[--machine-name=<slug>]'), 'WP-CLI help should document --machine-name.');
    ensure(cli.includes('[--parent=<slug>]'), 'WP-CLI help should document --parent.');
    ensure(cli.includes('[--dry-run]'), 'WP-CLI help should document --dry-run.');
    ensure(cli.includes('[--force]'), 'WP-CLI help should document --force.');
    ensure(cli.includes('[--activate]'), 'WP-CLI help should document --activate.');
    ensure(cli.includes('collect_metadata_updates'), 'Child theme generator should use targeted metadata updates.');
    ensure(cli.includes("replace_theme_header( $contents, 'Theme Name'"), 'Child theme generator should update Theme Name explicitly.');
    ensure(cli.includes("replace_theme_header( $contents, 'Text Domain'"), 'Child theme generator should update Text Domain explicitly.');
    ensure(cli.includes("replace_theme_header( $contents, 'Template'"), 'Child theme generator should update Template explicitly.');
    ensure(cli.includes("data['project']['name']"), 'Child theme generator should update project.emulsify.json project.name.');
    ensure(cli.includes("data['project']['machineName']"), 'Child theme generator should update project.emulsify.json project.machineName.');
    ensure(cli.includes("data['project']['generatedFrom']"), 'Child theme generator should update project.emulsify.json generatedFrom.');
    ensure(cli.includes("data['project']['generatedFromVersion']"), 'Child theme generator should update project.emulsify.json generatedFromVersion.');
    ensure(cli.includes("$this->join_path( dirname( $source ), 'style.css' ), 'Version'"), 'Child theme generator should read release lineage from installed WordPress theme metadata.');
    ensure(cli.includes("data['name'] = $machine_name"), 'Child theme generator should update package.json name.');
    ensure(cli.includes('collect_pattern_updates'), 'Child theme generator should update starter pattern namespaces.');
    ensure(cli.includes('sanitize_label_for_source'), 'Child theme generator should sanitize human labels before writing source comments.');
    ensure(cli.includes("replace_theme_header( $contents, 'Theme Name', $source_label )"), 'Child theme generator should use a source-safe label in style.css.');
    ensure(cli.includes("$source_label . ' child theme hooks.'"), 'Child theme generator should use a source-safe label in functions.php.');
    ensure(cli.includes('get_destination_replacement_error'), 'Child theme generator should verify existing destinations before force replacement.');
    ensure(cli.includes('project.platform: wordpress'), 'Child theme generator should require WordPress project metadata before force replacement.');
    ensure(cli.includes("if ( ! isset( $project['project']['generatedFrom'] )"), 'Child theme generator should require generatedFrom metadata before force replacement.');
    ensure(cli.includes("self::GENERATED_FROM !== $project['project']['generatedFrom']"), 'Child theme generator should require emulsify-wordpress lineage before force replacement.');
    ensure(cli.includes("if ( ! isset( $project['project']['generatedFromVersion'] )"), 'Child theme generator should require generatedFromVersion metadata before force replacement.');
    ensure(cli.includes("$machine_name !== $project['project']['machineName']"), 'Child theme generator should require the existing machineName to match the requested destination.');
    ensure(!cli.includes('$has_generated_from'), 'Child theme generator should not treat generatedFrom lineage as optional.');
    ensure(cli.includes('get_unique_sibling_path') && cli.includes("'tmp'") && cli.includes("'bak'"), 'Child theme generator should stage and back up themes in unique sibling paths.');
    ensure(cli.includes('replace_with_staged_theme') && cli.includes('cleanup_staging_path'), 'Child theme generator should atomically swap staged themes and clean failed copies.');
    ensure(!cli.includes('rename_instances'), 'Child theme generator should not use blind recursive starter string replacement.');
    ensure(cli.includes("'.cache'"), 'Child theme generator should exclude local cache directories.');
    ensure(smoke.includes("'machine-name' => 'acme-child'"), 'Child theme generator smoke should cover --machine-name.');
    ensure(smoke.includes("'dry-run' => true"), 'Child theme generator smoke should cover --dry-run.');
    ensure(smoke.includes("'force' => true"), 'Child theme generator smoke should cover --force.');
    ensure(smoke.includes("'activate' => true"), 'Child theme generator smoke should cover --activate.');
    ensure(smoke.includes('unrelated-theme'), 'Child theme generator smoke should prove --force refuses unrelated theme directories.');
    ensure(smoke.includes('Would replace existing destination because --force was provided'), 'Child theme generator smoke should prove --dry-run --force reports replacement intent.');
    ensure(smoke.includes('project.emulsify.json'), 'Child theme generator smoke should validate project.emulsify.json updates.');
    ensure(smoke.includes("assets/images/.gitkeep"), 'Child theme generator smoke should validate copied image asset placeholders.');
    ensure(smoke.includes("assets/icons/.gitkeep"), 'Child theme generator smoke should validate copied icon asset placeholders.');
    ensure(smoke.includes("'wordpress' === $project['project']['platform']"), 'Child theme generator smoke should validate the WordPress platform adapter.');
    ensure(smoke.includes("'emulsify-wordpress' === $project['project']['generatedFrom']"), 'Child theme generator smoke should validate generatedFrom metadata.');
    ensure(smoke.includes('emulsify_cli_smoke_expected_version()'), 'Child theme generator smoke should derive the expected generatedFromVersion from root package metadata instead of a literal.');
    ensure(smoke.includes("copy( $repo_root . '/style.css', $parent_root . '/style.css' )"), 'Child theme generator smoke should exercise an installable parent without root npm tooling.');
    ensure(!/'\d+\.\d+\.\d+' === \$project\['project'\]\['generatedFromVersion'\]/.test(smoke), 'Child theme generator smoke should not hardcode the generated source version.');
    ensure(smoke.includes('foreign-generator'), 'Child theme generator smoke should reject conflicting generatedFrom metadata.');
    ensure(smoke.includes('missing-lineage'), 'Child theme generator smoke should reject destinations without generatedFrom metadata.');
    ensure(smoke.includes('missing-version'), 'Child theme generator smoke should reject destinations without generatedFromVersion metadata.');
    ensure(smoke.includes('different-machine-name'), 'Child theme generator smoke should reject mismatched project.machineName metadata.');
    ensure(smoke.includes('*/ echo 1; /*'), 'Child theme generator smoke should cover hostile source-comment labels.');
    ensure(smoke.includes('emulsify_mkdir_failure') && smoke.includes("'.tmp-*'"), 'Child theme generator smoke should simulate a failed staged copy and verify cleanup.');
    ensure(smoke.includes('smoke-pattern.json'), 'Child theme generator smoke should validate optional copied pattern namespace updates.');
    ensure(smoke.includes("! is_dir( $destination . '/src/components/button' )"), 'Child theme generator smoke should prove removed starter components are not copied.');
    ensure(smoke.includes("! is_dir( $destination . '/src/editor' )"), 'Child theme generator smoke should prove assumed editor modules are not copied.');
    ensure(smoke.includes("! is_dir( $destination . '/src/foundation' )"), 'Child theme generator smoke should prove assumed foundation directories are not copied.');
    ensure(smoke.includes("! is_dir( $destination . '/src/layout' )"), 'Child theme generator smoke should prove assumed layout directories are not copied.');
    ensure(smoke.includes("! is_file( $destination . '/src/foundation.scss' )"), 'Child theme generator smoke should prove assumed Sass entrypoints are not copied.');
    ensure(smoke.includes("! is_file( $destination . '/theme.json' )"), 'Child theme generator smoke should prove empty child theme.json is not copied by default.');
    return 'WP-CLI child theme generation uses safe options and targeted metadata updates.';
  });

  runStaticCheck('Component locator memoization', () => {
    const locator = readFile('includes/Blocks/ComponentLocator.php');
    const fileDiscovery = readFile('includes/Support/FileDiscovery.php');
    const registry = readFile('includes/Blocks/Registry.php');
    const acfBlocks = readFile('includes/Blocks/AcfBlocks.php');
    const nativeBlocks = readFile('includes/Blocks/NativeBlocks.php');
    const smoke = readFile('.github/scripts/component-locator-smoke.php');

    ensure(locator.includes('private $component_roots'), 'Component locator should memoize component roots per request.');
    ensure(locator.includes('private $component_files'), 'Component locator should memoize the recursive component file index per request.');
    ensure(locator.includes('private $acf_components'), 'Component locator should memoize ACF/Twig component records per request.');
    ensure(locator.includes('private $native_block_directories'), 'Component locator should memoize native block directory records per request.');
    ensure(locator.includes('private $skipped_duplicates'), 'Component locator should track skipped duplicate component records.');
    ensure(locator.includes('function component_files'), 'Component locator should expose a shared internal component file index.');
    ensure(locator.includes('$this->component_files()'), 'ACF/Twig and native discovery should use the shared component file index.');
    ensure(locator.includes('FileDiscovery::theme_roots') && locator.includes('FileDiscovery::file_records'), 'Component locator should use shared filesystem discovery helpers.');
    ensure(locator.includes('acf_component_slug'), 'Component locator should detect duplicate ACF/Twig component slugs.');
    ensure(locator.includes('native_block_name'), 'Component locator should detect duplicate native block names.');
    ensure(fileDiscovery.includes('get_stylesheet_directory()') && fileDiscovery.includes('get_template_directory()'), 'File discovery helper should build child and parent theme roots.');
    ensure(fileDiscovery.indexOf('get_stylesheet_directory()') < fileDiscovery.indexOf('get_template_directory()'), 'File discovery helper should keep child roots before parent roots.');
    ensure(locator.includes('emulsify_theme_component_discovery_cache_enabled') && locator.includes('get_transient') && locator.includes('set_transient'), 'Component locator should expose opt-in transient-backed persistent discovery caching.');
    ensure(locator.includes('clear_discovery_cache') && locator.includes('delete_transient'), 'Component locator should provide a clear method for the active discovery cache key.');
    ensure(locator.includes('stylesheet_version') && locator.includes('template_version') && locator.includes('manifest_file_signature'), 'Component locator cache key should include theme versions and manifest filemtime.');
    ensure(locator.includes('wp_get_environment_type') && locator.includes('WP_DEBUG'), 'Component locator should keep active development uncached unless explicitly enabled.');
    ensure(registry.includes('$components = new ComponentLocator()'), 'Block registry should share one ComponentLocator instance.');
    ensure(acfBlocks.includes('$this->components->acf_components()'), 'ACF/Twig block discovery should use ComponentLocator.');
    ensure(!acfBlocks.includes("$args['data']['twig_template'] ="), 'ACF/Twig block registration should not persist template paths in editor block data.');
    ensure(
      acfBlocks.includes("$block['twig_template']")
      && acfBlocks.includes("$block['data']['twig_template']")
      && acfBlocks.indexOf("$block['twig_template']") < acfBlocks.indexOf("$block['data']['twig_template']"),
      'ACF/Twig rendering should prefer the registered template over persisted block data.'
    );
    ensure(smoke.includes('../../hostile.twig'), 'Component locator smoke should prove untrusted persisted template paths are rejected.');
    ensure(acfBlocks.includes('acf_block_name'), 'ACF/Twig block registration should skip duplicate final ACF block names.');
    ensure(acfBlocks.includes('acf_get_block_type'), 'ACF/Twig block registration should avoid already registered ACF block names.');
    ensure(nativeBlocks.includes('$this->components->native_block_directories()'), 'Native block discovery should use ComponentLocator.');
    ensure(nativeBlocks.includes('native_registered_block_name'), 'Native block registration should avoid already registered native block names.');
    ensure(smoke.includes('late-native'), 'Component locator smoke should prove native discovery reuses the memoized file index.');
    ensure(smoke.includes('late-card'), 'Component locator smoke should prove repeated ACF/Twig discovery is memoized per locator instance.');
    ensure(smoke.includes('Child ACF/Twig component metadata should override'), 'Component locator smoke should verify child ACF/Twig priority.');
    ensure(smoke.includes('Child native block metadata should override'), 'Component locator smoke should verify child native block priority.');
    ensure(smoke.includes('duplicate component slugs'), 'Component locator smoke should verify duplicate ACF/Twig component slug reporting.');
    ensure(smoke.includes('duplicate block.json name values'), 'Component locator smoke should verify duplicate native block name reporting.');
    ensure(smoke.includes('emulsify-shared-acf'), 'Component locator smoke should verify duplicate normalized final ACF block name handling.');
    ensure(smoke.includes('Missing persistent discovery cache should fall back') && smoke.includes('Enabled persistent discovery cache should be reused'), 'Component locator smoke should cover enabled persistent cache behavior.');
    ensure(smoke.includes('Changing the child theme version should change') && smoke.includes('Changing the asset manifest mtime should change'), 'Component locator smoke should cover cache key invalidation.');
    ensure(smoke.includes('Invalid persistent discovery cache data should fall back'), 'Component locator smoke should cover invalid cache fallback.');
    return 'Component locator memoizes request-local discovery and offers opt-in persistent caching with duplicate safety.';
  });

  runStaticCheck('Runtime filters', () => {
    const acfJson = readFile('includes/Acf/LocalJson.php');
    const assets = readFile('includes/Runtime/Assets.php');
    const twig = readFile('includes/Runtime/Twig.php');
    const context = readFile('includes/Runtime/Context.php');
    const setup = readFile('includes/Runtime/Setup.php');
    const editorEnhancements = readFile('includes/Editor/Enhancements.php');
    const editorPolicy = readFile('includes/Editor/Policy.php');
    const editorAllowedBlocks = readFile('includes/Editor/AllowedBlockTypes.php');
    const editorBlockSupport = readFile('includes/Editor/BlockSupportOverrides.php');
    const editorPatterns = readFile('includes/Editor/PatternGovernance.php');
    const editorPolicyOptions = readFile('includes/Editor/PolicyOptions.php');
    const editorUserPatterns = readFile('includes/Editor/UserPatternPermissions.php');
    const patterns = readFile('includes/Blocks/Patterns.php');
    const locator = readFile('includes/Blocks/ComponentLocator.php');
    const assetManifest = readFile('includes/Support/AssetManifest.php');
    const fileDiscovery = readFile('includes/Support/FileDiscovery.php');
    const acfBlocks = readFile('includes/Blocks/AcfBlocks.php');
    const nativeBlocks = readFile('includes/Blocks/NativeBlocks.php');
    const acfJsonSmoke = readFile('.github/scripts/acf-local-json-smoke.php');
    const assetManifestSmoke = readFile('.github/scripts/asset-manifest-smoke.php');
    const blockAssetSmoke = readFile('.github/scripts/block-scoped-assets-smoke.php');
    const componentLocatorSmoke = readFile('.github/scripts/component-locator-smoke.php');
    const editorEnhancementsSmoke = readFile('.github/scripts/editor-enhancements-smoke.php');
    const smoke = readFile('.github/scripts/theme-filters-smoke.php');
    const editorPolicySmoke = readFile('.github/scripts/editor-policy-smoke.php');
    const patternSmoke = readFile('.github/scripts/pattern-registry-smoke.php');
    const smokeText = [acfJsonSmoke, assetManifestSmoke, blockAssetSmoke, componentLocatorSmoke, editorEnhancementsSmoke, smoke, editorPolicySmoke, patternSmoke].join('\n');
    const expectedFilters = [
      'emulsify_theme_acf_json_enabled',
      'emulsify_theme_acf_json_save_path',
      'emulsify_theme_acf_json_load_paths',
      'emulsify_theme_acf_json_remove_default_load_path',
      'emulsify_theme_asset_directories',
      'emulsify_theme_asset_files',
      'emulsify_theme_asset_manifest_path',
      'emulsify_theme_asset_manifest_data',
      'emulsify_theme_editor_enhancements_config',
      'emulsify_theme_editor_asset_directories',
      'emulsify_theme_editor_asset_files',
      'emulsify_theme_twig_namespaces',
      'emulsify_theme_context',
      'emulsify_theme_acf_block_metadata',
      'emulsify_theme_acf_block_args',
      'emulsify_theme_acf_block_asset_records',
      'emulsify_theme_native_block_directories',
      'emulsify_theme_pattern_directories',
      'emulsify_theme_pattern_data',
      'emulsify_theme_pattern_categories',
      'emulsify_theme_pattern_args',
      'emulsify_theme_component_roots',
      'emulsify_theme_component_discovery_cache_enabled',
      'emulsify_theme_component_discovery_cache_key_parts',
      'emulsify_theme_component_discovery_cache_ttl',
      'emulsify_theme_setup_options',
      'emulsify_theme_editor_policy_options',
      'emulsify_theme_allowed_block_types',
      'emulsify_theme_pattern_namespaces',
      'emulsify_theme_block_support_overrides',
    ];
    const runtimeText = [
      acfJson,
      assets,
      twig,
      context,
      setup,
      editorEnhancements,
      editorPolicy,
      editorAllowedBlocks,
      editorBlockSupport,
      editorPatterns,
      editorPolicyOptions,
      editorUserPatterns,
      patterns,
      locator,
      assetManifest,
      acfBlocks,
      nativeBlocks,
    ].join('\n');

    for (const filter of expectedFilters) {
      ensure(runtimeText.includes(filter), `${filter} should be registered in runtime PHP code.`);
      ensure(smokeText.includes(filter), `${filter} should be covered by a runtime filter smoke test.`);
      ensure(docsText.includes(filter), `${filter} should be documented in docs.`);
    }

    ensure(acfJsonSmoke.includes('ACF Local JSON smoke checks passed'), 'ACF Local JSON smoke should have a clear success message.');
    ensure(assetManifestSmoke.includes('Asset manifest smoke checks passed'), 'Asset manifest smoke should have a clear success message.');
    ensure(blockAssetSmoke.includes('Block scoped asset smoke checks passed'), 'Block scoped asset smoke should have a clear success message.');
    ensure(editorEnhancementsSmoke.includes('Editor enhancements smoke checks passed'), 'Editor enhancements smoke should have a clear success message.');
    ensure(smoke.includes('Theme filter smoke checks passed'), 'Runtime filter smoke should have a clear success message.');
    ensure(editorPolicySmoke.includes('Editor policy smoke checks passed'), 'Editor policy smoke should have a clear success message.');
    ensure(patternSmoke.includes('Pattern registry smoke checks passed'), 'Pattern registry smoke should have a clear success message.');
    ensure(fileDiscovery.includes('function theme_roots'), 'File discovery helper should create child-first theme roots.');
    ensure(fileDiscovery.includes('function normalize_roots'), 'File discovery helper should normalize readable unique roots.');
    ensure(fileDiscovery.includes('function file_records'), 'File discovery helper should create file records for service-specific filtering.');
    ensure(fileDiscovery.includes('function recursive_files'), 'File discovery helper should support recursive scans.');
    ensure(fileDiscovery.includes('function directory_files'), 'File discovery helper should support shallow directory scans.');
    ensure(fileDiscovery.includes('function relative_path'), 'File discovery helper should normalize POSIX relative paths.');
    ensure(fileDiscovery.includes('function sort_by_priority_and_relative'), 'File discovery helper should provide stable priority sorting.');
    ensure(assetManifest.includes('dist/emulsify-assets.json'), 'Asset manifest helper should default to dist/emulsify-assets.json.');
    ensure(assetManifest.includes('emulsify_theme_asset_manifest_path') && assetManifest.includes('emulsify_theme_asset_manifest_data'), 'Asset manifest helper should expose path and parsed data filters.');
    ensure(assets.includes("'global'") && editorEnhancements.includes("'editor'") && assetManifest.includes("'components'") && assetManifest.includes("'blocks'"), 'Asset manifest support should cover global, editor, component, and block-specific sections.');
    ensure(assets.includes('FileDiscovery::theme_roots') && assets.includes('FileDiscovery::file_records'), 'Runtime assets should use shared filesystem discovery helpers.');
    ensure(assets.includes('AssetManifest') && assets.includes('manifest_asset_files') && assets.includes("enqueue_scripts( 'emulsify-global', 'dist/global' )"), 'Runtime assets should support manifest-backed global and component assets.');
    ensure(assets.includes('is_scoped_component_asset') && assets.includes('*.component.json'), 'Runtime assets should skip component files declared as block-scoped metadata.');
    ensure(editorEnhancements.includes('FileDiscovery::theme_roots') && editorEnhancements.includes('FileDiscovery::file_records'), 'Editor assets should use shared filesystem discovery helpers.');
    ensure(editorEnhancements.includes('AssetManifest') && editorEnhancements.includes("asset_records( 'editor'"), 'Editor assets should support manifest-backed editor assets.');
    ensure(acfBlocks.includes('scoped_asset_records') && acfBlocks.includes('enqueue_assets') && acfBlocks.includes('emulsify_theme_acf_block_asset_records'), 'ACF/Twig blocks should support metadata-driven scoped assets.');
    ensure(nativeBlocks.includes("register_block_type( (string) $component['path'] )"), 'Native blocks should delegate block.json asset fields to WordPress register_block_type().');
    ensure(locator.includes('FileDiscovery::theme_roots') && locator.includes('FileDiscovery::file_records'), 'Component locator should use shared filesystem discovery helpers.');
    ensure(patterns.includes('FileDiscovery::theme_roots') && patterns.includes("FileDiscovery::file_records( $this->pattern_directories(), array( 'json' ), false )"), 'Pattern discovery should use shared helpers while staying shallow.');
    ensure(patterns.includes('CATEGORY_METADATA_FILES') && patterns.includes('category_metadata'), 'Pattern discovery should support optional category metadata files.');
    ensure(twig.includes('FileDiscovery::normalize_roots'), 'Twig project component roots should use shared root normalization.');
    ensure(smoke.includes('Asset discovery should keep child roots before parent fallback roots'), 'Runtime filter smoke should verify asset root priority.');
    ensure(editorEnhancementsSmoke.includes('skip duplicate parent relative paths'), 'Editor enhancements smoke should verify editor asset duplicate handling.');
    ensure(patternSmoke.includes('Child pattern JSON files should override parent files with the same basename'), 'Pattern smoke should verify child-first pattern discovery.');
    ensure(patternSmoke.includes('Child pattern category metadata should override parent category labels') && patternSmoke.includes('metadata files'), 'Pattern smoke should cover category metadata and metadata-file skips.');
    ensure(assetManifestSmoke.includes('No manifest should fall back') && assetManifestSmoke.includes('Invalid manifest should fall back to the valid parent manifest') && assetManifestSmoke.includes('Child manifest should take priority'), 'Asset manifest smoke should cover scanner fallback, invalid-child parent fallback, and child priority.');
    ensure(assetManifestSmoke.includes('WP_DEBUG should log malformed asset manifest candidates') && assetManifestSmoke.includes('WP_DEBUG should log missing manifest asset files'), 'Asset manifest smoke should cover debug logging for malformed manifests and missing referenced files.');
    ensure(blockAssetSmoke.includes('Manifest block assets should take priority') && blockAssetSmoke.includes('Native block.json asset fields should be left for WordPress') && blockAssetSmoke.includes('Global component scanning should remain the fallback'), 'Block scoped asset smoke should cover manifest priority, native block.json delegation, and scanner fallback.');
    ensure(blockAssetSmoke.includes('WP_DEBUG should log missing ACF component metadata assets'), 'Block scoped asset smoke should cover missing ACF metadata asset diagnostics.');
    return 'Parent runtime exposes documented filters with smoke coverage.';
  });

  runStaticCheck('Whisk Core 4 and Vite metadata', () => {
    const initHook = readFile('whisk/.cli/init.js');
    const jestConfig = readFile('whisk/config/jest.config.js');
    const scripts = whiskPackage.scripts || {};
    const scriptText = Object.values(scripts).join('\n');
    const starterComponentFiles = listFilesRecursive('whisk/src/components', (file) => path.basename(file) !== '.gitkeep');
    const starterPatternFiles = listFilesRecursive('whisk/patterns', (file) => path.basename(file) !== '.gitkeep');
    ensure(whiskPackage.name === 'whisk', 'whisk/package.json name should remain whisk.');
    ensure(semver(whiskPackage.version), 'whisk/package.json version must be a valid semver string.');
    ensure(whiskPackage.version === releaseVersion, `whisk/package.json version should match the root ${releaseVersion} release line.`);
    ensure(whiskPackage.description, 'whisk/package.json description is required.');
    ensureGeneratedChildThemeLanguage('whisk/package.json description', whiskPackage.description);
    ensure(whiskPackage.license === 'GPL-2.0-only', 'whisk/package.json license should align with the WordPress theme.');
    ensure(whiskPackage.engines && whiskPackage.engines.node === '>=24', 'whisk/package.json engines.node should be >=24.');
    ensure(whiskPackage.type === 'module', 'whisk/package.json should remain an ES module package.');
    ensure(whiskProject.project.platform === 'wordpress', 'whisk/project.emulsify.json should use the WordPress platform adapter.');
    ensure(whiskProject.project.name === 'whisk', 'whisk/project.emulsify.json project.name should remain whisk.');
    ensure(whiskProject.project.machineName === 'whisk', 'whisk/project.emulsify.json project.machineName should remain whisk.');
    ensure(whiskProject.project.generatedFrom === 'emulsify-wordpress', 'whisk/project.emulsify.json should identify the generated child theme source.');
    ensure(whiskProject.project.generatedFromVersion === releaseVersion, `whisk/project.emulsify.json generatedFromVersion should match the root ${releaseVersion} release line.`);
    ensure(whiskProject.starter.repository === 'https://github.com/emulsify-ds/emulsify-wordpress-starter', 'whisk/project.emulsify.json should point to the standalone WordPress starter repository.');
    ensure(fs.existsSync(path.join(repoRoot, 'whisk/.cli/init.js')), 'Whisk should ship an emulsify-cli init hook.');
    ensure(fs.existsSync(path.join(repoRoot, 'whisk/.gitignore')), 'Whisk should ship standalone starter ignore rules.');
    ensure(fs.existsSync(path.join(repoRoot, 'whisk/.nvmrc')), 'Whisk should use .nvmrc for Node version tooling.');
    ensure(!fs.existsSync(path.join(repoRoot, 'whisk/.nvm')), 'Whisk should not use the legacy .nvm filename.');
    ensure(fs.existsSync(path.join(repoRoot, 'whisk/config/jest.config.js')), 'Whisk should provide the Jest config referenced by package scripts.');
    ensure(scripts.test === 'node --experimental-vm-modules node_modules/jest/bin/jest.js --coverage --config ./config/jest.config.js', 'whisk/package.json test should execute native project ESM and fail when no tests exist.');
    ensure(scripts.twatch === 'node --experimental-vm-modules node_modules/jest/bin/jest.js --no-coverage --watch --verbose --config ./config/jest.config.js', 'whisk/package.json twatch should use the same native ESM runner.');
    ensure(jestConfig.includes("testEnvironment: 'node'"), 'whisk/config/jest.config.js should define a node test environment.');
    ensure(jestConfig.includes('transform: {}') && jestConfig.includes("coverageProvider: 'v8'") && jestConfig.includes('collectCoverageFrom'), 'Whisk Jest config should execute ESM directly and collect project coverage.');
    ensure(initHook.includes('sanitizeLabelForSource'), 'Whisk init hook should sanitize human labels before writing source comments.');
    ensure(initHook.includes("replaceThemeHeader(contents, 'Theme Name', sourceLabel)"), 'Whisk init hook should update style.css with a source-safe Theme Name.');
    ensure(initHook.includes("replaceThemeHeader(contents, 'Text Domain', machineName)"), 'Whisk init hook should update style.css Text Domain.');
    ensure(initHook.includes("replaceThemeHeader(contents, 'Template', PARENT_THEME)"), 'Whisk init hook should keep Template aligned to the parent theme.');
    ensure(initHook.includes("const PARENT_THEME = 'emulsify'"), 'Whisk init hook should keep the parent Template slug as emulsify.');
    ensure(initHook.includes("data.name = machineName"), 'Whisk init hook should update package metadata names.');
    ensure(initHook.includes("updateLockfile('package-lock.json'"), 'Whisk init hook should update the package lockfile created before the hook runs.');
    ensure(initHook.includes("config.project.platform = 'wordpress'"), 'Whisk init hook should keep project.platform on wordpress.');
    ensure(initHook.includes("config.project.generatedFrom = GENERATED_FROM"), 'Whisk init hook should set generatedFrom metadata.');
    ensure(initHook.includes("config.project.generatedFromVersion = generatedFromVersion"), 'Whisk init hook should set generatedFromVersion metadata.');
    ensure(initHook.includes('updatePatternNamespaces'), 'Whisk init hook should update JSON pattern namespaces.');
    ensure(starterInitSmoke.includes('package-lock.json') && starterInitSmoke.includes('packages[""].name'), 'Starter init smoke should prove lockfile metadata is updated after npm install.');
    ensure(starterInitSmoke.includes("project.project.platform === 'wordpress'"), 'Starter init smoke should validate the WordPress platform adapter.');
    ensure(starterInitSmoke.includes("project.project.generatedFrom === 'emulsify-wordpress'"), 'Starter init smoke should validate generatedFrom metadata.');
    ensure(starterInitSmoke.includes('project.project.generatedFromVersion === expectedVersion'), 'Starter init smoke should derive the expected generatedFromVersion from root package metadata instead of a literal.');
    ensure(starterInitSmoke.includes("style.Template === 'emulsify'"), 'Starter init smoke should validate Template: emulsify.');
    ensure(starterInitSmoke.includes('*/ echo 1; /*'), 'Starter init smoke should cover hostile source-comment labels.');
    ensure(starterInitSmoke.includes('node_modules') && starterInitSmoke.includes('dist'), 'Starter init smoke should validate copied build and dependency output is absent.');
    ensure(starterInitSmoke.includes('No tests found'), 'Starter init smoke should verify an empty starter fails its npm test script.');
    ensure(starterInitSmoke.includes("['run', 'inspect:components', '--', '--json']"), 'Starter init smoke should run the component inspector JSON command from the generated theme root.');
    ensure(starterInitSmoke.includes('JSON.parse') && starterInitSmoke.includes('Array.isArray(report.components)'), 'Starter init smoke should validate the component inspector JSON report, including an empty components array.');
    ensure(fs.existsSync(path.join(repoRoot, 'whisk/src/components/.gitkeep')), 'whisk/src/components should remain as an empty optional component placeholder.');
    ensure(!fs.existsSync(path.join(repoRoot, 'whisk/theme.json')), 'Whisk should not ship an empty child theme.json by default.');
    ensure(!fs.existsSync(path.join(repoRoot, 'whisk/components')), 'whisk/components should not remain as an unused starter placeholder.');
    ensure(starterComponentFiles.length === 0, `Whisk should not ship concrete starter component files: ${starterComponentFiles.join(', ')}.`);
    ensure(fs.existsSync(path.join(repoRoot, 'whisk/patterns/.gitkeep')), 'whisk/patterns should remain as an empty optional pattern placeholder.');
    ensure(starterPatternFiles.length === 0, `Whisk should not ship concrete starter pattern files: ${starterPatternFiles.join(', ')}.`);
    for (const entryFile of ['foundation.scss', 'layout.scss', 'tokens.scss']) {
      ensure(!fs.existsSync(path.join(repoRoot, 'whisk/src', entryFile)), `whisk/src/${entryFile} should not assume a selected component library.`);
    }
    for (const directory of ['editor', 'foundation', 'layout']) {
      ensure(!fs.existsSync(path.join(repoRoot, 'whisk/src', directory)), `whisk/src/${directory} should not assume a selected component library.`);
    }
    ensure(!fs.existsSync(path.join(repoRoot, 'whisk/whisk.info.yml')), 'Whisk should not add Drupal-style .info.yml metadata.');
    ensure(!fs.existsSync(path.join(repoRoot, 'whisk/scripts/vite-if-inputs.mjs')), 'Whisk should not wrap Emulsify Core Vite build errors.');
    ensure(whiskPackage.dependencies && whiskPackage.dependencies['@emulsify/core'], 'whisk/package.json must declare @emulsify/core.');
    // Accept any caret range at or above Core 4.5, so a patch or minor Core
    // bump does not require editing this file.
    const coreRange = whiskPackage.dependencies['@emulsify/core'];
    const coreRangeMatch = /^\^4\.(\d+)\.(\d+)$/.exec(coreRange);
    ensure(coreRangeMatch, `whisk/package.json should target a caret range within Emulsify Core 4; found ${coreRange}.`);
    ensure(
      Number(coreRangeMatch[1]) >= 5,
      `whisk/package.json should target Emulsify Core ^4.5.0 or newer within Core 4; found ${coreRange}.`,
    );
    ensure(scripts['inspect:components'] === 'emulsify-inspect-components', 'whisk/package.json should expose the published Emulsify Core component inspector binary.');
    ensure(!scripts['inspect:components'].includes('node_modules/'), 'whisk/package.json should not deep-link to the component inspector implementation.');
    ensure(scripts.build && scripts.build.includes('vite build --config node_modules/@emulsify/core/config/vite/vite.config.js'), 'whisk/package.json build script should use the Emulsify Core Vite config directly.');
    ensure(scripts.vite && scripts.vite.includes('vite build --watch --config node_modules/@emulsify/core/config/vite/vite.config.js'), 'whisk/package.json should expose the Emulsify Core Vite watch script directly.');
    ensure(scripts.a11y === 'npm run storybook-build && node config/emulsify-core/run-a11y.js', 'whisk/package.json should run the project accessibility runner after building Storybook.');
    ensure(whiskA11yConfig.includes("storybookBuildDir: '.out'"), 'Whisk accessibility config should target the generated theme Storybook output relative to the consuming project root.');
    ensure(whiskA11yConfig.includes('wait: 1000'), 'Whisk accessibility config should wait for the selected Storybook story to mount.');
    ensure(whiskA11yRunner.includes('resolvePa11yStoryIds') && whiskA11yRunner.includes('storyIds.length === 0'), 'Whisk accessibility runner should discover stories and reject an empty audit.');
    ensure(whiskA11yRunner.includes('resolveProjectA11yConfig') && whiskA11yRunner.includes('applyProjectA11yConfig'), 'Whisk accessibility wrapper should load the consuming project config through Core.');
    ensure(whiskA11yRunner.includes('startStorybookServer') && whiskA11yRunner.includes('lintReportAndExit') && whiskA11yRunner.includes('finally'), 'Whisk accessibility wrapper should delegate HTTP serving, bounded checks, failure reporting, and cleanup to Core.');
    ensure(!whiskA11yRunner.includes('http.createServer') && !whiskA11yRunner.includes('await pa11y'), 'Whisk should not ship a copied accessibility engine.');
    ensure(!whiskA11yRunner.includes('--no-sandbox') && !whiskA11yRunner.includes('--disable-setuid-sandbox'), 'Whisk accessibility code must keep Chrome sandboxing enabled.');
    ensure(!scriptText.includes('vite-if-inputs'), 'whisk/package.json scripts should not wrap missing-input build errors.');
    ensure(scripts.develop && scripts.develop.includes('npm:vite'), 'whisk/package.json develop script should run the Vite watcher.');
    ensure(!scripts.webpack, 'whisk/package.json should not expose a webpack script.');
    ensure(!scripts['build-dev'], 'whisk/package.json should not expose the old Webpack build-dev script.');
    ensure(!scripts['tokens:transform'], 'whisk/package.json should not ship a default token-transformer script.');
    ensure(!scripts['tokens:build'], 'whisk/package.json should not ship a default token build script.');
    ensure(!scripts['style-dictionary:build'], 'whisk/package.json should not ship a default Style Dictionary script.');
    ensure(!/\bwebpack\b/i.test(scriptText), 'whisk/package.json scripts should not reference Webpack.');
    ensure(!/\btoken-transformer\b/.test(scriptText), 'whisk/package.json scripts should not reference token-transformer.');
    ensure(!/\bstyle-dictionary\b/.test(scriptText), 'whisk/package.json scripts should not reference Style Dictionary.');
    ensure(!String(whiskPackage.dependencies['@emulsify/core']).startsWith('^3.'), 'whisk/package.json should not target Emulsify Core 3.');
    return `Whisk targets ${whiskPackage.dependencies['@emulsify/core']} with Vite scripts.`;
  });

  runStaticCheck('Template fallback model', () => {
    const twigIntegration = readFile('includes/Runtime/Twig.php');
    const projectComponentLoader = readFile('includes/Twig/ProjectComponentLoader.php');
    const twigNamespaceSmoke = readFile('.github/scripts/twig-project-namespace-smoke.php');
    const childFunctions = readFile('whisk/functions.php');
    const parentTemplateFiles = listFilesRecursive('templates', (file) => file.endsWith('.twig')).sort();
    const childTemplateFiles = listFilesRecursive('whisk/templates', (file) => file.endsWith('.twig')).sort();
    const expectedParentFallbacks = [
      'templates/404.twig',
      'templates/archive.twig',
      'templates/author.twig',
      'templates/index.twig',
      'templates/page.twig',
      'templates/search.twig',
      'templates/single-password.twig',
      'templates/single.twig',
    ];
    const childTemplatePathIndex = twigIntegration.indexOf("'path'      => get_stylesheet_directory() . '/templates'");
    const parentTemplatePathIndex = twigIntegration.indexOf("'path'      => get_template_directory() . '/templates'");
    const childComponentSrcPathIndex = twigIntegration.indexOf("get_stylesheet_directory() . '/src/components'");
    const childComponentLegacyPathIndex = twigIntegration.indexOf("get_stylesheet_directory() . '/components'");
    const parentComponentSrcPathIndex = twigIntegration.indexOf("get_template_directory() . '/src/components'");
    const parentComponentLegacyPathIndex = twigIntegration.indexOf("get_template_directory() . '/components'");

    for (const fallback of expectedParentFallbacks) {
      ensure(parentTemplateFiles.includes(fallback), `${fallback} should exist as a parent fallback.`);
    }
    ensure(childTemplateFiles.length === 0, `Whisk ships no Twig templates; remove ${childTemplateFiles.join(', ')} and see docs/template-extension.md for intentional child overrides.`);
    ensure(!fs.existsSync(path.join(repoRoot, 'whisk/templates')), 'Whisk should omit the templates directory; see docs/template-extension.md before adding project overrides.');
    ensure(childTemplatePathIndex !== -1, 'Twig integration should register child @templates path.');
    ensure(parentTemplatePathIndex !== -1, 'Twig integration should register parent @templates path.');
    ensure(childTemplatePathIndex < parentTemplatePathIndex, 'Twig integration should register child @templates before parent @templates.');
    ensure(twigIntegration.includes("'namespace' => 'emulsify-tpl'") && twigIntegration.includes("'path'      => get_template_directory() . '/templates'"), 'Twig integration should expose parent templates through @emulsify-tpl.');
    ensure(childComponentSrcPathIndex !== -1, 'Twig integration should register child src @components path.');
    ensure(childComponentLegacyPathIndex !== -1, 'Twig integration should register child legacy @components path.');
    ensure(parentComponentSrcPathIndex !== -1, 'Twig integration should register parent src @components path.');
    ensure(parentComponentLegacyPathIndex !== -1, 'Twig integration should register parent legacy @components path.');
    ensure(childComponentSrcPathIndex < childComponentLegacyPathIndex, 'Twig integration should check child src/components compatibility roots before child components roots.');
    ensure(childComponentLegacyPathIndex < parentComponentSrcPathIndex, 'Twig integration should register child @components paths before parent @components paths.');
    ensure(parentComponentSrcPathIndex < parentComponentLegacyPathIndex, 'Twig integration should check parent src/components compatibility roots before parent components roots.');
    ensure(twigIntegration.includes('project.emulsify.json'), 'Twig integration should read active child project.emulsify.json metadata.');
    ensure(twigIntegration.includes('project_structure_namespaces') && twigIntegration.includes('structureImplementations'), 'Twig integration should honor Emulsify Core structureImplementations for configured namespaces.');
    ensure(twigIntegration.includes('emulsify_theme_project_component_roots'), 'Twig integration should expose a focused project component roots filter.');
    ensure(twigIntegration.includes('new ProjectComponentLoader') && projectComponentLoader.includes('implements \\Twig\\Loader\\LoaderInterface'), 'Twig integration should wrap the loader for machineName:component references.');
    ensure(twigIntegration.includes('component_root_records') && twigIntegration.includes("$loader->getPaths( 'components' )"), 'Twig component aliases should use the selected, filtered component namespace roots.');
    ensure(twigNamespaceSmoke.includes('@custom/teaser.twig') && twigNamespaceSmoke.includes('structureImplementations'), 'Twig namespace smoke should verify configured Core structure namespaces.');
    ensure(!fs.existsSync(path.join(repoRoot, 'whisk/includes/twig-namespaces.php')), 'Whisk should rely on the parent Twig namespace integration by default.');
    ensure(!childFunctions.includes('twig-namespaces.php'), 'whisk/functions.php should not require a duplicate Twig namespace file.');
    ensure(childFunctions.includes('project_machine_name:component_name') && childFunctions.includes('@components for compatible component libraries'), 'whisk/functions.php should document generic project machine-name component references while preserving @components compatibility.');
    for (const route of ['home', 'page', 'single', 'archive', 'search', 'author', '404']) {
      ensure(wordpressFixtureSmoke.includes(`name: '${route}'`), `WordPress fixture smoke should render the ${route} route.`);
    }
    ensure(wordpressFixtureSmoke.includes('response.status !== expectedStatus'), 'WordPress fixture smoke should fail when a route returns an unexpected status.');
    ensure(wordpressFixtureSmoke.includes('!body.includes(text)'), 'WordPress fixture smoke should fail when expected rendered content is missing.');
    ensure(wordpressFixtureSmoke.includes("const required = process.env.WP_SMOKE_REQUIRED === '1'"), 'WordPress fixture smoke should only require local prerequisites when WP_SMOKE_REQUIRED=1.');
    ensure(!wordpressFixtureSmoke.includes("process.env.CI === 'true'"), 'WordPress fixture smoke should not treat all CI runs as required fixture runs.');
    ensure(wordpressFixtureSmoke.includes("['theme', 'is-installed', 'emulsify']"), 'WordPress fixture smoke should prove the parent theme is installed.');
    ensure(wordpressFixtureSmoke.includes('WP_SMOKE_THEME_ARCHIVE') && wordpressFixtureSmoke.includes("'Extract installable theme archive', 'unzip'"), 'WordPress fixture smoke should support installing the built release ZIP.');
    ensure(wordpressFixtureSmoke.includes('Installable theme archive is missing the bundled Whisk starter'), 'WordPress fixture smoke should reject an archive without Whisk.');
    ensure(wordpressFixtureSmoke.includes("['cli', 'has-command', 'emulsify']"), 'WordPress fixture smoke should confirm the packaged WP-CLI command is registered.');
    ensure(wordpressFixtureSmoke.includes('Using production Composer dependencies bundled in the theme archive'), 'WordPress fixture smoke should use packaged production dependencies.');
    ensure(wordpressFixtureSmoke.includes("['emulsify', 'Smoke Generated'"), 'WordPress fixture smoke should generate a child theme from Whisk with WP-CLI.');
    ensure(wordpressFixtureSmoke.includes("['option', 'get', 'stylesheet']"), 'WordPress fixture smoke should verify the generated child theme is active.');
    ensure(wordpressFixtureSmoke.includes('assertGeneratedChildTheme'), 'WordPress fixture smoke should validate generated child theme metadata and copied Whisk files.');
    ensure(wordpressFixtureSmoke.includes("project.project?.generatedFrom !== 'emulsify-wordpress'"), 'WordPress fixture smoke should validate generatedFrom metadata.');
    ensure(wordpressFixtureSmoke.includes('project.project?.generatedFromVersion !== expectedGeneratedFromVersion'), 'WordPress fixture smoke should derive the expected generatedFromVersion from root package metadata instead of a literal.');
    ensure(wordpressFixtureSmoke.includes('Generated child theme should not include Twig templates by default.') && wordpressFixtureSmoke.includes('entry__content'), 'WordPress fixture smoke should prove parent page rendering without a generated child Twig override.');
    ensure(wordpressFixtureSmoke.includes('Child block extension must preserve every surrounding byte.') && wordpressFixtureSmoke.includes("{% extends '@emulsify-tpl/page.twig' %}") && wordpressFixtureSmoke.includes('finally'), 'WordPress fixture smoke should prove one-block child inheritance and restore the temporary override; see docs/template-extension.md.');
    ensure(wordpressFixtureSmoke.includes('checkGeneratedAssets'), 'WordPress fixture smoke should fetch generated child theme built assets.');
    ensure(wordpressFixtureSmoke.includes('runAcfDiscoveryWithoutAcf'), 'WordPress fixture smoke should check ACF/Twig discovery when ACF is absent.');
    ensure(wordpressFixtureSmoke.includes('installAcfStub'), 'WordPress fixture smoke should provide a fixture-only ACF stub.');
    ensure(wordpressFixtureSmoke.includes('runAcfDiscoveryWithStub'), 'WordPress fixture smoke should check ACF/Twig registration with the ACF stub.');
    ensure(wordpressFixtureSmoke.includes('emulsify/smoke-native'), 'WordPress fixture smoke should check native block.json discovery and registration.');
    return 'Parent owns route fallbacks and default Twig namespaces; Whisk ships no Twig templates.';
  });

  runStaticCheck('Template extension contract', () => {
    const base = readFile('templates/layouts/base.twig');
    const extensionDocs = readFile('docs/template-extension.md');
    for (const block of ['head', 'header', 'content', 'footer', 'site_header', 'main', 'page_title']) {
      ensure(base.includes(`{% block ${block} %}`) && extensionDocs.includes('`' + block + '`'), `Preserve the public ${block} block and boundary documented in docs/template-extension.md.`);
    }
    const goldens = listFilesRecursive('.github/fixtures/template-extension', (file) => file.endsWith('.html'));
    ensure(goldens.length >= 10, 'Keep the pre-extension output captures; see docs/template-extension.md.');
    ensure(readFile('.github/scripts/pr-validation.cjs').includes('smoke:template-extension'), 'PR validation should compare exact template bytes and child extension boundaries.');
    for (const file of ['README.md', 'docs/README.md', 'docs/timber-and-twig-authoring.md']) {
      ensure(readFile(file).includes('template-extension.md'), `${file} should link to the public template extension contract.`);
    }
    return 'Seven public layout blocks retain ten golden outputs, tested child inheritance, and documented boundaries.';
  });

  runStaticCheck('Rendered WordPress accessibility', () => {
    const { wcagTags, disabledRules } = require('./rendered-a11y.cjs');
    ensure(JSON.stringify(wcagTags) === JSON.stringify(['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa', 'wcag22aa']), 'Rendered WordPress axe audit must select all five WCAG tags.');
    ensure(JSON.stringify(disabledRules) === JSON.stringify(['target-size']), 'Only target-size may be disabled for the unstyled parent fixture.');
    const fixtureJob = themeReadinessWorkflow.slice(themeReadinessWorkflow.indexOf('  wordpress-fixture:'), themeReadinessWorkflow.indexOf('  extended-whisk:'));
    ensure(fixtureJob.includes('Select and verify runner Chrome') && fixtureJob.includes('--headless --dump-dom about:blank'), 'WordPress fixture job must verify sandboxed runner Chrome.');
    ensure(fixtureJob.includes("WP_SMOKE_A11Y: '1'") && fixtureJob.includes('WP_SMOKE_A11Y_OUTPUT:'), 'WordPress fixture job must enable the rendered browser audit and retain its reports.');
    ensure(fixtureJob.includes('wordpress-rendered-a11y-') && fixtureJob.includes('if: always()'), 'WordPress accessibility reports must upload on success and failure.');
    ensure(wordpressFixtureSmoke.includes('Verify rendered accessibility failure gates'), 'WordPress fixture must exercise the browser audit negative controls.');
    ensure(wordpressFixtureSmoke.includes('archiveCount < 12') && wordpressFixtureSmoke.includes("['option', 'update', 'posts_per_page', '1']"), 'Rendered archive audit needs at least 12 posts and one post per page.');
    const guide = readFile('docs/accessibility.md');
    ensure(guide.includes('target-size') && guide.includes('screen-reader-text') && guide.includes('focus'), 'Accessibility guide must document the sole rule exception and project styling responsibilities.');
    ensure(wordpressFixtureSmoke.includes("name: 'media-embeds'") && wordpressFixtureSmoke.includes('media-embed-smoke.php') && wordpressFixtureSmoke.includes('verifyMediaEmbeds'), 'The fixture must test media transforms and audit the rendered YouTube/Vimeo posters.');
    return 'Seven rendered routes use sandboxed Chrome, full WCAG tags, strict navigation/media assertions, and retained HTML/axe reports; only target-size is excluded.';
  });

  runStaticCheck('Generated child theme documentation', () => {
    const cli = readFile('includes/Cli/GenerateChildThemeCommand.php');
    const initHook = readFile('whisk/.cli/init.js');

    for (const heading of ['Quick start', 'Documentation']) {
      ensure(whiskReadme.includes(`## ${heading}`), `whisk/README.md should include the ${heading} section.`);
    }

    for (const heading of [
      'Prerequisites',
      'Initial setup',
      'Asset integration',
      'Component inspection',
      'Development workflow',
      'Project ownership',
    ]) {
      ensure(whiskDevelopment.includes(`## ${heading}`), `whisk/docs/development.md should include the ${heading} section.`);
    }

    for (const heading of ['Choose the upgrade type', 'Validate the result', 'Preserve source history']) {
      ensure(whiskUpgrading.includes(`## ${heading}`), `whisk/docs/upgrading.md should include the ${heading} section.`);
    }

    for (const heading of [
      'Troubleshooting',
      'Theme and frontend information',
      'WordPress environment information',
      'Problem description',
    ]) {
      ensure(whiskSupportInformation.includes(`## ${heading}`), `whisk/docs/support-information.md should include the ${heading} section.`);
    }

    // The token list is asserted in the template and in both generators, so a
    // token added on one side alone fails here instead of leaking into a project.
    for (const token of [
      '%%EMULSIFY_THEME_NAME%%',
      '%%EMULSIFY_MACHINE_NAME%%',
      '%%EMULSIFY_DESCRIPTION%%',
      '%%EMULSIFY_SOURCE_PROJECT%%',
      '%%EMULSIFY_SOURCE_VERSION%%',
      '%%EMULSIFY_CORE_RANGE%%',
    ]) {
      ensure(whiskReadme.includes(token), `whisk/README.md should include generated documentation token ${token}.`);
      ensure(cli.includes(token), `includes/Cli/GenerateChildThemeCommand.php should replace generated documentation token ${token}.`);
      ensure(initHook.includes(token), `whisk/.cli/init.js should replace generated documentation token ${token}.`);
    }

    for (const documentationPath of [
      'README.md',
      'docs/development.md',
      'docs/support-information.md',
      'docs/upgrading.md',
    ]) {
      ensure(fs.existsSync(path.join(repoRoot, 'whisk', documentationPath)), `Whisk should ship ${documentationPath} for generated child themes.`);
      ensure(cli.includes(`'${documentationPath}'`), `includes/Cli/GenerateChildThemeCommand.php should process ${documentationPath}.`);
      ensure(initHook.includes(`'${documentationPath}'`), `whisk/.cli/init.js should process ${documentationPath}.`);
    }

    ensure(cli.includes('%%EMULSIFY_[A-Z_]+%%'), 'Child theme generator should fail when a documentation token survives.');
    ensure(initHook.includes('DOCUMENTATION_TOKEN_PATTERN'), 'Whisk init hook should fail when a documentation token survives.');
    ensure(cli.includes('one_line('), 'Child theme generator should collapse whitespace before injecting Markdown values.');
    ensure(initHook.includes('oneLine('), 'Whisk init hook should collapse whitespace before injecting Markdown values.');
    ensure(cli.includes('encode_json('), 'Child theme generator should emit two-space JSON so both generation paths agree.');

    for (const concept of [
      'does not prescribe or scaffold a component library',
      'Twig components',
      'block.json',
      'Vite',
      'Storybook',
      'project.emulsify.json',
      'generatedFrom',
      'generatedFromVersion',
      'does not prescribe asset source directories',
      'component library owns',
      'npm run inspect:components',
      'npm run inspect:components -- --json',
      'npm run inspect:components -- --help',
    ]) {
      ensure(whiskDevelopment.includes(concept), `whisk/docs/development.md should document ${concept}.`);
    }

    for (const concept of [
      'npm dependency update',
      'newer starter release',
      'fresh, temporary comparison theme',
      'project.generatedFrom',
      'project.generatedFromVersion',
      'npm run lint',
      'npm run test',
      'npm run inspect:components',
      'npm run build',
      'npm run storybook-build',
    ]) {
      ensure(whiskUpgrading.includes(concept), `whisk/docs/upgrading.md should document ${concept}.`);
    }

    for (const concept of [
      'node --version',
      'npm --version',
      'npm ls @emulsify/core --depth=0',
      'wp core version',
      'wp theme list',
      'wp plugin list',
      'npm run build',
      'npm run storybook-build',
      'Do not share database credentials',
    ]) {
      ensure(whiskSupportInformation.includes(concept), `whisk/docs/support-information.md should document ${concept}.`);
    }

    return 'Generated docs cover project ownership, frontend workflows, upgrades, and sanitized support collection.';
  });

  runStaticCheck('Generated AI guidance', () => {
    const guidancePaths = [
      'AGENTS.md',
      'docs/wordpress-integration.md',
      '.agents/skills/emulsify-wordpress-integration/SKILL.md',
    ];
    for (const relativePath of guidancePaths) {
      const sourcePath = `whisk/${relativePath}`;
      const contents = readFile(sourcePath);
      ensure(!/%%EMULSIFY_[A-Z_]+%%|whisk/i.test(contents), `${sourcePath} must be token-free and contain no starter identity so generators copy it unchanged.`);
      ensure(generatedThemeContract.includes(`'${relativePath}'`), `Generated theme contract should require ${relativePath}.`);
      ensure(docsCommandCheck.includes(`'${relativePath}'`), `Documentation command checks should cover ${relativePath}.`);
      ensure(distBuilder.includes(`"${sourcePath}"`), `Distribution build should require staged ${sourcePath}.`);
    }

    const attributes = childProcess.spawnSync('git', [
      'check-attr', 'export-ignore', '--',
      'AGENTS.md', ...guidancePaths.map((file) => `whisk/${file}`),
    ], { cwd: repoRoot, encoding: 'utf8' });
    ensure(attributes.status === 0, `Unable to check guidance Git distribution attributes: ${attributes.stderr}`);
    ensure(!/: export-ignore: (?:set|true)\s*$/m.test(attributes.stdout), 'Git distributions must retain contributor and generated AI guidance, including hidden skills.');
    return 'Portable guidance is required, copied without replacement, command-checked, and retained in ZIP/Git distributions.';
  });

  runStaticCheck('Generated child theme contract', () => {
    ensure(rootPackage.scripts['test:generated-theme'] === 'node --test .github/scripts/generated-theme-contract.test.cjs', 'package.json should expose the focused generated child theme contract tests.');
    ensure(rootPackage.scripts['smoke:generation-parity'] === 'node .github/scripts/generation-parity-smoke.cjs', 'package.json should expose the generation parity smoke.');
    ensure(generatedThemeContract.includes('validateDocumentation'), 'generated-theme-contract.cjs should reuse the documentation command checker for generated child themes.');
    ensure(generatedThemeContract.includes('generatedFromVersion'), 'generated-theme-contract.cjs should validate generated source version lineage.');
    ensure(generatedThemeContract.includes('DOCUMENTATION_TOKEN_PATTERN'), 'generated-theme-contract.cjs should reject surviving documentation tokens.');
    ensure(generatedThemeContract.includes('FORBIDDEN_GENERATED_PATHS'), 'generated-theme-contract.cjs should reject generation-only tooling in generated output.');
    ensure(docsCommandCheck.includes('--generated-theme'), 'docs-command-check.cjs should support validating a generated child theme directory.');
    ensure(docsCommandCheck.includes('module.exports = { validateDocumentation }'), 'docs-command-check.cjs should export the reusable documentation validator.');
    ensure(generatedThemeContractTests.includes('requires the generated documentation set'), 'generated-theme-contract.test.cjs should cover missing generated documentation.');
    ensure(generatedThemeContractTests.includes('reports leftover documentation tokens'), 'generated-theme-contract.test.cjs should cover stale documentation tokens.');
    ensure(generatedThemeContractTests.includes('reports a stale starter machine name'), 'generated-theme-contract.test.cjs should cover stale starter identity.');

    // Two generators in two languages only stay aligned if something compares
    // their output. This is that something.
    ensure(generationParitySmoke.includes('generateWithWpCli') && generationParitySmoke.includes('generateWithStarterInit'), 'generation-parity-smoke.cjs should exercise both supported generation paths.');
    ensure(generationParitySmoke.includes('compareTrees'), 'generation-parity-smoke.cjs should require both generation paths to produce the same file tree.');
    ensure(generationParitySmoke.includes("path.join(repoRoot, 'style.css')") && generationParitySmoke.includes("path.join(parentDir, 'style.css')"), 'Generation parity should model installed parent runtime metadata without root npm tooling.');
    ensure(generationParitySmoke.includes('EMULSIFY_PARITY_REQUIRED'), 'generation-parity-smoke.cjs should support requiring the PHP generation path in CI.');
    ensure(themeReadinessWorkflow.includes('smoke:generation-parity'), 'Theme readiness should run the generation parity smoke.');
    ensure(themeReadinessWorkflow.includes('EMULSIFY_PARITY_REQUIRED'), 'Theme readiness should require the PHP generation path during generation parity.');

    for (const intentionalExclusion of [
      'a particular component library',
      'example component',
      'frontend CSS behavior',
      'frontend JavaScript behavior',
      'design-token system',
    ]) {
      ensure(generatedChildThemeContractDoc.includes(intentionalExclusion), `docs/generated-child-theme-contract.md should exclude ${intentionalExclusion} from the contract.`);
    }

    ensure(generatedChildThemeContractDoc.includes('## Run the checks'), 'docs/generated-child-theme-contract.md should document how to run the checks.');
    ensure(generatedChildThemeContractDoc.includes('Two supported generation paths'), 'docs/generated-child-theme-contract.md should describe both generation paths.');

    return 'Generated child theme output is validated by a contract, focused tests, and cross-path parity.';
  });

  runStaticCheck('Starter asset placeholders', () => {
    const iconFiles = listFilesRecursive('whisk/assets/icons', (file) => path.basename(file) !== '.gitkeep');
    const expectedPlaceholders = [
      'whisk/assets/fonts/.gitkeep',
      'whisk/assets/icons/.gitkeep',
      'whisk/assets/images/.gitkeep',
    ];

    ensure(iconFiles.length === 0, `Remove client-specific starter icons: ${iconFiles.join(', ')}.`);
    for (const placeholder of expectedPlaceholders) {
      ensure(fs.existsSync(path.join(repoRoot, placeholder)), `${placeholder} should keep the starter asset directory.`);
    }
    ensure(wordpressFixtureSmoke.includes('assets/icons/.gitkeep') && wordpressFixtureSmoke.includes('assets/images/.gitkeep'), 'WordPress fixture smoke should validate copied asset placeholders.');
    ensure(!fs.existsSync(path.join(repoRoot, 'whisk/assets/audio')), 'whisk/assets/audio should not ship as a default starter directory.');
    ensure(!fs.existsSync(path.join(repoRoot, 'whisk/assets/video')), 'whisk/assets/video should not ship as a default starter directory.');
    return 'Whisk keeps only generic starter asset placeholders.';
  });

  runStaticCheck('Duplicate package scripts', () => {
    const duplicates = [
      ...findDuplicatePackageScripts('package.json').map((key) => `package.json:${key}`),
      ...findDuplicatePackageScripts('whisk/package.json').map((key) => `whisk/package.json:${key}`),
    ];
    ensure(duplicates.length === 0, `Duplicate script keys found: ${duplicates.join(', ')}.`);
    return 'No duplicate script keys were found in package metadata.';
  });

  runStaticCheck('Semantic release configuration', () => {
    const analyzerOptions = getReleasePluginOptions(releaseConfig, '@semantic-release/commit-analyzer');
    const notesOptions = getReleasePluginOptions(releaseConfig, '@semantic-release/release-notes-generator');
    const githubOptions = getReleasePluginOptions(releaseConfig, '@semantic-release/github');
    const releaseGuard = releaseConfig.plugins.find((plugin) => plugin && typeof plugin.verifyRelease === 'function');
    const readinessJobStart = semanticReleaseWorkflow.indexOf('  release-readiness:');
    const releaseJobStart = semanticReleaseWorkflow.indexOf('  semantic-release:');
    const readinessJob = semanticReleaseWorkflow.slice(readinessJobStart, releaseJobStart);
    const releaseJob = semanticReleaseWorkflow.slice(releaseJobStart);
    const buildStep = releaseJob.indexOf('npm run build:dist');
    const publishStep = releaseJob.indexOf('run: npm run publish\n');
    const dryRunStep = releaseJob.indexOf('run: npm run publish-test -- --no-ci');
    const releaseAsset = Array.isArray(githubOptions.assets)
      ? githubOptions.assets.find((asset) => asset.path === 'dist-artifact/emulsify.zip')
      : null;
    ensure(releaseConfig.tagFormat === '${version}', 'release.config.js should emit non-prefixed semver tags.');
    ensure(releaseConfig.repositoryUrl === 'git@github.com:emulsify-ds/emulsify-wordpress.git', 'release.config.js should publish against emulsify-wordpress.');
    ensure(Array.isArray(releaseConfig.branches), 'release.config.js branches must be an array.');
    ensure(releaseConfig.branches.length === 1 && releaseConfig.branches[0] === 'main', 'release.config.js should publish only from main.');
    ensure(!releaseConfig.plugins.some((plugin) => ['@semantic-release/npm', '@semantic-release/git'].includes(Array.isArray(plugin) ? plugin[0] : plugin)), 'release.config.js must not rewrite committed versions through @semantic-release/npm or @semantic-release/git.');
    ensure(releaseGuard, 'release.config.js should require the computed release version to match the prepared package version.');
    ensure(rootPackage.scripts.publish.startsWith('node .github/scripts/semantic-release.cjs '), 'The publish script should prepare semantic-release through the guarded runner.');
    ensure(rootPackage.scripts['publish-test'].startsWith('node .github/scripts/semantic-release.cjs '), 'The publish-test script should use the same guarded semantic-release runner.');
    ensure(releaseAsset, 'release.config.js should attach dist-artifact/emulsify.zip to GitHub releases.');
    ensure(releaseAsset.label === 'Emulsify WordPress theme (with dependencies)', 'release.config.js should give the installable ZIP a clear release label.');
    ensureBreakingParser('@semantic-release/commit-analyzer', analyzerOptions.parserOpts);
    ensureBreakingParser('@semantic-release/release-notes-generator', notesOptions.parserOpts);
    ensure(readinessJobStart >= 0, 'semantic-release.yml should run release-readiness before publishing.');
    ensure(semanticReleaseWorkflow.includes('needs: release-readiness'), 'semantic-release.yml release job should wait for release readiness.');
    ensure(semanticReleaseWorkflow.includes('contents: write'), 'semantic-release.yml should grant GitHub release permissions explicitly.');
    ensure(semanticReleaseWorkflow.includes('id: semantic'), 'semantic-release.yml should expose the semantic-release step as steps.semantic.');
    ensure(semanticReleaseWorkflow.includes('npm run release:check'), 'semantic-release.yml should run local release readiness checks.');
    ensure(readinessJob.includes('fetch-depth: 0') && releaseJob.includes('fetch-depth: 0'), 'Release readiness and publishing should fetch full history and tags for the release version contract.');
    ensure(semanticReleaseWorkflow.includes('wp-cli'), 'semantic-release.yml should install WP-CLI for the WordPress smoke fixture.');
    ensure(semanticReleaseWorkflow.includes('mysql:'), 'semantic-release.yml should provide a MySQL service for the WordPress smoke fixture.');
    ensure(semanticReleaseWorkflow.includes('WP_SMOKE_DB_HOST'), 'semantic-release.yml should pass WordPress smoke database settings.');
    ensure(readinessJob.includes('- name: Build tested installable theme archive') && readinessJob.includes('run: npm run build:dist'), 'Release readiness should build the archive before the WordPress fixture.');
    ensure(readinessJob.includes('WP_SMOKE_THEME_ARCHIVE: dist-artifact/emulsify.zip'), 'Release readiness should test the built archive in WordPress.');
    ensure(releaseJobStart >= 0, 'semantic-release.yml should define the semantic-release job.');
    ensure(releaseJob.includes("php-version: '8.3'"), 'semantic-release.yml release job should set up PHP 8.3.');
    ensure(releaseJob.includes('tools: composer:v2'), 'semantic-release.yml release job should install Composer.');
    ensure(buildStep >= 0, 'semantic-release.yml release job should build the installable theme archive.');
    ensure(publishStep >= 0 && buildStep < publishStep, 'semantic-release.yml should build the installable theme archive before publishing.');
    ensure(dryRunStep >= 0 && dryRunStep < publishStep, 'semantic-release.yml should run the dry run in the publishing job before publishing.');
    ensure(!readinessJob.includes('npm run publish-test') && !readinessJob.includes('GITHUB_TOKEN:'), 'Read-only release readiness must not run the authenticated semantic-release dry run.');
    ensure(semanticReleaseRunner.includes('Refusing to publish local-only stable tag(s)'), 'The guarded runner should refuse to push unpublished local stable tags.');
    return 'Semantic release requires a prepared package version, non-prefixed tags, main-only publishing, and the tested installable ZIP asset.';
  });

  runStaticCheck('Installable release artifact', () => {
    ensure(distBuilder.includes('dist-artifact'), 'Distribution build should write to dist-artifact/.');
    ensure(distBuilder.includes('artifact_path="${artifact_dir}/emulsify.zip"'), 'Distribution build should create emulsify.zip.');
    ensure(distBuilder.includes('staging_root="${staging_parent}/emulsify"'), 'Distribution build should stage a top-level emulsify/ directory.');
    ensure(distBuilder.includes('--working-dir="${staging_root}"'), 'Distribution build should install Composer dependencies from inside the staged theme.');
    ensure(distBuilder.includes('--no-dev') && distBuilder.includes('--optimize-autoloader'), 'Distribution build should install optimized production-only Composer dependencies.');
    ensure(distBuilder.includes('rm -f "${staging_root}/composer.json" "${staging_root}/composer.lock"'), 'Distribution build should remove temporary Composer metadata from the archive.');
    ensure(distBuilder.includes('"docs"') && distBuilder.includes('"includes"') && distBuilder.includes('"templates"') && distBuilder.includes('"src"'), 'Distribution build should include user documentation and parent runtime directories.');
    ensure(distBuilder.includes('"UPGRADE.md"') && distBuilder.includes('"docs/wp-cli-child-theme-generation.md"'), 'Distribution build should retain the documentation linked from its README.');
    ensure(distBuilder.includes('"functions.php"') && distBuilder.includes('"style.css"') && distBuilder.includes('"theme.json"'), 'Distribution build should include required WordPress theme files.');
    ensure(distBuilder.includes('copy_tracked_directory "whisk"'), 'Distribution build should include the tracked Whisk generator payload.');
    ensure(distBuilder.includes('"whisk/project.emulsify.json"') && distBuilder.includes('"whisk/style.css"'), 'Distribution build should verify required Whisk generator files.');
    ensure(distBuilder.includes('"whisk/.cache"') && distBuilder.includes('"whisk/.cli"') && distBuilder.includes('"whisk/node_modules"'), 'Distribution build should reject Whisk caches, generation tooling, and dependencies.');
    ensure(distBuilder.includes('find emulsify -type f -print') && distBuilder.includes('zip -X'), 'Distribution build should archive the staged emulsify/ tree reproducibly.');
    ensure(!distBuilder.includes('cp -R'), 'Distribution build should copy tracked files instead of recursive working-tree directories.');
    ensure(gitAttributes.includes('/.github/** export-ignore') && gitAttributes.includes('/scripts/** export-ignore'), 'Git archives should exclude CI and release tooling.');
    ensure(gitAttributes.includes('/package.json export-ignore') && gitAttributes.includes('/composer.lock export-ignore'), 'Git archives should exclude root development metadata while retaining composer.json.');
    ensure(gitAttributes.includes('/whisk/.cli/** export-ignore'), 'Composer distributions should exclude the standalone Whisk CLI hook.');
    ensure(!gitAttributes.includes('/docs export-ignore') && !gitAttributes.includes('/UPGRADE.md export-ignore'), 'Composer distributions should retain README-linked user documentation.');
    return 'Distribution build stages the parent theme, Whisk generator payload, and production Composer dependencies under emulsify/.';
  });

  runStaticCheck('Theme readiness workflow', () => {
    const prValidationScript = readFile('.github/scripts/pr-validation.cjs');
    const practicalStart = themeReadinessWorkflow.indexOf('  practical:');
    const phpLintStart = themeReadinessWorkflow.indexOf('  php-lint:');
    const wordpressFixtureStart = themeReadinessWorkflow.indexOf('  wordpress-fixture:');
    const extendedWhiskStart = themeReadinessWorkflow.indexOf('  extended-whisk:');
    const practicalJob = themeReadinessWorkflow.slice(practicalStart, phpLintStart);
    const phpLintJob = themeReadinessWorkflow.slice(phpLintStart, wordpressFixtureStart);
    const wordpressFixtureJob = themeReadinessWorkflow.slice(wordpressFixtureStart, extendedWhiskStart);
    const extendedWhiskJob = themeReadinessWorkflow.slice(extendedWhiskStart);
    const runtimeAuditIndex = themeReadinessWorkflow.indexOf('- name: Run runtime npm audit');
    const fullAuditIndex = themeReadinessWorkflow.indexOf('- name: Run full npm audit');
    const releaseTargetedPrGate = "(github.event_name == 'pull_request' && (github.base_ref == 'main' || github.base_ref == 'release-2.x'))";

    ensure(themeReadinessWorkflow.includes('name: WordPress Theme Readiness'), 'theme-readiness.yml should identify the WordPress theme readiness workflow.');
    ensure(themeReadinessWorkflow.includes('pull_request:'), 'theme-readiness.yml should run for pull_request events.');
    ensure(themeReadinessWorkflow.includes('workflow_dispatch:'), 'theme-readiness.yml should support manual dispatch.');
    ensure(themeReadinessWorkflow.includes('schedule:'), 'theme-readiness.yml should run on a schedule.');
    ensure(themeReadinessWorkflow.includes("cron: '17 11 * * 1'"), 'theme-readiness.yml should run extended readiness weekly.');
    ensure(themeReadinessWorkflow.includes('release-2.x'), 'theme-readiness.yml should cover the release-2.x branch.');
    ensure(themeReadinessWorkflow.includes('node-version-file: .nvmrc'), 'theme-readiness.yml should set up Node from .nvmrc.');
    ensure(themeReadinessWorkflow.includes("php-version: '8.3'"), 'theme-readiness.yml should set up PHP 8.3.');
    ensure(themeReadinessWorkflow.includes('npm ci --ignore-scripts'), 'theme-readiness.yml should install root npm dependencies cleanly.');
    ensure(themeReadinessWorkflow.includes('composer validate --no-check-publish --strict'), 'theme-readiness.yml should validate Composer metadata.');
    ensure(themeReadinessWorkflow.includes('- name: Run runtime npm audit\n        run: npm audit --omit=dev'), 'theme-readiness.yml should block PR checks on runtime npm audit findings.');
    ensure(themeReadinessWorkflow.includes('- name: Run full npm audit\n        continue-on-error: true\n        run: npm audit'), 'theme-readiness.yml should report full npm audit findings without blocking PR checks.');
    ensure(runtimeAuditIndex < fullAuditIndex, 'theme-readiness.yml should run the blocking runtime audit before the informational full audit.');
    ensure(themeReadinessWorkflow.includes('npm run lint:php'), 'theme-readiness.yml should run PHP lint.');
    ensure(themeReadinessWorkflow.includes('npm run pr:check'), 'theme-readiness.yml should delegate project smoke checks to npm run pr:check.');
    ensure(themeReadinessWorkflow.includes('npm run release:check'), 'theme-readiness.yml should run release readiness checks.');
    ensure(practicalJob.includes('fetch-depth: 0') && wordpressFixtureJob.includes('fetch-depth: 0'), 'Readiness jobs running the release version contract should fetch full history and tags.');
    ensure(practicalJob.includes("- name: Build installable theme archive\n        if: matrix.php-version == '8.3'\n        run: npm run build:dist"), 'The PHP 8.3 practical lane should build the client-testable release archive.');
    const readinessJobs = yaml.load(themeReadinessWorkflow).jobs;
    const archiveUpload = readinessJobs.practical.steps.find((step) => step.name === 'Upload installable theme archive');
    ensure(archiveUpload?.if === "matrix.php-version == '8.3'" && /^actions\/upload-artifact@[a-f0-9]{40}$/.test(archiveUpload.uses), 'The PHP 8.3 practical lane should use a pinned artifact upload.');
    ensure(practicalJob.includes('path: dist-artifact/emulsify.zip') && practicalJob.includes('archive: false') && practicalJob.includes('overwrite: true'), 'Theme readiness should upload the installable ZIP directly and support workflow reruns.');
    ensure(practicalJob.includes('if-no-files-found: error') && practicalJob.includes('retention-days: 14'), 'The client-testable artifact should be required and retained for 14 days.');
    ensure(practicalStart >= 0 && phpLintStart > practicalStart && wordpressFixtureStart > phpLintStart && extendedWhiskStart > wordpressFixtureStart, 'theme-readiness.yml should define practical, PHP, WordPress fixture, and Whisk accessibility jobs.');
    ensure(phpLintJob.includes("php-version: '8.3'") && phpLintJob.includes('tools: composer:v2'), 'Dedicated PHP analysis job should set up PHP 8.3 and Composer.');
    ensure(phpLintJob.includes('composer install --no-interaction --no-progress --prefer-dist'), 'Dedicated PHP analysis job should install Composer development dependencies.');
    ensure(phpLintJob.includes('npm run lint:php'), 'Dedicated PHP analysis job should run PHPCS and PHPStan.');
    ensure(wordpressFixtureJob.includes(releaseTargetedPrGate), 'WordPress fixture job should run on release-targeted pull requests.');
    ensure(wordpressFixtureJob.includes("github.event_name == 'schedule'"), 'WordPress fixture job should run on the schedule.');
    ensure(wordpressFixtureJob.includes("(github.event_name == 'workflow_dispatch' && inputs.wordpress_fixture)"), 'WordPress fixture job should honor its manual input.');
    ensure(wordpressFixtureJob.includes('mysql:'), 'WordPress fixture job should provide MySQL.');
    ensure(wordpressFixtureJob.includes('wp-cli'), 'WordPress fixture job should install WP-CLI.');
    ensure(wordpressFixtureJob.includes("WP_SMOKE_REQUIRED: '1'"), 'WordPress fixture job should require the fixture instead of allowing a skip.');
    ensure(wordpressFixtureJob.includes('- name: Build installable theme archive') && wordpressFixtureJob.includes('run: npm run build:dist'), 'WordPress fixture job should build the installable archive.');
    ensure(wordpressFixtureJob.includes('WP_SMOKE_THEME_ARCHIVE: dist-artifact/emulsify.zip'), 'WordPress fixture job should test the built archive instead of the source tree.');
    ensure(wordpressFixtureSmoke.includes("['core', 'version']") && wordpressFixtureSmoke.includes('WordPress version: requested') && wordpressFixtureSmoke.includes('resolved ${resolvedWordpressVersion}'), 'WordPress fixture smoke should log the actual downloaded core version, including latest and nightly resolution.');
    ensure(wordpressFixtureSmoke.includes('log(`WordPress ${resolvedWordpressVersion} (requested ${wordpressVersion}): Generated'), 'The fixture success summary must expose resolved WordPress versions in captured CI output.');
    ensure(themeReadinessWorkflow.includes('extended_checks'), 'theme-readiness.yml should expose optional extended checks.');
    ensure(extendedWhiskJob.includes(releaseTargetedPrGate), 'Whisk accessibility job should run on release-targeted pull requests.');
    ensure(extendedWhiskJob.includes("github.event_name == 'schedule'"), 'Whisk accessibility job should run on the schedule.');
    ensure(extendedWhiskJob.includes("(github.event_name == 'workflow_dispatch' && inputs.extended_checks)"), 'Whisk accessibility job should honor its manual input.');
    ensure(extendedWhiskJob.includes('cp -R .github/fixtures/whisk-a11y/. whisk/src/components/'), 'Whisk accessibility job should seed the CI-only component fixture.');
    ensure(extendedWhiskJob.includes('Select and verify runner Chrome') && extendedWhiskJob.includes('command -v google-chrome'), 'Whisk accessibility job should use the runner-installed Chrome.');
    ensure(extendedWhiskJob.includes('"$chrome_path" --version') && extendedWhiskJob.includes('"$chrome_path" --headless --dump-dom about:blank') && extendedWhiskJob.includes('PUPPETEER_EXECUTABLE_PATH=%s') && extendedWhiskJob.includes('"$GITHUB_ENV"'), 'Whisk accessibility job should verify a sandboxed Chrome launch and export its path.');
    ensure(!extendedWhiskJob.includes('browser-actions/setup-chrome') && !extendedWhiskJob.includes('--no-sandbox') && !extendedWhiskJob.includes('--disable-setuid-sandbox'), 'Whisk accessibility CI must preserve the installed browser sandbox.');
    ensure(extendedWhiskJob.includes("PUPPETEER_SKIP_DOWNLOAD: 'true'"), 'Whisk CI should skip redundant Puppeteer browser downloads.');
    ensure(extendedWhiskJob.includes('npm --prefix whisk run test -- --runInBand'), 'Whisk CI should execute real seeded project tests before the accessibility audit.');
    ensure(extendedWhiskJob.includes('npm --prefix whisk run a11y'), 'Whisk accessibility job should build Storybook and run the real audit.');
    ensure(!extendedWhiskJob.includes('continue-on-error: true'), 'Whisk accessibility violations should fail the job.');
    ensure(whiskA11yEntry.includes('ciReadinessFixture'), 'CI-only Whisk fixture should provide a Vite entry.');
    ensure(whiskA11yStory.includes("title: 'CI/Readiness fixture'") && whiskA11yStory.includes("import template from './ci-readiness.twig'"), 'CI-only Whisk fixture should provide a discoverable Twig story.');
    ensure(whiskA11yTemplate.includes('<main>') && whiskA11yTemplate.includes('<article') && whiskA11yTemplate.includes('<h2') && whiskA11yTemplate.includes('<a href='), 'CI-only Whisk story should use native semantic landmarks, headings, and links.');
    ensure(prValidationScript.includes('composer') && prValidationScript.includes('validate'), 'PR validation should validate Composer metadata.');
    ensure(prValidationScript.includes('docs:check-commands'), 'PR validation should validate documented npm commands.');
    for (const script of ['test:lint-wrappers', 'smoke:jest-project', 'test:a11y-runner']) {
      ensure(prValidationScript.includes(script), `PR validation should run ${script}.`);
    }
    ensure(prValidationScript.includes('composer') && prValidationScript.includes('install'), 'PR validation should install Composer dependencies for Twig smoke coverage.');
    ensure(!prValidationScript.includes("'lint:php'"), 'PR validation should leave PHP analysis to its dedicated status check.');
    ensure(prValidationScript.includes('smoke:acf-json'), 'PR validation should run the ACF Local JSON smoke test.');
    ensure(prValidationScript.includes('smoke:asset-manifest'), 'PR validation should run the asset manifest smoke test.');
    ensure(prValidationScript.includes('smoke:attributes'), 'PR validation should run the attribute helper smoke test.');
    ensure(prValidationScript.includes('smoke:block-assets'), 'PR validation should run the block scoped asset smoke test.');
    ensure(prValidationScript.includes('smoke:bootstrap-loader'), 'PR validation should run the Bootstrap loader smoke test.');
    ensure(prValidationScript.indexOf('composer') < prValidationScript.indexOf('smoke:bootstrap-loader'), 'PR validation should install Composer dependencies before checking runtime autoloading.');
    ensure(prValidationScript.includes('smoke:child-theme-generator'), 'PR validation should run the child theme generator smoke test.');
    ensure(prValidationScript.includes('smoke:component-locator'), 'PR validation should run the component locator smoke test.');
    ensure(prValidationScript.includes('smoke:editor-enhancements'), 'PR validation should run the editor enhancements smoke test.');
    ensure(prValidationScript.includes('smoke:editor-policy'), 'PR validation should run the editor policy smoke test.');
    ensure(prValidationScript.includes('smoke:patterns'), 'PR validation should run the pattern registry smoke test.');
    ensure(prValidationScript.includes('smoke:starter-init'), 'PR validation should run the WordPress starter init smoke test.');
    ensure(prValidationScript.includes('smoke:theme-filters'), 'PR validation should run the parent theme filter smoke test.');
    ensure(prValidationScript.includes('smoke:twig-autoescape'), 'PR validation should run the Twig autoescape smoke test.');
    ensure(prValidationScript.includes('smoke:twig-switch'), 'PR validation should run the Twig switch smoke test.');
    ensure(prValidationScript.includes('smoke:twig-project-namespace'), 'PR validation should run the Twig project namespace smoke test.');
    ensure(prValidationScript.includes('whisk:install'), 'PR validation should install Whisk dependencies.');
    ensure(prValidationScript.indexOf('whisk:install') < prValidationScript.indexOf('smoke:starter-init'), 'PR validation should install Whisk dependencies before checking the starter npm test script.');
    ensure(!prValidationScript.includes('whisk:build'), 'PR validation should not require a Whisk build before a component system is installed.');
    return 'Theme readiness runs fast checks on every configured pull request and extended checks for release targets, schedules, and manual dispatches.';
  });

  runStaticCheck('Readiness matrix contract', () => {
    const entries = validateReadinessMatrix(themeReadinessWorkflow, {
      minimumWordPress: rootThemeHeader['Requires at least'],
    });
    return `Blocking WordPress minimum and latest, advisory nightly, and ${entries.practical.filter((entry) => !entry.experimental).length} blocking PHP legs are required.`;
  });

  runStaticCheck('Pinned workflow actions and validation credentials', () => {
    const files = listFilesRecursive('.github/workflows', (file) => /\.ya?ml$/.test(file));
    for (const file of files) {
      const source = readFile(file);
      for (const line of source.split(/\r?\n/)) {
        if (!/^\s*(?:-\s*)?uses:\s*/.test(line)) continue;
        ensure(/^\s*(?:-\s*)?uses:\s*[\w.-]+\/[\w./-]+@[a-f0-9]{40}\s+#\s*v\d+\.\d+\.\d+(?:[\w.+-]*)?\s*$/.test(line),
          `${file} must pin every action to a full commit SHA with its release version comment: ${line.trim()}`);
        if (line.includes('actions/upload-artifact@')) ensure(/#\s*v7\./.test(line), `${file} must use the Node 24 upload-artifact v7 release.`);
      }
      const workflow = yaml.load(source);
      ensure(workflow.permissions?.contents === 'read' && Object.keys(workflow.permissions).length === 1,
        `${file} must default to exactly contents: read.`);
      ensure(!Object.hasOwn(workflow.on || {}, 'pull_request_target'), `${file} must not run untrusted pull requests with pull_request_target credentials.`);
      for (const [jobName, job] of Object.entries(workflow.jobs || {})) {
        const publishingJob = file === '.github/workflows/semantic-release.yml' && jobName === 'semantic-release';
        if (publishingJob) {
          ensure(job.permissions?.contents === 'write' && job.permissions?.issues === 'write'
            && job.permissions?.['pull-requests'] === 'write' && Object.keys(job.permissions).length === 3,
          'The semantic-release publishing job must request exactly contents, issues, and pull-requests write permissions.');
        }
        else {
          ensure(job.permissions === undefined || (typeof job.permissions === 'object'
            && Object.values(job.permissions).every((value) => value === 'read' || value === 'none')),
          `${file} ${jobName} validation permissions must remain read-only.`);
        }
        for (const step of job.steps || []) {
          if (!step.uses?.startsWith('actions/checkout@') || publishingJob) continue;
          ensure(step.with?.['persist-credentials'] === false, `${file} ${jobName} validation checkout must disable persisted credentials.`);
        }
      }
    }
    return 'All workflow actions use full SHA pins with version comments, and validation checkouts do not retain credentials.';
  });

  runStaticCheck('Release documentation', () => {
    const expectedDocLinks = [
      'docs/README.md',
      'docs/upgrading-1x-to-2x.md',
      'docs/sister-project-parity.md',
      'docs/parent-child-architecture.md',
      'docs/timber-and-twig-authoring.md',
      'docs/core-4-vite-workflow.md',
      'docs/acf-local-json.md',
      'docs/acf-twig-blocks.md',
      'docs/native-gutenberg-blocks.md',
      'docs/block-patterns.md',
      'docs/editor-enhancements.md',
      'docs/editor-policy.md',
      'docs/asset-loading.md',
      'docs/wp-cli-child-theme-generation.md',
      'docs/component-recipes.md',
      'docs/release-process.md',
      'docs/post-2x-optimization-roadmap.md',
    ];

    ensure(readme.includes('Emulsify WordPress is a Timber-first WordPress parent theme'), 'README.md should describe the Timber-first parent theme.');
    ensure(readme.includes('Emulsify WordPress is licensed under GPL-2.0-only'), 'README.md should document the GPL-2.0-only license.');
    ensure(readme.includes('[LICENSE](LICENSE)'), 'README.md should link to the repository license file.');
    ensure(readme.includes('## Requirements'), 'README.md should keep requirements visible.');
    ensure(readme.includes('## Installation') && readme.includes('### Composer (primary)'), 'README.md should document Composer as the primary installation path.');
    ensure(readme.includes('### Manual release ZIP') && readme.includes('emulsify.zip'), 'README.md should document the supported manual release ZIP.');
    ensure(readme.includes('already includes production Composer dependencies under `vendor/`'), 'README.md should explain that the manual ZIP bundles production dependencies.');
    ensure(readme.includes('emulsify-ds/emulsify-wordpress-theme:^2.0'), 'README.md should use the published Composer package coordinate.');
    ensure(readme.includes('installer-name: emulsify'), 'README.md should explain the required Composer install slug.');
    ensure(readme.includes("not GitHub's automatically generated **Source code** archives"), 'README.md should distinguish the manual release asset from source archives.');
    ensure(readme.includes('WordPress.org listing and SVN deployment are planned as a future release step'), 'README.md should identify WordPress.org publishing as future work.');
    ensure(readme.includes('uploads the built `emulsify.zip` directly') && readme.includes('workflow artifact for client testing'), 'README.md should explain how to get the packaged pre-release artifact.');
    ensure(readme.includes('## Using Emulsify WordPress in a site project'), 'README.md should keep site project usage guidance visible.');
    ensure(readme.includes('## Working inside a generated child theme'), 'README.md should keep child theme workflow guidance visible.');
    ensure(readme.includes('## Parent and child themes'), 'README.md should keep the parent/child overview visible.');
    ensure(readme.includes('## Developing or releasing the parent theme'), 'README.md should keep parent maintainer commands visible.');
    ensure(readme.includes('## Documentation'), 'README.md should link to deeper docs.');
    ensure(readme.includes('Do not run root npm commands in the parent theme for normal site implementation'), 'README.md should distinguish parent npm tooling from project runtime work.');
    ensure(readme.includes('Require `timber/timber` from the application-level Composer project'), 'README.md should document application-level Timber installation.');
    ensure(readme.includes('npm ci --ignore-scripts'), 'README.md should document parent maintainer npm install.');
    ensure(readme.includes('composer install'), 'README.md should document Composer install usage.');
    ensure(readme.includes('whisk/project.emulsify.json') && readme.includes('"platform": "wordpress"'), 'README.md should explain the current project.emulsify.json platform setting.');
    ensure(readme.includes('generatedFrom') && readme.includes('generatedFromVersion'), 'README.md should explain generated child theme source metadata.');
    ensure(readme.includes('whisk/assets/images') && readme.includes('whisk/assets/icons'), 'README.md should document the generated child asset placeholders.');
    ensure(readme.includes('wp emulsify "Acme Site" --machine-name=acme-site'), 'README.md should document child theme generator examples.');
    ensure(readme.includes('--parent=emulsify'), 'README.md should document the child theme generator parent option.');
    ensure(readme.includes('docs/component-recipes.md'), 'README.md should link to component recipes.');
    ensure(readme.includes('### Linting and static analysis'), 'README.md should document local PHP analysis.');
    ensure(readme.includes('`lint:php` runs PHPCS') && readme.includes('PHPStan'), 'README.md should explain the combined PHPCS and PHPStan command.');
    ensure(readme.includes('npm run lint:php:fix') && readme.includes('.husky/pre-commit'), 'README.md should document PHPCBF fixes and the pre-commit PHP gate.');
    ensure(readme.includes('Every pull request to the configured branches runs two visible fast readiness jobs') && readme.includes('Pull requests targeting `main` or `release-2.x` additionally run'), 'README.md should distinguish fast pull-request checks from release-targeted extended checks.');
    ensure(readme.includes('Weekly scheduled runs repeat the WordPress fixture and Whisk accessibility audit'), 'README.md should document the weekly extended readiness cadence.');
    ensure(readme.includes('GitHub Actions > `WordPress Theme Readiness`'), 'README.md should tell maintainers where to run the manual fixture workflow.');
    ensure(readme.includes('WP_SMOKE_REQUIRED=1'), 'README.md should document required WordPress fixture smoke behavior.');

    for (const docLink of expectedDocLinks) {
      ensure(readme.includes(docLink), `README.md should link to ${docLink}.`);
    }

    ensure(docs.upgrading.includes('Emulsify WordPress 2.x changes the project model'), 'Upgrade doc should explain the 2.x project model.');
    ensure(docs.upgrading.includes('generatedFrom: "emulsify-wordpress"') && docs.upgrading.includes('Older generated child themes may not include these fields'), 'Upgrade doc should document generated child theme lineage metadata.');
    ensure(docs.parity.includes('Emulsify WordPress is the WordPress sister project to Emulsify Drupal'), 'Sister-project parity doc should name the Drupal sister project.');
    ensure(docs.parity.includes('The parent theme owns reusable CMS runtime behavior'), 'Sister-project parity doc should define parent runtime ownership.');
    ensure(docs.parity.includes('The generated child theme owns project implementation'), 'Sister-project parity doc should define child theme ownership.');
    ensure(docs.parity.includes('Whisk is the starter'), 'Sister-project parity doc should define Whisk as the starter.');
    ensure(docs.parity.includes('Emulsify Core 4 provides the component workflow'), 'Sister-project parity doc should document the Core 4 workflow.');
    ensure(docs.parity.includes('Vite builds frontend assets'), 'Sister-project parity doc should document Vite.');
    ensure(docs.parity.includes('Storybook presents component examples'), 'Sister-project parity doc should document Storybook.');
    ensure(docs.parity.includes('Twig is the component template language'), 'Sister-project parity doc should document Twig.');
    ensure(docs.parity.includes('Node 24 is the expected JavaScript runtime'), 'Sister-project parity doc should document Node 24.');
    ensure(docs.parity.includes('Built global assets are emitted under `dist/global`'), 'Sister-project parity doc should document global build output.');
    ensure(docs.parity.includes('Built component assets and block metadata are emitted under `dist/components`'), 'Sister-project parity doc should document component build output.');
    ensure(docs.parity.includes('Frontend rendering uses Timber'), 'Sister-project parity doc should document Timber as a WordPress difference.');
    ensure(docs.twig.includes('## Switch statements') && docs.twig.includes("{% case 'primary' or 'secondary' %}"), 'Twig authoring docs should document switch tags and multiple case values.');
    ensure(docs.release.includes('Twig switch tag smoke test'), 'Release documentation should list Twig switch smoke coverage.');
    ensure(docs.release.includes('npm run docs:check-commands'), 'Release documentation should include the documentation command validator in local checks.');
    ensure(docs.parity.includes('WordPress theme identity lives in `style.css` headers'), 'Sister-project parity doc should document WordPress theme headers.');
    ensure(docs.parity.includes('`theme.json` is the WordPress site and editor configuration surface'), 'Sister-project parity doc should document theme.json.');
    ensure(docs.parity.includes('Whisk does not include a child `theme.json` by default'), 'Sister-project parity doc should explain why Whisk does not ship an empty child theme.json.');
    ensure(docs.parity.includes('ACF/Twig block registration is an optional WordPress integration'), 'Sister-project parity doc should document ACF/Twig blocks.');
    ensure(docs.parity.includes('Native Gutenberg blocks use WordPress `block.json` metadata'), 'Sister-project parity doc should document native block.json blocks.');
    ensure(docs.parity.includes("WordPress project generation is handled by the parent theme's WP-CLI command"), 'Sister-project parity doc should document WP-CLI generation.');
    ensure(docs.parity.includes('`project.emulsify.json` uses `"platform": "wordpress"`'), 'Sister-project parity doc should document the WordPress platform adapter.');
    ensure(docs.parity.includes('generatedFrom: "emulsify-wordpress"'), 'Sister-project parity doc should document generated child theme lineage metadata.');
    ensure(docs.parity.includes('{% include "project_machine_name:component_name" %}') && docs.parity.includes('The legacy `@components/component-name/component-name.twig` namespace remains supported'), 'Sister-project parity doc should promote generic project machine-name component includes while preserving @components compatibility.');
    ensure(docs.architecture.includes('The parent theme owns reusable runtime behavior'), 'Architecture doc should explain parent responsibilities.');
    ensure(docs.architecture.includes('generatedFrom: "emulsify-wordpress"') && docs.architecture.includes('safer replacement checks'), 'Architecture doc should document generated child theme lineage metadata.');
    ensure(docs.architecture.includes('@emulsify-tpl'), 'Architecture doc should document the parent-only template namespace.');
    ensure(docs.architecture.includes('{% include "project_machine_name:component_name" %}') && docs.architecture.includes('The legacy `@components/component-name/component-name.twig` namespace remains supported'), 'Architecture doc should document generic project machine-name component includes while preserving @components compatibility.');
    ensure(docs.architecture.includes('Optional persistent discovery cache') && docs.architecture.includes('clear_discovery_cache'), 'Architecture doc should document the optional component discovery cache and clear method.');
    ensure(docs.twig.includes('@templates') && docs.twig.includes('@components'), 'Twig doc should document core namespaces.');
    ensure(docs.twig.includes('Install or author project components in the structure defined by the selected Emulsify component system'), 'Twig doc should avoid prescribing a component source structure.');
    ensure(docs.twig.includes('The general form is `project_machine_name:component_name`'), 'Twig doc should document the generic project component include form.');
    ensure(docs.twig.includes('The legacy `@components/example-card/example-card.twig` namespace remains supported for compatible component libraries'), 'Twig doc should preserve @components compatibility language.');
    ensure(docs.twig.includes('[Component recipes](component-recipes.md)'), 'Twig doc should link to component recipes.');
    ensure(docs.twig.includes('emulsify_theme_context'), 'Twig doc should document context extension.');
    ensure(docs.workflow.includes('Core 4, Vite, and Storybook commands'), 'Workflow doc should use the expected command heading.');
    ensure(docs.workflow.includes('npm run inspect:components -- --json') && docs.workflow.includes('npm run inspect:components -- --help'), 'Core 4 workflow doc should include component inspector JSON and help examples.');
    ensure(docs.workflow.includes('metadata, dependencies, configuration issues, and orphaned files'), 'Core 4 workflow doc should explain the component inspector report.');
    ensure(/published\s+`emulsify-inspect-components` binary/.test(docs.workflow), 'Core 4 workflow doc should attribute the component inspector implementation to Emulsify Core.');
    ensure(docs.workflow.includes('"platform": "wordpress"'), 'Workflow doc should explain the WordPress platform adapter.');
    ensure(docs.workflow.includes('generatedFrom: "emulsify-wordpress"') && docs.workflow.includes('support diagnostics'), 'Core 4 workflow doc should document generated child theme lineage metadata.');
    ensure(docs.workflow.includes('does not ship a concrete component library'), 'Core 4 workflow doc should describe Whisk as component-system agnostic.');
    ensure(docs.workflow.includes('assets/images') && docs.workflow.includes('assets/icons'), 'Core 4 workflow doc should document generic starter asset directories.');
    ensure(docs.workflow.includes('does not include a child `theme.json` by default'), 'Core 4 workflow doc should document the child theme.json convention.');
    ensure(docs.workflow.includes('The following shape is an example of a compatible component, not files shipped by Whisk'), 'Core 4 workflow doc should keep component examples documentation-only.');
    ensure(docs.workflow.includes('[Component recipes](component-recipes.md)'), 'Core 4 workflow doc should link to component recipes.');
    ensure(docs.workflow.includes('{% include "project_machine_name:component_name" %}') && docs.workflow.includes('The legacy `@components/component-name/component-name.twig` namespace remains supported'), 'Core 4 workflow doc should promote generic project machine-name component includes while preserving @components compatibility.');
    ensure(docs.generatedThemeUpgrade.includes('"@emulsify/core": "^4.3.0"'), 'Generated-theme upgrade guide should require Emulsify Core 4.3.0 or newer.');
    ensure(docs.generatedThemeUpgrade.includes('"inspect:components": "emulsify-inspect-components"'), 'Generated-theme upgrade guide should show how existing themes expose the component inspector binary.');
    ensure(/Whisk\s+changes only affect themes generated after the new Emulsify Drupal release/.test(docs.generatedThemeUpgrade), 'Generated-theme upgrade guide should explain the Drupal Whisk generation boundary.');
    ensure(docs.generatedThemeUpgrade.includes('Publish the compatible `@emulsify/core` 4.3.0 release') && docs.generatedThemeUpgrade.includes('Merge and release the consuming Emulsify WordPress or Emulsify Drupal'), 'Generated-theme upgrade guide should preserve the Core-first release order.');
    ensure(docsCommandCheck.includes("expectedScripts: ['inspect:components']") && docsCommandCheck.includes("packagePath: 'whisk/package.json'"), 'Documentation command validation should check the generated-theme inspector script.');
    ensure(docs.componentRecipes.includes('not files that must ship in every starter'), 'Component recipes doc should keep examples documentation-only.');
    ensure(docs.componentRecipes.includes('Do not add a full component library to `whisk/src/components`'), 'Component recipes doc should avoid adding active starter components to Whisk.');
    ensure(docs.componentRecipes.includes('src/components/card/card.twig'), 'Component recipes doc should include a Twig component example.');
    ensure(docs.componentRecipes.includes('src/components/card/card.stories.js'), 'Component recipes doc should include a Storybook story example.');
    ensure(docs.componentRecipes.includes('src/components/card/card.component.json'), 'Component recipes doc should include an ACF/Twig component metadata example.');
    ensure(docs.componentRecipes.includes('src/components/card/block.json'), 'Component recipes doc should include a native block metadata example.');
    ensure(docs.componentRecipes.includes('patterns/card-feature.json'), 'Component recipes doc should include a pattern JSON example.');
    ensure(docs.componentRecipes.includes('Use core block Twig rendering only'), 'Component recipes doc should explain core block Twig rendering use.');
    ensure(docs.acfJson.includes('config/acf-json'), 'ACF Local JSON doc should document the child theme JSON path.');
    ensure(docs.acfJson.includes('emulsify_theme_acf_json_save_path'), 'ACF Local JSON doc should document the save path filter.');
    ensure(docs.acfJson.includes('commit ACF JSON files'), 'ACF Local JSON doc should tell project teams to commit ACF JSON.');
    ensure(docs.acfBlocks.includes('Whisk does not include an active ACF/Twig block example'), 'ACF/Twig blocks doc should explain that starter metadata is documentation-only.');
    ensure(docs.acfBlocks.includes('[Component recipes](component-recipes.md)'), 'ACF/Twig blocks doc should link to component recipes.');
    ensure(docs.acfBlocks.includes('emulsify_theme_acf_block_args'), 'ACF/Twig blocks doc should document the block args filter.');
    ensure(docs.acfBlocks.includes('Scoped assets') && docs.acfBlocks.includes('emulsify_theme_acf_block_asset_records'), 'ACF/Twig blocks doc should document scoped block assets.');
    ensure(docs.nativeBlocks.includes('The starter does not include an active native block example'), 'Native blocks doc should avoid over-claiming a native example.');
    ensure(docs.nativeBlocks.includes('[Component recipes](component-recipes.md)'), 'Native block doc should link to component recipes.');
    ensure(docs.nativeBlocks.includes('emulsify_theme_native_block_directories'), 'Native blocks doc should document the native block directories filter.');
    ensure(docs.nativeBlocks.includes('style`, `script`, `viewScript`') && docs.nativeBlocks.includes('register_block_type()'), 'Native blocks doc should document WordPress-owned block.json asset loading.');
    ensure(docs.blockPatterns.includes('patterns/*.json'), 'Block patterns doc should document JSON pattern discovery.');
    ensure(docs.blockPatterns.includes('patterns/categories.json') && docs.blockPatterns.includes('patterns/_categories.json'), 'Block patterns doc should document category metadata files.');
    ensure(docs.blockPatterns.includes('[Component recipes](component-recipes.md)'), 'Block patterns doc should link to component recipes.');
    ensure(docs.blockPatterns.includes('emulsify_theme_pattern_directories'), 'Block patterns doc should document directory filtering.');
    ensure(docs.blockPatterns.includes('emulsify_theme_pattern_args'), 'Block patterns doc should document final args filtering.');
    ensure(docs.editorEnhancements.includes('emulsify_theme_editor_enhancements_config'), 'Editor enhancements doc should document the config filter.');
    ensure(docs.editorEnhancements.includes('columnsEqualHeight'), 'Editor enhancements doc should document the columns module.');
    ensure(docs.editorEnhancements.includes('fileCaption'), 'Editor enhancements doc should document the file caption module.');
    ensure(docs.editorEnhancements.includes('embedVariations'), 'Editor enhancements doc should document the embed variation module.');
    ensure(docs.editorEnhancements.includes('placement'), 'Editor enhancements doc should document the placement module.');
    ensure(docs.editorPolicy.includes('emulsify_theme_editor_policy_options'), 'Editor policy doc should document the options filter.');
    ensure(docs.editorPolicy.includes('auto_allow_pattern_blocks'), 'Editor policy doc should document pattern JSON auto-allow behavior.');
    ensure(docs.editorPolicy.includes('emulsify_theme_block_support_overrides'), 'Editor policy doc should document block support overrides.');
    ensure(docs.assets.includes('emulsify_theme_asset_directories'), 'Asset loading doc should document asset directory filtering.');
    ensure(docs.assets.includes('dist/emulsify-assets.json') && docs.assets.includes('emulsify_theme_asset_manifest_path'), 'Asset loading doc should document optional manifest loading.');
    ensure(docs.assets.includes('block-scoped assets') && docs.assets.includes('emulsify_theme_acf_block_asset_records'), 'Asset loading doc should document block-scoped asset behavior.');
    ensure(docs.assets.includes('Manifest data is memoized') && docs.assets.includes('Scanner fallback records are also memoized'), 'Asset loading doc should document per-request manifest and scanner memoization.');
    ensure(docs.coreBlockTwig.includes('[Component recipes](component-recipes.md)'), 'Core block Twig rendering doc should link to component recipes.');
    ensure(docs.cli.includes('--parent=<slug>') && docs.cli.includes('Defaults to `emulsify`'), 'WP-CLI doc should document the parent option and its default.');
    ensure(docs.cli.includes('--dry-run') && docs.cli.includes('--force') && docs.cli.includes('--activate'), 'WP-CLI doc should document generator safety options.');
    ensure(docs.cli.includes('## Bedrock and DDEV') && docs.cli.includes('path: web/wp'), 'WP-CLI doc should configure the Bedrock WordPress core path.');
    ensure(docs.cli.includes('ddev wp --path=web/wp emulsify'), 'WP-CLI doc should provide an explicit DDEV Bedrock command.');
    ensure(docs.cli.includes('No WordPress installation found') && docs.cli.includes('never loading'), 'WP-CLI doc should explain the downstream command-registration error.');
    ensure(docs.cli.includes('Force replacement safety') && docs.cli.includes('Emulsify-generated child theme markers'), 'WP-CLI doc should document force replacement safety.');
    ensure(docs.cli.includes('Upgrade and support diagnostics') && docs.cli.includes('generatedFromVersion'), 'WP-CLI doc should document generated child theme lineage diagnostics.');
    ensure(docs.cli.includes('Ignored dependency, cache, Storybook, and Vite output directories are not copied'), 'WP-CLI doc should explain that generated themes do not inherit build output.');
    ensure(docs.release.includes('## Release PR procedure') && docs.release.includes('prepared version'), 'Release process doc should explain preparing a version in a release PR.');
    ensure(docs.release.includes('Packagist lists `emulsify-ds/emulsify-wordpress-theme` as maintained'), 'Release process doc should require the published Composer package to be un-abandoned.');
    ensure(docs.release.includes('WordPress Theme Readiness workflow'), 'Release process doc should document the theme readiness workflow.');
    ensure(docs.index.includes('## Continuous integration') && docs.index.includes('dedicated PHPCS/PHPStan job') && docs.index.includes('Pull requests targeting `main` or `release-2.x` additionally run'), 'Docs index should summarize fast and release-targeted pull-request readiness coverage.');
    ensure(docs.release.includes('Every configured pull request runs two independently visible fast jobs') && docs.release.includes('Pull requests targeting `main` or `release-2.x` additionally run the two extended jobs'), 'Release process doc should distinguish fast pull-request checks from release-targeted extended checks.');
    ensure(docs.release.includes('Weekly scheduled runs repeat both extended jobs'), 'Release process doc should explain scheduled extended coverage.');
    ensure(docs.release.includes('Open GitHub Actions for `emulsify-ds/emulsify-wordpress`'), 'Release process doc should tell maintainers where to trigger the manual workflow.');
    ensure(docs.release.includes('Keep `wordpress_fixture` enabled'), 'Release process doc should document the manual workflow fixture input.');
    ensure(docs.release.includes('`PHP coding standards and static analysis`') && docs.release.includes('`Extended Whisk Storybook and a11y`'), 'Release process doc should name the dedicated PHP and Whisk jobs.');
    ensure(docs.release.includes('generates and activates a child theme from the packaged Whisk source'), 'Release process doc should document generated child fixture coverage.');
    ensure(docs.release.includes('tracked `whisk/` child starter required by `wp emulsify`'), 'Release process doc should require Whisk in the installable archive.');
    ensure(docs.release.includes('uploads `emulsify.zip` directly for 14 days'), 'Release process doc should describe the pre-release package artifact.');
    ensure(docs.release.includes('extracts the built `emulsify.zip`') && docs.release.includes('packaged `wp emulsify` command'), 'Release process doc should describe artifact-level WordPress fixture coverage.');
    ensure(docs.release.includes('ACF/Twig and native `block.json` discovery'), 'Release process doc should document block discovery fixture coverage.');
    ensure(docs.release.includes('WP_SMOKE_REQUIRED=1'), 'Release process doc should document required fixture smoke behavior.');
    ensure(docs.release.includes('CI-only component seed') && docs.release.includes('home/page/single/archive/search/author/404'), 'Release process doc should document the CI seed and real route coverage.');
    ensure(docs.post2xRoadmap.includes('follow-up opportunities for focused minor releases, not 2.0 blockers'), 'Post-2.x roadmap should frame items as follow-up opportunities.');
    ensure(docs.post2xRoadmap.includes('Ship 2.0 without adding new runtime features'), 'Post-2.x roadmap should keep 2.0 focused.');
    ensure(docs.post2xRoadmap.includes('## Done in 2.0'), 'Post-2.x roadmap should separate completed 2.0 work from follow-up work.');
    ensure(docs.post2xRoadmap.includes('Composer PSR-4 autoloading and grouped runtime directories'), 'Post-2.x roadmap should identify runtime class grouping as completed.');
    ensure(docs.post2xRoadmap.includes('Optional manifest-driven asset loading') || docs.post2xRoadmap.includes('optional manifest-driven asset loading'), 'Post-2.x roadmap should identify asset manifest support as completed.');
    ensure(docs.post2xRoadmap.includes('AssetRecord') && docs.post2xRoadmap.includes('AssetEnqueuer') && docs.post2xRoadmap.includes('Diagnostics'), 'Post-2.x roadmap should identify shared Support helpers as completed.');
    ensure(docs.post2xRoadmap.includes('ProjectComponentLoader'), 'Post-2.x roadmap should identify the named Twig project component loader as completed.');
    ensure(docs.post2xRoadmap.includes('wp emulsify doctor'), 'Post-2.x roadmap should include CLI diagnostics.');
    ensure(docs.post2xRoadmap.includes('PHPUnit'), 'Post-2.x roadmap should include migration of smoke coverage into PHPUnit or an equivalent WordPress-aware layer.');
    ensure(docs.post2xRoadmap.includes('block.json-based ACF registration'), 'Post-2.x roadmap should include block.json-based ACF registration as a follow-up.');
    ensure(docs.post2xRoadmap.includes('optional persistent discovery caching') && docs.post2xRoadmap.includes('invalidation guidance'), 'Post-2.x roadmap should keep cache follow-up work focused on diagnostics and guidance.');
    for (const heading of [
      '## Done in 2.0',
      '## Code organization',
      '## Runtime architecture',
      '## Asset loading and performance',
      '## Component/block discovery',
      '## CLI diagnostics',
      '## Editor and block feature modules',
      '## Documentation and support tooling',
    ]) {
      ensure(docs.post2xRoadmap.includes(heading), `Post-2.x roadmap should include ${heading}.`);
    }
    for (const docLink of [
      'upgrading-1x-to-2x.md',
      'parent-child-architecture.md',
      'wp-cli-child-theme-generation.md',
      'timber-and-twig-authoring.md',
      'component-recipes.md',
      'core-4-vite-workflow.md',
      'acf-twig-blocks.md',
      'native-gutenberg-blocks.md',
      'core-block-twig-rendering.md',
      'block-patterns.md',
      'editor-policy.md',
      'editor-enhancements.md',
      'acf-local-json.md',
      'asset-loading.md',
      'release-process.md',
      'sister-project-parity.md',
      'post-2x-optimization-roadmap.md',
    ]) {
      const matches = docs.index.split(`(${docLink})`).length - 1;
      ensure(matches === 1, `Docs index should link to ${docLink} exactly once.`);
    }
    ensure(pullRequestTemplate.includes('PHP coding standards and static analysis') && pullRequestTemplate.includes('Extended Whisk Storybook and a11y'), 'PR template should require the complete readiness status set.');
    ensure(/duplicate[\w\s/`.-]*skipped instead of being registered twice/i.test(docsText), 'Docs should document duplicate block handling.');
    ensure(docsText.includes('normal frontend visitors') || docsText.includes('Normal frontend visitors'), 'Docs should document that duplicate diagnostics avoid frontend noise.');
    // UPGRADE.md is the one place the retired tool may be named, because its
    // job is telling readers what they are migrating away from. Everywhere else
    // a Webpack reference means stale 1.x language.
    const docsTextWithoutUpgradeGuide = Object.entries(docs)
      .filter(([key]) => key !== 'generatedThemeUpgrade')
      .map(([, value]) => value)
      .join('\n');
    ensure(!/Webpack/i.test(`${readme}\n${docsTextWithoutUpgradeGuide}`), 'Docs should not mention Webpack.');
    ensure(/Webpack to Vite/.test(docs.generatedThemeUpgrade), 'UPGRADE.md should explain the Webpack to Vite move for 1.x projects.');
    ensure(!incorrectWordPressPattern.test(`${readme}\n${docsText}`), 'Docs should use the canonical WordPress spelling.');
    ensure(issueTemplate.includes('emulsify-wordpress/releases'), 'Issue template should link to WordPress theme releases.');
    ensure(pullRequestTemplate.includes('emulsify-wordpress/issues/1'), 'Pull request template should link to WordPress theme issues.');
    ensure(!/emulsify-drupal/.test(`${issueTemplate}\n${pullRequestTemplate}`), 'GitHub templates should not link to the Drupal repository.');
    return 'README, docs, and GitHub templates match the WordPress parent theme release process.';
  });

  runStaticCheck('License metadata', () => {
    ensureGpl2LicenseText('LICENSE', license);
    ensure(!fs.existsSync(path.join(repoRoot, 'LICENSE.txt')), 'LICENSE.txt should not duplicate the canonical LICENSE file.');
    ensure(!/MIT License/i.test(readme), 'README.md should not document an MIT license.');
    return 'License files and project metadata align on GPL-2.0-only.';
  });

  runStaticCheck('No Drupal.org workflow references', () => {
    const forbiddenPatterns = [
      /Drupal\.org/i,
      /drupal-org/i,
      /DRUPAL_ORG/,
      /DRUPAL_REPO_URL/,
      /shimataro\/ssh-key-action/,
      /SSH_CONFIG/,
      /KNOWN_HOSTS/,
    ];
    const files = listFilesRecursive('.github/workflows', (file) => /\.ya?ml$/.test(file));
    const violations = [];

    for (const file of files) {
      const contents = readFile(file);
      for (const pattern of forbiddenPatterns) {
        if (pattern.test(contents)) {
          violations.push(`${file} matches ${pattern}`);
        }
      }
    }

    ensure(violations.length === 0, `Remove Drupal.org workflow references:\n${violations.join('\n')}`);
    return 'No Drupal.org sync workflow references or stale Drupal.org secrets remain.';
  });

  runStaticCheck('No .DS_Store files', () => {
    const dsStoreFiles = listFilesRecursive('.', (file) => path.basename(file) === '.DS_Store')
      .filter((file) => !file.startsWith('.git/'));
    ensure(dsStoreFiles.length === 0, `.DS_Store files found outside .git: ${dsStoreFiles.join(', ')}.`);
    return 'No .DS_Store files were found outside .git.';
  });

  runStaticCheck('Theme JSON styles', () => {
    const errors = validateThemeJsonStyles(readJson('theme.json'));
    ensure(errors.length === 0, errors.join('\n'));
    return 'Style values are scalar where required and WordPress preset references resolve to settings.';
  });

  runCommandCheck('Theme JSON style contract tests', process.execPath, ['--test', '.github/scripts/theme-json-style-contract.test.cjs']);
  runCommandCheck('Release version contract tests', process.execPath, ['--test', '.github/scripts/release-version-contract.test.cjs']);
  runCommandCheck('Readiness matrix contract tests', process.execPath, ['--test', '.github/scripts/readiness-matrix-contract.test.cjs']);
  runCommandCheck('Semantic release guard tests', process.execPath, ['--test', '.github/scripts/semantic-release.test.cjs']);

  if (skipSmoke) {
    addResult('SKIP', 'WordPress fixture smoke', 'Skipped by --skip-smoke.');
  }
  else {
    runCommandCheck('WordPress fixture smoke', process.execPath, ['.github/scripts/wordpress-fixture-smoke.cjs']);
  }
}

function printSummary() {
  console.log('\nRelease Check Summary');
  for (const result of results) {
    console.log(`${result.status.padEnd(4)} ${result.name}: ${result.detail}`);
  }
}

runStaticChecks();
printSummary();

const hasBlockingFailure = results.some((result) => result.status === 'FAIL');
process.exit(hasBlockingFailure ? 1 : 0);
