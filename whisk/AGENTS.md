# Theme guidance

If `.cli/init.js` exists, this directory is a generation source. Follow the
enclosing repository's contributor instructions and validate generated output.
An installed parent may also bundle generation source without `.cli/`; check
enclosing instructions before treating this directory as a project-owned theme.

Read [README.md](README.md), [the development guide](docs/development.md),
`project.emulsify.json`, `package.json`, and `style.css` before changing theme
code. Discover identity, available commands, dependencies, and installed
component structure from those files and existing project code. Preserve
`Template: emulsify`, `generatedFrom`, and `generatedFromVersion`.

For WordPress template or component integration, use
[the integration skill](.agents/skills/emulsify-wordpress-integration/SKILL.md)
and [the integration guide](docs/wordpress-integration.md). For upgrades or
failures, read [upgrading](docs/upgrading.md) or
[support information](docs/support-information.md).

Read `docs/component-library.md` when the installed library supplies it, along
with relevant component schemas, stories, source, and local instructions. The
library owns its layout, APIs, tokens, and build inputs; the project owns its
WordPress data mappings and hooks. Discover these before choosing an approach.

Run frontend commands from this theme directory. Select checks from
`package.json` for the changed behavior and available build inputs, then verify
WordPress integration in the actual site. A passing story does not prove
Timber context, block registration, or WordPress hooks work.
