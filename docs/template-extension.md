# Extend parent Twig templates

New generated child themes ship no Twig templates or `templates/` directory.
WordPress falls back to the parent's PHP route controllers, and the parent
`@templates` namespace supplies the corresponding Twig fallback. Create a child
`templates/` directory only when the project needs an override.

## Extend the parent explicitly

In a child `templates/page.twig`, extend the parent-only namespace:

```twig
{% extends '@emulsify-tpl/page.twig' %}

{% block content %}
  {{ parent() }}
  <p class="project-notice">Thank you for visiting.</p>
{% endblock %}
```

For pages that do not require a password, WordPress selects the parent's
`page.php`, which renders `@templates/page.twig` unless the page is the static
front page and a `@templates/front-page.twig` exists.
That child-first lookup selects the child's override. Its `@emulsify-tpl` extends
then selects the parent explicitly, so surrounding layout and later parent fixes
continue to reach the child. `parent()` preserves the selected block's default
contents; omit it when intentionally replacing that block.

Using `{% extends '@templates/page.twig' %}` inside the child file with that same
path resolves back to itself and can recurse. Use `@templates` for normal
child-first includes, and `@emulsify-tpl` for same-path parent inheritance. The
same rule applies to a child `templates/layouts/base.twig`.

## Front page and posts page

The parent controllers follow WordPress's front-page hierarchy:

- Front page showing posts: `front-page.twig`, then `home.twig`, then `index.twig`.
- Static front page: `front-page.twig`, then `page.twig`.
- Separate posts page: `home.twig`, then `index.twig`.

These optional names use the normal child-first `@templates` lookup. A missing
front-page or home template falls through to the next name. A protected static
front page uses `single-password.twig` until its password is accepted, before
trying a custom front-page template. Existing Timber render filters continue
to receive the selected file.

## Public extension points

The layout and route blocks below are a public contract. Their names and
boundaries are stable: renaming, removing, or changing a boundary incompatibly
is a breaking change. The existing `head`, `header`, `content`, and `footer`
blocks retain their original names and boundaries.

| Parent template | Block | Default contents |
| --- | --- | --- |
| `layouts/base.twig` | `head` | The head partial, including metadata, the `get_header` action, and `wp_head()` |
| `layouts/base.twig` | `site_header` | The complete site `<header>`, its inner div, brand link, and optional primary navigation |
| `layouts/base.twig` | `header` | Brand link and optional navigation inside the site header's inner div |
| `layouts/base.twig` | `main` | The complete `<main id="content" tabindex="-1">`, including `page_title` and `content` |
| `layouts/base.twig` | `page_title` | The conditional page-title header and its h1; empty when `title` is unset or empty |
| `layouts/base.twig` | `content` | Empty; route templates provide the page-specific contents |
| `layouts/base.twig` | `footer` | The footer partial and copyright markup |
| `page.twig` | `content` | Page article with title/content, or the empty-page message |
| `single.twig` | `content` | Post article, metadata, content, and comments |
| `single-password.twig` | `content` | WordPress password form for protected posts and pages |
| `index.twig`, `archive.twig`, `author.twig`, `search.twig` | `content` | Teasers, pagination, or the route's empty-result message |
| `404.twig` | `content` | Not-found heading and message |

Replacing `site_header` replaces its nested `header` unless the override calls
`parent()`. Replacing `main` similarly replaces its nested `page_title` and
`content`. Preserve `id="content"` and `tabindex="-1"` on your main element so
the skip link remains usable. Keep `wp_head()` when replacing the `head` block,
usually by calling `parent()`.

The base layout keeps `wp_body_open()`, the skip link, `wp_footer()`, and the
`get_footer` action outside every overridable block. Block overrides therefore
preserve these WordPress hooks and the skip link.

## Password-protected content

When `post_password_required()` is true, the parent page and single controllers
render `@templates/single-password.twig`. This remains a child-first extension
point for both posts and pages. A same-path child override can extend
`@emulsify-tpl/single-password.twig` and call `parent()` in its `content` block.

The parent fallback sets its title with `get_the_title()` and prints
`get_the_password_form( post.id )`, preserving WordPress's protected-title
format, site URL, password-cookie flow, and `the_password_form` filter. Use that
WordPress filter to customize the form; core's form uses `.post-password-form`
rather than the former custom `.password-form` markup.

The parent enables Timber's password-form handling for `post.content` even in
child templates. Empty filtered forms stay hidden without falling through to
protected content. Excerpts and raw `post.post_content` do not have that
protection. Check `post.password_required` before rendering them, including in
overrides of `partials/tease.twig` and `partials/tease-post.twig`. A correct
post-password cookie makes that check false and restores normal rendering.

## Verification

Run `npm run smoke:template-extension` after `composer install`. It compares
literal output bytes with goldens captured before the added blocks, including
title/menu presence and populated/empty content. The harness uses real parent
namespaces and helpers with deterministic WordPress stubs and a frozen footer
clock. It also checks a temporary child's parent-only inheritance and verifies
that overriding one block preserves every surrounding byte.

The full WordPress fixture verifies a generated child renders pages without a
local Twig template, then temporarily adds a one-block page override, compares
HTTP response bytes, and restores the child afterward. Run it through
`npm run release:check` with WP-CLI and MySQL available.
