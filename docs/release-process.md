# Release process

This repository uses semantic-release with Conventional Commits. Releases are prepared from `main`, and Git tags use non-prefixed SemVer such as `2.0.0`.

## Repository history

Release checks require full Git history and tags. Use a full clone, run
`git fetch origin --tags` before preparing a release, and use
`git fetch --unshallow --tags origin` first if the checkout is shallow. CI jobs
that run release checks use `fetch-depth: 0`. The version contract fails clearly
if no stable tag is available; it does not invent a release baseline.

## Commit messages

Use commit messages that describe the public change:

- `fix: correct timber attribute helpers` creates a patch release.
- `feat: add native block registration` creates a minor release.
- `feat!: change generated child theme structure` or a `BREAKING CHANGE:` footer creates a major release.

## Version strategy

| Commit type | Release impact | Example |
|---|---|---|
| `fix:` | patch | `fix: correct timber attribute helpers` |
| `perf:` | patch | `perf: memoize component discovery` |
| `feat:` | minor | `feat: add native block registration` |
| `<type>!:` or `BREAKING CHANGE:` footer | major | `feat!: change generated child theme structure` |
| `docs:`, `test:`, `ci:`, `chore:`, `refactor:`, `style:` | none | `docs: clarify asset loading` |

Root `package.json` is the single source of truth for the release version. Every
other version surface is compared against it rather than restating a literal:

- `style.css` `Version`
- `whisk/package.json` version
- `whisk/style.css` `Version`
- `whisk/project.emulsify.json` `project.generatedFromVersion`
- both generators, which read the version at generation time rather than
  embedding it

`release:check` fails when any of these disagree, so a missed bump cannot ship
inconsistent generated-theme lineage metadata.

The root version must be stable `x.y.z`: either the latest stable tag, or an
untagged next patch, minor, or major of it. For example, after `2.0.0`, the
prepared version may be `2.0.1`, `2.1.0`, or `3.0.0`. Skipped versions, older
tagged versions, and prereleases are rejected. Release-note draft headings must
name an untagged version, and claims about the latest or current release must
match the latest stable tag.

## Release PR procedure

1. Start a release PR from an up-to-date `origin/main` with full history and
   tags. Review the Conventional Commits since the latest stable tag to choose
   the upcoming version.
2. Bump root `package.json` to that prepared version. Update `style.css`
   `Version`, `whisk/package.json` version, `whisk/style.css` `Version`, and
   `whisk/project.emulsify.json` `project.generatedFromVersion` to match. Keep
   lockfile package metadata aligned when applicable. No release plugin writes
   these versions for you.
3. Update `docs/release-notes-next.md` and `UPGRADE.md`, then run the local
   readiness checks below, leaving `publish-test` for step 4. Include the full
   `npm run release:check` with WP-CLI and
   MySQL available. The release job packages these committed files, including
   their version strings, into the ZIP.
4. Push the release PR branch, then run `npm run publish-test -- --no-ci`.
   This explicit local dry run analyzes the current pushed branch; publishing
   remains restricted to `main`. Semantic-release must compute exactly
   the prepared version from the Conventional Commits. A mismatch fails with
   an actionable message; resolve the version or commit classification before
   merging.
5. Merge the release PR to `main` after readiness checks pass. The publishing
   workflow repeats the checks and publishes only when its computed version
   matches the prepared version.
6. After publishing, rotate `docs/release-notes-next.md` to an untagged draft
   heading and release title, and update or remove its latest-tag claim. The
   contract rejects the shipped draft on subsequent runs until it is rotated.

When `package.json` still equals the latest stable tag, no release is prepared.
The guarded runner skips publishing successfully, even if newer commits would
otherwise trigger semantic-release. Ordinary change PRs do not need to bump
versions; the release PR prepares them together.

## Release checks

Confirm each of the following before publishing:

- Root `package.json`, `style.css`, `whisk/package.json`, `whisk/style.css`, and
  `whisk/project.emulsify.json` all describe the same release version.
- `LICENSE`, `package.json`, `composer.json`, `style.css`, and
  `whisk/package.json` all identify the project as `GPL-2.0-only`.
- Packagist lists `emulsify-ds/emulsify-wordpress-theme` as maintained, the
  release tag is indexed, and a clean Composer install lands at `emulsify/`.
