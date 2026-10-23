#!/usr/bin/env node

// Smoke checks for the standalone WordPress starter path used by emulsify-cli.
// The fixture mirrors the current CLI sequence: clone starter, write
// project.emulsify.json, npm install creates a lockfile, then .cli/init.js runs.

const childProcess = require('child_process');
const fs = require('fs');
const os = require('os');
const path = require('path');

const { validateGeneratedTheme } = require('./generated-theme-contract.cjs');

const repoRoot = path.resolve(__dirname, '../..');
const starterRoot = path.join(repoRoot, 'whisk');
// Root package.json is the single source of truth for the release version.
const expectedVersion = JSON.parse(
  fs.readFileSync(path.join(repoRoot, 'package.json'), 'utf8'),
).version;
const excludedCopySegments = new Set([
  '.coverage',
  '.git',
  '.out',
  '.cache',
  'dist',
  'node_modules',
]);

function assert(condition, message) {
  if (!condition) {
    throw new Error(message);
  }
}

function readJson(filePath) {
  return JSON.parse(fs.readFileSync(filePath, 'utf8'));
}

function writeJson(filePath, data) {
  fs.mkdirSync(path.dirname(filePath), { recursive: true });
  fs.writeFileSync(filePath, `${JSON.stringify(data, null, 2)}\n`);
}

function copyStarterFixture(source, destination) {
  fs.mkdirSync(destination, { recursive: true });

  for (const entry of fs.readdirSync(source, { withFileTypes: true })) {
    if (excludedCopySegments.has(entry.name)) {
      continue;
    }

    const sourcePath = path.join(source, entry.name);
    const destinationPath = path.join(destination, entry.name);

    if (entry.isDirectory()) {
      copyStarterFixture(sourcePath, destinationPath);
      continue;
    }

    if (entry.isFile()) {
      fs.mkdirSync(path.dirname(destinationPath), { recursive: true });
      fs.copyFileSync(sourcePath, destinationPath);
    }
  }
}

function removePath(filePath) {
  if (fs.existsSync(filePath)) {
    fs.rmSync(filePath, { force: true, recursive: true });
  }
}

function parseThemeHeader(contents) {
  return Object.fromEntries(
    contents
      .split(/\r?\n/)
      .map((line) => line.match(/^\s*\*\s*([^:]+):\s*(.*?)\s*$/))
      .filter(Boolean)
      .map((match) => [match[1].trim(), match[2].trim()]),
  );
}

function runNodeScript(scriptPath) {
  const result = childProcess.spawnSync(process.execPath, [scriptPath], {
    cwd: path.dirname(scriptPath),
    encoding: 'utf8',
    env: process.env,
  });

  if (result.status !== 0) {
    throw new Error(
      `${scriptPath} failed:\n${result.stdout || ''}${result.stderr || ''}`.trim(),
    );
  }
}

function assertEmptyGeneratedTestFails(projectDir) {
  const jestBin = path.join(
    starterRoot,
    'node_modules',
    '.bin',
    process.platform === 'win32' ? 'jest.cmd' : 'jest',
  );

  assert(
    fs.existsSync(jestBin),
    'Whisk dependencies are not installed. Run `npm run whisk:install` before the starter init smoke test.',
  );

  const npm = process.platform === 'win32' ? 'npm.cmd' : 'npm';
  fs.symlinkSync(path.join(starterRoot, 'node_modules'), path.join(projectDir, 'node_modules'), 'junction');
  const result = childProcess.spawnSync(npm, ['test', '--', '--runInBand'], {
    cwd: projectDir,
    encoding: 'utf8',
    env: { ...process.env, CI: '1' },
    timeout: 60000,
  });
  const output = `${result.stdout || ''}${result.stderr || ''}`;
  assert(!result.error, `Generated npm test could not run: ${result.error && result.error.message}`);
  assert(result.status !== 0 && /No tests found/.test(output), `An empty generated child theme must fail npm test because no tests exist:\n${output}`);
}

