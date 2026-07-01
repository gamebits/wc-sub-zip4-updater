<?php
/**
 * Address standardization helpers.
 *
 * @package WooCommerce_Zip4
 */

defined( 'ABSPATH' ) || exit;

/**
 * Standardizes USA ZIP codes to ZIP+4 format.
 */
class WC_Zip4_Standardizer {

	/**
	 * Standardize a ZIP code using the USPS API.
	 *
	 * @param string $addr1   Street address line 1.
	 * @param string $addr2   Street address line 2.
	 * @param string $city    City.
	 * @param string $state   State code.
	 * @param string $zip     ZIP code.
	 * @param string $country Country code.
	 * @param int    $timeout Request timeout in seconds.
	 * @return string Original ZIP or standardized ZIP+4.
	 */
	public static function standardize_zip( $addr1, $addr2, $city, $state, $zip, $country, $timeout = 7 ) {
		if ( 'US' !== $country || empty( $zip ) || false !== strpos( $zip, '-' ) ) {
			return $zip;
		}

		if ( ! WC_Zip4_Credentials::is_configured() ) {
			return $zip;
		}

		$zip4 = WC_Zip4_API::lookup_zip4( $addr1, $addr2, $city, $state, $zip, $timeout );
		return $zip4 ? $zip4 : $zip;
	}

	/**
	 * Determine whether an order should use shipping or billing address.
	 *
	 * @param WC_Order $order Order object.
	 * @return string Prefix: shipping or billing.
	 */
	public static function get_order_address_prefix( $order ) {
		return ! empty( $order->get_shipping_address_1() ) ? 'shipping' : 'billing';
	}

	/**
	 * Apply ZIP+4 to an order address if a match is found.
	 *
	 * @param WC_Order $order  Order object.
	 * @param string   $note   Order note to add on success.
	 * @param int      $timeout Request timeout in seconds.
	 * @return bool Whether the order ZIP was updated.
	 */
	public static function apply_to_order( $order, $note, $timeout = 7 ) {
		if ( ! $order instanceof WC_Order ) {
			return false;
		}

		$prefix  = self::get_order_address_prefix( $order );
		$new_zip = self::standardize_zip(
			$order->{"get_{$prefix}_address_1"}(),
			$order->{"get_{$prefix}_address_2"}(),
			$order->{"get_{$prefix}_city"}(),
			$order->{"get_{$prefix}_state"}(),
			$order->{"get_{$prefix}_postcode"}(),
			$order->{"get_{$prefix}_country"}(),
			$timeout
		);

		if ( false === strpos( $new_zip, '-' ) ) {
			return false;
		}

		$order->{"set_{$prefix}_postcode"}( $new_zip );
		$order->add_order_note( sprintf( $note, $new_zip ) );
		return true;
	}

	/**
	 * Apply ZIP+4 to a subscription address if a match is found.
	 *
	 * @param WC_Order $subscription Subscription object.
	 * @param string   $address_type Address type: shipping or billing.
	 * @param string   $note         Subscription note to add on success.
	 * @param int      $timeout      Request timeout in seconds.
	 * @return bool Whether the subscription ZIP was updated.
	 */
	public static function apply_to_subscription( $subscription, $address_type, $note, $timeout = 7 ) {
		if ( ! $subscription instanceof WC_Order ) {
			return false;
		}

		$new_zip = self::standardize_zip(
			$subscription->{"get_{$address_type}_address_1"}(),
			$subscription->{"get_{$address_type}_address_2"}(),
			$subscription->{"get_{$address_type}_city"}(),
			$subscription->{"get_{$address_type}_state"}(),
			$subscription->{"get_{$address_type}_postcode"}(),
			$subscription->{"get_{$address_type}_country"}(),
			$timeout
		);

		if ( false === strpos( $new_zip, '-' ) ) {
			return false;
		}

		$subscription->{"set_{$address_type}_postcode"}( $new_zip );
		$subscription->save();
		$subscription->add_order_note( sprintf( $note, $new_zip ) );
		return true;
	}

	/**
	 * Apply ZIP+4 to a user address meta field.
	 *
	 * @param int    $user_id      User ID.
	 * @param string $address_type Address type: shipping or billing.
	 * @return bool Whether the user ZIP was updated.
	 */
	public static function apply_to_user_address( $user_id, $address_type ) {
		$country = get_user_meta( $user_id, $address_type . '_country', true );

		$new_zip = self::standardize_zip(
			get_user_meta( $user_id, $address_type . '_address_1', true ),
			get_user_meta( $user_id, $address_type . '_address_2', true ),
			get_user_meta( $user_id, $address_type . '_city', true ),
			get_user_meta( $user_id, $address_type . '_state', true ),
			get_user_meta( $user_id, $address_type . '_postcode', true ),
			$country
		);

		if ( false === strpos( $new_zip, '-' ) ) {
			return false;
		}

		update_user_meta( $user_id, $address_type . '_postcode', $new_zip );
		return true;
	}
}
