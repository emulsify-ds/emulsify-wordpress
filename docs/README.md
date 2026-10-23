# Emulsify WordPress docs

## Getting started

- [Generated-theme upgrade guide](../UPGRADE.md)
- [Required and optional 2.0.0 to 2.1.0 upgrade actions](../UPGRADE.md#upgrading-from-200-to-210)
- [Upgrading from 1.x to 2.x](upgrading-1x-to-2x.md)
- [Parent and child theme architecture](parent-child-architecture.md)
- [WP-CLI child theme generation](wp-cli-child-theme-generation.md)
- [Generated child theme contract](generated-child-theme-contract.md)
- [Dependency audit: October 23, 2026](dependency-audit-2026-10-23.md)

## Authoring

- [Timber and Twig authoring](timber-and-twig-authoring.md)
- [Parent Twig extension points](template-extension.md)
- [Accessibility checks and project responsibilities](accessibility.md)
- [Privacy and click-to-load media embeds](media-embeds.md)
- [Component recipes](component-recipes.md)
- [Emulsify Core 4 and Vite workflow](core-4-vite-workflow.md)
- [Design token integration](design-token-integration.md)

## Blocks and editor

- [ACF/Twig blocks](acf-twig-blocks.md)
- [Native Gutenberg blocks](native-gutenberg-blocks.md)
- [Core block Twig rendering](core-block-twig-rendering.md)
- [Block patterns](block-patterns.md)
- [Editor policy](editor-policy.md)
- [Editor enhancements](editor-enhancements.md)
- [ACF Local JSON](acf-local-json.md)

## Operations

- [Asset loading](asset-loading.md)
- [Release process](release-process.md)
- [2.1.0 release notes (unreleased)](release-notes-next.md)
- [2.1.0 release PR checklist](release-notes-next.md#release-pr-checklist)
- [Sister-project parity contract](sister-project-parity.md)
- [Drupal 7.3.0 sync log](sister-project-parity.md#sync-log)
- [How to run a sister-project sync](sister-project-parity.md#how-to-run-a-sync)
- [Post-2.x optimization roadmap](post-2x-optimization-roadmap.md)

## Continuous integration

Every configured pull request runs practical smoke coverage and a dedicated PHPCS/PHPStan job. Pull requests targeting `main` or `release-2.x` additionally run the required MySQL/WP-CLI WordPress rendering fixture and a CI-seeded Whisk Storybook accessibility audit. The WordPress job also audits seven rendered routes, including YouTube and Vimeo posters, with explicit WCAG 2.2 AA tags and retains per-route evidence; see [accessibility checks](accessibility.md) for the sole target-size exception and project responsibilities. Weekly scheduled runs repeat both extended fixtures. Manual readiness runs use the `wordpress_fixture` and `extended_checks` inputs to select those extended jobs. The release process documents commands, route coverage, and merge-gate details.
