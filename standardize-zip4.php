<?php

/**
 * USPS ZIP+4 on new orders & addresses
 *
 * https://github.com/gamebits/wc-sub-zip4-updater
 *
 * Standardize any US Address via USPS API. Triggers when new orders are
 * placed or when customers update their accounts. Returns standardized
 * ZIP+4 string or original ZIP if match fails.
 */
function zip4_standardize_usps_zip( $addr1, $addr2, $city, $state, $zip ) {
    // 1. USPS API Credentials
    $client_id     = 'YOUR_CLIENT_ID';
    $client_secret = 'YOUR_CLIENT_SECRET';

    // 2. Initial Validation: Skip if already ZIP+4 or if ZIP is empty
    if ( empty( $zip ) || strpos( $zip, '-' ) !== false ) {
        return $zip;
    }

    // 3. Get USPS Access Token
    $token_auth = wp_remote_post( 'https://apis.usps.com/oauth2/v3/token', [
        'body' => [ 
            'grant_type'    => 'client_credentials', 
            'client_id'     => $client_id, 
            'client_secret' => $client_secret 
        ]
    ]);
    
    $token_data   = json_decode( wp_remote_retrieve_body( $token_auth ) );
    $access_token = $token_data->access_token ?? false;

    // If authentication fails, return the original ZIP to avoid breaking the checkout/update flow
    if ( ! $access_token ) {
        return $zip;
    }

    // 4. Clean addresses for API
    $clean_addr1 = str_replace( '.', '', $addr1 );
    $clean_addr2 = str_replace( '#', '', $addr2 );

    $query_args = [
        'streetAddress'    => $clean_addr1,
        'secondaryAddress' => $clean_addr2,
        'city'             => $city,
        'state'            => $state,
        'ZIPCode'          => $zip,
    ];

    // 5. Call USPS API
    $response = wp_remote_get( add_query_arg( $query_args, 'https://apis.usps.com/addresses/v3/address' ), [
        'headers' => [ 
            'Authorization' => 'Bearer ' . $access_token, 
            'Accept'        => 'application/json' 
        ],
        'timeout' => 10
    ]);

    if ( ! is_wp_error( $response ) ) {
        $result = json_decode( wp_remote_retrieve_body( $response ) );
        
        if ( isset( $result->address->ZIPPlus4 ) && ! empty( $result->address->ZIPPlus4 ) ) {
            // Return the full 5+4 format
            return $result->address->ZIPCode . '-' . $result->address->ZIPPlus4;
        }
    }

    // Fallback to original ZIP if API match fails
    return $zip;
}

/**
 * TRIGGER 1: New Orders (Checkout)
 */
add_action( 'woocommerce_checkout_update_order_meta', 'zip4_standardize_on_checkout', 20, 2 );
function zip4_standardize_on_checkout( $order_id, $data ) {
    $order = wc_get_order( $order_id );
    if ( ! $order ) return;

    // Run only for US addresses
    $country = $order->get_shipping_country() ?: $order->get_billing_country();
    if ( $country !== 'US' ) return;

    $prefix = ! empty( $order->get_shipping_address_1() ) ? 'shipping' : 'billing';
    
    // Pass order data to the helper function
    $new_zip = zip4_standardize_usps_zip(
        $order->{"get_{$prefix}_address_1"}(),
        $order->{"get_{$prefix}_address_2"}(),
        $order->{"get_{$prefix}_city"}(),
        $order->{"get_{$prefix}_state"}(),
        $order->{"get_{$prefix}_postcode"}()
    );

    // Only save if the helper returned a new ZIP+4 format
    if ( strpos( $new_zip, '-' ) !== false ) {
        $order->{"set_{$prefix}_postcode"}( $new_zip );
        $order->save();
        $order->add_order_note( "USPS: ZIP standardized to $new_zip" );
    }
}

/**
 * TRIGGER 2: Account Address Updates (My Account)
 */
add_action( 'woocommerce_customer_save_address', 'zip4_standardize_on_profile_update', 10, 2 );
function zip4_standardize_on_profile_update( $user_id, $address_type ) {
    // Only run for US addresses
    $country = get_user_meta( $user_id, $address_type . '_country', true );
    if ( $country !== 'US' ) return;

    // Pass user meta data to the helper function
    $new_zip = zip4_standardize_usps_zip(
        get_user_meta( $user_id, $address_type . '_address_1', true ),
        get_user_meta( $user_id, $address_type . '_address_2', true ),
        get_user_meta( $user_id, $address_type . '_city', true ),
        get_user_meta( $user_id, $address_type . '_state', true ),
        get_user_meta( $user_id, $address_type . '_postcode', true )
    );

    // Only update if the helper returned a new ZIP+4 format
    if ( strpos( $new_zip, '-' ) !== false ) {
        update_user_meta( $user_id, $address_type . '_postcode', $new_zip );
    }
}
