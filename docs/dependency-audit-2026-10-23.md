# Dependency audit — October 23, 2026

The targeted root npm update reduces the audit from **10 vulnerable package
entries (1 critical, 8 high, 1 moderate)** to **7 (6 high, 1 moderate)**.
The root runtime audit, `npm audit --omit=dev`, reports zero findings. All
remaining entries belong to development-only release tooling; this audit does
not cover dependencies installed separately by generated child themes.

## Compatible updates

| Lockfile entry | Before → after | Dependency owner |
| --- | --- | --- |
| `handlebars` | 4.7.9 → 4.7.10 | `conventional-changelog-writer` |
| `js-yaml` | 4.3.0 → 4.3.2 | Root YAML contract checks and release tooling |
| `fast-uri` | 3.1.5 → 3.1.9 | `ajv` |
| `@actions/http-client` → `undici` | 6.27.0 → 6.29.1 | `@semantic-release/npm` → `@actions/core` |
| Root `undici` | 7.28.0 → 7.30.1 | `@semantic-release/github` |

The direct `js-yaml` pin changes from `4.3.0` to `4.3.2`; its exact pin otherwise
prevents `npm update` from selecting the fix. All other manifest ranges stay
unchanged. There are no overrides, dependency major upgrades, engine changes,
or theme version changes. npm also removes a redundant optional peer copy of
`conventional-commits-filter` 6.0.1 from the lockfile.

