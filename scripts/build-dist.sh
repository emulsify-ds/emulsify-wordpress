#!/usr/bin/env bash

set -euo pipefail

repo_root="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
artifact_dir="${repo_root}/dist-artifact"
artifact_path="${artifact_dir}/emulsify.zip"

for command_name in composer git node zip; do
  if ! command -v "${command_name}" >/dev/null 2>&1; then
    echo "Required command not found: ${command_name}" >&2
    exit 1
  fi
done

# Resolve one immutable commit before reading any payload. Working-tree and index
# edits never become release inputs, even if HEAD moves while the build runs.
build_commit="$(git -C "${repo_root}" rev-parse --verify 'HEAD^{commit}')"

while IFS= read -r -d '' tree_entry; do
  case "${tree_entry}" in
    '120000 '*)
      echo "Refusing to package committed symlink: ${tree_entry#*$'\t'}" >&2
      exit 1
      ;;
  esac
done < <(git -C "${repo_root}" ls-tree -r -z "${build_commit}")

copy_committed_file() {
  local relative_path="$1"
  local target_path="${staging_root}/${relative_path}"

  mkdir -p "$(dirname "${target_path}")"
  if ! git -C "${repo_root}" cat-file blob "${build_commit}:${relative_path}" > "${target_path}"; then
    echo "Required committed file not found: ${relative_path}" >&2
    exit 1
  fi
}

copy_committed_directory() {
  local relative_directory="$1"
  local relative_path
  local copied_files=0

  while IFS= read -r -d '' relative_path; do
    case "/${relative_path}/" in
      */.cache/*|*/.cli/*|*/.coverage/*|*/.out/*|*/dist/*|*/node_modules/*)
        continue
        ;;
    esac

    copy_committed_file "${relative_path}"
    copied_files=$((copied_files + 1))
  done < <(git -C "${repo_root}" ls-tree -r -z --name-only "${build_commit}" -- "${relative_directory}")

  if (( copied_files == 0 )); then
    echo "No committed files found for required directory: ${relative_directory}" >&2
    exit 1
  fi
}

mkdir -p "${artifact_dir}"
staging_parent="$(mktemp -d "${artifact_dir}/.build.XXXXXX")"
staging_root="${staging_parent}/emulsify"
temporary_archive="${staging_parent}/emulsify.zip"

cleanup() {
  rm -rf "${staging_parent}"
}
trap cleanup EXIT

mkdir -p "${staging_root}"

runtime_files=(
  "404.php"
  "AGENTS.md"
  "LICENSE"
  "README.md"
  "UPGRADE.md"
  "archive.php"
  "author.php"
  "functions.php"
  "index.php"
  "page.php"
  "screenshot.png"
  "search.php"
  "single.php"
  "style.css"
  "theme.json"
)

runtime_directories=(
  "docs"
  "includes"
  "src"
  "templates"
)

for relative_path in "${runtime_files[@]}"; do
  copy_committed_file "${relative_path}"
done

for relative_directory in "${runtime_directories[@]}"; do
  copy_committed_directory "${relative_directory}"
done

# Whisk is product payload for `wp emulsify`, but only its committed generator
# source belongs in the release. Development output stays outside the archive.
copy_committed_directory "whisk"

# Read Git objects explicitly rather than git archive: export-ignore omits the
# lockfile from Git distributions, but Composer needs it for reproducible builds.
for composer_file in composer.json composer.lock; do
  copy_committed_file "${composer_file}"
done

echo "Installing production Composer dependencies..."
composer \
  --working-dir="${staging_root}" \
  install \
  --no-dev \
  --no-interaction \
  --no-progress \
  --prefer-dist \
  --optimize-autoloader

rm -f "${staging_root}/composer.json" "${staging_root}/composer.lock"

if [[ ! -f "${staging_root}/vendor/autoload.php" ]]; then
  echo "Composer did not create the staged vendor/autoload.php file." >&2
  exit 1
fi

required_release_paths=(
  "AGENTS.md"
  "UPGRADE.md"
  "docs/wp-cli-child-theme-generation.md"
  "functions.php"
  "vendor/autoload.php"
  "whisk/README.md"
  "whisk/AGENTS.md"
  "whisk/.agents/skills/emulsify-wordpress-integration/SKILL.md"
  "whisk/docs/wordpress-integration.md"
  "whisk/package.json"
  "whisk/project.emulsify.json"
  "whisk/style.css"
)

for relative_path in "${required_release_paths[@]}"; do
  if [[ ! -f "${staging_root}/${relative_path}" ]]; then
    echo "Required release path not found after staging: ${relative_path}" >&2
    exit 1
  fi
done

for forbidden_path in \
  ".github" \
  ".husky" \
  "node_modules" \
  "package-lock.json" \
  "package.json" \
  "scripts" \
  "whisk/.cli" \
  "whisk/.cache" \
  "whisk/.coverage" \
  "whisk/.out" \
  "whisk/dist" \
  "whisk/node_modules"; do
  if [[ -e "${staging_root}/${forbidden_path}" ]]; then
    echo "Forbidden development path found in staged release: ${forbidden_path}" >&2
    exit 1
  fi
done

if [[ -z "${SOURCE_DATE_EPOCH:-}" ]]; then
  SOURCE_DATE_EPOCH="$(git -C "${repo_root}" show --no-show-signature -s --format=%ct "${build_commit}")"
fi

if [[ ! "${SOURCE_DATE_EPOCH}" =~ ^[0-9]+$ ]] || (( SOURCE_DATE_EPOCH < 315532800 )); then
  echo "SOURCE_DATE_EPOCH must be an integer at or after 1980-01-01." >&2
  exit 1
fi

node - "${staging_root}" "${SOURCE_DATE_EPOCH}" <<'NODE'
const fs = require('fs');
const path = require('path');

const root = process.argv[2];
const epoch = Number(process.argv[3]);

function normalize(entryPath) {
  const stat = fs.lstatSync(entryPath);

  if (stat.isDirectory()) {
    for (const entry of fs.readdirSync(entryPath).sort()) {
      normalize(path.join(entryPath, entry));
    }
    fs.chmodSync(entryPath, 0o755);
    fs.utimesSync(entryPath, epoch, epoch);
    return;
  }

  if (stat.isFile()) {
    fs.chmodSync(entryPath, 0o644);
    fs.utimesSync(entryPath, epoch, epoch);
  }
}

normalize(root);
NODE

echo "Creating ${artifact_path}..."
(
  cd "${staging_parent}"
  find emulsify -type f -print | LC_ALL=C sort | zip -X -q "${temporary_archive}" -@
)

archive_listing="$(zip -sf "${temporary_archive}")"
for relative_path in "${required_release_paths[@]}"; do
  if ! grep -Fqx "  emulsify/${relative_path}" <<< "${archive_listing}"; then
    echo "Required release path not found in ZIP: ${relative_path}" >&2
    exit 1
  fi
done

mv "${temporary_archive}" "${artifact_path}"
echo "Built ${artifact_path}"
