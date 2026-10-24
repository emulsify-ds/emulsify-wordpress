# Parent template output goldens

These HTML files capture the parent templates at commit
`28db7c3f58387e08176664163fdbf92ab515d572`, before the new extension blocks.
Every byte, including whitespace, is compared without output normalization.

The harness uses the parent's real Twig namespace registration, autoescaping,
attribute/BEM helpers, and head/menu/footer partials. WordPress functions and
actions emit deterministic hook markers. Only the literal date input `now` is
frozen at `2026-10-22T12:00:00Z`; formatting still delegates to Twig's real date
formatter in UTC, keeping the footer's year stable.

The eight `content-*` files cover every combination of title, menu, and
populated/empty content. Empty cases render the real base directly and compare
it with an empty ArrayLoader child content block at runtime. Populated cases
use that child template to supply the real base's content block. The two
`page-*` files render the real parent page fallback with a post and without one.

Verify without rewriting fixtures:

```sh
php .github/scripts/template-extension-smoke.php
```

Capture intentionally, after reviewing the rendered-output change:

```sh
php .github/scripts/template-extension-smoke.php --update-goldens
```

Updating goldens is an explicit review step. Do not use the capture option in CI.
