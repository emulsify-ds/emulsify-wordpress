#!/usr/bin/env node

// Proves the two supported generation paths produce the same child theme.
//
// Emulsify WordPress can generate a child theme through the in-site WP-CLI
// command or through the standalone starter's `.cli/init.js` hook. They are
// separate implementations in different languages, so nothing but an explicit
// comparison keeps them from drifting. This generates the same identity through
// both, validates each against the generated child theme contract, and requires
// their file trees to match.

const childProcess = require('node:child_process');
const crypto = require('node:crypto');
const fs = require('node:fs');
const os = require('node:os');
const path = require('node:path');

const { validateGeneratedTheme } = require('./generated-theme-contract.cjs');

const repoRoot = path.resolve(__dirname, '../..');
const starterRoot = path.join(repoRoot, 'whisk');

const excludedCopySegments = new Set([
  '.coverage',
  '.git',
  '.out',
  '.cache',
  'dist',
  'node_modules',
]);

// The standalone starter runs `npm install` before its init hook, so it owns a
// lockfile the in-site path never creates. That is an expected difference.
const comparisonExclusions = new Set([
  'package-lock.json',
  'npm-shrinkwrap.json',
]);

const scenarios = [
  {
    machineName: 'acme-site',
    displayName: 'Acme Site',
    description: 'Marketing site for Acme, Inc. (2026 rebuild).',
  },
  {
    machineName: 'civic-portal',
    displayName: 'Civic Portal',
    description: 'Resident services portal: forms, alerts & payments.',
  },
];

function assert(condition, message) {
  if (!condition) {
    throw new Error(message);
  }
}

const phpBinary = process.env.EMULSIFY_PHP_BINARY || 'php';

function hasPhp() {
  const probe = childProcess.spawnSync(phpBinary, ['--version'], {
    encoding: 'utf8',
  });

  return !probe.error && probe.status === 0;
}

function copyStarter(source, destination) {
  fs.mkdirSync(destination, { recursive: true });

  for (const entry of fs.readdirSync(source, { withFileTypes: true })) {
    if (excludedCopySegments.has(entry.name)) {
      continue;
    }

    const sourcePath = path.join(source, entry.name);
    const destinationPath = path.join(destination, entry.name);

    if (entry.isDirectory()) {
      copyStarter(sourcePath, destinationPath);
      continue;
    }

    if (entry.isFile()) {
      fs.copyFileSync(sourcePath, destinationPath);
    }
  }
}

function readJson(filePath) {
  return JSON.parse(fs.readFileSync(filePath, 'utf8'));
}

function writeJson(filePath, data) {
  fs.mkdirSync(path.dirname(filePath), { recursive: true });
  fs.writeFileSync(filePath, `${JSON.stringify(data, null, 2)}\n`);
}

/**
 * Generates through the in-site WP-CLI command.
 */
function generateWithWpCli(themeRoot, scenario) {
  // The command reads the starter from <theme-root>/<parent>/whisk and writes
  // the child theme as a sibling of the parent theme.
  const parentDir = path.join(themeRoot, 'emulsify');
  copyStarter(starterRoot, path.join(parentDir, 'whisk'));
  fs.copyFileSync(
    path.join(repoRoot, 'style.css'),
    path.join(parentDir, 'style.css'),
  );

  const result = childProcess.spawnSync(
    phpBinary,
    [
      path.join(__dirname, 'generation-parity-harness.php'),
      themeRoot,
      scenario.displayName,
      scenario.machineName,
      scenario.description,
    ],
    { encoding: 'utf8' },
  );

  assert(
    result.status === 0,
    `WP-CLI generation failed for ${scenario.machineName}:\n${
      result.error ? result.error.message : result.stderr || result.stdout
    }`,
  );

  return path.join(themeRoot, scenario.machineName);
}

/**
 * Generates through the standalone starter init hook.
 */
function generateWithStarterInit(root, scenario) {
  const target = path.join(root, scenario.machineName);
  copyStarter(starterRoot, target);

  const project = readJson(path.join(target, 'project.emulsify.json'));
  project.project.name = scenario.displayName;
  project.project.machineName = scenario.machineName;
  project.project.description = scenario.description;
  writeJson(path.join(target, 'project.emulsify.json'), project);

  const result = childProcess.spawnSync(
    process.execPath,
    [path.join(target, '.cli/init.js')],
    { cwd: target, encoding: 'utf8' },
  );

  assert(
    result.status === 0,
    `Starter init generation failed for ${scenario.machineName}:\n${result.stderr || result.stdout}`,
  );

  return target;
}

