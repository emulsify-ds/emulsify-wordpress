# Timber and Twig authoring

Timber provides the bridge between WordPress data and Twig templates. Emulsify WordPress keeps Twig authoring predictable by registering a small set of namespaces and helper functions from the parent theme.

## Bedrock and Timber

Timber is required for frontend rendering. Site projects can install Timber from the application-level Composer project or from the parent theme `composer.json`. If the parent theme owns the dependency, run:

```sh
cd web/app/themes/emulsify
composer install
```

In Bedrock, prefer the application-level Composer project when that is where PHP dependencies are managed. Either approach works as long as WordPress loads the Composer autoloader before the theme renders.

If Timber is missing, the parent theme shows an admin notice and stops frontend rendering with a clear runtime error.

## Twig namespaces

The parent theme registers these namespaces:

- `@templates`: child templates first, then parent fallbacks.
- `@emulsify-tpl`: parent templates only.
- `@components`: child component paths first, then parent component paths.

Use `@templates` for normal includes. Use `@emulsify-tpl` when a child override extends the parent template with the same relative path.

New generated themes inherit parent fallbacks without local Twig templates.
Create `templates/` when an override is needed. The [template extension
contract](template-extension.md) documents layout blocks, stable boundaries,
and the same-path inheritance recursion pitfall.

```twig
{% extends '@emulsify-tpl/page.twig' %}

{% block content %}
  <div class="project-page">
    {{ parent() }}
  </div>
{% endblock %}
```

## Component includes

Install or author project components in the structure defined by the selected Emulsify component system. For compatible component roots, new project includes can use the generated child theme machine name from `project.emulsify.json`:

```twig
{% include "project_machine_name:example-card" with {
  heading: post.title
} only %}
```

The general form is `project_machine_name:component_name`.
Project references such as `project_machine_name:button` and
`@project_machine_name/button` use Core's direct-then-grouped resolution. So do
`@components/button` and `@components/button/button.twig`; the component
namespace also accepts `components:button`, `components::button`, and
`components/button`.

Without `variant.structureImplementations`, WordPress retains these
compatibility roots in order:

1. Child theme `src/components`.
2. Child theme `components`.
3. Parent theme `src/components`.
4. Parent theme `components`.

With accepted structure implementations, the entry named `components` supplies
the child component root. If none has that name, the first accepted
implementation supplies it, matching Core's `components` namespace. That root
replaces both default child paths; it does not combine every implementation's
directory. Other implementation names register separate Twig namespaces.
Parent fallback roots remain available for theme inheritance.

Core resolves one standalone project's component root. WordPress's ordered
legacy and parent fallbacks are intentional compatibility extensions; within
each root, candidate and group ordering match Core.

Each root is searched completely before moving to the next. For a requested path `x`, direct candidates are tried in this order:

1. `x`.
2. `x.twig`.
3. `x.html.twig`.
4. `<x without a final .twig>/<stem>.twig`.
5. `<x without a final .twig>/<stem>.html.twig`.

Here, `stem` is the last segment after removing a final `.twig`; an explicit `.html.twig` therefore keeps `.html` in the nested stem. For example, `example-card` can resolve to `example-card.twig`, `example-card.html.twig`, `example-card/example-card.twig`, or `example-card/example-card.html.twig`.

If no direct candidate exists, the same candidates are tried below group directories in breadth-first order. Sibling directories are sorted by UTF-16 code unit, matching Core's JavaScript ordering. Thus `project_machine_name:button` can find `src/components/atoms/button/button.twig` or a component inside deeper groups. A grouped child component takes precedence over a direct parent component.

Duplicate names resolve deterministically: the first root wins, direct candidates precede grouped candidates, and shallower groups precede deeper groups. Use an explicit group or template path, such as `project_machine_name:atoms/button/button.twig`, to select an otherwise ambiguous component.

Group discovery skips `node_modules`, `.git`, `.cache`, `.vite`, `.out`, `.coverage`, and `dist`. It does not follow directory symlinks discovered during the walk. Explicit file or directory symlinks can resolve when both their lexical and real paths remain inside the component root. Internal `.` and `..` segments are normalized like Core. WordPress rejects a leading `..` segment before trying groups, even if Core could find a contained candidate from a deeper group.

Explicit unregistered prefixes also follow Core's fallback: `project:button`,
`anything:button`, and `@organisms/button` can resolve a component when no
registered Twig namespace owns the prefix. The resolver tries the literal
reference as a direct component path first, then removes the unregistered
prefix and tries direct and grouped candidates.

