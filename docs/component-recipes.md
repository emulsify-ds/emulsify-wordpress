# Component recipes

Whisk intentionally keeps `src/components` empty until a project installs the component system it wants to use. The snippets below are recipes for generated child themes, not files that must ship in every starter. Add them to a project child theme only when that project needs them.

Do not add a full component library to `whisk/src/components`. Use Whisk as a light starter, then let the selected Emulsify component system define the project source structure.

## When to use each option

Use a plain Twig component for reusable markup that is rendered from templates, ACF/Twig blocks, patterns, or other components.

Use an ACF/Twig block when editors need a custom block backed by ACF fields and the frontend should render through Twig.

Use a native Gutenberg block when the project needs WordPress Block API features such as attributes, supports, transforms, editor scripts, view scripts, or dynamic rendering.

Use core block Twig rendering only when a project intentionally replaces the frontend output of an existing core block such as `core/paragraph` or `core/heading`.

Use a block pattern when editors need a reusable layout made from existing blocks rather than a new block type.

## Plain Twig component

Example generated child theme path:

```text
src/components/card/card.twig
```

```twig
<article class="card">
  {% if eyebrow %}
    <p class="card__eyebrow">{{ eyebrow }}</p>
  {% endif %}

  <h2 class="card__title">{{ title }}</h2>

  {% if url %}
    <a class="card__link" href="{{ url }}">{{ link_text|default('Read more') }}</a>
  {% endif %}
</article>
```

Include it from a Twig template with the generated project machine name:

```twig
{% include "project_machine_name:card" with {
  eyebrow: 'News',
  title: post.title,
  url: post.link
} only %}
```

## Storybook story

Story files belong with the component source when the selected component system supports that convention:

```text
src/components/card/card.stories.js
```

```js
import template from './card.twig';

export default {
  title: 'Components/Card',
};

export const Default = {
  render: (args) => template(args),
  args: {
    eyebrow: 'News',
    title: 'Example card',
    url: '#',
    link_text: 'Read more',
  },
};
```

Adjust the import and render shape to the selected component system's Storybook Twig loader.

## Optional ACF/Twig block metadata

Add `*.component.json` only when the component should register as an ACF/Twig block after the child theme build:

```text
src/components/card/card.component.json
```

```json
{
  "name": "emulsify-card",
  "title": "Card",
  "description": "A short card block rendered with Twig.",
  "category": "widgets",
  "icon": "index-card",
  "mode": "preview"
}
```

After build, the parent scans `dist/components` for the built Twig template and matching `*.component.json` metadata. Whisk does not ship active ACF/Twig block metadata by default.

## Optional native Gutenberg block metadata

Add `block.json` only when the component is a native WordPress block:

```text
src/components/card/block.json
```

```json
{
  "apiVersion": 3,
  "name": "project/card",
  "title": "Card",
  "category": "widgets",
  "icon": "index-card",
  "description": "A project card block.",
  "supports": {
    "html": false
  },
  "textdomain": "project"
}
```

Native blocks usually need editor scripts, attributes, supports, and save or render behavior defined by the project's chosen block tooling. Whisk does not ship a native block example by default.

## Optional pattern JSON

Use a block pattern when existing blocks can express the editor experience:

```text
patterns/card-feature.json
```

```json
{
  "name": "project/card-feature",
  "title": "Card feature",
  "description": "A simple card-style feature pattern.",
  "categories": ["text"],
  "content": "<!-- wp:heading --><h2>Feature title</h2><!-- /wp:heading --><!-- wp:paragraph --><p>Feature copy.</p><!-- /wp:paragraph -->"
}
```

Patterns live in the generated child theme's `patterns` directory. The child theme generator updates copied starter pattern namespaces from `whisk/*` to the generated machine name, but new project patterns should use the project namespace directly.

## Core block Twig rendering

Use core block Twig rendering only for explicit replacements of existing WordPress core block output:

```php
add_filter( 'emulsify_theme_core_block_twig_rendering_enabled', '__return_true' );

add_filter(
  'emulsify_theme_core_block_twig_template_map',
  function ( array $map ): array {
    $map['core/heading'] = 'dist/components/heading/heading.twig';
    return $map;
  }
);
```

Keep this opt-in narrow. Replacing core block markup can affect block validation, editor expectations, accessibility, and plugin integrations.

Mapped templates read block data from `block_attributes`, for example
`block_attributes.level` for a heading. The context's `attributes` starts as an
empty array reserved for HTML attributes; do not copy block data into it because
`bem()` and `add_attributes()` merge it into markup. See
[the mapped template context](core-block-twig-rendering.md#twig-context).
