# Emulsify Core 4 and Vite workflow

The generated child theme uses Emulsify Core 4, Vite, Storybook, Twig stories, Sass, and JavaScript. The parent theme does not build project assets directly.

## Project metadata

`whisk/project.emulsify.json` uses `"platform": "wordpress"` so Emulsify Core and Emulsify CLI tooling can load the WordPress platform adapter while this parent theme owns the reusable WordPress runtime. It also records `generatedFrom: "emulsify-wordpress"` and `generatedFromVersion` for future starter upgrades and support diagnostics.

Keep this file in generated child themes. The child theme generator updates `project.name`, `project.machineName`, and generated source metadata, and Emulsify Core uses the same metadata for component-library behavior.

If a selected component system needs legacy Twig namespaces, use Core's existing `variant.structureImplementations` array:

```json
{
	"variant": {
		"structureImplementations": [
			{
				"name": "atoms",
				"directory": "src/components/atoms"
			}
		]
	}
}
```

The WordPress runtime also reads that shape so `{% include "@atoms/example/example.twig" %}` works in PHP-rendered Timber templates without requiring a separate WordPress-only namespace file. Do not move these roots into `theme.json`; WordPress uses `theme.json` for editor settings and global styles, not Twig loader configuration.

## Source directories

The generated child theme intentionally does not ship a concrete component library. Emulsify CLI can install the component system a project chooses, and that system should own the source tree, Sass entrypoints, stories, and fixture data.

For Emulsify CLI initialization, `whisk/` is also the source for the standalone `emulsify-wordpress-starter` repository. The starter hook runs after CLI dependency installation and updates package metadata, WordPress theme headers, project metadata, and optional JSON pattern namespaces for the generated project.

Whisk keeps `src/components/.gitkeep` only as a placeholder for compatible systems. It does not include default `tokens.scss`, `foundation.scss`, or `layout.scss` files.

Whisk keeps empty `assets/images` and `assets/icons` directories as project-owned theme asset placeholders. Use these for source files that belong to the theme repository, not for uploaded media library files. The optional `assets/fonts` placeholder is available when a project owns local font files.

Whisk does not include a child `theme.json` by default. The parent theme provides the reusable WordPress editor/global-style baseline; generated child themes should add `theme.json` only when the project needs its own presets, settings, styles, templates, or style variations.

The parent WordPress runtime has only two default build-output conventions:

- `dist/global` for built global CSS and JavaScript.
- `dist/components` for built component assets, ACF/Twig block metadata, and native `block.json` metadata.

A fresh Whisk child theme has no Vite input files until a component system is installed. Its build and watch scripts call Emulsify Core's Vite config directly, so Core owns build-system errors and reporting when required inputs are missing.

## Core 4, Vite, and Storybook commands

Run these from the generated child theme directory:

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

### Component inspector

The component inspector is provided by the published
`emulsify-inspect-components` binary in `@emulsify/core`. Run it from the
generated child theme root; it has no WordPress or Drupal runtime dependency and
does not duplicate the inspector implementation in this repository.

Use the default report during development, JSON for automation, and the built-in
help when checking the current Core options:

```sh
npm run inspect:components
npm run inspect:components -- --json
npm run inspect:components -- --help
```

## Documentation-only component example

The following shape is an example of a compatible component, not files shipped by Whisk:

```text
src/components/example-card/
  example-card.twig
  example-card.scss
  example-card.data.json
  example-card.stories.js
  example-card.component.json
```

Project teams should add, rename, or remove component files according to the selected component system and project naming model. This parent theme does not require a button component or any specific foundation, layout, or token Sass files.

See [Component recipes](component-recipes.md) for small Twig, Storybook, ACF/Twig, native block, and pattern examples that can be copied into a generated child theme when a project needs them.

When a component system builds `*.component.json` files into `dist/components`, the parent theme can discover those files for optional ACF/Twig block registration. Documentation examples prove the shape, but Whisk does not register any starter ACF/Twig blocks by default.

For new project component includes, prefer the generated child theme machine name from `project.emulsify.json`. The general form is `{% include "project_machine_name:component_name" %}`. The legacy `@components/component-name/component-name.twig` namespace remains supported for compatible component libraries, existing projects, shared templates, and migration work.

Whisk keeps `patterns/.gitkeep` only as a placeholder. If a project adds JSON patterns, the child theme generator updates copied pattern namespaces from `whisk/*` to the generated machine name.

## Build output

The parent theme loads built files from the active child theme first, then parent fallbacks. Build the child theme before expecting component styles, JavaScript, ACF/Twig block metadata, or native block metadata to appear in WordPress.
