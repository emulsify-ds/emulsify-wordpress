# Emulsify WordPress contributor guidance

## Repository map

- This repository distributes the `emulsify` WordPress parent theme, not a site.
- `includes/` owns reusable PHP runtime services; root PHP route controllers and
  `templates/` provide Timber fallbacks and public Twig extension blocks.
- `whisk/` is the generation source for project-owned child themes. Its
  `AGENTS.md`, integration guide, and skill describe generated-project work.
  While changing that source here, follow this contributor workflow and verify
  the generated result.
- `whisk/config/emulsify-core/` supplies copied project extension points. Shared
  frontend tooling belongs to the installed `@emulsify/core` package.
- `.github/scripts/` and `.github/fixtures/` implement maintainer checks and
  consumer contracts; they are not generated-project commands.
- `scripts/build-dist.sh` builds the installable parent ZIP, including the
  starter payload and production Composer dependencies.

Read [README.md](README.md), [UPGRADE.md](UPGRADE.md), and the relevant contracts
in `docs/`, especially [sister-project parity](docs/sister-project-parity.md),
[generated themes](docs/generated-child-theme-contract.md), and
[template extension](docs/template-extension.md).

## Ownership boundaries

The parent owns reusable WordPress integration, context, discovery, assets,
and fallback rendering. Project-specific templates, components, stories,
styling, and content mappings belong in the generated child; functionality
independent of the theme belongs in a project plugin. The parent adds no
frontend CSS. Keep WordPress behavior native rather than importing Drupal
names or mechanics.

Keep the starter component-library-neutral. Discover the selected library's
directories, APIs, namespaces, tokens, stories, and build inputs from its guide
and existing project code. Do not scaffold one library's conventions into the
starter. Keep generated guidance free of starter identity and documentation
tokens so it copies unchanged; its links must resolve inside the child theme.

Child generation has two implementations:
`includes/Cli/GenerateChildThemeCommand.php` and `whisk/.cli/init.js`. Update
both when their behavior changes. Preserve hidden `.agents/` skills through
both generators, the installable ZIP, and Git distributions. Only generation
tooling such as `.cli/` is removed.

Preserve Twig autoescaping, translations, accessibility, WordPress hooks, and
the `#content` skip-link target when changing rendering. Existing Twig block
names and boundaries are public contracts; check their docs and exact-output
fixtures before changing them.

## Validation and releases

Use root `package.json` for maintainer commands and a generated theme's own
package for consumer commands. Install with `composer install` and
`npm ci --ignore-scripts`, then run:

```sh
npm run lint:php
npm run pr:check
npm run test:generated-theme
npm run smoke:generation-parity
npm run release:check
```

`npm run docs:check-commands` checks documented npm commands. For static release
checks use `npm run release:check -- --skip-smoke`. Changes to rendering or
generation need the full WordPress fixture with WP-CLI and MySQL; set
`WP_SMOKE_REQUIRED=1` to fail when prerequisites are missing. Report checks run,
skips and their reasons, and any behavior still unverified. Storybook does not
exercise Timber context, ACF, or WordPress hooks. Empty generated projects have
no tests and intentionally fail `npm run test`; builds need library inputs.

Use Conventional Commits. Do not bump versions outside the
[release procedure](docs/release-process.md): root `package.json` is the version
source, derived metadata must match, and publishing requires semantic-release
to compute the prepared committed version. Release checks require full Git
history and stable tags. Preserve generated-source lineage and unrelated
working-tree changes. Put user-facing changes in
`docs/release-notes-next.md` and consumer actions in `UPGRADE.md`.
