<?php
/**
 * Plugin Name: Freya AppLovin Conversion API
 * Plugin URI:  https://freyameds.com
 * Description: Sends server-to-server (S2S) page_view and generate_lead events to the AppLovin Axon Event API (restricted lead-generation flow). generate_lead fires on successful new-customer purchases.
 * Version:     1.1.0
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
 * AppLovin Conversion API key + Axon pixel_id — define in wp-config.php.
 */
if ( ! defined( 'FREYA_APPLOVIN_API_KEY' ) ) {
	define( 'FREYA_APPLOVIN_API_KEY', '' );
}
if ( ! defined( 'FREYA_APPLOVIN_PIXEL_ID' ) ) {
	define( 'FREYA_APPLOVIN_PIXEL_ID', '' );
}

/** Default ISO 4217 currency reported with generate_lead events (fallback). */
define( 'FREYA_APPLOVIN_DEFAULT_CURRENCY', 'USD' );

/** Default monetary value reported with generate_lead events (fallback; purchases use order total). */
define( 'FREYA_APPLOVIN_DEFAULT_LEAD_VALUE', 0 );

define( 'FREYA_APPLOVIN_VERSION', '1.1.0' );
define( 'FREYA_APPLOVIN_FILE', __FILE__ );
define( 'FREYA_APPLOVIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'FREYA_APPLOVIN_URL', plugin_dir_url( __FILE__ ) );

require_once FREYA_APPLOVIN_DIR . 'includes/constants.php';
require_once FREYA_APPLOVIN_DIR . 'includes/dependencies.php';
require_once FREYA_APPLOVIN_DIR . 'includes/identifiers.php';
require_once FREYA_APPLOVIN_DIR . 'includes/client-capture.php';
require_once FREYA_APPLOVIN_DIR . 'includes/api.php';
require_once FREYA_APPLOVIN_DIR . 'includes/events.php';
require_once FREYA_APPLOVIN_DIR . 'includes/orders.php';
require_once FREYA_APPLOVIN_DIR . 'includes/scheduler.php';
require_once FREYA_APPLOVIN_DIR . 'includes/hooks.php';

register_activation_hook( __FILE__, 'freya_applovin_activate' );
register_deactivation_hook( __FILE__, 'freya_applovin_deactivate' );

add_action( 'plugins_loaded', 'freya_applovin_bootstrap', 20 );
