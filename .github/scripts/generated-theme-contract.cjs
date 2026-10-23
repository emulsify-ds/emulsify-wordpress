#!/usr/bin/env node

/**
 * Validates a generated Emulsify WordPress child theme against the generation
 * contract documented in docs/generated-child-theme-contract.md.
 *
 * Usage:
 *   generated-theme-contract.cjs [--check-built-assets] \
 *     <theme-dir> <machine-name> <display-name> <description> <whisk-source-dir>
 *
 * The validator is deliberately independent of the generator implementations so
 * the WP-CLI path and the standalone starter path are held to the same contract.
 */

const fs = require('fs');
const path = require('path');
const { isDeepStrictEqual } = require('util');
const yaml = require('js-yaml');

const { validateDocumentation } = require('./docs-command-check.cjs');

const SECTION_LABELS = {
  generation: 'generation',
  wordpress: 'WordPress metadata',
  frontend: 'frontend metadata',
  references: 'file references',
  documentation: 'documentation',
  guidance: 'AI guidance',
  placeholders: 'placeholder replacement',
  build: 'build',
};

const REQUIRED_DOCUMENTED_SCRIPTS = [
  'a11y',
  'build',
  'develop',
  'inspect:components',
  'lint',
  'storybook',
  'storybook-build',
  'test',
];

const REQUIRED_GENERATED_FILES = [
  'style.css',
  'functions.php',
  'package.json',
  'project.emulsify.json',
  'README.md',
  'docs/development.md',
  'docs/support-information.md',
  'docs/upgrading.md',
];

const REQUIRED_GUIDANCE_FILES = [
  'AGENTS.md',
  'docs/wordpress-integration.md',
  '.agents/skills/emulsify-wordpress-integration/SKILL.md',
];

// Generation-only tooling and build output must never reach a project repo.
const FORBIDDEN_GENERATED_PATHS = ['.cli', 'node_modules', 'dist', '.out', '.coverage'];

const IGNORED_SCAN_DIRECTORIES = new Set([
  'node_modules',
  'dist',
  '.out',
  '.coverage',
  '.git',
]);

const DOCUMENTATION_TOKEN_PATTERN = /%%EMULSIFY_[A-Z_]+%%/;

function normalizeWhitespace(value) {
  return String(value || '').replace(/\s+/g, ' ').trim();
}

function escapeRegExp(value) {
  return value.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
}

function expected(value) {
  return JSON.stringify(value);
}

function readTextFile(filePath) {
  return fs.readFileSync(filePath, 'utf8');
}

function readJsonFile(filePath) {
  return JSON.parse(readTextFile(filePath));
}

function readThemeHeaders(filePath) {
  const headers = {};

  for (const line of readTextFile(filePath).split(/\r?\n/)) {
    const match = line.match(/^\s*\*?\s*([A-Za-z][A-Za-z ]*?):\s*(.+?)\s*$/);
    if (match) {
      headers[match[1].trim()] = match[2].trim();
    }
  }

  return headers;
}

function listFilesRecursive(root, relative = '') {
  const absolute = path.join(root, relative);
  const results = [];

  for (const entry of fs.readdirSync(absolute, { withFileTypes: true })) {
    const entryRelative = relative ? path.join(relative, entry.name) : entry.name;

    if (entry.isSymbolicLink()) {
      results.push({ relativePath: entryRelative, symlink: true });
      continue;
    }

    if (entry.isDirectory()) {
      if (IGNORED_SCAN_DIRECTORIES.has(entry.name)) {
        results.push({ relativePath: entryRelative, directory: true, ignored: true });
        continue;
      }
      results.push({ relativePath: entryRelative, directory: true });
      results.push(...listFilesRecursive(root, entryRelative));
      continue;
    }

    results.push({ relativePath: entryRelative, file: true });
  }

  return results;
}

function isBinary(buffer) {
  return buffer.includes(0);
}

