#!/usr/bin/env bash
# Build a production WordPress plugin zip for local testing.
# Usage: bash bin/build-plugin.sh [--version X.Y.Z]
#
# Output: dist/sybgo-{version}.zip (relative to repo root)
set -eu

# ---------------------------------------------------------------------------
# Resolve paths
# ---------------------------------------------------------------------------
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
REPO_ROOT="$(cd "${SCRIPT_DIR}/.." && pwd)"
PLUGIN_DIR="${REPO_ROOT}/wp-plugin"
LIB_DIR="${REPO_ROOT}/lib"
DIST_DIR="${REPO_ROOT}/dist"

# ---------------------------------------------------------------------------
# Parse arguments
# ---------------------------------------------------------------------------
VERSION_OVERRIDE=""
while [[ $# -gt 0 ]]; do
  case "$1" in
    --version) VERSION_OVERRIDE="$2"; shift 2 ;;
    *) echo "Unknown argument: $1" >&2; exit 1 ;;
  esac
done

# ---------------------------------------------------------------------------
# Resolve plugin version
# ---------------------------------------------------------------------------
if [[ -n "${VERSION_OVERRIDE}" ]]; then
  PLUGIN_VERSION="${VERSION_OVERRIDE}"
else
  PLUGIN_VERSION=$(grep -m1 '^ \* Version:' "${PLUGIN_DIR}/class-sybgo.php" \
    | sed 's/.*Version: *//' | tr -d '[:space:]')
  if [[ -z "${PLUGIN_VERSION}" ]]; then
    echo "Error: could not read Version from class-sybgo.php" >&2
    exit 1
  fi
fi

echo "Building sybgo version: ${PLUGIN_VERSION}"

# ---------------------------------------------------------------------------
# Prerequisite checks
# ---------------------------------------------------------------------------
command -v composer &>/dev/null || { echo "Error: composer not found. Install from https://getcomposer.org" >&2; exit 1; }
command -v zip      &>/dev/null || { echo "Error: zip not found. Install zip (e.g. brew install zip)." >&2; exit 1; }
command -v rsync    &>/dev/null || { echo "Error: rsync not found." >&2; exit 1; }

# ---------------------------------------------------------------------------
# Build rsync --exclude flags from a .distignore file
# Falls back to hardcoded defaults if no .distignore is present.
# ---------------------------------------------------------------------------
build_excludes() {
  local distignore="${PLUGIN_DIR}/.distignore"
  if [[ -f "${distignore}" ]]; then
    while IFS= read -r line; do
      # Skip blank lines and comments
      [[ -z "${line}" || "${line}" == \#* ]] && continue
      printf -- '--exclude=%s\n' "${line}"
    done < "${distignore}"
  else
    # Default excludes when no .distignore exists
    printf -- '--exclude=%s\n' \
      'Tests/' 'bin/' 'docs/' \
      'composer.json' 'composer.lock' \
      'phpunit.xml.dist' 'phpcs.xml' 'phpcs.xml.dist' \
      'phpstan.neon' 'phpstan-bootstrap.php' \
      '.git/' '.DS_Store' '*.cache'
  fi
}

# ---------------------------------------------------------------------------
# Staging area with guaranteed cleanup
# ---------------------------------------------------------------------------
BUILD_TMP=$(mktemp -d /tmp/sybgo-build-XXXXXX)
trap 'rm -rf "${BUILD_TMP}"' EXIT
STAGE="${BUILD_TMP}/sybgo"
mkdir "${STAGE}"

# ---------------------------------------------------------------------------
# Step 1: Install production Composer dependencies
# ---------------------------------------------------------------------------
echo "Installing production Composer dependencies..."
(cd "${PLUGIN_DIR}" && composer install --no-dev --prefer-dist --no-interaction --quiet)

# Read excludes into an array (bash 3.2 compatible: no mapfile)
RSYNC_EXCLUDE_ARGS=()
while IFS= read -r flag; do
  RSYNC_EXCLUDE_ARGS[${#RSYNC_EXCLUDE_ARGS[@]}]="${flag}"
done < <(build_excludes)

# ---------------------------------------------------------------------------
# Step 2: Replace symlink with a clean copy of lib/ (no dev artifacts)
# lib/ vendor is installed without dev dependencies before copying.
# ---------------------------------------------------------------------------
echo "Installing production Composer dependencies for lib..."
rm -rf "${LIB_DIR}/vendor"
(cd "${LIB_DIR}" && composer install --no-dev --prefer-dist --no-interaction --quiet)

echo "Replacing vendor/wp-media/sybgo-lib symlink with real copy..."
rm -rf "${PLUGIN_DIR}/vendor/wp-media/sybgo-lib"
rsync -a --quiet "${RSYNC_EXCLUDE_ARGS[@]}" \
  "${LIB_DIR}/" "${PLUGIN_DIR}/vendor/wp-media/sybgo-lib/"

# ---------------------------------------------------------------------------
# Step 3: Stage production plugin files
# ---------------------------------------------------------------------------
echo "Staging plugin files..."
rsync -a --quiet "${RSYNC_EXCLUDE_ARGS[@]}" \
  "${PLUGIN_DIR}/" "${STAGE}/"

# ---------------------------------------------------------------------------
# Step 4: Create zip from staging area
# ---------------------------------------------------------------------------
mkdir -p "${DIST_DIR}"
ZIP_NAME="sybgo-${PLUGIN_VERSION}.zip"
ZIP_PATH="${DIST_DIR}/${ZIP_NAME}"
rm -f "${ZIP_PATH}"
echo "Creating ${ZIP_NAME}..."
(cd "${BUILD_TMP}" && zip -r --quiet "${ZIP_PATH}" sybgo/)

# ---------------------------------------------------------------------------
# Done
# ---------------------------------------------------------------------------
echo ""
echo "Build complete."
echo "  File : ${ZIP_PATH}"
echo "  Size : $(du -sh "${ZIP_PATH}" | cut -f1)"
echo ""
echo "Install via: WP Admin > Plugins > Add New > Upload Plugin"
