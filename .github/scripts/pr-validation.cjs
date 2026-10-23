#!/usr/bin/env node

// Practical PR gate for checks that do not need a database-backed WordPress
// install. PHP analysis and CI-seeded Whisk build/a11y coverage have dedicated
// workflow jobs so their status is visible independently on pull requests.

const childProcess = require('child_process');
const path = require('path');

const repoRoot = path.resolve(__dirname, '../..');

function run(label, command, args) {
  console.log(`\n[pr-validation] ${label}`);
  const result = childProcess.spawnSync(command, args, {
    cwd: repoRoot,
    env: process.env,
    stdio: 'inherit',
  });

  if (result.status !== 0) {
    process.exit(result.status || 1);
  }
}

run('Validate Composer metadata', 'composer', ['validate', '--no-check-publish', '--strict']);
run('Validate documented npm commands', 'npm', ['run', 'docs:check-commands']);
run('Install Composer dependencies for Twig smoke coverage', 'composer', [
  'install',
  '--no-interaction',
  '--no-progress',
  '--prefer-dist',
]);
run('Run Bootstrap loader smoke test', 'npm', ['run', 'smoke:bootstrap-loader']);
run('Run ACF Local JSON smoke test', 'npm', ['run', 'smoke:acf-json']);
run('Run asset manifest smoke test', 'npm', ['run', 'smoke:asset-manifest']);
run('Run Twig attribute helper smoke test', 'npm', ['run', 'smoke:attributes']);
run('Run block scoped asset smoke test', 'npm', ['run', 'smoke:block-assets']);
run('Run child theme generator smoke test', 'npm', ['run', 'smoke:child-theme-generator']);
run('Run component locator smoke test', 'npm', ['run', 'smoke:component-locator']);
run('Run core block Twig renderer smoke test', 'npm', ['run', 'smoke:core-block-twig']);
run('Run editor enhancements smoke test', 'npm', ['run', 'smoke:editor-enhancements']);
run('Run editor policy smoke test', 'npm', ['run', 'smoke:editor-policy']);
run('Run pattern registry smoke test', 'npm', ['run', 'smoke:patterns']);
run('Run parent theme filter smoke test', 'npm', ['run', 'smoke:theme-filters']);
run('Run Twig autoescape smoke test', 'npm', ['run', 'smoke:twig-autoescape']);
run('Run parent template extension smoke test', 'npm', ['run', 'smoke:template-extension']);
run('Run fallback navigation smoke test', 'npm', ['run', 'smoke:navigation']);
run('Run Twig switch smoke test', 'npm', ['run', 'smoke:twig-switch']);
run('Run Twig project namespace smoke test', 'npm', ['run', 'smoke:twig-project-namespace']);
run('Install Whisk dependencies', 'npm', ['run', 'whisk:install']);
run('Verify lint and format failure propagation', 'npm', ['run', 'test:lint-wrappers']);
run('Run generated project Jest smoke test', 'npm', ['run', 'smoke:jest-project']);
run('Run accessibility runner tests', 'npm', ['run', 'test:a11y-runner']);
run('Run WordPress starter init smoke test', 'npm', ['run', 'smoke:starter-init']);
