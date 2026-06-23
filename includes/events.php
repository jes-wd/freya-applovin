<?php
/**
 * Event construction and dispatch.
 *
 * @package FreyaAppLovin
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Current Unix epoch time in milliseconds.
 *
 * @return int
 */
function freya_applovin_event_time_ms() {
	return (int) round( microtime( true ) * 1000 );
}

/**
 * Build a single ServerEvent payload.
 *
 * @param string     $name      Event name (page_view or generate_lead).
 * @param array      $user_data UserData payload.
 * @param array|null $data      EventData payload, or null for page_view.
 * @param string     $dedupe_id Optional de-duplication identifier.
 * @return array
 */
function freya_applovin_build_event( $name, array $user_data, $data = null, $dedupe_id = '' ) {
	$event = array(
		'event_time'       => freya_applovin_event_time_ms(),
		'event_source_url' => freya_applovin_get_event_source_url(),
		'name'             => $name,
		'user_data'        => $user_data,
		'data'             => $data,
	);

	if ( '' !== $dedupe_id ) {
		$event['dedupe_id'] = $dedupe_id;
	}

	return $event;
}

/**
 * Whether a page_view should be tracked for the current request.
 *
 * @return bool
 */
function freya_applovin_should_track_page_view() {
	if ( is_admin() || wp_doing_ajax() || wp_doing_cron() ) {
		return false;
	}

	if ( ( defined( 'REST_REQUEST' ) && REST_REQUEST ) || is_feed() || is_robots() || is_preview() ) {
		return false;
	}

	if ( isset( $_SERVER['REQUEST_METHOD'] ) && 'GET' !== strtoupper( sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) ) ) {
		return false;
	}

	if ( is_404() ) {
		return false;
	}

	/**
	 * Filter whether the current request should fire a page_view event.
	 *
	 * @param bool $track Whether to track the page view.
	 */
	return (bool) apply_filters( 'freya_applovin_track_page_view', true );
}

/**
 * Track a page_view event for the current request (non-blocking).
 *
 * @return void
 */
function freya_applovin_track_page_view() {
	if ( ! freya_applovin_is_configured() || ! freya_applovin_should_track_page_view() ) {
		return;
	}

	$event = freya_applovin_build_event(
		FREYA_APPLOVIN_EVENT_PAGE_VIEW,
		freya_applovin_build_user_data(),
		null
	);

	// page_view volume is high, so fire-and-forget without blocking the response.
	freya_applovin_send_events( array( $event ), false );
}

/**
 * Whether a Gravity Forms submission should fire a generate_lead event.
 *
 * @param int $form_id Gravity Forms form ID.
 * @return bool
 */
function freya_applovin_should_track_lead( $form_id ) {
	$form_id = (int) $form_id;

	$allowed = defined( 'FREYA_APPLOVIN_LEAD_FORM_IDS' ) ? (array) FREYA_APPLOVIN_LEAD_FORM_IDS : array();

	/**
	 * Filter the list of form IDs that fire generate_lead.
	 *
	 * Return an empty array to track every form.
	 *
	 * @param array $allowed Form IDs.
	 * @param int   $form_id Current form ID.
	 */
	$allowed = (array) apply_filters( 'freya_applovin_lead_form_ids', $allowed, $form_id );

	$track = empty( $allowed ) || in_array( $form_id, array_map( 'intval', $allowed ), true );

	/**
	 * Filter whether a given form submission should fire generate_lead.
	 *
	 * @param bool $track   Whether to track the lead.
	 * @param int  $form_id Form ID.
	 */
	return (bool) apply_filters( 'freya_applovin_track_lead', $track, $form_id );
}

/**
 * Resolve the monetary value for a generate_lead event.
 *
 * @param int $entry_id Gravity Forms entry ID.
 * @param int $form_id  Gravity Forms form ID.
 * @return float
 */
function freya_applovin_get_lead_value( $entry_id, $form_id ) {
	$value = defined( 'FREYA_APPLOVIN_DEFAULT_LEAD_VALUE' ) ? FREYA_APPLOVIN_DEFAULT_LEAD_VALUE : 0;

	/**
	 * Filter the generate_lead value. Return 0 for duplicate or low-quality leads.
	 *
	 * @param float $value    Lead value.
	 * @param int   $entry_id Entry ID.
	 * @param int   $form_id  Form ID.
	 */
	return (float) apply_filters( 'freya_applovin_lead_value', $value, $entry_id, $form_id );
}

