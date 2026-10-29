# Upgrade guide

Emulsify WordPress is a parent theme. Generated child themes do not inherit
later starter changes, so most upgrades have two parts: update the parent theme,
then decide which starter changes to adopt in each generated project.

## Upgrading from 2.0.0 to 2.1.0

Before updating the parent theme, review the conditional actions below against
the project's overrides and components. Existing generated child themes are not
rewritten: their copied scripts, configuration, templates, and documentation
remain project-owned. New generated themes receive the updated defaults.

### Required actions

1. **Update WordPress first if the site is on 6.7.** The parent now requires
   WordPress 6.8 or newer, matching the locked Timber dependency. PHP remains
   8.3 or newer; the PHP and Timber dependency constraints do not change.
2. **Review component resolution if the project has ambiguous paths.** Project
   references now follow Core's candidate order: a flat `component.twig`
   precedes `component/component.twig`, and a child's grouped component wins
   over a parent's direct component. Duplicate names resolve by root, direct
   candidate, then breadth-first group order. `@components` now uses that same
   grouped lookup: a grouped child source component can win over a direct file
   in a later legacy or parent root. Use an explicit group or template path
   where a project needs one particular component.
   If `variant.structureImplementations` is configured, its `components` entry,
   or its first accepted entry when none is named `components`, replaces the
   default child component roots. Move components or use an explicit registered
   namespace if includes relied on `src/components` or `components` staying
   available alongside the override. Parent fallbacks remain available.
   Implementation names are trimmed and lowercased; use plain identifiers
   without an `@` prefix. Unnamed entries receive `structure-N` names.
   Unregistered explicit prefixes now fall back to component lookup as in Core;
   registered namespaces retain their scope. Literal prefixed component files
   are tried before removing an unregistered prefix, matching Core. Registered
   namespace colon aliases now use their named namespace. The `components:...`,
   `components::...`, and `components/...` aliases now target component lookup;
   use `@templates/components/...` for a controller template under that path.
   Custom loaders need `getNamespaces()` and `getPaths()` to support this
   integration; opaque loaders keep their native behavior.
   Other bare paths keep every segment and stay with WordPress's normal Twig
   loader. Correct references that relied on a
   missing first segment falling back to another group. See the
   [component resolution order](docs/timber-and-twig-authoring.md#component-includes).
3. **Apply the relevant fixes to child overrides.** Unmodified parent fallbacks
   receive the fixes automatically; project copies need review:
   - Move separate posts-page markup from `front-page.twig` to `home.twig`.
     `front-page.twig` now serves only the front page, whether it shows posts or
     a static page, ahead of `home.twig` or `page.twig`. Protected static front
     pages still use the password template until the password is accepted.
   - Mapped core-block templates receive a cached global Timber context and a
     fresh block-specific `post`, without setting up WordPress's global post.
     Add any required route-specific `posts`, `term`, `author` or `search_query`
     values through the block context filter. Use `content` for current-block
     HTML, rather than `post.content`. For blocks with children, `inner_content`
     now equals the full rendered `content`, including its saved wrapper; print
     one of them once. See [mapped block context](docs/core-block-twig-rendering.md#twig-context).
   - `templates/partials/pagination.twig`: mark only `page.current` with
     `aria-current="page"`; display a translated literal `…` in the existing
     non-interactive span; decode Timber's display-escaped URL entities before
     passing every page, previous, and next URL to `add_attributes`.
     Decode numbered `page.title` entities once before Twig autoescaping,
     for both linked and current numbers, so localized non-breaking thousands
     separators display correctly. Never apply `|raw` to these titles.
   - Numbered pagination links: prefix the number with translated `Page ` in
     `span.screen-reader-text`. Keep the current-page span, ellipsis, and
     Previous/Next text unchanged.
   - `templates/partials/menu.twig`: mark only links whose `item.current` flag
     is true with `aria-current="page"`; leave ancestors unmarked.
   - Base layout: print Timber's trusted `site.language_attributes` fragment
     as markup so `lang` and `dir` parse correctly, while retaining
     autoescaping for other values.
   - Argumentless Twig `bem()` and `add_attributes()` now read only inherited
     `attributes`. Pass intended values explicitly if a template relied on
     unrelated Timber context variables becoming attributes or BEM options.
     Explicit-argument calls and the direct PHP helper API are unchanged.
4. **Check the rendered site's accessibility and link states.** Projects own
   `screen-reader-text`, visible focus styles, and pointer target sizing. The
   parent ships no stylesheet, but its `theme.json` produces WordPress global
   styles on the frontend: h1–h6 color and typography, visited-link color,
   root padding, button border radius/width and padding, and quote/cite
   typography and margins plus cite color. It also produces preset custom
   properties and `.has-*` classes, which style nothing until content uses
   them. These rules do not supply the project's accessibility helpers. The
   rendered audit disables
   only axe's `target-size` rule; that exception does not establish WCAG 2.2
   SC 2.5.8 compliance. See
   [project responsibilities](docs/accessibility.md#project-responsibilities).
   The parent's normal pale-blue link color has been removed because it failed
   contrast on white. Its unresolved hover, focus, and active declarations
   have also been removed; those declarations caused color inheritance, so
   removal can reveal an existing link color. Review normal, visited, and
   focused links against project backgrounds. Quote text now inherits the
   page's text color instead of the pale blue that failed contrast on white;
   review project quote presentation. The removed button color object never
   applied, and quote spacing is unchanged: the previously unresolved bottom
   quote and top cite margins are now explicitly `0`. Other styles and
   presets remain.
5. **Migrate block-data reads if the project opted into experimental core-block
   Twig rendering.** In mapped templates and
   `emulsify_theme_core_block_twig_context` filters, replace
   `attributes.<key>` reads with `block_attributes.<key>` (in PHP,
   `$context['block_attributes']['level']`, for example). Until migrated, those
   reads return empty values because `attributes` is now an empty array reserved
   for HTML attributes. Block data remains available as `block.attrs` and
   `block_metadata.attrs`. Do not copy `anchor` or `className` into HTML
   `attributes`: the wrapped WordPress content already carries them, and a
   copied anchor would duplicate its ID. The PHP attribute bag also rejects
   case-insensitive `on*` names to prevent event-handler attributes. See
   [core-block context](docs/core-block-twig-rendering.md#twig-context).
6. **Review password-protected content in child overrides.** Check
   `post.password_required` in copied `page.twig`, `single.twig`,
   `partials/tease.twig`, and `partials/tease-post.twig` before printing protected
   content or excerpts. The parent now enables Timber's password-form handling
   for `post.content`, including child templates; `post.excerpt`, raw
   `post.post_content`, and custom rendering still need an explicit guard.
   Protected pages and posts share `single-password.twig`, which now uses
   WordPress's filtered password form and site URL. Review styles targeting the
   former custom form; core uses `.post-password-form`. See
   [password-protected template handling](docs/template-extension.md#password-protected-content).
7. **Use WordPress escapers for formatted text in child overrides.** Print
   post/page titles in headings and links with `|wp_kses_post`; site names,
   archive/search titles, menu titles, and author names with `|esc_html`.
   Strip literal tags with `wp_strip_all_tags` before `|esc_attr` for alt text,
   including title fallbacks. These filters avoid encoding WordPress entities
   twice while retaining output safety. Keep Twig autoescaping enabled and do
   not substitute `|raw`. See
   [WordPress-formatted text](docs/timber-and-twig-authoring.md#wordpress-formatted-text).

8. **Review values passed to `bem()` and `add_attributes()`.** The PHP bag now
   rejects `srcdoc`, expression-bearing Alpine/Vue and htmx directives, and
   leading `:` bindings in addition to native `on*` and `@` handlers. URL
   attributes use WordPress's allowed protocols; default policy strips
   `javascript:` and `data:`. Replace data-URI image sources with permitted
   URLs. Pass attribute maps as arrays, Traversables or `AttributeBag`, rather
   than arbitrary objects such as `WP_Post`. `CLASS` now merges into `class`.
   See [attribute rules](docs/timber-and-twig-authoring.md#attribute-helpers).

Render representative pages as both an administrator and an anonymous visitor,
check the editor where blocks are used, and inspect `debug.log`. Storybook
alone does not exercise Timber context or WordPress hooks.

### Optional actions for existing generated themes

Choose the changes below individually and preserve local customizations. A
parent update does not copy starter files into an existing child theme.

#### Update Core and the effective Node version

New child themes use Emulsify Core `^4.5.0`. To adopt it in an existing project,
change the dependency and refresh the project's lockfile with `npm install`:

```diff
 "dependencies": {
-  "@emulsify/core": "^4.3.2"
+  "@emulsify/core": "^4.5.0"
 }
```

Use Node.js 24.13.0 or newer for Core 4.5.0. The generated theme's `>=24` engine
and `.nvmrc` value `24` do not express that effective minor-version floor; check
the resolved Core package's engines after dependency updates. Core 4.5.0 adds
bounded accessibility concurrency and an optional
`@emulsify/core/a11y/wcag22` preset. The preset is not enabled automatically.

#### Preserve every lint and format failure

Adopting these copied script fixes is recommended so CI cannot silently pass a
failed first check. Update the existing project's `package.json`; both steps
still run:

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

#### Adopt the Core accessibility runner

The accessibility command stays the same before and after:

```json
{
  "scripts": {
    "a11y": "npm run storybook-build && node config/emulsify-core/run-a11y.js"
  }
}
```

Update Core to `^4.5.0` first, then replace the copied
`config/emulsify-core/run-a11y.js` with the current starter's thin wrapper.
Changing `package.json` alone does not replace the old runner. The new wrapper
uses Core's bounded concurrency, complete reporting, and static server, while
retaining WordPress's failure when no stories are found. Core loads project
`config/emulsify-core/a11y.config.js` from the working directory and defaults
Storybook output to `.out`; existing `pa11y.wait` settings still apply. Preserve
project configuration and use a sandbox-capable Chrome. Never disable its
sandbox to make a check pass. Choosing the WCAG 2.2 preset remains optional.

#### Run ESM tests and collect project coverage

Copy `config/jest.config.js` from a fresh comparison theme and update both test
commands together, preserving project-specific Jest settings:

```diff
 "scripts": {
-  "test": "jest --coverage --passWithNoTests --config ./config/jest.config.js",
-  "twatch": "jest --no-coverage --watch --verbose --passWithNoTests --config ./config/jest.config.js",
+  "test": "node --experimental-vm-modules node_modules/jest/bin/jest.js --coverage --config ./config/jest.config.js",
+  "twatch": "node --experimental-vm-modules node_modules/jest/bin/jest.js --no-coverage --watch --verbose --config ./config/jest.config.js"
 }
```

This optional migration makes an empty project's test command fail, matching new
generated themes. Add a real project test before enabling it in existing CI.
The defaults retain `rootDir: '..'` and the existing test locations, add
`transform: {}` and V8 coverage, and exclude dependencies, built files, stories,
and test files from source coverage. Coverage includes eligible project files
that tests do not import. Projects using JSX or TypeScript need their own
transformer that emits ESM.

#### Keep audit JSON machine-readable

The copied `audit` and `audit:twig-stories` wrappers now send their documentation
footers to stderr and preserve the underlying audit's exit status. Copy those
two script values from a fresh comparison theme if adopting the fix. If project
automation parses audit JSON, adopt it before relying on stdout as JSON, and
capture diagnostics separately. See the [project audit](#project-audit).

#### Simplify template overrides

New generated themes no longer ship `templates/page.twig` or a `templates/`
directory. Existing 2.x children can keep their override: it still inherits
parent fixes when it extends `@emulsify-tpl/page.twig` and calls `parent()`.
Search project CSS for the `.<machine>-page` wrapper, such as `.acme-site-page`,
before deleting the file. Remove it only if the wrapper is unused and the file
has no other project customizations.

The new `site_header`, `main`, and `page_title` layout blocks are additive and
do not change the default output. Existing `head`, `header`, `content`, and
`footer` names and boundaries remain. Use the
[public template extension contract](docs/template-extension.md) when adding
an override, including preserving the `#content` skip-link target. A same-path
child override extends `@emulsify-tpl/...`; extending `@templates/...` resolves
back to itself.

#### Enable privacy-friendly video embeds

YouTube and Vimeo oEmbeds gain optional privacy mode and JavaScript-free
click-to-load posters. Both are off by default. Existing child themes inherit
the runtime and parent poster fallback without copying starter files. Enable
them with `emulsify_theme_media_embed_options` only after reviewing the site's
Content Security Policy. The `srcdoc` document inherits that policy, including
inline-style restrictions; the poster's CSS is confined to its iframe.

Provider posters still request a third-party thumbnail before activation;
choose `poster: 'neutral'` to avoid that request. The provider receives data once
the player loads, and Back returns to the poster. A child can override
`templates/partials/oembed-poster.twig`. See [media embeds](docs/media-embeds.md)
for the options, override contract, metadata cache, and CSP requirements.

#### Adopt project-local AI guidance

New generated themes include `AGENTS.md`, `docs/wordpress-integration.md`, and
`.agents/skills/emulsify-wordpress-integration/SKILL.md`. Existing projects can
copy these token-free files from a fresh comparison theme and adapt them. Keep
the hidden `.agents/` directory when copying, and resolve the guide's links
within the project. Start assistant sessions in the child theme directory for
skill discovery. Generation does not change site-root agent instructions.

### Release maintainers

The release-tooling changes need no site runtime or generated-theme edits.
Maintainers need a full Git checkout with stable tags to run release checks.
Prepare root `package.json` and every derived version together in a release PR,
then confirm semantic-release computes that exact version. A checkout still at
the latest tagged version verifies GitHub publication in CI before skipping.
Use the [local dry-run requirements](docs/release-process.md#local-release-dry-runs)
for pushed branches and token setup. Follow the
[release PR procedure](docs/release-process.md#release-pr-procedure), including
re-syncing the standalone starter before release.

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
