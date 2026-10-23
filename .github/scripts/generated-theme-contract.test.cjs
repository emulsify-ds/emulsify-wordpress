#!/usr/bin/env node

// Focused tests for the generated child theme contract. These build a real
// generated theme with the standalone starter hook, then mutate it to prove each
// class of contract failure is actually detected.

const assert = require('node:assert/strict');
const childProcess = require('node:child_process');
const fs = require('node:fs');
const os = require('node:os');
const path = require('node:path');
const { test } = require('node:test');

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

const machineName = 'acme-site';
const displayName = 'Acme Site';
const description = 'Marketing site for Acme, Inc. (rebuild).';
const guidanceFiles = [
  'AGENTS.md',
  'docs/wordpress-integration.md',
  '.agents/skills/emulsify-wordpress-integration/SKILL.md',
];

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

function writeJson(filePath, data) {
  fs.mkdirSync(path.dirname(filePath), { recursive: true });
  fs.writeFileSync(filePath, `${JSON.stringify(data, null, 2)}\n`);
}

function readJson(filePath) {
  return JSON.parse(fs.readFileSync(filePath, 'utf8'));
}

/**
 * Generates a throwaway child theme and returns its directory.
 */
function generateTheme() {
  const workRoot = fs.mkdtempSync(path.join(os.tmpdir(), 'emulsify-contract-'));
  const target = path.join(workRoot, machineName);

  copyStarter(starterRoot, target);

  const project = readJson(path.join(target, 'project.emulsify.json'));
  project.project.name = displayName;
  project.project.machineName = machineName;
  project.project.description = description;
  writeJson(path.join(target, 'project.emulsify.json'), project);

  childProcess.execFileSync(process.execPath, [path.join(target, '.cli/init.js')], {
    cwd: target,
    stdio: 'pipe',
  });

  return { workRoot, target };
}

function validate(target, options = {}) {
  return validateGeneratedTheme({
    themeDir: target,
    machineName,
    displayName,
    description,
    sourceDir: starterRoot,
    ...options,
  });
}

function withGeneratedTheme(callback) {
  const { workRoot, target } = generateTheme();

  try {
    return callback(target);
  } finally {
    fs.rmSync(workRoot, { recursive: true, force: true });
  }
}

test('accepts a freshly generated child theme', () => {
  withGeneratedTheme((target) => {
    const result = validate(target);
    assert.equal(
      result.errors.length,
      0,
      `Expected a clean generated theme:\n${result.format()}`,
    );
    assert.match(result.format(), /PASS documentation/);
    assert.match(result.format(), /PASS placeholder replacement/);
  });
});

test('freshly generated child themes inherit parent templates without local Twig files', () => {
  withGeneratedTheme((target) => {
    assert.equal(fs.existsSync(path.join(target, 'templates')), false);
    assert.deepEqual(
      fs.readdirSync(target, { recursive: true }).filter((file) => file.endsWith('.twig')),
      [],
    );
  });
});

test('accepts a generated child theme without a local page template', () => {
  withGeneratedTheme((target) => {
    fs.rmSync(path.join(target, 'templates'), { recursive: true, force: true });
    const result = validate(target);
    assert.deepEqual(result.errors, [], result.format());
  });
});

test('allows consumers to add an intentional page override after generation', () => {
  withGeneratedTheme((target) => {
    fs.mkdirSync(path.join(target, 'templates'), { recursive: true });
    fs.writeFileSync(
      path.join(target, 'templates/page.twig'),
      "{% extends '@emulsify-tpl/page.twig' %}\n{% block content %}<div class=\"project-page\">{{ parent() }}</div>{% endblock %}\n",
    );
    const result = validate(target);
    assert.deepEqual(result.errors, [], result.format());
  });
});

test('requires the generated documentation set', () => {
  withGeneratedTheme((target) => {
    fs.rmSync(path.join(target, 'README.md'));
    fs.rmSync(path.join(target, 'docs'), { recursive: true, force: true });

    const result = validate(target);
    assert.ok(result.errors.length > 0);
    assert.match(result.format(), /FAIL generation/);
    assert.match(result.format(), /missing required generated file "README\.md"/);
  });
});

test('requires all generated AI guidance files', () => {
  for (const file of guidanceFiles) {
    withGeneratedTheme((target) => {
      fs.rmSync(path.join(target, file));
      const result = validate(target);
      assert.match(result.format(), /FAIL AI guidance/);
      assert.ok(result.errors.some((error) => error.includes(`missing required generated guidance file "${file}"`)), result.format());
    });
  }
});

