# Post-2.x optimization roadmap

Emulsify WordPress 2.0 should stay focused on the parent and generated child theme release contract. The items below are follow-up opportunities for focused minor releases, not 2.0 blockers.

## Release posture

- Ship 2.0 without adding new runtime features.
- Keep 2.0 changes limited to release hardening, documentation cleanup, and bug fixes.
- Group future improvements into small minor releases with focused smoke or PHPUnit coverage.

## Done in 2.0

- Composer PSR-4 autoloading and grouped runtime directories under `includes/`.
- Shared `Support` helpers including `AssetRecord`, `AssetEnqueuer`, `Diagnostics`, `FileDiscovery`, and `ProjectConfig`.
- A named `ProjectComponentLoader` for `project_machine_name:component_name` Twig references.
- Optional manifest-driven asset loading through `dist/emulsify-assets.json`, with scanner fallback.
- Per-request memoization for manifest reads, asset discovery, editor config, and component discovery.
- Block-scoped asset support for ACF/Twig blocks while native blocks keep WordPress `block.json` asset handling.
- Optional persistent discovery caching that is enabled by default outside local, development, or `WP_DEBUG` environments.
- `switch_theme` cache invalidation and cache-key parts for theme versions, environment, and manifest mtime.
- Safer WP-CLI `--force` replacement checks using generated child theme metadata.

The 2.1 hardening adds theme and component directory identity and build-state
signals to the persistent discovery key, with relative cached paths and stale
file validation. Custom builds that change nested output in place still need a
build token or explicit cache clear; see the
[cache invalidation guidance](parent-child-architecture.md#optional-persistent-discovery-cache).

## Recommended implementation order

1. Keep the 2.0 release branch focused on release readiness only.
2. Add read-only CLI diagnostics such as `wp emulsify doctor`.
3. Migrate the highest-value smoke harness checks into PHPUnit or another WordPress-aware test layer.
4. Explore block.json-based ACF registration only after the current `*.component.json` path is stable in real projects.
5. Expand cache and manifest diagnostics after maintainers have real-world invalidation guidance feedback.

## Code organization

- Keep the current domain folders stable through the 2.0 release.
- Add tests around service boundaries before further class movement.
- Avoid reintroducing compatibility shims for class names that never shipped in a stable release.

## Runtime architecture

- Consider a small service-provider layer if Bootstrap grows again.
- Keep parent services focused on reusable WordPress runtime behavior.
- Keep project-specific behavior in child themes or site plugins.

## Asset loading and performance

- Add build-time manifest validation so malformed `dist/emulsify-assets.json` files fail earlier.
- Document CDN or asset-host filters if real projects need them.
- Add diagnostics that explain whether a page used manifest records, scanner fallback, or block-scoped assets.

## Component/block discovery

- Add `wp emulsify doctor` checks for active child theme metadata, generated lineage, Timber availability, build output, and component discovery state.
- Improve duplicate and cache diagnostics for optional persistent discovery caching without adding an admin UI.
- Keep invalidation guidance close to the filters that alter roots or cache-key parts.

## CLI diagnostics

- Start with read-only reporting before adding repair commands.
- Report parent install state, active child theme, `Template` header, `project.platform`, `generatedFrom`, `generatedFromVersion`, Timber status, and missing build output.
- Include cache status and manifest path checks once those messages are stable.

## Editor and block feature modules

- Keep editor enhancements opt-in and independently testable.
- Evaluate block.json-based ACF registration as a follow-up to the current ACF/Twig metadata path.
- Add feature-specific docs and smoke/PHPUnit coverage with each new editor or block module.

## Documentation and support tooling

- Migrate smoke harness behavior into PHPUnit where WordPress APIs are easier to model.
- Maintain upgrade checklists, troubleshooting notes, component recipes, and release checklists as support material.
- Keep examples as recipes instead of active starter files in `whisk/src/components`.
