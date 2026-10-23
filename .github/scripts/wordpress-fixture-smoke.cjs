#!/usr/bin/env node

// End-to-end WordPress smoke fixture. It installs a disposable WordPress site,
// mounts this parent theme, generates a Whisk child theme, and exercises the
// runtime features that cannot be proven with pure PHP stubs.

const childProcess = require('child_process');
const fs = require('fs');
const net = require('net');
const os = require('os');
const path = require('path');

const repoRoot = path.resolve(__dirname, '../..');
// Root package.json is the single source of truth for the release version.
const expectedGeneratedFromVersion = JSON.parse(
  fs.readFileSync(path.join(repoRoot, 'package.json'), 'utf8'),
).version;
const required = process.env.WP_SMOKE_REQUIRED === '1';
const keepFixture = process.env.WP_SMOKE_KEEP === '1';
const auditAccessibility = process.env.WP_SMOKE_A11Y === '1';
const host = process.env.WP_SMOKE_HOST || '127.0.0.1';
const wordpressVersion = process.env.WP_SMOKE_WORDPRESS_VERSION || '6.8';
let workDir = null;
let logDir = null;
let server = null;
let databaseName = null;
let currentDatabaseEnv = null;

function log(message) {
  console.log(`[wordpress-smoke] ${message}`);
}

function unavailable(message) {
  if (required) {
    throw new Error(message);
  }

  console.log(`WORDPRESS_SMOKE_SKIPPED ${message}`);
  process.exit(0);
}

function commandExists(command) {
  const result = childProcess.spawnSync('sh', ['-lc', `command -v ${command}`], {
    encoding: 'utf8',
  });

  return result.status === 0;
}

function appendLog(file, contents) {
  if (!logDir || !contents || !fs.existsSync(logDir)) {
    return;
  }

  fs.appendFileSync(path.join(logDir, file), contents);
}

function run(label, command, args, options = {}) {
  appendLog('commands.log', `\n$ ${[command, ...args].join(' ')}\n`);

  const result = childProcess.spawnSync(command, args, {
    cwd: options.cwd || repoRoot,
    encoding: 'utf8',
    env: options.env || process.env,
    input: options.input,
    maxBuffer: 1024 * 1024 * 20,
  });

  appendLog('commands.log', result.stdout);
  appendLog('commands.log', result.stderr);

  if (result.status !== 0) {
    throw new Error(`${label} failed with exit code ${result.status}. See ${path.join(logDir, 'commands.log')}.`);
  }

  return result.stdout.trim();
}

function wp(wpPath, args, options = {}) {
  return run(`wp ${args.join(' ')}`, 'wp', ['--allow-root', `--path=${wpPath}`, ...args], options);
}

function ensureTools() {
  for (const command of ['php', 'composer', 'npm', 'wp']) {
    if (!commandExists(command)) {
      unavailable(`Required command "${command}" is not available.`);
    }
  }

  const mysqli = childProcess.spawnSync('php', ['-r', 'exit(extension_loaded("mysqli") ? 0 : 1);']);
  if (mysqli.status !== 0) {
    unavailable('The PHP mysqli extension is required for the WordPress fixture database.');
  }

  if (!required && !process.env.WP_SMOKE_DB_HOST && !process.env.WP_SMOKE_DB_NAME) {
    unavailable('Database environment is not configured. Set WP_SMOKE_DB_HOST, WP_SMOKE_DB_NAME, WP_SMOKE_DB_USER, and WP_SMOKE_DB_PASSWORD to run locally.');
  }
}

function databaseEnv() {
  const uniqueName = `emulsify_smoke_${process.pid}_${Date.now()}`;
  databaseName = process.env.WP_SMOKE_DB_NAME || uniqueName;

  if (!/^[A-Za-z0-9_]+$/.test(databaseName)) {
    throw new Error('WP_SMOKE_DB_NAME may only contain letters, numbers, and underscores.');
  }

  return {
    ...process.env,
    WP_SMOKE_DB_HOST: process.env.WP_SMOKE_DB_HOST || '127.0.0.1',
    WP_SMOKE_DB_PORT: process.env.WP_SMOKE_DB_PORT || '3306',
    WP_SMOKE_DB_NAME: databaseName,
    WP_SMOKE_DB_USER: process.env.WP_SMOKE_DB_USER || 'root',
    WP_SMOKE_DB_PASSWORD: process.env.WP_SMOKE_DB_PASSWORD || 'root',
  };
}

function createDatabase(env) {
  const code = String.raw`
$host = getenv('WP_SMOKE_DB_HOST') ?: '127.0.0.1';
$port = (int) (getenv('WP_SMOKE_DB_PORT') ?: 3306);
$user = getenv('WP_SMOKE_DB_USER') ?: 'root';
$pass = getenv('WP_SMOKE_DB_PASSWORD') ?: '';
$db = getenv('WP_SMOKE_DB_NAME') ?: 'wordpress_smoke';
if (!preg_match('/^[A-Za-z0-9_]+$/', $db)) {
  fwrite(STDERR, "Unsafe database name.\n");
  exit(1);
}
$mysqli = mysqli_init();
if (!$mysqli || !$mysqli->real_connect($host, $user, $pass, null, $port)) {
  fwrite(STDERR, "Unable to connect to MySQL: " . mysqli_connect_error() . "\n");
  exit(1);
}
$mysqli->query('DROP DATABASE IF EXISTS ' . $db);
if (!$mysqli->query('CREATE DATABASE ' . $db . ' CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci')) {
  fwrite(STDERR, "Unable to create database: " . $mysqli->error . "\n");
  exit(1);
}
`;

  run('Create WordPress smoke database', 'php', ['-r', code], { env });
}

