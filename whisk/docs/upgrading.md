# Upgrading %%EMULSIFY_THEME_NAME%%

This project was generated from `%%EMULSIFY_SOURCE_PROJECT%%` `%%EMULSIFY_SOURCE_VERSION%%` and currently expects `@emulsify/core` `%%EMULSIFY_CORE_RANGE%%`.

## Choose the upgrade type

An npm dependency update is different from adopting a newer starter release. A newer starter release requires a manual comparison against a newly generated theme, because this project owns its templates and components after generation.

### Update npm dependencies

1. Work on a branch with a green build.
2. Review the available updates and the Emulsify Core release notes.
3. Update only the intended dependencies and commit the resulting `package-lock.json`.
4. Review transitive changes and rerun validation.

### Compare with a newer starter release

1. Check out the intended Emulsify WordPress release separately from this project.
2. Generate a fresh, temporary comparison theme with a different machine name, either with `wp emulsify "Comparison Theme" --machine-name=comparison-theme` or with the Emulsify CLI against the WordPress starter repository.
3. Compare the two themes, focusing on:
   - `package.json`, `project.emulsify.json`, and `.nvmrc`;
   - `config/emulsify-core/` plus the Vite, Storybook, Jest, lint, and formatting scripts;
   - `style.css` headers, `functions.php`, and `patterns/`;
   - the README, development guide, upgrade guidance, and support checklist.
4. Port only the changes that benefit this project. Project-owned templates and components are not overwritten by a starter release.
5. Delete the temporary comparison theme.

Starter releases do not provide an automatic project diff or migration.

New generated themes ship no Twig templates or `templates/` directory. Existing
2.x page overrides still inherit parent fixes when they extend
`@emulsify-tpl/page.twig` and call `parent()`. Before deleting an old page
override, search project CSS for its machine-name page wrapper class and keep
the override if that wrapper is used. Create project templates only as needed;
the parent's `docs/template-extension.md` documents the supported layout blocks.

## Validate the result

```bash
npm install
npm run inspect:components
npm run lint
npm run test
npm run build
npm run storybook-build
```

An empty component inspector report is valid for a project that has not added components yet.

Also activate and render the theme in WordPress when theme headers, Twig templates, patterns, components, or asset output changed. Check both an administrator view and an anonymous view.

## Preserve source history

Keep `project.generatedFrom` and `project.generatedFromVersion` in `project.emulsify.json`. Update them only when deliberately adopting a newer baseline, and record that change in version control.
