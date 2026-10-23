# Sister-project parity contract

Emulsify WordPress is the WordPress sister project to Emulsify Drupal. The projects should feel familiar to teams moving between CMS platforms while preserving the runtime conventions each CMS expects.

This contract defines the shared Emulsify model first, then the intentional WordPress differences.

## Shared Emulsify contract

Emulsify WordPress and Emulsify Drupal share the same project boundary:

- The parent theme owns reusable CMS runtime behavior.
- The generated child theme owns project implementation.
- Whisk is the starter used to generate real project child themes.
- Emulsify Core 4 provides the component workflow.
- Vite builds frontend assets.
- Storybook presents component examples.
- Twig is the component template language.
- Node 24 is the expected JavaScript runtime for generated child theme work.

The parent runtime should stay reusable and predictable. It provides CMS integration, fallback rendering, component discovery, asset loading, and extension points. Project-specific styling, components, stories, data fixtures, templates, and behavior belong in the generated child theme or in project plugins when the behavior is not theme-specific.

## Generated source and assets

Generated child themes are expected to own component source and built output. The selected Emulsify component system defines the project source structure:

- Whisk keeps an empty `src/components` placeholder for compatible systems, but it does not prescribe a component tree.
- Whisk does not ship default `tokens.scss`, `foundation.scss`, or `layout.scss` entrypoints.
- Storybook stories and data fixtures live with the component source chosen by the project.
- Built global assets are emitted under `dist/global`.
- Built component assets and block metadata are emitted under `dist/components`.

WordPress should load built child theme output before parent fallback output. A generated child theme can override parent components or templates intentionally, while the parent theme keeps minimal fallbacks available for routes and runtime services.

## Intentional WordPress differences

Emulsify WordPress is not a Drupal runtime port. It keeps parity at the Emulsify project-model layer and uses WordPress-native integration where WordPress needs it.

### Timber runtime

Frontend rendering uses Timber. The parent theme owns Timber bootstrapping, global context, Twig namespace registration, route fallbacks, helper functions, and Emulsify-compatible switch tags. Child themes supply project Twig templates and component source.

For new project component includes, prefer the generated child theme machine name from `project.emulsify.json`, using the general form `{% include "project_machine_name:component_name" %}`. The legacy `@components/component-name/component-name.twig` namespace remains supported for compatible component libraries, existing projects, shared templates, and migration work.

Custom legacy namespaces should use Emulsify Core's existing `variant.structureImplementations` entries in `project.emulsify.json`. Do not add Drupal `.info.yml` files to the WordPress starter for this purpose.

### Parent and child theme headers

WordPress theme identity lives in `style.css` headers. The parent theme is installed as `emulsify`, and generated child themes declare `Template: emulsify` so WordPress uses the parent runtime.

The generator updates the child theme `Theme Name`, `Text Domain`, `Template`, package metadata, and Emulsify project metadata for each generated project.

### theme.json surface

`theme.json` is the WordPress site and editor configuration surface. It carries editor settings, presets, and styles that WordPress and the Site Editor understand. This is separate from component source and should be treated as WordPress configuration, not as a replacement for Core 4 Sass and component assets.

Whisk does not include a child `theme.json` by default. Add one in a generated child theme when the project needs WordPress editor/global-style overrides; do not add an empty file just to mirror the parent.

### ACF/Twig block registration

ACF/Twig block registration is an optional WordPress integration. Built component folders can include `*.component.json` metadata and a Twig template. When ACF is available, the parent theme registers those components with `acf_register_block_type()` and renders them through Timber.

### Native block.json registration

Native Gutenberg blocks use WordPress `block.json` metadata. Projects add `block.json` intentionally when they need native editor APIs such as attributes, supports, editor scripts, view scripts, transforms, or dynamic rendering. The parent theme registers discovered built block folders with `register_block_type()`.

### WP-CLI child theme generation

