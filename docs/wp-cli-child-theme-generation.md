# WP-CLI child theme generation

The parent theme includes a WP-CLI command for generating a project child theme from the bundled Whisk starter.

WordPress must bootstrap the parent theme before the command can register. Keep Emulsify active directly, or installed as the parent of the active child theme, and point WP-CLI at the directory containing WordPress core.

## Basic usage

```sh
wp emulsify "Acme Site" --dry-run
wp emulsify "Acme Site" --machine-name=acme-site
wp emulsify "Acme Site" --machine-name=acme-site --parent=emulsify
wp emulsify "Acme Site" --machine-name=acme-site --force
wp emulsify "Acme Site" --machine-name=acme-site --activate
```

## Bedrock and DDEV

Bedrock commonly keeps WordPress core in `web/wp` while the HTTP document root is `web`. Add a project-level `wp-cli.yml` so WP-CLI targets core rather than only the document root:

```yaml
path: web/wp
server:
  docroot: web
```

With that file in place, the normal DDEV command works:

```sh
ddev wp emulsify "Acme Site" --dry-run
```

You can also pass the path explicitly:

```sh
ddev wp --path=web/wp emulsify "Acme Site" --dry-run
```

For new DDEV projects, use the `wp-bedrock` project type rather than the standard `wordpress` type.

If WP-CLI reports both `No WordPress installation found` and `emulsify is not a registered wp command`, fix the WordPress path first. The registration error is a consequence of WordPress and the active theme never loading. Confirm the target and registration independently:

```sh
ddev wp --path=web/wp core is-installed
ddev wp --path=web/wp cli has-command emulsify
```

## Options

| Option | Purpose |
| --- | --- |
| `--machine-name=<slug>` | Override the generated slug used for paths and package metadata. |
| `--parent=<slug>` | Use a different installed parent theme directory slug. Defaults to `emulsify`. |
| `--dry-run` | Show what would be created or changed without writing files. |
| `--force` | Replace an existing destination only when it already looks like an Emulsify-generated child theme. |
| `--activate` | Activate the generated child theme after creation. |

## What the generator updates

The generator copies `emulsify/whisk` to a sibling child theme directory and updates:

- WordPress `Theme Name`.
- WordPress `Text Domain`.
- WordPress `Template`.
- `package.json` name.
- `project.emulsify.json` project name.
- `project.emulsify.json` machine name.
- `project.emulsify.json` generated source metadata.
- Visible Whisk starter labels where appropriate.
- Lowercase slug references where appropriate.

The generator avoids broad blind string replacement. It targets known metadata files and starter labels.

Ignored dependency, cache, Storybook, and Vite output directories are not copied. A generated child theme should install its own dependencies, install or configure the chosen Emulsify component system, and create its own `dist` output.

## Force replacement safety

`--force` refuses to delete an existing destination unless the directory has Emulsify-generated child theme markers:

- `style.css` declares `Template: emulsify` or the selected parent slug.
- `project.emulsify.json` exists.
- `project.emulsify.json` declares `"platform": "wordpress"`.
- `project.emulsify.json` includes a project `machineName` that exactly matches the requested machine name.
- `project.emulsify.json` includes `generatedFrom: "emulsify-wordpress"` and a non-empty `generatedFromVersion`.

Use `--dry-run --force` to inspect replacement intent without deleting files. If the destination is an unrelated theme, remove or rename it manually before generating a child theme with the same machine name.

Generation is atomic. The command copies and updates the new child theme in a temporary sibling directory, then moves it into place. With `--force`, the existing verified theme is moved to a temporary backup only after staging succeeds and is restored if the final move fails.

## Upgrade and support diagnostics

Generated child themes record their source in `project.emulsify.json`:

```json
{
  "project": {
    "platform": "wordpress",
    "generatedFrom": "emulsify-wordpress",
    "generatedFromVersion": "2.0.0"
  }
}
```

Use these fields when diagnosing project lineage or planning starter upgrades. `generatedFrom` identifies the Emulsify WordPress starter lineage, while `generatedFromVersion` records the parent/starter release that generated or last regenerated the child theme. For safety, `--force` does not replace older child themes that lack these lineage fields; add verified metadata deliberately or replace those directories manually.

## Emulsify CLI starter hook

Emulsify CLI uses the standalone starter repository at `https://github.com/emulsify-ds/emulsify-wordpress-starter`. That repository should be built from this branch's `whisk/` directory, which is the generated child theme layer rather than the parent runtime theme root.

The starter includes `.cli/init.js` for CLI initialization. Current Emulsify CLI timing is:

1. Clone the starter.
2. Write `project.emulsify.json`.
3. Run `npm install`.
4. Execute `.cli/init.js`.

Because `npm install` runs before the hook, the hook updates package metadata and the generated `package-lock.json` when the lockfile exists. A pre-install CLI hook is not required for the current targeted metadata updates.

The hook deliberately mirrors the parent WP-CLI generator's targeted updates:

- `style.css` `Theme Name`, `Text Domain`, and `Template`.
- `package.json` name and generated package lockfile root name.
- `project.emulsify.json` project name, machine name, generated source metadata, and `"platform": "wordpress"`.
- Visible Whisk labels in known starter files.
- JSON pattern names from the `whisk/*` namespace to the generated machine-name namespace.

No Twig templates or `templates/` directory are generated. Create project
overrides only as needed, using the [template extension contract](template-extension.md).

The generated child theme must keep `Template: emulsify`; the parent theme remains installed separately as `emulsify`.

## After generation

Install dependencies and build assets in the generated child theme:

```sh
cd web/app/themes/acme-site
npm install
npm run build
```

Activate the child theme for site work. The parent theme should stay installed as `emulsify`.
