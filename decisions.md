# Decisions log

## 2026-05-07

### 13:40 · Admin UI · Standardise dashicons sizing on `line-height: 1` + explicit width/height

Across the admin screen, several spots used a fixed numeric `line-height` (e.g. `line-height: 28px` on action-button icons, `16px` on tab icons, `14px` on footer/lock icons) to vertically align dashicons with adjacent text. That approach silently misaligned by 1–2px on macOS Chrome and worse on Windows because the dashicons font's intrinsic ascent/descent isn't symmetric, and because the line-height value was tied to a specific button/tab height that didn't always match what WP core actually rendered.

**New rule for the codebase:** every dashicons span inside `.iws-wrap` gets `font-size: <px>; width: <px>; height: <px>; line-height: 1;`, and the surrounding container uses `display: inline-flex; align-items: center; gap: <px>;` to do the centering. The two responsibilities — sizing the glyph box vs. centering it against text — stay separate.

Why: relying on `line-height` for vertical centering is fragile because it couples the icon's intrinsic font metrics to the parent's chrome height. Flex `align-items: center` is metric-agnostic and works regardless of whether the surrounding text is 11.5px (badges), 13px (buttons), or 22px (header h1).

Also bumped `.iws-actions .button` to `display: inline-flex` for the same reason — the previous code applied `line-height: 28px` to the icon to match WP core's default button height, but that height changes whenever the admin font scale changes.

### 16:44 · Admin UI · Scope the inline-flex button rule to `.iws-wrap`, not `.iws-actions`

The 13:40 fix above scoped the inline-flex button treatment to `.iws-actions .button` — but several buttons live outside that container: "Add Rule" and "Clear Log" sit in `.iws-card__header`, and "Copy" sits in `.iws-key-reveal`. Those kept misaligning in rc2 because they fell back to WP core's block-layout button rendering where the dashicons span (default font-size 20px, line-height 1) drifts above the smaller text label.

Generalised the selector to `.iws-wrap .button` (and `.iws-wrap .button .dashicons`). Inline-flex on a button without flex children is a no-op, so the broader scope is safe — including for the `<input type="submit">` Save Settings button (inputs ignore inline-flex layout because they have no rendered children).

Convention sharpened: **every button inside `.iws-wrap` gets inline-flex centering**, not just buttons in action rows. Anywhere we put a dashicon next to text, this is the contract.

### 22:27 · Admin UI · Persist active tab via URL hash + localStorage

The active tab was lost whenever the user (a) saved a settings form — the form posts to `options.php` and redirects back to the admin URL, which drops any URL fragment — or (b) reloaded the page. Both cases bounced the user back to the default "Settings" tab, which is jarring if you were watching the Live Stream or editing Segments.

Decided on a two-channel persistence model in `assets/js/admin.js`:

1. **URL hash** (`#tab-log`): updated via `history.replaceState` on click. Survives reloads, makes tabs deep-linkable / shareable, no scroll jump.
2. **localStorage** (`iws_active_tab`): updated alongside the hash. Recovers state across the form-submit redirect, since hashes don't survive server-side redirects.

On load: hash wins (so deep links work), localStorage is the fallback, otherwise the server-rendered default ("settings") stays active. `activateTab()` is defensive — unknown slugs (e.g. stale storage from a renamed tab) are ignored and we fall through to the default.