WordPress project generation is handled by the parent theme's WP-CLI command:

```sh
wp emulsify "Acme Site" --machine-name=acme-site
```

The command copies Whisk to a sibling child theme and performs targeted metadata updates. It avoids broad recursive string replacement so generated projects keep predictable WordPress headers, package metadata, and Emulsify project metadata.

Emulsify CLI uses the standalone `https://github.com/emulsify-ds/emulsify-wordpress-starter` repository for the same child theme layer. That starter is sourced from `whisk/`, runs `.cli/init.js` after CLI dependency installation, and keeps `Template: emulsify` so WordPress loads the parent runtime theme.

### Emulsify platform metadata

`project.emulsify.json` uses `"platform": "wordpress"` so Emulsify Core and Emulsify CLI tooling can load the WordPress platform adapter. It also records `generatedFrom: "emulsify-wordpress"` and `generatedFromVersion` so support and upgrade tooling can identify the WordPress starter lineage. WordPress runtime behavior still lives in this parent theme for the 2.x release line.

This file may also hold Emulsify Core metadata such as `variant.structureImplementations`. `theme.json` remains the WordPress site and editor configuration surface, not a component-library or Twig namespace registry.

## Sync log

| Sister reference | WordPress target | Status |
| --- | --- | --- |
| Drupal 7.3.0: [7.2.2](https://github.com/emulsify-ds/emulsify-drupal/tree/7.2.2) plus [PR #382](https://github.com/emulsify-ds/emulsify-drupal/pull/382), pinned at [`f5d7d4a`](https://github.com/emulsify-ds/emulsify-drupal/commit/f5d7d4ac572fc583c734213879a15dde2140f6c3) | WordPress 2.1.0 | Implemented in [PR #47](https://github.com/emulsify-ds/emulsify-wordpress/pull/47); pending merge and release. |

This sync includes the 7.2.2 fixes underlying the Drupal 7.3.0 reference. The
WordPress work is collected in one release branch by maintainer decision.
[PR #42](https://github.com/emulsify-ds/emulsify-wordpress/pull/42)'s audit-wrapper
change is incorporated into PR #47; its separate PR is still open. These are
implementation references, not a claim that either release has been published.

### Ported or adapted

| Area | Classification and reason | Implementation |
| --- | --- | --- |
| Release-version contracts and stale release claims | Adapt: WordPress publishes prepared, committed version metadata, and semantic-release must calculate the same version. | [PR #47](https://github.com/emulsify-ds/emulsify-wordpress/pull/47); [release procedure](release-process.md). |
| Core 4.5, lint/format exit status, ESM tests and coverage, audit JSON, and sandboxed accessibility runner | Port/adapt: shared frontend tooling retains WordPress's failures for empty test suites and empty accessibility audits. | [PR #47](https://github.com/emulsify-ds/emulsify-wordpress/pull/47), including [audit-wrapper PR #42](https://github.com/emulsify-ds/emulsify-wordpress/pull/42). |
| Pager/menu current items, hidden Page prefix, and rendered accessibility audit | Adapt: Timber fallbacks and seven WordPress fixture routes replace Drupal render templates and routes. | [PR #47](https://github.com/emulsify-ds/emulsify-wordpress/pull/47); [accessibility contract](accessibility.md). |
| Public Twig extension blocks and removal of the starter page override | Adapt: additive parent blocks preserve WordPress template inheritance and existing child overrides. | [PR #47](https://github.com/emulsify-ds/emulsify-wordpress/pull/47); [template extension contract](template-extension.md). |
| Generated AI guidance and root contributor guidance | Adapt: library-neutral instructions describe Timber, WordPress hooks, blocks, and verification. | [PR #47](https://github.com/emulsify-ds/emulsify-wordpress/pull/47); [generated-theme contract](generated-child-theme-contract.md). |
| PHP/JavaScript Twig helper characterization | Port/adapt: a shared corpus records intentional helper differences and catches runtime bugs without silently changing contracts. | [PR #47](https://github.com/emulsify-ds/emulsify-wordpress/pull/47); [Twig authoring](timber-and-twig-authoring.md). |
| Action pins, blocking readiness matrix, dependency maintenance, and README assets | Port/adapt: shared validation policy uses WordPress versions and the supported PHP matrix. | [PR #47](https://github.com/emulsify-ds/emulsify-wordpress/pull/47); [dependency audit](dependency-audit-2026-10-23.md). |
| Privacy video embeds | Adapt, implemented for 2.1.0: render-time oEmbed filters, overridable iframe `srcdoc`, and cached background Vimeo metadata replace Drupal's same-origin embed document. Both options default to off. | [PR #47](https://github.com/emulsify-ds/emulsify-wordpress/pull/47); [media embeds](media-embeds.md). |

WordPress companion fixes in the same PR align grouped component discovery with
Core, raise the minimum WordPress version to 6.8 to match locked Timber, and
correct fallback language markup and failing link-color declarations. See the
[2.1.0 notes](release-notes-next.md) and [upgrade actions](../UPGRADE.md#upgrading-from-200-to-210).

### Intentional differences

| Sister feature | WordPress decision and reason |
| --- | --- |
| 24 px target-size rule and Drupal's parent asset build | Skip: the parent adds no frontend CSS to the surrounding page; projects own target size, `screen-reader-text`, and focus styles. Admin-only CSS is allowed. |
| Regions: `status`, `breadcrumb`, `highlighted`, `sidebar_first`, `sidebar_second` | Skip: WordPress uses menus, block patterns, and template parts; the parent registers no widget areas. Maintainers may revisit this choice. |
| Container and form template suggestions, inline form-error IDs, status-message/feed/progress template moves, and select option attributes | Skip: these belong to Drupal's render and Form APIs. |
| Favicon dark-mode icons, SVG sanitization, and generated social share image | Skip: WordPress uses core Site Icon and `custom-logo`; SEO plugins own `og:image`. |
| Default parent logo, library/breakpoint contract, Drupal.org channel-skew validation, Stable 9 parity, and the `dr` CLI | Skip: these are Drupal-only runtime and distribution contracts. |

The opt-in video poster is a separate iframe document with its own inline
accessibility styles, as approved for that feature. It adds no stylesheet to
the surrounding page; its CSP and override contract are documented in
[media embeds](media-embeds.md#poster-behavior-and-overrides).

## How to run a sync

- [ ] Read the sister release's notes and upgrade guide from a full clone with
  history and tags; record the exact tag and PR head used as the reference.
- [ ] Classify every change as port, adapt, or skip, with a reason grounded in
  the shared project model and native WordPress behavior.
- [ ] Open one PR per area, link the source changes, and keep both child-theme
  generators and their contracts aligned where applicable.
- [ ] Run the relevant readiness checks, including the full WordPress fixture
  for rendering or generation changes, and document consumer actions.
- [ ] Update this sync log, release notes, and upgrade guide with implementation
  links, intentional differences, and the actual merge/release status.

## Parity guardrails

Use this contract when evaluating future changes:

- Keep reusable CMS runtime behavior in the parent theme.
- Keep project implementation in the generated child theme.
- Keep Whisk small enough to work as a starter.
- Keep generated child theme source aligned with Emulsify Core 4 conventions.
- Keep WordPress-specific behavior explicit rather than hiding it behind Drupal naming or assumptions.
- The parent adds no frontend CSS to the surrounding page. Admin-only CSS is
  allowed; projects own target size, `screen-reader-text`, and focus styles.
  Opt-in video posters confine their accessibility styles to the iframe document.
- Keep `project.emulsify.json` on `"platform": "wordpress"` and `generatedFrom: "emulsify-wordpress"` so generated child themes advertise the WordPress platform adapter and starter lineage.
