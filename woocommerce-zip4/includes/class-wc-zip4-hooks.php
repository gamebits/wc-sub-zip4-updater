<?php
/**
 * WooCommerce action hooks for ZIP+4 standardization.
 *
 * @package WooCommerce_Zip4
 */

defined( 'ABSPATH' ) || exit;

/**
 * Registers runtime hooks based on plugin settings.
 */
class WC_Zip4_Hooks {

	/**
	 * Initialize hooks.
	 */
	public static function init() {
		$settings = WC_Zip4_Admin::get_settings();

		if ( ! empty( $settings['new_order_placed'] ) ) {
			add_action( 'woocommerce_checkout_create_order', array( __CLASS__, 'handle_checkout_order' ), 10, 2 );
			add_action( 'woocommerce_checkout_update_user_meta', array( __CLASS__, 'handle_checkout_user_meta' ), 10, 2 );
		}

		if ( ! empty( $settings['address_updated_by_customer'] ) ) {
			add_action( 'woocommerce_customer_save_address', array( __CLASS__, 'handle_customer_save_address' ), 10, 2 );
		}

		if ( ! empty( $settings['address_updated_by_administrator'] ) ) {
			add_action( 'woocommerce_process_shop_order_meta', array( __CLASS__, 'handle_admin_order_save' ), 50, 2 );
			add_action( 'woocommerce_process_customer_meta', array( __CLASS__, 'handle_admin_customer_save' ), 50, 1 );
		}

		if ( ! empty( $settings['subscription_updated_by_customer'] ) || ! empty( $settings['subscription_updated_by_administrator'] ) ) {
			add_action( 'woocommerce_subscription_address_updated', array( __CLASS__, 'handle_subscription_address_updated' ), 10, 3 );
		}
	}

	/**
	 * Standardize ZIP on checkout order creation.
	 *
	 * @param WC_Order $order Order object.
	 * @param array    $data  Posted checkout data.
	 */
	public static function handle_checkout_order( $order, $data ) {
		unset( $data );

		if ( ! WC_Zip4_Admin::is_setting_enabled( 'new_order_placed' ) ) {
			return;
		}

		WC_Zip4_Standardizer::apply_to_order(
			$order,
			__( 'USPS: Order ZIP standardized to %s', 'woocommerce-zip4' )
		);
	}

	/**
	 * Standardize customer profile ZIP during checkout.
	 *
	 * @param int   $user_id      User ID.
	 * @param array $posted_data  Posted checkout data.
	 */
	public static function handle_checkout_user_meta( $user_id, $posted_data ) {
		if ( ! $user_id || ! WC_Zip4_Admin::is_setting_enabled( 'new_order_placed' ) ) {
			return;
		}

		$prefix = ! empty( $posted_data['shipping_address_1'] ) ? 'shipping' : 'billing';

		$new_zip = WC_Zip4_Standardizer::standardize_zip(
			$posted_data[ $prefix . '_address_1' ] ?? '',
			$posted_data[ $prefix . '_address_2' ] ?? '',
			$posted_data[ $prefix . '_city' ] ?? '',
			$posted_data[ $prefix . '_state' ] ?? '',
			$posted_data[ $prefix . '_postcode' ] ?? '',
			$posted_data[ $prefix . '_country' ] ?? 'US'
		);

		if ( false !== strpos( $new_zip, '-' ) ) {
			update_user_meta( $user_id, $prefix . '_postcode', $new_zip );
		}
	}

	/**
	 * Standardize customer account address updates.
	 *
	 * @param int    $user_id      User ID.
	 * @param string $address_type Address type.
	 */
	public static function handle_customer_save_address( $user_id, $address_type ) {
		if ( self::is_administrator_action() ) {
			return;
		}

		WC_Zip4_Standardizer::apply_to_user_address( $user_id, $address_type );
	}

	/**
	 * Standardize order or subscription addresses saved in admin.
	 *
	 * @param int      $order_id Order ID.
	 * @param WC_Order $order    Order object.
	 */
	public static function handle_admin_order_save( $order_id, $order ) {
		unset( $order_id );

		if ( ! self::is_administrator_action() || ! WC_Zip4_Admin::is_setting_enabled( 'address_updated_by_administrator' ) ) {
			return;
		}

		if ( ! $order instanceof WC_Order ) {
			return;
		}

		if ( 'shop_subscription' === $order->get_type() ) {
			if ( WC_Zip4_Admin::is_setting_enabled( 'subscription_updated_by_administrator' ) ) {
				$prefix = WC_Zip4_Standardizer::get_order_address_prefix( $order );
				WC_Zip4_Standardizer::apply_to_subscription(
					$order,
					$prefix,
					__( 'USPS: Subscription ZIP standardized to %s', 'woocommerce-zip4' )
				);
			}
			return;
		}

		WC_Zip4_Standardizer::apply_to_order(
			$order,
			__( 'USPS: Order ZIP standardized to %s', 'woocommerce-zip4' )
		);
	}

	/**
	 * Standardize customer addresses saved by an administrator.
	 *
	 * @param int $user_id User ID.
	 */
	public static function handle_admin_customer_save( $user_id ) {
		if ( ! self::is_administrator_action() || ! WC_Zip4_Admin::is_setting_enabled( 'address_updated_by_administrator' ) ) {
			return;
		}

		WC_Zip4_Standardizer::apply_to_user_address( $user_id, 'billing' );
		WC_Zip4_Standardizer::apply_to_user_address( $user_id, 'shipping' );
	}

	/**
	 * Standardize subscription address updates from customers or administrators.
	 *
	 * @param WC_Subscription $subscription Subscription object.
	 * @param array           $new_address  Updated address data.
	 * @param string          $address_type Address type.
	 */
	public static function handle_subscription_address_updated( $subscription, $new_address, $address_type ) {
		$is_admin = self::is_administrator_action();

		if ( $is_admin && ! WC_Zip4_Admin::is_setting_enabled( 'subscription_updated_by_administrator' ) ) {
			return;
		}

		if ( ! $is_admin && ! WC_Zip4_Admin::is_setting_enabled( 'subscription_updated_by_customer' ) ) {
			return;
		}

		$country = $new_address['country'] ?? '';
		$new_zip = WC_Zip4_Standardizer::standardize_zip(
			$new_address['address_1'] ?? '',
			$new_address['address_2'] ?? '',
			$new_address['city'] ?? '',
			$new_address['state'] ?? '',
			$new_address['postcode'] ?? '',
			$country
		);

		if ( false === strpos( $new_zip, '-' ) ) {
			return;
		}

		$subscription->{"set_{$address_type}_postcode"}( $new_zip );
		$subscription->save();
		$subscription->add_order_note(
			sprintf(
				__( 'USPS: Subscription ZIP standardized to %s', 'woocommerce-zip4' ),
				$new_zip
			)
		);
	}

	/**
	 * Determine whether the current request is an administrator action.
	 *
	 * @return bool
	 */
	private static function is_administrator_action() {
		if ( ! is_user_logged_in() || ! current_user_can( 'manage_woocommerce' ) ) {
			return false;
		}

		if ( wp_doing_ajax() ) {
			$referer = wp_get_referer();
			if ( $referer && false !== strpos( $referer, 'my-account' ) ) {
				return false;
			}
		}

		return is_admin();
	}
}
