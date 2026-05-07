<?php
/**
 * Admin settings screen template.
 *
 * @package Etherlabz\Intercom_Woo_Sync
 */

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

use Etherlabz\Intercom_Woo_Sync\Modules\Settings\Settings;
use Etherlabz\Intercom_Woo_Sync\Modules\Settings\Admin_Screen;
use Etherlabz\Intercom_Woo_Sync\Modules\Bulk_Sync;
use Etherlabz\Intercom_Woo_Sync\Core\Encryption;

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound
// Reason: these are file-scope locals in an included template, not true globals.
// The iws_ prefix is used but WPCS rejects it as too short to count as valid.
$iws_log = get_option( 'iws_sync_log', array() );
if ( ! is_array( $iws_log ) ) {
	$iws_log = array();
}

$iws_bulk_running   = Bulk_Sync::is_running();
$iws_has_token      = '' !== get_option( 'iws_access_token', '' );
$iws_fin_key_raw    = (string) get_option( 'iws_fin_api_key', '' );
$iws_has_fin_key    = '' !== $iws_fin_key_raw;
$iws_fin_key_masked = '';
if ( $iws_has_fin_key ) {
	$iws_plain          = Encryption::decrypt( $iws_fin_key_raw );
	$iws_fin_key_masked = substr( $iws_plain, 0, 8 ) . str_repeat( '*', max( 0, strlen( $iws_plain ) - 8 ) );
}
// phpcs:enable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound
?>

