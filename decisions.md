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
