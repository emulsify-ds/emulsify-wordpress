# Upgrade guide

Emulsify WordPress is a parent theme. Generated child themes do not inherit
later starter changes, so most upgrades have two parts: update the parent theme,
then decide which starter changes to adopt in each generated project.

## Upgrading from 2.0.0 to 2.1.0

The minimum WordPress version is now 6.8, matching the locked Timber dependency.
Sites running WordPress 6.7 must update WordPress before updating the theme.

New child themes use Emulsify Core `^4.5.0`. Existing generated themes do not
inherit copied starter scripts or configuration when the parent is updated.
Updating Core is optional for existing projects; to adopt 4.5.0, change the
dependency and refresh your project's lockfile with `npm install`:

```diff
 "dependencies": {
-  "@emulsify/core": "^4.3.2"
+  "@emulsify/core": "^4.5.0"
 }
```

Core requires Node.js 24.13.0 or newer, even though the generated theme advertises
`>=24` and `.nvmrc` selects only the Node 24 line. Check the resolved package's
engines after updates. Core 4.5.0 adds bounded accessibility concurrency and an
optional `@emulsify/core/a11y/wcag22` preset. Its Twig resolver preserves every
segment of bare paths: correct references that relied on a missing first
segment falling back to another group.

To adopt the copied tooling fixes, edit the existing project's `package.json`
and copy the updated configs described below. Parent theme updates alone do
not replace these files. Adoption is optional for an existing project, but the
lint/format failure fixes are recommended so CI cannot silently pass a failed
check. These commands still run both steps:

```diff
 "scripts": {
-  "lint": "npm run lint-js; npm run lint-styles",
-  "lint-fix": "npm run lint-js -- --fix; npm run lint-styles -- --fix",
-  "format": "npm run lint-fix; npm run prettier-fix",
+  "lint": "sh -c 'status=0; npm run lint-js || status=$?; npm run lint-styles || status=$?; exit $status'",
+  "lint-fix": "sh -c 'status=0; npm run lint-js -- --fix || status=$?; npm run lint-styles -- --fix || status=$?; exit $status'",
+  "format": "sh -c 'status=0; npm run lint-fix || status=$?; npm run prettier-fix || status=$?; exit $status'"
 }
```

The accessibility command stays the same before and after:

```json
{
  "scripts": {
    "a11y": "npm run storybook-build && node config/emulsify-core/run-a11y.js"
  }
}
```

For this optional migration, update Core to `^4.5.0` first and replace the copied
`config/emulsify-core/run-a11y.js` with the current starter's thin wrapper.
Changing `package.json` alone does not replace the old runner. The new wrapper
uses Core's bounded concurrency, complete reporting, and static server, while
retaining WordPress's failure when no stories are found. Core resolves project
`a11y.config.js` from the working directory and defaults the Storybook output
to `.out`; existing `pa11y.wait` settings still apply. Use a sandbox-capable
Chrome rather than disabling its sandbox. The WCAG 2.2 preset is a separate,
optional choice; it is not enabled by this migration.

To adopt ESM tests and project coverage, copy `config/jest.config.js` from the
current starter and update the test commands together:

```diff
 "scripts": {
-  "test": "jest --coverage --passWithNoTests --config ./config/jest.config.js",
-  "twatch": "jest --no-coverage --watch --verbose --passWithNoTests --config ./config/jest.config.js",
+  "test": "node --experimental-vm-modules node_modules/jest/bin/jest.js --coverage --config ./config/jest.config.js",
+  "twatch": "node --experimental-vm-modules node_modules/jest/bin/jest.js --no-coverage --watch --verbose --config ./config/jest.config.js"
 }
```

This optional copied-tooling migration intentionally makes an empty project
fail tests, matching new generated themes. Add at least one real test before
enabling it in an existing project's CI. Keep project-specific Jest settings;
the new defaults retain `rootDir: '..'` and the existing test locations, add
`transform: {}` and V8 coverage, and exclude dependencies, built files, stories,
and test files from source coverage.

The parent no longer emits link hover, focus, and active color declarations
that referenced an undefined custom property. Those declarations caused color
inheritance; removing them can reveal existing link colors. In the default
fixture, focused links keep their normal color instead of inheriting the
surrounding text color. Review child theme link-state styles if they relied on
that inheritance.

