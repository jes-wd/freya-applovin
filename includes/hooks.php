<?php
/**
 * WordPress and Gravity Forms hooks.
 *
 * @package FreyaAppLovin
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Bootstrap plugin hooks once dependencies are loaded.
 *
 * @return void
 */
function freya_applovin_bootstrap() {
	add_action( 'admin_notices', 'freya_applovin_admin_notice_missing_config' );

	freya_applovin_register_scheduler_hooks();

	// Capture identifiers as early as possible so cookies are set before output.
	add_action( 'init', 'freya_applovin_capture_identifiers', 1 );

	// Cache-safe capture: inline head JS sets cookies even on Breeze / Varnish HITs.
	add_action( 'wp_head', 'freya_applovin_output_head_capture_script', 0 );

	// WC session is usually unavailable on init:1 — sync durable stores once it is.
	add_action( 'woocommerce_init', 'freya_applovin_sync_durable_stores', 20 );
	add_action( 'wp', 'freya_applovin_sync_durable_stores', 5 );

	// Stamp identifiers onto orders at checkout (independent of API config).
	freya_applovin_register_order_hooks();

	// Durable storage hooks (independent of Conversion API credentials).
	add_filter( 'freya_checkout_recovery_payload', 'freya_applovin_filter_recovery_payload', 10, 1 );
	add_action( 'freya_checkout_recovery_restored', 'freya_applovin_on_recovery_restored', 10, 1 );
	add_action( 'wp_login', 'freya_applovin_on_user_login', 20, 2 );

	if ( freya_applovin_has_gravity_forms() ) {
		// Persist click IDs onto the GF entry for later order attribution (no generate_lead).
		add_action( 'gform_after_submission', 'freya_applovin_persist_entry_identifiers', 15, 2 );
	}

	if ( ! freya_applovin_is_configured() ) {
		return;
	}

	// Fire page_view once the main query is resolved (so 404 detection works).
	add_action( 'template_redirect', 'freya_applovin_track_page_view', 20 );

	// Fire generate_lead after a successful new-customer purchase.
	freya_applovin_register_purchase_lead_hooks();
}
