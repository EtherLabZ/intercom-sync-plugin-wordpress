<div align="center">

# Etherlabz Intercom Sync for WooCommerce

**The complete Intercom integration for WooCommerce.**
Customers, orders, cart funnel, abandoned carts, subscriptions, purchase tags — and a HMAC-secured Messenger embed. All in one plugin, all open source.

[![License: GPL v2+](https://img.shields.io/badge/License-GPLv2+-blue.svg)](https://www.gnu.org/licenses/gpl-2.0)
[![PHP 7.4+](https://img.shields.io/badge/PHP-7.4+-777BB4?logo=php&logoColor=white)](https://www.php.net/)
[![WordPress 6.0+](https://img.shields.io/badge/WordPress-6.0+-21759B?logo=wordpress&logoColor=white)](https://wordpress.org/)
[![WooCommerce 7.0+](https://img.shields.io/badge/WooCommerce-7.0+-96588A?logo=woocommerce&logoColor=white)](https://woocommerce.com/)
[![Tests](https://img.shields.io/badge/tests-80%20passing-brightgreen)](#testing)
[![Built by Etherlabz](https://img.shields.io/badge/built%20by-Etherlabz-4338CA)](https://etherlabz.com)

</div>

---

## Why this plugin

Most Intercom integrations stop at "sync the customer record." That's table stakes. Where Intercom actually drives revenue is in the **front-end funnel** — browse abandonment, cart recovery, post-purchase nurture — and in **Fin AI** answering "where is my order?" without a human in the loop.

This plugin closes the entire loop, end-to-end:

- **Server-side sync** so contact records are always fresh
- **Front-end events** so Intercom Series can react to behaviour
- **Cart-abandonment cron** so recovery campaigns fire automatically
- **Purchase-based tagging** so segments build themselves
- **Fin REST endpoints** so the AI can answer order questions in real time
- **HMAC-secured Messenger embed** so identity verification is solved out of the box

No Composer dependencies at runtime. No npm. No build step. Drop it in `wp-content/plugins/` and activate.

---

## Features at a glance

| Capability | Out of the box |
|---|---|
| Customer upsert (create + update) on WooCommerce events | ✅ |
| Order-status events (`placed-order`, `order-completed`, `order-refunded`, …) with line-item metadata | ✅ |
| Guest-checkout contact creation | ✅ |
| **Bulk sync** all existing customers via WP-Cron, 25 per batch | ✅ |
| **Front-end events**: `product-viewed`, `cart-added`, `coupon-applied`, `checkout-started` | ✅ |
| **Cart abandonment** cron with configurable threshold | ✅ |
| **Subscription lifecycle** events (activated, renewed, cancelled, payment-failed) | ✅ |
| **Auto-tagging** by purchased product/category (applied on order, removed on refund) | ✅ |
| **Custom data attributes** auto-registered in Intercom | ✅ |
| **Intercom Messenger** front-end embed with HMAC `user_hash` | ✅ |
| **Fin REST connector** for order/customer lookup with Bearer auth | ✅ |
| **HPOS** (High Performance Order Storage) compatibility | ✅ |
| **Encrypted token storage** (AES-256-CBC via `AUTH_KEY`/`AUTH_SALT`) | ✅ |
| Filter hooks at every payload boundary for extensibility | ✅ |
| Translation-ready (`etherlabz-intercom-sync` text domain) | ✅ |

---

## Requirements

| | Minimum | Recommended |
|---|---|---|
| PHP | 7.4 | 8.2+ |
| WordPress | 6.0 | latest |
| WooCommerce | 7.0 | latest |
| Intercom plan | any plan that offers an Access Token | Pro or Premium for Series & Fin |

No Composer, no npm, no build step at runtime. Composer is only used for the dev toolchain (PHPCS + PHPUnit).

---

## Installation

### From a release ZIP

1. Download the latest ZIP from the [Releases page](https://github.com/EtherLabZ/intercom-sync-plugin-wordpress/releases).
2. WP Admin → **Plugins → Add New → Upload Plugin** → pick the ZIP.
3. Activate **Etherlabz Intercom Sync for WooCommerce**.
4. Open **Intercom Sync** in the admin sidebar.
5. Paste your Intercom **Access Token** and click **Test Connection**.

### From source

```bash
cd wp-content/plugins
git clone https://github.com/EtherLabZ/intercom-sync-plugin-wordpress.git etherlabz-intercom-sync
cd etherlabz-intercom-sync
composer install   # only needed for tests / lint
```

Then activate as above.

---

## Setup checklist

After activation, walk through this once:

1. **Settings tab** → paste **Access Token**, hit **Test Connection** (must show ✓).
2. *(Optional)* Paste your **Identity Verification Secret** — required for HMAC on the Messenger.
3. *(Optional)* Paste your **Intercom App ID** + flip **Embed Intercom Messenger** on if you want the chat widget on the public site.
4. Pick which capabilities to enable (all default off except guest-checkout sync):
   - **Track Cart & Funnel Events** — fires `product-viewed`, `cart-added`, `coupon-applied`, `checkout-started`
   - **Abandoned Cart Detection** — hourly cron + minutes threshold
   - **WooCommerce Subscriptions Events** — needs WC Subscriptions
   - **Auto-Tag Customers by Purchase** — `purchased-{slug}` and `purchased-category-{slug}`
5. **Fin / Data tab** → **Register Attributes in Intercom** (one click, idempotent).
6. *(For Fin)* **Generate API Key** and configure your Intercom Custom Action with the URLs shown.

---

## Where to get the credentials

| Credential | Where in Intercom |
|---|---|
| Access Token | Settings → Integrations → Developer Hub → your app → Authentication |
| App ID (workspace) | Settings → Workspace → General |
| Identity Verification Secret | Settings → Security → Identity Verification |

---

## Architecture

A small, modern, no-magic codebase:

- **Namespaced PHP** (`Etherlabz\Intercom_Woo_Sync`) with `declare(strict_types=1)` everywhere.
- **PSR-4 autoloading** via a custom 30-line loader — zero Composer runtime deps.
- **Singleton bootstrap** (`Main`) wires every module exactly once.
- **Registrable interface** — every module has one `register_hooks()` entry point.
- **WP_Error returns** from every API call so callers can distinguish 401, 422, 500 cleanly.
- **HPOS-ready** via `FeaturesUtil::declare_compatibility('custom_order_tables')`.

```
etherlabz-intercom-sync/
├── intercom-woo-sync.php           Plugin bootstrap + header
├── uninstall.php                    Clean removal of all options + crons
├── inc/
│   ├── Autoloader.php              PSR-4 autoloader (no Composer)
│   ├── Main.php                    Singleton + module registry
│   ├── Contracts/
│   │   ├── Registrable.php         interface { register_hooks(): void }
│   │   └── Singleton.php           shared singleton trait
│   ├── Core/
│   │   ├── Assets.php              Admin CSS/JS enqueue + i18n strings
│   │   └── Encryption.php          AES-256-CBC token storage
│   └── Modules/
│       ├── Intercom_API.php        Intercom v2.10 REST wrapper (WP_Error returns)
│       ├── Customer_Sync.php       Contact upsert on WC customer change
│       ├── Order_Events.php        Order events + line items + guest checkout
│       ├── Cart_Events.php         product-viewed / cart-added / checkout-started
│       ├── Cart_Abandonment.php    Hourly cron for abandoned-cart event
│       ├── Subscription_Events.php WC Subscriptions lifecycle events
│       ├── Tag_Manager.php         purchased-{slug} tagging on completion
│       ├── Messenger.php           Intercom Messenger embed + HMAC user_hash
│       ├── Bulk_Sync.php           WP-Cron batch resync
│       ├── Fin_Connector.php       REST endpoints + bearer-key auth for Fin
│       ├── Ajax_Handler.php        Admin UI AJAX endpoints
│       └── Settings/
│           ├── Admin_Screen.php    Top-level admin menu page
│           └── Settings.php        Settings API: register + sanitize + render
├── templates/admin-screen.php      Tabbed admin UI (Settings / Bulk / Fin / Log)
└── assets/{css,js}/admin.{css,js}  Scoped styles + jQuery-built UI
```

---

## Filter hooks (extensibility surface)

Every payload that crosses into Intercom is filterable. Use these to add custom attributes, redact PII, route through your own enrichment pipeline, etc.

| Hook | Filters | Args after value |
|---|---|---|
| `iws_contact_payload` | The contact payload sent on customer create/update | `WC_Customer $customer` |
| `iws_order_event_metadata` | Per-order event metadata | `WC_Order $order, string $from, string $to` |
| `iws_cart_event_metadata` | Cart-funnel event metadata | `string $event_name, string $email` |
| `iws_cart_abandoned_metadata` | Abandoned-cart event metadata | `int $user_id, array $entry` |
| `iws_subscription_event_metadata` | Subscription event metadata | `WC_Subscription $sub, string $event_name` |
| `iws_purchase_tags` | Tag list before apply/remove | `WC_Order $order` |
| `iws_messenger_settings` | `window.intercomSettings` payload | `WP_User\|null $user` |

Example — add a VIP attribute to every contact synced:

```php
add_filter( 'iws_contact_payload', function ( array $data, WC_Customer $customer ) {
    if ( (float) $customer->get_total_spent() > 1000 ) {
        $data['custom_attributes']['vip'] = true;
    }
    return $data;
}, 10, 2 );
```

Example — strip PII from order events:

```php
add_filter( 'iws_order_event_metadata', function ( array $metadata ) {
    unset( $metadata['line_items'] ); // omit per-product breakdown
    return $metadata;
} );
```

---

## Fin AI: order lookup REST endpoints

Authenticated with a Bearer key (generated on the **Fin / Data** tab):

```
GET /wp-json/iws/v1/orders?email={email}
GET /wp-json/iws/v1/orders/{id}
GET /wp-json/iws/v1/customer?email={email}

Authorization: Bearer <your-fin-api-key>
```

Configure these as a **Custom Action** in Intercom (Settings → AI Agent → Custom Actions). The setup guide is rendered in-product on the **Fin / Data** tab once you generate a key.

---

## Development

```bash
composer install     # install dev tools (PHPCS, PHPUnit, Brain\Monkey, Mockery)
composer lint        # PHPCS — WordPress + VIP + PHPCompatibility (must exit 0)
composer lint:fix    # PHPCBF auto-fixes
composer test        # PHPUnit — currently 80 tests, 125 assertions, all green
composer build       # bash bin/build.sh — produces dist/etherlabz-intercom-sync-X.Y.Z.zip
```

The Makefile wraps the same commands plus releases:

```bash
make check           # lint + test
make build           # production ZIP
make release         # build + create draft GitHub release
make release-publish # build + publish immediately
```

Releases are also automated on tag push via `.github/workflows/release.yml`. See [RELEASING.md](./RELEASING.md) for the full procedure.

---

## Testing

The unit-test suite mocks WordPress + WooCommerce via [Brain\Monkey](https://brain-wp.github.io/BrainMonkey/) and [Mockery](http://docs.mockery.io/), so it runs in isolation against plain PHP — no full WordPress test environment required.

```bash
composer test
# 80 / 80 tests, 125 assertions, ~130 ms
```

Coverage focuses on the pure-logic surfaces: encryption round-trip, settings sanitization, HMAC generation, line-item extraction, tag computation, abandonment thresholds, and the WP_Error pathways through the API wrapper.

---

## Security model

- Tokens and secrets are **encrypted at rest** in `wp_options` with AES-256-CBC keyed off `AUTH_KEY` / `AUTH_SALT`.
- All admin AJAX endpoints require `manage_options` capability **and** a `wp_create_nonce` check.
- Front-end Messenger uses **HMAC-SHA256** (`user_hash`) when an Identity Verification Secret is configured, preventing contact impersonation.
- The Fin REST connector is gated by a per-install Bearer key generated client-side and **never displayed in plaintext after the first reveal**.
- Uninstall removes every `iws_` option and clears every `iws_*_cron` schedule.

---

## License

[GPL-2.0-or-later](https://www.gnu.org/licenses/gpl-2.0.html). Use it, fork it, ship it.

---

<div align="center">

**Built with ♥ by [Etherlabz](https://etherlabz.com)**
[GitHub](https://github.com/EtherLabZ/intercom-sync-plugin-wordpress) · [Issues](https://github.com/EtherLabZ/intercom-sync-plugin-wordpress/issues) · [Releases](https://github.com/EtherLabZ/intercom-sync-plugin-wordpress/releases) · [Discord](https://discord.gg/mUzv4wbX5p)

</div>
