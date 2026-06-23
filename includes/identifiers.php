<?php
/**
 * Visitor identifier capture and retrieval.
 *
 * AppLovin requires at least one of client_id / alart / user_id, plus the
 * always-required client_ip_address, client_user_agent and esi fields. The
 * aleid click identifier is sent whenever it is available.
 *
 * @package FreyaAppLovin
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Capture AppLovin identifiers from the request and persist them as cookies.
 *
 * Runs early on every front-end request. Cookie values created during the
 * current request are cached in-memory so the same request can use them
 * before the browser echoes them back.
 *
 * @return void
 */
function freya_applovin_capture_identifiers() {
	if ( is_admin() || wp_doing_cron() ) {
		return;
	}

	// aleid: AppLovin click identifier supplied as a query parameter.
	$aleid = freya_applovin_read_query_param( 'aleid' );
	if ( '' !== $aleid ) {
		freya_applovin_set_cookie( FREYA_APPLOVIN_COOKIE_ALEID, $aleid );
	}

	// alart: alternate click identifier supplied as a query parameter.
	$alart = freya_applovin_read_query_param( 'alart' );
	if ( '' !== $alart ) {
		freya_applovin_set_cookie( FREYA_APPLOVIN_COOKIE_ALART, $alart );
	}

	// client_id: stable first-party identifier generated once per visitor.
	if ( '' === freya_applovin_get_cookie( FREYA_APPLOVIN_COOKIE_CLIENT_ID ) ) {
		freya_applovin_set_cookie( FREYA_APPLOVIN_COOKIE_CLIENT_ID, freya_applovin_generate_client_id() );
	}
}

/**
 * Generate a globally unique, stable first-party client identifier.
 *
 * @return string
 */
function freya_applovin_generate_client_id() {
	if ( function_exists( 'wp_generate_uuid4' ) ) {
		return wp_generate_uuid4();
	}

	return md5( uniqid( (string) wp_rand(), true ) );
}

/**
 * Read and sanitize a query parameter value.
 *
 * @param string $key Query parameter name.
 * @return string Sanitized value or empty string.
 */
