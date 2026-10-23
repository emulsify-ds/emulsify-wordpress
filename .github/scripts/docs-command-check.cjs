#!/usr/bin/env node

const fs = require('fs');
const path = require('path');

const repoRoot = path.resolve(__dirname, '../..');

// Documentation that describes the repository itself. Each entry is scoped to a
// heading so unrelated prose in the same file cannot satisfy the check.
const ROOT_CHECKS = [
  {
    relativePath: 'README.md',
    heading: 'Working inside a generated child theme',
    packagePath: 'whisk/package.json',
    packageLabel: 'generated child themes',
    includeInlineCode: true,
    expectedScripts: [
      'a11y',
      'audit',
      'audit:twig-stories',
      'build',
      'develop',
      'inspect:components',
      'lint',
      'storybook',
      'storybook-build',
      'test',
      'vite',
    ],
  },
  {
    relativePath: 'docs/core-4-vite-workflow.md',
    heading: 'Core 4, Vite, and Storybook commands',
    packagePath: 'whisk/package.json',
    packageLabel: 'generated child themes',
    includeInlineCode: true,
    expectedScripts: [
      'a11y',
      'audit',
      'audit:twig-stories',
      'build',
      'develop',
      'inspect:components',
      'lint',
      'storybook',
      'storybook-build',
      'test',
      'vite',
    ],
  },
  {
    relativePath: 'UPGRADE.md',
    heading: 'Component inspector',
    packagePath: 'whisk/package.json',
    packageLabel: 'existing generated child themes',
    includeInlineCode: true,
    expectedScripts: ['inspect:components'],
  },
  {
    relativePath: 'UPGRADE.md',
    heading: 'Project audit',
    packagePath: 'whisk/package.json',
    packageLabel: 'existing generated child themes',
    includeInlineCode: true,
    expectedScripts: ['audit', 'audit:twig-stories'],
  },
  {
    relativePath: 'docs/upgrading-1x-to-2x.md',
    heading: 'Validation',
    packagePath: 'package.json',
    packageLabel: 'the root project',
    expectedScripts: ['pr:check', 'release:check'],
  },
  {
    relativePath: 'docs/release-process.md',
    heading: 'Local checks',
    packagePath: 'package.json',
    packageLabel: 'the root project',
    expectedScripts: [
      'docs:check-commands',
      'lint:php',
      'pr:check',
      'publish-test',
      'release:check',
      'test:generated-theme',
    ],
  },
  {
    relativePath: 'docs/generated-child-theme-contract.md',
    heading: 'Run the checks',
    packagePath: 'package.json',
    packageLabel: 'the root project',
    expectedScripts: ['test:generated-theme', 'release:check'],
  },
];

// Documentation that ships inside a generated child theme. These run twice: once
// against the Whisk source and once against real generated output, so a command
// cannot drift between the template and the theme a project actually receives.
const THEME_DOC_CHECKS = [
  ...[
    'AGENTS.md',
    'docs/wordpress-integration.md',
    '.agents/skills/emulsify-wordpress-integration/SKILL.md',
  ].map((relativePath) => ({
    relativePath,
    packagePath: 'package.json',
    packageLabel: 'the generated child theme',
    includeInlineCode: true,
  })),
  {
    relativePath: 'README.md',
    packagePath: 'package.json',
    packageLabel: 'the generated child theme',
    includeInlineCode: true,
    requireNpmInstall: true,
  },
  {
    relativePath: 'docs/development.md',
    packagePath: 'package.json',
    packageLabel: 'the generated child theme',
    includeInlineCode: true,
    requireNpmInstall: true,
    expectedScripts: [
      'a11y',
      'build',
      'develop',
      'inspect:components',
      'lint',
      'storybook',
      'storybook-build',
      'test',
    ],
  },
  {
    relativePath: 'docs/upgrading.md',
    packagePath: 'package.json',
    packageLabel: 'the generated child theme',
    includeInlineCode: true,
    expectedScripts: ['inspect:components'],
  },
  {
    relativePath: 'docs/support-information.md',
    packagePath: 'package.json',
    packageLabel: 'the generated child theme',
    includeInlineCode: true,
  },
];

