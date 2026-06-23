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

	if ( ! freya_applovin_is_configured() ) {
		return;
	}

	// Fire page_view once the main query is resolved (so 404 detection works).
	add_action( 'template_redirect', 'freya_applovin_track_page_view', 20 );

	// Fire generate_lead after a Gravity Forms submission is saved.
	if ( freya_applovin_has_gravity_forms() ) {
		add_action( 'gform_after_submission', 'freya_applovin_queue_lead', 20, 2 );
	}
}
