# Intercom WooCommerce Sync

Syncs WooCommerce customers and order events to Intercom in real time.

## Features

- **Customer Sync** — Automatically upserts WooCommerce customers to Intercom contacts on create/update.
- **Order Events** — Fires Intercom events on every WooCommerce order-status change (`placed-order`, `order-processing`, `order-completed`, `order-cancelled`, `order-refunded`, `order-shipped`, etc.).
- **Bulk Sync** — Background batch sync of all existing customers via WP-Cron (25 per batch, won't timeout).
- **Admin UI** — Tabbed settings page with connection test, bulk-sync controls, and a live sync log.
- **Filter Hooks** — `iws_contact_payload` and `iws_order_event_metadata` let you customise data before it hits Intercom.

## Requirements

| Requirement | Version |
| ----------- | ------- |
| PHP         | 7.4+    |
| WordPress   | 6.0+    |
| WooCommerce | 7.0+    |

No Composer, no npm, no build step — pure WordPress.

## Installation

1. Download or clone this repository.
2. Copy the `intercom-woo-sync/` folder into `wp-content/plugins/`.
3. Activate **Intercom WooCommerce Sync** from WP Admin → Plugins.
4. Navigate to **Intercom Sync** in the admin sidebar.
5. Enter your Intercom Access Token and click **Test Connection**.

## Getting an Intercom Access Token

1. Log into [Intercom](https://app.intercom.com).
2. Go to **Settings → Developers → Developer Hub**.
3. Create or select your app.
4. Copy the **Access Token** (Bearer token).

## File Structure

```
intercom-woo-sync/
├── intercom-woo-sync.php            # Main plugin bootstrap
├── uninstall.php                     # Cleanup on delete
├── README.md
├── inc/
│   ├── Autoloader.php               # PSR-4 autoloader (no Composer)
│   ├── Main.php                     # Singleton bootstrap
│   ├── Contracts/
│   │   ├── Registrable.php          # Registrable interface
│   │   └── Singleton.php            # Singleton trait
│   ├── Core/
│   │   └── Assets.php               # Admin CSS/JS enqueue
│   └── Modules/
│       ├── Intercom_API.php          # Intercom REST API wrapper
│       ├── Customer_Sync.php         # Contact upsert on customer change
│       ├── Order_Events.php          # Order-status event tracking
│       ├── Bulk_Sync.php             # WP-Cron batch re-sync
│       ├── Ajax_Handler.php          # AJAX endpoints for admin UI
│       └── Settings/
│           ├── Admin_Screen.php      # Menu page registration
│           └── Settings.php          # Settings API registration
├── templates/
│   └── admin-screen.php             # Admin UI template
└── assets/
    ├── css/
    │   └── admin.css                # Admin styles
    └── js/
        └── admin.js                 # Admin JavaScript
```

## Architecture

Built on the [rtCamp plugin-skeleton-d](https://github.com/rtCamp/plugin-skeleton-d) patterns:

- **Namespaced PHP** (`Etherlabz\Intercom_Woo_Sync`) with `declare(strict_types=1)`.
- **PSR-4 autoloading** via a lightweight custom autoloader — no Composer required.
- **Singleton Main** class bootstraps all modules.
- **Registrable interface** — every module implements `register_hooks()`.
- **Modular structure** — API, sync, events, settings each in their own class.

## Filter Hooks

### `iws_contact_payload`

Filter the contact data array before it is sent to Intercom.

```php
add_filter( 'iws_contact_payload', function( array $data, \WC_Customer $customer ) {
    $data['custom_attributes']['vip'] = true;
    return $data;
}, 10, 2 );
```

### `iws_order_event_metadata`

Filter the event metadata before it is sent to Intercom.

```php
add_filter( 'iws_order_event_metadata', function( array $metadata, \WC_Order $order, string $from, string $to ) {
    $metadata['shipping_method'] = $order->get_shipping_method();
    return $metadata;
}, 10, 4 );
```

## License

GPL-2.0-or-later
