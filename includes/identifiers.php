<?php
/**
 * Visitor identifier capture and retrieval.
 *
 * AppLovin requires at least one of client_id / alart / user_id, plus the
 * always-required client_ip_address, client_user_agent and esi fields. The
 * aleid click identifier is sent whenever it is available.
 *
 * Click IDs (aleid / alart) are persisted in multiple places so they survive
 * cookie loss (in-app browsers, recovery links, cross-device checkout):
 * cookies, WooCommerce session, logged-in user meta, Gravity Forms entry meta,
 * and the checkout recovery payload.
 *
 * @package FreyaAppLovin
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Capture AppLovin identifiers from the request and persist them durably.
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

	$aleid = freya_applovin_read_query_param( 'aleid' );
	$alart = freya_applovin_read_query_param( 'alart' );

	// Also accept click IDs embedded in a full landing / referrer URL.
	if ( '' === $aleid || '' === $alart ) {
		$from_url = freya_applovin_parse_click_ids_from_url( freya_applovin_current_request_url() );
		if ( '' === $aleid && ! empty( $from_url['aleid'] ) ) {
			$aleid = $from_url['aleid'];
		}
		if ( '' === $alart && ! empty( $from_url['alart'] ) ) {
			$alart = $from_url['alart'];
		}
	}

	if ( '' !== $aleid ) {
		freya_applovin_set_cookie( FREYA_APPLOVIN_COOKIE_ALEID, $aleid );
	}

	if ( '' !== $alart ) {
		freya_applovin_set_cookie( FREYA_APPLOVIN_COOKIE_ALART, $alart );
	}

	// client_id: stable first-party identifier generated once per visitor.
	if ( '' === freya_applovin_get_cookie( FREYA_APPLOVIN_COOKIE_CLIENT_ID ) ) {
		freya_applovin_set_cookie( FREYA_APPLOVIN_COOKIE_CLIENT_ID, freya_applovin_generate_client_id() );
	}

	if ( '' !== $aleid || '' !== $alart ) {
		freya_applovin_persist_click_ids(
			array(
				'aleid'     => $aleid,
				'alart'     => $alart,
				'client_id' => freya_applovin_get_cookie( FREYA_APPLOVIN_COOKIE_CLIENT_ID ),
			)
		);
	} else {
		// Keep session / user meta in sync with the stable client_id cookie.
		freya_applovin_persist_click_ids(
			array(
				'client_id' => freya_applovin_get_cookie( FREYA_APPLOVIN_COOKIE_CLIENT_ID ),
			),
			false
		);
	}
}

/**
 * Re-sync cookies into WC session / user meta once WooCommerce is available.
 *
 * @return void
 */
function freya_applovin_sync_durable_stores() {
	if ( is_admin() || wp_doing_cron() ) {
		return;
	}

	static $done = false;
	if ( $done ) {
		return;
	}
	$done = true;

	$ids = array(
		'aleid'     => freya_applovin_get_cookie( FREYA_APPLOVIN_COOKIE_ALEID ),
		'alart'     => freya_applovin_get_cookie( FREYA_APPLOVIN_COOKIE_ALART ),
		'client_id' => freya_applovin_get_cookie( FREYA_APPLOVIN_COOKIE_CLIENT_ID ),
	);

	$has_click = ( '' !== $ids['aleid'] || '' !== $ids['alart'] );
	freya_applovin_persist_click_ids( $ids, $has_click );
}

/**
 * Persist click / visitor identifiers to WC session and user meta.
 *
 * @param array<string, string> $ids            Keys: aleid, alart, client_id.
 * @param bool                  $overwrite_click When false, only fill empty click slots.
 * @return void
 */