- README and `UPGRADE.md` describe the current parent theme workflow, the
  WordPress and PHP baselines, Node.js expectations, and the Vite build workflow.
- The [sister-project parity contract](./sister-project-parity.md) stays linked
  from the README and still reflects the shared Emulsify Drupal/WordPress model.
- Whisk remains a generation-only starter and generated child themes keep
  `Template: emulsify`. Review the
  [generated child theme contract](./generated-child-theme-contract.md).
- Generated child themes include a project-specific `README.md` plus
  `docs/development.md`, `docs/upgrading.md`, and `docs/support-information.md`,
  and the documentation checker validates their npm commands in both the Whisk
  source and real generated output.
- Both generation paths produce the same child theme file tree.
- `docs/release-notes-next.md` describes the release being published.

## Local checks

Run:

```sh
npm run docs:check-commands
npm run lint:php
npm run test:generated-theme
npm run smoke:generation-parity
npm run pr:check
npm run release:check
npm run publish-test -- --no-ci
```

`docs:check-commands` verifies that documented generated-theme and maintainer
commands still exist in the package where readers are instructed to run them.

`lint:php` is the local PHPCS and PHPStan entry point. Install Composer development dependencies first; use `npm run lint:php:fix` for PHPCBF auto-fixes.

`test:generated-theme` runs focused Node tests that generate a throwaway child theme and then mutate it to prove each class of contract failure is detected.

`smoke:generation-parity` generates the same identities through the WP-CLI command and the standalone starter hook, validates both against the generated child theme contract, and requires their file trees to match. It needs a PHP binary and skips with a warning when none is available; set `EMULSIFY_PARITY_REQUIRED=1` in CI so a skip fails instead.

`pr:check` runs the practical, stubbed pull request suite:

- Composer metadata validation.
- Composer dependency install for Twig smoke coverage.
- ACF Local JSON smoke test.
- Twig attribute helper smoke test.
- Twig switch tag smoke test.
- Child theme generator smoke test.
- Component locator smoke test.
- Parent theme filter smoke test.
- Whisk dependency installation.

`pr:check` does not build the empty Whisk starter. The dedicated extended CI job copies `.github/fixtures/whisk-a11y` into `whisk/src/components` before building, so a real Twig story and Vite entry exercise Core without adding a component system to the committed starter.

`release:check` adds static release-readiness checks and the full WordPress fixture smoke path. In CI, the fixture extracts the built `emulsify.zip` into an isolated WordPress site, confirms the packaged `wp emulsify` command, generates and activates a child theme from the packaged Whisk source, adds neutral built asset and block fixtures, renders frontend routes through Timber, fetches those built child assets, and checks ACF/Twig and native `block.json` discovery. It requires WP-CLI and MySQL. It skips gracefully when WP-CLI or database settings are unavailable unless `WP_SMOKE_REQUIRED=1` is set.

Use `npm run release:check -- --skip-smoke` for static checks and focused
contract tests without runtime smoke commands. This is a quick local check;
the release PR and publishing workflow still require the full fixture path.

## Installable release artifact

Run `npm run build:dist` to create `dist-artifact/emulsify.zip`. The build stages an explicit parent-theme runtime file list under a top-level `emulsify/` directory, copies only tracked Whisk generator files, and installs the versions pinned in `composer.lock` with `--no-dev --optimize-autoloader` directly into that staged tree. The ZIP therefore bundles `vendor/` and can be installed without running Composer after download.

The archive includes the runtime PHP entry points, `includes/`, `templates/`, `src/`, `style.css`, `theme.json`, the screenshot, license, README, linked user documentation, production Composer dependencies, and the tracked `whisk/` child starter required by `wp emulsify`. It excludes repository metadata, `.github/`, root npm and Composer metadata, `node_modules/`, development configuration, smoke tests, and Whisk's standalone `.cli` hook, caches, dependencies, and build output.

Composer/GitHub distributions are a separate channel. They retain `composer.json` so the consuming application can resolve dependencies, and `.gitattributes` removes repository-only CI, release scripts, and root npm tooling while retaining user documentation. A direct Git clone remains a full development checkout. For a manual WordPress upload, always use the named `emulsify.zip` release asset rather than GitHub's automatic **Source code** archive.

The semantic-release publish job sets up PHP 8.3 and Composer, builds this archive, and lets `@semantic-release/github` attach it to the GitHub release as **Emulsify WordPress theme (with dependencies)**. WordPress.org SVN deployment remains a future step after the project has a WordPress.org profile; it is not performed by this workflow.

