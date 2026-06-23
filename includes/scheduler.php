<?php
/**
 * Action Scheduler registration and lifecycle hooks.
 *
 * @package FreyaAppLovin
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Register Action Scheduler callbacks.
 *
 * @return void
 */
function freya_applovin_register_scheduler_hooks() {
	add_action( FREYA_APPLOVIN_HOOK_SEND_LEAD, 'freya_applovin_process_lead', 10, 1 );
}

/**
 * Plugin activation handler.
 *
 * @return void
 */
function freya_applovin_activate() {
	// No persistent schedule required; events are queued on demand.
}

/**
 * Plugin deactivation handler.
 *
 * @return void
 */
function freya_applovin_deactivate() {
	if ( function_exists( 'as_unschedule_all_actions' ) ) {
		as_unschedule_all_actions( '', array(), FREYA_APPLOVIN_AS_GROUP );
	}
}