/**
 * Resolve the currency for a generate_lead event.
 *
 * @param int $entry_id Gravity Forms entry ID.
 * @param int $form_id  Gravity Forms form ID.
 * @return string
 */
function freya_applovin_get_lead_currency( $entry_id, $form_id ) {
	$currency = defined( 'FREYA_APPLOVIN_DEFAULT_CURRENCY' ) ? FREYA_APPLOVIN_DEFAULT_CURRENCY : 'USD';

	/**
	 * Filter the generate_lead currency (ISO 4217).
	 *
	 * @param string $currency Currency code.
	 * @param int    $entry_id Entry ID.
	 * @param int    $form_id  Form ID.
	 */
	return (string) apply_filters( 'freya_applovin_lead_currency', $currency, $entry_id, $form_id );
}

/**
 * Queue a generate_lead event for a Gravity Forms submission.
 *
 * Captures the request-scoped identifiers immediately and defers the HTTP call
 * to Action Scheduler so the submission is never blocked.
 *
 * @param array $entry Gravity Forms entry.
 * @param array $form  Gravity Forms form.
 * @return void
 */
function freya_applovin_queue_lead( $entry, $form ) {
	if ( ! freya_applovin_is_configured() || ! freya_applovin_has_action_scheduler() ) {
		return;
	}

	$entry_id = (int) rgar( $entry, 'id' );
	$form_id  = (int) rgar( $entry, 'form_id' );

	if ( $entry_id <= 0 || ! freya_applovin_should_track_lead( $form_id ) ) {
		return;
	}

	$args = array(
		array(
			'entry_id'   => $entry_id,
			'form_id'    => $form_id,
			'value'      => freya_applovin_get_lead_value( $entry_id, $form_id ),
			'currency'   => freya_applovin_get_lead_currency( $entry_id, $form_id ),
			'user_data'  => freya_applovin_snapshot_user_data(),
			'source_url' => freya_applovin_get_event_source_url(),
			'event_time' => freya_applovin_event_time_ms(),
		),
	);

	if ( as_has_scheduled_action( FREYA_APPLOVIN_HOOK_SEND_LEAD, $args, FREYA_APPLOVIN_AS_GROUP ) ) {
		return;
	}

	as_enqueue_async_action( FREYA_APPLOVIN_HOOK_SEND_LEAD, $args, FREYA_APPLOVIN_AS_GROUP );
}

/**
 * Action Scheduler callback: send a queued generate_lead event.
 *
 * @param array $payload Snapshot captured at submission time.
 * @return void
 * @throws Exception When the API call fails so Action Scheduler can retry.
 */
function freya_applovin_process_lead( $payload ) {
	if ( ! is_array( $payload ) ) {
		return;
	}

	$entry_id = (int) ( $payload['entry_id'] ?? 0 );
	$form_id  = (int) ( $payload['form_id'] ?? 0 );

	$user_data = isset( $payload['user_data'] ) && is_array( $payload['user_data'] )
		? freya_applovin_build_user_data( $payload['user_data'] )
		: freya_applovin_build_user_data();

	$event = array(
		'event_time'       => isset( $payload['event_time'] ) ? (int) $payload['event_time'] : freya_applovin_event_time_ms(),
		'event_source_url' => isset( $payload['source_url'] ) && $payload['source_url'] ? (string) $payload['source_url'] : freya_applovin_get_event_source_url(),
		'name'             => FREYA_APPLOVIN_EVENT_GENERATE_LEAD,
		'user_data'        => $user_data,
		'data'             => array(
			'currency' => isset( $payload['currency'] ) ? (string) $payload['currency'] : FREYA_APPLOVIN_DEFAULT_CURRENCY,
			'value'    => isset( $payload['value'] ) ? (float) $payload['value'] : (float) FREYA_APPLOVIN_DEFAULT_LEAD_VALUE,
		),
	);

	if ( $entry_id > 0 ) {
		$event['dedupe_id'] = 'gf_lead_' . $entry_id;
	}

	$result = freya_applovin_send_events( array( $event ), true );

	if ( is_wp_error( $result ) ) {
		if ( $entry_id > 0 && function_exists( 'gform_update_meta' ) ) {
			gform_update_meta( $entry_id, FREYA_APPLOVIN_META_SENT_AT, '', $form_id );
		}

		throw new Exception( 'Freya AppLovin generate_lead failed: ' . $result->get_error_message() );
	}

	if ( $entry_id > 0 && function_exists( 'gform_update_meta' ) ) {
		gform_update_meta( $entry_id, FREYA_APPLOVIN_META_SENT_AT, gmdate( 'c' ), $form_id );
	}
}