test('preserves guidance bytes during generation and permits project edits after a build', () => {
  withGeneratedTheme((target) => {
    for (const file of guidanceFiles) {
      assert.deepEqual(fs.readFileSync(path.join(target, file)), fs.readFileSync(path.join(starterRoot, file)));
      fs.appendFileSync(path.join(target, file), '\nProject guidance customized by the team.\n');
    }
    const result = validate(target);
    assert.match(result.format(), /FAIL AI guidance/);
    for (const file of guidanceFiles) {
      assert.ok(result.errors.some((error) => error.includes(`changed guidance file "${file}" during generation`)), result.format());
    }
    for (const directory of ['dist', 'node_modules', '.out', '.coverage']) {
      fs.mkdirSync(path.join(target, directory));
    }
    const built = validate(target, { checkBuiltAssets: true });
    assert.deepEqual(built.errors, [], built.format());
    assert.match(built.format(), /PASS AI guidance/);

    fs.mkdirSync(path.join(target, '.cli'));
    const leftover = validate(target, { checkBuiltAssets: true });
    assert.match(leftover.format(), /generation-only or build path "\.cli"/);
  });
});

test('validates skill YAML after project customization with actionable errors', () => {
  for (const [contents, expected] of [
    ['Missing frontmatter\n', /expected YAML frontmatter/],
    ['---\nname: [\n---\n', /invalid skill manifest/],
    ['---\n- name: emulsify-wordpress-integration\n---\n', /name must match the skill directory/],
    ['---\nname: another-skill\ndescription: Integration guidance\n---\n', /name must match the skill directory/],
    ['---\nname: emulsify-wordpress-integration\ndescription: ""\n---\n', /description must be a nonempty string/],
    ['---\nname: emulsify-wordpress-integration\ndescription: [Integration guidance]\n---\n', /description must be a nonempty string/],
  ]) {
    withGeneratedTheme((target) => {
      fs.mkdirSync(path.join(target, 'dist'));
      fs.writeFileSync(path.join(target, guidanceFiles[2]), contents);
      const result = validate(target, { checkBuiltAssets: true });
      assert.match(result.format(), /FAIL AI guidance/);
      assert.match(result.format(), expected);
    });
  }
});

test('accepts a customized skill with folded YAML and local links', () => {
  withGeneratedTheme((target) => {
    fs.mkdirSync(path.join(target, 'dist'));
    fs.writeFileSync(path.join(target, guidanceFiles[2]), '---\r\nname: "emulsify-wordpress-integration"\r\ndescription: >\r\n  Project integration\r\n  guidance.\r\n---\r\n[Integration guide](../../../docs/wordpress-integration.md)\r\n');
    const result = validate(target, { checkBuiltAssets: true });
    assert.deepEqual(result.errors, [], result.format());
  });
});

test('validates guidance links after customization and rejects missing or escaping targets', () => {
  withGeneratedTheme((target) => {
    fs.mkdirSync(path.join(target, 'dist'));
    const skill = path.join(target, guidanceFiles[2]);
    fs.appendFileSync(skill, '\n[Integration guide](../../../docs/wordpress-integration.md)\n');
    assert.deepEqual(validate(target, { checkBuiltAssets: true }).errors, []);

    const outside = path.join(path.dirname(target), `${machineName}-external`);
    fs.mkdirSync(outside);
    fs.writeFileSync(path.join(outside, 'outside.md'), 'Outside the theme.\n');
    fs.appendFileSync(skill, `\n[Missing guide](../../../docs/missing.md)\n[Outside theme](../../../../${machineName}-external/outside.md)\n`);
    const result = validate(target, { checkBuiltAssets: true });
    assert.match(result.format(), /FAIL AI guidance/);
    assert.match(result.format(), /resolves to missing local path "docs\/missing\.md"/);
    assert.match(result.format(), /resolves outside the generated child theme/);
  });
});

test('checks npm commands in every generated guidance entry point', () => {
  for (const file of guidanceFiles) {
    withGeneratedTheme((target) => {
      fs.mkdirSync(path.join(target, 'dist'));
      fs.appendFileSync(path.join(target, file), '\nRun `npm run impossible-script` for this project.\n');
      const result = validate(target, { checkBuiltAssets: true });
      assert.match(result.format(), /FAIL AI guidance/);
      assert.ok(result.errors.some((error) => error.includes(`${file}:`) && error.includes('documents npm run impossible-script')), result.format());
      assert.match(result.format(), /has no "impossible-script" script/);
    });
  }
});

test('continues checking guidance for stale tokens and starter identity after customization', () => {
  withGeneratedTheme((target) => {
    fs.mkdirSync(path.join(target, 'dist'));
    fs.appendFileSync(path.join(target, guidanceFiles[0]), '\n%%EMULSIFY_MACHINE_NAME%%\n');
    fs.appendFileSync(path.join(target, guidanceFiles[2]), '\nnamespace: whiskHelper\n');
    const result = validate(target, { checkBuiltAssets: true });
    assert.match(result.format(), /stale documentation token/);
    assert.match(result.format(), /stale starter machine name "whisk"/);
  });
});

