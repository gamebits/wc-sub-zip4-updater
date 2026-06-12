<?php
/**
 * Subscription batch processing queries.
 *
 * @package WooCommerce_Zip4
 */

defined( 'ABSPATH' ) || exit;

/**
 * Finds and updates subscription addresses for batch processing.
 */
class WC_Zip4_Subscriptions {

	/**
	 * Subscription status labels keyed by settings field name.
	 *
	 * @return array<string, string>
	 */
	public static function get_status_options() {
		return array(
			'active'               => __( 'Active', 'woocommerce-zip4' ),
			'pending'              => __( 'Pending', 'woocommerce-zip4' ),
			'on_hold'              => __( 'On Hold', 'woocommerce-zip4' ),
			'pending_cancellation' => __( 'Pending Cancellation', 'woocommerce-zip4' ),
			'cancelled'            => __( 'Cancelled', 'woocommerce-zip4' ),
			'expired'              => __( 'Expired', 'woocommerce-zip4' ),
		);
	}

	/**
	 * Map settings keys to WooCommerce subscription status slugs.
	 *
	 * @return array<string, string>
	 */
	public static function get_status_map() {
		return array(
			'active'               => 'wc-active',
			'pending'              => 'wc-pending',
			'on_hold'              => 'wc-on-hold',
			'pending_cancellation' => 'wc-pending-cancel',
			'cancelled'            => 'wc-cancelled',
			'expired'              => 'wc-expired',
		);
	}

	/**
	 * Default selected subscription statuses.
	 *
	 * @return array<string, bool>
	 */
	public static function get_default_status_selection() {
		$defaults = array();
		foreach ( array_keys( self::get_status_options() ) as $key ) {
			$defaults[ $key ] = true;
		}
		return $defaults;
	}

	/**
	 * Build SQL IN clause for selected subscription statuses.
	 *
	 * @param array<string, bool> $selected Selected status settings.
	 * @return string
	 */
	public static function build_status_sql( $selected ) {
		global $wpdb;

		$statuses = array();
		foreach ( self::get_status_map() as $key => $slug ) {
			if ( ! empty( $selected[ $key ] ) ) {
				$statuses[] = $slug;
			}
		}

		if ( empty( $statuses ) ) {
			return 'AND 1=0';
		}

		$placeholders = implode( ', ', array_fill( 0, count( $statuses ), '%s' ) );
		return $wpdb->prepare( "AND o.status IN ($placeholders)", $statuses );
	}

	/**
	 * Get subscription IDs that still need ZIP+4 updates.
	 *
	 * @param array<string, bool> $selected Selected status settings.
	 * @return int[]
	 */
	public static function get_pending_subscription_ids( $selected ) {
		global $wpdb;

		$status_sql = self::build_status_sql( $selected );
		if ( 'AND 1=0' === $status_sql ) {
			return array();
		}

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- status SQL uses $wpdb->prepare().
		return array_map(
			'intval',
			$wpdb->get_col(
				"
				SELECT DISTINCT o.id
				FROM {$wpdb->prefix}wc_orders o
				INNER JOIN {$wpdb->prefix}wc_order_addresses oa ON o.id = oa.order_id
				WHERE o.type = 'shop_subscription'
				{$status_sql}
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
				ORDER BY o.id ASC
				"
			)
		);
	}

	/**
	 * Process a single subscription for ZIP+4 standardization.
	 *
	 * @param int $subscription_id Subscription ID.
	 * @return array{success: bool, message: string, updated: bool}
	 */
	public static function process_subscription( $subscription_id ) {
		$subscription = wc_get_order( $subscription_id );
		if ( ! $subscription ) {
			return array(
				'success' => false,
				'updated' => false,
				'message' => sprintf(
					/* translators: %d: subscription ID */
					__( 'Subscription #%d could not be loaded.', 'woocommerce-zip4' ),
					$subscription_id
				),
			);
		}

		$prefix = WC_Zip4_Standardizer::get_order_address_prefix( $subscription );
		$note = __( 'USPS: Subscription ZIP standardized to %s', 'woocommerce-zip4' );

		$updated = WC_Zip4_Standardizer::apply_to_subscription(
			$subscription,
			$prefix,
			$note,
			15
		);

		if ( $updated ) {
			$new_zip = $subscription->{"get_{$prefix}_postcode"}();
			return array(
				'success' => true,
				'updated' => true,
				'message' => sprintf(
					/* translators: 1: subscription ID, 2: ZIP+4 code */
					__( 'Subscription #%1$d updated to %2$s.', 'woocommerce-zip4' ),
					$subscription_id,
					$new_zip
				),
			);
		}

		return array(
			'success' => true,
			'updated' => false,
			'message' => sprintf(
				/* translators: %d: subscription ID */
				__( 'Subscription #%d skipped (no ZIP+4 match).', 'woocommerce-zip4' ),
				$subscription_id
			),
		);
	}
}