function freya_applovin_persist_click_ids( array $ids, $overwrite_click = true ) {
	$normalized = freya_applovin_normalize_id_map( $ids );
	if ( empty( $normalized ) ) {
		return;
	}

	$existing = freya_applovin_get_session_ids();
	$merged   = $existing;

	foreach ( array( 'aleid', 'alart', 'client_id' ) as $key ) {
		if ( empty( $normalized[ $key ] ) ) {
			continue;
		}
		if ( ! $overwrite_click && in_array( $key, array( 'aleid', 'alart' ), true ) && ! empty( $merged[ $key ] ) ) {
			continue;
		}
		$merged[ $key ] = $normalized[ $key ];
	}

	if ( ! empty( $normalized['aleid'] ) || ! empty( $normalized['alart'] ) ) {
		$merged['captured_at'] = time();
	} elseif ( empty( $merged['captured_at'] ) ) {
		$merged['captured_at'] = time();
	}

	freya_applovin_set_session_ids( $merged );

	$user_id = get_current_user_id();
	if ( $user_id > 0 ) {
		freya_applovin_persist_user_ids( $user_id, $merged, $overwrite_click );
	}
}

/**
 * Write identifiers onto a WordPress user.
 *
 * @param int                   $user_id         User ID.
 * @param array<string, string> $ids             Normalized id map.
 * @param bool                  $overwrite_click Overwrite existing click IDs.
 * @return void
 */
function freya_applovin_persist_user_ids( $user_id, array $ids, $overwrite_click = true ) {
	$user_id = (int) $user_id;
	if ( $user_id <= 0 ) {
		return;
	}

	$map = array(
		'aleid'     => FREYA_APPLOVIN_META_USER_ALEID,
		'alart'     => FREYA_APPLOVIN_META_USER_ALART,
		'client_id' => FREYA_APPLOVIN_META_USER_CLIENT_ID,
	);

	foreach ( $map as $key => $meta_key ) {
		if ( empty( $ids[ $key ] ) ) {
			continue;
		}
		if ( ! $overwrite_click && in_array( $key, array( 'aleid', 'alart' ), true ) ) {
			$existing = (string) get_user_meta( $user_id, $meta_key, true );
			if ( '' !== $existing ) {
				continue;
			}
		}
		update_user_meta( $user_id, $meta_key, $ids[ $key ] );
	}
}

/**
 * Normalize an identifier map to sanitized non-empty strings.
 *
 * @param array<string, mixed> $ids Raw map.
 * @return array<string, string>
 */
function freya_applovin_normalize_id_map( array $ids ) {
	$out = array();
	foreach ( array( 'aleid', 'alart', 'client_id' ) as $key ) {
		if ( ! isset( $ids[ $key ] ) ) {
			continue;
		}
		$value = sanitize_text_field( (string) $ids[ $key ] );
		if ( '' !== $value ) {
			$out[ $key ] = $value;
		}
	}
	return $out;
}

/**
 * Read durable identifiers from the WooCommerce session.
 *
 * @return array<string, mixed>
 */
function freya_applovin_get_session_ids() {
	if ( ! function_exists( 'WC' ) || ! WC()->session ) {
		return array();
	}

	$stored = WC()->session->get( FREYA_APPLOVIN_SESSION_KEY );
	if ( ! is_array( $stored ) ) {
		return array();
	}

	$captured_at = isset( $stored['captured_at'] ) ? (int) $stored['captured_at'] : 0;
	if ( $captured_at > 0 && ( time() - $captured_at ) > FREYA_APPLOVIN_CLICK_ID_TTL ) {
		// Expired click IDs — keep client_id only.
		$kept = array();
		if ( ! empty( $stored['client_id'] ) ) {
			$kept['client_id']   = sanitize_text_field( (string) $stored['client_id'] );
			$kept['captured_at'] = $captured_at;
		}
		return $kept;
	}

	return freya_applovin_normalize_id_map( $stored ) + array(
		'captured_at' => $captured_at,
	);
}

/**
 * Store identifiers in the WooCommerce session.
 *
 * @param array<string, mixed> $ids Session payload.
 * @return void
 */
function freya_applovin_set_session_ids( array $ids ) {
	if ( ! function_exists( 'WC' ) || ! WC()->session ) {
		return;
	}

	WC()->session->set( FREYA_APPLOVIN_SESSION_KEY, $ids );
}

/**
 * Resolve the best available identifier value for a key.
 *
 * Lookup order: request cookie/cache → WC session → logged-in user meta →
 * checkout recovery payload → Gravity Forms entry for billing email →
 * first-landing / order-attribution URLs already on the order.
 *
 * @param string        $key   One of aleid, alart, client_id.
 * @param WC_Order|null $order Optional order being stamped (for URL fallbacks).
 * @return string
 */
