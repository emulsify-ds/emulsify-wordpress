# 2.1.0 Release Notes Draft

Status date: 2026-10-22

## Version strategy

Latest published tag: `2.0.0`. This draft tracks the upcoming `2.1.0` release.

Root `package.json` remains at the latest stable version until a release PR
bumps it and every derived version surface together. The version contract
accepts the latest stable tag or an untagged next patch, minor, or major, and
requires full Git history and tags. It rejects already-tagged draft versions
and stale claims about the latest release.

The guarded runner skips publishing when no new version is prepared. Once a
release PR prepares `2.1.0`, semantic-release must compute exactly `2.1.0` from
Conventional Commits before it can publish the committed theme headers, ZIP,
and generated-theme lineage. See the [release process](release-process.md).

## Compatibility notes

- WordPress 6.8 or newer; PHP 8.3 or newer.
- Timber `^2.3` is required by the parent theme.
- Generated child themes use Emulsify Core `^4.3.2` and the Vite build workflow.
- Generated child themes keep `Template: emulsify`; `whisk` remains a
  generation-only starter source and must not be activated directly.
- Root release tooling requires Node.js `>=24.10`; generated child theme tooling
  requires Node.js `>=24`.

## Draft GitHub release notes

Title: `2.1.0`

### Added

- Timber-first WordPress parent theme with Twig route fallbacks, global context,
  namespace registration, and Emulsify-compatible switch tags.
- `wp emulsify` WP-CLI child theme generation with `--machine-name`,
  `--description`, `--parent`, `--dry-run`, `--force`, and `--activate`.
- Standalone starter generation through
  [`emulsify-wordpress-starter`](https://github.com/emulsify-ds/emulsify-wordpress-starter)
  and its `.cli/init.js` hook.
- Project-specific documentation in generated child themes: `README.md`,
  `docs/development.md`, `docs/upgrading.md`, and `docs/support-information.md`,
  resolved from `%%EMULSIFY_*%%` tokens at generation time.
- A generated child theme contract with focused tests, plus a cross-path parity
  smoke that requires both generation paths to produce the same file tree.
- ACF/Twig block registration, native `block.json` registration, block pattern
  registration, editor policy controls, and editor enhancements.
- Asset discovery and enqueuing for built child theme output under `dist/`.

### Changed

- Root `package.json` is now the single source of truth for the release version.
  Theme headers, starter metadata, generated lineage, and smoke assertions all
  derive from it instead of restating a literal.
- Both generation paths emit two-space JSON so generated metadata is identical
  regardless of which path a project used.
- Generation-only tooling is no longer left in generated child themes. The
  WP-CLI generator excludes `.cli`, and the standalone starter hook removes
  itself after a successful run.
- `docs-command-check.cjs` now exports a reusable validator and can validate a
  real generated child theme directory, not just repository documentation.

### Compatibility

- The minimum WordPress version is now 6.8, matching the locked Timber 2.5.1
  requirement. Sites on WordPress 6.7 must update WordPress before updating the
  theme. The blocking fixture and `Tested up to` header now target 6.8.

### Fixed

- Removed link hover, focus, and active color rules that referenced an undefined
  custom property instead of introducing replacement link-state colors. This
  can reveal existing link colors where the invalid declarations previously
  caused inheritance; see [upgrade guidance](../UPGRADE.md#upgrading-from-200-to-210).
- Project component references now match Emulsify Core's direct candidates and
  breadth-first grouped discovery, including `.html.twig` templates and the
  `@project_machine_name/component` alias. Each child root is searched before
  parent roots, with deterministic duplicate precedence and root containment.
- Fallback menus set `aria-current="page"` only on the current menu item,
  leaving its ancestors unmarked.
- Fallback pagination marks only the actual current page, renders a translated
  ellipsis character as non-interactive text, and preserves multi-argument URLs
  in numbered, previous, and next links without double escaping.
- Twig helper registration uses Timber's function options so rendered attribute
  markup remains intact and helpers receive the intended Twig context.
- Release tooling supports prepared releases after `2.0.0`, validates versions
  against stable Git tags, and prevents publishing metadata that differs from
  the version semantic-release computes. `release:check -- --skip-smoke` now
  skips runtime smoke commands as documented.
- `style.css` header replacement no longer warns when a header already holds the
  requested value; only a genuinely missing header is reported.

### Internal

- Added `npm run test:generated-theme` and `npm run smoke:generation-parity`.
- Added release checks for generated documentation sections, the documentation
  token list across both generators, and the generated child theme contract.

## Validation notes

- Run `npm run release:check -- --skip-smoke` for static validation.
- Run `npm run test:generated-theme` and `npm run smoke:generation-parity` for
  generation coverage.
- Run `npm run release:check` with WP-CLI and MySQL available for the full
  WordPress fixture path.
- Run `npm run publish-test -- --no-ci` before publishing.
- Publish the compatible `@emulsify/core` release before Emulsify WordPress
  `2.1.0`.
