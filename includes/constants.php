<?php
/**
 * Plugin constants.
 *
 * @package FreyaAppLovin
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Axon Event API endpoint. */
define( 'FREYA_APPLOVIN_ENDPOINT', 'https://b.applovin.com/v1/event' );

/** Action Scheduler group name. */
define( 'FREYA_APPLOVIN_AS_GROUP', 'freya-applovin' );

/** Action Scheduler hook: send a single generate_lead event. */
define( 'FREYA_APPLOVIN_HOOK_SEND_LEAD', 'freya_applovin_send_lead' );

/** Valid event names accepted by the restricted lead-generation flow. */
define( 'FREYA_APPLOVIN_EVENT_PAGE_VIEW', 'page_view' );
define( 'FREYA_APPLOVIN_EVENT_GENERATE_LEAD', 'generate_lead' );

/** Event source identifier ("web" or "app"). */
define( 'FREYA_APPLOVIN_ESI', 'web' );

/**
 * Cookie used to persist the AppLovin aleid click identifier.
 *
 * AppLovin recommends naming this cookie `_axeid` with a one-year expiration.
 */
define( 'FREYA_APPLOVIN_COOKIE_ALEID', '_axeid' );

/** Cookie used to persist the alart click identifier. */
define( 'FREYA_APPLOVIN_COOKIE_ALART', '_axart' );

/** Cookie used to persist a stable first-party visitor identifier (client_id). */
define( 'FREYA_APPLOVIN_COOKIE_CLIENT_ID', '_axcid' );

/** Lifetime (seconds) for AppLovin identifier cookies. */
define( 'FREYA_APPLOVIN_COOKIE_LIFETIME', YEAR_IN_SECONDS );

/** HTTP timeout (seconds) for blocking Axon Event API requests. */
define( 'FREYA_APPLOVIN_HTTP_TIMEOUT', 15 );

/** Entry meta key: timestamp the generate_lead event was sent to AppLovin (legacy GF flow). */
define( 'FREYA_APPLOVIN_META_SENT_AT', 'freya_applovin_sent_at' );

/** Order meta key: timestamp the generate_lead event was sent for a new-customer purchase. */
define( 'FREYA_APPLOVIN_META_ORDER_LEAD_SENT_AT', '_freya_applovin_lead_sent_at' );

/** Order meta key: AppLovin aleid click identifier captured at checkout. */
define( 'FREYA_APPLOVIN_META_ORDER_ALEID', '_freya_applovin_aleid' );

/** Order meta key: AppLovin alart click identifier captured at checkout. */
define( 'FREYA_APPLOVIN_META_ORDER_ALART', '_freya_applovin_alart' );

/** Order meta key: stable first-party client identifier captured at checkout. */
define( 'FREYA_APPLOVIN_META_ORDER_CLIENT_ID', '_freya_applovin_client_id' );

/** User meta: last AppLovin aleid click identifier for the account. */
define( 'FREYA_APPLOVIN_META_USER_ALEID', '_freya_applovin_aleid' );

/** User meta: last AppLovin alart click identifier for the account. */
define( 'FREYA_APPLOVIN_META_USER_ALART', '_freya_applovin_alart' );

/** User meta: stable first-party client identifier for the account. */
define( 'FREYA_APPLOVIN_META_USER_CLIENT_ID', '_freya_applovin_client_id' );

/** Gravity Forms entry meta: aleid captured at lead submission. */
define( 'FREYA_APPLOVIN_META_ENTRY_ALEID', 'freya_applovin_aleid' );

/** Gravity Forms entry meta: alart captured at lead submission. */
define( 'FREYA_APPLOVIN_META_ENTRY_ALART', 'freya_applovin_alart' );

/** Gravity Forms entry meta: client_id captured at lead submission. */
define( 'FREYA_APPLOVIN_META_ENTRY_CLIENT_ID', 'freya_applovin_client_id' );

/** WooCommerce session key for durable click / visitor identifiers. */
define( 'FREYA_APPLOVIN_SESSION_KEY', 'freya_applovin_ids' );

/** How long (seconds) to keep click IDs in WC session / user meta lookups. */
define( 'FREYA_APPLOVIN_CLICK_ID_TTL', 30 * DAY_IN_SECONDS );
