# Upgrading from 1.x to 2.x

Emulsify WordPress 2.x changes the project model. The repository is now a Timber-first WordPress parent theme that ships a generated child theme starter. Projects should customize the child theme instead of editing parent runtime classes.

## What changed

- The parent theme owns WordPress runtime behavior in `includes/`.
- The parent theme owns minimal Timber fallback templates in `templates/`.
- Generated child themes own project components, template overrides, source Sass and JavaScript, and built assets.
- The selected Emulsify component system defines the component source structure; Whisk does not ship default foundation, layout, token, or button files.
- The starter child theme uses Emulsify Core 4, Vite, Storybook, and Twig.
- The generated child theme keeps `project.emulsify.json` set to `"platform": "wordpress"` for Emulsify Core and Emulsify CLI WordPress adapter support.
- New generated child themes also include `generatedFrom: "emulsify-wordpress"` and `generatedFromVersion` in `project.emulsify.json` so future upgrades and support checks can identify starter lineage. Older generated child themes may not include these fields until they are regenerated or updated intentionally.
- Release metadata is aligned around the `2.0.0` stable release.

## Before upgrading

Inventory the current project before moving code:

- Custom WordPress hooks or theme setup changes.
- Template overrides.
- Component source files.
- Built assets committed to the theme.
- Block registration metadata.
- Any assumptions about old build scripts or paths.

Do not move everything into the parent theme. The parent should stay reusable; project code belongs in the generated child theme or a project plugin.

## Migration checklist

1. Install the 2.x parent theme as `emulsify`.
2. Generate or update a child theme from the Whisk starter.
3. Install or configure the chosen Emulsify component system, then move project components into that system's source structure.
4. Move only intentional template overrides into the child theme `templates` directory.
5. Rebuild child theme assets with Vite.
6. Confirm WordPress is activating the child theme, not the parent.
7. Run the validation commands from the parent repository root.

## Template changes

The parent theme now provides minimal Timber fallbacks for normal WordPress routes. Child themes should not copy every parent template. Add a child template only when the project needs to override or extend that fallback.

When a child template extends the parent template with the same relative path, use the parent-only namespace:

```twig
{% extends '@emulsify-tpl/page.twig' %}
```

Using `@templates/page.twig` from a child `templates/page.twig` would resolve
back to the child file. New generated themes ship no Twig templates or
`templates/` directory; create one only when needed. See the [template extension
contract](template-extension.md) for the supported blocks and boundaries.

## Block metadata changes

ACF/Twig block metadata and native `block.json` files are discovered from built `dist/components` output. The child theme is scanned before the parent theme, and duplicate definitions are skipped instead of being registered twice.

For ACF/Twig blocks, the final ACF block `name` is checked after defaults, metadata, and filters are merged. For native blocks, the `name` value in `block.json` is checked before registration.

## Validation

Run:

```sh
npm run pr:check
npm run release:check
```

The full WordPress fixture smoke test needs WP-CLI and a database. If `wp` is not available locally, `release:check` reports that fixture as skipped.
