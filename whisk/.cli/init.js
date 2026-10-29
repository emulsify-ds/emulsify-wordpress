import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const STARTER_SLUG = 'whisk';
const PARENT_THEME = 'emulsify';
const GENERATED_FROM = 'emulsify-wordpress';
const FALLBACK_DESCRIPTION = 'No description was supplied during generation.';
const ROOT = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');

// Documentation files are copied verbatim, so this is the only place their
// %%EMULSIFY_*%% tokens are resolved. A surviving token fails generation.
const DOCUMENTATION_FILES = [
  'README.md',
  'docs/development.md',
  'docs/support-information.md',
  'docs/upgrading.md',
];

const DOCUMENTATION_TOKEN_PATTERN = /%%EMULSIFY_[A-Z_]+%%/;

const projectConfigPath = path.join(ROOT, 'project.emulsify.json');

const isPlainObject = (value) =>
  value !== null && typeof value === 'object' && !Array.isArray(value);

const readText = (filePath) => fs.readFileSync(filePath, 'utf8');

const writeTextIfChanged = (filePath, contents) => {
  if (readText(filePath) !== contents) {
    fs.writeFileSync(filePath, contents);
  }
};

const readJson = (filePath) => {
  let data;
  try {
    data = JSON.parse(readText(filePath));
  } catch (error) {
    throw new Error(`Starter JSON file must contain a valid JSON object: ${path.relative(ROOT, filePath)}`, { cause: error });
  }
  if (!isPlainObject(data)) {
    throw new Error(`Starter JSON file must contain a valid JSON object: ${path.relative(ROOT, filePath)}`);
  }
  return data;
};

const writeJsonIfChanged = (filePath, data) => {
  const contents = `${JSON.stringify(data, null, 2)}\n`;
  writeTextIfChanged(filePath, contents);
};

const escapeRegExp = (value) => value.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');

// Generated values land in Markdown table cells and prose, where a newline
// would break the surrounding structure.
const oneLine = (value) => String(value ?? '').replace(/\s+/g, ' ').trim();

