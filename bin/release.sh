#!/usr/bin/env bash
# =============================================================================
# bin/release.sh — Build the plugin ZIP and publish a GitHub release.
#
# Usage:
#   bash bin/release.sh [--draft] [--pre-release] [--notes <text>]
#
# Flags:
#   --draft        Create a draft release (default). Publish manually in GitHub.
#   --publish      Publish immediately (skip draft).
#   --pre-release  Mark as a pre-release.
#   --notes <text> Custom release notes (overrides --generate-notes).
#
# Prerequisites:
#   - GitHub CLI (gh): https://cli.github.com/
#     Install:  brew install gh
#     Auth:     gh auth login
#   - The working tree must be clean (no uncommitted changes).
#   - The version in intercom-woo-sync.php is the authoritative source.
#
# Example — create a draft release:
#   bash bin/release.sh
#
# Example — publish immediately:
#   bash bin/release.sh --publish
# =============================================================================

set -euo pipefail

# ---------------------------------------------------------------------------
# Defaults
# ---------------------------------------------------------------------------

DRAFT_FLAG="--draft"
PRERELEASE_FLAG=""
CUSTOM_NOTES=""
PLUGIN_SLUG="etherlabz-intercom-sync"
MAIN_FILE="intercom-woo-sync.php"

# ---------------------------------------------------------------------------
# Parse arguments
# ---------------------------------------------------------------------------

while [[ $# -gt 0 ]]; do
  case $1 in
    --draft)        DRAFT_FLAG="--draft"; shift ;;
    --publish)      DRAFT_FLAG=""; shift ;;
    --pre-release)  PRERELEASE_FLAG="--prerelease"; shift ;;
    --notes)        CUSTOM_NOTES="$2"; shift 2 ;;
    *) echo "Unknown option: $1"; exit 1 ;;
  esac
done

# ---------------------------------------------------------------------------
# Resolve root
# ---------------------------------------------------------------------------

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
ROOT_DIR="$(cd "${SCRIPT_DIR}/.." && pwd)"
cd "${ROOT_DIR}"

# ---------------------------------------------------------------------------
# Dependency checks
# ---------------------------------------------------------------------------

if ! command -v gh &>/dev/null; then
  echo "❌  GitHub CLI (gh) is not installed." >&2
  echo "    Install: brew install gh" >&2
  echo "    Docs:    https://cli.github.com/" >&2
  exit 1
fi

if ! gh auth status &>/dev/null; then
  echo "❌  Not authenticated with GitHub CLI." >&2
  echo "    Run: gh auth login" >&2
  exit 1
fi

# ---------------------------------------------------------------------------
# Guard — require a clean working tree.
# ---------------------------------------------------------------------------

if ! git diff --quiet || ! git diff --cached --quiet; then
  echo "❌  Working tree has uncommitted changes. Commit or stash first." >&2
  git status --short >&2
  exit 1
fi

# ---------------------------------------------------------------------------
# Read version
# ---------------------------------------------------------------------------

VERSION=$(grep -m1 "^[[:space:]]*\*[[:space:]]*Version:" "${MAIN_FILE}" | sed 's/.*Version:[[:space:]]*//' | tr -d '[:space:]')

if [[ -z "${VERSION}" ]]; then
  echo "❌  Could not read version from ${MAIN_FILE}" >&2
  exit 1
fi

TAG="v${VERSION}"
ZIP_PATH="dist/${PLUGIN_SLUG}-${VERSION}.zip"

echo "🚀  Releasing ${PLUGIN_SLUG} ${TAG}"

# ---------------------------------------------------------------------------
# Check for existing release
# ---------------------------------------------------------------------------

if gh release view "${TAG}" &>/dev/null; then
  echo "❌  Release ${TAG} already exists on GitHub." >&2
  echo "    Delete it first or bump the version." >&2
  exit 1
fi

# ---------------------------------------------------------------------------
# Ensure the tag exists locally and on remote
# ---------------------------------------------------------------------------

if ! git rev-parse "${TAG}" &>/dev/null; then
  echo "🏷   Creating and pushing tag ${TAG} …"
  git tag "${TAG}"
  git push origin "${TAG}"
else
  echo "🏷   Tag ${TAG} already exists locally."
  # Push in case it hasn't been pushed yet.
  git push origin "${TAG}" 2>/dev/null || true
fi

# ---------------------------------------------------------------------------
# Build the ZIP
# ---------------------------------------------------------------------------

echo "🔧  Building distribution ZIP …"
bash "${SCRIPT_DIR}/build.sh"

if [[ ! -f "${ZIP_PATH}" ]]; then
  echo "❌  Build failed — ZIP not found at ${ZIP_PATH}" >&2
  exit 1
fi

# ---------------------------------------------------------------------------
# Create the GitHub release
# ---------------------------------------------------------------------------

RELEASE_ARGS=("${TAG}" "${ZIP_PATH}" "--title" "${TAG}")

if [[ -n "${DRAFT_FLAG}" ]]; then
  RELEASE_ARGS+=("${DRAFT_FLAG}")
fi

if [[ -n "${PRERELEASE_FLAG}" ]]; then
  RELEASE_ARGS+=("${PRERELEASE_FLAG}")
fi

if [[ -n "${CUSTOM_NOTES}" ]]; then
  RELEASE_ARGS+=("--notes" "${CUSTOM_NOTES}")
else
  RELEASE_ARGS+=("--generate-notes")
fi

echo "📦  Creating GitHub release …"
RELEASE_URL=$(gh release create "${RELEASE_ARGS[@]}")

# ---------------------------------------------------------------------------
# Summary
# ---------------------------------------------------------------------------

echo ""
echo "✅  Release ${TAG} created!"
if [[ -n "${DRAFT_FLAG}" ]]; then
  echo "    Status : DRAFT — review and publish at GitHub before sharing."
else
  echo "    Status : PUBLISHED"
fi
echo "    URL    : ${RELEASE_URL}"
echo "    Asset  : ${ZIP_PATH}"