Why both channels rather than just localStorage: localStorage alone breaks shareability ("here's the link to the Segments tab" wouldn't open on that tab). And hash alone breaks form submits. Belt-and-braces for the cost of ~30 lines.

## 2026-05-08

### 05:02 · Admin UI · Add `wp-header-end` marker so admin_notices land below our brand header

WordPress's admin chrome runs JS on every admin page that hoists any `.notice` element to a canonical location: immediately before the first element with class `wp-header-end`, or — if no marker exists — immediately after the first `<h1>` it finds. Our `<h1>` lives inside `.iws-header` (the brand gradient block), with the version pill and description rendered after it. Without a marker, third-party `admin_notices` (e.g. WooCommerce update notices, plugin-update prompts) were getting injected between our title and the version pill, splitting the brand header in half.

Decided to render a literal `<hr class="wp-header-end" />` immediately after the brand header div, hidden via CSS (visibility:hidden + height:0 + zeroed margin/border, *not* display:none — some WP versions skip display:none nodes when scanning for the marker).

Why a real `<hr>` rather than slapping the class on the `<h1>` directly: the brand header treats the `<h1>` as a flex child alongside the icon and version pill. Adding `wp-header-end` to it would turn our visual title into the literal placement target, but it would also pull notices into the gradient block (above the description). A separate sibling `<hr>` lets the placement target sit *outside* the brand chrome, so notices land in the natural admin spot below it.

Convention to remember: any custom admin screen with a custom-styled header **must** include a `wp-header-end` marker after the chrome ends. Otherwise WP's notice-relocation will fight the design.

## 2026-06-17

### 02:50 · Intercom API · Centralise sync-error recovery in Intercom_API rather than per-caller

Three recurring sync-log errors traced to one wrapper. Fixed all in `Intercom_API` so every module benefits, instead of patching Customer_Sync / Order_Events / Cart / Subscription separately.

- **409 conflict (incl. archived):** `upsert_contact` searched by email first, but Intercom search excludes archived contacts and lags its index, so POST /contacts still 409'd. Now on a 409 we parse the `id=<24hex>` out of the error message, unarchive if the message says "archived", and PUT-update in place. Why parse the message rather than re-search: the id is handed to us in the error and re-search would hit the same stale index.
- **404 "User Not Found" on /events:** events fired for an email with no contact yet (Order_Events fired the event *before* upserting). Fixed centrally in `create_event`: on 404 we upsert a minimal `{role:user,email}` contact and replay the event once. Chose central self-heal over reordering each caller so Cart/Subscription events get it too.
- **422 "phone is invalid":** old code stripped non-digits and prepended `+`, turning national numbers into invalid E.164. New `Intercom_API::format_phone($phone,$country)` treats `+`/`00` as explicit country code, else derives the calling code from the billing country (small ISO→code map), drops a trunk leading 0, and returns '' (skip the field) when it can't be sure. Why skip rather than best-effort: a skipped phone is a non-event; an invalid one is a logged 422 on every sync.

### 02:55 · Distribution · Add wp.org readme.txt with External Services disclosure

Plugin had only README.md (GitHub). Added `readme.txt` in wp.org format. Security audit of escaping/sanitization/nonce/caps came back clean — the only real blocker was the missing readme + an **External Services** section: wp.org requires disclosing that data is sent to Intercom (api.intercom.io, widget.intercom.io, api-iam.intercom.io), what data, when, and links to Intercom ToS/privacy. Stable tag set to 1.6.0; header is still 1.6.0-rc1 — must be reconciled (header → 1.6.0) before actual wp.org submission.

### 03:05 · Versioning · Bump to 2.0.0 and align header/readme/changelog

Reconciled the earlier header(1.6.0-rc1)/readme(1.6.0) split by going straight to 2.0.0 per request. The 409/404/422 recovery rework + the new failure-logging behaviour are a meaningful behavioural shift in how syncs resolve, so major-version is defensible. Stable tag, header Version, INTERCOM_WOO_SYNC_VERSION constant, changelog and upgrade notice all now read 2.0.0.

### 03:06 · Logging · Name the affected email on a complete sync failure

Raw HTTP errors were logged against the endpoint (e.g. "/contacts HTTP 422") with no way to tell *which* customer failed. Added a private `Intercom_API::flag_failure()` that, when the final result (after 409/404 recovery) is still a WP_Error, logs a second line "Failed for <email>: <msg>" under a friendly action label ("Contact sync" / "Event: <name>"). Falls back to "(unknown email)" when none is known. Why a separate line rather than enriching request()'s log: request() has no email in scope and is also used for token-less calls (/me, /tags); keeping the who-failed concern at the upsert/event layer keeps it accurate.

### 03:07 · Distribution · wordpress.org screenshot via wporg-assets/, excluded from zip

wp.org reads screenshots from the SVN root `assets/` dir, not the plugin zip. Added `wporg-assets/` (screenshot-1.svg source + rendered screenshot-1.png, generated with rsvg-convert) and excluded it via .distignore so it never ships in the plugin. readme `== Screenshots ==` lists exactly the one file that exists to avoid Plugin Check "missing screenshot" warnings. screenshot-1.png is a representative admin-UI mockup — README in wporg-assets/ notes it can be swapped for a real capture and lists the icon/banner assets still to add.

### 03:20 · Compliance · Rename text-domain + slug to etherlabz-intercom-sync (trademark)

wp.org rejects plugin slugs/names that **begin** with another company's trademark. Old slug/text-domain `intercom-woo-sync` starts with "intercom" (Intercom Inc.) → would be rejected. Renamed text-domain to `etherlabz-intercom-sync` (brand-led, Etherlabz owns it; "Intercom" mid-string and "for WooCommerce" suffix are allowed). Text-domain MUST equal the wp.org SVN slug, so the zip inner folder + PLUGIN_SLUG in bin/build.sh + bin/release.sh also became `etherlabz-intercom-sync`. Updated phpcs.xml.dist `text_domain` to match.

Deliberately NOT renamed: the main file `intercom-woo-sync.php` (filename is irrelevant to i18n auto-loading since WP 4.6, and renaming risks plugin-basename churn) and `Admin_Screen::SCREEN_ID` / asset handle PREFIX / `toplevel_page_` hook check (internal admin-URL + handle identifiers, not a compliance concern — changing them would move the admin page URL for no benefit).

License: added canonical GPLv2 as `license.txt` (curl'd from gnu.org) to match the existing `GPL-2.0-or-later` header. Chose GPLv2 over MIT to stay consistent with the header and WP norm. Version kept at 2.0.0.

## 2026-08-14

### 06:20 · Security · Encryption format v2: AES-256-GCM with random IV (enc2::)

The old format (enc::) was AES-256-CBC with a static IV derived from AUTH_SALT — deterministic ciphertexts, no integrity check, and a hardcoded fallback key when AUTH_KEY was undefined. New format: AES-256-GCM, 12-byte random IV + 16-byte tag prepended to the ciphertext, `enc2::` prefix. Legacy `enc::` values stay decryptable (read-only path retains the old derivation, including the old fallback strings, verbatim) and migrate to v2 on the next settings save. When AUTH_KEY is missing we now refuse to encrypt (plaintext passthrough, same as the no-OpenSSL path) instead of pretending with a publicly known key. Chose GCM over CBC+HMAC: one primitive, authenticated by construction, universally available in OpenSSL.

### 06:22 · Security · Secrets are write-only in the admin UI; blank submit keeps stored value

The settings screen used to echo the decrypted access token and HMAC secret into `value=""` on every load. Fields now always render empty with a masked placeholder; sanitizers (`sanitize_access_token` / `sanitize_hmac_secret`) treat an empty submission as "keep what's stored" (re-encrypting it, which migrates legacy formats forward). Trade-off: you can't clear a secret from the UI, only replace it — accepted, since clearing is rare and the alternative (echoing secrets into page source) is a real leak vector.

### 06:24 · API surface · Fin write endpoints require email ownership of the order

`POST /orders/{id}/cancel` and `/refund` acted on any order ID with only the shared Bearer key — any chat user who got Fin to pass someone else's order ID could cancel/refund it. Both now resolve the caller's email (X-Intercom-Verified-Email preferred, X-Email fallback — same ladder as the read endpoints) and require it to match the order's billing email, returning 404 on mismatch so existence isn't leaked. This is a breaking change for Fin action configs that didn't forward an email header. /customer/note now also prefers the verified header.

### 06:26 · Compatibility · Minimum PHP raised to 8.0 (was 7.4)

Fin_Connector has used PHP 8.0 union return types (string|WP_Error etc.) and str_starts_with since 1.5 — the 7.4 header claim was already false and would fatal on 7.4. Rather than rewriting working signatures, the floor is now honest: `Requires PHP: 8.0` in header + readme. The one PHP 8.2-only construct (`true|WP_Error` standalone true type) was downgraded to `bool|WP_Error` so 8.0/8.1 hosts still work. Also bumped `Tested up to: 7.0` (current WP stable as of Aug 2026).

### 06:40 · Design · Admin UI aligned to etherlabz.com (rust accent, dark CTA, pill tabs, system serif)

Sampled the live site: paper #F9F5F3, ink #1D1E22/#080605, working accent rust #B8451A (the dominant text/interactive accent), bright orange #EF7240 and peach #FDC9A4 as highlights, 10px button radii, display serif + grotesk pairing. Admin changes: primary buttons now use the site's dark CTA (black → rust on hover), tabs became a segmented pill nav, cards got 1rem radii + soft warm shadows, headings use a system display-serif stack (Iowan Old Style/Palatino/Georgia) because wp.org forbids shipping/loading external webfonts, and interactive accents on light backgrounds switched from orange (#EF7240, ~2.8:1 on white — fails contrast) to rust (#B8451A, ~4.9:1). Orange/peach remain only on the dark header and gradient edge where contrast holds.

### 07:35 · Compliance · Rename global prefix iws_ → etherlabz_intercom_ (v2.1.0)

Supersedes the earlier decision (see phpcs.xml.dist rationale and the 2026-05 slug entry) to keep the short `iws` prefix. User approved breaking the old surface to get fully wp.org-compliant. Everything global moved: options, cron hooks, public filters, AJAX actions, nonce, transients, session key, settings group/section, `iws/v1` → `etherlabz-intercom/v1` REST namespace, `iwsAdmin` → `etherlabzIntercomAdmin` JS global, `INTERCOM_WOO_SYNC_*` → `ETHERLABZ_INTERCOM_*` constants. A one-time `plugins_loaded` migration (guarded by the legacy `iws_version` option) copies options to the new names, drops the old ones, and reschedules crons — verified live against a seeded legacy install in Docker. Deliberately NOT renamed: `.iws-*` CSS classes and DOM classes (not PHP globals, scoped to our admin page — renaming is pure churn), the `intercom-woo-sync` admin page slug (URL stability), and the main plugin filename (basename churn). Version bumped to 2.1.0 since 2.0.0 shipped in June.

## 2026-08-17

### 04:30 · Naming · Display name shortened to "Etherlabz Intercom Sync" (slug derivation)

wordpress.org's submission preflight derives the plugin slug — and therefore the expected text domain — from the Plugin Name. "Etherlabz Intercom Sync for WooCommerce" derives `etherlabz-intercom-sync-for-woocommerce`, mismatching our text domain `etherlabz-intercom-sync` and hard-failing Plugin Check's TextDomainMismatch. Two options: rename the text domain across ~190 gettext calls (and put "woocommerce" into the slug), or shorten the display name so the derived slug equals the existing domain. Chose the name change: zero i18n churn, the slug/text-domain pair stays `etherlabz-intercom-sync` (standing rule), and the name now contains no third-party trademarks at all — the safest possible form under the trademark guidelines. "for WooCommerce" remains in the short description, long description, and tags, which is what directory search actually weighs. Supersedes the naming half of the 2026-05 "Rename text-domain + slug" entry; the slug half stands.