function createValidator() {
  const sections = new Map(
    Object.keys(SECTION_LABELS).map((key) => [key, []]),
  );
  const skipped = new Set();

  return {
    addError(section, message) {
      sections.get(section).push(message);
    },
    skip(section) {
      skipped.add(section);
    },
    get errors() {
      return [...sections.values()].flat();
    },
    format() {
      const lines = [];

      for (const [key, label] of Object.entries(SECTION_LABELS)) {
        if (skipped.has(key)) {
          lines.push(`SKIP ${label}`);
          continue;
        }

        const messages = sections.get(key);
        if (messages.length === 0) {
          lines.push(`PASS ${label}`);
          continue;
        }

        lines.push(`FAIL ${label}`);
        for (const message of messages) {
          lines.push(`  ${message}`);
        }
      }

      return lines.join('\n');
    },
  };
}

function checkEqual(addError, section, themeLabel, file, key, actual, want) {
  if (actual !== want) {
    addError(
      section,
      `${themeLabel} has ${key} ${expected(actual)} in ${expected(file)}; expected ${expected(want)}.`,
    );
  }
}

function validateGenerationShape(validator, contract) {
  const { themeDir, themeLabel, sourceDir, checkBuiltAssets } = contract;
  const entries = listFilesRecursive(themeDir);
  const relativeFiles = new Set(
    entries.filter((entry) => entry.file).map((entry) => entry.relativePath.split(path.sep).join('/')),
  );

  for (const requiredFile of REQUIRED_GENERATED_FILES) {
    if (
      fs.existsSync(path.join(sourceDir, requiredFile)) &&
      !relativeFiles.has(requiredFile)
    ) {
      validator.addError(
        'generation',
        `${themeLabel} is missing required generated file ${expected(requiredFile)}.`,
      );
    }
  }

  for (const entry of entries) {
    if (entry.symlink) {
      validator.addError(
        'generation',
        `${themeLabel} contains symbolic link ${expected(entry.relativePath)}; generated files must be self-contained.`,
      );
    }
  }

  for (const forbidden of FORBIDDEN_GENERATED_PATHS) {
    if (checkBuiltAssets && forbidden !== '.cli') {
      continue;
    }
    if (fs.existsSync(path.join(themeDir, forbidden))) {
      validator.addError(
        'generation',
        `${themeLabel} retained generation-only or build path ${expected(forbidden)}; expected it to be omitted.`,
      );
    }
  }

  return { entries, relativeFiles };
}

function validateWordPressMetadata(validator, contract) {
  const { themeDir, themeLabel, machineName, sourceLabel, description, parent, sourceDir } =
    contract;
  const stylePath = path.join(themeDir, 'style.css');

  if (!fs.existsSync(stylePath)) {
    validator.addError('wordpress', `${themeLabel} is missing "style.css".`);
    return;
  }

  const headers = readThemeHeaders(stylePath);
  const sourceHeaders = readThemeHeaders(path.join(sourceDir, 'style.css'));

  checkEqual(
    validator.addError,
    'wordpress',
    themeLabel,
    'style.css',
    'Theme Name',
    headers['Theme Name'],
    sourceLabel,
  );
  checkEqual(
    validator.addError,
    'wordpress',
    themeLabel,
    'style.css',
    'Text Domain',
    headers['Text Domain'],
    machineName,
  );
  checkEqual(
    validator.addError,
    'wordpress',
    themeLabel,
    'style.css',
    'Template',
    headers.Template,
    parent,
  );
  checkEqual(
    validator.addError,
    'wordpress',
    themeLabel,
    'style.css',
    'Description',
    headers.Description,
    description,
  );
  checkEqual(
    validator.addError,
    'wordpress',
    themeLabel,
    'style.css',
    'License',
    headers.License,
    sourceHeaders.License,
  );

  if (!headers.Version) {
    validator.addError(
      'wordpress',
      `${themeLabel} is missing a "style.css" Version header.`,
    );
  }

  const functionsPath = path.join(themeDir, 'functions.php');
  if (fs.existsSync(functionsPath)) {
    const functions = readTextFile(functionsPath);
    if (!functions.includes(`${sourceLabel} child theme hooks.`)) {
      validator.addError(
        'wordpress',
        `${themeLabel} "functions.php" should describe the generated theme with ${expected(`${sourceLabel} child theme hooks.`)}.`,
      );
    }
  }
}

