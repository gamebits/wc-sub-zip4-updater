<?php
/**
 * USPS Address API client.
 *
 * @package WooCommerce_Zip4
 */

defined( 'ABSPATH' ) || exit;

/**
 * Handles USPS OAuth and address validation requests.
 */
class WC_Zip4_API {

	const TOKEN_TRANSIENT = 'wc_zip4_usps_access_token';
	const TOKEN_TTL       = 3300;

	/**
	 * Look up a standardized ZIP+4 for an address.
	 *
	 * @param string $addr1   Street address line 1.
	 * @param string $addr2   Street address line 2.
	 * @param string $city    City.
	 * @param string $state   State code.
	 * @param string $zip     Five-digit ZIP code.
	 * @param int    $timeout Request timeout in seconds.
	 * @return string|null ZIP+4 string on success, null on failure.
	 */
	public static function lookup_zip4( $addr1, $addr2, $city, $state, $zip, $timeout = 7 ) {
		$access_token = self::get_access_token();
		if ( ! $access_token ) {
			return null;
		}

		$query_args = array(
			'streetAddress'    => str_replace( '.', '', $addr1 ),
			'secondaryAddress' => str_replace( '#', '', $addr2 ),
			'city'             => $city,
			'state'            => $state,
			'ZIPCode'          => $zip,
		);

		$response = wp_remote_get(
			add_query_arg( $query_args, 'https://apis.usps.com/addresses/v3/address' ),
			array(
				'headers' => array(
					'Authorization' => 'Bearer ' . $access_token,
					'Accept'        => 'application/json',
				),
				'timeout' => $timeout,
			)
		);

		if ( is_wp_error( $response ) ) {
			return null;
		}

		$result = json_decode( wp_remote_retrieve_body( $response ) );
		if ( isset( $result->address->ZIPPlus4 ) && ! empty( $result->address->ZIPPlus4 ) ) {
			return $result->address->ZIPCode . '-' . $result->address->ZIPPlus4;
		}

		return null;
	}

	/**
	 * Get a cached or fresh OAuth access token.
	 *
	 * @return string|null
	 */
	public static function get_access_token() {
		$cached = get_transient( self::TOKEN_TRANSIENT );
		if ( is_string( $cached ) && $cached !== '' ) {
			return $cached;
		}

		$credentials = WC_Zip4_Credentials::get();
		if ( ! $credentials ) {
			return null;
		}

		$response = wp_remote_post(
			'https://apis.usps.com/oauth2/v3/token',
			array(
				'body' => array(
					'grant_type'    => 'client_credentials',
					'client_id'     => $credentials['client_id'],
					'client_secret' => $credentials['client_secret'],
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			return null;
		}

		$data = json_decode( wp_remote_retrieve_body( $response ) );
		if ( empty( $data->access_token ) ) {
			return null;
		}

		set_transient( self::TOKEN_TRANSIENT, $data->access_token, self::TOKEN_TTL );

		return $data->access_token;
	}
}