function freya_applovin_resolve_identifier( $key, $order = null ) {
	$cookie_map = array(
		'aleid'     => FREYA_APPLOVIN_COOKIE_ALEID,
		'alart'     => FREYA_APPLOVIN_COOKIE_ALART,
		'client_id' => FREYA_APPLOVIN_COOKIE_CLIENT_ID,
	);

	if ( ! isset( $cookie_map[ $key ] ) ) {
		return '';
	}

	$value = freya_applovin_get_cookie( $cookie_map[ $key ] );
	if ( '' !== $value ) {
		return $value;
	}

	$session = freya_applovin_get_session_ids();
	if ( ! empty( $session[ $key ] ) ) {
		return (string) $session[ $key ];
	}

	$user_id = get_current_user_id();
	if ( $user_id <= 0 && $order instanceof WC_Order ) {
		$user_id = (int) $order->get_user_id();
	}
	if ( $user_id > 0 ) {
		$user_meta_map = array(
			'aleid'     => FREYA_APPLOVIN_META_USER_ALEID,
			'alart'     => FREYA_APPLOVIN_META_USER_ALART,
			'client_id' => FREYA_APPLOVIN_META_USER_CLIENT_ID,
		);
		$from_user = (string) get_user_meta( $user_id, $user_meta_map[ $key ], true );
		if ( '' !== $from_user ) {
			return $from_user;
		}
	}

	$from_recovery = freya_applovin_ids_from_recovery_payload();
	if ( ! empty( $from_recovery[ $key ] ) ) {
		return (string) $from_recovery[ $key ];
	}

	if ( $order instanceof WC_Order && in_array( $key, array( 'aleid', 'alart' ), true ) ) {
		$from_gf = freya_applovin_ids_from_customer_gf_entry( $order );
		if ( ! empty( $from_gf[ $key ] ) ) {
			return (string) $from_gf[ $key ];
		}

		$from_urls = freya_applovin_ids_from_order_urls( $order );
		if ( ! empty( $from_urls[ $key ] ) ) {
			return (string) $from_urls[ $key ];
		}
	}

	return '';
}

/**
 * Pull AppLovin IDs out of the current checkout recovery transient / session token.
 *
 * @return array<string, string>
 */
function freya_applovin_ids_from_recovery_payload() {
	if ( ! function_exists( 'freya_checkout_recovery_get_session_token' ) ) {
		return array();
	}

	$token = freya_checkout_recovery_get_session_token();
	if ( '' === $token || ! function_exists( 'freya_checkout_recovery_transient_key' ) ) {
		return array();
	}

	$payload = get_transient( freya_checkout_recovery_transient_key( $token ) );
	if ( ! is_array( $payload ) || empty( $payload['applovin'] ) || ! is_array( $payload['applovin'] ) ) {
		return array();
	}

	return freya_applovin_normalize_id_map( $payload['applovin'] );
}

/**
 * Look up the newest Gravity Forms entry for this customer that stored click IDs.
 *
 * Prefers entry meta written by freya_applovin_persist_entry_identifiers(); falls
 * back to parsing aleid/alart out of source_url for older entries.
 *
 * @param WC_Order $order Order being created.
 * @return array<string, string>
 */
