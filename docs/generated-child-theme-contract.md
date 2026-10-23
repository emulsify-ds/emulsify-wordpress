# Generated Child Theme Contract

A generated child theme is a new, project-owned WordPress theme. It is not the Whisk source directory and it is not part of the Emulsify WordPress repository. Once generated, the project owns its templates, components, patterns, and styling.

This contract describes what generation guarantees, what it deliberately leaves to the project, and how the guarantees are enforced.

## Two supported generation paths

Emulsify WordPress supports two ways to create a child theme:

- **In-site WP-CLI.** `wp emulsify "Acme Site" --machine-name=acme-site` copies the bundled Whisk starter from the installed parent theme and applies targeted metadata updates.
- **Standalone starter.** Emulsify CLI clones [`emulsify-wordpress-starter`](https://github.com/emulsify-ds/emulsify-wordpress-starter), installs dependencies, and runs the starter's `.cli/init.js` hook.

These are separate implementations in different languages, so the release checks generate the same identities through both paths and require their file trees to match. The only expected difference is the lockfile the standalone path creates during `npm install`.

## What the contract guarantees

- The machine name, display name, and description are applied consistently to `style.css` headers, `package.json`, `project.emulsify.json`, `functions.php`, `templates/`, and `patterns/`.
- `style.css` keeps `Template: emulsify` so WordPress loads the Emulsify parent runtime, and preserves the starter's license metadata.
- `package.json` is valid JSON, uses the generated machine name, retains every Emulsify Core npm script, and keeps the starter's `@emulsify/core` range.
- `project.emulsify.json` is valid JSON and retains `platform`, the generated `machineName`, `generatedFrom`, `generatedFromVersion`, and the `starter` repository block.
- `README.md`, `docs/development.md`, `docs/upgrading.md`, and `docs/support-information.md` are present. The README contains the requested display name, machine name, description, source project, source version, and Emulsify Core range, and the guides document only npm commands that the generated `package.json` exposes.
- No `%%EMULSIFY_*%%` documentation token survives generation. An unreplaced token fails generation rather than shipping to a project.
- No starter identity survives: the machine name `whisk`, the display name `Whisk`, and legacy `EMULSIFY_NAME` placeholders are all absent.
- Generation-only tooling and build output are absent, including `.cli`, `node_modules`, `dist`, `.out`, and `.coverage`.
- Generated files are self-contained: no symbolic links, no documentation links that resolve outside the theme, and no script paths that point outside the theme except published packages under `node_modules/`.
- Generated Markdown uses current terminology; retired Webpack and "subtheme" language fails the check.
- Both generation paths write JSON with two-space indentation, so generated metadata is byte-identical regardless of path.

## Outside the contract

Generation intentionally does not provide:

- a particular component library, component directory, or example component;
- frontend CSS behavior or visual design;
- frontend JavaScript behavior;
- a particular design-token system or token build pipeline;
- a child `theme.json`, which projects add only when they need WordPress editor or global-style overrides;
- ACF field groups, block patterns, or native block definitions beyond the empty placeholders.

Projects select and install their own component libraries after generation. The contract preserves component-neutral project metadata without requiring any components to exist. A component-neutral project returns a valid empty component inspector report.

## Run the checks

Install dependencies first with `npm ci --ignore-scripts`, then:

```bash
npm run test:generated-theme
npm run smoke:generation-parity
npm run release:check -- --skip-smoke
```

`npm run test:generated-theme` runs focused contract tests that generate a throwaway theme and then mutate it to prove each class of failure is detected. `npm run smoke:generation-parity` generates through both paths and compares them; it requires a PHP binary and skips with a warning when none is available.

To verify generated audit wrappers against an actual packed Core artifact, run:

```sh
EMULSIFY_CORE_TARBALL=/absolute/path/emulsify-core-4.5.0.tgz npm run test:audit-wrappers
```

This opt-in test generates a temporary child theme and installs that package
without installer hooks. It compares both wrappers with the installed Core
executables, checks complete JSON stdout, stderr footers, exit codes 0/1/2, and
arguments containing spaces. Negative controls prove that stdout footers,
masked failures, and dropped arguments are detected. It requires Node 24 and
registry access for the packed package's dependencies; it leaves the source
theme and dependency ranges unchanged.

The full release check additionally exercises a real WordPress fixture:

```bash
npm run release:check
```

That requires Node.js, PHP, Composer, MySQL, WP-CLI, and network access.

## Common failures

- **Generation:** A required generated file is missing, or generation-only tooling such as `.cli` was copied into the theme. Fix the copy exclusions or the starter layout.
- **WordPress metadata:** A `style.css` header does not match the requested identity, or `functions.php` still describes the starter.
- **Frontend metadata:** `package.json` or `project.emulsify.json` lost a required key, an npm script, or the Emulsify Core range.
- **Documentation:** A required generated guide is missing, still contains a source token, lost project-specific metadata, or documents an npm command the generated package does not expose. Fix the Whisk documentation template or the generation post-processors.
- **Placeholder replacement:** A starter name or token survived. Both generators must be updated together; a token added to the templates without a matching replacement in each generator fails the release check.
- **File references:** A documentation link or script path escapes the generated theme.

## Keeping the paths aligned

When you add a `%%EMULSIFY_*%%` token to a Whisk documentation file, add the matching replacement in **both** generators:

- `includes/Cli/GenerateChildThemeCommand.php` (`collect_documentation_updates()`)
- `whisk/.cli/init.js` (`updateDocumentation()`)

The release check asserts the token list appears in the templates and in both generators, so a one-sided change fails CI rather than shipping a leaked token.