function dropDatabase(env) {
  if (!databaseName) {
    return;
  }

  const code = String.raw`
$host = getenv('WP_SMOKE_DB_HOST') ?: '127.0.0.1';
$port = (int) (getenv('WP_SMOKE_DB_PORT') ?: 3306);
$user = getenv('WP_SMOKE_DB_USER') ?: 'root';
$pass = getenv('WP_SMOKE_DB_PASSWORD') ?: '';
$db = getenv('WP_SMOKE_DB_NAME') ?: '';
if (!preg_match('/^[A-Za-z0-9_]+$/', $db)) {
  exit(0);
}
$mysqli = mysqli_init();
if ($mysqli && $mysqli->real_connect($host, $user, $pass, null, $port)) {
  $mysqli->query('DROP DATABASE IF EXISTS ' . $db);
}
`;

  try {
    run('Drop WordPress smoke database', 'php', ['-r', code], { env });
  }
  catch (error) {
    appendLog('commands.log', `Database cleanup failed: ${error.message}\n`);
  }
}

function stopServer() {
  if (!server) {
    return;
  }

  server.kill();
  server = null;
}

function copyDirectory(source, destination, filter) {
  fs.cpSync(source, destination, {
    recursive: true,
    filter: (sourcePath) => filter(path.relative(source, sourcePath).split(path.sep)),
  });
}

function copyThemes(themesDir) {
  const parentTheme = path.join(themesDir, 'emulsify');
  const childTheme = path.join(themesDir, 'whisk');
  const configuredArchive = process.env.WP_SMOKE_THEME_ARCHIVE;

  if (configuredArchive) {
    const archivePath = path.resolve(repoRoot, configuredArchive);
    if (!fs.existsSync(archivePath)) {
      throw new Error(`Configured theme archive does not exist: ${archivePath}`);
    }

    run('Extract installable theme archive', 'unzip', ['-q', archivePath, '-d', themesDir]);
    if (!fs.existsSync(path.join(parentTheme, 'whisk', 'style.css'))) {
      throw new Error('Installable theme archive is missing the bundled Whisk starter.');
    }

    copyDirectory(path.join(parentTheme, 'whisk'), childTheme, (segments) => {
      const first = segments[0];

      return ![
        '.cache',
        '.cli',
        '.coverage',
        '.out',
        'dist',
        'node_modules',
      ].includes(first);
    });

    return { childTheme, parentTheme };
  }

  copyDirectory(repoRoot, parentTheme, (segments) => {
    const first = segments[0];
    const second = segments[1];

    if ([
      '.git',
      '.github',
      '.artifacts',
      '.coverage',
      '.out',
      '.publish',
      'dist-artifact',
      'node_modules',
      'vendor',
    ].includes(first)) {
      return false;
    }

    if ('whisk' === first && ['.coverage', '.out', 'node_modules'].includes(second)) {
      return false;
    }

    return true;
  });

  copyDirectory(path.join(repoRoot, 'whisk'), childTheme, (segments) => {
    const first = segments[0];

    return ![
      '.coverage',
      '.out',
      'node_modules',
    ].includes(first);
  });

  return { childTheme, parentTheme };
}

function installThemeDependencies(parentTheme) {
  if (fs.existsSync(path.join(parentTheme, 'vendor', 'autoload.php'))) {
    log('Using production Composer dependencies bundled in the theme archive.');
    return;
  }

  run('Install Timber with Composer', 'composer', ['install', '--no-interaction', '--no-progress', '--prefer-dist', '--no-dev'], {
    cwd: parentTheme,
  });
}

