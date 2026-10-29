# ACF/Twig blocks

Related: [Native Gutenberg blocks](native-gutenberg-blocks.md), [Core block Twig rendering](core-block-twig-rendering.md), [Block patterns](block-patterns.md).

ACF/Twig blocks are optional. If ACF is not active, this registration path is skipped without affecting the base theme.

## How discovery works

The parent theme scans built component output under `dist/components`. A component can register as an ACF/Twig block when its built folder contains:

- A `*.component.json` metadata file.
- A matching Twig template.

The Twig template can match the metadata filename or the component directory name.

Discovery is memoized during each request. Persistent discovery caching is enabled by default outside local, development, or `WP_DEBUG` environments; see [Parent and child theme architecture](parent-child-architecture.md#optional-persistent-discovery-cache).

## Example

Whisk does not include an active ACF/Twig block example. A project component system can add metadata like this and build it into `dist/components`:

```text
src/components/example-card/example-card.component.json
```

After the child theme build, that metadata might be available at:

```text
dist/components/example-card/example-card.component.json
dist/components/example-card/example-card.twig
```

This documentation example proves the shape, but it does not ship project field groups or content model assumptions.

See [Component recipes](component-recipes.md) for a short `*.component.json` example and guidance on when to choose ACF/Twig blocks.

## Registration

The parent theme reads the component metadata, merges default block arguments, and calls `acf_register_block_type()`. Rendering happens through Timber with block data, ACF fields, preview state, and the normal global Timber context.

For ACF/Twig component metadata, use an ACF-safe `name` such as `emulsify-hero` rather than a namespaced native block name such as `project/hero`. ACF's PHP registration API receives an un-namespaced slug and WordPress exposes the editor block under the `acf/` namespace. The parent normalizes accidental slashes or other unsupported characters to dashes before duplicate checks and registration.

The final registration arguments can be adjusted with:

```php
add_filter(
  'emulsify_theme_acf_block_args',
  function ( array $args, array $component ): array {
    if ( 'example-card' === $component['relative'] ) {
      $args['category'] = 'design';
    }

    return $args;
  },
  10,
  2
);
```

Component metadata can be adjusted before defaults are merged with `emulsify_theme_acf_block_metadata`.

## Scoped assets

ACF/Twig blocks can declare block-scoped frontend and editor assets. Prefer `dist/emulsify-assets.json` when build tooling can write one, because it keeps hashed filenames and dependencies in one place. If no matching manifest entry exists, the parent reads optional `assets` metadata from `*.component.json`.

Component metadata paths are relative to the built component directory:

```json
{
	"title": "Example Card",
	"assets": {
		"frontend": {
			"css": [{ "path": "example-card.css", "version": "card-css-123" }],
			"js": [{ "path": "example-card.js", "module": true }]
		},
		"editor": {
			"css": [{ "path": "example-card.editor.css" }],
			"js": [{ "path": "example-card.editor.js", "dependencies": ["wp-blocks"] }]
		}
	}
}
```

When scoped assets are declared, they are enqueued from the ACF block `enqueue_assets` callback instead of being loaded globally for every page. Components without scoped metadata continue to use the global `dist/components` scanner fallback.

Manifest block entries can target the final ACF block name:

```json
{
	"assets": {
		"blocks": {
			"acf/emulsify-example-card": {
				"frontend": {
					"css": [{ "path": "components/example-card/example-card.css" }]
				}
			}
		}
	}
}
```

Scoped asset records can be adjusted before registration with `emulsify_theme_acf_block_asset_records`.

## Duplicate handling

The child theme is scanned before the parent theme. Duplicate component paths, duplicate component slugs, and duplicate final ACF block `name` values are skipped instead of being registered twice.

When `WP_DEBUG` is enabled, skipped duplicates are logged and shown as admin-only notices to users who can edit themes. Normal frontend visitors do not see duplicate diagnostics.

### Scoped assets in subfolders

Asset paths declared by `*.component.json` remain component-scoped even when
CSS or JavaScript lives in nested folders. The parent reuses its component
metadata index and asset scan, including custom asset-only roots. Editor-only
files are excluded from global frontend enqueueing; components without a Twig
template retain that exclusion too.
