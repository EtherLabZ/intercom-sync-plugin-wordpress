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
