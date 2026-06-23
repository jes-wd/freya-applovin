<?php
/**
 * Plugin Name: Freya AppLovin Conversion API
 * Plugin URI:  https://freyameds.com
 * Description: Sends server-to-server (S2S) page_view and generate_lead events to the AppLovin Axon Event API (restricted lead-generation flow).
 * Version:     1.0.0
 * Author:      Freya Meds
 * Author URI:  https://freyameds.com
 * License:     GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: freya-applovin
 * Requires PHP: 7.4
 *
 * @package FreyaAppLovin
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * AppLovin Conversion API key (sent as the Authorization header).
 *
 * Replace the placeholder with the value from your AppLovin dashboard.
 */
define( 'FREYA_APPLOVIN_API_KEY', 'ak_0o474k0s2i354s3s15492u2v543c1l302z1b2a2q6f3z4v0q2p672u1p0s28222x' );

/**
 * Axon event key, sent as the required `pixel_id` query parameter.
 *
 * Replace the placeholder with the value from your AppLovin dashboard.
 */
define( 'FREYA_APPLOVIN_PIXEL_ID', 'b9f5f376-789b-412f-939c-fef73409c826' );

/**
 * Gravity Forms form IDs that should fire a generate_lead event.
 *
 * Use an empty array to track every form, or list specific IDs, e.g. array( 5, 12 ).
 * Can also be overridden per request via the `freya_applovin_lead_form_ids` filter.
 */
define( 'FREYA_APPLOVIN_LEAD_FORM_IDS', array() );

/** Default ISO 4217 currency reported with generate_lead events. */
define( 'FREYA_APPLOVIN_DEFAULT_CURRENCY', 'USD' );

/** Default monetary value reported with generate_lead events. */
define( 'FREYA_APPLOVIN_DEFAULT_LEAD_VALUE', 0 );

define( 'FREYA_APPLOVIN_VERSION', '1.0.0' );
define( 'FREYA_APPLOVIN_FILE', __FILE__ );
define( 'FREYA_APPLOVIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'FREYA_APPLOVIN_URL', plugin_dir_url( __FILE__ ) );

require_once FREYA_APPLOVIN_DIR . 'includes/constants.php';
require_once FREYA_APPLOVIN_DIR . 'includes/dependencies.php';
require_once FREYA_APPLOVIN_DIR . 'includes/identifiers.php';
require_once FREYA_APPLOVIN_DIR . 'includes/api.php';
require_once FREYA_APPLOVIN_DIR . 'includes/events.php';
require_once FREYA_APPLOVIN_DIR . 'includes/scheduler.php';
require_once FREYA_APPLOVIN_DIR . 'includes/hooks.php';

register_activation_hook( __FILE__, 'freya_applovin_activate' );
register_deactivation_hook( __FILE__, 'freya_applovin_deactivate' );

add_action( 'plugins_loaded', 'freya_applovin_bootstrap', 20 );