Fast URI 3.1.9 includes the fix for
[GHSA-hrr3-gc8f-f4qj](https://github.com/advisories/GHSA-hrr3-gc8f-f4qj), whose
affected range includes 3.1.7. The independently resolved Handlebars, JS-YAML,
Fast URI and Undici copies are absent from the final audit findings.

## Remaining findings and ownership

Every remaining finding is inside
`semantic-release` → `@semantic-release/npm` 13.1.5 → `npm` 11.18.0. npm bundles
these dependencies, so updating the independent packages above does not replace
them.

| Bundled package | Installed version | Audit severity | Follow-up owner |
| --- | --- | --- | --- |
| `brace-expansion` | 5.0.7 | High | npm bundle; root maintainers can evaluate a separate npm lock update. |
| `http-cache-semantics` | 4.2.0 | High | npm bundle; root maintainers can evaluate a separate npm lock update. |
| `ip-address` | 10.2.0 | High | npm bundle; root maintainers can evaluate a separate npm lock update. |
| `postcss-selector-parser` | 7.1.4 | Moderate | npm bundle; root maintainers can evaluate a separate npm lock update. |
| `tar` | 7.5.19 | High | npm bundle; root maintainers can evaluate a separate npm lock update. |
| `undici` | 6.27.0 | High | npm bundle; root maintainers can evaluate a separate npm lock update. |

The seventh entry is the propagated high finding on `npm` itself, via `tar`;
these are package entries, not seven independent vulnerabilities. The installed
`@semantic-release/npm` accepts npm `^11.6.2`, so a separate compatible npm update
can be assessed without changing the release plugin's major version. It was
outside this four-package update; no bundled leaf was replaced manually.

Root `micromatch` 4.0.8 also depends on `braces` 3.0.3. Neither is reported by
this audit response. If that path is flagged by a future audit, the dependency
owners are `braces`/`micromatch` and their release-tooling consumers; a generated
theme's Core/Stylelint tree must be assessed separately. A clean runtime audit
does not establish that development tooling or generated projects are free of
security issues.

## Reproduction

Commands ran with Node 24.22.1 and npm 11.19.1:

```sh
npm audit --json
# Update the existing exact js-yaml pin to 4.3.2 first.
npm update handlebars js-yaml fast-uri undici --ignore-scripts
npm ci --ignore-scripts
npm audit --json
npm audit --omit=dev --json
npm run test:generated-theme
```

The clean install succeeds. Both full audits exit 1 because findings remain;
the runtime audit exits 0. The generated-theme contract tests verify the YAML
guidance checks with the updated parser. Results are point-in-time registry
observations, and should be refreshed before the release.

## October 28 release-tooling refresh

The snapshots above remain the October 23 observations. On October 28, the
root tooling was refreshed to the baselines in the published Drupal 7.3.0
release, using Node 24.22.1 and npm 11.19.1:

| Direct development dependency | Range before → after | Locked version before → after |
| --- | --- | --- |
| `semantic-release` | `^25.0.8` → `^25.0.9` | 25.0.8 → 25.0.9 |
| `@semantic-release/github` | `^12.0.9` → `^12.0.10` | 12.0.9 → 12.0.10 |
| `@commitlint/cli` | `^21.2.1` → `^21.2.4` | 21.2.1 → 21.2.4 |
| `@commitlint/config-conventional` | `^21.2.0` → `^21.2.4` | 21.2.0 → 21.2.4 |
| `lint-staged` | `^17.3.0` → `^17.6.0` | 17.3.0 → 17.6.0 |

Only those five `devDependencies` and their lockfile resolutions change.
`js-yaml` stays pinned and locked at 4.3.2. Theme versions, Composer dependencies,
Whisk dependency ranges, release plugins and the prepared-version policy are
unchanged. The update uses `npm install --save-dev --ignore-scripts`, followed
by a clean `npm ci --ignore-scripts`; no audit fix or overrides were applied.

### Before and after findings

| Audit | Before refresh | After clean installation | Exit status |
| --- | --- | --- | --- |
| `npm audit --json` | 6 package entries: 5 high, 1 moderate | Same 6 entries and severities | 1 both times |
| `npm audit --omit=dev --json` | 0 findings | 0 findings | 0 both times |

The before snapshot already differs from October 23's seven entries; that
point-in-time registry difference is not a result of this refresh. None of the
six October 28 findings was fixed by the five targeted updates:

| Package | Installed before and after | Severity | Dependency owner | Ships in parent ZIP? |
| --- | --- | --- | --- | --- |
| `brace-expansion` | 5.0.7 | High | npm 11.18.0's bundled dependencies | No |
| `ip-address` | 10.2.0 | High | npm 11.18.0's bundled dependencies | No |
| `postcss-selector-parser` | 7.1.4 | Moderate | npm 11.18.0's bundled dependencies | No |
| `tar` | 7.5.19 | High | npm 11.18.0's bundled dependencies | No |
| `undici` | 6.27.0 | High | npm 11.18.0's bundled dependencies | No |
| `npm` | 11.18.0 | High, propagated via `tar` | `@semantic-release/npm` 13.1.5 | No |

All six paths are under `semantic-release` → `@semantic-release/npm` 13.1.5 →
its bundled npm 11.18.0. The npm plugin still accepts npm `^11.6.2`; evaluating
a separate update of that bundle remains a maintainer follow-up. This plugin
is installed as a semantic-release dependency, but WordPress's explicit release
plugin list does not use it to write or publish npm version metadata. The npm
entry is propagated, so these counts are package entries, not six independent
vulnerabilities.

The parent ZIP excludes root npm manifests, `node_modules`, and release tools;
it contains production Composer dependencies instead. A before/after ZIP
comparison at the same Git HEAD confirmed 616 identical entry paths and
byte-identical runtime/vendor files; only documentation changed. This audit
covers the root npm tree, not Composer
packages or dependencies installed separately by generated child themes.

Reproduce the targeted update and audit with:

```sh
npm audit --json
npm audit --omit=dev --json
npm install --save-dev --ignore-scripts 'semantic-release@^25.0.9' '@semantic-release/github@^12.0.10' '@commitlint/cli@^21.2.4' '@commitlint/config-conventional@^21.2.4' 'lint-staged@^17.6.0'
npm ci --ignore-scripts
npm audit --json
npm audit --omit=dev --json
```
