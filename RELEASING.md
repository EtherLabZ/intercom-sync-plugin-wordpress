# Release Process

This document defines the **authoritative, step-by-step release process** for
Intercom WooCommerce Sync. It is written for human developers **and** AI agents.
Follow every step in order; do not skip or reorder.

---

## Concepts

| Term | Meaning |
|---|---|
| **Version** | Semver string in the plugin header (`Version: X.Y.Z`). This is the single source of truth. |
| **Tag** | Git tag `vX.Y.Z` (stable) or `vX.Y.Z-<suffix>` (pre-release, e.g. `v1.3.0-beta-rc1`). |
| **ZIP** | Production archive built by `bin/build.sh`. Contains only runtime files. |
| **Release** | A GitHub release attached to a tag with the ZIP as an asset. |
| **Draft** | A release not yet visible to the public. Always create a draft first for inspection. |

---

## Pre-flight checklist

Before starting a release, verify **all** of the following:

```
[ ] All feature work merged to main
[ ] git status is clean (no uncommitted changes, no untracked files that matter)
[ ] composer test passes  (./vendor/bin/phpunit)
[ ] composer lint passes  (./vendor/bin/phpcs)
[ ] Version in intercom-woo-sync.php is correct and bumped from previous release
[ ] INTERCOM_WOO_SYNC_VERSION constant matches the plugin header Version:
[ ] No existing git tag with the same name
[ ] GitHub Actions are enabled on the repo
```

If any item fails, **stop and fix it before continuing**.

---

## Step 1 — Decide the version and tag

Use [Semantic Versioning](https://semver.org/):

| Change type | Bump | Example |
|---|---|---|
| Bug fixes only | Patch | `1.3.0` → `1.3.1` |
| New backwards-compatible feature | Minor | `1.3.0` → `1.4.0` |
| Breaking change | Major | `1.3.0` → `2.0.0` |

**Tag naming:**

| Release type | Tag format | Example |
|---|---|---|
| Stable | `vX.Y.Z` | `v1.3.0` |
| Beta / RC | `vX.Y.Z-<label>` | `v1.3.0-beta-rc1` |
| Alpha | `vX.Y.Z-alpha.N` | `v1.4.0-alpha.1` |

The GitHub Actions workflow auto-marks any tag containing `-` as a pre-release.

---

## Step 2 — Bump the version

Edit **`intercom-woo-sync.php`** — two places must match:

```php
// Plugin header (line ~13)
 * Version:           1.3.1

// Version constant (line ~45)
define( 'INTERCOM_WOO_SYNC_VERSION', '1.3.1' );
```

Verify they match:
```bash
grep -E "Version:|INTERCOM_WOO_SYNC_VERSION" intercom-woo-sync.php
```

---

## Step 3 — Run the full quality gate

```bash
composer install          # ensure deps are up to date
composer lint             # must exit 0
composer test             # must exit 0, all tests green
```

Fix any failures before continuing. Do not release a red build.

---

## Step 4 — Commit the version bump

```bash
git add intercom-woo-sync.php
git commit -m "Bump version to X.Y.Z"
git push origin main
```

The commit message must be a plain version bump statement. Do not bundle
feature changes into the version-bump commit.

---

## Step 5 — Create and push the tag

```bash
git tag vX.Y.Z            # e.g.  git tag v1.3.1
git push origin vX.Y.Z    # triggers GitHub Actions
```

For a pre-release:
```bash
git tag v1.3.0-beta-rc1
git push origin v1.3.0-beta-rc1
```

> **Do not** amend or force-push a tag that has already been pushed.
> If the tag is wrong, delete it (`git tag -d <tag> && git push origin :refs/tags/<tag>`)
> and start Step 5 again.

---

## Step 6 — Monitor the GitHub Actions workflow

1. Open the repo on GitHub → **Actions** tab.
2. Find the **Release** workflow triggered by the tag push.
3. Confirm it completes with a green checkmark.
4. If it fails, read the logs, fix the root cause, delete the tag, and re-run from Step 5.

The workflow:
- Checks out the tag
- Runs `bin/build.sh` to produce the ZIP
- Creates a GitHub release named `vX.Y.Z` with auto-generated release notes
- Attaches `dist/intercom-woo-sync-X.Y.Z.zip` as the release asset

---

## Step 7 — Inspect the GitHub release

1. Open the repo → **Releases**.
2. Verify the ZIP is attached.
3. Download and inspect the ZIP:
   ```bash
   unzip -l intercom-woo-sync-X.Y.Z.zip
   ```
   Check that:
   - No `vendor/`, `tests/`, `.claude/`, `composer.json`, `Makefile`, `bin/` are present.
   - The inner folder is exactly `intercom-woo-sync/`.
   - `intercom-woo-sync.php`, `uninstall.php`, `inc/`, `assets/`, `templates/`, `languages/` are all present.

4. For pre-releases: confirm **Pre-release** badge is shown.
5. For stable releases: confirm **Latest release** badge is shown.

---

## Step 8 — Publish (if draft)

If the workflow was configured with `draft: true`, publish manually:

1. GitHub → Releases → find the draft.
2. Click **Edit** → **Publish release**.

If you used the local CLI path:
```bash
# Created a draft? Publish it:
gh release edit vX.Y.Z --draft=false
```

---

## Step 9 — Post-release

```bash
# Verify the tag is on origin:
git ls-remote --tags origin

# Optionally, set the tag as the default release on GitHub (for stable only):
gh release edit vX.Y.Z --latest
```

Update any external references (wordpress.org SVN, documentation, changelog).

---

## Automated path (recommended for CI)

```bash
# 1. Make sure main is clean and green
composer lint && composer test

# 2. Bump version in intercom-woo-sync.php (both header and constant)

# 3. Commit + push
git add intercom-woo-sync.php
git commit -m "Bump version to X.Y.Z"
git push origin main

# 4. Tag + push (CI does everything else)
git tag vX.Y.Z && git push origin vX.Y.Z
```

---

## Local path (when CI is unavailable)

```bash
# Requires: brew install gh && gh auth login

make release          # build ZIP + create DRAFT release
# Review at GitHub, then:
gh release edit vX.Y.Z --draft=false
```

---

## Rollback procedure

If a bad release is discovered after publishing:

1. **Do not delete the tag.** Deleting published tags breaks existing download URLs and user references.
2. Yank the release on GitHub: Releases → Edit → **Delete release** (keeps the tag).
3. Fix the issue in a new commit on main.
4. Increment the **patch** version (e.g. `1.3.0` → `1.3.1`).
5. Follow the full release process from Step 1.

---

## AI agent guidelines

When an AI agent is asked to cut a release, it **must**:

1. Read this file in full before taking any action.
2. Run the pre-flight checklist — stop and report any failure to the user.
3. Never push a tag unless `composer lint` and `composer test` both exit 0.
4. Never force-push or delete a tag that exists on `origin`.
5. Always create a **draft** release first unless the user explicitly says `--publish`.
6. Report the GitHub release URL to the user after completion.
7. Do not modify the `Version:` header or constant as part of any other commit — always use a dedicated version-bump commit.
8. Do not bundle unrelated changes into a version-bump commit.

---

## Files involved in a release

| File | Role |
|---|---|
| `intercom-woo-sync.php` | Authoritative version (header + constant) |
| `.distignore` | Controls what ships in the ZIP |
| `bin/build.sh` | Builds the ZIP |
| `bin/release.sh` | Local release via `gh` CLI |
| `Makefile` | Convenience commands |
| `.github/workflows/release.yml` | Automated CI release on tag push |
| `RELEASING.md` | This document |
