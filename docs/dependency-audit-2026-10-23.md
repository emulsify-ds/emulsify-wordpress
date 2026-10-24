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
