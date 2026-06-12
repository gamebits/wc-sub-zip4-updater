<?php
/**
 * USPS credential resolution.
 *
 * @package WooCommerce_Zip4
 */

defined( 'ABSPATH' ) || exit;

/**
 * Resolves USPS OAuth credentials for API requests.
 */
class WC_Zip4_Credentials {

	/**
	 * Cached credentials.
	 *
	 * @var array{client_id: string, client_secret: string}|null|false
	 */
	private static $credentials = false;

	/**
	 * Get USPS OAuth credentials.
	 *
	 * Priority:
	 * 1. WooCommerce USPS Shipping Method plugin (active, REST configured)
	 * 2. wp-config.php constants USPS_CLIENT_ID and USPS_CLIENT_SECRET
	 *
	 * @return array{client_id: string, client_secret: string}|null
	 */
	public static function get() {
		if ( false !== self::$credentials ) {
			return self::$credentials;
		}

		$plugin_credentials = self::from_wc_usps_plugin();
		if ( $plugin_credentials ) {
			self::$credentials = $plugin_credentials;
			return self::$credentials;
		}

		if ( defined( 'USPS_CLIENT_ID' ) && defined( 'USPS_CLIENT_SECRET' ) ) {
			$client_id     = trim( (string) USPS_CLIENT_ID );
			$client_secret = trim( (string) USPS_CLIENT_SECRET );

			if ( $client_id !== '' && $client_secret !== '' ) {
				self::$credentials = array(
					'client_id'     => $client_id,
					'client_secret' => $client_secret,
				);
				return self::$credentials;
			}
		}

		self::$credentials = null;
		return null;
	}

	/**
	 * Whether credentials are configured.
	 *
	 * @return bool
	 */
	public static function is_configured() {
		return null !== self::get();
	}

	/**
	 * Read credentials from the WooCommerce USPS Shipping Method plugin.
	 *
	 * @return array{client_id: string, client_secret: string}|null
	 */
	private static function from_wc_usps_plugin() {
		if ( ! function_exists( 'is_plugin_active' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		if ( ! is_plugin_active( 'woocommerce-shipping-usps/woocommerce-shipping-usps.php' ) ) {
			return null;
		}

		if ( ! class_exists( 'WC_Shipping_USPS' ) && 0 === did_action( 'woocommerce_shipping_init' ) ) {
			do_action( 'woocommerce_shipping_init' );
		}

		if ( class_exists( 'WC_Shipping_USPS' ) ) {
			$usps = new WC_Shipping_USPS();

			$api_type = $usps->get_option( 'api_type', '' );
			if ( $api_type && 'rest' !== $api_type ) {
				return null;
			}

			$client_id     = trim( (string) $usps->get_option( 'rest_api_key', '' ) );
			$client_secret = trim( (string) $usps->get_option( 'rest_api_secret', '' ) );

			if ( $client_id !== '' && $client_secret !== '' ) {
				return array(
					'client_id'     => $client_id,
					'client_secret' => $client_secret,
				);
			}
		}

		$settings = get_option( 'woocommerce_usps_settings', array() );
		if ( ! is_array( $settings ) ) {
			return null;
		}

		$api_type = $settings['api_type'] ?? '';
		if ( $api_type && 'rest' !== $api_type ) {
			return null;
		}

		$client_id     = trim( (string) ( $settings['rest_api_key'] ?? '' ) );
		$client_secret = trim( (string) ( $settings['rest_api_secret'] ?? '' ) );

		if ( $client_id === '' || $client_secret === '' ) {
			return null;
		}

		return array(
			'client_id'     => $client_id,
			'client_secret' => $client_secret,
		);
	}
}