Project component references now resolve components inside group folders and
accept both `project_machine_name:component` and
`@project_machine_name/component`. Resolution follows Emulsify Core, so a flat
`component.twig` now precedes `component/component.twig`; earlier WordPress
versions preferred the latter. Check projects that contain both forms or
duplicate component names, and use an explicit group or template path to keep
the intended choice. See the [component resolution order](docs/timber-and-twig-authoring.md#component-includes).

Fallback pagination ellipses remain non-interactive spans with the existing BEM
classes, now displaying a translated `…` and omitting `aria-current`. Child
themes that override `templates/partials/pagination.twig` should adopt these
markup corrections and decode Timber's display-escaped URLs before passing
them to `add_attributes`. The parent adds no frontend CSS.

Child themes overriding `templates/partials/menu.twig` should also mark only
links whose `item.current` flag is true with `aria-current="page"`.

The release tooling change requires no runtime or generated child theme edits.
Maintainers need a full Git checkout with tags to run release checks. Before
publishing, prepare a release PR that updates root `package.json` and all derived
version metadata together, then confirm semantic-release computes the same
version. A checkout still at the latest tagged version skips publishing. See
the [release PR procedure](docs/release-process.md#release-pr-procedure).

## Upgrading from 1.x to 2.0.0

2.0.0 is a full rebuild of the WordPress theme around Timber, Emulsify Core 4,
Vite, and Twig. Treat it as a theme-platform change rather than a version bump.
Step-by-step guidance lives in
[docs/upgrading-1x-to-2x.md](docs/upgrading-1x-to-2x.md).

### Requirements

- WordPress 6.8 or newer.
- PHP 8.3 or newer.
- Timber 2.3 or newer, available to the parent theme.
- Node.js 24.13.0 or newer for generated child theme frontend tooling. Root release
  tooling requires Node.js 24.10 or newer.
- Generated child themes keep `Template: emulsify` in `style.css` so WordPress
  loads the parent runtime.

### Known breaking changes

- The theme is now Timber-first. Frontend rendering goes through Twig templates
  resolved by the parent theme rather than through PHP template parts.
- The frontend build moved from Webpack to Vite, provided by Emulsify Core 4.
- Generated child themes use Emulsify Core 4 and its Storybook, Jest, ESLint,
  Stylelint, and Prettier configuration under `config/emulsify-core/`.
- `whisk` is a generation-only starter source. Do not activate it directly.
- The starter no longer ships `tokens.scss`, `foundation.scss`, or
  `layout.scss` entrypoints, a component tree, or a child `theme.json`. Projects
  choose and install their own component library after generation.
- Child theme generation is handled by the parent theme's WP-CLI command,
  `wp emulsify`, or by the standalone
  [`emulsify-wordpress-starter`](https://github.com/emulsify-ds/emulsify-wordpress-starter)
  repository through Emulsify CLI.
- Generation-only tooling is no longer copied into generated child themes. The
  `.cli` directory is excluded by the WP-CLI generator and removed by the
  standalone starter hook after it runs.
- Generated child themes now include project documentation. See
  [generated project documentation](#generated-project-documentation).

### Package changes

- Added: `timber/timber` `^2.3` as a parent theme requirement.
- Added: `@emulsify/core` `^4.5.0` in generated child themes.
- Added: PHPCS, PHPStan, WPCS, and WordPress/ACF/WP-CLI stubs as parent theme
  development requirements.
- Removed: Webpack-era build dependencies from the starter.

### Recommended upgrade path

1. Confirm the site meets the requirements above.
2. Update the Emulsify WordPress parent theme to 2.x.
3. Generate a fresh child theme and port project templates, components, and
   styling into it rather than upgrading a 1.x child theme in place.
4. Move frontend build docs, scripts, and team habits from Webpack terminology
   to the Vite workflow.
5. Install a component library and rebuild assets so the parent theme can
   discover built output under `dist/`.
6. Run the validation commands in
   [docs/upgrading-1x-to-2x.md](docs/upgrading-1x-to-2x.md).

## Generated project documentation

Child themes generated by 2.x include a project-specific `README.md` plus
`docs/development.md`, `docs/upgrading.md`, and `docs/support-information.md`.
Both generation paths resolve the same `%%EMULSIFY_*%%` tokens and fail
generation if any token survives, so a generated project never ships template
placeholders.

Existing generated child themes are not rewritten. To recover the documentation
for an older project, generate a temporary comparison theme with a different
machine name, copy the guides across, and delete the temporary theme. The
guarantees are described in
[docs/generated-child-theme-contract.md](docs/generated-child-theme-contract.md).

## Component inspector

The component inspector is a backward-compatible generated-theme feature
available with Emulsify Core 4.3.0 or newer. It discovers components and reports
metadata, dependencies, configuration issues, and orphaned files. The
implementation remains in `@emulsify/core`; adopting projects only need to
upgrade the dependency and expose its published binary.

Existing generated child themes can opt in with these `package.json` changes:

```json
{
  "scripts": {
    "inspect:components": "emulsify-inspect-components"
  },
  "dependencies": {
    "@emulsify/core": "^4.3.0"
  }
}
```

After updating the dependency and lockfile, run the command from the generated
theme root:

```sh
npm run inspect:components
npm run inspect:components -- --json
npm run inspect:components -- --help
```

The `whisk/` update in this repository only affects WordPress child themes
generated after the Emulsify WordPress release that contains it. Existing
generated themes do not inherit later starter changes and must update their own
`package.json`.

The same generation boundary applies to the Drupal sister project: Whisk
changes only affect themes generated after the new Emulsify Drupal release.
Existing Drupal themes must adopt the dependency and script explicitly.

Keep the coordinated release order:

1. Publish the compatible `@emulsify/core` 4.3.0 release.
2. Merge and release the consuming Emulsify WordPress or Emulsify Drupal
   change.

## Project audit

Emulsify Core ships two audit commands that help a generated child theme move
off legacy patterns. Run them from the generated theme root:

```sh
npm run audit
npm run audit:twig-stories
```

`npm run audit` reports Storybook discovery problems, unresolved Twig
`include()` and `source()` references, Webpack-era patterns, imports that reach
into Emulsify Core internals, and platform assumptions that will not hold on
WordPress.

`npm run audit:twig-stories` focuses on legacy Twig stories: large Twig
Storybook roots and story formats that predate Emulsify Core 4.

Both commands print a link to the relevant Emulsify Core migration
documentation. They report findings only; they do not modify project files.
