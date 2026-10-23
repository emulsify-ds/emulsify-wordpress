# %%EMULSIFY_THEME_NAME%%

%%EMULSIFY_DESCRIPTION%%

This is the project-owned Emulsify child theme for **%%EMULSIFY_THEME_NAME%%**, with a Vite-based build workflow and Storybook.
Shared tooling comes from Emulsify Core; the project owns its templates and components.

|   |   |
|---|---|
| Machine name | `%%EMULSIFY_MACHINE_NAME%%` |
| WordPress metadata | `style.css` |
| Project metadata | [`project.emulsify.json`](project.emulsify.json) |
| Parent theme | `emulsify` |
| Generated from | `%%EMULSIFY_SOURCE_PROJECT%%` `%%EMULSIFY_SOURCE_VERSION%%` |
| Expected Emulsify Core range | `%%EMULSIFY_CORE_RANGE%%` |

## Quick start

Use Node.js 24.13.0 or newer for Emulsify Core 4.5.0. This theme advertises
`>=24`, but Core's `>=24.13.0` requirement sets the effective minimum.
`.nvmrc` selects the Node 24 line without pinning a minor version. Check the
resolved Core package's `engines` after dependency updates. If you use nvm:

```bash
nvm install
nvm use
```

Then install the shared tooling:

```bash
npm install
```

The generated theme does not include project asset source files or active asset references. The parent theme discovers built output under `dist/` and enqueues it, so nothing is enqueued until the project installs a component library and runs a build. Install that library before running development and build commands.

## Documentation

- [Development guide](./docs/development.md) - Setup, commands, assets, and project ownership.
- [WordPress integration](./docs/wordpress-integration.md) - Timber template selection, component mapping, and site verification.
- [Upgrading](./docs/upgrading.md) - Dependency updates and starter-release comparisons.
- [Troubleshooting and support](./docs/support-information.md) - Common fixes and a sanitized diagnostic checklist.

## Working with AI

[AGENTS.md](AGENTS.md) routes an assistant to this theme's metadata and guides.
The optional
[WordPress integration skill](.agents/skills/emulsify-wordpress-integration/SKILL.md)
supports connecting project components to WordPress. The selected component
library supplies its own structure, APIs, assets, and token guidance.

Start an AI session from this theme directory for local skill discovery. For a
session started at the WordPress site root, explicitly read this theme's
`AGENTS.md` and skill, or reference them in the site's existing agent
instructions. Generation leaves site-root instructions unchanged and does not
configure an AI tool. See [the development guide](docs/development.md) for setup.