Registered namespaces always keep their scope, including plugin namespaces
and a namespace that happens to match the project machine name. A missing
`@templates/button.twig`, for example, never falls back to a component named
`button`. Registered single- and double-colon aliases delegate to the same
namespace as `@namespace/path`. Only the `components` namespace uses grouped
component lookup. Custom loaders must expose their namespace inventory through
`getNamespaces()` and namespace paths through `getPaths()` for this fallback
integration; loaders without those APIs keep their own resolution unchanged.

Other bare paths and bare `@name` references without a slash stay with the normal
WordPress/Twig loader. This differs from Core's bare component-file fallback
and preserves existing controller template lookup. Every path segment remains
intact: a missing `atoms/button` never falls back to `molecules/button`.

Group directories, successful and missing component resolutions, and decoded
project configuration are shared across loader instances for an HTTP request.
Timber can create several loaders during one compile; each canonical root is
scanned once, and direct hits do not scan groups. Cache keys distinguish the
project machine name and ordered canonical roots. Namespace ownership is
checked before using component results, so a registered namespace cannot be
shadowed by a cached fallback.

CLI processes, including WP-CLI and typical queue workers, bypass these caches
by default so file and configuration changes remain visible. The
`emulsify_theme_twig_request_cache_enabled` filter can change that policy. A
long-running process that enables caching must call
`\Emulsify\Theme\Runtime\Twig::clear_request_cache()` between jobs or after
changing component files or project configuration, then create fresh loaders
and Twig environments. Custom persistent HTTP workers must do the same at
request boundaries. This clears component/configuration memoization and PHP's
filesystem caches; Twig's own compiled-template cache is separate.

Group directories are not added to the persistent discovery cache: new groups
inside existing groups can leave the root directory's modification time, theme
version, and build manifest unchanged, so a persisted index could miss their
components. The existing persistent cache continues to serve built
`dist/components` discovery.

The legacy `@components/example-card/example-card.twig` namespace remains supported for compatible component libraries, existing projects, shared templates, and migration work:

```twig
{% include "@components/example-card/example-card.twig" with {
  heading: post.title
} only %}
```

The filtered `@components` namespace paths supply the default component roots.
`emulsify_theme_project_component_roots` runs afterward and can replace their
order for every component reference form. Later loader `addPath()` or
`prependPath()` calls for `components` update that order for all aliases too. Its machine-name argument is `''`
when project metadata is missing or invalid; `@components` and unregistered
prefix fallback still work in that case. Use `emulsify_theme_twig_namespaces`
for advanced runtime-only `@namespace/path.twig` paths.

Root-level `components` and `src/components` directories are compatibility paths, not starter requirements. Do not remove existing `@components` includes during migration; both forms are supported.

Maintainers can compare fixture paths with the installed Core 4.5.0 resolver
and count filesystem work across actual Timber compiles from this repository
checkout:

```sh
npm run test:component-resolution
npm run smoke:component-request-cache
```

The differential check records intentional WordPress compatibility differences
with reasons. Run `npm run smoke:component-request-cache -- --benchmark` for
the repeatable large-tree benchmark; it enables request caching explicitly to
model HTTP rendering from a CLI process.

A local PHP 8.3.36 run used 500 synthetic groups (about 1,500 added directory
and file entries), ten real Timber compiles per batch, and seven batches with a
warm filesystem cache. Median per-compile times fell from 93.9 ms to 3.84 ms
for a grouped target and from 0.470 ms to 0.291 ms for a direct target. Each
batch began with cleared request caches: the first grouped lookup still scans
the tree, and later compiles reuse it. A separate three-compile counting test
reduced child-root scans from nine to one, parent-root scans from three to one,
and project JSON reads from eighteen to one. Timings depend on the component
tree and filesystem; these are synthetic workload measurements.

See [Component recipes](component-recipes.md) for a small Twig component and Storybook story example that can be added to a generated child theme.

## Component-system namespaces

For legacy `@namespace/path/to/template.twig` references that should work in both Emulsify Core and WordPress, configure the namespace roots in `project.emulsify.json` with Core's existing `variant.structureImplementations` shape:

```json
{
	"project": {
		"platform": "wordpress",
		"name": "Example Project",
		"machineName": "example_project"
	},
	"variant": {
		"structureImplementations": [
			{
				"name": "components",
				"directory": "src/components"
			},
			{
				"name": "atoms",
				"directory": "src/components/atoms"
			},
			{
				"name": "molecules",
				"directory": "src/components/molecules"
			}
		]
	}
}
```

