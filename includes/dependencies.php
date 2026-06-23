<?php
/**
 * Dependency checks and admin notices.
 *
 * @package FreyaAppLovin
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Whether the Conversion API key and Axon event key are configured.
 *
 * @return bool
 */
function freya_applovin_is_configured() {
	return defined( 'FREYA_APPLOVIN_API_KEY' )
		&& FREYA_APPLOVIN_API_KEY
		&& 'REPLACE_WITH_CONVERSION_API_KEY' !== FREYA_APPLOVIN_API_KEY
		&& defined( 'FREYA_APPLOVIN_PIXEL_ID' )
		&& FREYA_APPLOVIN_PIXEL_ID
		&& 'REPLACE_WITH_AXON_EVENT_KEY' !== FREYA_APPLOVIN_PIXEL_ID;
}

/**
 * Whether Gravity Forms is available.
 *
 * @return bool
 */
function freya_applovin_has_gravity_forms() {
	return class_exists( 'GFAPI' );
}

/**
 * Whether Action Scheduler is available.
 *
 * @return bool
 */
function freya_applovin_has_action_scheduler() {
	return function_exists( 'as_enqueue_async_action' ) && function_exists( 'as_has_scheduled_action' );
}

/**
 * Display a warning when the AppLovin credentials are missing.
 *
 * @return void
 */
function freya_applovin_admin_notice_missing_config() {
	if ( ! current_user_can( 'manage_options' ) || freya_applovin_is_configured() ) {
		return;
	}

	printf(
		'<div class="notice notice-warning"><p><strong>%s</strong> %s</p></div>',
		esc_html__( 'Freya AppLovin Conversion API:', 'freya-applovin' ),
		esc_html__( 'Define FREYA_APPLOVIN_API_KEY and FREYA_APPLOVIN_PIXEL_ID in the plugin file before events can be sent.', 'freya-applovin' )
	);
}
