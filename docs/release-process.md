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
4. Push the release PR branch, then follow the
   [local dry-run requirements](#local-release-dry-runs) and run
   `npm run publish-test -- --no-ci`. Publishing remains restricted to `main`.
   Semantic-release must compute exactly
   the prepared version from the Conventional Commits. A mismatch fails with
   an actionable message; resolve the version or commit classification before
   merging.
5. Merge the release PR to `main` after readiness checks pass. The publishing
   workflow repeats the checks and publishes only when its computed version
   matches the prepared version.
6. After publishing, rotate `docs/release-notes-next.md` to an untagged draft
   heading and release title, and update or remove its latest-tag claim. The
   contract rejects the shipped draft on subsequent runs until it is rotated.

Keep `main` at the merge commit until the release finishes. Do not merge or
push other changes while release readiness and publishing are running.
Contributor updates are maintained by hand in reviewed pull requests; the
contributors bot workflow has been removed so it cannot advance `main`.

For a prepared release in CI, the runner compares its checkout with the live
`origin/main` before invoking semantic-release, including during its dry run.
If `main` moved, start a new **WordPress Release** workflow
(`semantic-release.yml`) run with `workflow_dispatch`, selecting **main**.
This runs readiness and publishing against the new tip. Do not use **Re-run
jobs** on the old run: it retains the old commit. The preflight detects drift
but cannot prevent a later push, so keep `main` unchanged for the new run too.
Local dry runs on release branches and valid unprepared-release skips are
unaffected.

When `package.json` still equals the latest stable tag, no release is prepared.
In CI, the guarded runner first verifies that the tag has a published stable
GitHub release with a nonempty, uploaded `emulsify.zip`. It then skips publishing
successfully, even if newer commits would otherwise trigger semantic-release.
Local unprepared-release skips need no GitHub token. Ordinary change PRs do not
need to bump versions; the release PR prepares them together.

## Recovering a partial release

Semantic-release pushes the tag before uploading and publishing the GitHub
release. The release workflow checks publication state with read-only access
before readiness, so stale draft documentation cannot hide a missing release or
ZIP. A failure prints the relevant `gh release upload` or `gh release edit`
commands. A read-only token may also see a draft as missing; inspect it with a
maintainer account before creating anything.

Use the ZIP and checksum from the successful readiness run for that exact tag.
Verify the checksum before uploading. If that artifact has expired, check out
the tag in a full clone and rebuild it with `npm run build:dist`. With WP-CLI,
MySQL, Chrome and the `WP_SMOKE_DB_*` settings configured, validate that ZIP:

```sh
WP_SMOKE_REQUIRED=1 WP_SMOKE_A11Y=1 WP_SMOKE_THEME_ARCHIVE=dist-artifact/emulsify.zip node .github/scripts/wordpress-fixture-smoke.cjs
```

Use the standalone fixture for this already-tagged recovery: the unreleased-draft
contract intentionally rejects the historical draft notes at the tag. Do not
edit the tag's sources, rebuild from a newer branch tip, move the tag, or prepare
another version to hide a partial release.
Review release notes before publishing a recovered draft. After recovery,
rotate the release draft as described above and start a new workflow dispatch
on `main`; the publication check should pass and publishing should skip.

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
npm run build:dist
WP_SMOKE_REQUIRED=1 WP_SMOKE_THEME_ARCHIVE=dist-artifact/emulsify.zip npm run release:check
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

## Local release dry runs

Before `npm run publish-test -- --no-ci`:

- Use an attached local branch with full history and tags. Detached `HEAD`
  checkouts cannot supply the release branch.
- Push that branch to `origin` first, fetch it, and make sure the checkout is
  not behind its remote branch. Push new commits before repeating the dry run.
- Set `GH_TOKEN` or `GITHUB_TOKEN` to a GitHub token with push permission for
  this repository. The GitHub plugin verifies that permission even in dry runs;
  an authenticated Git CLI alone does not supply the environment variable.
- Build `dist-artifact/emulsify.zip` with `npm run build:dist` and test that exact
  archive with `WP_SMOKE_THEME_ARCHIVE=dist-artifact/emulsify.zip`. Configure the
  disposable database with the `WP_SMOKE_DB_*` variables used in
  `.github/workflows/theme-readiness.yml`.

The dry run analyzes the current pushed release branch and must compute the
prepared version exactly. It creates no tags or releases. Use the guarded
`publish-test` command; the root npm package is private and exposes no raw
`semantic-release` script. A missing or empty ZIP fails release verification
before any tag is created, including during dry runs.

## Installable release artifact

Run `npm run build:dist` to create `dist-artifact/emulsify.zip`. Commit source
changes before building: the build reads one immutable `HEAD` commit, including
Composer metadata and Whisk payload, and excludes staged or working-tree edits.
It refuses committed symlinks before installing dependencies. Git objects are
read explicitly so `export-ignore` cannot omit the lockfile needed for the build.

The build stages an explicit parent-theme runtime file list under a top-level
`emulsify/` directory and installs the committed versions pinned in
`composer.lock` with `--no-dev --optimize-autoloader` directly into that tree.
The ZIP bundles `vendor/` and can be installed without running Composer after
download.

The archive includes the runtime PHP entry points, `includes/`, `templates/`, `src/`, `style.css`, `theme.json`, the screenshot, license, README, linked user documentation, production Composer dependencies, and the tracked `whisk/` child starter required by `wp emulsify`. It excludes repository metadata, `.github/`, root npm and Composer metadata, `node_modules/`, development configuration, smoke tests, and Whisk's standalone `.cli` hook, caches, dependencies, and build output.

Composer/GitHub distributions are a separate channel. They retain `composer.json` so the consuming application can resolve dependencies, and `.gitattributes` removes repository-only CI, release scripts, and root npm tooling while retaining user documentation. A direct Git clone remains a full development checkout. For a manual WordPress upload, always use the named `emulsify.zip` release asset rather than GitHub's automatic **Source code** archive.

Read-only readiness builds the archive once in its PHP 8.3 practical lane.
WordPress fixture jobs download that archive and verify its SHA-256 checksum
before testing it. The publishing job downloads the same artifact and checks
the checksum before its dry run and publish command. It installs no PHP or
Composer and does not rebuild the ZIP. `@semantic-release/github` attaches it
as **Emulsify WordPress theme (with dependencies)**. WordPress.org SVN
deployment remains a future step after the project has a WordPress.org profile;
it is not performed by this workflow.

For pre-release client testing, the PHP 8.3 `Practical theme readiness` lane also builds the archive and uploads `emulsify.zip` directly for 14 days. This provides the packaged theme before a public GitHub Release exists without publishing an untagged stable release or wrapping the installable ZIP inside another archive.

## CI

The WordPress Theme Readiness workflow runs on pull requests, manual dispatch, and a weekly schedule.

Every configured pull request runs two independently visible fast jobs:

- `Practical theme readiness`: clean root npm installation, Composer validation,
  blocking `composer audit --locked --no-dev` for the dependencies bundled in
  the ZIP, root npm audits, the practical `pr:check` suite, and static
  `release:check` assertions. The root runtime npm audit alone covers no shipped
  packages because the root package contains only development dependencies.
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
the publishing job receives release writes. Its checkout also disables
persisted credentials, npm lifecycle scripts stay disabled, and the token is
provided only to the release commands. Its dry run runs immediately
before publishing because semantic-release verifies push permission even in
dry-run mode. Contributor updates use reviewed pull requests rather than a
bot with repository-content write permission. Adding issues to the organization
project still requires the configured Projects token. See the
[dated dependency audit](dependency-audit-2026-10-23.md) for remaining
development-tool findings and their owners.

The Whisk job creates an ephemeral lock during its dependency install and logs
`npm audit` findings without blocking readiness. This covers the installed
dependency tree; `whisk:install` intentionally does not commit a starter lockfile.
Every concrete release job has an explicit timeout. The reusable-workflow call
uses the limits on its called jobs, since GitHub does not accept a timeout on a
job that only calls another workflow.

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

The semantic-release workflow calls the full WordPress Theme Readiness workflow
with release coverage enabled. It waits for PHP 8.3, 8.4 and 8.5 practical checks,
PHP analysis, required generation parity, `pr:check`, minimum/latest WordPress
fixtures with rendered accessibility, and the Whisk tests/Storybook/accessibility
job. Nightly WordPress runs too and retains its advisory policy. Release dispatch
always enables this coverage regardless of the ordinary manual-input defaults.
After readiness succeeds, the publishing job verifies the tested artifact and
runs a semantic-release dry run before publishing. Parsed YAML contracts and
negative tests reject skipped smoke paths, failure bypasses and weakened needs.

## License

The 2.x branch uses GPL-2.0-only metadata across npm, Composer, WordPress theme headers, and repository license files.