function assertGeneratedChildTheme(themePath, slug) {
  const style = fs.readFileSync(path.join(themePath, 'style.css'), 'utf8');
  const project = JSON.parse(fs.readFileSync(path.join(themePath, 'project.emulsify.json'), 'utf8'));
  const packageJson = JSON.parse(fs.readFileSync(path.join(themePath, 'package.json'), 'utf8'));
  const requiredFiles = [
    'assets/icons/.gitkeep',
    'assets/images/.gitkeep',
    'config/acf-json/.gitkeep',
    'patterns/.gitkeep',
    'src/components/.gitkeep',
    'project.emulsify.json',
  ];

  if (!style.includes('Theme Name: Smoke Generated') || !style.includes('Template: emulsify')) {
    throw new Error('Generated child theme style.css headers were not updated correctly.');
  }

  if (packageJson.name !== slug) {
    throw new Error(`Generated child theme package.json name should be ${slug}.`);
  }

  if (
    project.project?.platform !== 'wordpress' ||
    project.project?.name !== 'Smoke Generated' ||
    project.project?.machineName !== slug ||
    project.project?.generatedFrom !== 'emulsify-wordpress' ||
    project.project?.generatedFromVersion !== expectedGeneratedFromVersion
  ) {
    throw new Error('Generated child theme project.emulsify.json metadata is incorrect.');
  }

  for (const file of requiredFiles) {
    const filePath = path.join(themePath, file);
    if (!fs.existsSync(filePath)) {
      throw new Error(`Generated child theme is missing expected Whisk file: ${file}`);
    }
  }

  for (const file of ['src/foundation.scss', 'src/layout.scss', 'src/tokens.scss']) {
    if (fs.existsSync(path.join(themePath, file))) {
      throw new Error(`Generated child theme should not include assumed Sass entrypoint: ${file}`);
    }
  }

  if (fs.existsSync(path.join(themePath, 'theme.json'))) {
    throw new Error('Generated child theme should not include an empty child theme.json by default.');
  }

  if (fs.existsSync(path.join(themePath, 'templates'))) {
    throw new Error('Generated child theme should not include a templates directory by default.');
  }

  if (fs.readdirSync(themePath, { recursive: true }).some((file) => file.endsWith('.twig'))) {
    throw new Error('Generated child theme should not include Twig templates by default.');
  }

  for (const directory of ['src/editor', 'src/foundation', 'src/layout']) {
    if (fs.existsSync(path.join(themePath, directory))) {
      throw new Error(`Generated child theme should not include assumed source directory: ${directory}`);
    }
  }

  for (const directory of ['dist', '.out']) {
    if (fs.existsSync(path.join(themePath, directory))) {
      throw new Error(`Generated child theme should not copy ignored build output directory: ${directory}`);
    }
  }
}

function installGeneratedChildFixtures(themePath) {
  const globalDirectory = path.join(themePath, 'dist', 'global');
  const acfDirectory = path.join(themePath, 'dist', 'components', 'smoke-acf');
  const groupedDirectory = path.join(themePath, 'src', 'components', 'atoms', 'layout', 'smoke-grouped');
  const themeSlug = path.basename(themePath);

  fs.mkdirSync(globalDirectory, { recursive: true });
  fs.mkdirSync(path.join(acfDirectory, 'css'), { recursive: true });
  fs.mkdirSync(groupedDirectory, { recursive: true });
  fs.mkdirSync(path.join(themePath, 'templates'), { recursive: true });
  fs.writeFileSync(
    path.join(groupedDirectory, 'smoke-grouped.html.twig'),
    '<p class="smoke-grouped-project-component">{{ label }}</p>\n'
  );
  fs.writeFileSync(
    path.join(themePath, 'templates', 'single.twig'),
    `{% extends '@emulsify-tpl/single.twig' %}
{% block content %}
  {{ parent() }}
  {% include "${themeSlug}:smoke-grouped" with { label: 'Grouped project component via colon' } only %}
  {% include "@${themeSlug}/smoke-grouped" with { label: 'Grouped project component via namespace' } only %}
{% endblock %}
`
  );
  fs.writeFileSync(
    path.join(globalDirectory, 'smoke.css'),
    '.smoke-generated-global { color: inherit; }\n'
  );
  fs.writeFileSync(
    path.join(acfDirectory, 'css', 'smoke-acf.css'),
    '.smoke-acf { display: block; }\n'
  );
  fs.writeFileSync(
    path.join(acfDirectory, 'smoke-acf.twig'),
    '<section class="smoke-acf">{{ title|default("Smoke ACF") }}</section>\n'
  );
  fs.writeFileSync(
    path.join(acfDirectory, 'smoke-acf.component.json'),
    JSON.stringify(
      {
        name: 'emulsify-smoke-acf',
        title: 'Smoke ACF',
        description: 'Fixture component for WordPress smoke coverage.',
        category: 'widgets',
        icon: 'admin-generic',
        keywords: ['smoke'],
      },
      null,
      2
    ) + '\n'
  );
}

function installNativeBlockFixture(themePath) {
  const blockDirectory = path.join(themePath, 'dist', 'components', 'smoke-native');
  const metadata = {
    apiVersion: 3,
    name: 'emulsify/smoke-native',
    title: 'Smoke Native',
    category: 'widgets',
    textdomain: 'smoke-generated',
  };

  fs.mkdirSync(blockDirectory, { recursive: true });
  fs.writeFileSync(
    path.join(blockDirectory, 'block.json'),
    JSON.stringify(metadata, null, 2) + '\n'
  );
}

