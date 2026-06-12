<?php
/**
 * Resolve USPS OAuth credentials for ZIP+4 scripts.
 *
 * Priority:
 * 1. WooCommerce USPS Shipping Method plugin (active, REST API configured)
 * 2. wp-config.php constants USPS_CLIENT_ID and USPS_CLIENT_SECRET
 *
 * @return array{client_id: string, client_secret: string}|null
 */
function zip4_get_usps_credentials() {
	static $credentials = null;
	static $resolved    = false;

	if ( $resolved ) {
		return $credentials;
	}

	$resolved = true;

	$plugin_credentials = zip4_get_usps_credentials_from_wc_plugin();
	if ( $plugin_credentials ) {
		$credentials = $plugin_credentials;
		return $credentials;
	}

	if ( defined( 'USPS_CLIENT_ID' ) && defined( 'USPS_CLIENT_SECRET' ) ) {
		$client_id     = trim( (string) USPS_CLIENT_ID );
		$client_secret = trim( (string) USPS_CLIENT_SECRET );

		if ( $client_id !== '' && $client_secret !== '' ) {
			$credentials = array(
				'client_id'     => $client_id,
				'client_secret' => $client_secret,
			);
			return $credentials;
		}
	}

	$credentials = null;
	return null;
}

/**
 * Read credentials from the official WooCommerce USPS Shipping Method plugin.
 *
 * @return array{client_id: string, client_secret: string}|null
 */
function zip4_get_usps_credentials_from_wc_plugin() {
	if ( ! function_exists( 'is_plugin_active' ) ) {
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
	}

	if ( ! is_plugin_active( 'woocommerce-shipping-usps/woocommerce-shipping-usps.php' ) ) {
		return null;
	}

	if ( ! class_exists( 'WC_Shipping_USPS' ) && did_action( 'woocommerce_shipping_init' ) === 0 ) {
		do_action( 'woocommerce_shipping_init' );
	}

	if ( class_exists( 'WC_Shipping_USPS' ) ) {
		$usps = new WC_Shipping_USPS();

		$api_type = $usps->get_option( 'api_type', '' );
		if ( $api_type && $api_type !== 'rest' ) {
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
	if ( $api_type && $api_type !== 'rest' ) {
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
