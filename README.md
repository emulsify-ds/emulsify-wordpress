![Emulsify Design System](https://github.com/emulsify-ds/.github/blob/6bd435be881bd820bddfa05d88905efe29176a0a/assets/images/header.png)

# Emulsify WordPress

Emulsify WordPress is a Timber-first WordPress parent theme for teams building component-driven sites with Emulsify Core 4, Vite, Storybook, and Twig.

The parent theme provides the WordPress runtime: theme setup, Timber bootstrapping, Twig namespaces and helpers, template fallbacks, asset loading, and optional block registration. Generated child themes provide the project layer: components, templates, source Sass and JavaScript, compiled assets, and site-specific overrides.

## Installation

### Composer (primary)

Track the parent theme through the site project's Composer configuration and install it as `emulsify`:

```sh
composer config allow-plugins.composer/installers true
composer require emulsify-ds/emulsify-wordpress-theme:^2.0
```

The Composer package uses `installer-name: emulsify`, so Composer Installers places it under the required parent-theme slug. Composer-based applications should also require `timber/timber` from the application-level Composer project so Timber loads before WordPress activates the theme. If the parent theme owns its dependencies instead, run `composer install` inside the installed `emulsify` directory.

### Manual release ZIP

Download the `emulsify.zip` asset from the matching [GitHub release](https://github.com/emulsify-ds/emulsify-wordpress/releases), then upload it through Appearance > Themes > Add New > Upload Theme. The release ZIP already includes production Composer dependencies under `vendor/` and the bundled Whisk generator source, so a manual installation does not need to run Composer.

The archive installs into the required `emulsify/` directory. A WordPress.org listing and SVN deployment are planned as a future release step; they are not part of the current release workflow.

Before a public release is available, the PHP 8.3 `Practical theme readiness` job uploads the built `emulsify.zip` directly as a short-lived workflow artifact for client testing.

Use the named `emulsify.zip` asset, not GitHub's automatically generated **Source code** archives, for a manual WordPress upload. Composer distributions retain `composer.json` and let the site project resolve PHP dependencies; the manual asset bundles those dependencies. A direct Git clone is a maintainer checkout and intentionally contains CI and release tooling. Tagged Composer/GitHub distributions exclude that repository-only material through `.gitattributes` while retaining the user documentation linked below.

## Requirements

- WordPress 6.8 or newer.
- PHP 8.3 or newer.
- Composer 2 for Composer-based installation and parent-theme maintenance.
- Node.js 24.13.0 or newer for generated child themes: their `package.json` advertises `>=24`, but Emulsify Core 4.5.0 requires `>=24.13.0`. Root release tooling separately requires `>=24.10`. Both `.nvmrc` files select the Node 24 line without pinning a minor version.
- Timber 2, installed by the site project or bundled in the manual release ZIP.
- WP-CLI when generating child themes or running the full WordPress fixture smoke test.

## Using Emulsify WordPress in a site project

Install the parent theme as `emulsify` and pair it with a child theme for project work. In Bedrock, that usually means:

```sh
web/app/themes/emulsify
web/app/themes/whisk
```

In a standard WordPress install, use:

```sh
wp-content/themes/emulsify
wp-content/themes/whisk
```

Timber 2 must be loaded before the theme renders. Composer-based site projects can satisfy that requirement in either place:

- Require `timber/timber` from the application-level Composer project.
- Run Composer inside the parent theme when the parent theme owns its PHP dependencies:

```sh
cd web/app/themes/emulsify
composer install
```

Activate the child theme, not the parent theme. The child theme header includes `Template: emulsify`, which tells WordPress to use Emulsify as the parent runtime.

Do not run root npm commands in the parent theme for normal site implementation. Project frontend work happens in the generated child theme.

## Working inside a generated child theme

Run component, Vite, Storybook, and project lint commands from the generated child theme:

```sh
cd web/app/themes/whisk
npm install
```

| Command | Purpose |
| --- | --- |
| `npm run build` | Build Core 4 assets with Vite. |
| `npm run vite` | Watch and rebuild Vite assets. |
| `npm run storybook` | Start Storybook on port 6006. |
| `npm run develop` | Run the Vite watcher and Storybook together. |
| `npm run storybook-build` | Build assets and export a static Storybook. |
| `npm run lint` | Run JavaScript and Sass linting with the Core 4 config. |
| `npm run audit` | Run the Core migration/static audit. |
| `npm run audit:twig-stories` | Check Twig story compatibility. |
| `npm run inspect:components` | Discover components and report metadata, dependencies, configuration issues, and orphaned files. |
| `npm run a11y` | Build Storybook and run the Core accessibility check. |
| `npm run test` | Run project Jest tests with coverage; fails when no tests exist. |

The component inspector comes from the installed `@emulsify/core` package and
runs from the generated child theme root without requiring WordPress to be
installed or bootstrapped:

```sh
npm run inspect:components
npm run inspect:components -- --json
npm run inspect:components -- --help
```

## Parent and child themes

The `emulsify` parent theme owns reusable runtime behavior:

- `includes/` contains the namespaced runtime classes.
- `templates/` provides minimal Timber fallback templates.
- `theme.json` provides editor settings and presets.
- `whisk/` is the generated starter child theme source.

The generated `whisk` child theme owns project implementation:

- `whisk/src/components` is an empty placeholder until a project installs the component system it wants to use.
- Source Sass, JavaScript, stories, data fixtures, and component metadata are defined by the selected Emulsify component system, not by this parent theme starter.
- `whisk/assets/images` and `whisk/assets/icons` are empty placeholders for project-owned theme media and icon files.
- New generated themes contain no Twig overrides or `templates/` directory. Add project templates only when needed; use the documented [Twig extension points](docs/template-extension.md).
- `whisk/dist/global` and `whisk/dist/components` are runtime build output conventions when a component system emits them.
- `whisk/project.emulsify.json` uses `"platform": "wordpress"` so Core and CLI tooling can load the WordPress platform adapter. It also records `generatedFrom` and `generatedFromVersion` so future upgrades and support diagnostics can identify Emulsify-generated WordPress child themes. Projects can also use Core-supported metadata such as `variant.structureImplementations` there when a selected component system needs legacy Twig namespaces.

## Bedrock and Timber

Timber is required for frontend template rendering. This repository declares `timber/timber` in the parent theme `composer.json`, so a standalone theme install can run Composer inside the parent theme.

For Bedrock applications, it is also valid to require Timber from the application-level Composer project as long as WordPress loads that Composer autoloader before the theme renders. If Timber is missing, the parent theme shows an actionable admin notice and stops frontend rendering with a clear runtime error.

## Developing or releasing the parent theme

Parent-theme root commands are for maintainers and release checks, not normal project frontend development. Run them from the parent theme repository root:

```sh
composer install
npm ci --ignore-scripts
```

Composer install creates the runtime autoloader. After adding or renaming parent runtime classes, run `composer dump-autoload` so Composer's optimized classmap sees the current files. The supported manual release ZIP ships that autoloader and its production dependencies; a source checkout without `vendor/` still uses the Bootstrap fallback loader.

### Linting and static analysis

Install the PHP development dependencies, then run the combined coding-standards and static-analysis command:

```sh
composer install
npm run lint:php
```

`lint:php` runs PHPCS with `phpcs.xml.dist`, followed by PHPStan with `phpstan.neon.dist`. Both configurations cover `includes/` and the parent theme's root PHP entry points, including `functions.php`. Use `npm run lint:php:fix` to apply safe PHPCBF coding-standard fixes, then rerun `npm run lint:php` to confirm PHPStan and the remaining PHPCS checks.

The `.husky/pre-commit` hook already runs `npm run lint`, which delegates to this PHP check.

| Command | Purpose |
| --- | --- |
| `npm run lint:php` | Run PHPCS and PHPStan across the parent runtime. |
| `npm run pr:check` | Run the practical, stubbed pull request validation suite. |
| `npm run release:check` | Run release-readiness checks. |
| `npm run build:dist` | Build the installable `dist-artifact/emulsify.zip` release archive. |
| `npm run publish-test -- --no-ci` | Run a local semantic-release dry run. |

Every pull request to the configured branches runs two visible fast readiness jobs: practical smoke checks and dedicated PHPCS/PHPStan analysis. Pull requests targeting `main` or `release-2.x` additionally run the MySQL-backed WordPress fixture and Whisk Storybook accessibility audit. The WordPress job builds and extracts `emulsify.zip`, confirms its packaged generator and Whisk source, and sets `WP_SMOKE_REQUIRED=1`, so missing WP-CLI/MySQL prerequisites or route render failures fail the job instead of producing a skip. The Whisk job installs Chrome explicitly, copies an accessible CI-only story and Vite entry into the otherwise component-agnostic starter, and then builds Storybook and runs axe.

Weekly scheduled runs repeat the WordPress fixture and Whisk accessibility audit. Manual GitHub Actions > `WordPress Theme Readiness` runs can select either extended fixture with the `wordpress_fixture` and `extended_checks` inputs. Local `release:check` still skips the database fixture when prerequisites are unavailable unless `WP_SMOKE_REQUIRED=1` is set. Release publishing also requires the full WordPress fixture path before semantic-release can publish.

## Generate a child theme

Generate a project child theme from the bundled Whisk starter with WP-CLI:

```sh
wp emulsify "Acme Site" --dry-run
wp emulsify "Acme Site" --machine-name=acme-site
wp emulsify "Acme Site" --machine-name=acme-site --parent=emulsify
wp emulsify "Acme Site" --machine-name=acme-site --force
wp emulsify "Acme Site" --machine-name=acme-site --activate
```

WP-CLI must point at the actual WordPress core directory so WordPress can load the active theme and register the command. In a Bedrock/DDEV project with core at `web/wp`, add `path: web/wp` to the project's `wp-cli.yml`, or run `ddev wp --path=web/wp emulsify "Acme Site" --dry-run`. See the [WP-CLI guide](docs/wp-cli-child-theme-generation.md#bedrock-and-ddev) for the complete configuration and troubleshooting steps.

The generator copies `<parent>/whisk` to a sibling child theme directory, updates WordPress theme headers, package metadata, Emulsify project metadata, and visible starter labels, then optionally activates the generated child theme. `--parent=<slug>` selects a different installed parent theme directory; it defaults to `emulsify`.

`--force` only replaces an existing destination when its WordPress platform, `generatedFrom: "emulsify-wordpress"` lineage, generated version, parent template, and machine name all match the requested generated child theme. The replacement is staged atomically so a copy failure leaves the existing theme intact; use `--dry-run --force` to inspect replacement intent without deleting files.

For Emulsify CLI integration, the standalone starter repository is `https://github.com/emulsify-ds/emulsify-wordpress-starter`. It represents the generated child theme layer from `whisk/`, not the parent runtime theme root, and generated projects still declare `Template: emulsify` so WordPress loads the installed parent theme.

## Documentation

- [Documentation index](docs/README.md)
- [Generated-theme upgrade guide](UPGRADE.md)
- [Upgrading from 1.x to 2.x](docs/upgrading-1x-to-2x.md)
- [Sister-project parity contract](docs/sister-project-parity.md)
- [Generated child theme contract](docs/generated-child-theme-contract.md)
- [Parent and child theme architecture](docs/parent-child-architecture.md)
- [Timber and Twig authoring](docs/timber-and-twig-authoring.md)
- [Emulsify Core 4 and Vite workflow](docs/core-4-vite-workflow.md)
- [Design token integration](docs/design-token-integration.md)
- [Component recipes](docs/component-recipes.md)
- [ACF Local JSON](docs/acf-local-json.md)
- [ACF/Twig blocks](docs/acf-twig-blocks.md)
- [Core block Twig rendering](docs/core-block-twig-rendering.md)
- [Native Gutenberg blocks](docs/native-gutenberg-blocks.md)
- [Block patterns](docs/block-patterns.md)
- [Editor enhancements](docs/editor-enhancements.md)
- [Editor policy](docs/editor-policy.md)
- [Asset loading](docs/asset-loading.md)
- [WP-CLI child theme generation](docs/wp-cli-child-theme-generation.md)
- [Release process](docs/release-process.md)
- [Next release notes draft](docs/release-notes-next.md)
- [Post-2.x optimization roadmap](docs/post-2x-optimization-roadmap.md)

## License

Emulsify WordPress is licensed under GPL-2.0-only. See [LICENSE](LICENSE).

## Contributing

### Code of conduct

Read the [Code of Conduct](https://github.com/emulsify-ds/emulsify-wordpress/blob/main/CODE_OF_CONDUCT.md) before contributing. File bugs and feature requests at [emulsify-ds/emulsify-wordpress](https://github.com/emulsify-ds/emulsify-wordpress/issues).

### Committing changes

This repository uses [Conventional Commits](https://www.conventionalcommits.org/). Commit messages drive semantic-release, so the type determines the next version:

- `fix:` produces a patch release.
- `feat:` produces a minor release.
- `feat!:` or a `BREAKING CHANGE:` footer produces a major release.
- `docs:`, `test:`, `ci:`, and `chore:` produce no release.

A commit-msg hook validates the format with commitlint. See [docs/release-process.md](docs/release-process.md) for the full version strategy.

### Release readiness

Before opening a release pull request, run the local checks:

```sh
npm run lint:php
npm run docs:check-commands
npm run test:generated-theme
npm run release:check
```

[docs/release-process.md](docs/release-process.md) documents the release checks, CI coverage, and the publish gate.

## Author

Emulsify&reg; is a product of [Four Kitchens &mdash; We make BIG websites](https://fourkitchens.com).

### Contributors
