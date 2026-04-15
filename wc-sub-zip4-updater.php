<?php

/**
 * Multi-Target USPS ZIP+4 Updater for HPOS.
 */
function run_usps_updater() {
    global $wpdb;

    // --- MANUAL CONFIGURATION ---
    // 1 = Active Only | 2 = Non-Active Only | 3 = ALL Subscriptions
    $target_mode    = 1;

    // true = Full Audit (No changes) | false = Live Updates
    $is_dry_run     = false;

    $client_id     = 'CLIENT_ID';
    $client_secret = 'CLIENT_SECRET';
	$log_file = '/tmp/usps_update_log.txt';
    // ----------------------------

// 1. Define Status SQL

    switch ( $target_mode ) {
        case 1:
            $status_label = "ACTIVE ONLY";
            $status_sql   = "AND o.status = 'wc-active'";
            break;
        case 2:
            $status_label = "NON-ACTIVE (Including Pending)";
            $status_sql   = "AND o.status != 'wc-active'";
            break;
        case 3:
        default:
            $status_label = "ALL (Active, Non-Active & Pending)";
            $status_sql   = ""; 
            break;
    }

    /**
     * 2. Fetch IDs needing updates (5-digit)
     * SQL logic: Target shipping row if it exists/not empty, otherwise target billing.
     * Only include the ID if that specific priority row is 5-digits (no hyphen).
     */
    $subscription_ids = $wpdb->get_col("
        SELECT DISTINCT o.id
        FROM {$wpdb->prefix}wc_orders o
        INNER JOIN {$wpdb->prefix}wc_order_addresses oa ON o.id = oa.order_id
        WHERE o.type = 'shop_subscription'
        $status_sql
        AND oa.country = 'US'
        AND oa.postcode REGEXP '^[0-9]{5}$'
        AND oa.postcode NOT LIKE '%-%'
        AND (
            (oa.address_type = 'shipping' AND oa.address_1 != '')
            OR
            (oa.address_type = 'billing' AND NOT EXISTS (
                SELECT 1 FROM {$wpdb->prefix}wc_order_addresses
                WHERE order_id = o.id AND address_type = 'shipping' AND address_1 != ''
            ))
        )
    ");

    // 3. Count existing ZIP+4 (Standardized) based on the same priority logic
    $already_standardized_count = $wpdb->get_var("
        SELECT COUNT(DISTINCT o.id)
        FROM {$wpdb->prefix}wc_orders o
        INNER JOIN {$wpdb->prefix}wc_order_addresses oa ON o.id = oa.order_id
        WHERE o.type = 'shop_subscription'
        $status_sql
        AND oa.country = 'US'
        AND oa.postcode LIKE '%-%'
        AND (
            (oa.address_type = 'shipping' AND oa.address_1 != '')
            OR
            (oa.address_type = 'billing' AND NOT EXISTS (
                SELECT 1 FROM {$wpdb->prefix}wc_order_addresses
                WHERE order_id = o.id AND address_type = 'shipping' AND address_1 != ''
            ))
        )
    ");

    $count = count( $subscription_ids );
    $total_seconds = $count * 62;
    $hours = floor($total_seconds / 3600);
    $minutes = floor(($total_seconds / 60) % 60);

    if ( $is_dry_run ) {
        /**
         * --- PATH A: FULL AUDIT ---
         */
        WP_CLI::line( "### FULL AUDIT [Target: $status_label] ###" );
        WP_CLI::line( "Priority: Shipping Address (Billing Fallback)" );
        WP_CLI::line( "Standardized (Already ZIP+4): $already_standardized_count" );
        WP_CLI::line( "Pending Update (5-digit):     $count" );
        WP_CLI::line( "Estimated Live Run Time:      {$hours}h {$minutes}m" );
        WP_CLI::line( "------------------------------------------------------" );

        $i = 0;
        foreach ( $subscription_ids as $sub_id ) {
            $subscription = wc_get_order( $sub_id );
            if ( ! $subscription ) continue;

            $is_shipping = ! empty( $subscription->get_shipping_address_1() );
            $prefix      = $is_shipping ? 'shipping' : 'billing';

            WP_CLI::log( sprintf(
                "[%d/%d] ID: %-7d | Source: %-8s | ZIP: %s | Addr: %s",
                ++$i,
                $count,
                $sub_id,
                $prefix,
                $subscription->{"get_{$prefix}_postcode"}(),
                $subscription->{"get_{$prefix}_address_1"}()
            ) );
        }
        WP_CLI::line( "------------------------------------------------------" );
        WP_CLI::success( "Found $count subscriptions to be updated, and skipped $already_standardized_count subscriptions that already have ZIP+4." );
        return;

    } else {
        /**
         * --- PATH B: LIVE UPDATES ---
         */
        WP_CLI::line( "### LIVE MODE [Target: $status_label] ###" );
        WP_CLI::line( "Batch Size: $count | Estimated Completion: {$hours}h {$minutes}m" );
        WP_CLI::confirm( "Proceed with updates?", false );

        $token_auth = wp_remote_post( 'https://apis.usps.com/oauth2/v3/token', [
            'body' => [ 'grant_type' => 'client_credentials', 'client_id' => $client_id, 'client_secret' => $client_secret ]
        ]);
        $token_data = json_decode( wp_remote_retrieve_body( $token_auth ) );
        $access_token = $token_data->access_token ?? false;

        if ( ! $access_token ) {
            WP_CLI::error( "USPS Authentication Failed." );
            return;
        }

        $log_handle = @fopen( $log_file, 'a' );
        $processed = 0;

        foreach ( $subscription_ids as $sub_id ) {
            $subscription = wc_get_order( $sub_id );
            if ( ! $subscription ) continue;

            $is_shipping = ! empty( $subscription->get_shipping_address_1() );
            $prefix      = $is_shipping ? 'shipping' : 'billing';

            // USPS API FIXES:
            // 1. Remove "." from Address 1 (e.g., "Dr." -> "Dr")
            // 2. Remove "#" from Address 2 (e.g., "#133" -> "133")
            $clean_addr1 = str_replace('.', '', $subscription->{"get_{$prefix}_address_1"}());
            $clean_addr2 = str_replace('#', '', $subscription->{"get_{$prefix}_address_2"}());

            $query_args = [
                'streetAddress'    => $clean_addr1,
                'secondaryAddress' => $clean_addr2,
                'city'             => $subscription->{"get_{$prefix}_city"}(),
                'state'            => $subscription->{"get_{$prefix}_state"}(),
                'ZIPCode'          => $subscription->{"get_{$prefix}_postcode"}(),
            ];

            $response = wp_remote_get( add_query_arg( $query_args, 'https://apis.usps.com/addresses/v3/address' ), [
                'headers' => [ 'Authorization' => 'Bearer ' . $access_token, 'Accept' => 'application/json' ],
                'timeout' => 15
            ]);

            if ( ! is_wp_error( $response ) ) {
                $result = json_decode( wp_remote_retrieve_body( $response ) );
                if ( isset( $result->address->ZIPPlus4 ) && ! empty( $result->address->ZIPPlus4 ) ) {
                    $full_zip = $result->address->ZIPCode . '-' . $result->address->ZIPPlus4;
                    $subscription->{"set_{$prefix}_postcode"}( $full_zip );
                    $subscription->save();
                    $msg = "SUCCESS: Sub #$sub_id updated to $full_zip\n";
                } else {
                    $msg = "SKIPPED: Sub #$sub_id - No ZIP+4 match.\n";
                }
            } else {
                $msg = "ERROR: Sub #$sub_id - API failure.\n";
            }

            WP_CLI::log( sprintf( "[%d/%d] %s", ++$processed, $count, $msg ) );
            if ($log_handle) {
                fwrite($log_handle, $msg);
                fflush($log_handle);
            }

            unset( $subscription );
            if ( function_exists( 'gc_collect_cycles' ) ) gc_collect_cycles();
            sleep( 62 );
        }

        if ( $log_handle ) fclose( $log_handle );
        WP_CLI::success( "Live processing finished." );
    }
}

run_usps_updater();