The explicit `components` entry keeps project aliases scoped to the full
component tree; group namespaces remain independent. WordPress trims and
lowercases implementation names, accepts plain ASCII letter/digit/underscore/
hyphen identifiers, and supplies `structure-N` for unnamed entries. Unsafe or
duplicate namespace names are skipped.

Then includes such as this resolve through the configured child-theme-relative root:

```twig
{% include "@atoms/button/button.twig" with {
  text: 'Read more'
} only %}
```

WordPress keeps its existing configuration containment rules: implementation
paths must be relative to the child theme and cannot contain `..` segments.
Absolute paths, including absolute paths inside the project, remain rejected;
Core accepts some of these contained paths. This restriction applies to
configuration paths, not safe internal dot segments in component references.

Do not use `theme.json` for Twig namespaces. `theme.json` is WordPress configuration for editor settings, global styles, presets, templates, and style variations; it is not a Twig loader configuration file.

## Escaping and trusted HTML

The parent theme enables Twig's HTML autoescaping for every Timber render path. Component values, including ACF fields and Gutenberg block attributes, are escaped by default:

```twig
<h2>{{ fields.heading }}</h2>
```

Use `|raw` only for already-rendered, trusted WordPress HTML. Do not use it to print plain ACF values, block attributes, query parameters, or other editor- or visitor-controlled strings.

```twig
{{ post.content|raw }}
{{ comment.content|wpautop|raw }}
```

The `bem()` and `add_attributes()` helpers build and escape their own attribute values and are registered as safe HTML. Print them directly without `|raw`.

### WordPress-formatted text

WordPress can return text that already contains HTML entities. Post and page
titles pass through `the_title`, which texturizes punctuation; site and term
names can be stored escaped; `get_search_query()` escapes its result by default.
Menu titles and author names can contain encoded characters too. Plain Twig
autoescaping would encode those entities again.

Use Timber's WordPress escaper filters at the output boundary. They handle both
raw text and existing entities and tell Twig that the result is safe HTML:

| Output | Filter |
| --- | --- |
| Post/page titles in headings and teaser links | `post.title\|wp_kses_post` |
| Site name, archive/search/author heading, menu title, author or comment-author name | `value\|esc_html` |
| Thumbnail alt text, including a post-title fallback | `function('wp_strip_all_tags', value)\|esc_attr` |

`wp_kses_post` preserves WordPress's allowed post markup and removes disallowed
tags, event handlers, and unsafe URL protocols. Its allowlist is broader than
inline markup. `esc_html` displays tag-looking text literally, without creating
elements. Strip literal tags and script/style contents from alt text before
`esc_attr`; encoded tag-looking text remains inert text in the attribute.

```twig
<h1>{{ post.title|wp_kses_post }}</h1>
<span>{{ site.name|esc_html }}</span>
<img src="{{ post.thumbnail.src }}" alt="{{ function('wp_strip_all_tags', post.thumbnail.alt|default(post.title))|esc_attr }}">
```

The parent keeps `get_search_query()`'s default escaping and prints the composed
search title with `esc_html`. Do not decode these values or use `|raw` as a
double-escaping workaround. Keep ordinary autoescaping for ACF values, parsed
block data, and other strings that do not have a WordPress formatting contract.

## Attribute helpers

The parent theme registers Core-style helpers for class and attribute handling:

```twig
<div {{ bem('example-card', ['featured']) }}>
  {{ content }}
</div>

<div {{ add_attributes({ class: ['foo'], 'data-component': 'example' }) }}>
  {{ content }}
</div>
```

The helpers return an attribute bag that serializes safely in Twig string contexts.