function freya_applovin_ids_from_customer_gf_entry( $order ) {
	if ( ! $order instanceof WC_Order || ! freya_applovin_has_gravity_forms() ) {
		return array();
	}

	$email = strtolower( trim( (string) $order->get_billing_email() ) );
	if ( '' === $email || ! is_email( $email ) ) {
		return array();
	}

	global $wpdb;
	$entry_table = $wpdb->prefix . 'gf_entry';
	$meta_table  = $wpdb->prefix . 'gf_entry_meta';

	// 1) Newest entry with stored aleid/alart meta for this email.
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	$row = $wpdb->get_row(
		$wpdb->prepare(
			"SELECT e.id, e.source_url
			FROM {$entry_table} e
			INNER JOIN {$meta_table} email_meta
				ON email_meta.entry_id = e.id AND email_meta.meta_value = %s
			WHERE e.status = 'active'
			  AND e.date_created >= DATE_SUB(UTC_TIMESTAMP(), INTERVAL %d SECOND)
			  AND (
			    EXISTS (
			      SELECT 1 FROM {$meta_table} m
			      WHERE m.entry_id = e.id AND m.meta_key = %s AND m.meta_value <> ''
			    )
			    OR EXISTS (
			      SELECT 1 FROM {$meta_table} m
			      WHERE m.entry_id = e.id AND m.meta_key = %s AND m.meta_value <> ''
			    )
			    OR e.source_url LIKE %s
			    OR e.source_url LIKE %s
			  )
			ORDER BY e.id DESC
			LIMIT 1",
			$email,
			FREYA_APPLOVIN_CLICK_ID_TTL,
			FREYA_APPLOVIN_META_ENTRY_ALEID,
			FREYA_APPLOVIN_META_ENTRY_ALART,
			'%aleid=%',
			'%alart=%'
		),
		ARRAY_A
	);

	if ( ! is_array( $row ) || empty( $row['id'] ) ) {
		return array();
	}

	$entry_id = (int) $row['id'];
	$ids      = array();

	foreach ( array(
		'aleid'     => FREYA_APPLOVIN_META_ENTRY_ALEID,
		'alart'     => FREYA_APPLOVIN_META_ENTRY_ALART,
		'client_id' => FREYA_APPLOVIN_META_ENTRY_CLIENT_ID,
	) as $key => $meta_key ) {
		$value = '';
		if ( function_exists( 'gform_get_meta' ) ) {
			$value = (string) gform_get_meta( $entry_id, $meta_key );
		} else {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$value = (string) $wpdb->get_var(
				$wpdb->prepare(
					"SELECT meta_value FROM {$meta_table} WHERE entry_id = %d AND meta_key = %s LIMIT 1",
					$entry_id,
					$meta_key
				)
			);
		}
		if ( '' !== $value ) {
			$ids[ $key ] = sanitize_text_field( $value );
		}
	}

	if ( empty( $ids['aleid'] ) || empty( $ids['alart'] ) ) {
		$from_source = freya_applovin_parse_click_ids_from_url( (string) ( $row['source_url'] ?? '' ) );
		$ids         = array_merge( $from_source, $ids );
	}

	// Prefer the Action Scheduler generate_lead snapshot when present — GF
	// source_url is often truncated and older entries may lack entry meta.
	$from_as = freya_applovin_ids_from_actionscheduler_entry( $entry_id );
	if ( ! empty( $from_as ) ) {
		$ids = array_merge( $ids, $from_as );
	}

	return freya_applovin_normalize_id_map( $ids );
}

/**
 * Recover click IDs from the Action Scheduler generate_lead snapshot for an entry.
 *
 * @param int $entry_id Gravity Forms entry ID.
 * @return array<string, string>
 */
function freya_applovin_ids_from_actionscheduler_entry( $entry_id ) {
	$entry_id = (int) $entry_id;
	if ( $entry_id <= 0 ) {
		return array();
	}

	global $wpdb;
	$table = $wpdb->prefix . 'actionscheduler_actions';

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	$extended = (string) $wpdb->get_var(
		$wpdb->prepare(
			"SELECT extended_args FROM {$table}
			WHERE hook = %s AND extended_args LIKE %s
			ORDER BY action_id DESC LIMIT 1",
			FREYA_APPLOVIN_HOOK_SEND_LEAD,
			'%"entry_id":' . $entry_id . ',%'
		)
	);

	if ( '' === $extended ) {
		return array();
	}

	$decoded = json_decode( $extended, true );
	if ( ! is_array( $decoded ) || empty( $decoded[0]['user_data'] ) || ! is_array( $decoded[0]['user_data'] ) ) {
		return array();
	}

	return freya_applovin_normalize_id_map( $decoded[0]['user_data'] );
}

/**
 * Extract click IDs from URLs already stored on the order (landing / attribution).
 *
 * @param WC_Order $order Order object.
 * @return array<string, string>
 */
