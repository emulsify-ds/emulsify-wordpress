# Development guide for %%EMULSIFY_THEME_NAME%%

This guide covers local setup, the frontend workflow, and what this project owns versus what shared Emulsify tooling provides.

## Prerequisites

- An existing WordPress site with the Emulsify parent theme and this generated theme installed in `wp-content/themes/%%EMULSIFY_MACHINE_NAME%%` (or the equivalent themes directory).
- The Timber plugin or Composer package available to the parent theme. The parent theme owns Timber bootstrapping, Twig namespaces, and route fallbacks.
- Node.js 24 or newer. `package.json` declares the supported range and `.nvmrc` records the recommended version.
- nvm is optional but keeps the Node version aligned with the project.
- npm, which ships with Node.js.

## Initial setup

If you use nvm, select the recommended Node version first:

```bash
nvm install
nvm use
```

Install dependencies:

```bash
npm install
```

Use `npm install` for the first installation because a generated theme does not initially include a lockfile. After your project commits a `package-lock.json`, use `npm ci` in CI and other reproducible installs.

## Asset integration

This theme does not prescribe asset source directories, Sass entrypoints, build output paths, or active WordPress asset registrations. Those belong to the component library the project selects.

The component library owns:

- project source and component directory structure;
- stylesheet and JavaScript entrypoints;
- compiled asset locations;
- component metadata such as `*.component.json` or `block.json`;
- any design-token pipeline.

The Emulsify parent theme discovers built output under `dist/global` and `dist/components` and enqueues it, preferring child theme output over parent fallback output. Until a component library is installed and built, there is nothing to enqueue and the parent fallback remains in effect.

Shared lint, format, and test defaults discover supported files across the project without requiring `src` or `components` to exist.

## Component inspection

Inspect the components the project currently exposes:

```bash
npm run inspect:components
npm run inspect:components -- --json
npm run inspect:components -- --help
```

The inspector is safe to run before a component library exists. A component-neutral project returns a valid empty report.

## Development workflow

Run Vite in watch mode alongside Storybook:

```bash
npm run develop
```

Run Storybook on its own:

```bash
npm run storybook
```

Build production assets:

```bash
npm run build
```

Build a static Storybook:

```bash
npm run storybook-build
```

Before opening a pull request:

```bash
npm run lint
npm run test
npm run a11y
```

`npm run test` succeeds when no tests have been added yet. The accessibility check builds Storybook first, so it takes longer than lint or unit tests.

For machine-readable project audits, silence npm's command banner and keep the
documentation footer on stderr:

```bash
npm run audit --silent -- --json
npm run audit:twig-stories --silent -- --json
```

Both wrappers preserve Core's exit status and forward arguments unchanged. Use
`npm run audit --silent -- --json --fail-on warn` or
`npm run audit:twig-stories --silent -- --json --fail-on-found` to fail when
migration findings are present. Quote paths containing spaces, for example
`npm run audit --silent -- --root "../theme source" --json`. Existing generated
themes must copy the updated audit scripts from the current starter; updating
Core alone does not change a project's `package.json`.

To update an existing wrapper, keep `"$@"`, the `status=$?` immediately after
Core runs, and `exit $status`. Add only `>&2` after the footer's `printf`:

```diff
- printf "\nAudit docs: https://github.com/emulsify-ds/emulsify-core/blob/4.x/docs/migration-4x.md#storybook-migration\n"; exit $status
+ printf "\nAudit docs: https://github.com/emulsify-ds/emulsify-core/blob/4.x/docs/migration-4x.md#storybook-migration\n" >&2; exit $status
```

Apply the same redirection to `audit:twig-stories`, retaining its existing
`Migration docs:` footer. Alternatively, bypass the project wrappers and run
the installed Core executables directly without downloading another version:

```bash
npx --no-install emulsify-audit --json
npx --no-install emulsify-audit-twig-stories --json
```

## Project ownership

### Component library

This theme does not prescribe or scaffold a component library. A project may use Twig components rendered through Timber, ACF blocks backed by `*.component.json` metadata, native Gutenberg blocks declared with `block.json`, React components, existing project components, or a combination.

Treat `config/emulsify-core/` and the installed `@emulsify/core` package as shared tooling rather than a place for project code.

### WordPress surfaces

`style.css` carries WordPress theme identity, including the `Template: emulsify` header that binds this theme to the Emulsify parent runtime. Add a child `theme.json` only when the project needs WordPress editor or global-style overrides; do not add an empty file just to mirror the parent.

`patterns/` holds block pattern definitions registered by the parent theme. `functions.php` is the project hook surface.

### Generated source

`project.emulsify.json` records the platform, machine name, source project, source version, and starter repository. Preserve `generatedFrom` and `generatedFromVersion`.

Generation is a starting point, not a continuing ownership boundary. The original baseline is `%%EMULSIFY_SOURCE_PROJECT%%` `%%EMULSIFY_SOURCE_VERSION%%`, and the expected Emulsify Core range is `%%EMULSIFY_CORE_RANGE%%`.

See [Upgrading](upgrading.md) for how to adopt a newer starter release.