For pre-release client testing, the PHP 8.3 `Practical theme readiness` lane also builds the archive and uploads `emulsify.zip` directly for 14 days. This provides the packaged theme before a public GitHub Release exists without publishing an untagged stable release or wrapping the installable ZIP inside another archive.

## CI

The WordPress Theme Readiness workflow runs on pull requests, manual dispatch, and a weekly schedule.

Every configured pull request runs two independently visible fast jobs:

- `Practical theme readiness`: clean root npm installation, Composer validation, npm audits, the practical `pr:check` suite, and static `release:check` assertions.
- `PHP coding standards and static analysis`: PHP 8.3, Composer development dependencies, PHPCS, and PHPStan through `npm run lint:php`.

The practical matrix blocks on PHP 8.3, 8.4, and 8.5. PHP 8.5 was promoted
after the locked Composer install, PHP analysis, and practical suite passed on
8.5.11. No platform-requirement bypass is used for these blocking jobs.

Pull requests targeting `main` or `release-2.x` additionally run the two extended jobs:

- `WordPress fixture smoke`: the built installable ZIP, MySQL, WP-CLI, Timber, packaged generator/Whisk coverage, generated-child activation, block discovery, assets, and home/page/single/archive/search/author/404 rendering with `WP_SMOKE_REQUIRED=1`.
- `Extended Whisk Storybook and a11y`: an explicit Chrome setup, a CI-only component seed, the Core 4 Vite and Storybook builds, and an axe audit of the discovered story.

The WordPress matrix blocks on the declared minimum (6.8) and `latest`.
WP-CLI resolves `latest` at run time and the fixture logs the actual downloaded
version. Nightly is advisory coverage for the next release. On October 23,
2026, the packaged fixture and all seven rendered accessibility audits passed
on WordPress 7.1.3; `Tested up to` therefore names 7.1. Raise that header only
after a green fixture run for the newer stable line. The
`readiness-matrix-contract.cjs` check and its negative tests run in `pr:check`
and `release:check`, preventing required legs from becoming advisory or
silently bypassing fixture checks.

Every action is pinned to a full commit SHA with a release-version comment;
weekly GitHub Actions Dependabot updates maintain those pins. Validation jobs
use read-only credentials and disable persisted checkout credentials. Only
the publishing job receives release writes. Its dry run runs immediately
before publishing because semantic-release verifies push permission even in
dry-run mode. Contributor automation separately receives repository-content
write permission and uses `GITHUB_TOKEN`; adding issues to the organization
project still requires the configured Projects token. See the
[dated dependency audit](dependency-audit-2026-10-23.md) for remaining
development-tool findings and their owners.

Weekly scheduled runs repeat both extended jobs. Manual dispatch runs the WordPress fixture when `wordpress_fixture` is enabled and the Whisk build/audit when `extended_checks` is enabled. The workflow concurrency group cancels superseded pull-request runs, and both extended jobs have explicit runtime limits.

## Merge and release gate

The two fast pull-request jobs run automatically for every configured target branch. All four jobs run for changes targeting `main` or `release-2.x`. For an additional final rerun before merging a release PR:

1. Open GitHub Actions for `emulsify-ds/emulsify-wordpress`.
2. Select the `WordPress Theme Readiness` workflow.
3. Choose `Run workflow`.
4. Select the release PR branch.
5. Keep `wordpress_fixture` enabled. It defaults to enabled for manual runs.
6. Enable `extended_checks` to rerun the CI-seeded Storybook and accessibility audit.

Success means `Practical theme readiness`, `PHP coding standards and static analysis`, `WordPress fixture smoke`, and `Extended Whisk Storybook and a11y` pass. The fixture job installs WP-CLI, starts MySQL, sets `WP_SMOKE_REQUIRED=1`, and runs `npm run release:check` against the isolated WordPress fixture. Record the successful workflow run in the release PR before merging.

The semantic-release workflow remains release-gated. Read-only release readiness requires the full WordPress fixture smoke path with WP-CLI and MySQL. After readiness succeeds, the publishing job runs a semantic-release dry run before the actual publish command. Release publishing must not proceed when the full fixture path is skipped or failed.

## License

The 2.x branch uses GPL-2.0-only metadata across npm, Composer, WordPress theme headers, and repository license files.