test('reports leftover documentation tokens', () => {
  withGeneratedTheme((target) => {
    const readmePath = path.join(target, 'README.md');
    fs.writeFileSync(
      readmePath,
      `${fs.readFileSync(readmePath, 'utf8')}\n%%EMULSIFY_THEME_NAME%%\n`,
    );

    const result = validate(target);
    assert.match(result.format(), /FAIL placeholder replacement/);
    assert.match(result.format(), /documentation token/);
  });
});

test('reports a stale starter machine name', () => {
  withGeneratedTheme((target) => {
    const packagePath = path.join(target, 'package.json');
    const packageJson = readJson(packagePath);
    packageJson.name = 'whisk';
    writeJson(packagePath, packageJson);

    const result = validate(target);
    assert.match(result.format(), /FAIL placeholder replacement/);
    assert.match(result.format(), /stale starter machine name "whisk"/);
  });
});

test('reports a documented npm command that package.json does not expose', () => {
  withGeneratedTheme((target) => {
    const docPath = path.join(target, 'docs/support-information.md');
    fs.writeFileSync(
      docPath,
      `${fs.readFileSync(docPath, 'utf8')}\n\`\`\`bash\nnpm run does-not-exist\n\`\`\`\n`,
    );

    const result = validate(target);
    assert.match(result.format(), /FAIL documentation/);
    assert.match(result.format(), /has no "does-not-exist" script/);
  });
});

test('reports documentation links that escape the generated theme', () => {
  withGeneratedTheme((target) => {
    const readmePath = path.join(target, 'README.md');
    fs.writeFileSync(
      readmePath,
      `${fs.readFileSync(readmePath, 'utf8')}\n[Escape](../../secrets.md)\n`,
    );

    const result = validate(target);
    assert.match(result.format(), /FAIL file references/);
    assert.match(result.format(), /resolves outside the generated child theme/);
  });
});

test('requires WordPress theme identity to match the request', () => {
  withGeneratedTheme((target) => {
    const stylePath = path.join(target, 'style.css');
    fs.writeFileSync(
      stylePath,
      fs.readFileSync(stylePath, 'utf8').replace('Template: emulsify', 'Template: twentytwentyfive'),
    );

    const result = validate(target);
    assert.match(result.format(), /FAIL WordPress metadata/);
    assert.match(result.format(), /Template/);
  });
});

test('requires generated project lineage metadata', () => {
  withGeneratedTheme((target) => {
    const projectPath = path.join(target, 'project.emulsify.json');
    const project = readJson(projectPath);
    project.project.generatedFromVersion = '0.0.0';
    writeJson(projectPath, project);

    const result = validate(target);
    assert.match(result.format(), /FAIL frontend metadata/);
    assert.match(result.format(), /project\.generatedFromVersion/);
  });
});

test('requires generated project description metadata', () => {
  withGeneratedTheme((target) => {
    const projectPath = path.join(target, 'project.emulsify.json');
    const project = readJson(projectPath);
    delete project.project.description;
    writeJson(projectPath, project);

    const result = validate(target);
    assert.match(result.format(), /FAIL frontend metadata/);
    assert.match(result.format(), /project\.description/);
  });
});

test('rejects generation-only tooling left in the generated theme', () => {
  withGeneratedTheme((target) => {
    fs.mkdirSync(path.join(target, '.cli'), { recursive: true });
    fs.writeFileSync(path.join(target, '.cli/init.js'), '// leftover\n');

    const result = validate(target);
    assert.match(result.format(), /FAIL generation/);
    assert.match(result.format(), /generation-only or build path "\.cli"/);
  });
});

test('rejects symbolic links in generated output', () => {
  withGeneratedTheme((target) => {
    fs.symlinkSync(path.join(target, 'style.css'), path.join(target, 'linked.css'));

    const result = validate(target);
    assert.match(result.format(), /FAIL generation/);
    assert.match(result.format(), /contains symbolic link/);
  });
});

test('rejects retired terminology in generated markdown', () => {
  withGeneratedTheme((target) => {
    const docPath = path.join(target, 'docs/development.md');
    fs.writeFileSync(
      docPath,
      `${fs.readFileSync(docPath, 'utf8')}\nBuild the subtheme with Webpack.\n`,
    );

    const result = validate(target);
    assert.match(result.format(), /FAIL placeholder replacement/);
    assert.match(result.format(), /retired frontend tooling/);
    assert.match(result.format(), /retired theme terminology/);
  });
});
