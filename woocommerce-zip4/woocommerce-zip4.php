<?php
/**
 * Plugin Name:       WooCommerce ZIP+4
 * Description:       Standardizes USA mailing addresses to USPS ZIP+4 format on orders and subscriptions.
 * Version:           1.0.0
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Author:            Ken Gagne
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       woocommerce-zip4
 *
 * @package WooCommerce_Zip4
 */

defined( 'ABSPATH' ) || exit;

define( 'WC_ZIP4_VERSION', '1.0.0' );
define( 'WC_ZIP4_PLUGIN_FILE', __FILE__ );
define( 'WC_ZIP4_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'WC_ZIP4_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

require_once WC_ZIP4_PLUGIN_DIR . 'includes/class-wc-zip4-credentials.php';
require_once WC_ZIP4_PLUGIN_DIR . 'includes/class-wc-zip4-api.php';
require_once WC_ZIP4_PLUGIN_DIR . 'includes/class-wc-zip4-standardizer.php';
require_once WC_ZIP4_PLUGIN_DIR . 'includes/class-wc-zip4-subscriptions.php';
require_once WC_ZIP4_PLUGIN_DIR . 'includes/class-wc-zip4-hooks.php';
require_once WC_ZIP4_PLUGIN_DIR . 'includes/class-wc-zip4-admin.php';

/**
 * Main plugin bootstrap.
 */
final class WooCommerce_Zip4 {

	/**
	 * Plugin instance.
	 *
	 * @var WooCommerce_Zip4|null
	 */
	private static $instance = null;

	/**
	 * Get plugin instance.
	 *
	 * @return WooCommerce_Zip4
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Constructor.
	 */
	private function __construct() {
		add_action( 'plugins_loaded', array( $this, 'init' ) );
	}

	/**
	 * Initialize plugin components after WooCommerce loads.
	 */
	public function init() {
		if ( ! class_exists( 'WooCommerce' ) ) {
			return;
		}

		WC_Zip4_Hooks::init();
		WC_Zip4_Admin::init();
	}

	/**
	 * Whether WooCommerce Subscriptions is active.
	 *
	 * @return bool
	 */
	public static function is_subscriptions_active() {
		return class_exists( 'WC_Subscriptions' );
	}
}

WooCommerce_Zip4::instance();
