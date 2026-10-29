# Parent and child theme architecture

Emulsify WordPress is split into a reusable parent theme and generated project child themes. The goal is to keep parent runtime behavior stable while allowing each project to own its implementation details.

## Parent theme responsibilities

The parent theme owns reusable runtime behavior:

- WordPress bootstrap code.
- Theme setup, menus, editor support, and image sizes.
- Timber integration and global context.
- Twig namespace registration and helper functions.
- Asset loading for built child and parent files.
- Optional ACF Local JSON save/load path support.
- Optional ACF/Twig block registration.
- Optional native Gutenberg block registration.
- Optional JSON block pattern registration.
- Optional block editor governance when configured by a child theme.
- Minimal Timber fallback templates.

Most parent runtime code lives in `includes/`. The root `functions.php` should stay thin.

Runtime classes are grouped by domain: `Runtime`, `Blocks`, `Editor`, `Acf`, `Cli`, `Support`, and `Twig`. Composer PSR-4 autoloading loads these classes when Composer is available, and Bootstrap keeps a small fallback loader for manual theme installs without Composer.

## Child theme responsibilities

Generated child themes own:

- Project components in the structure supplied by the selected Emulsify component system.
- Project Sass and JavaScript defined by that component system or by the project.
- Storybook stories and data fixtures.
- Built Vite output under `dist/`.
- Intentional template overrides.
- Project-specific filters or hooks.

The bundled Whisk starter is an example child theme. Real projects can generate a renamed child theme with WP-CLI.

## Template fallback model

The parent `templates/` directory contains complete minimal fallbacks. Generated
child themes ship no Twig templates or `templates/` directory. Pages render
through the parent's `page.php` and Twig fallback until the project creates an
intentional override. See the [public Twig extension points](template-extension.md).

Timber resolves the `@templates` namespace child-first:

1. Child theme `templates`.
2. Parent theme `templates`.

The parent-only `@emulsify-tpl` namespace points at parent templates. Use it when a child override needs to extend the parent fallback it is replacing.

## Component discovery priority

Built child theme components are discovered before built parent theme components. When a child and parent component use the same relative path under `dist/components`, the child component wins for both ACF/Twig `*.component.json` metadata and native `block.json` metadata.

Discovery is memoized for the current PHP request. ACF/Twig and native block registration share one filesystem scan by default.

Recursive discovery skips unreadable or disappearing directories so one broken
component folder cannot interrupt the request. With `WP_DEBUG` enabled, the
runtime reports skipped unreadable roots and traversal failures for diagnosis.

## Asset resolution priority

Built assets are resolved child-first. When `dist/emulsify-assets.json` exists and is valid, the manifest is used before recursive scanner fallback for each asset scope. If a child manifest is valid, it takes priority over the parent manifest; if a scope is missing or invalid, that scope falls back to scanning built child and parent directories.

Manifest reads are memoized per `AssetManifest` instance during a request. Scanner fallback also memoizes raw file records per theme-relative directory, then filters CSS and JavaScript in memory for each enqueue pass while preserving the existing asset filters.

## Optional persistent discovery cache

Persistent component discovery caching is enabled by default in production and staging environments, and disabled by default in local, development, or `WP_DEBUG` environments. Projects can still opt out or force it on with:

```php
add_filter( 'emulsify_theme_component_discovery_cache_enabled', '__return_false' );
```

The transient cache key includes the active stylesheet, parent template, both
theme versions and directories, the WordPress environment type, the effective
component roots and their filesystem state, and `dist/emulsify-assets.json`
filemtime when that manifest exists. Directory identity and timestamps invalidate
the index after a release-directory deploy or a standard Emulsify Core build,
which empties the output tree before writing it. The runtime also calls
`clear_discovery_cache` on WordPress' `switch_theme` hook.

Cached file paths are relative to their component root and resolved against the
current roots. Before reusing an index, the runtime checks that every cached file
is still readable and inside its root. If any record is stale or invalid, it
discards the index and scans again. A warm cache performs filesystem checks but
does not recursively scan the component tree. A scan that skips an unreadable or
disappearing directory is not persisted, so the next request can discover the
recovered branch.

Projects that customize component roots dynamically can add their own
invalidation token with
`emulsify_theme_component_discovery_cache_key_parts`. Preserve the existing key
parts when adding a token. Custom builds that update nested directories in place
must change a build token or clear the discovery cache: a nested addition need
not change the component root's timestamp. This also applies when forcing
persistent caching on during Core's watch builds, which preserve the output tree
after their first cycle. The default cache lifetime can be adjusted with
`emulsify_theme_component_discovery_cache_ttl`.

To clear the current cache key from project code, a maintenance command, or custom deployment logic, call:

```php
\Emulsify\Theme\Blocks\ComponentLocator::clear_discovery_cache();
```

For new project component includes, prefer the generated child theme machine name from `project.emulsify.json`, using the general form `{% include "project_machine_name:component_name" %}`. The legacy `@components/component-name/component-name.twig` namespace remains supported for compatible component libraries, existing projects, shared templates, and migration work.

When a selected component system needs custom legacy namespaces, define them once in `project.emulsify.json` with Emulsify Core's `variant.structureImplementations` array. The WordPress runtime reads that same shape for Timber, keeping Storybook/Core and PHP-rendered templates aligned without adding Drupal-style `.info.yml` metadata.

## Runtime filters

Child themes and project plugins can extend parent behavior with focused WordPress filters:

- `emulsify_theme_asset_directories`
- `emulsify_theme_asset_files`
- `emulsify_theme_twig_namespaces`
- `emulsify_theme_project_component_roots`
- `emulsify_theme_context`
- `emulsify_theme_acf_json_enabled`
- `emulsify_theme_acf_json_save_path`
- `emulsify_theme_acf_json_load_paths`
- `emulsify_theme_acf_json_remove_default_load_path`
- `emulsify_theme_component_roots`
- `emulsify_theme_component_discovery_cache_enabled`
- `emulsify_theme_component_discovery_cache_key_parts`
- `emulsify_theme_component_discovery_cache_ttl`
- `emulsify_theme_acf_block_metadata`
- `emulsify_theme_acf_block_args`
- `emulsify_theme_native_block_directories`
- `emulsify_theme_pattern_directories`
- `emulsify_theme_pattern_data`
- `emulsify_theme_pattern_categories`
- `emulsify_theme_pattern_args`
- `emulsify_theme_setup_options`
- `emulsify_theme_editor_policy_options`
- `emulsify_theme_allowed_block_types`
- `emulsify_theme_pattern_namespaces`
- `emulsify_theme_block_support_overrides`

Use these filters for project-specific behavior before editing a parent class. Keep broad application logic in a project plugin when it is not theme-specific.

## Emulsify project metadata

The generated child theme includes `project.emulsify.json` with `"platform": "wordpress"`. Emulsify Core and Emulsify CLI tooling use that adapter value for WordPress-aware project behavior, while reusable WordPress runtime support remains in this parent theme.

Generated child themes also record `generatedFrom: "emulsify-wordpress"` and `generatedFromVersion`. These fields identify the starter lineage for future upgrades, support diagnostics, and safer replacement checks.

Use WordPress-native files for WordPress concerns: `style.css` headers for theme identity and `theme.json` for editor/global style settings. Use `project.emulsify.json` for Emulsify project metadata that Core and project tooling need to share.