function installAcfStub(wpPath) {
  const muPlugins = path.join(wpPath, 'wp-content', 'mu-plugins');
  fs.mkdirSync(muPlugins, { recursive: true });
  fs.writeFileSync(
    path.join(muPlugins, 'emulsify-acf-smoke.php'),
    `<?php
/**
 * Fixture-only ACF block API stub.
 */

if ( ! isset( $GLOBALS['emulsify_smoke_acf_registered'] ) || ! is_array( $GLOBALS['emulsify_smoke_acf_registered'] ) ) {
\t$GLOBALS['emulsify_smoke_acf_registered'] = array();
}

if ( ! function_exists( 'acf_register_block_type' ) ) {
\tfunction acf_register_block_type( array $args ) {
\t\t$GLOBALS['emulsify_smoke_acf_registered'][] = $args;
\t\treturn $args;
\t}
}

if ( ! function_exists( 'acf_get_block_type' ) ) {
\tfunction acf_get_block_type( $name ) {
\t\tforeach ( $GLOBALS['emulsify_smoke_acf_registered'] as $block ) {
\t\t\tif ( isset( $block['name'] ) && $block['name'] === $name ) {
\t\t\t\treturn $block;
\t\t\t}
\t\t}

\t\treturn false;
\t}
}
`
  );
}

function runAcfDiscoveryWithoutAcf(wpPath) {
  const code = String.raw`
function emulsify_smoke_fail( $message ) {
  fwrite( STDERR, $message . "\n" );
  exit( 1 );
}

if ( function_exists( 'acf_register_block_type' ) ) {
  emulsify_smoke_fail( 'ACF block API should not be present before the fixture stub is installed.' );
}

$locator = new \Emulsify\Theme\Blocks\ComponentLocator();
$components = $locator->acf_components();
$fixture = null;

foreach ( $components as $component ) {
  if ( isset( $component['relative'], $component['source'] ) && 'smoke-acf' === $component['relative'] && 'child' === $component['source'] ) {
    $fixture = $component;
    break;
  }
}

if ( null === $fixture ) {
  emulsify_smoke_fail( 'Generated child ACF/Twig fixture component was not discovered without ACF.' );
}

if ( 'dist/components/smoke-acf/smoke-acf.twig' !== $fixture['template'] ) {
  emulsify_smoke_fail( 'Generated child ACF/Twig fixture template was not resolved.' );
}

( new \Emulsify\Theme\Blocks\AcfBlocks( $locator ) )->register_blocks();
`;

  wp(wpPath, ['eval', code]);
}

function runAcfDiscoveryWithStub(wpPath) {
  const code = String.raw`
function emulsify_smoke_fail( $message ) {
  fwrite( STDERR, $message . "\n" );
  exit( 1 );
}

if ( ! function_exists( 'acf_register_block_type' ) ) {
  emulsify_smoke_fail( 'ACF block API stub is not loaded.' );
}

$GLOBALS['emulsify_smoke_acf_registered'] = array();
do_action( 'acf/init' );
$registered = isset( $GLOBALS['emulsify_smoke_acf_registered'] ) && is_array( $GLOBALS['emulsify_smoke_acf_registered'] )
  ? $GLOBALS['emulsify_smoke_acf_registered']
  : array();
$fixture = null;

foreach ( $registered as $block ) {
  if ( isset( $block['name'] ) && 'emulsify-smoke-acf' === $block['name'] ) {
    $fixture = $block;
    break;
  }
}

if ( null === $fixture ) {
  emulsify_smoke_fail( 'Generated child ACF/Twig fixture block was not registered with the ACF stub.' );
}

if ( 'dist/components/smoke-acf/smoke-acf.twig' !== $fixture['twig_template'] ) {
  emulsify_smoke_fail( 'Generated child ACF/Twig fixture did not keep its Twig template.' );
}

if ( ! empty( $fixture['data']['twig_template'] ) ) {
  emulsify_smoke_fail( 'Generated child ACF/Twig fixture data should not persist its Twig template.' );
}
`;

  wp(wpPath, ['eval', code]);
}

function runNativeBlockDiscovery(wpPath) {
  const code = String.raw`
function emulsify_smoke_fail( $message ) {
  fwrite( STDERR, $message . "\n" );
  exit( 1 );
}

$registry = \WP_Block_Type_Registry::get_instance();

if ( ! $registry->is_registered( 'emulsify/smoke-native' ) ) {
  emulsify_smoke_fail( 'Generated child native block.json fixture was not registered.' );
}

$locator = new \Emulsify\Theme\Blocks\ComponentLocator();
$native = $locator->native_block_directories();
$found = false;

foreach ( $native as $block ) {
  if (
    isset( $block['name'], $block['relative'], $block['source'] ) &&
    'emulsify/smoke-native' === $block['name'] &&
    'smoke-native' === $block['relative'] &&
    'child' === $block['source']
  ) {
    $found = true;
    break;
  }
}

if ( ! $found ) {
  emulsify_smoke_fail( 'Generated child native block.json fixture was not discovered child-first.' );
}
`;

  wp(wpPath, ['eval', code]);
}