// Match sanitize_text_field() for generated plain-text metadata, including its
// removal of percent-encoded octets. Encoding source files is handled separately.
const sanitizeTextField = (value) => {
  let text = String(value)
    .replace(/<(script|style)\b[^>]*>[\s\S]*?<\/\1>/gi, '')
    .replace(/<[^>]*?((?=<)|>|$)/g, (part) => part.endsWith('>') ? part : part.replace(/&/g, '&amp;').replace(/</g, '&lt;'))
    .replace(/<!--[^]*?-->|<(?:[^>\"']|\"[^\"]*\"|'[^']*')*>/g, '')
    .replace(/[\r\n\t ]+/g, ' ')
    .trim();
  while (/%[a-f0-9]{2}/i.test(text)) {
    text = text.replace(/%[a-f0-9]{2}/gi, '').replace(/ +/g, ' ').trim();
  }
  return text;
};

const readThemeHeader = (contents, field) => {
  const match = contents.match(
    new RegExp(`^\\s*\\*\\s*${escapeRegExp(field)}:\\s*(.+?)\\s*$`, 'mi'),
  );

  return match ? match[1].trim() : '';
};

const sanitizeLabelForSource = (label) => {
  const sourceLabel = label
    .replace(/[^\p{L}\p{N} -]+/gu, '')
    .replace(/ +/g, ' ')
    .trim();

  return sourceLabel || 'New Theme';
};

const replaceThemeHeader = (contents, field, value) => {
  const pattern = new RegExp(`^(\\s*\\*\\s*${escapeRegExp(field)}:\\s*).*$`, 'mi');
  let found = false;
  const updated = contents.replace(pattern, (_match, prefix) => {
    found = true;
    return `${prefix}${value}`;
  });

  if (!found) {
    throw new Error(`Could not update "${field}" in style.css.`);
  }

  return updated;
};

const getProjectConfig = () => {
  const config = readJson(projectConfigPath);

  if (!isPlainObject(config.project)) {
    throw new Error('project.emulsify.json must contain a project object.');
  }

  const { name, machineName } = config.project;

  if (typeof name !== 'string' || name.trim() === '') {
    throw new Error('project.emulsify.json project.name must be a non-empty string.');
  }

  if (typeof machineName !== 'string' || machineName.trim() === '') {
    throw new Error('project.emulsify.json project.machineName must be a non-empty string.');
  }

  const canonical = machineName.replace(/[A-Z]/g, (letter) => letter.toLowerCase()).replace(/[^a-z0-9]+/g, '-').replace(/^-+|-+$/g, '');
  if (machineName !== canonical || canonical === '') {
    const advice = canonical ? ` Re-run Emulsify CLI with --machineName ${canonical}.` : '';
    throw new Error(`project.machineName must be canonical: lowercase letters, digits and single hyphens, with no leading or trailing hyphen.${advice} The CLI chooses the project directory before this hook runs.`);
  }

  return config;
};

const updateStyleCss = ({ sourceLabel, machineName, description }) => {
  const filePath = path.join(ROOT, 'style.css');
  let contents = readText(filePath);

  contents = replaceThemeHeader(contents, 'Theme Name', sourceLabel);
  contents = replaceThemeHeader(contents, 'Text Domain', machineName);
  contents = replaceThemeHeader(contents, 'Description', description);
  contents = replaceThemeHeader(contents, 'Template', PARENT_THEME);

  writeTextIfChanged(filePath, contents);
};

const updatePackageJson = ({ machineName, description }) => {
  const filePath = path.join(ROOT, 'package.json');
  const data = readJson(filePath);

  data.name = machineName;
  data.description = description;

  writeJsonIfChanged(filePath, data);
};

const getDescription = (config) => {
  const declared = config.project.description;

  if (typeof declared === 'string' && declared.trim() !== '') {
    const sanitized = sanitizeTextField(declared);
    if (sanitized !== '') return oneLine(sanitized);
  }

  const starter = readThemeHeader(
    readText(path.join(ROOT, 'style.css')),
    'Description',
  );

  return starter !== '' ? oneLine(starter) : FALLBACK_DESCRIPTION;
};

const getCoreRange = () => {
  const data = readJson(path.join(ROOT, 'package.json'));
  const range = data.dependencies?.['@emulsify/core'];

  if (typeof range !== 'string' || range.trim() === '') {
    throw new Error('package.json is missing dependencies.@emulsify/core.');
  }

  return oneLine(range);
};

const updateDocumentation = ({
  name,
  machineName,
  description,
  generatedFromVersion,
  coreRange,
}) => {
  const replacements = new Map([
    ['%%EMULSIFY_THEME_NAME%%', oneLine(name)],
    ['%%EMULSIFY_MACHINE_NAME%%', oneLine(machineName)],
    ['%%EMULSIFY_DESCRIPTION%%', oneLine(description)],
    ['%%EMULSIFY_SOURCE_PROJECT%%', GENERATED_FROM],
    ['%%EMULSIFY_SOURCE_VERSION%%', oneLine(generatedFromVersion)],
    ['%%EMULSIFY_CORE_RANGE%%', oneLine(coreRange)],
  ]);

  for (const relativePath of DOCUMENTATION_FILES) {
    const filePath = path.join(ROOT, relativePath);

    if (!fs.existsSync(filePath)) {
      throw new Error(
        `Expected generated documentation file is missing: ${relativePath}`,
      );
    }

    let contents = readText(filePath);

    for (const [token, value] of replacements) {
      contents = contents.split(token).join(value);
    }

    const leftover = contents.match(DOCUMENTATION_TOKEN_PATTERN);

    if (leftover) {
      throw new Error(
        `Unable to replace documentation token ${leftover[0]} in ${relativePath}.`,
      );
    }

    writeTextIfChanged(filePath, contents);
  }
};

// The starter package.json carries the release version it was cut from. Failing
// loudly is better than recording a stale fallback that would misreport a
// generated project's lineage.
const getGeneratedFromVersion = () => {
  const data = readJson(path.join(ROOT, 'package.json'));

  if (typeof data.version !== 'string' || data.version.trim() === '') {
    throw new Error('package.json is missing a release version.');
  }

  return data.version.trim();
};

const updateLockfile = (relativePath, { machineName }) => {
  const filePath = path.join(ROOT, relativePath);

  if (!fs.existsSync(filePath)) {
    return;
  }

  const data = readJson(filePath);

  data.name = machineName;

  if (isPlainObject(data.packages) && isPlainObject(data.packages[''])) {
    data.packages[''].name = machineName;
  }

  writeJsonIfChanged(filePath, data);
};

const updateProjectConfig = (
  config,
  { name, machineName, generatedFromVersion, description },
) => {
  config.project.platform = 'wordpress';
  config.project.name = name;
  config.project.machineName = machineName;
  config.project.generatedFrom = GENERATED_FROM;
  config.project.generatedFromVersion = generatedFromVersion;
  config.project.description = oneLine(description);

  writeJsonIfChanged(projectConfigPath, config);
};

const updateFunctionsPhp = ({ sourceLabel }) => {
  const filePath = path.join(ROOT, 'functions.php');
  const contents = readText(filePath).replace(
    'Whisk child theme hooks.',
    `${sourceLabel} child theme hooks.`,
  );

  writeTextIfChanged(filePath, contents);
};

const walkJsonFiles = (directory) => {
  if (!fs.existsSync(directory)) {
    return [];
  }

  return fs.readdirSync(directory, { withFileTypes: true }).sort((left, right) => left.name.localeCompare(right.name)).flatMap((entry) => {
    const filePath = path.join(directory, entry.name);

    if (entry.isDirectory()) {
      return walkJsonFiles(filePath);
    }

    return entry.isFile() && entry.name.endsWith('.json') ? [filePath] : [];
  });
};

const updatePatternNamespaces = ({ machineName }, patterns) => {
  for (const { filePath, data } of patterns) {
    if (typeof data.name === 'string' && data.name.startsWith(`${STARTER_SLUG}/`)) {
      data.name = `${machineName}/${data.name.slice(STARTER_SLUG.length + 1)}`;
    }

    // Rewrite unconditionally so pattern JSON is canonically formatted on both
    // generation paths. writeJsonIfChanged skips the write when nothing moved.
    writeJsonIfChanged(filePath, data);
  }
};

const main = () => {
  const config = getProjectConfig();
  const name = sanitizeTextField(config.project.name) || 'New Theme';
  // Validate every pattern before rewriting any generated files.
  const patterns = walkJsonFiles(path.join(ROOT, 'patterns')).map((filePath) => ({ filePath, data: readJson(filePath) }));
  const project = {
    name,
    sourceLabel: sanitizeLabelForSource(name),
    machineName: config.project.machineName,
    generatedFromVersion: getGeneratedFromVersion(),
    description: getDescription(config),
    coreRange: getCoreRange(),
  };

  // Resolve documentation tokens before the metadata rewrites so a failed
  // substitution aborts generation while the starter is still recognizable.
  updateDocumentation(project);
  updateStyleCss(project);
  updatePackageJson(project);
  updateLockfile('package-lock.json', project);
  updateLockfile('npm-shrinkwrap.json', project);
  updateProjectConfig(config, project);
  updateFunctionsPhp(project);
  updatePatternNamespaces(project, patterns);

  // Generation-only tooling should not linger in a project repository. Removing
  // it last keeps the hook available if any step above throws, and keeps this
  // path aligned with the WP-CLI generator, which never copies `.cli`.
  fs.rmSync(path.dirname(fileURLToPath(import.meta.url)), {
    recursive: true,
    force: true,
  });
};

main();
