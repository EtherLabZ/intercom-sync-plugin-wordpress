# Decisions log

## 2026-05-07

### 13:40 · Admin UI · Standardise dashicons sizing on `line-height: 1` + explicit width/height

Across the admin screen, several spots used a fixed numeric `line-height` (e.g. `line-height: 28px` on action-button icons, `16px` on tab icons, `14px` on footer/lock icons) to vertically align dashicons with adjacent text. That approach silently misaligned by 1–2px on macOS Chrome and worse on Windows because the dashicons font's intrinsic ascent/descent isn't symmetric, and because the line-height value was tied to a specific button/tab height that didn't always match what WP core actually rendered.

**New rule for the codebase:** every dashicons span inside `.iws-wrap` gets `font-size: <px>; width: <px>; height: <px>; line-height: 1;`, and the surrounding container uses `display: inline-flex; align-items: center; gap: <px>;` to do the centering. The two responsibilities — sizing the glyph box vs. centering it against text — stay separate.

Why: relying on `line-height` for vertical centering is fragile because it couples the icon's intrinsic font metrics to the parent's chrome height. Flex `align-items: center` is metric-agnostic and works regardless of whether the surrounding text is 11.5px (badges), 13px (buttons), or 22px (header h1).

Also bumped `.iws-actions .button` to `display: inline-flex` for the same reason — the previous code applied `line-height: 28px` to the icon to match WP core's default button height, but that height changes whenever the admin font scale changes.
