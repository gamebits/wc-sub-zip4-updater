/**
 * Auto-Standardize USPS ZIP+4 on Checkout
 * Place this in your child theme's functions.php, or a custom plugin, or in a Code Snippets plugin.
 */

add_action( 'woocommerce_checkout_update_order_meta', 'standardize_usps_zip_on_checkout', 20, 2 );

function standardize_usps_zip_on_checkout( $order_id, $data ) {
    $order = wc_get_order( $order_id );
    if ( ! $order ) return;

    // 1. Only run for US addresses
    $country = $order->get_shipping_country() ?: $order->get_billing_country();
    if ( $country !== 'US' ) return;

    // 2. USPS API Credentials
    $client_id     = 'CLIENT_ID';
    $client_secret = 'CLIENT_SECRET';

    // 3. Determine which address to standardize (Shipping priority)
    $prefix = ! empty( $order->get_shipping_address_1() ) ? 'shipping' : 'billing';
    $zip    = $order->{"get_{$prefix}_postcode"}();

    // Skip if already ZIP+4
    if ( strpos( $zip, '-' ) !== false ) return;

    // 4. Get USPS Access Token
    $token_auth = wp_remote_post( 'https://apis.usps.com/oauth2/v3/token', [
        'body' => [ 
            'grant_type'    => 'client_credentials', 
            'client_id'     => $client_id, 
            'client_secret' => $client_secret 
        ]
    ]);
    
    $token_data   = json_decode( wp_remote_retrieve_body( $token_auth ) );
    $access_token = $token_data->access_token ?? false;

    if ( ! $access_token ) return;

    // 5. Clean addresses for API
    $addr1 = str_replace( '.', '', $order->{"get_{$prefix}_address_1"}() );
    $addr2 = str_replace( '#', '', $order->{"get_{$prefix}_address_2"}() );

    $query_args = [
        'streetAddress'    => $addr1,
        'secondaryAddress' => $addr2,
        'city'             => $order->{"get_{$prefix}_city"}(),
        'state'            => $order->{"get_{$prefix}_state"}(),
        'ZIPCode'          => $zip,
    ];

    // 6. Call USPS API
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
            $full_zip = $result->address->ZIPCode . '-' . $result->address->ZIPPlus4;
            
            // Update the order postcode
            $order->{"set_{$prefix}_postcode"}( $full_zip );
            $order->save();
            
            // Optional: Add an order note for tracking
            $order->add_order_note( "USPS: ZIP standardized to $full_zip" );
        }
    }
}