function freya_applovin_ids_from_order_urls( $order ) {
	if ( ! $order instanceof WC_Order ) {
		return array();
	}

	$candidates = array(
		(string) $order->get_meta( '_freya_first_landing_url' ),
		(string) $order->get_meta( '_wc_order_attribution_session_entry' ),
		(string) $order->get_meta( 'cookie_REFERRER_URL' ),
	);

	foreach ( $candidates as $url ) {
		$parsed = freya_applovin_parse_click_ids_from_url( $url );
		if ( ! empty( $parsed['aleid'] ) || ! empty( $parsed['alart'] ) ) {
			return $parsed;
		}
	}

	return array();
}

/**
 * Parse aleid / alart from a URL or query string.
 *
 * @param string $url Full URL or query string.
 * @return array<string, string>
 */
function freya_applovin_parse_click_ids_from_url( $url ) {
	$url = trim( (string) $url );
	if ( '' === $url ) {
		return array();
	}

	$query = '';
	$parts = wp_parse_url( $url );
	if ( is_array( $parts ) && ! empty( $parts['query'] ) ) {
		$query = $parts['query'];
	} elseif ( false !== strpos( $url, '=' ) ) {
		$query = ltrim( $url, '?' );
	}

	if ( '' === $query ) {
		return array();
	}

	$params = array();
	wp_parse_str( $query, $params );

	$out = array();
	foreach ( array( 'aleid', 'alart' ) as $key ) {
		if ( ! empty( $params[ $key ] ) && is_scalar( $params[ $key ] ) ) {
			$value = sanitize_text_field( (string) $params[ $key ] );
			if ( '' !== $value ) {
				$out[ $key ] = $value;
			}
		}
	}

	return $out;
}

/**
 * Best-effort current request URL (path + query).
 *
 * @return string
 */