function validateFrontendMetadata(validator, contract) {
  const { themeDir, themeLabel, machineName, description, sourceProject, sourcePackage } =
    contract;
  const packagePath = path.join(themeDir, 'package.json');

  if (!fs.existsSync(packagePath)) {
    validator.addError('frontend', `${themeLabel} is missing "package.json".`);
  } else {
    const parsed = readJsonFile(packagePath);

    checkEqual(
      validator.addError,
      'frontend',
      themeLabel,
      'package.json',
      'name',
      parsed.name,
      machineName,
    );
    checkEqual(
      validator.addError,
      'frontend',
      themeLabel,
      'package.json',
      'description',
      normalizeWhitespace(parsed.description),
      description,
    );
    checkEqual(
      validator.addError,
      'frontend',
      themeLabel,
      'package.json',
      'license',
      parsed.license,
      sourcePackage.license,
    );
    checkEqual(
      validator.addError,
      'frontend',
      themeLabel,
      'package.json',
      'dependencies["@emulsify/core"]',
      parsed.dependencies?.['@emulsify/core'],
      sourcePackage.dependencies?.['@emulsify/core'],
    );

    for (const script of REQUIRED_DOCUMENTED_SCRIPTS) {
      const value = parsed.scripts?.[script];
      if (typeof value !== 'string' || value.trim() === '') {
        validator.addError(
          'frontend',
          `${themeLabel} "package.json" is missing a non-empty ${expected(script)} script.`,
        );
      }
    }

    validateScriptReferences(validator, contract, parsed.scripts || {});
  }

  const projectPath = path.join(themeDir, 'project.emulsify.json');

  if (!fs.existsSync(projectPath)) {
    validator.addError(
      'frontend',
      `${themeLabel} is missing "project.emulsify.json".`,
    );
    return;
  }

  const parsedProject = readJsonFile(projectPath);

  if (!parsedProject.project || typeof parsedProject.project !== 'object') {
    validator.addError(
      'frontend',
      `${themeLabel} requires a project object in "project.emulsify.json"; found ${expected(parsedProject.project)}.`,
    );
    return;
  }

  const project = parsedProject.project;

  for (const [key, value] of [
    ['platform', sourceProject.platform],
    ['machineName', machineName],
    ['generatedFrom', sourceProject.generatedFrom],
    ['generatedFromVersion', sourceProject.generatedFromVersion],
    ['description', description],
  ]) {
    checkEqual(
      validator.addError,
      'frontend',
      themeLabel,
      'project.emulsify.json',
      `project.${key}`,
      key === 'description' ? normalizeWhitespace(project[key]) : project[key],
      value,
    );
  }

  if (typeof project.name !== 'string' || project.name.trim() === '') {
    validator.addError(
      'frontend',
      `${themeLabel} requires a non-empty project.name in "project.emulsify.json"; found ${expected(project.name)}.`,
    );
  }

  if (!isDeepStrictEqual(parsedProject.starter, contract.sourceProjectFile.starter)) {
    validator.addError(
      'frontend',
      `${themeLabel} has inconsistent generated-source repository metadata in "project.emulsify.json"; expected ${expected(contract.sourceProjectFile.starter)}, found ${expected(parsedProject.starter)}.`,
    );
  }
}

