# Support information for %%EMULSIFY_THEME_NAME%%

Work through the troubleshooting list first. If the problem persists, collect the sanitized information below before opening an issue or asking for help.

## Troubleshooting

- **Wrong Node.js version:** run `nvm use` and compare `node --version` with the resolved Emulsify Core package's `engines.node`. Core 4.5.0 requires at least 24.13.0 even though the theme advertises `>=24`; `.nvmrc` selects only the Node 24 line.
- **Missing package or command:** rerun `npm install`. A generated theme has no lockfile until the project commits one.
- **WordPress cannot find project assets:** confirm a component library is installed and that a build has produced output under `dist/`. The parent theme enqueues discovered build output; it does not invent asset paths.
- **The parent theme is not being used:** confirm the `Template` header in `style.css` names the installed Emulsify parent theme directory and that the parent theme is present in the themes directory.
- **Storybook fails:** run `npm run build` first, then retry.
- **A generated value looks wrong:** inspect `style.css`, `package.json`, and `project.emulsify.json` before editing documentation by hand.

## Theme and frontend information

```bash
node --version
npm --version
npm ls @emulsify/core --depth=0
npm run
node -e "const data=require('./project.emulsify.json'); console.log(data.project)"
```

The expected Emulsify Core range is `%%EMULSIFY_CORE_RANGE%%`. The generated-source record is `%%EMULSIFY_SOURCE_PROJECT%%` `%%EMULSIFY_SOURCE_VERSION%%` for machine name `%%EMULSIFY_MACHINE_NAME%%`.

Confirm the build and Storybook build succeed independently:

```bash
npm run build
npm run storybook-build
```

## WordPress environment information

Run these from the WordPress project root:

```bash
php --version
composer --version
wp core version
wp theme list --format=table
wp plugin list --status=active --format=table
```

Also note the database server and version, the active theme, whether Timber is available, and whether the problem affects administrators, anonymous visitors, or both.

Do not share database credentials, API keys, environment variables, private URLs, user data, full option or configuration exports, or unreviewed site health reports. Redact hostnames and paths that identify a client environment.

## Problem description

Include:

- the failing command or page, plus the full error output;
- a minimal reproduction;
- whether the problem began after a dependency update, a WordPress or plugin update, a content change, or a project code change;
- a sanitized diff of the relevant project change;
- whether `npm run build` and `npm run storybook-build` pass independently.

Future automated WordPress diagnostic collection belongs in the parent theme or a companion plugin so it can use WordPress APIs and apply consistent redaction. This generated theme intentionally provides guidance only and adds no diagnostic runtime code.
