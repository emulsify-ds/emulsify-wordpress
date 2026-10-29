# 2.1.0 Release Notes Draft

Status date: 2026-10-28. WordPress 2.1.0 remains unreleased; the parity work and
release preparation must merge before publication. These notes describe changes
since `2.0.0`, including the sync with [Drupal 7.3.0, published on October 28](https://github.com/emulsify-ds/emulsify-drupal/releases/tag/7.3.0).
The [sync log](sister-project-parity.md#sync-log) records the published tag and
the platform-specific classifications.

## Version strategy

Latest published tag: `2.0.0`. This draft tracks the upcoming `2.1.0` release.

The release PR prepares `2.1.0` in root `package.json` together with every
derived version surface. The version contract accepts the
latest stable tag or an untagged next patch, minor, or major, and requires full
Git history and tags. It rejects already-tagged draft versions and stale claims
about the latest release.

Publishing skips successfully when no version is prepared after CI verifies
that the latest tag has a published GitHub release and its ZIP. Once `2.1.0` is
prepared, semantic-release must compute exactly `2.1.0` from Conventional
Commits before publishing the committed theme headers, ZIP, and generated-theme
lineage. See the [release process](release-process.md).

## Added

- Optional privacy mode and JavaScript-free click-to-load posters for YouTube
  and Vimeo oEmbeds. Both default to off. The
  `emulsify_theme_media_embed_options` filter controls privacy, activation, and
  provider or neutral posters; children can override the accessible `srcdoc`
  document. Posters declare the document's language and direction. See
  [media embeds](media-embeds.md) for CSP and privacy requirements.
- Public `site_header`, `main`, and `page_title` Twig blocks let children extend
  the parent layout without copying it. Existing block names, boundaries, and
  default output are preserved and tested. See
  [template extension](template-extension.md).
- Generated themes include portable `AGENTS.md` guidance, a WordPress
  integration guide, and an optional integration skill. Both generators retain
  identical guidance, including the hidden `.agents/` directory. The root
  contributor guide documents maintainer ownership and validation.
- Rendered WordPress accessibility checks cover seven fixture routes, including
  paginated content and video posters. They retain HTML and axe reports, check
  current menu/pager items, and use five WCAG tags through WCAG 2.2 AA. Only
  `target-size` is disabled because projects style the fallbacks; see
  [accessibility](accessibility.md).
- A shared fixture characterizes PHP Twig helpers against installed Emulsify
  Core JavaScript helpers, recording known differences explicitly. Run
  `npm run test:twig-helpers`; see
  [Twig authoring](timber-and-twig-authoring.md).

## Changed

- Release publishing waits for the full reusable readiness workflow and reuses
  its tested ZIP with a SHA-256 checksum. Readiness audits the shipped Composer
  dependencies and logs Whisk dependency findings. Release credentials remain
  scoped to the commands that need them; all concrete release jobs have time
  limits. The root npm package is private, and Husky 9 installs a real commit
  message hook in clones and worktrees.
- Root development-only release tooling adopts Drupal 7.3.0's refreshed
  semantic-release, GitHub plugin, commitlint, and lint-staged baselines.
  WordPress retains its prepared, committed release-version model.
- New generated themes use Emulsify Core `^4.5.0`, with an effective Node.js
  minimum of 24.13.0. Existing child manifests and lockfiles remain
  project-owned.
- New generated themes ship no Twig overrides or `templates/` directory. Pages
  use parent fallbacks; projects create overrides only when needed.
- Project tests execute native ESM, collect project coverage including untested
  source, and fail when no tests exist. Lint, lint-fix, and format run all steps
  while preserving any failure. The accessibility wrapper uses Core's bounded
  runner, preserves the empty-audit failure, honors `pa11y.wait`, and keeps
  Chrome's sandbox enabled.
- CI pins all actions to commit SHAs, updates them through Dependabot, and
  protects the blocking WordPress/PHP matrix with a contract check. Compatible
  release-tooling lockfile updates address independently resolved dependency
  findings; remaining development findings and upstream owners are recorded in
  the [October 23 audit](dependency-audit-2026-10-23.md).

## Fixed

- Installable ZIPs contain only committed inputs from one Git revision and
  refuse symlinks instead of packaging their targets.

- Both child-theme generators preserve JSON object/list types, rewrite nested
  patterns, sanitize metadata consistently, and stop on invalid JSON without
  installing a partially processed starter. Standalone generation rejects
  noncanonical machine names before changing files.

- Native robots, favicon, feed, trackback and sitemap responses remain
  available when Timber is missing, while theme pages retain a clear error.

- PHP attribute helpers reject script-bearing URLs, `srcdoc`, framework event
  and expression directives, and arbitrary object attribute sources. Class
  names are handled case-insensitively while SVG name case is preserved.

- Mapped group and quote blocks retain children in `inner_content` without
  rendering them again. Mapped blocks reuse global context, select their own
  post without resetting Query Loop globals, reject directory templates, and
  fall back to WordPress markup on recursive rendering. Dynamic and late-added
  template-map filters remain active.
- Optional `front-page.twig` now serves the actual front page for both static
  pages and posts. A separate posts page uses `home.twig`, then `index.twig`;
  protected static front pages retain password-form routing.
- Empty custom post type archives select their queried type's template, and
  day archive titles use the site's configured date format.
- Release reruns report an incomplete tagged GitHub release or missing ZIP with
  recovery commands, before stale release-draft documentation can mask it.
- Release test fixtures ignore maintainer commit/tag signing settings, and the
  organization-project workflow skips fork and Dependabot pull requests.
- Recursive file discovery skips unreadable or disappearing directories and
  reports failures when debugging, so a broken component folder no longer makes
  frontend or admin requests fatal.
- Persistent component discovery invalidates after release-directory deploys
  and standard builds without requiring a theme-version bump or asset manifest.
  Cached paths resolve against the current roots, and stale or unreadable files
  trigger a fresh scan instead of reusing a dead index.
- Click-to-load media failures produce an escaped video link without contacting
  the provider before activation. Poster `srcdoc` escaping is exact on the
  minimum WordPress version, with a script-blocking meta CSP. Provider
  permissions survive activation, thumbnails lazy-load, and Vimeo metadata
  caching runs only when provider posters need it while preserving valid data
  on failed refreshes.
- Parent templates use WordPress escapers for formatted titles, site/menu and
  author names, archive/search headings, and thumbnail alt text, so encoded
  punctuation and ampersands display once without weakening Twig autoescaping.
- Security: password-protected pages and listing excerpts no longer expose
  content without a valid post-password cookie, fixing an issue present in
  `2.0.0`. Timber content reads now show the password form, and protected pages
  and posts use WordPress's filtered form with the correct site URL, including
  installations in a subdirectory.
- The media embed filter accepts missing post IDs and normalizes public-filter
  inputs, including embeds rendered outside a post, without interrupting the
  request.
- Security: experimental mapped core-block rendering keeps parsed block data in
  `block_attributes` and starts HTML `attributes` as an empty array, preventing
  block comment data from becoming executable HTML attributes through Twig
  helpers. The PHP attribute bag also drops all case-insensitive `on*`
  event-handler attribute names.
- Pagination marks only the actual current page, renders a translated ellipsis
  character as non-interactive text, and preserves query arguments in numbered,
  previous, and next links. Numbered links now include a translated `Page `
  prefix in a `screen-reader-text` span, with no added frontend CSS.
- Localized pagination numbers decode HTML entities before autoescaping, so
  non-breaking thousands separators render correctly instead of literal `&nbsp;`.
- Fallback menus mark the current item with `aria-current="page"`, leaving
  ancestors unmarked.
- Project and `@components` references follow Core's direct candidates and
  breadth-first grouped discovery, including `.html.twig` and unregistered
  explicit prefixes, while registered namespaces retain their scope.
  `structureImplementations` selects the child component root as Core does.
  Component discovery, hit/miss results, and project configuration are shared
  across the request's Timber loaders; CLI processes bypass those caches by
  default. Ordered WordPress legacy/parent fallbacks and containment remain.
- In `2.0.0`, Timber 2.5 passed Twig only a function's `callable` and `options`
  keys, so the helpers' top-level `needs_context` and `is_safe` settings were
  ignored. Every `bem()` and `add_attributes()` call printed escaped attribute
  markup, such as `<h2 class=&quot;heading&quot;>`, and never received context
  attributes. The options are now registered correctly; `2.0.0` projects can
  drop any `|raw` or other workaround they added. Argumentless `bem()` and
  `add_attributes()` calls no longer consume unrelated context as arguments or
  expose it as attributes.
- The document shell preserves trusted WordPress language attributes as markup,
  producing a valid `lang` attribute.
- Parent `theme.json` no longer sets the failing pale-blue normal link color or
  invalid hover, focus, and active color declarations. Quote text now inherits
  the page's text color instead of the same failing pale blue; review project
  quote presentation. The removed button color object never applied, and
  quote spacing is unchanged: previously unresolved bottom quote and top cite
  margins are now explicitly `0`. Other global styles and editor presets
  remain; review project link presentation after updating.
- Generated audit wrappers keep JSON stdout clean by sending documentation
  footers to stderr, while preserving arguments and Core's exit status.
- Release tooling accepts prepared versions after `2.0.0`, rejects stale draft
  versions and release claims, and prevents publishing mismatched metadata.
  `npm run release:check -- --skip-smoke` now honors its documented behavior.

## Compatibility

- **WordPress 6.8 or newer is required**, raised from 6.7 to match locked Timber
  2.5.1. Update WordPress before updating the theme. PHP remains 8.3 or newer;
  the PHP and Timber package constraints are unchanged.
- The minimum WordPress version and current stable release are blocking CI
  legs. `Tested up to` is 7.1 after a passing fixture on 7.1.3; nightly remains
  advisory. Practical checks block on PHP 8.3, 8.4, and 8.5.
- Generated child tooling advertises Node.js `>=24`, while Core sets the
  effective minimum at 24.13.0. Root maintainer tooling requires `>=24.10`.
- Existing children keep `Template: emulsify` and their copied files. Parent
  updates do not replace their scripts, Jest config, guidance, or dependencies.
  Existing parent-extending page overrides continue receiving parent fixes.

## Consumer action

Before updating, sites on WordPress 6.7 must upgrade WordPress to at least 6.8.
After updating, check menus, long archives, filtered searches, link colors, and
language markup. Projects own target size, `screen-reader-text`, and focus
styles; confirm the new pager prefix is visually hidden by the site's styles.

Adopting copied tooling fixes, Core `^4.5.0`, AI guidance, or the new extension
blocks in an existing child is optional. Copy the relevant changes into the
child deliberately; refreshing the parent does not change those files. An old
page wrapper can remain, or be removed after checking project CSS. Privacy and
click-to-load embeds remain off until enabled; review CSP and playback behavior
before opting in. See the required and optional actions in
[UPGRADE.md](../UPGRADE.md#upgrading-from-200-to-210).

## Release PR checklist

- [ ] Merge the parity implementation PRs and review the
  [sync log](sister-project-parity.md#sync-log) against the final changes.
- [ ] Prepare `2.1.0` following the [release procedure](release-process.md): bump
  root `package.json`, both theme `Version` headers, `whisk/package.json`, and
  `whisk/project.emulsify.json` lineage together, including applicable lockfile
  metadata.
- [ ] Re-sync
  [`emulsify-wordpress-starter`](https://github.com/emulsify-ds/emulsify-wordpress-starter)
  from `whisk/`, including hidden `.agents/` guidance and the standalone
  `.cli/init.js` generator; confirm both generation paths still agree.
- [x] Confirm a published `@emulsify/core` version satisfies `^4.5.0`:
  [4.5.0](https://www.npmjs.com/package/@emulsify/core/v/4.5.0) was verified in
  the npm registry on 2026-10-23 and requires Node.js 24.13.0 or newer.
- [ ] Confirm the version resolved by a fresh generated theme lockfile still
  satisfies that range and the effective Node requirement.
- [ ] Run the readiness checks below on the prepared committed files and
  confirm semantic-release computes exactly `2.1.0`. Have the maintainer review
  and push the release PR, then confirm CI passes before merging. Rotate this
  draft after publication.

## Validation notes

- Run `npm run docs:check-commands` and `npm run lint:php`.
- Run `npm run pr:check`, `npm run test:generated-theme`, and
  `npm run smoke:generation-parity`.
- Run `npm run release:check` with WP-CLI and MySQL available for the full
  WordPress fixture path; set `WP_SMOKE_REQUIRED=1` so missing prerequisites fail.
- Run `npm run release:check -- --skip-smoke` only for static validation; it
  does not replace the full release gate.
- Push the branch, then follow the
  [local dry-run requirements](release-process.md#local-release-dry-runs) before
  `npm run publish-test -- --no-ci` to verify the prepared version calculation.
