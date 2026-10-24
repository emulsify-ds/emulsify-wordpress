---
name: emulsify-wordpress-integration
description: Integrate or debug project components in an Emulsify WordPress child theme, including Timber template selection, WordPress data mapping, block registration, and asset loading. Use for connecting components to WordPress rendering; component-only styling or stories do not require this workflow.
---

# Emulsify WordPress integration

Read the theme's [AGENTS.md](../../../AGENTS.md) and
[WordPress integration guide](../../../docs/wordpress-integration.md). Resolve
links from this skill's directory, then work from the theme root.

If `.cli/init.js` exists, follow the enclosing repository's generation workflow
and verify the generated result. For a generated theme:

1. Read project metadata, theme headers, npm scripts, installed library
   guidance, and the closest existing integration. Discover the component's
   inputs and supported references from its schema, stories, source, and tests.
   Use the component inspector when available; its inventory does not prove
   WordPress registration or define the complete component API.
2. Confirm the WordPress route, post type, fields, permissions, active child
   theme, and actual PHP/Twig candidates. Inspect the installed parent's
   templates and hooks before adding an override or data transformation.
3. Keep project mappings in child templates, `functions.php`, or an appropriate
   project plugin. Prefer a parent Twig extension block when it fits. Preserve
   WordPress hooks, filtered content, escaping, translations, the skip target,
   and accessible interaction while passing the component's explicit inputs.
4. Follow the library's build and asset contract. Rebuild changed output,
   refresh applicable discovery/site caches, run relevant project checks, and
   render the affected route in WordPress. Check anonymous and administrator views,
   editor previews, block registration, and debug logs where relevant.

## Report back

Report the selected template/component, mapping or asset change, checks run,
and exact WordPress behavior still unverified. Storybook does not execute the
site's Timber context, ACF, or WordPress hooks.