<div class="wrap iws-wrap">

	<!-- Header -->
	<div class="iws-header">
		<div class="iws-header__title">
			<span class="dashicons dashicons-share iws-header__icon"></span>
			<h1><?php esc_html_e( 'Etherlabz Intercom Sync', 'intercom-woo-sync' ); ?></h1>
			<span class="iws-header__version">v<?php echo esc_html( INTERCOM_WOO_SYNC_VERSION ); ?></span>
		</div>
		<p class="iws-header__desc">
			<?php esc_html_e( 'The complete Intercom integration for WooCommerce — customers, order events, cart funnel, abandoned carts, subscriptions and tags.', 'intercom-woo-sync' ); ?>
		</p>
	</div>

	<?php
	// WordPress relocates admin_notices to immediately before the first
	// element matching .wp-header-end (or the first <h1> in the page if no
	// marker is found). Our <h1> is nested inside the brand header, so
	// without this marker third-party notices slot themselves between the
	// title and the version pill / description, which looks broken.
	// The <hr> is hidden via CSS — its only job is to be the placement target.
	?>
	<hr class="wp-header-end" />

	<!-- Notices (our own AJAX-driven container — separate from WP admin_notices) -->
	<div id="iws-notices"></div>

	<!-- Tab navigation -->
	<nav class="iws-tabs" role="tablist">
		<button class="iws-tabs__tab iws-tabs__tab--active" data-tab="settings" role="tab" aria-selected="true">
			<span class="dashicons dashicons-admin-generic"></span>
			<?php esc_html_e( 'Settings', 'intercom-woo-sync' ); ?>
		</button>
		<button class="iws-tabs__tab" data-tab="sync" role="tab" aria-selected="false">
			<span class="dashicons dashicons-update"></span>
			<?php esc_html_e( 'Bulk Sync', 'intercom-woo-sync' ); ?>
		</button>
		<button class="iws-tabs__tab" data-tab="fin" role="tab" aria-selected="false">
			<span class="dashicons dashicons-superhero-alt"></span>
			<?php esc_html_e( 'Fin / Data', 'intercom-woo-sync' ); ?>
		</button>
		<button class="iws-tabs__tab" data-tab="segments" role="tab" aria-selected="false">
			<span class="dashicons dashicons-tag"></span>
			<?php esc_html_e( 'Segments', 'intercom-woo-sync' ); ?>
		</button>
		<button class="iws-tabs__tab" data-tab="log" role="tab" aria-selected="false">
			<span class="dashicons dashicons-list-view"></span>
			<?php esc_html_e( 'Live Stream', 'intercom-woo-sync' ); ?>
		</button>
	</nav>

	<!-- Tab: Settings -->
	<div class="iws-tab-panel iws-tab-panel--active" id="iws-panel-settings" role="tabpanel">
		<form method="post" action="options.php">
			<?php
			settings_fields( Settings::GROUP );
			do_settings_sections( Admin_Screen::SCREEN_ID );
			?>

			<div class="iws-actions">
				<?php submit_button( __( 'Save Settings', 'intercom-woo-sync' ), 'primary', 'submit', false ); ?>
				<button type="button" id="iws-test-connection" class="button button-secondary" <?php disabled( ! $iws_has_token ); ?>>
					<span class="dashicons dashicons-yes-alt"></span>
					<?php esc_html_e( 'Test Connection', 'intercom-woo-sync' ); ?>
				</button>
				<span id="iws-test-spinner" class="spinner"></span>
				<span id="iws-test-result" class="iws-inline-result"></span>
			</div>
		</form>
	</div>

	<!-- Tab: Bulk Sync -->
	<div class="iws-tab-panel" id="iws-panel-sync" role="tabpanel">
		<div class="iws-card">
			<h2><?php esc_html_e( 'Bulk Customer Sync', 'intercom-woo-sync' ); ?></h2>
			<p>
				<?php esc_html_e( 'Push all existing WooCommerce customers to Intercom. This runs in background batches of 25 via WP-Cron so it won\'t timeout or slow down your site.', 'intercom-woo-sync' ); ?>
			</p>

			<div class="iws-bulk-status" id="iws-bulk-status">
				<?php if ( $iws_bulk_running ) : ?>
					<span class="iws-badge iws-badge--running">
						<span class="dashicons dashicons-update iws-spin"></span>
						<?php esc_html_e( 'Running…', 'intercom-woo-sync' ); ?>
					</span>
					<span class="iws-bulk-offset">
						<?php
						printf(
							/* translators: %d: number of customers processed so far. */
							esc_html__( '%d customers processed so far', 'intercom-woo-sync' ),
							(int) get_option( 'iws_bulk_sync_offset', 0 )
						);
						?>
					</span>
				<?php else : ?>
					<span class="iws-badge iws-badge--idle">
						<?php esc_html_e( 'Idle', 'intercom-woo-sync' ); ?>
					</span>
				<?php endif; ?>
			</div>

			<div class="iws-actions iws-mt-16">
				<button type="button" id="iws-start-bulk-sync" class="button button-primary" <?php disabled( $iws_bulk_running || ! $iws_has_token ); ?>>
					<span class="dashicons dashicons-update"></span>
					<?php esc_html_e( 'Start Bulk Sync', 'intercom-woo-sync' ); ?>
				</button>
				<span id="iws-bulk-spinner" class="spinner"></span>
				<span id="iws-bulk-result" class="iws-inline-result"></span>
			</div>
		</div>
	</div>

	<!-- Tab: Fin / Data -->
	<div class="iws-tab-panel" id="iws-panel-fin" role="tabpanel">

		<!-- Register Data Attributes -->
		<div class="iws-card iws-mb-20">
			<div class="iws-card__header">
				<h2><?php esc_html_e( 'Custom Data Attributes', 'intercom-woo-sync' ); ?></h2>
			</div>
			<p>
				<?php esc_html_e( 'Register the custom contact attributes that this plugin uses in Intercom. Run this once before your first bulk sync.', 'intercom-woo-sync' ); ?>
			</p>
			<div class="iws-attr-list">
				<code>woo_customer_id</code>
				<code>total_orders</code>
				<code>lifetime_value</code>
				<code>billing_city</code>
				<code>billing_country</code>
				<code>last_order_status</code>
				<code>last_order_id</code>
				<code>last_order_date</code>
			</div>
			<div class="iws-actions iws-mt-16">
				<button type="button" id="iws-register-attrs" class="button button-primary" <?php disabled( ! $iws_has_token ); ?>>
					<span class="dashicons dashicons-database-add"></span>
					<?php esc_html_e( 'Register Attributes in Intercom', 'intercom-woo-sync' ); ?>
				</button>
				<span id="iws-attrs-spinner" class="spinner"></span>
				<span id="iws-attrs-result" class="iws-inline-result"></span>
			</div>
		</div>

		<!-- Fin Data Connector -->
		<div class="iws-card iws-mb-20">
			<div class="iws-card__header">
				<h2><?php esc_html_e( 'Fin Data Connector (Order Lookup)', 'intercom-woo-sync' ); ?></h2>
			</div>
			<p>
				<?php esc_html_e( 'These REST API endpoints let Intercom Fin look up WooCommerce orders in real time. Configure them as a Custom Action in Intercom so Fin can answer "Where is my order?" questions.', 'intercom-woo-sync' ); ?>
			</p>

			<table class="iws-endpoint-table">
				<tr>
					<td class="iws-endpoint-label"><?php esc_html_e( 'Orders by email', 'intercom-woo-sync' ); ?></td>
					<td><code>GET <?php echo esc_html( rest_url( 'iws/v1/orders?email={email}' ) ); ?></code></td>
				</tr>
				<tr>
					<td class="iws-endpoint-label"><?php esc_html_e( 'Order by ID', 'intercom-woo-sync' ); ?></td>
					<td><code>GET <?php echo esc_html( rest_url( 'iws/v1/orders/{id}' ) ); ?></code></td>
				</tr>
				<tr>
					<td class="iws-endpoint-label"><?php esc_html_e( 'Customer by email', 'intercom-woo-sync' ); ?></td>
					<td><code>GET <?php echo esc_html( rest_url( 'iws/v1/customer?email={email}' ) ); ?></code></td>
				</tr>
				<tr>
					<td class="iws-endpoint-label"><?php esc_html_e( 'Auth header', 'intercom-woo-sync' ); ?></td>
					<td><code>Authorization: Bearer &lt;your-fin-api-key&gt;</code></td>
				</tr>
			</table>

			<h3 class="iws-mt-20"><?php esc_html_e( 'API Key', 'intercom-woo-sync' ); ?></h3>
			<?php if ( $iws_has_fin_key ) : ?>
				<div class="iws-key-display">
					<code id="iws-fin-key-value"><?php echo esc_html( $iws_fin_key_masked ); ?></code>
					<span class="iws-badge iws-badge--success"><?php esc_html_e( 'Active', 'intercom-woo-sync' ); ?></span>
				</div>
			<?php else : ?>
				<p class="iws-hint"><?php esc_html_e( 'No API key generated yet. Generate one to authenticate Fin requests.', 'intercom-woo-sync' ); ?></p>
			<?php endif; ?>

			<div class="iws-actions iws-mt-12">
				<button type="button" id="iws-generate-fin-key" class="button <?php echo $iws_has_fin_key ? 'button-secondary' : 'button-primary'; ?>">
					<span class="dashicons dashicons-admin-network"></span>
					<?php
					echo $iws_has_fin_key
						? esc_html__( 'Regenerate API Key', 'intercom-woo-sync' )
						: esc_html__( 'Generate API Key', 'intercom-woo-sync' );
					?>
				</button>
				<span id="iws-finkey-spinner" class="spinner"></span>
				<span id="iws-finkey-result" class="iws-inline-result"></span>
			</div>

			<!-- Shown after generation -->
			<div id="iws-fin-key-reveal" class="iws-key-reveal iws-hidden iws-mt-16">
				<p class="iws-key-reveal__warn">
					<span class="dashicons dashicons-warning"></span>
					<?php esc_html_e( 'Copy this key now. It will not be shown in full again.', 'intercom-woo-sync' ); ?>
				</p>
				<input type="text" id="iws-fin-key-full" class="regular-text" readonly />
				<button type="button" id="iws-copy-fin-key" class="button button-small">
					<span class="dashicons dashicons-clipboard"></span> <?php esc_html_e( 'Copy', 'intercom-woo-sync' ); ?>
				</button>
			</div>
		</div>

		<!-- Fin Action Endpoints (write actions — default off) -->
		<div class="iws-card iws-fin-actions iws-mb-20">
			<div class="iws-card__header">
				<h2><?php esc_html_e( 'Fin Write Actions (Advanced)', 'intercom-woo-sync' ); ?></h2>
			</div>
			<p>
				<?php esc_html_e( 'These endpoints let Fin take action on orders — not just look them up. They are powerful and irreversible. All toggles are off by default; enable only the actions you trust Fin to perform.', 'intercom-woo-sync' ); ?>
			</p>

			<form method="post" action="options.php">
				<?php settings_fields( Settings::GROUP ); ?>

				<div class="iws-fin-action-row">
					<div class="iws-fin-action-row__meta">
						<div class="iws-fin-action-row__name">POST /iws/v1/orders/{id}/cancel</div>
						<div class="iws-fin-action-row__desc">
							<?php esc_html_e( 'Allow Fin to cancel a customer order. Only orders in non-terminal states can be cancelled.', 'intercom-woo-sync' ); ?>
						</div>
						<span class="iws-fin-action-row__danger"><?php esc_html_e( 'Dangerous', 'intercom-woo-sync' ); ?></span>
					</div>
					<label class="iws-toggle">
						<input type="checkbox" name="iws_fin_action_cancel_enabled" value="yes"
							class="iws-fin-action-toggle" data-action="cancel"
							<?php checked( get_option( 'iws_fin_action_cancel_enabled', 'no' ), 'yes' ); ?> />
						<span class="iws-toggle__slider"></span>
					</label>
				</div>

				<div class="iws-fin-action-row">
					<div class="iws-fin-action-row__meta">
						<div class="iws-fin-action-row__name">POST /iws/v1/orders/{id}/refund</div>
						<div class="iws-fin-action-row__desc">
							<?php esc_html_e( 'Allow Fin to issue a full or partial refund. Amount and reason are accepted as POST params.', 'intercom-woo-sync' ); ?>
						</div>
						<span class="iws-fin-action-row__danger"><?php esc_html_e( 'Dangerous', 'intercom-woo-sync' ); ?></span>
					</div>
					<label class="iws-toggle">
						<input type="checkbox" name="iws_fin_action_refund_enabled" value="yes"
							class="iws-fin-action-toggle" data-action="refund"
							<?php checked( get_option( 'iws_fin_action_refund_enabled', 'no' ), 'yes' ); ?> />
						<span class="iws-toggle__slider"></span>
					</label>
				</div>

				<div class="iws-fin-action-row">
					<div class="iws-fin-action-row__meta">
						<div class="iws-fin-action-row__name">POST /iws/v1/customer/note</div>
						<div class="iws-fin-action-row__desc">
							<?php esc_html_e( 'Allow Fin to attach a private note to the customer\'s most recent order. Useful for handover from chat to human support.', 'intercom-woo-sync' ); ?>
						</div>
					</div>
					<label class="iws-toggle">
						<input type="checkbox" name="iws_fin_action_note_enabled" value="yes"
							class="iws-fin-action-toggle" data-action="note"
							<?php checked( get_option( 'iws_fin_action_note_enabled', 'no' ), 'yes' ); ?> />
						<span class="iws-toggle__slider"></span>
					</label>
				</div>

				<div class="iws-actions iws-mt-16">
					<?php submit_button( __( 'Save Fin Action Settings', 'intercom-woo-sync' ), 'primary', 'submit', false ); ?>
				</div>
			</form>
		</div>

		<!-- Intercom Custom Action Setup Guide -->
		<div class="iws-card">
			<div class="iws-card__header">
				<h2><?php esc_html_e( 'Setup Guide: Intercom Custom Action for Fin', 'intercom-woo-sync' ); ?></h2>
			</div>
			<ol class="iws-setup-steps">
				<li><?php esc_html_e( 'Click "Register Attributes" above (run once).', 'intercom-woo-sync' ); ?></li>
				<li><?php esc_html_e( 'Generate an API key above and copy it.', 'intercom-woo-sync' ); ?></li>
				<li>
				<?php
					/* translators: %s: Intercom navigation path rendered as HTML (Settings → AI Agent → Custom Actions). */
					printf( esc_html__( 'In Intercom, go to %s.', 'intercom-woo-sync' ), '<strong>Settings &rarr; AI Agent &rarr; Custom Actions</strong>' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
				?>
				</li>
				<li><?php esc_html_e( 'Create a new Custom Action with these settings:', 'intercom-woo-sync' ); ?>
					<ul class="iws-setup-steps__sub">
						<li><strong>URL:</strong> <code><?php echo esc_html( rest_url( 'iws/v1/orders' ) ); ?></code></li>
						<li><strong>Method:</strong> GET</li>
						<li><strong>Header:</strong> <code>Authorization: Bearer YOUR_KEY</code></li>
						<li><strong>Query param:</strong> <code>email</code> = the customer's email (use Intercom variable)</li>
					</ul>
				</li>
				<li><?php esc_html_e( 'Save and enable the action. Fin will now be able to look up orders.', 'intercom-woo-sync' ); ?></li>
				<li><?php esc_html_e( 'Paste your Identity Verification Secret in the Settings tab to enable HMAC on your frontend chat widget.', 'intercom-woo-sync' ); ?></li>
			</ol>
		</div>

	</div>

	<!-- Tab: Segments -->
	<div class="iws-tab-panel" id="iws-panel-segments" role="tabpanel">
		<div class="iws-card iws-mb-20">
			<div class="iws-card__header">
				<h2><?php esc_html_e( 'Smart Segment Rules', 'intercom-woo-sync' ); ?></h2>
				<button type="button" id="iws-add-rule" class="button button-secondary">
					<span class="dashicons dashicons-plus-alt2"></span>
					<?php esc_html_e( 'Add Rule', 'intercom-woo-sync' ); ?>
				</button>
			</div>
			<p>
				<?php esc_html_e( 'Define rules that auto-tag customers in Intercom every time they sync. Example: tag a customer as "vip" when they have made 5+ orders and spent more than $200. Tags created here are applied via the Intercom Tags API and removed by your own Intercom rules — this side only adds.', 'intercom-woo-sync' ); ?>
			</p>

			<div id="iws-rules-list"
				data-rules="<?php echo esc_attr( (string) wp_json_encode( array_values( (array) get_option( 'iws_segment_rules', array() ) ) ) ); ?>">
				<p class="iws-empty iws-rules-empty"><?php esc_html_e( 'No rules yet. Click "Add Rule" to create one.', 'intercom-woo-sync' ); ?></p>
			</div>

			<div class="iws-actions iws-mt-16">
				<button type="button" id="iws-save-segments" class="button button-primary">
					<span class="dashicons dashicons-saved"></span>
					<?php esc_html_e( 'Save All Rules', 'intercom-woo-sync' ); ?>
				</button>
				<span id="iws-segments-spinner" class="spinner"></span>
				<span id="iws-segments-result" class="iws-inline-result"></span>
			</div>
		</div>
	</div>

	<!-- Tab: Live Stream / Sync Log -->
	<div class="iws-tab-panel" id="iws-panel-log" role="tabpanel">
		<div class="iws-card">
			<div class="iws-card__header">
				<h2><?php esc_html_e( 'Live Event Stream', 'intercom-woo-sync' ); ?></h2>
				<button type="button" id="iws-clear-log" class="button button-link-delete">
					<span class="dashicons dashicons-trash"></span>
					<?php esc_html_e( 'Clear Log', 'intercom-woo-sync' ); ?>
				</button>
			</div>

			<!-- Filter / live-stream control bar -->
			<div class="iws-stream-bar">
				<label>
					<?php esc_html_e( 'Status:', 'intercom-woo-sync' ); ?>
					<select id="iws-filter-status">
						<option value="all"><?php esc_html_e( 'All', 'intercom-woo-sync' ); ?></option>
						<option value="success"><?php esc_html_e( 'Success', 'intercom-woo-sync' ); ?></option>
						<option value="error"><?php esc_html_e( 'Error', 'intercom-woo-sync' ); ?></option>
					</select>
				</label>
				<label>
					<?php esc_html_e( 'Action contains:', 'intercom-woo-sync' ); ?>
					<input type="text" id="iws-filter-action" placeholder="<?php esc_attr_e( 'e.g. /contacts', 'intercom-woo-sync' ); ?>" />
				</label>
				<label>
					<input type="checkbox" id="iws-stream-toggle" checked />
					<?php esc_html_e( 'Auto-refresh', 'intercom-woo-sync' ); ?>
				</label>
				<span id="iws-stream-indicator" class="iws-stream-bar__live iws-stream-bar__live--on">
					<span class="iws-stream-bar__live-dot"></span>
					<span class="iws-stream-bar__live-label"><?php esc_html_e( 'Live', 'intercom-woo-sync' ); ?></span>
				</span>
			</div>

			<div id="iws-log-table-wrap">
				<?php if ( empty( $iws_log ) ) : ?>
					<p class="iws-empty"><?php esc_html_e( 'No log entries yet.', 'intercom-woo-sync' ); ?></p>
				<?php else : ?>
					<table class="widefat striped iws-log-table">
						<thead>
							<tr>
								<th><?php esc_html_e( 'Time', 'intercom-woo-sync' ); ?></th>
								<th><?php esc_html_e( 'Status', 'intercom-woo-sync' ); ?></th>
								<th><?php esc_html_e( 'Action', 'intercom-woo-sync' ); ?></th>
								<th><?php esc_html_e( 'Message', 'intercom-woo-sync' ); ?></th>
							</tr>
						</thead>
						<tbody>
							<?php foreach ( $iws_log as $iws_entry ) : // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound ?>
								<tr>
									<td><code><?php echo esc_html( $iws_entry['time'] ?? '' ); ?></code></td>
									<td>
										<?php if ( 'success' === ( $iws_entry['status'] ?? '' ) ) : ?>
											<span class="iws-badge iws-badge--success"><?php esc_html_e( 'OK', 'intercom-woo-sync' ); ?></span>
										<?php else : ?>
											<span class="iws-badge iws-badge--error"><?php esc_html_e( 'Error', 'intercom-woo-sync' ); ?></span>
										<?php endif; ?>
									</td>
									<td><code><?php echo esc_html( $iws_entry['action'] ?? '' ); ?></code></td>
									<td><?php echo esc_html( $iws_entry['msg'] ?? '' ); ?></td>
								</tr>
							<?php endforeach; ?>
						</tbody>
					</table>
				<?php endif; ?>
			</div>
		</div>
	</div>

	<!-- Branding footer -->
	<div class="iws-footer">
		<span class="iws-footer__text">
			<?php
			printf(
				/* translators: %1$s: heart icon HTML. %2$s: link to Etherlabz. */
				esc_html__( 'Built with %1$s by %2$s', 'intercom-woo-sync' ),
				'<span class="iws-footer__heart" aria-hidden="true">&#9829;</span>',
				'<a href="https://etherlabz.com" target="_blank" rel="noopener noreferrer" class="iws-footer__brand">Etherlabz</a>'
			); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			?>
		</span>
		<span class="iws-footer__sep" aria-hidden="true">&middot;</span>
		<a href="https://github.com/Evy04/intercom-sync" target="_blank" rel="noopener noreferrer" class="iws-footer__link">
			<span class="dashicons dashicons-editor-code"></span>
			<?php esc_html_e( 'Source on GitHub', 'intercom-woo-sync' ); ?>
		</a>
		<span class="iws-footer__sep" aria-hidden="true">&middot;</span>
		<span class="iws-footer__version">v<?php echo esc_html( INTERCOM_WOO_SYNC_VERSION ); ?></span>
	</div>

</div>
