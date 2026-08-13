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
 * Whether an order is a successful paid purchase eligible for generate_lead.
 *
 * @param WC_Order $order Order object.
 * @return bool
 */
function freya_applovin_order_is_successful_purchase( $order ) {
	if ( ! $order instanceof WC_Order || 'shop_order' !== $order->get_type() ) {
		return false;
	}

	$status = $order->get_status();
	$paid   = array( 'processing', 'completed' );

	/**
	 * Filter which order statuses count as a successful purchase for generate_lead.
	 *
	 * @param string[] $paid  Statuses without the wc- prefix.
	 * @param WC_Order $order Order object.
	 */
	$paid = (array) apply_filters( 'freya_applovin_purchase_lead_statuses', $paid, $order );

	return in_array( $status, $paid, true );
}

/**
 * Whether the order is a new-customer first purchase.
 *
 * Prefers Freya Core `is_new_customer` meta / helpers when available.
 *
 * @param WC_Order $order Order object.
 * @return bool
 */
function freya_applovin_order_is_new_customer( $order ) {
	if ( ! $order instanceof WC_Order ) {
		return false;
	}

	if ( function_exists( 'freya_order_is_subscription_renewal' ) && freya_order_is_subscription_renewal( $order ) ) {
		return false;
	}

	if ( function_exists( 'freya_order_ensure_customer_type_meta' ) ) {
		$values = freya_order_ensure_customer_type_meta( $order );
		return isset( $values['is_new_customer'] ) && 'yes' === $values['is_new_customer'];
	}

	$flag = $order->get_meta( 'is_new_customer', true );
	if ( in_array( $flag, array( 'yes', 'no' ), true ) ) {
		return 'yes' === $flag;
	}

	if ( function_exists( 'freya_order_compute_is_new_customer' ) ) {
		return (bool) freya_order_compute_is_new_customer( $order );
	}

	// Fallback: no prior shop_order for this billing email (excl. renewals via meta).
	if ( $order->get_meta( '_subscription_renewal' ) || $order->get_meta( '_subscription_renewal_early' ) ) {
		return false;
	}

	$email = strtolower( trim( (string) $order->get_billing_email() ) );
	if ( '' === $email || ! is_email( $email ) || ! function_exists( 'wc_get_orders' ) ) {
		return false;
	}

	$prior = wc_get_orders(
		array(
			'type'          => 'shop_order',
			'billing_email' => $email,
			'status'        => array( 'processing', 'completed', 'pending', 'on-hold', 'refunded' ),
			'limit'         => 1,
			'return'        => 'ids',
			'exclude'       => array( (int) $order->get_id() ),
		)
	);

	return empty( $prior );
}

/**
 * Whether a purchase should fire generate_lead.
 *
 * @param WC_Order $order Order object.
 * @return bool
 */
function freya_applovin_should_track_purchase_lead( $order ) {
	if ( ! $order instanceof WC_Order ) {
		return false;
	}

	if ( '' !== (string) $order->get_meta( FREYA_APPLOVIN_META_ORDER_LEAD_SENT_AT ) ) {
		return false;
	}

	$track = freya_applovin_order_is_successful_purchase( $order )
		&& freya_applovin_order_is_new_customer( $order );

	/**
	 * Filter whether a given purchase should fire generate_lead.
	 *
	 * @param bool     $track Whether to track.
	 * @param WC_Order $order Order object.
	 */
	return (bool) apply_filters( 'freya_applovin_track_purchase_lead', $track, $order );
}

/**
 * Resolve the monetary value for a purchase generate_lead event.
 *
 * @param WC_Order $order Order object.
 * @return float
 */
function freya_applovin_get_purchase_lead_value( $order ) {
	$value = $order instanceof WC_Order ? (float) $order->get_total() : 0.0;

	/**
	 * Filter the generate_lead value for a purchase.
	 *
	 * @param float    $value Order total.
	 * @param WC_Order $order Order object.
	 */
	return (float) apply_filters( 'freya_applovin_purchase_lead_value', $value, $order );
}

/**
 * Resolve the currency for a purchase generate_lead event.
 *
 * @param WC_Order $order Order object.
 * @return string
 */
function freya_applovin_get_purchase_lead_currency( $order ) {
	$currency = $order instanceof WC_Order ? (string) $order->get_currency() : '';
	if ( '' === $currency ) {
		$currency = defined( 'FREYA_APPLOVIN_DEFAULT_CURRENCY' ) ? FREYA_APPLOVIN_DEFAULT_CURRENCY : 'USD';
	}

	/**
	 * Filter the generate_lead currency (ISO 4217) for a purchase.
	 *
	 * @param string   $currency Currency code.
	 * @param WC_Order $order    Order object.
	 */
	return (string) apply_filters( 'freya_applovin_purchase_lead_currency', $currency, $order );
}

/**
 * Snapshot AppLovin user_data from an order (safe for async payment webhooks).
 *
 * @param WC_Order $order Order object.
 * @return array
 */
