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

/** Entry meta key: timestamp the generate_lead event was sent to AppLovin. */
define( 'FREYA_APPLOVIN_META_SENT_AT', 'freya_applovin_sent_at' );