function freya_applovin_current_request_url() {
	$uri = isset( $_SERVER['REQUEST_URI'] ) ? (string) wp_unslash( $_SERVER['REQUEST_URI'] ) : '';
	if ( '' === $uri ) {
		return '';
	}

	$home = home_url( '/' );
	$parts = wp_parse_url( $home );
	$scheme = ! empty( $parts['scheme'] ) ? $parts['scheme'] : ( is_ssl() ? 'https' : 'http' );
	$host   = ! empty( $parts['host'] ) ? $parts['host'] : ( isset( $_SERVER['HTTP_HOST'] ) ? (string) wp_unslash( $_SERVER['HTTP_HOST'] ) : '' );

	if ( '' === $host ) {
		return $uri;
	}

	return $scheme . '://' . $host . $uri;
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
		'aleid'             => freya_applovin_resolve_identifier( 'aleid' ),
		'alart'             => freya_applovin_resolve_identifier( 'alart' ),
		'client_id'         => freya_applovin_resolve_identifier( 'client_id' ),
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
		'aleid'             => freya_applovin_resolve_identifier( 'aleid' ),
		'alart'             => freya_applovin_resolve_identifier( 'alart' ),
		'client_id'         => freya_applovin_resolve_identifier( 'client_id' ),
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

/**
 * Stash click IDs into the checkout recovery payload.
 *
 * @param array<string, mixed> $payload Recovery payload.
 * @return array<string, mixed>
 */
function freya_applovin_filter_recovery_payload( $payload ) {
	if ( ! is_array( $payload ) ) {
		return $payload;
	}

	$ids = freya_applovin_normalize_id_map(
		array(
			'aleid'     => freya_applovin_resolve_identifier( 'aleid' ),
			'alart'     => freya_applovin_resolve_identifier( 'alart' ),
			'client_id' => freya_applovin_resolve_identifier( 'client_id' ),
		)
	);

	// Preserve previously stored click IDs when the current browser has none.
	if ( empty( $ids['aleid'] ) && empty( $ids['alart'] ) && ! empty( $payload['applovin'] ) && is_array( $payload['applovin'] ) ) {
		$ids = freya_applovin_normalize_id_map( $payload['applovin'] ) + $ids;
	}

	if ( ! empty( $ids ) ) {
		$payload['applovin'] = $ids;
	}

	return $payload;
}

/**
 * Restore click IDs from a recovery payload into cookies + session.
 *
 * @param array<string, mixed> $payload Restored recovery payload.
 * @return void
 */
function freya_applovin_on_recovery_restored( $payload ) {
	if ( ! is_array( $payload ) || empty( $payload['applovin'] ) || ! is_array( $payload['applovin'] ) ) {
		return;
	}

	$ids = freya_applovin_normalize_id_map( $payload['applovin'] );
	if ( empty( $ids ) ) {
		return;
	}

	foreach ( array(
		'aleid'     => FREYA_APPLOVIN_COOKIE_ALEID,
		'alart'     => FREYA_APPLOVIN_COOKIE_ALART,
		'client_id' => FREYA_APPLOVIN_COOKIE_CLIENT_ID,
	) as $key => $cookie ) {
		if ( empty( $ids[ $key ] ) ) {
			continue;
		}
		// Do not clobber a fresher cookie already present in this browser.
		if ( '' === freya_applovin_get_cookie( $cookie ) ) {
			freya_applovin_set_cookie( $cookie, $ids[ $key ] );
		}
	}

	freya_applovin_persist_click_ids( $ids, false );
}

/**
 * When a user logs in, copy any session click IDs onto their account.
 *
 * @param string  $user_login Username.
 * @param WP_User $user       User object.
 * @return void
 */
function freya_applovin_on_user_login( $user_login, $user ) {
	unset( $user_login );

	if ( ! $user instanceof WP_User || $user->ID <= 0 ) {
		return;
	}

	$ids = freya_applovin_normalize_id_map(
		array(
			'aleid'     => freya_applovin_resolve_identifier( 'aleid' ),
			'alart'     => freya_applovin_resolve_identifier( 'alart' ),
			'client_id' => freya_applovin_resolve_identifier( 'client_id' ),
		)
	);

	if ( ! empty( $ids ) ) {
		freya_applovin_persist_user_ids( (int) $user->ID, $ids, true );
		freya_applovin_persist_click_ids( $ids, true );
	}
}

/**
 * Persist click IDs onto a Gravity Forms entry (and user meta when possible).
 *
 * Runs even when Conversion API credentials are missing, so order attribution
 * can recover the IDs later via billing email.
 *
 * @param array $entry Gravity Forms entry.
 * @param array $form  Gravity Forms form.
 * @return void
 */
function freya_applovin_persist_entry_identifiers( $entry, $form ) {
	unset( $form );

	$entry_id = (int) rgar( $entry, 'id' );
	if ( $entry_id <= 0 || ! function_exists( 'gform_update_meta' ) ) {
		return;
	}

	// Prefer query params on the form source_url, then durable resolvers.
	$from_source = freya_applovin_parse_click_ids_from_url( (string) rgar( $entry, 'source_url' ) );

	$ids = freya_applovin_normalize_id_map(
		array(
			'aleid'     => ! empty( $from_source['aleid'] ) ? $from_source['aleid'] : freya_applovin_resolve_identifier( 'aleid' ),
			'alart'     => ! empty( $from_source['alart'] ) ? $from_source['alart'] : freya_applovin_resolve_identifier( 'alart' ),
			'client_id' => freya_applovin_resolve_identifier( 'client_id' ),
		)
	);

	if ( empty( $ids ) ) {
		return;
	}

	// Re-persist so cookies/session catch IDs that only lived on source_url.
	if ( ! empty( $ids['aleid'] ) ) {
		freya_applovin_set_cookie( FREYA_APPLOVIN_COOKIE_ALEID, $ids['aleid'] );
	}
	if ( ! empty( $ids['alart'] ) ) {
		freya_applovin_set_cookie( FREYA_APPLOVIN_COOKIE_ALART, $ids['alart'] );
	}
	freya_applovin_persist_click_ids( $ids, true );

	$form_id = (int) rgar( $entry, 'form_id' );
	foreach ( array(
		'aleid'     => FREYA_APPLOVIN_META_ENTRY_ALEID,
		'alart'     => FREYA_APPLOVIN_META_ENTRY_ALART,
		'client_id' => FREYA_APPLOVIN_META_ENTRY_CLIENT_ID,
	) as $key => $meta_key ) {
		if ( ! empty( $ids[ $key ] ) ) {
			gform_update_meta( $entry_id, $meta_key, $ids[ $key ], $form_id );
		}
	}

	$created_by = (int) rgar( $entry, 'created_by' );
	if ( $created_by > 0 ) {
		freya_applovin_persist_user_ids( $created_by, $ids, true );
	}
}
