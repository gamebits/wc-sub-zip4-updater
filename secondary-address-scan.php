<?php

/**
 * Passive Audit: Identifies ACTIVE US subscriptions with Unit Info in Address 1.
 */

function audit_active_unit_issues() {
    global $wpdb;

    // Use [[:<:]] and [[:>:]] for MySQL word boundaries (compatible with older/standard versions)
    // This ensures 'Ste' matches 'Ste 100' but NOT 'Sterling'
    $sql_search = "oa.address_1 REGEXP '[[:<:]](Apt|Unit|Ste|Suite|Bldg|Floor|Fl)[[:>:]]' OR oa.address_1 LIKE '%#%'";

    $results = $wpdb->get_results("
        SELECT oa.order_id, oa.address_1, oa.postcode 
        FROM {$wpdb->prefix}wc_order_addresses oa
        INNER JOIN {$wpdb->prefix}wc_orders o ON oa.order_id = o.id
        WHERE o.type = 'shop_subscription'
        AND o.status = 'wc-active'
        AND oa.address_type = 'shipping'
        AND oa.country IN ('US', 'USA', 'United States')
        AND ($sql_search)
    ");

    if ( empty( $results ) ) {
        WP_CLI::success( "No active subscriptions found with unit designators in Address 1." );
        return;
    }

    WP_CLI::line( sprintf( "Found %d active subscriptions for manual review:", count( $results ) ) );
    WP_CLI::line( str_repeat('-', 60) );
    WP_CLI::line( sprintf( "%-10s | %-30s | %-10s", "Sub ID", "Address 1", "ZIP" ) );
    WP_CLI::line( str_repeat('-', 60) );

    foreach ( $results as $row ) {
        WP_CLI::log( sprintf( 
            "%-10d | %-30s | %-10s", 
            $row->order_id, 
            $row->address_1, 
            $row->postcode 
        ) );
    }
    WP_CLI::line( str_repeat('-', 60) );
    WP_CLI::success( "Audit complete. Use these IDs to manually move unit info to Address 2." );
}

audit_active_unit_issues();