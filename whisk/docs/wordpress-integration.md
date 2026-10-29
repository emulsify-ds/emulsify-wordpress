# Integrate project components with WordPress

This guide covers the WordPress side of an Emulsify child theme. The installed
component library defines its own structure, APIs, tokens, entrypoints, and
build outputs. The project maps WordPress content to those components.

## Discover the project and component

Work from the generated theme root. Read [AGENTS.md](../AGENTS.md),
`project.emulsify.json`, `package.json`, `style.css`, existing templates, and
project hooks. Check `project.machineName`, the child theme's `Text Domain`,
and `Template: emulsify`; preserve `generatedFrom` and `generatedFromVersion`.

If `.cli/init.js` exists, this directory is a generation source. Follow the
enclosing repository's workflow and verify generated output before treating a
source edit as a working project integration.

Read `docs/component-library.md` when installed, plus relevant local
instructions, schemas, stories, source, and tests. Project metadata such as
`system` and `variant.structureImplementations` can identify the selected
library and source roots. Discover actual component inputs, slots, assets, and
namespace mappings instead of importing another project's conventions.

After installing npm dependencies, inspect the available component inventory:

```bash
npm run inspect:components -- --json
```

The inspector locates non-partial Twig templates recognized by Emulsify Core
and reports source/output paths, references, and namespace collisions. An
empty project returns a valid empty inventory. This does not establish the
complete component API or prove registration in WordPress.

## Select the WordPress extension point

Confirm the actual query, post type, content fields, permissions, and active
theme in the site. WordPress first chooses a PHP controller through its normal
template hierarchy; the parent's controllers then pass candidates to Timber:

- `page.php` renders `@templates/page.twig`.
- `single.php` tries `@templates/single-{post_type}.twig` before
  `@templates/single.twig`; protected posts use `single-password.twig`.
- Listing fallbacks try `@templates/partials/tease-{post_type}.twig` before
  `@templates/partials/tease.twig` for each result.

Inspect the installed controller and existing child overrides rather than
assuming that a route uses a particular template. Locate the parent through
WordPress's `get_template_directory()` and read its `docs/template-extension.md`
and matching templates. Installed parent paths vary between sites.

Fresh generated themes contain no Twig templates or `templates/` directory.
Create only the overrides needed. `@templates` searches child templates before
parent fallbacks; `@emulsify-tpl` selects only the parent. A same-path child
override must extend the latter to avoid resolving back to itself:

```twig
{% extends '@emulsify-tpl/page.twig' %}

{% block content %}
  {{ parent() }}
  <!-- project content extension -->
{% endblock %}
```

Inspect the installed parent's extension blocks before replacing markup.
The layout exposes `head`, `site_header`, `header`, `main`, `page_title`,
`content`, and `footer`; the installed parent's guide defines their boundaries.
Preserve `wp_head()`, `wp_body_open()`, `wp_footer()`, and applicable WordPress
actions. Keep the skip link and its `#content` target, including the main
element's focus behavior, along with headings, accessible names, and keyboard
interaction. Calling `parent()` keeps a block's inherited contents.

## Map WordPress data to components

Keep project-specific hooks in `functions.php` or an appropriate project
plugin. Use the parent's `emulsify_theme_context` filter to extend global Timber
context, preserving inherited `site`, `theme`, `post`, `menu`, and `wp` values
unless intentionally replacing them. Inspect the component's contract and
actual post, taxonomy, media, or ACF data before choosing a mapping; do not
assume example fields exist.

New project references use `project_machine_name:component_name` or
`@project_machine_name/component_name`, taking the machine name from project
metadata. Both support grouped components. The compatible
`@components/path/to/template.twig` namespace remains available; custom library
namespaces can use `variant.structureImplementations`. Confirm the selected
root and exact path when duplicate names exist.

Pass only the component's documented inputs. Twig `only` isolates include
context, so explicitly provide every required value, attribute, and slot.
Timber's Twig environment autoescapes HTML. Use `raw` only for trusted rendered
HTML, such as `post.content` after WordPress content filtering; never use it to
bypass escaping on plain ACF values, query parameters, or editor-entered text.
The `bem()` and `add_attributes()` helpers escape their own attribute values.
Keep UI labels translatable using the child's `Text Domain` from `style.css`,
normally its machine name, and preserve relevant content filtering and access
or password checks.

Choose the project's existing WordPress integration type intentionally:

- Plain Twig components are included from project templates or other components.
- ACF/Twig blocks require active ACF, built `*.component.json` metadata, and a
  matching Twig template under `dist/components`. Their render context includes
  `block`, `fields`, `content`, and `is_preview` alongside normal Timber context.
- Native blocks use built `block.json` folders registered through WordPress's
  Block API. Keep their assets in native metadata such as `style`, `editorScript`,
  and `viewScript`; a plain Twig file does not register a block.
- JSON files directly under `patterns/` supply serialized block patterns.
  Add child `theme.json` only for intentional editor/global-style settings,
  presets, or styles; it does not configure Twig namespaces.

For an existing core block intentionally mapped to Twig, inspect the installed
parent's `docs/core-block-twig-rendering.md` and the project's mapping. That
optional bridge is separate from registering a new block.

Storybook does not execute the site's Timber context, ACF, or WordPress hooks.
Supply story inputs explicitly and verify PHP helper behavior in WordPress.

## Build and verify the integration

Follow the library's build contract and use scripts in this theme's
`package.json`. The parent discovers built child output under `dist/global`
and `dist/components` before parent output, preferring a valid asset manifest
when supplied. Rebuild changed sources and inspect emitted files and loaded
assets. A source edit alone does not update a built block. Extend shared
tooling through `config/emulsify-core/`; keep project edits out of installed
`node_modules` packages.

Built component discovery is memoized per request and may use a persistent
cache outside local/development or `WP_DEBUG` environments. After changing
discovered block metadata or deployment output, clear the current discovery
key through the site's configured maintenance workflow when necessary:

```php
\Emulsify\Theme\Blocks\ComponentLocator::clear_discovery_cache();
```

This does not clear unrelated WordPress page caches or replace a build. Source
Twig group lookup is cached only for the request. Refresh other site or Twig
caches through the site's existing tooling when applicable.

Confirm the intended child theme is active, activating it through the site's
local/test workflow when needed. Run relevant lint, tests, builds, inspection,
and accessibility checks from [the development guide](development.md); builds
and Storybook need library inputs, and the default test command fails when no
tests exist. Render the affected WordPress route anonymously and as an
administrator. Check empty/populated content, translations, permissions, password-protected
content, editor previews and block insertion as relevant. Verify assets and
keyboard behavior, and inspect `wp-content/debug.log` with local debugging
enabled. Keep debug settings out of production configuration.

Report the selected template/component, mappings or assets changed, checks
completed, and exact site behavior left unverified when WordPress or required
tooling is unavailable. See [support information](support-information.md) for
diagnostics and [upgrading](upgrading.md) for existing project updates.
