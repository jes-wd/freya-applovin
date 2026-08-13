<?php
/**
 * WooCommerce order attribution.
 *
 * Captures the AppLovin visitor identifiers (aleid / alart / client_id) onto
 * each order at checkout so purchases can be reliably attributed to AppLovin
 * later, independent of the client IP (which is masked by iCloud Private Relay
 * and CDN/carrier egress for most mobile traffic).
 *
 * Resolution order for each identifier:
 * cookie → WC session → user meta → recovery payload → GF entry → order URLs.
 *
 * @package FreyaAppLovin
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Register the order-capture hooks.
 *
 * Runs regardless of whether the Conversion API credentials are configured, so
 * the attribution data is always recorded for reporting.
 *
 * @return void
 */
function freya_applovin_register_order_hooks() {
	if ( ! freya_applovin_has_woocommerce() ) {
		return;
	}

	// Classic / shortcode checkout: fires while the order is being built, before save.
	add_action( 'woocommerce_checkout_create_order', 'freya_applovin_capture_order_identifiers', 20, 2 );

	// Block / Store API checkout: fires while the order is populated from the request.
	add_action( 'woocommerce_store_api_checkout_update_order_from_request', 'freya_applovin_capture_order_identifiers', 20, 2 );

	// Late pass after other plugins may have written landing / attribution URLs.
	add_action( 'woocommerce_checkout_order_processed', 'freya_applovin_capture_order_identifiers_after_processed', 30, 1 );
	add_action( 'woocommerce_store_api_checkout_order_processed', 'freya_applovin_capture_order_identifiers_after_processed', 30, 1 );
}

/**
 * Register hooks that queue generate_lead for successful new-customer purchases.
 *
 * @return void
 */
function freya_applovin_register_purchase_lead_hooks() {
	if ( ! freya_applovin_has_woocommerce() ) {
		return;
	}

	add_action( 'woocommerce_payment_complete', 'freya_applovin_queue_purchase_lead', 40, 1 );
	add_action( 'woocommerce_order_status_processing', 'freya_applovin_queue_purchase_lead', 40, 1 );
	add_action( 'woocommerce_order_status_completed', 'freya_applovin_queue_purchase_lead', 40, 1 );
}

/**
 * Stamp identifiers after the order is fully processed (and saved).
 *
 * @param int|WC_Order $order Order ID or object.
 * @return void
 */
function freya_applovin_capture_order_identifiers_after_processed( $order ) {
	if ( is_numeric( $order ) ) {
		$order = wc_get_order( (int) $order );
	}

	if ( ! $order instanceof WC_Order ) {
		return;
	}

	freya_applovin_capture_order_identifiers( $order, null );

	// Ensure late-resolved meta is persisted.
	$order->save();
}

/**
 * Stamp the current visitor's AppLovin identifiers onto an order.
 *
 * @param WC_Order $order   Order being created at checkout.
 * @param mixed    $context Checkout data array or Store API request (unused).
 * @return void
 */
function freya_applovin_capture_order_identifiers( $order, $context = null ) {
	unset( $context );

	if ( ! $order instanceof WC_Order ) {
		return;
	}

	$map = array(
		FREYA_APPLOVIN_META_ORDER_ALEID     => 'aleid',
		FREYA_APPLOVIN_META_ORDER_ALART     => 'alart',
		FREYA_APPLOVIN_META_ORDER_CLIENT_ID => 'client_id',
	);

	$resolved = array();

	foreach ( $map as $meta_key => $id_key ) {
		// Do not overwrite a value already captured (e.g. on order re-save).
		if ( '' !== (string) $order->get_meta( $meta_key ) ) {
			continue;
		}

		$value = freya_applovin_resolve_identifier( $id_key, $order );

		if ( '' !== $value ) {
			$order->update_meta_data( $meta_key, $value );
			$resolved[ $id_key ] = $value;
		}
	}

	// Keep durable stores warm for subsequent requests / renewals.
	if ( ! empty( $resolved ) ) {
		freya_applovin_persist_click_ids( $resolved, false );

		$user_id = (int) $order->get_user_id();
		if ( $user_id > 0 ) {
			freya_applovin_persist_user_ids( $user_id, $resolved, false );
		}
	}
}
