<?php
/**
 * USPS ZIP+4 Standardization for WooCommerce
 * Standardizes US addresses via USPS API on checkout and profile updates.
 * Returns ZIP+4 or original ZIP if match/auth fails.
 */

if ( ! function_exists( 'zip4_get_usps_credentials' ) ) {
	$credentials_file = __DIR__ . '/zip4-usps-credentials.php';
	if ( is_readable( $credentials_file ) ) {
		require_once $credentials_file;
	}
}

/**
 * 1. CENTRAL HELPER FUNCTION
 * Handles API authentication, domestic check, and address standardization.
 */
function zip4_standardize_usps_zip( $addr1, $addr2, $city, $state, $zip, $country ) {
    // Initial Validation: Skip if not USA, already ZIP+4, or if ZIP is empty
    if ( $country !== 'US' || empty( $zip ) || strpos( $zip, '-' ) !== false ) {
        return $zip;
    }

    $credentials = zip4_get_usps_credentials();
    if ( ! $credentials ) {
        return $zip;
    }

    $client_id     = $credentials['client_id'];
    $client_secret = $credentials['client_secret'];

    // Get USPS Access Token
    $token_auth = wp_remote_post( 'https://apis.usps.com/oauth2/v3/token', [
        'body' => [
            'grant_type'    => 'client_credentials',
            'client_id'     => $client_id,
            'client_secret' => $client_secret
        ]
    ]);
   
    $token_data   = json_decode( wp_remote_retrieve_body( $token_auth ) );
    $access_token = $token_data->access_token ?? false;

    // Fallback: Return original ZIP if authentication fails
    if ( ! $access_token ) {
        return $zip;
    }

    // Clean addresses for API
    $clean_addr1 = str_replace( '.', '', $addr1 );
    $clean_addr2 = str_replace( '#', '', $addr2 );

    $query_args = [
        'streetAddress'    => $clean_addr1,
        'secondaryAddress' => $clean_addr2,
        'city'             => $city,
        'state'            => $state,
        'ZIPCode'          => $zip,
    ];

    // Call USPS API with a timeout to prevent checkout hanging
    $response = wp_remote_get( add_query_arg( $query_args, 'https://apis.usps.com/addresses/v3/address' ), [
        'headers' => [
            'Authorization' => 'Bearer ' . $access_token,
            'Accept'        => 'application/json'
        ],
        'timeout' => 7
    ]);

    if ( ! is_wp_error( $response ) ) {
        $result = json_decode( wp_remote_retrieve_body( $response ) );
       
        if ( isset( $result->address->ZIPPlus4 ) && ! empty( $result->address->ZIPPlus4 ) ) {
            return $result->address->ZIPCode . '-' . $result->address->ZIPPlus4;
        }
    }

    // Fallback to original ZIP if API match fails
    return $zip;
}

/**
 * 2. TRIGGER: NEW ORDERS (CHECKOUT)
 * Updates BOTH the order object and the customer's permanent profile during checkout.
 */

// Part A: Update the Order Object before saving
add_action( 'woocommerce_checkout_create_order', 'zip4_handle_checkout_standardization', 10, 2 );
function zip4_handle_checkout_standardization( $order, $data ) {
    if ( ! $order ) return;

    $prefix = ! empty( $order->get_shipping_address_1() ) ? 'shipping' : 'billing';
   
    $new_zip = zip4_standardize_usps_zip(
        $order->{"get_{$prefix}_address_1"}(),
        $order->{"get_{$prefix}_address_2"}(),
        $order->{"get_{$prefix}_city"}(),
        $order->{"get_{$prefix}_state"}(),
        $order->{"get_{$prefix}_postcode"}(),
        $order->{"get_{$prefix}_country"}()
    );

    if ( strpos( $new_zip, '-' ) !== false ) {
        $order->{"set_{$prefix}_postcode"}( $new_zip );
        $order->add_order_note( "USPS: Order ZIP standardized to $new_zip" );
    }
}

// Part B: Update the User Profile metadata during the checkout save process
add_action( 'woocommerce_checkout_update_user_meta', 'zip4_handle_checkout_profile_standardization', 10, 2 );
function zip4_handle_checkout_profile_standardization( $user_id, $posted_data ) {
    if ( ! $user_id ) return;

    // Prioritize shipping data; fallback to billing if shipping line 1 is missing
    $prefix = ! empty( $posted_data['shipping_address_1'] ) ? 'shipping' : 'billing';
   
    // Check if country data exists in the form submission
    $country = $posted_data[$prefix . '_country'] ?? 'US';

    $new_zip = zip4_standardize_usps_zip(
        $posted_data[$prefix . '_address_1'] ?? '',
        $posted_data[$prefix . '_address_2'] ?? '',
        $posted_data[$prefix . '_city'] ?? '',
        $posted_data[$prefix . '_state'] ?? '',
        $posted_data[$prefix . '_postcode'] ?? '',
        $country
    );

    if ( strpos( $new_zip, '-' ) !== false ) {
        // Force the standardized ZIP code straight into the user's metadata
        update_user_meta( $user_id, $prefix . '_postcode', $new_zip );
    }
}

/**
 * 3. TRIGGER: ACCOUNT ADDRESS UPDATES (MY ACCOUNT)
 * Standardizes address data when a customer saves their profile.
 */
add_action( 'woocommerce_customer_save_address', 'zip4_handle_profile_update_standardization', 10, 2 );
function zip4_handle_profile_update_standardization( $user_id, $address_type ) {
    $country = get_user_meta( $user_id, $address_type . '_country', true );

    $new_zip = zip4_standardize_usps_zip(
        get_user_meta( $user_id, $address_type . '_address_1', true ),
        get_user_meta( $user_id, $address_type . '_address_2', true ),
        get_user_meta( $user_id, $address_type . '_city', true ),
        get_user_meta( $user_id, $address_type . '_state', true ),
        get_user_meta( $user_id, $address_type . '_postcode', true ),
        $country
    );

    // Update user metadata if a ZIP+4 was returned
    if ( strpos( $new_zip, '-' ) !== false ) {
        update_user_meta( $user_id, $address_type . '_postcode', $new_zip );
    }
}

/**
 * 4. TRIGGER: SUBSCRIPTION UPDATES
 * Standardizes the ZIP on the actual subscription object when a user edits their address.
 */
add_action( 'woocommerce_subscription_address_updated', 'zip4_handle_subscription_update_standardization', 10, 3 );
function zip4_handle_subscription_update_standardization( $subscription, $new_address, $address_type ) {
    $country = $new_address['country'] ?? '';

    $new_zip = zip4_standardize_usps_zip(
        $new_address['address_1'] ?? '',
        $new_address['address_2'] ?? '',
        $new_address['city'] ?? '',
        $new_address['state'] ?? '',
        $new_address['postcode'] ?? '',
        $country
    );

    if ( strpos( $new_zip, '-' ) !== false ) {
        $subscription->{"set_{$address_type}_postcode"}( $new_zip );
        $subscription->save();
        $subscription->add_order_note( "USPS: Subscription ZIP standardized to $new_zip" );
    }
}