function freya_applovin_snapshot_user_data_from_order( $order ) {
	if ( ! $order instanceof WC_Order ) {
		return freya_applovin_snapshot_user_data();
	}

	$ip = (string) $order->get_customer_ip_address();
	if ( '' === $ip ) {
		$ip = freya_applovin_get_client_ip();
	}

	$ua = (string) $order->get_customer_user_agent();
	if ( '' === $ua ) {
		$ua = freya_applovin_get_user_agent();
	}

	$user_id = (int) $order->get_user_id();

	return array(
		'client_ip_address' => $ip,
		'client_user_agent' => $ua,
		'aleid'             => (string) $order->get_meta( FREYA_APPLOVIN_META_ORDER_ALEID ),
		'alart'             => (string) $order->get_meta( FREYA_APPLOVIN_META_ORDER_ALART ),
		'client_id'         => (string) $order->get_meta( FREYA_APPLOVIN_META_ORDER_CLIENT_ID ),
		'user_id'           => $user_id > 0 ? (string) $user_id : '',
		'esi'               => FREYA_APPLOVIN_ESI,
	);
}

/**
 * Queue a generate_lead event for a successful new-customer purchase.
 *
 * @param int|WC_Order $order Order ID or object.
 * @return void
 */
function freya_applovin_queue_purchase_lead( $order ) {
	if ( ! freya_applovin_is_configured() || ! freya_applovin_has_action_scheduler() ) {
		return;
	}

	if ( is_numeric( $order ) ) {
		$order = wc_get_order( (int) $order );
	}

	if ( ! $order instanceof WC_Order || ! freya_applovin_should_track_purchase_lead( $order ) ) {
		return;
	}

	// Ensure identifiers are stamped before snapshotting (checkout may have skipped).
	freya_applovin_capture_order_identifiers( $order, null );

	$order_id = (int) $order->get_id();
	if ( $order_id <= 0 ) {
		return;
	}

	$args = array(
		array(
			'order_id'   => $order_id,
			'value'      => freya_applovin_get_purchase_lead_value( $order ),
			'currency'   => freya_applovin_get_purchase_lead_currency( $order ),
			'user_data'  => freya_applovin_snapshot_user_data_from_order( $order ),
			'source_url' => freya_applovin_get_event_source_url(),
			'event_time' => freya_applovin_event_time_ms(),
		),
	);

	if ( as_has_scheduled_action( FREYA_APPLOVIN_HOOK_SEND_LEAD, $args, FREYA_APPLOVIN_AS_GROUP ) ) {
		return;
	}

	// Soft-lock so payment_complete + status hooks do not double-enqueue.
	$order->update_meta_data( FREYA_APPLOVIN_META_ORDER_LEAD_SENT_AT, 'queued:' . gmdate( 'c' ) );
	$order->save_meta_data();

	as_enqueue_async_action( FREYA_APPLOVIN_HOOK_SEND_LEAD, $args, FREYA_APPLOVIN_AS_GROUP );
}

/**
 * Action Scheduler callback: send a queued generate_lead event.
 *
 * @param array $payload Snapshot captured at purchase (or legacy GF submission) time.
 * @return void
 * @throws Exception When the API call fails so Action Scheduler can retry.
 */
function freya_applovin_process_lead( $payload ) {
	if ( ! is_array( $payload ) ) {
		return;
	}

	$order_id = (int) ( $payload['order_id'] ?? 0 );
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

	if ( $order_id > 0 ) {
		$event['dedupe_id'] = 'order_lead_' . $order_id;
	} elseif ( $entry_id > 0 ) {
		// Legacy in-flight GF jobs.
		$event['dedupe_id'] = 'gf_lead_' . $entry_id;
	}

	$result = freya_applovin_send_events( array( $event ), true );

	if ( is_wp_error( $result ) ) {
		if ( $order_id > 0 ) {
			$order = wc_get_order( $order_id );
			if ( $order instanceof WC_Order ) {
				$order->delete_meta_data( FREYA_APPLOVIN_META_ORDER_LEAD_SENT_AT );
				$order->save_meta_data();
			}
		}

		if ( $entry_id > 0 && function_exists( 'gform_update_meta' ) ) {
			gform_update_meta( $entry_id, FREYA_APPLOVIN_META_SENT_AT, '', $form_id );
		}

		throw new Exception( 'Freya AppLovin generate_lead failed: ' . $result->get_error_message() );
	}

	if ( $order_id > 0 ) {
		$order = wc_get_order( $order_id );
		if ( $order instanceof WC_Order ) {
			$order->update_meta_data( FREYA_APPLOVIN_META_ORDER_LEAD_SENT_AT, gmdate( 'c' ) );
			$order->save_meta_data();
		}
	}

	if ( $entry_id > 0 && function_exists( 'gform_update_meta' ) ) {
		gform_update_meta( $entry_id, FREYA_APPLOVIN_META_SENT_AT, gmdate( 'c' ), $form_id );
	}
}