Both helpers merge the context's `attributes` with their explicit attributes.
Keep that context value for intentional HTML attributes, never raw block data
or arbitrary user input. Mapped core-block templates receive parsed block data
in `block_attributes`; see [core-block context](core-block-twig-rendering.md#twig-context).

The PHP attribute bag rejects every attribute name beginning with `on`,
case-insensitively, including `onclick` and `onMouseOver`. Construction, `set()`,
`merge()`, and serialization all enforce this rule. This is intentional
WordPress PHP hardening: Core's JavaScript helpers and Emulsify Drupal still
accept these names. Attribute-value escaping alone does not make event-handler
attributes safe.

Argumentless Twig calls such as `{{ add_attributes() }}` and `{{ bem() }}` use
only the context's `attributes`. Other context variables are not attributes or
BEM options. The registered callbacks keep that injected context separate from
explicit arguments; direct PHP helper calls retain their existing API.

## Helper characterization

The parent implements `bem()` and `add_attributes()` in PHP; Storybook uses
Emulsify Core's JavaScript helpers. Their shared API does not guarantee identical
behavior for every input. The maintainer contract records each implementation's
observed behavior separately in
`.github/fixtures/twig-helper-contract.json` in the repository checkout.
It uses the WordPress parent's own `AttributeBag`; Core's
[4.5.0 PHP corpus](https://github.com/emulsify-ds/emulsify-core/blob/v4.5.0/docs/twig-php-parity.md)
instead records a pinned Emulsify Tools counterpart. That corpus's portable
subset is not a guarantee about this WordPress implementation.

This check is for a maintainer checkout; its `.github` scripts and fixtures are
excluded from released parent ZIPs. Install the parent Composer dependencies
and the starter's declared Core dependency, then run it from the repository root:

```sh
composer install
npm run whisk:install
npm run test:twig-helpers
```

The command uses `whisk/` by default. To characterize the Core version installed
in another generated theme, pass its directory:

```sh
npm run test:twig-helpers -- "/path/to/generated-theme"
```

Each case compares three observations against that runtime's own expectations:
the exact serialized HTML string, the returned attribute map, and
`contextAfter`, the attributes remaining in the caller's context. Whitespace,
escaping, attribute types and order, and context mutation remain visible. The
check does not trim output, rewrite escaping, or normalize known differences
to make the PHP and JavaScript results appear equal.

The recorded ordinary positional/object BEM cases, aliases, nested lists, class
sanitization, list replacement, scalar number serialization, and escaping agree.
Selected differences remain explicit:

| Recorded input | WordPress PHP | Core 4.5.0 JavaScript |
| --- | --- | --- |
| A `true` BEM modifier | `card--1` | `card--true` |
| Numeric `0` as an extra class | `_0` | Omitted |
| `true` in an `add_attributes()` class list | `_1` | `true` |
| `true` in a non-class attribute list | `1` | `true` |
| Attribute names starting with `on`, regardless of case | Dropped | Accepted |
| Caller attributes supplied as an array or attribute bag | Copied; remain in caller context | Consumed from invocation context |

The fixture cases `bem-boolean-and-numeric-values`,
`add-booleans-aria-and-falsy-classes`, and
`add-invalid-names-and-nested-values` record the coercion differences.
`add-repeated-context-consumption` demonstrates that WordPress prints caller
attributes again on a second call, whereas Core has consumed them. WordPress's
own `AttributeBag` preserves the caller context too; changing from an array to
that bag does not provide print-once behavior.
`add-event-handler-names` records the intentional attribute-name hardening
difference in both the returned map and serialized HTML.

Both implementations currently treat boolean ARIA values like HTML booleans:
`true` can produce a bare attribute, and `false` can be omitted rather than
replacing an existing value. Supply explicit strings such as
`{ 'aria-expanded': 'false' }` for ARIA state values. This describes the tested
inputs, not equivalence for arbitrary attributes or Twig expressions.

Review changed expectations when updating a helper or Core dependency. A
characterization fixture records compatibility evidence; it does not decide
which runtime should change or promise universal PHP/JavaScript parity. The
check does not replace Twig autoescaping coverage or rendering the affected
template in WordPress with its actual Timber context and hooks.

## Switch statements

The parent theme registers the Emulsify-compatible `switch`, `case`, `default`, and `endswitch` tags used by Emulsify Core in Storybook and Emulsify Tools in Drupal. Use `or` to match more than one value in a case:

```twig
{% switch variant %}
  {% case 'primary' or 'secondary' %}
    <span class="badge badge--strong">{{ label }}</span>
  {% default %}
    <span class="badge">{{ label }}</span>
{% endswitch %}
```

The `default` branch is optional and, when present, follows the case branches. Each matching case stops automatically, so switch blocks do not fall through to later cases. Matching follows PHP switch semantics in Timber and the equivalent scalar behavior in Emulsify Core's Twig.js runtime.

## Global context

The parent theme adds common values such as `site`, `theme`, `menu`, `post`, `wp`, and `body_class` to the Timber context. Child themes and project plugins can add values with:

```php
add_filter(
  'emulsify_theme_context',
  function ( array $context ): array {
    $context['project'] = array(
      'name' => 'Acme Site',
    );

    return $context;
  }
);
```