function freya_applovin_read_query_param( $key ) {
	if ( ! isset( $_GET[ $key ] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		return '';
	}

	return sanitize_text_field( wp_unslash( $_GET[ $key ] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
}

/**
 * Set an identifier cookie and cache its value for the current request.
 *
 * @param string $name  Cookie name.
 * @param string $value Cookie value.
 * @return void
 */
function freya_applovin_set_cookie( $name, $value ) {
	freya_applovin_cookie_cache( $name, $value );

	if ( headers_sent() ) {
		return;
	}

	$secure = is_ssl();
	$domain = freya_applovin_cookie_domain();

	setcookie(
		$name,
		$value,
		array(
			'expires'  => time() + FREYA_APPLOVIN_COOKIE_LIFETIME,
			'path'     => COOKIEPATH ? COOKIEPATH : '/',
			'domain'   => $domain,
			'secure'   => $secure,
			'httponly' => true,
			'samesite' => 'Lax',
		)
	);
}

/**
 * Resolve the cookie domain, falling back to a host-only cookie.
 *
 * @return string
 */
function freya_applovin_cookie_domain() {
	return defined( 'COOKIE_DOMAIN' ) && COOKIE_DOMAIN ? COOKIE_DOMAIN : '';
}

/**
 * Read an identifier cookie, preferring values set in the current request.
 *
 * @param string $name Cookie name.
 * @return string
 */
function freya_applovin_get_cookie( $name ) {
	$cached = freya_applovin_cookie_cache( $name );
	if ( null !== $cached ) {
		return $cached;
	}

	if ( isset( $_COOKIE[ $name ] ) ) {
		return sanitize_text_field( wp_unslash( $_COOKIE[ $name ] ) );
	}

	return '';
}

/**
 * In-memory store for cookie values created during the current request.
 *
 * @param string      $name  Cookie name.
 * @param string|null $value Value to store, or null to read.
 * @return string|null Stored value when reading, otherwise null.
 */
function freya_applovin_cookie_cache( $name, $value = null ) {
	static $cache = array();

	if ( null !== $value ) {
		$cache[ $name ] = $value;
		return null;
	}

	return isset( $cache[ $name ] ) ? $cache[ $name ] : null;
}

/**
 * Build the user_data payload required by the Axon Event API.
 *
 * Only the supported identifier fields are included; email and phone are never
 * sent. Empty optional identifiers are omitted from the payload.
 *
 * @param array $overrides Optional snapshot values (used by background jobs).
 * @return array
 */
function freya_applovin_build_user_data( array $overrides = array() ) {
	$defaults = array(
		'client_ip_address' => freya_applovin_get_client_ip(),
		'client_user_agent' => freya_applovin_get_user_agent(),
		'aleid'             => freya_applovin_get_cookie( FREYA_APPLOVIN_COOKIE_ALEID ),
		'alart'             => freya_applovin_get_cookie( FREYA_APPLOVIN_COOKIE_ALART ),
		'client_id'         => freya_applovin_get_cookie( FREYA_APPLOVIN_COOKIE_CLIENT_ID ),
		'user_id'           => freya_applovin_get_numeric_user_id(),
		'esi'               => FREYA_APPLOVIN_ESI,
	);

	$data = array_merge( $defaults, $overrides );

	$user_data = array(
		'client_ip_address' => (string) $data['client_ip_address'],
		'client_user_agent' => (string) $data['client_user_agent'],
		'esi'               => (string) $data['esi'],
	);

	foreach ( array( 'aleid', 'alart', 'client_id', 'user_id' ) as $key ) {
		if ( isset( $data[ $key ] ) && '' !== (string) $data[ $key ] ) {
			$user_data[ $key ] = (string) $data[ $key ];
		}
	}

	return $user_data;
}

/**
 * Snapshot the request-scoped identifiers so they can be passed to a job.
 *
 * @return array
 */
function freya_applovin_snapshot_user_data() {
	return array(
		'client_ip_address' => freya_applovin_get_client_ip(),
		'client_user_agent' => freya_applovin_get_user_agent(),
		'aleid'             => freya_applovin_get_cookie( FREYA_APPLOVIN_COOKIE_ALEID ),
		'alart'             => freya_applovin_get_cookie( FREYA_APPLOVIN_COOKIE_ALART ),
		'client_id'         => freya_applovin_get_cookie( FREYA_APPLOVIN_COOKIE_CLIENT_ID ),
		'user_id'           => freya_applovin_get_numeric_user_id(),
		'esi'               => FREYA_APPLOVIN_ESI,
	);
}

/**
 * Get the current user's numeric WordPress ID, if logged in.
 *
 * AppLovin requires user_id to be numeric, so the WordPress user ID is used.
 *
 * @return string Numeric user ID or empty string.
 */
function freya_applovin_get_numeric_user_id() {
	$user_id = get_current_user_id();

	return $user_id > 0 ? (string) $user_id : '';
}

/**
 * Resolve the visitor's IP address, honoring common proxy headers.
 *
 * @return string
 */
function freya_applovin_get_client_ip() {
	$headers = array(
		'HTTP_CF_CONNECTING_IP',
		'HTTP_X_FORWARDED_FOR',
		'HTTP_X_REAL_IP',
		'REMOTE_ADDR',
	);

	foreach ( $headers as $header ) {
		if ( empty( $_SERVER[ $header ] ) ) {
			continue;
		}

		$raw = sanitize_text_field( wp_unslash( $_SERVER[ $header ] ) );

		// X-Forwarded-For can be a comma-separated list; the first entry is the client.
		foreach ( explode( ',', $raw ) as $candidate ) {
			$candidate = trim( $candidate );
			if ( filter_var( $candidate, FILTER_VALIDATE_IP ) ) {
				return $candidate;
			}
		}
	}

	return '';
}

/**
 * Resolve the visitor's user agent string.
 *
 * @return string
 */
function freya_applovin_get_user_agent() {
	if ( empty( $_SERVER['HTTP_USER_AGENT'] ) ) {
		return '';
	}

	return sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) );
}

/**
 * Build the event_source_url truncated to the domain only.
 *
 * Per AppLovin requirements only the scheme + host is sent (e.g.
 * https://freyameds.com/ rather than the full path).
 *
 * @return string
 */
function freya_applovin_get_event_source_url() {
	$home = home_url( '/' );
	$parts = wp_parse_url( $home );

	if ( empty( $parts['host'] ) ) {
		return $home;
	}

	$scheme = ! empty( $parts['scheme'] ) ? $parts['scheme'] : ( is_ssl() ? 'https' : 'http' );

	return $scheme . '://' . $parts['host'] . '/';
}
