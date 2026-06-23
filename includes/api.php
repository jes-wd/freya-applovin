<?php
/**
 * Axon Event API client.
 *
 * @package FreyaAppLovin
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Write a message to wp-content/debug.log when WP_DEBUG_LOG is enabled.
 *
 * @param string $message Log message.
 * @return void
 */
function freya_applovin_debug_log( $message ) {
	if ( ! defined( 'WP_DEBUG' ) || ! WP_DEBUG || ! defined( 'WP_DEBUG_LOG' ) || ! WP_DEBUG_LOG ) {
		return;
	}

	error_log( '[Freya AppLovin] ' . $message ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
}

/**
 * Build the Axon Event API endpoint URL including the required pixel_id.
 *
 * @return string
 */
function freya_applovin_get_endpoint_url() {
	return add_query_arg( 'pixel_id', rawurlencode( FREYA_APPLOVIN_PIXEL_ID ), FREYA_APPLOVIN_ENDPOINT );
}

/**
 * Send a batch of events to the Axon Event API.
 *
 * @param array $events    List of ServerEvent arrays (max 100 per batch).
 * @param bool  $blocking  Whether to wait for and validate the response.
 * @return true|WP_Error True on success (or when non-blocking), WP_Error on failure.
 */
function freya_applovin_send_events( array $events, $blocking = true ) {
	if ( ! freya_applovin_is_configured() ) {
		return new WP_Error( 'freya_applovin_not_configured', 'AppLovin Conversion API credentials are not configured.' );
	}

	if ( empty( $events ) ) {
		return new WP_Error( 'freya_applovin_no_events', 'No events to send.' );
	}

	$payload = array( 'events' => array_values( $events ) );
	$body    = wp_json_encode( $payload );

	freya_applovin_debug_log(
		sprintf(
			'Request (%s): POST %s payload=%s',
			$blocking ? 'blocking' : 'non-blocking',
			freya_applovin_get_endpoint_url(),
			$body
		)
	);

	$response = wp_remote_post(
		freya_applovin_get_endpoint_url(),
		array(
			'timeout'  => $blocking ? FREYA_APPLOVIN_HTTP_TIMEOUT : 0.01,
			'blocking' => (bool) $blocking,
			'headers'  => array(
				'Content-Type'  => 'application/json',
				'Authorization' => FREYA_APPLOVIN_API_KEY,
			),
			'body'     => $body,
		)
	);

	if ( ! $blocking ) {
		if ( is_wp_error( $response ) ) {
			freya_applovin_debug_log( 'Non-blocking transport error: ' . $response->get_error_message() );
		}

		return true;
	}

	if ( is_wp_error( $response ) ) {
		freya_applovin_debug_log( 'Transport error: ' . $response->get_error_message() );

		return $response;
	}

	$status       = (int) wp_remote_retrieve_response_code( $response );
	$response_body = wp_remote_retrieve_body( $response );
	$response_body = is_string( $response_body ) ? substr( $response_body, 0, 300 ) : '';

	freya_applovin_debug_log(
		sprintf(
			'Response: HTTP %d%s',
			$status,
			$response_body ? ' body=' . $response_body : ''
		)
	);

	if ( 200 === $status ) {
		return true;
	}

	return new WP_Error(
		'freya_applovin_http_' . $status,
		sprintf( 'AppLovin Event API returned HTTP %1$d.%2$s', $status, $response_body ? ' Response: ' . $response_body : '' )
	);
}