function hashFile(filePath) {
  return crypto.createHash('sha256').update(fs.readFileSync(filePath)).digest('hex');
}

function collectComparableFiles(root, relative = '', collected = new Map()) {
  for (const entry of fs.readdirSync(path.join(root, relative), {
    withFileTypes: true,
  })) {
    const entryRelative = relative ? path.join(relative, entry.name) : entry.name;

    if (excludedCopySegments.has(entry.name) || comparisonExclusions.has(entry.name)) {
      continue;
    }

    if (entry.isDirectory()) {
      collectComparableFiles(root, entryRelative, collected);
      continue;
    }

    if (entry.isFile()) {
      collected.set(entryRelative, hashFile(path.join(root, entryRelative)));
    }
  }

  return collected;
}

function compareTrees(scenario, wpCliDir, starterDir) {
  const left = collectComparableFiles(wpCliDir);
  const right = collectComparableFiles(starterDir);
  const guidance = collectComparableFiles(starterRoot, '.agents');
  const differences = [];

  assert(guidance.size > 0, 'Whisk must ship a non-empty .agents guidance tree.');
  for (const [label, generated] of [['WP-CLI', left], ['starter init', right]]) {
    const guidancePaths = [...generated.keys()].filter((file) => file.startsWith(`.agents${path.sep}`));
    if (guidancePaths.length !== guidance.size) {
      differences.push(`${label} .agents tree does not contain exactly the source guidance files`);
    }
    const changed = [...guidance].filter(([file, hash]) => generated.get(file) !== hash);
    for (const [relativePath] of changed) {
      differences.push(`${label} ${relativePath} does not match the Whisk source bytes`);
    }
  }

  for (const [relativePath, hash] of left) {
    if (!right.has(relativePath)) {
      differences.push(`only the WP-CLI path produced ${relativePath}`);
      continue;
    }

    if (right.get(relativePath) !== hash) {
      differences.push(`${relativePath} differs between generation paths`);
    }
  }

  for (const relativePath of right.keys()) {
    if (!left.has(relativePath)) {
      differences.push(`only the starter init path produced ${relativePath}`);
    }
  }

  assert(
    differences.length === 0,
    `Generation paths diverged for ${scenario.machineName}:\n- ${differences.join('\n- ')}`,
  );

  console.log(
    `Both generated .agents trees match the Whisk source across ${guidance.size} file${guidance.size === 1 ? '' : 's'} for ${scenario.machineName}.`,
  );
  return left.size;
}

function validate(scenario, themeDir, pathLabel) {
  const result = validateGeneratedTheme({
    themeDir,
    machineName: scenario.machineName,
    displayName: scenario.displayName,
    description: scenario.description,
    sourceDir: starterRoot,
  });

  assert(
    result.errors.length === 0,
    `Contract failed for ${scenario.machineName} via ${pathLabel}:\n${result.format()}`,
  );
}

if (!hasPhp()) {
  // CI always has PHP. Skipping keeps the Node-only path usable locally instead
  // of reporting a false failure, but a skip must never be mistaken for a pass.
  const message =
    'Generation parity smoke skipped: no PHP binary available. Set EMULSIFY_PHP_BINARY or install PHP to compare both generation paths.';

  if (process.env.EMULSIFY_PARITY_REQUIRED === '1') {
    console.error(message.replace('skipped', 'failed'));
    process.exit(1);
  }

  console.warn(message);
  process.exit(0);
}

const workRoot = fs.mkdtempSync(path.join(os.tmpdir(), 'emulsify-parity-'));

try {
  for (const scenario of scenarios) {
    const wpCliRoot = fs.mkdtempSync(path.join(workRoot, 'wp-cli-'));
    const starterParent = fs.mkdtempSync(path.join(workRoot, 'starter-'));

    const wpCliTheme = generateWithWpCli(wpCliRoot, scenario);
    const starterTheme = generateWithStarterInit(starterParent, scenario);

    validate(scenario, wpCliTheme, 'the WP-CLI command');
    validate(scenario, starterTheme, 'the standalone starter');

    const compared = compareTrees(scenario, wpCliTheme, starterTheme);

    console.log(
      `Generation parity confirmed for ${scenario.machineName} across ${compared} files.`,
    );
  }

  console.log('Generation parity smoke checks passed.');
} catch (error) {
  console.error(error.message);
  process.exitCode = 1;
} finally {
  fs.rmSync(workRoot, { recursive: true, force: true });
}
