const fs = require('node:fs');
const path = require('node:path');

const parserOpts = {
  // semantic-release's default parser does not treat "feat!:" consistently
  // across the release lines this project supports, so keep the breaking-change
  // header patterns explicit.
  headerPattern: /^(\w*)(?:\(([\w$.\-*/ ]*)\))?!?: (.*)$/,
  headerCorrespondence: ['type', 'scope', 'subject'],
  breakingHeaderPattern: /^(\w*)(?:\(([\w$.\-*/ ]*)\))?!: (.*)$/,
  noteKeywords: ['BREAKING CHANGE', 'BREAKING CHANGES', 'BREAKING-CHANGE'],
};

const preparedReleaseGuard = {
  verifyConditions(pluginConfig, { cwd = __dirname }) {
    const archive = fs.statSync(path.join(cwd, 'dist-artifact/emulsify.zip'), { throwIfNoEntry: false });
    if (!archive?.isFile() || archive.size === 0) {
      throw new Error('Required release asset dist-artifact/emulsify.zip is missing or empty. Run npm run build:dist and test that exact ZIP before publishing.');
    }
  },
  verifyRelease(pluginConfig, { cwd = __dirname, nextRelease }) {
    const preparedVersion = JSON.parse(fs.readFileSync(path.join(cwd, 'package.json'), 'utf8')).version;

    if (nextRelease.version !== preparedVersion) {
      throw new Error(
        `Prepared package.json version ${preparedVersion}, but semantic-release computed ${nextRelease.version}. Update the release PR's package.json and all derived version files to match Conventional Commits, then rerun npm run release:check.`,
      );
    }
  },
};

module.exports = {
  tagFormat: '${version}',
  branches: ['main'],
  repositoryUrl: 'git@github.com:emulsify-ds/emulsify-wordpress.git',
  plugins: [
    ['@semantic-release/commit-analyzer', { preset: 'angular', parserOpts }],
    [
      '@semantic-release/release-notes-generator',
      {
        preset: 'angular',
        parserOpts,
        writerOpts: {
          commitsSort: ['subject', 'scope'],
        },
      },
    ],
    preparedReleaseGuard,
    [
      '@semantic-release/github',
      {
        assets: [
          {
            path: 'dist-artifact/emulsify.zip',
            label: 'Emulsify WordPress theme (with dependencies)',
          },
        ],
      },
    ],
  ],
};
