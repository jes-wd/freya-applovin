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

	$response = wp_remote_post(
		freya_applovin_get_endpoint_url(),
		array(
			'timeout'  => $blocking ? FREYA_APPLOVIN_HTTP_TIMEOUT : 0.01,
			'blocking' => (bool) $blocking,
			'headers'  => array(
				'Content-Type'  => 'application/json',
				'Authorization' => FREYA_APPLOVIN_API_KEY,
			),
			'body'     => wp_json_encode( array( 'events' => array_values( $events ) ) ),
		)
	);

	if ( ! $blocking ) {
		return true;
	}

	if ( is_wp_error( $response ) ) {
		return $response;
	}

	$status = (int) wp_remote_retrieve_response_code( $response );

	if ( 200 === $status ) {
		return true;
	}

	$body = wp_remote_retrieve_body( $response );
	$body = is_string( $body ) ? substr( $body, 0, 300 ) : '';

	return new WP_Error(
		'freya_applovin_http_' . $status,
		sprintf( 'AppLovin Event API returned HTTP %1$d.%2$s', $status, $body ? ' Response: ' . $body : '' )
	);
}