function prefixThemeScope(scope) {
  return {
    ...scope,
    relativePath: path.join('whisk', scope.relativePath),
    packagePath: path.join('whisk', scope.packagePath),
  };
}

function readFile(root, relativePath) {
  return fs.readFileSync(path.join(root, relativePath), 'utf8');
}

function readJson(root, relativePath) {
  return JSON.parse(readFile(root, relativePath));
}

function normalizeHeadingText(text) {
  return text.replace(/\s+#+\s*$/, '').trim();
}

function extractMarkdownSection(root, relativePath, heading) {
  const contents = readFile(root, relativePath);

  if (!heading) {
    return { text: contents, startLine: 1 };
  }

  const lines = contents.split(/\r?\n/);

  for (let index = 0; index < lines.length; index += 1) {
    const match = lines[index].match(/^(#{1,6})\s+(.+?)\s*$/);
    if (!match || normalizeHeadingText(match[2]) !== heading) {
      continue;
    }

    const level = match[1].length;
    const bodyStart = index + 1;
    let bodyEnd = lines.length;
    for (let nextIndex = bodyStart; nextIndex < lines.length; nextIndex += 1) {
      const nextMatch = lines[nextIndex].match(/^(#{1,6})\s+/);
      if (nextMatch && nextMatch[1].length <= level) {
        bodyEnd = nextIndex;
        break;
      }
    }

    return {
      text: lines.slice(bodyStart, bodyEnd).join('\n'),
      startLine: bodyStart + 1,
    };
  }

  throw new Error(
    `${relativePath}:1 is missing the "${heading}" documentation section.`,
  );
}

function extractShellFenceCommands(section) {
  const commands = [];
  const lines = section.text.split(/\r?\n/);
  let shellFence = null;

  for (let index = 0; index < lines.length; index += 1) {
    const line = lines[index];
    const fenceMatch = line.match(/^```([A-Za-z0-9_-]*)\s*$/);

    if (!shellFence && fenceMatch) {
      const language = fenceMatch[1].toLowerCase();
      shellFence = {
        collect: ['bash', 'sh', 'shell'].includes(language),
        lines: [],
        startLine: section.startLine + index + 1,
      };
      continue;
    }

    if (shellFence && /^```\s*$/.test(line)) {
      if (shellFence.collect) {
        commands.push(
          ...extractNpmRunCommands(
            shellFence.lines.join('\n'),
            shellFence.startLine,
          ),
        );
      }
      shellFence = null;
      continue;
    }

    if (shellFence && shellFence.collect) {
      shellFence.lines.push(line);
    }
  }

  return commands;
}

function extractInlineCommands(section) {
  const commands = [];
  for (const match of section.text.matchAll(
    /`([^`\n]*\bnpm\s+run\s+[^`]*)`/g,
  )) {
    commands.push(
      ...extractNpmRunCommands(
        match[1],
        section.startLine + lineOffsetForIndex(section.text, match.index),
      ),
    );
  }
  return commands;
}

function extractNpmRunCommands(text, startLine) {
  const commands = [];
  const lines = text.split(/\r?\n/);
  for (let index = 0; index < lines.length; index += 1) {
    const line = lines[index].trim();
    if (line === '' || line.startsWith('#')) {
      continue;
    }

    for (const match of line.matchAll(
      /\bnpm\s+run\s+([A-Za-z0-9:_-]+)/g,
    )) {
      commands.push({
        script: match[1],
        line: startLine + index,
      });
    }
  }

  return commands;
}

function hasExactNpmInstall(section) {
  return (
    section.text
      .split(/\r?\n/)
      .some((line) => line.trim() === 'npm install') ||
    /`npm install`/.test(section.text)
  );
}

function lineOffsetForIndex(text, index) {
  return text.slice(0, index).split(/\r?\n/).length - 1;
}

function unique(values) {
  return [...new Set(values)];
}

function validateScope(root, scope, displayRoot) {
  const section = extractMarkdownSection(root, scope.relativePath, scope.heading);
  const packageJson = readJson(root, scope.packagePath);
  const scripts = packageJson.scripts || {};
  const commands = [
    ...extractShellFenceCommands(section),
    ...(scope.includeInlineCode ? extractInlineCommands(section) : []),
  ];
  const documentedScripts = unique(
    commands.map((command) => command.script),
  ).sort();
  const displayPath = path.join(displayRoot, scope.relativePath);
  const scopeLabel = scope.heading ? `${displayPath}#${scope.heading}` : displayPath;
  const errors = [];

  // A scope with no expected scripts is documentation that may legitimately
  // describe setup only, so an empty command list is not a failure there.
  if (scope.expectedScripts?.length && commands.length === 0) {
    errors.push(
      `${displayPath}:1 ${scopeLabel} does not document any npm run commands for ${scope.packageLabel}.`,
    );
  }

  for (const expectedScript of scope.expectedScripts || []) {
    if (!documentedScripts.includes(expectedScript)) {
      errors.push(
        `${displayPath}:1 should document npm run ${expectedScript} for ${scope.packageLabel}.`,
      );
    }
  }

  if (scope.requireNpmInstall && !hasExactNpmInstall(section)) {
    errors.push(
      `${displayPath}:1 should document the exact npm install command for ${scope.packageLabel}.`,
    );
  }

  for (const command of commands) {
    if (!scripts[command.script]) {
      errors.push(
        `${displayPath}:${command.line} documents npm run ${command.script} for ${scope.packageLabel}, but ${path.join(displayRoot, scope.packagePath)} has no "${command.script}" script.`,
      );
    }
  }

  return {
    documentedScripts,
    errors,
    label: `${scopeLabel} -> ${path.join(displayRoot, scope.packagePath)}`,
  };
}

/**
 * Validates documented npm commands against the package that exposes them.
 *
 * With no options this checks the repository's own documentation plus the Whisk
 * source templates. With `generatedTheme` it checks a real generated child theme
 * directory, so generated output is held to the same contract as the template.
 */
function validateDocumentation(options = {}) {
  const generatedTheme = options.generatedTheme || null;
  const root = generatedTheme ? path.resolve(generatedTheme) : repoRoot;
  const displayRoot = generatedTheme ? path.basename(root) : '';
  const checks = generatedTheme
    ? THEME_DOC_CHECKS
    : [...ROOT_CHECKS, ...THEME_DOC_CHECKS.map(prefixThemeScope)];

  const errors = [];
  const summaries = [];

  for (const scope of checks) {
    let result;

    try {
      result = validateScope(root, scope, displayRoot);
    } catch (error) {
      errors.push(error.message);
      continue;
    }

    errors.push(...result.errors);
    summaries.push(`${result.label}: ${result.documentedScripts.join(', ')}`);
  }

  return { errors, summaries, count: checks.length };
}

function parseArgs(argv) {
  const options = {};

  for (let index = 0; index < argv.length; index += 1) {
    if (argv[index] === '--generated-theme') {
      options.generatedTheme = argv[index + 1];
      index += 1;
    }
  }

  return options;
}

if (require.main === module) {
  const result = validateDocumentation(parseArgs(process.argv.slice(2)));

  if (result.errors.length > 0) {
    for (const error of result.errors) {
      console.error(error);
    }
    process.exit(1);
  }

  console.log(
    `Validated documented npm scripts in ${result.count} documentation sections.`,
  );
  for (const summary of result.summaries) {
    console.log(`- ${summary}`);
  }
}

module.exports = { validateDocumentation };