function runGeneratedComponentInspector(projectDir) {
  const binDirectory = path.join(starterRoot, 'node_modules', '.bin');
  const inspectorBin = path.join(
    binDirectory,
    process.platform === 'win32'
      ? 'emulsify-inspect-components.cmd'
      : 'emulsify-inspect-components',
  );

  assert(
    fs.existsSync(inspectorBin),
    'The Emulsify component inspector is not installed. Install Whisk dependencies with @emulsify/core 4.3.0 or newer.',
  );

  const npm = process.platform === 'win32' ? 'npm.cmd' : 'npm';
  const commandEnvironment = {
    ...process.env,
    PATH: `${binDirectory}${path.delimiter}${process.env.PATH || ''}`,
    npm_config_loglevel: 'silent',
  };
  const result = childProcess.spawnSync(
    npm,
    ['run', 'inspect:components', '--', '--json'],
    {
      cwd: projectDir,
      encoding: 'utf8',
      env: commandEnvironment,
    },
  );

  if (result.status !== 0) {
    throw new Error(
      `npm run inspect:components -- --json failed:\n${result.stdout || ''}${result.stderr || ''}`.trim(),
    );
  }

  let report;
  try {
    report = JSON.parse((result.stdout || '').trim());
  } catch (error) {
    throw new Error(
      `npm run inspect:components -- --json did not produce valid JSON: ${error.message}\n${result.stdout || ''}${result.stderr || ''}`.trim(),
    );
  }

  assert(
    report && typeof report === 'object' && !Array.isArray(report),
    'Component inspector JSON should be an object.',
  );
  assert(
    report.project &&
      typeof report.project === 'object' &&
      !Array.isArray(report.project),
    'Component inspector JSON should include project metadata.',
  );
  assert(
    report.project.machineName === null ||
      typeof report.project.machineName === 'string',
    'Component inspector project.machineName should be a string or null.',
  );
  assert(
    typeof report.project.platform === 'string',
    'Component inspector project.platform should be a string.',
  );
  assert(
    report.project.namespaceRoots &&
      typeof report.project.namespaceRoots === 'object' &&
      !Array.isArray(report.project.namespaceRoots),
    'Component inspector project.namespaceRoots should be an object.',
  );
  assert(
    typeof report.project.singleDirectoryComponents === 'boolean',
    'Component inspector project.singleDirectoryComponents should be a boolean.',
  );
  assert(
    Array.isArray(report.components),
    'Component inspector JSON should include a components array.',
  );

  for (const component of report.components) {
    assert(
      component && typeof component === 'object' && !Array.isArray(component),
      'Each inspected component should be an object.',
    );
    assert(
      typeof component.name === 'string',
      'Each inspected component should include a name.',
    );
    assert(
      Array.isArray(component.namespaces),
      'Each inspected component should include a namespaces array.',
    );
    assert(
      typeof component.source === 'string',
      'Each inspected component should include a source path.',
    );
  }

  return report;
}

const workRoot = fs.mkdtempSync(path.join(os.tmpdir(), 'emulsify-wordpress-starter-'));
const target = path.join(workRoot, 'acme-theme');
const hostileName = 'Acme */ echo 1; /* Theme';

