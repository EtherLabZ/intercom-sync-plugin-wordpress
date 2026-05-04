#!/usr/bin/env bash
# =============================================================================
# bin/build.sh — Build a production-ready ZIP for the plugin.
#
# Usage:
#   bash bin/build.sh [--out-dir <path>]
#
# Output:
#   dist/intercom-woo-sync-<version>.zip
#
# The ZIP contains only the files that should ship to end-users.
# Files listed in .distignore are excluded.
# =============================================================================

set -euo pipefail

# ---------------------------------------------------------------------------
# Configuration
# ---------------------------------------------------------------------------

PLUGIN_SLUG="intercom-woo-sync"
MAIN_FILE="intercom-woo-sync.php"
DIST_DIR="dist"

# Allow overriding the output directory.
while [[ $# -gt 0 ]]; do
  case $1 in
    --out-dir) DIST_DIR="$2"; shift 2 ;;
    *) echo "Unknown option: $1"; exit 1 ;;
  esac
done

# ---------------------------------------------------------------------------
# Resolve root — always run from the repo root regardless of CWD.
# ---------------------------------------------------------------------------

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
ROOT_DIR="$(cd "${SCRIPT_DIR}/.." && pwd)"
cd "${ROOT_DIR}"

# ---------------------------------------------------------------------------
# Read version from plugin header.
# ---------------------------------------------------------------------------

if [[ ! -f "${MAIN_FILE}" ]]; then
  echo "❌  ${MAIN_FILE} not found. Run this script from the plugin root." >&2
  exit 1
fi

VERSION=$(grep -m1 "^[[:space:]]*\*[[:space:]]*Version:" "${MAIN_FILE}" | sed 's/.*Version:[[:space:]]*//' | tr -d '[:space:]')

if [[ -z "${VERSION}" ]]; then
  echo "❌  Could not read version from ${MAIN_FILE}" >&2
  exit 1
fi

ZIP_NAME="${PLUGIN_SLUG}-${VERSION}.zip"
STAGING_DIR="${DIST_DIR}/${PLUGIN_SLUG}"
ZIP_PATH="${DIST_DIR}/${ZIP_NAME}"

echo "🔧  Building ${PLUGIN_SLUG} v${VERSION} …"

# ---------------------------------------------------------------------------
# Clean any previous build for this slug.
# ---------------------------------------------------------------------------

rm -rf "${STAGING_DIR}" "${ZIP_PATH}"
mkdir -p "${DIST_DIR}"

# ---------------------------------------------------------------------------
# Copy production files to staging directory using .distignore.
# ---------------------------------------------------------------------------

if [[ ! -f ".distignore" ]]; then
  echo "⚠️   .distignore not found — copying everything (this may include dev files)." >&2
fi

rsync \
  --archive \
  --exclude-from=".distignore" \
  --exclude="${DIST_DIR}/" \
  . \
  "${STAGING_DIR}/"

# Ensure the languages directory exists (wordpress.org expects it).
mkdir -p "${STAGING_DIR}/languages"

# ---------------------------------------------------------------------------
# Create the ZIP — the inner folder must match the plugin slug.
# ---------------------------------------------------------------------------

cd "${DIST_DIR}"
zip --recurse-paths --quiet "${ZIP_NAME}" "${PLUGIN_SLUG}/"
cd "${ROOT_DIR}"

# Clean up staging directory.
rm -rf "${STAGING_DIR}"

# ---------------------------------------------------------------------------
# Summary
# ---------------------------------------------------------------------------

ZIP_SIZE=$(du -sh "${ZIP_PATH}" | cut -f1)
echo "✅  Built: ${ZIP_PATH}  (${ZIP_SIZE})"
echo "   Version : ${VERSION}"
echo "   Slug    : ${PLUGIN_SLUG}"
