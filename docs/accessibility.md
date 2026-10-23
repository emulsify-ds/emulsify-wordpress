# Accessibility checks

The parent theme supplies semantic fallback markup and WordPress integration.
It adds no frontend CSS. Projects own the final visual presentation, target
sizes, focus indicators, and any visually hidden text helpers their pages need.

## Check Storybook and WordPress

Run `npm run a11y` from a generated child theme to build and audit its Storybook
stories through Emulsify Core. The CI-seeded story exercises the component
toolchain without adding a component library to the committed starter.

Stories do not execute WordPress route selection, Timber context, menus, ACF,
plugins, or the parent layout. The separate rendered WordPress audit checks
actual HTTP pages from a disposable generated and activated child theme. Its
Puppeteer and axe dependencies come from that child's installed Emulsify Core
dependency tree, rather than a separate root accessibility stack.

From a maintainer checkout, install Composer and root npm dependencies, configure
the fixture's `WP_SMOKE_DB_HOST`, `WP_SMOKE_DB_PORT`, `WP_SMOKE_DB_USER`, and
`WP_SMOKE_DB_PASSWORD`, and provide a sandbox-capable Chrome:

```sh
WP_SMOKE_REQUIRED=1 WP_SMOKE_A11Y=1 \
  PUPPETEER_EXECUTABLE_PATH="/path/to/google-chrome" \
  npm run release:check
```

WP-CLI, PHP with mysqli, and MySQL are required. `WP_SMOKE_A11Y=1` opts into the
browser audit; `WP_SMOKE_REQUIRED=1` prevents missing WordPress prerequisites
from producing a skip. The normal runtime fixture without the accessibility
flag does not establish browser-audit coverage. `--skip-smoke` skips the fixture
and its browser audit. See [the release process](release-process.md) for setup.

The fixture calls the `auditRenderedPages` API exported by
`.github/scripts/rendered-a11y.cjs` while its HTTP server is live. A separate
CLI is available for an already running site:

```text
node .github/scripts/rendered-a11y.cjs <generated-theme-dir> <routes.json> <output-dir>
```

The WordPress readiness job enables the browser audit and verifies the runner's
installed Chrome can launch with its sandbox. Do not disable Chrome's sandbox
to make an audit pass.

## Rendered route and navigation coverage

The fixture uses plain permalinks and audits these anonymous routes:

| Route | Fixture request |
| --- | --- |
| Home | `/` |
| Page | `/?page_id=<page-id>` |
| Single post | `/?p=<post-id>` |
| Category archive | `/?cat=<category-id>` |
| Search | `/?s=Smoke` |
| Category archive, page 2 | `/?cat=<category-id>&paged=2` |

During these audits, `posts_per_page` is 1 and at least 12 posts belong to the
category. The second archive page therefore exercises the actual current page,
numbered links, previous/next links, and a non-interactive ellipsis with the
fallback's `mid_size: 3` and `end_size: 2` settings.

Navigation assertions supplement axe. The runtime fixture follows a search
pagination link and verifies its destination. The rendered audit checks
meaningful content and, on the second archive page, exactly one
`aria-current="page"` in its pager, current page 2, and a literal non-interactive
ellipsis without `aria-current`. The fixture assigns a primary menu with page,
post, and category links and checks the matching current link on those routes.
Current-state counts are scoped to each navigation region: a menu and pager
may each correctly have a current item. The fallback marks current menu items
only, leaving ancestors unmarked.

Numbered pagination links include a translated `Page ` prefix in a
`span.screen-reader-text`, giving a link such as `Page 2` an explicit name.
Only linked page numbers receive the prefix. The current-page span, ellipsis,
and already named Previous/Next links retain their existing text and BEM
classes. This markup change adds no stylesheet.

## Axe policy and artifacts

The rendered audit explicitly selects these axe tags:

```text
wcag2a, wcag2aa, wcag21a, wcag21aa, wcag22aa
```

This includes WCAG 2.2 AA rules rather than relying on a runner's default tag
selection. Failed HTTP responses, browser or audit execution errors, failed
render/navigation assertions, and any reported violation of the enabled rules
fail the audit.

The sole disabled axe rule is `target-size`. This is a narrow test-policy
exception for the unstyled parent fallback: the parent does not add frontend
CSS to enlarge or space links. It is not an exemption from WCAG or a claim that
the rendered site meets Target Size (Minimum). No other selected rule is
disabled.

Results go to `.artifacts/wordpress-a11y` by default; set
`WP_SMOKE_A11Y_OUTPUT` to choose another directory. Each route saves rendered
HTML, full axe JSON including incomplete checks, and computed-style evidence
for screen-reader text. CI retains them as
`wordpress-rendered-a11y-<matrix-version>`, including on failure. Inspect
incomplete results manually; an automatic pass cannot resolve those checks.

The parent document shell preserves WordPress's trusted language attributes as
markup so the browser parses `lang` correctly. Normal link colors now fall
through to WordPress core, browser, or project styles rather than the former
pale-blue theme rule that failed contrast on white. The existing visited-link
rule is retained. Projects must verify their final colors against actual
backgrounds.

## Project responsibilities

Projects must meet [WCAG 2.2 SC 2.5.8, Target Size (Minimum)](https://www.w3.org/WAI/WCAG22/Understanding/target-size-minimum.html):
provide pointer targets of at least 24 by 24 CSS pixels or satisfy an applicable
criterion exception, such as sufficient spacing. Audit the styled project with
`target-size` enabled; the parent fixture's exclusion must not hide project
regressions.

Ensure `screen-reader-text` remains available to assistive technology while
being visually hidden where intended. Do not use `display: none` or
`visibility: hidden` for spoken link labels. Verify the actual loaded WordPress
and project styles: a class name alone does not hide text. Projects must supply
a compatible helper if their asset policy does not provide one.

In the WordPress 6.8 fixture, core's loaded
`wp-includes/css/dist/block-library/style.min.css` supplies `.screen-reader-text`.
Before focus, both the skip link and pagination prefix measure 1 by 1 CSS
pixels, with absolute positioning, hidden overflow, and `clip-path: inset(50%)`.
The parent adds no helper CSS. Projects that disable the core stylesheet must
provide an equivalent accessible helper and verify its focus behavior.

Preserve a visible keyboard focus indicator on every interactive control, as
required by [SC 2.4.7, Focus Visible](https://www.w3.org/WAI/WCAG22/Understanding/focus-visible.html).
Check the skip link reveals itself on focus and reaches `#content`, menu and
pagination operation, heading structure, zoom/reflow, contrast, and screen
reader announcements on real project content. Test anonymous and relevant
signed-in states, editor previews, and plugin integrations. Automated axe
results are useful evidence, not complete WCAG conformance or a replacement
for keyboard and assistive-technology testing.
