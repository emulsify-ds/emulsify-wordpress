# Core block Twig rendering

Related: [ACF/Twig blocks](acf-twig-blocks.md), [Native Gutenberg blocks](native-gutenberg-blocks.md), [Block patterns](block-patterns.md).

The parent theme includes an experimental `CoreBlockTwigRenderer` service that can render explicitly mapped WordPress blocks through Twig templates. It is disabled by default and does not change frontend output unless a child theme or project plugin opts in.

Use this carefully. Replacing core block output can affect block validation, style supports, accessibility attributes, plugin integrations, and future WordPress markup changes. Keep Twig output compatible with the saved block attributes and test editing, saving, reloading, and frontend rendering for every mapped block.

See [Component recipes](component-recipes.md) for guidance on when to choose core block Twig rendering instead of a plain Twig component, ACF/Twig block, native block, or pattern.

## Enable the service

Enable rendering and provide an explicit block-to-template map:

```php
add_filter( 'emulsify_theme_core_block_twig_rendering_enabled', '__return_true' );

add_filter(
	'emulsify_theme_core_block_twig_template_map',
	function ( array $map ): array {
		$map['core/paragraph'] = 'dist/components/paragraph/paragraph.twig';
		$map['core/heading']   = 'dist/components/heading/heading.twig';

		return $map;
	}
);
```

Mapped template paths are child/parent theme relative. The active child theme is checked first, then the parent theme. If a block is not mapped, or the mapped Twig file is not readable, WordPress' original rendered block content is returned unchanged.

## Twig context

Mapped templates receive the normal Timber context plus these values:

- `block`: parsed block metadata from `render_block`.
- `block_metadata`: alias for `block`.
- `block_name`: block name such as `core/paragraph`.
- `attributes`: an empty array reserved for HTML attributes.
- `block_attributes`: the parsed block attributes, also available as
  `block.attrs` and `block_metadata.attrs`.
- `content`: WordPress' original rendered block content.
- `inner_content`: saved string fragments for a leaf block; the full rendered
  `content` when fragments contain child placeholders or no usable text.
- `inner_blocks`: parsed inner block records when available.
- `wp_block`: the `WP_Block` instance when WordPress passes one.
- `twig_template`: the mapped Twig template.

`inner_content` includes the saved block's wrapper. For a group, quote or other
block containing children, it deliberately equals `content`, including every
child's final rendered output. Children are never rendered again. Capturing
generic `render_block` output would miss later block-name filters and children
replaced through `pre_render_block`, so the renderer uses WordPress's assembled
HTML. Print either `content` or `inner_content` once; printing both duplicates
the block. Neither value promises wrapper-free children for a replacement
component wrapper.

Keep block data separate from HTML attributes: `bem()` and `add_attributes()`
merge the context's `attributes` into their output. Never put block data or
untrusted user input there. The renderer does not copy `anchor` or `className`
into this array; WordPress' rendered `content` already carries those values,
and copying an anchor would duplicate its ID.

Use `emulsify_theme_core_block_twig_context` to add project-specific values:

```php
add_filter(
	'emulsify_theme_core_block_twig_context',
	function ( array $context, array $block ): array {
		if ( 'core/heading' === ( $block['blockName'] ?? '' ) ) {
			$context['heading_level'] = $context['block_attributes']['level'] ?? 2;
		}

		return $context;
	},
	10,
	2
);
```

## Optional class cleanup

The renderer does not strip `wp-block` classes by default. If a project needs class cleanup, opt in separately:

```php
add_filter( 'emulsify_theme_core_block_twig_cleanup_classes_enabled', '__return_true' );
```

Cleanup uses `WP_HTML_Tag_Processor` when available and otherwise leaves Twig output unchanged. Use `emulsify_theme_core_block_twig_cleanup_class_names` to control exactly which classes are removed.

## Error handling

If a mapped template throws an exception or Timber is unavailable, normal frontend visitors receive WordPress' original block output. Diagnostics are only appended for users who can edit posts or edit theme options, and debug logging only runs when `WP_DEBUG` is enabled.

## Filters

- `emulsify_theme_core_block_twig_rendering_enabled`
- `emulsify_theme_core_block_twig_template_map`
- `emulsify_theme_core_block_twig_template`
- `emulsify_theme_core_block_twig_context`
- `emulsify_theme_core_block_twig_cleanup_classes_enabled`
- `emulsify_theme_core_block_twig_cleanup_class_names`