function validateScriptReferences(validator, contract, scripts) {
  const { themeDir, themeLabel } = contract;

  for (const [name, command] of Object.entries(scripts)) {
    if (typeof command !== 'string') {
      continue;
    }

    const references = [
      ...command.matchAll(/--(?:config|ignore-path)[= ]([^\s'"]+)/g),
      ...command.matchAll(/(?:^|\s)(config\/[^\s'"]+)/g),
    ].map((match) => match[1]);

    for (const reference of references) {
      if (reference.startsWith('node_modules/')) {
        continue;
      }

      const resolved = path.resolve(themeDir, reference);

      if (!resolved.startsWith(path.resolve(themeDir))) {
        validator.addError(
          'references',
          `${themeLabel} script ${expected(name)} references ${expected(reference)} outside the generated child theme.`,
        );
        continue;
      }

      if (!fs.existsSync(resolved)) {
        validator.addError(
          'references',
          `${themeLabel} script ${expected(name)} references missing local path ${expected(reference)}.`,
        );
      }
    }
  }
}

function validateMarkdownReferences(validator, contract, entries) {
  const { themeDir, themeLabel } = contract;
  const themeRoot = path.resolve(themeDir);
  const realThemeRoot = fs.realpathSync(themeRoot);

  for (const entry of entries) {
    if (!entry.file || !entry.relativePath.endsWith('.md')) {
      continue;
    }

    const contents = readTextFile(path.join(themeDir, entry.relativePath));
    const relativePath = entry.relativePath.split(path.sep).join('/');
    const section = REQUIRED_GUIDANCE_FILES.includes(relativePath) || relativePath.startsWith('.agents/skills/') ? 'guidance' : 'references';

    for (const match of contents.matchAll(/\[[^\]]*\]\(([^)]+)\)/g)) {
      const target = match[1].replace(/^<|>$/g, '').split('#')[0].trim();

      if (target === '' || /^(?:[a-z][a-z0-9+.-]*:|\/\/|#)/i.test(target)) {
        continue;
      }

      const resolved = path.resolve(
        path.dirname(path.join(themeDir, entry.relativePath)),
        target,
      );

      if (resolved !== themeRoot && !resolved.startsWith(`${themeRoot}${path.sep}`)) {
	validator.addError(section,
          `${themeLabel} has documentation link ${expected(target)} in ${expected(entry.relativePath)} that resolves outside the generated child theme; expected a self-contained path or published package.`,
        );
        continue;
      }

      if (!fs.existsSync(resolved)) {
	validator.addError(section,
          `${themeLabel} has documentation link ${expected(target)} in ${expected(entry.relativePath)} that resolves to missing local path ${expected(path.relative(themeDir, resolved))}; expected it inside the generated child theme.`,
        );
        continue;
      }

      const realTarget = fs.realpathSync(resolved);
      if (realTarget !== realThemeRoot && !realTarget.startsWith(`${realThemeRoot}${path.sep}`)) {
        validator.addError(
          section,
          `${themeLabel} has documentation link ${expected(target)} in ${expected(entry.relativePath)} that resolves outside the generated child theme through a symbolic link; expected it inside the generated child theme.`,
        );
      }
    }
  }
}

function validateGeneratedGuidance(validator, contract, entries, relativeFiles) {
  const { themeDir, themeLabel, sourceDir, checkBuiltAssets } = contract;
  for (const relativePath of REQUIRED_GUIDANCE_FILES) {
    if (!relativeFiles.has(relativePath)) {
      validator.addError('guidance', `${themeLabel} is missing required generated guidance file ${expected(relativePath)}.`);
      continue;
    }
    const source = path.join(sourceDir, relativePath);
    if (!checkBuiltAssets && (!fs.existsSync(source) || !fs.readFileSync(path.join(themeDir, relativePath)).equals(fs.readFileSync(source)))) {
      validator.addError('guidance', `${themeLabel} changed guidance file ${expected(relativePath)} during generation; expected a byte-for-byte copy of the starter guidance.`);
    }
  }

  for (const entry of entries.filter((file) => file.file && /^\.agents[\\/]skills[\\/][^\\/]+[\\/]SKILL\.md$/.test(file.relativePath))) {
    try {
      const frontmatter = readTextFile(path.join(themeDir, entry.relativePath)).match(/^---\r?\n([\s\S]*?)\r?\n---(?:\r?\n|$)/);
      if (!frontmatter) throw new Error('expected YAML frontmatter bounded by ---');
      const metadata = yaml.load(frontmatter[1]);
      if (!metadata || typeof metadata !== 'object' || Array.isArray(metadata) || metadata.name !== path.basename(path.dirname(entry.relativePath))) throw new Error('frontmatter name must match the skill directory');
      if (typeof metadata.description !== 'string' || !metadata.description.trim()) throw new Error('frontmatter description must be a nonempty string');
    } catch (error) {
      validator.addError('guidance', `${themeLabel} has invalid skill manifest in ${expected(entry.relativePath)}: ${error.message}.`);
    }
  }
}

function validateGeneratedDocumentation(validator, contract) {
  const { themeDir, themeLabel, machineName, displayName, description } = contract;

  let result;

  try {
    result = validateDocumentation({ generatedTheme: themeDir });
  } catch (error) {
    validator.addError('documentation', `${themeLabel} ${error.message}`);
    return;
  }

  for (const error of result.errors) {
    const section = REQUIRED_GUIDANCE_FILES.some((file) => error.replaceAll('\\', '/').includes(`${file}:`)) ? 'guidance' : 'documentation';
    validator.addError(section, `${themeLabel} ${error}`);
  }

  const readmePath = path.join(themeDir, 'README.md');

  if (!fs.existsSync(readmePath)) {
    return;
  }

  const readme = readTextFile(readmePath);
  const project = readJsonFile(path.join(themeDir, 'project.emulsify.json')).project;
  const coreRange = readJsonFile(path.join(themeDir, 'package.json'))?.dependencies?.[
    '@emulsify/core'
  ];

  const expectedValues = [
    ['display name', normalizeWhitespace(displayName)],
    ['machine name', normalizeWhitespace(machineName)],
    ['description', description],
    ['generated source project', project?.generatedFrom],
    ['generated source version', project?.generatedFromVersion],
    ['Emulsify Core range', coreRange],
  ];

  for (const [label, value] of expectedValues) {
    if (!value) {
      continue;
    }

    if (!readme.includes(value)) {
      validator.addError(
        'documentation',
        `${themeLabel} README.md is missing its ${label} ${expected(value)}; expected project-specific generation metadata.`,
      );
    }
  }
}

function validatePlaceholders(validator, contract, entries) {
  const {
    themeDir,
    themeLabel,
    machineName,
    displayName,
    sourceLabel,
    description,
    sourceMachineName,
    sourceDisplayName,
    sourceDescription,
  } = contract;

  const staleValues = [
    {
      category: 'starter display name',
      value: sourceDisplayName,
      replacement: displayName,
    },
    {
      category: 'placeholder description',
      value: sourceDescription,
      replacement: 'the requested description',
      skip: sourceDescription === description,
    },
    {
      category: 'documentation token',
      value: '%%EMULSIFY_*%%',
      pattern: DOCUMENTATION_TOKEN_PATTERN,
      replacement: 'project-specific generated documentation',
    },
    {
      category: 'legacy placeholder',
      value: 'EMULSIFY_NAME',
      replacement: displayName,
    },
    {
      category: 'retired frontend tooling',
      value: 'Webpack',
      pattern: /\bwebpack\b/i,
      replacement: 'the Vite build workflow',
      markdownOnly: true,
    },
    {
      category: 'retired theme terminology',
      value: 'subtheme',
      pattern: /\bsub[ -]?theme\b/i,
      replacement: 'child theme',
      markdownOnly: true,
    },
  ].filter((stale) => !stale.skip && stale.value);

  const machineNamePattern = new RegExp(escapeRegExp(sourceMachineName), 'i');
  const knownValues = [machineName, displayName, sourceLabel, description].filter(
    Boolean,
  );

  for (const entry of entries) {
    if (!entry.file) {
      continue;
    }

    const absolute = path.join(themeDir, entry.relativePath);
    const buffer = fs.readFileSync(absolute);

    if (isBinary(buffer)) {
      continue;
    }

    const isMarkdown = entry.relativePath.endsWith('.md');
    const lines = buffer.toString('utf8').split(/\r?\n/);

    for (let index = 0; index < lines.length; index += 1) {
      const line = lines[index];
      const location = `${entry.relativePath}:${index + 1}`;

      for (const stale of staleValues) {
        if (stale.markdownOnly && !isMarkdown) {
          continue;
        }

        const hit = stale.pattern
          ? stale.pattern.test(line)
          : line.includes(stale.value);

        if (hit) {
          validator.addError(
            'placeholders',
            `${themeLabel} contains stale ${stale.category} ${expected(stale.value)} in ${expected(location)}; expected ${expected(stale.replacement)}.`,
          );
        }
      }

      // Strip the project's own identity before looking for the starter slug so
      // a project legitimately named after the starter cannot trip the check.
      let residual = line;
      for (const value of knownValues) {
        residual = residual.split(value).join('');
      }

      if (machineNamePattern.test(residual)) {
        validator.addError(
          'placeholders',
          `${themeLabel} contains stale starter machine name ${expected(sourceMachineName)} in ${expected(location)}; expected ${expected(machineName)}.`,
        );
      }
    }
  }
}

function validateBuiltAssets(validator, contract) {
  const { themeDir, themeLabel } = contract;
  const distDir = path.join(themeDir, 'dist');

  if (!fs.existsSync(distDir)) {
    validator.addError(
      'build',
      `${themeLabel} is missing "dist" after a build; the parent theme discovers built output there.`,
    );
  }
}

function validateGeneratedTheme(options) {
  const {
    themeDir,
    machineName,
    displayName,
    description,
    sourceDir,
    checkBuiltAssets = false,
  } = options;

  const validator = createValidator();
  validator.addError = validator.addError.bind(validator);

  if (!fs.existsSync(themeDir) || !fs.statSync(themeDir).isDirectory()) {
    validator.addError(
      'generation',
      `Generated theme directory ${expected(themeDir)} does not exist.`,
    );
    for (const key of Object.keys(SECTION_LABELS)) {
      if (key !== 'generation') {
        validator.skip(key);
      }
    }
    return validator;
  }

  const sourcePackage = readJsonFile(path.join(sourceDir, 'package.json'));
  const sourceProjectFile = readJsonFile(path.join(sourceDir, 'project.emulsify.json'));
  const sourceHeaders = readThemeHeaders(path.join(sourceDir, 'style.css'));

  const contract = {
    themeDir,
    themeLabel: `Generated theme ${expected(machineName)}`,
    machineName,
    displayName: normalizeWhitespace(displayName),
    sourceLabel: normalizeWhitespace(displayName).replace(/[^\p{L}\p{N} -]+/gu, '').replace(/ +/g, ' ').trim(),
    description: normalizeWhitespace(description),
    parent: 'emulsify',
    sourceDir,
    checkBuiltAssets,
    sourcePackage,
    sourceProjectFile,
    sourceProject: sourceProjectFile.project,
    sourceMachineName: sourceProjectFile.project.machineName,
    sourceDisplayName: sourceHeaders['Theme Name'],
    sourceDescription: normalizeWhitespace(sourceHeaders.Description),
  };

  const { entries, relativeFiles } = validateGenerationShape(validator, contract);
  validateWordPressMetadata(validator, contract);
  validateFrontendMetadata(validator, contract);
  validateGeneratedGuidance(validator, contract, entries, relativeFiles);
  validateMarkdownReferences(validator, contract, entries);
  validateGeneratedDocumentation(validator, contract);
  validatePlaceholders(validator, contract, entries);

  if (checkBuiltAssets) {
    validateBuiltAssets(validator, contract);
  } else {
    validator.skip('build');
  }

  return validator;
}

if (require.main === module) {
  const argv = process.argv.slice(2);
  const checkBuiltAssets = argv.includes('--check-built-assets');
  const positional = argv.filter((value) => !value.startsWith('--'));

  if (positional.length !== 5) {
    console.error(
      'Usage: generated-theme-contract.cjs [--check-built-assets] <theme-dir> <machine-name> <display-name> <description> <whisk-source-dir>',
    );
    process.exit(2);
  }

  const [themeDir, machineName, displayName, description, sourceDir] = positional;
  const validator = validateGeneratedTheme({
    themeDir: path.resolve(themeDir),
    machineName,
    displayName,
    description,
    sourceDir: path.resolve(sourceDir),
    checkBuiltAssets,
  });

  console.log(validator.format());

  if (validator.errors.length > 0) {
    process.exit(1);
  }
}

module.exports = { validateGeneratedTheme, normalizeWhitespace };