try {
  copyStarterFixture(starterRoot, target);

  writeJson(path.join(target, 'project.emulsify.json'), {
    project: {
      platform: 'wordpress',
      name: hostileName,
      machineName: 'acme-theme',
    },
    starter: {
      repository: 'https://github.com/emulsify-ds/emulsify-wordpress-starter',
    },
  });

  writeJson(path.join(target, 'patterns/smoke-pattern.json'), {
    name: 'whisk/smoke-pattern',
    title: 'Smoke Pattern',
    categories: ['text'],
    content: '<!-- wp:paragraph --><p>Smoke pattern content</p><!-- /wp:paragraph -->',
  });

  const lockfileRootPackage = {
    name: 'whisk',
    version: '2.0.0',
    dependencies: { '@emulsify/core': '^4.3.0' },
  };
  writeJson(path.join(target, 'package-lock.json'), {
    name: 'whisk',
    version: '2.0.0',
    lockfileVersion: 3,
    requires: true,
    packages: {
      '': lockfileRootPackage,
    },
  });

  assert(
    fs.existsSync(path.join(target, '.cli/init.js')),
    'Starter should include the emulsify-cli init hook before generation.',
  );

  runNodeScript(path.join(target, '.cli/init.js'));

  const style = parseThemeHeader(fs.readFileSync(path.join(target, 'style.css'), 'utf8'));
  const packageJson = readJson(path.join(target, 'package.json'));
  const packageLock = readJson(path.join(target, 'package-lock.json'));
  const project = readJson(path.join(target, 'project.emulsify.json'));
  const pattern = readJson(path.join(target, 'patterns/smoke-pattern.json'));
  const functionsPhp = fs.readFileSync(path.join(target, 'functions.php'), 'utf8');

  assert(style['Theme Name'] === 'Acme echo 1 Theme', 'style.css should use a source-safe Theme Name.');
  assert(!style['Theme Name'].includes('*/'), 'style.css should not contain a crafted comment terminator.');
  assert(style['Text Domain'] === 'acme-theme', 'style.css should update Text Domain.');
  assert(style.Template === 'emulsify', 'style.css should keep Template: emulsify.');
  assert(packageJson.name === 'acme-theme', 'package.json should update name.');
  assert(packageLock.name === 'acme-theme', 'package-lock.json should update the root name.');
  assert(packageLock.packages[''].name === 'acme-theme', 'package-lock.json packages[""].name should update.');
  assert(project.project.platform === 'wordpress', 'project.emulsify.json should keep project.platform: wordpress.');
  assert(project.project.name === hostileName, 'project.emulsify.json should preserve the richer project.name.');
  assert(project.project.machineName === 'acme-theme', 'project.emulsify.json should update project.machineName.');
  assert(project.project.generatedFrom === 'emulsify-wordpress', 'project.emulsify.json should identify the generated child theme source.');
  assert(project.project.generatedFromVersion === expectedVersion, 'project.emulsify.json should record the generated child theme source version.');
  assert(
    project.starter.repository === 'https://github.com/emulsify-ds/emulsify-wordpress-starter',
    'project.emulsify.json should keep the standalone starter repository.',
  );
  assert(functionsPhp.includes('Acme echo 1 Theme child theme hooks.'), 'functions.php should use a source-safe visible label.');
  assert(!functionsPhp.includes('*/ echo 1; /*'), 'functions.php should not contain the attempted docblock breakout.');
  assert(!fs.existsSync(path.join(target, 'templates')), 'Fresh generated child themes should inherit parent templates without a local templates directory.');
  assert(pattern.name === 'acme-theme/smoke-pattern', 'JSON pattern names should update the whisk namespace.');
  assert(
    !fs.existsSync(path.join(target, '.cli')),
    'Generation-only tooling should be removed from the generated child theme.',
  );
  assert(fs.existsSync(path.join(target, '.gitignore')), 'Starter should include standalone ignore rules.');
  assert(fs.existsSync(path.join(target, '.nvmrc')), 'Starter should use .nvmrc for Node tooling.');
  assert(!fs.existsSync(path.join(target, '.nvm')), 'Starter should not keep the legacy .nvm file.');

  for (const generatedPath of ['node_modules', 'dist', '.out', '.coverage', '.cache', '.git']) {
    assert(
      !fs.existsSync(path.join(target, generatedPath)),
      `Cloned starter fixture should not contain copied ${generatedPath} output.`,
    );
  }

  const readme = fs.readFileSync(path.join(target, 'README.md'), 'utf8');
  assert(
    !/%%EMULSIFY_[A-Z_]+%%/.test(readme),
    'README.md should not keep unreplaced documentation tokens.',
  );
  assert(readme.includes('acme-theme'), 'README.md should record the generated machine name.');
  assert(
    readme.includes('emulsify-wordpress') && readme.includes(expectedVersion),
    'README.md should record the generated source lineage.',
  );
  for (const generatedDoc of [
    'docs/development.md',
    'docs/support-information.md',
    'docs/upgrading.md',
  ]) {
    const contents = fs.readFileSync(path.join(target, generatedDoc), 'utf8');
    assert(
      !/%%EMULSIFY_[A-Z_]+%%/.test(contents),
      `${generatedDoc} should not keep unreplaced documentation tokens.`,
    );
  }

  const contract = validateGeneratedTheme({
    themeDir: target,
    machineName: 'acme-theme',
    displayName: hostileName,
    description: parseThemeHeader(
      fs.readFileSync(path.join(starterRoot, 'style.css'), 'utf8'),
    ).Description,
    sourceDir: starterRoot,
  });
  assert(
    contract.errors.length === 0,
    `Generated child theme contract failed:\n${contract.format()}`,
  );

  const inspectorReport = runGeneratedComponentInspector(target);
  assert(
    inspectorReport.project.machineName === project.project.machineName,
    'Component inspector should read project metadata from the generated theme root.',
  );
  if (inspectorReport.components.length === 0) {
    assert(
      Object.hasOwn(inspectorReport, 'project') && Object.hasOwn(inspectorReport, 'components'),
      'An empty component inspection should retain the project metadata and components array.',
    );
  }

  assertEmptyGeneratedTestFails(target);

  console.log('WordPress starter init smoke checks passed.');
} catch (error) {
  console.error(error.message);
  process.exitCode = 1;
} finally {
  removePath(workRoot);
}