function configureDebugLog(wpPath) {
  wp(wpPath, ['config', 'set', 'WP_DEBUG', 'true', '--raw']);
  const configPath = path.join(wpPath, 'wp-config.php');
  const config = fs.readFileSync(configPath, 'utf8');
  const debugConfig = `
define( 'WP_DEBUG_LOG', true );
define( 'WP_DEBUG_DISPLAY', false );
define( 'WP_ENVIRONMENT_TYPE', 'local' );
@ini_set( 'display_errors', 0 );
`;

  fs.writeFileSync(
    configPath,
    config.replace(/\/\* That's all, stop editing!.*$/s, `${debugConfig}\n$&`)
  );
}

function createFixtureContent(wpPath) {
  const pageId = wp(wpPath, [
    'post',
    'create',
    '--post_type=page',
    '--post_status=publish',
    '--post_title=Smoke Page',
    '--post_name=smoke-page',
    '--post_content=Smoke page content',
    '--porcelain',
  ]);
  const postId = wp(wpPath, [
    'post',
    'create',
    '--post_type=post',
    '--post_status=publish',
    '--post_title=Smoke Post',
    '--post_name=smoke-post',
    '--post_content=Smoke post content',
    '--porcelain',
  ]);
  const authorId = wp(wpPath, [
    'user',
    'create',
    'smokeauthor',
    'smokeauthor@example.test',
    '--role=author',
    '--display_name=Smoke Author',
    '--porcelain',
  ]);
  const categoryId = wp(wpPath, [
    'term',
    'create',
    'category',
    'Smoke Category',
    '--slug=smoke-category',
    '--porcelain',
  ]);

  wp(wpPath, ['post', 'update', postId, `--post_author=${authorId}`]);
  wp(wpPath, ['post', 'term', 'add', postId, 'category', categoryId, '--by=id']);
  wp(wpPath, [
    'comment',
    'create',
    `--comment_post_ID=${postId}`,
    '--comment_content=Smoke comment content',
    '--comment_author=Smoke Commenter',
    '--comment_author_email=commenter@example.test',
    '--comment_approved=1',
  ]);

  wp(wpPath, ['eval', String.raw`
update_option( 'permalink_structure', '' );
update_option( 'posts_per_page', 10 );
for ( $number = 1; $number <= 11; $number++ ) {
  $result = wp_insert_post( array(
    'post_type' => 'post',
    'post_status' => 'publish',
    'post_title' => sprintf( 'PaginationProbe result %02d', $number ),
    'post_content' => sprintf( 'PaginationProbe content %02d', $number ),
    'post_date' => sprintf( '2001-01-%02d 12:00:00', $number ),
    'post_category' => array( ${Number(categoryId)} ),
  ), true );
  if ( is_wp_error( $result ) ) {
    fwrite( STDERR, $result->get_error_message() . "\n" );
    exit( 1 );
  }
}
`]);

  const menuId = wp(wpPath, ['menu', 'create', 'Smoke Primary', '--porcelain']);
  wp(wpPath, ['menu', 'item', 'add-post', menuId, pageId]);
  wp(wpPath, ['menu', 'item', 'add-post', menuId, postId]);
  wp(wpPath, ['menu', 'item', 'add-term', menuId, 'category', categoryId]);
  wp(wpPath, ['menu', 'location', 'assign', menuId, 'primary']);

  return { authorId, categoryId, pageId, postId };
}

function getAvailablePort() {
  return new Promise((resolve, reject) => {
    const probe = net.createServer();

    probe.once('error', reject);
    probe.listen(0, host, () => {
      const address = probe.address();
      probe.close(() => resolve(address.port));
    });
  });
}

function startServer(wpPath, port) {
  server = childProcess.spawn('php', ['-S', `${host}:${port}`, '-t', wpPath], {
    cwd: wpPath,
    stdio: ['ignore', 'pipe', 'pipe'],
  });

  server.stdout.on('data', (data) => appendLog('server.log', data.toString()));
  server.stderr.on('data', (data) => appendLog('server.log', data.toString()));
  server.on('exit', (code, signal) => {
    appendLog('server.log', `\nPHP server exited with code ${code} signal ${signal}\n`);
  });
}

async function waitForServer(baseUrl) {
  const deadline = Date.now() + 30000;
  let lastError = null;

  while (Date.now() < deadline) {
    try {
      const response = await fetch(baseUrl);
      if (response.status < 500) {
        return;
      }
    }
    catch (error) {
      lastError = error;
    }

    await new Promise((resolve) => setTimeout(resolve, 500));
  }

  throw new Error(`WordPress fixture server did not become ready. ${lastError ? lastError.message : ''}`);
}

async function checkRoute(baseUrl, route) {
  const response = await fetch(`${baseUrl}${route.path}`);
  const body = await response.text();
  const expectedStatus = route.status || 200;

  appendLog('http.log', `\n${route.name} ${route.path}\nStatus: ${response.status}\n`);
  appendLog('http.log', body);
  appendLog('http.log', '\n');

  if (response.status !== expectedStatus) {
    throw new Error(`${route.name} returned ${response.status}; expected ${expectedStatus}.`);
  }

  for (const text of route.includes) {
    if (!body.includes(text)) {
      throw new Error(`${route.name} did not include expected text: ${text}`);
    }
  }

  return body;
}

async function checkGeneratedAsset(baseUrl, themeSlug, relativePath, expectedText) {
  const response = await fetch(`${baseUrl}/wp-content/themes/${themeSlug}/${relativePath}`);
  const body = await response.text();

  appendLog('http.log', `\nasset ${relativePath}\nStatus: ${response.status}\n`);
  appendLog('http.log', body.slice(0, 1000));
  appendLog('http.log', '\n');

  if (response.status !== 200) {
    throw new Error(`Generated child asset ${relativePath} returned ${response.status}; expected 200.`);
  }

  if (expectedText && !body.includes(expectedText)) {
    throw new Error(`Generated child asset ${relativePath} did not include expected text: ${expectedText}`);
  }
}

async function checkGeneratedAssets(baseUrl, themeSlug) {
  await checkGeneratedAsset(baseUrl, themeSlug, 'dist/global/smoke.css', '.smoke-generated-global');
  await checkGeneratedAsset(baseUrl, themeSlug, 'dist/components/smoke-acf/css/smoke-acf.css', '.smoke-acf');
}

async function checkSearchPagination(baseUrl) {
  const firstPage = await checkRoute(baseUrl, {
    name: 'search pagination page 1',
    path: '/?s=PaginationProbe',
    includes: ['Search results for PaginationProbe', 'PaginationProbe result 11'],
  });
  appendLog('search-pagination.html', firstPage);
  const nextLink = firstPage.match(/<li\b[^>]*\bclass="[^"]*\bpagination__item--next\b[^"]*"[^>]*>\s*<a\b[^>]*\bhref="([^"]+)"/);
  if (!nextLink) {
    const navigation = firstPage.match(/<nav\b[^>]*>[\s\S]*?<\/nav>/g) || [];
    throw new Error(`Search page 1 did not render a next pagination link. Navigation: ${navigation.join('\n') || '(none)'}`);
  }

  // Decode HTML attribute ampersands once, as the browser does. A double-escaped
  // &#038; must remain a fragment in the URL so the regression cannot pass.
  const href = nextLink[1].replace(/&(?:amp|#0*38|#x0*26);/gi, '&');
  const nextUrl = new URL(href, baseUrl);
  if (
    nextUrl.origin !== baseUrl ||
    nextUrl.searchParams.get('s') !== 'PaginationProbe' ||
    nextUrl.searchParams.get('paged') !== '2' ||
    nextUrl.hash !== ''
  ) {
    throw new Error(`Search pagination should retain its search and request page 2; received ${nextUrl.href}.`);
  }

  const secondPage = await checkRoute(baseUrl, {
    name: 'search pagination page 2',
    path: `${nextUrl.pathname}${nextUrl.search}`,
    includes: ['Search results for PaginationProbe', 'PaginationProbe result 01'],
  });
  if (!/<span\b[^>]*\baria-current="page"[^>]*>\s*2\s*<\/span>/.test(secondPage)) {
    throw new Error('Following the search pagination link did not render current page 2.');
  }
  if (secondPage.includes('PaginationProbe result 11')) {
    throw new Error('Search page 2 incorrectly includes the first-page-only result.');
  }
}

async function checkChildBlockExtension(baseUrl, pagePath, themePath, baseline) {
  const childTemplate = path.join(themePath, 'templates', 'page.twig');
  const original = fs.existsSync(childTemplate) ? fs.readFileSync(childTemplate, 'utf8') : null;
  const before = '<!-- child content extension start -->';
  const after = '<!-- child content extension end -->';

  try {
    fs.mkdirSync(path.dirname(childTemplate), { recursive: true });
    fs.writeFileSync(
      childTemplate,
      `{% extends '@emulsify-tpl/page.twig' %}{% block content %}${before}{{ parent() }}${after}{% endblock %}`
    );
    const extended = await checkRoute(baseUrl, {
      name: 'child page block extension',
      path: pagePath,
      includes: [before, after, 'Smoke Page', 'Smoke page content'],
    });

    // Anonymous fixture responses are deterministic. Compare raw bytes, including
    // whitespace and WordPress hook output, without normalizing the document.
    if (
      extended.split(before).length !== 2 ||
      extended.split(after).length !== 2 ||
      extended.replace(before, '').replace(after, '') !== baseline
    ) {
      throw new Error('Child block extension must preserve every surrounding byte.');
    }
  }
  finally {
    if (original === null) {
      fs.rmSync(childTemplate, { force: true });
    }
    else {
      fs.writeFileSync(childTemplate, original);
    }
  }

  const restored = await checkRoute(baseUrl, {
    name: 'parent page after child extension cleanup',
    path: pagePath,
    includes: ['Smoke Page', 'Smoke page content'],
  });
  if (restored !== baseline) {
    throw new Error('Removing the child block extension must restore the parent page bytes.');
  }
}

async function renderRoutes(wpPath, content, baseUrl, port, themeSlug) {
  startServer(wpPath, port);
  await waitForServer(baseUrl);

  const pageIncludes = [
    'Smoke Page',
    'Smoke page content',
    `<article class="entry" id="post-${content.pageId}">`,
    '<h1 class="entry__title">Smoke Page</h1>',
    '<div class="entry__content">',
    `/wp-content/themes/${themeSlug}/dist/global/smoke.css`,
    `/wp-content/themes/${themeSlug}/dist/components/smoke-acf/css/smoke-acf.css`,
  ];
  const routes = [
    { name: 'home', path: '/', includes: ['Emulsify Smoke', 'Smoke Post'] },
    { name: 'page', path: `/?page_id=${content.pageId}`, includes: pageIncludes },
    {
      name: 'single',
      path: `/?p=${content.postId}`,
      includes: [
        'Smoke Post',
        'Smoke post content',
        'Smoke comment content',
        'Grouped project component via colon',
        'Grouped project component via namespace',
      ],
    },
    { name: 'archive', path: `/?cat=${content.categoryId}`, includes: ['Smoke Category', 'Smoke Post'] },
    { name: 'search', path: '/?s=Smoke', includes: ['Search results for Smoke', 'Smoke Post'] },
    { name: 'author', path: `/?author=${content.authorId}`, includes: ['Archive of Smoke Author', 'Smoke Post'] },
    { name: '404', path: '/?p=9999999', status: 404, includes: ['Page not found'] },
  ];

  let parentPage = null;
  for (const route of routes) {
    const body = await checkRoute(baseUrl, route);
    if (route.name === 'page') {
      parentPage = body;
    }
  }

  await checkChildBlockExtension(
    baseUrl,
    `/?page_id=${content.pageId}`,
    path.join(wpPath, 'wp-content', 'themes', themeSlug),
    parentPage
  );
  await checkSearchPagination(baseUrl);
  await checkGeneratedAssets(baseUrl, themeSlug);

  if (auditAccessibility) {
    const archiveCount = Number(wp(wpPath, ['post', 'list', '--post_type=post', '--post_status=publish', `--cat=${content.categoryId}`, '--format=count']));
    if (!Number.isInteger(archiveCount) || archiveCount < 12) {
      throw new Error(`Rendered accessibility archive needs at least 12 published posts; found ${archiveCount}.`);
    }
    wp(wpPath, ['option', 'update', 'posts_per_page', '1']);
    const themeDir = path.join(wpPath, 'wp-content', 'themes', themeSlug);
    run('Install generated child accessibility dependencies', 'npm', [
      'install', '--ignore-scripts', '--package-lock=false',
    ], {
      cwd: themeDir,
      env: { ...process.env, PUPPETEER_SKIP_DOWNLOAD: 'true' },
    });
    run('Verify rendered accessibility failure gates', 'node', [
      '--test', path.join(repoRoot, '.github/scripts/rendered-a11y.test.cjs'),
    ], { env: { ...process.env, EMULSIFY_A11Y_THEME: themeDir } });
    const { auditRenderedPages } = require('./rendered-a11y.cjs');
    const outputDir = path.resolve(process.env.WP_SMOKE_A11Y_OUTPUT || path.join(repoRoot, '.artifacts', 'wordpress-a11y'));
    const pageUrl = `${baseUrl}/?page_id=${content.pageId}`;
    const singleUrl = `${baseUrl}/?p=${content.postId}`;
    const archiveUrl = `${baseUrl}/?cat=${content.categoryId}`;
    const routes = [
      { name: 'home', url: `${baseUrl}/`, expectedText: 'Smoke Post' },
      { name: 'page', url: pageUrl, expectedText: ['Smoke Page', 'Smoke page content'], currentMenuHref: pageUrl },
      { name: 'single', url: singleUrl, expectedText: ['Smoke Post', 'Smoke post content'], currentMenuHref: singleUrl },
      { name: 'archive', url: archiveUrl, expectedText: ['Smoke Category', 'Smoke Post'], currentMenuHref: archiveUrl },
      { name: 'search', url: `${baseUrl}/?s=Smoke`, expectedText: ['Search results for Smoke', 'Smoke Page'] },
      {
        name: 'archive-page-2',
        url: `${archiveUrl}&paged=2`,
        expectedText: ['Smoke Category', 'PaginationProbe result 11'],
        paginationPage: 2,
        currentMenuHref: archiveUrl,
      },
    ];
    fs.mkdirSync(outputDir, { recursive: true });
    fs.writeFileSync(path.join(outputDir, 'routes.json'), `${JSON.stringify(routes, null, 2)}\n`);
    await auditRenderedPages({ themeDir, outputDir, routes });
    log(`Rendered accessibility reports: ${outputDir}`);
  }
}

function assertCleanDebugLog(wpPath) {
  const debugLog = path.join(wpPath, 'wp-content', 'debug.log');
  if (fs.existsSync(debugLog) && fs.readFileSync(debugLog, 'utf8').trim() !== '') {
    throw new Error(`WordPress fixture debug log must remain clean. See ${debugLog}.`);
  }
}

function dumpLogs(wpPath) {
  if (!logDir) {
    return;
  }

  const files = [
    path.join(logDir, 'commands.log'),
    path.join(logDir, 'server.log'),
    path.join(logDir, 'http.log'),
    path.join(wpPath || '', 'wp-content', 'debug.log'),
  ];

  console.error('\nWordPress Smoke Logs');
  for (const file of files) {
    if (!file || !fs.existsSync(file)) {
      continue;
    }

    const contents = fs.readFileSync(file, 'utf8');
    const tail = contents.slice(-12000);
    console.error(`\n--- ${file} ---\n${tail}`);
  }
}

async function main() {
  if (process.env.WP_SMOKE_SKIP === '1') {
    unavailable('WP_SMOKE_SKIP=1');
  }

  ensureTools();

  workDir = fs.mkdtempSync(path.join(os.tmpdir(), 'emulsify-wordpress-smoke-'));
  logDir = path.join(workDir, 'logs');
  fs.mkdirSync(logDir, { recursive: true });

  const wpPath = path.join(workDir, 'wordpress');
  const env = databaseEnv();
  currentDatabaseEnv = env;
  const port = Number(process.env.WP_SMOKE_PORT) || await getAvailablePort();
  const baseUrl = `http://${host}:${port}`;

  log(`Fixture directory: ${workDir}`);
  createDatabase(env);

  run('Download WordPress core', 'wp', [
    '--allow-root',
    `--path=${wpPath}`,
    'core',
    'download',
    `--version=${wordpressVersion}`,
    '--force',
  ]);
  const resolvedWordpressVersion = wp(wpPath, ['core', 'version']);
  log(`WordPress version: requested ${wordpressVersion}; resolved ${resolvedWordpressVersion}`);

  run('Create wp-config.php', 'wp', [
    '--allow-root',
    `--path=${wpPath}`,
    'config',
    'create',
    `--dbname=${env.WP_SMOKE_DB_NAME}`,
    `--dbuser=${env.WP_SMOKE_DB_USER}`,
    `--dbpass=${env.WP_SMOKE_DB_PASSWORD}`,
    `--dbhost=${env.WP_SMOKE_DB_HOST}:${env.WP_SMOKE_DB_PORT}`,
    '--skip-check',
  ]);
  configureDebugLog(wpPath);

  const themesDir = path.join(wpPath, 'wp-content', 'themes');
  const { parentTheme } = copyThemes(themesDir);
  installThemeDependencies(parentTheme);
  const generatedChildSlug = 'smoke-generated';
  const generatedChild = path.join(themesDir, generatedChildSlug);

  wp(wpPath, [
    'core',
    'install',
    `--url=${baseUrl}`,
    '--title=Emulsify Smoke',
    '--admin_user=admin',
    '--admin_password=admin-password-123',
    '--admin_email=admin@example.test',
  ]);
  wp(wpPath, ['theme', 'is-installed', 'emulsify']);
  wp(wpPath, ['theme', 'is-installed', 'whisk']);
  wp(wpPath, ['theme', 'activate', 'whisk']);
  wp(wpPath, ['cli', 'has-command', 'emulsify']);
  wp(wpPath, ['emulsify', 'Smoke Generated', `--machine-name=${generatedChildSlug}`, '--activate']);
  assertGeneratedChildTheme(generatedChild, generatedChildSlug);
  installGeneratedChildFixtures(generatedChild);
  wp(wpPath, ['theme', 'is-installed', generatedChildSlug]);

  const activeStylesheet = wp(wpPath, ['option', 'get', 'stylesheet']);
  if (activeStylesheet !== generatedChildSlug) {
    throw new Error(`Generated child theme was not activated. Active stylesheet is "${activeStylesheet}".`);
  }

  installNativeBlockFixture(generatedChild);
  runAcfDiscoveryWithoutAcf(wpPath);
  runNativeBlockDiscovery(wpPath);
  installAcfStub(wpPath);
  runAcfDiscoveryWithStub(wpPath);
  wp(wpPath, [
    'eval',
    'if ( ! class_exists( "\\\\Timber\\\\Timber" ) ) { fwrite( STDERR, "Timber is not loaded.\\n" ); exit( 1 ); }',
  ]);

  const content = createFixtureContent(wpPath);
  await renderRoutes(wpPath, content, baseUrl, port, generatedChildSlug);
  stopServer();
  assertCleanDebugLog(wpPath);

  log('Generated and activated a child without Twig templates, checked block discovery, loaded assets, rendered parent page fallbacks and child block extensions without changing surrounding bytes, rendered home, single, archive, search, author, and 404 routes, and followed search pagination to page 2 with a clean debug log.' + (auditAccessibility ? ' Six rendered routes passed the WCAG accessibility audit.' : ''));
  dropDatabase(env);

  if (!keepFixture) {
    fs.rmSync(workDir, { force: true, recursive: true });
  }
  else {
    log(`Keeping fixture directory: ${workDir}`);
  }
}

main()
  .catch((error) => {
    console.error(`WordPress fixture smoke failed: ${error.message}`);
    dumpLogs(workDir ? path.join(workDir, 'wordpress') : null);

    if (server) {
      stopServer();
    }

    if (currentDatabaseEnv) {
      dropDatabase(currentDatabaseEnv);
    }

    if (workDir && !keepFixture) {
      fs.rmSync(workDir, { force: true, recursive: true });
    }

    process.exit(1);
  })
  .finally(() => {
    if (server) {
      stopServer();
    }
  });
