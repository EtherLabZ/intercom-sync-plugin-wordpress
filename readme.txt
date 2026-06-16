=== Etherlabz Intercom Sync for WooCommerce ===
Contributors: etherlabz
Tags: woocommerce, intercom, crm, abandoned cart, fin
Requires at least: 6.0
Tested up to: 6.7
Requires PHP: 7.4
Stable tag: 2.0.0
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Sync WooCommerce customers, orders, cart funnel, abandoned carts, subscriptions and purchase tags to Intercom, with a HMAC-secured Messenger embed.

== Description ==

Etherlabz Intercom Sync connects your WooCommerce store to Intercom end to end — not just the customer record, but the whole revenue funnel.

* **Server-side customer sync** — contacts are upserted to Intercom whenever a WooCommerce customer is created or updated (guest checkouts included).
* **Order events** — `placed-order`, `order-completed`, `order-refunded` and more, with line-item metadata, so Intercom Series can react to behaviour.
* **Cart & funnel events** — `product-viewed`, `cart-added`, `coupon-applied`, `checkout-started`.
* **Abandoned-cart detection** — a WP-Cron job fires a recovery event after a configurable threshold.
* **Subscription lifecycle events** — activated, renewed, cancelled, payment-failed (with WooCommerce Subscriptions).
* **Auto-tagging by purchase** — `purchased-{slug}` and `purchased-category-{slug}`, applied on order and removed on refund.
* **Custom data attributes** auto-registered in Intercom.
* **Intercom Messenger embed** with HMAC-SHA256 `user_hash` identity verification.
* **Fin AI REST connector** — Bearer-authenticated endpoints so Fin can answer "where is my order?" in real time.
* **HPOS compatible** (High-Performance Order Storage).
* **Encrypted token storage** (AES-256-CBC keyed off `AUTH_KEY` / `AUTH_SALT`).
* Filter hooks at every payload boundary for extensibility.

= Why this plugin =

Most Intercom integrations stop at "sync the customer record." This one closes the full loop: front-end events, cart recovery, post-purchase nurture, and Fin AI order lookup. No Composer or npm dependencies at runtime, no build step.

== External services ==

This plugin connects to **Intercom**, a third-party customer-messaging service, to sync your store data and (optionally) display the Intercom Messenger on your site. The plugin will not contact Intercom until you enter an Intercom Access Token in its settings.

What is sent and when:

* **Contact sync** (on customer create/update, order status change, and bulk sync): customer email, name, phone, WooCommerce customer ID, order count, lifetime value, billing city and country are sent to the Intercom REST API (`https://api.intercom.io`).
* **Events** (on order status change, cart activity, subscription changes): the customer email plus event metadata (order id, totals, line items, statuses) are sent to `https://api.intercom.io/events`.
* **Messenger embed** (only if you enable it and supply an App ID): the Intercom Messenger JavaScript is loaded from `https://widget.intercom.io/widget/<app-id>` (talking to `https://api-iam.intercom.io`) on the front end, and the logged-in user's email/name (plus an HMAC hash if a secret is configured) are passed to it.
* **Fin connector**: Intercom's Fin AI calls back into your site's REST endpoints (it does not send store data outward); requests are authenticated with a Bearer key you generate.

No data is sent to Intercom for visitors until they trigger one of the events above, and nothing is sent at all until an Access Token is configured.

Intercom terms of service: https://www.intercom.com/legal/terms-and-policies
Intercom privacy policy: https://www.intercom.com/legal/privacy

== Installation ==

1. Upload the plugin to `wp-content/plugins/intercom-woo-sync`, or install it from **Plugins → Add New → Upload Plugin**.
2. Activate **Etherlabz Intercom Sync for WooCommerce** (WooCommerce must be active).
3. Open **Intercom Sync** in the admin sidebar.
4. Paste your Intercom **Access Token** and click **Test Connection**.
5. (Optional) Add your **App ID** and **Identity Verification Secret**, then enable the Messenger and any funnel/subscription/tagging features you want.

== Frequently Asked Questions ==

= Where do I get an Intercom Access Token? =

In Intercom: Settings → Integrations → Developer Hub → your app → Authentication.

= Does it work with guest checkouts? =

Yes. Guest orders create a contact in Intercom identified by the billing email.

= Why are some phone numbers skipped? =

Intercom requires E.164 format (`+<country><number>`). The plugin normalises numbers using the billing country, but skips any number it cannot convert safely rather than sending an invalid value.

= Is WooCommerce required? =

Yes. The plugin declares WooCommerce as a required plugin and will not run without it.

= Is my Access Token stored securely? =

Yes. Tokens and secrets are encrypted at rest in `wp_options` with AES-256-CBC keyed off your site's `AUTH_KEY` / `AUTH_SALT`.

== Screenshots ==

1. Settings tab — Intercom Access Token, connection test, and sync feature toggles.

== Changelog ==

= 2.0.0 =
* Fix: contact upserts now recover from HTTP 409 conflicts (including archived contacts) by updating the existing contact in place instead of failing.
* Fix: events for an unknown email now create the contact and replay the event instead of returning HTTP 404 "User Not Found".
* Fix: phone numbers are normalised to E.164 using the billing country, eliminating most HTTP 422 "phone is invalid" errors.
* New: failed syncs are now logged with the affected customer email so you can see exactly who didn't sync.
* Admin notices now render below the brand header.

= 1.5.1 =
* Persist the active admin tab across form submits and reloads.

== Upgrade Notice ==

= 2.0.0 =
Resolves the most common sync errors (409 conflicts, 404 on events, 422 invalid phone) and logs the affected email on failure. Recommended for all users.
