<?php
/**
 * Admin screens and AJAX handlers.
 *
 * @package WooCommerce_Zip4
 */

defined( 'ABSPATH' ) || exit;

/**
 * WooCommerce ZIP+4 admin UI.
 */
class WC_Zip4_Admin {

	const OPTION_KEY = 'woocommerce_zip4_settings';
	const PAGE_SLUG  = 'woocommerce-zip4';
	const RATE_LIMIT = 62;

	/**
	 * Initialize admin hooks.
	 */
	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'register_menu' ) );
		add_action( 'admin_init', array( __CLASS__, 'register_settings' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_assets' ) );
		add_action( 'wp_ajax_wc_zip4_prepare_subscriptions', array( __CLASS__, 'ajax_prepare_subscriptions' ) );
		add_action( 'wp_ajax_wc_zip4_process_subscription', array( __CLASS__, 'ajax_process_subscription' ) );
	}

	/**
	 * Register WooCommerce submenu page.
	 */
	public static function register_menu() {
		add_submenu_page(
			'woocommerce',
			__( 'ZIP+4', 'woocommerce-zip4' ),
			__( 'ZIP+4', 'woocommerce-zip4' ),
			'manage_woocommerce',
			self::PAGE_SLUG,
			array( __CLASS__, 'render_page' )
		);
	}

	/**
	 * Register plugin settings.
	 */
	public static function register_settings() {
		register_setting(
			'woocommerce_zip4_settings_group',
			self::OPTION_KEY,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( __CLASS__, 'sanitize_settings' ),
				'default'           => self::get_default_settings(),
			)
		);
	}

	/**
	 * Default settings values.
	 *
	 * @return array<string, bool>
	 */
	public static function get_default_settings() {
		return array(
			'new_order_placed'                   => true,
			'address_updated_by_customer'        => true,
			'subscription_updated_by_customer'   => true,
			'address_updated_by_administrator'   => true,
			'subscription_updated_by_administrator' => true,
		);
	}

	/**
	 * Get saved settings merged with defaults.
	 *
	 * @return array<string, bool>
	 */
	public static function get_settings() {
		$settings = get_option( self::OPTION_KEY, array() );
		if ( ! is_array( $settings ) ) {
			$settings = array();
		}

		return array_merge( self::get_default_settings(), $settings );
	}

	/**
	 * Check whether a setting is enabled.
	 *
	 * @param string $key Setting key.
	 * @return bool
	 */
	public static function is_setting_enabled( $key ) {
		$settings = self::get_settings();
		return ! empty( $settings[ $key ] );
	}

	/**
	 * Sanitize settings on save.
	 *
	 * @param array<string, mixed> $input Raw settings input.
	 * @return array<string, bool>
	 */
	public static function sanitize_settings( $input ) {
		$sanitized = self::get_default_settings();

		if ( ! is_array( $input ) ) {
			return $sanitized;
		}

		foreach ( array_keys( $sanitized ) as $key ) {
			$sanitized[ $key ] = ! empty( $input[ $key ] );
		}

		return $sanitized;
	}

	/**
	 * Enqueue admin assets on plugin pages.
	 *
	 * @param string $hook Current admin page hook.
	 */
	public static function enqueue_assets( $hook ) {
		if ( 'woocommerce_page_' . self::PAGE_SLUG !== $hook ) {
			return;
		}

		wp_enqueue_style(
			'woocommerce-zip4-admin',
			WC_ZIP4_PLUGIN_URL . 'assets/css/admin.css',
			array(),
			WC_ZIP4_VERSION
		);

		if ( self::get_current_tab() === 'subscriptions' && WooCommerce_Zip4::is_subscriptions_active() ) {
			wp_enqueue_script(
				'woocommerce-zip4-admin',
				WC_ZIP4_PLUGIN_URL . 'assets/js/admin.js',
				array( 'jquery' ),
				WC_ZIP4_VERSION,
				true
			);

			wp_localize_script(
				'woocommerce-zip4-admin',
				'wcZip4Admin',
				array(
					'ajaxUrl'   => admin_url( 'admin-ajax.php' ),
					'nonce'     => wp_create_nonce( 'wc_zip4_subscriptions' ),
					'rateLimit' => self::RATE_LIMIT,
					'i18n'      => array(
						'processing' => __( 'Processing', 'woocommerce-zip4' ),
						'waiting'    => __( 'Waiting for USPS rate limit…', 'woocommerce-zip4' ),
						'complete'   => __( 'Processing complete.', 'woocommerce-zip4' ),
						'none'       => __( 'No subscriptions matched the selected statuses.', 'woocommerce-zip4' ),
						'error'      => __( 'An error occurred while processing subscriptions.', 'woocommerce-zip4' ),
					),
				)
			);
		}
	}

	/**
	 * Get current admin tab.
	 *
	 * @return string
	 */
	public static function get_current_tab() {
		$tab = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'settings';

		if ( 'subscriptions' === $tab && ! WooCommerce_Zip4::is_subscriptions_active() ) {
			return 'settings';
		}

		return $tab;
	}

	/**
	 * Render admin page.
	 */
	public static function render_page() {
		$tab = self::get_current_tab();
		?>
		<div class="wrap woocommerce-zip4-admin">
			<h1><?php esc_html_e( 'ZIP+4', 'woocommerce-zip4' ); ?></h1>

			<?php self::render_credentials_notice(); ?>

			<nav class="nav-tab-wrapper woo-nav-tab-wrapper">
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=' . self::PAGE_SLUG . '&tab=settings' ) ); ?>" class="nav-tab <?php echo 'settings' === $tab ? 'nav-tab-active' : ''; ?>">
					<?php esc_html_e( 'Settings', 'woocommerce-zip4' ); ?>
				</a>
				<?php if ( WooCommerce_Zip4::is_subscriptions_active() ) : ?>
					<a href="<?php echo esc_url( admin_url( 'admin.php?page=' . self::PAGE_SLUG . '&tab=subscriptions' ) ); ?>" class="nav-tab <?php echo 'subscriptions' === $tab ? 'nav-tab-active' : ''; ?>">
						<?php esc_html_e( 'Subscriptions', 'woocommerce-zip4' ); ?>
					</a>
				<?php endif; ?>
			</nav>

			<div class="woocommerce-zip4-admin__content">
				<?php
				if ( 'subscriptions' === $tab ) {
					self::render_subscriptions_tab();
				} else {
					self::render_settings_tab();
				}
				?>
			</div>
		</div>
		<?php
	}

	/**
	 * Render credentials warning when USPS credentials are missing.
	 */
	private static function render_credentials_notice() {
		if ( WC_Zip4_Credentials::is_configured() ) {
			return;
		}
		?>
		<div class="notice notice-warning">
			<p>
				<?php
				echo wp_kses_post(
					__( 'USPS API credentials were not found. Install and configure the <strong>USPS Shipping Method</strong> extension, or add <code>USPS_CLIENT_ID</code> and <code>USPS_CLIENT_SECRET</code> to <code>wp-config.php</code>.', 'woocommerce-zip4' )
				);
				?>
			</p>
		</div>
		<?php
	}

	/**
	 * Render settings tab.
	 */
	private static function render_settings_tab() {
		$settings = self::get_settings();
		$fields   = array(
			'new_order_placed'                   => __( 'New order placed', 'woocommerce-zip4' ),
			'address_updated_by_customer'        => __( 'Address updated by customer', 'woocommerce-zip4' ),
			'subscription_updated_by_customer'   => __( 'Subscription updated by customer', 'woocommerce-zip4' ),
			'address_updated_by_administrator'   => __( 'Address updated by administrator', 'woocommerce-zip4' ),
			'subscription_updated_by_administrator' => __( 'Subscription updated by administrator', 'woocommerce-zip4' ),
		);
		?>
		<form method="post" action="options.php">
			<?php settings_fields( 'woocommerce_zip4_settings_group' ); ?>

			<p><?php esc_html_e( 'Check the actions that should cause USA ZIP codes to be silently updated.', 'woocommerce-zip4' ); ?></p>

			<table class="form-table" role="presentation">
				<tbody>
					<?php foreach ( $fields as $key => $label ) : ?>
						<tr>
							<th scope="row"><?php echo esc_html( $label ); ?></th>
							<td>
								<label>
									<input type="checkbox" name="<?php echo esc_attr( self::OPTION_KEY . '[' . $key . ']' ); ?>" value="1" <?php checked( ! empty( $settings[ $key ] ) ); ?> />
									<?php esc_html_e( 'Enabled', 'woocommerce-zip4' ); ?>
								</label>
							</td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>

			<?php submit_button( __( 'Save Settings', 'woocommerce-zip4' ) ); ?>
		</form>
		<?php
	}

	/**
	 * Render subscriptions tab.
	 */
	private static function render_subscriptions_tab() {
		$statuses = WC_Zip4_Subscriptions::get_status_options();
		?>
		<p><?php esc_html_e( 'This process will perform a one-time update of your WooCommerce Subscriptions to update USA shipping addresses to the ZIP+4 format.', 'woocommerce-zip4' ); ?></p>

		<form id="wc-zip4-subscriptions-form" method="post" action="">
			<table class="form-table" role="presentation">
				<tbody>
					<?php foreach ( $statuses as $key => $label ) : ?>
						<tr>
							<th scope="row"><?php echo esc_html( $label ); ?></th>
							<td>
								<label>
									<input type="checkbox" name="wc_zip4_statuses[<?php echo esc_attr( $key ); ?>]" value="1" checked="checked" />
									<?php esc_html_e( 'Include', 'woocommerce-zip4' ); ?>
								</label>
							</td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>

			<p class="submit">
				<button type="button" class="button button-primary" id="wc-zip4-process-subscriptions">
					<?php esc_html_e( 'Process Subscriptions', 'woocommerce-zip4' ); ?>
				</button>
			</p>
		</form>

		<div id="wc-zip4-progress" class="woocommerce-zip4-admin__progress" hidden>
			<p class="woocommerce-zip4-admin__progress-count">
				<strong><?php esc_html_e( 'Progress:', 'woocommerce-zip4' ); ?></strong>
				<span id="wc-zip4-progress-text">0/0</span>
			</p>
			<p id="wc-zip4-progress-status" class="woocommerce-zip4-admin__progress-status"></p>
			<ul id="wc-zip4-progress-log" class="woocommerce-zip4-admin__progress-log"></ul>
		</div>
		<?php
	}

	/**
	 * Parse selected subscription statuses from AJAX request.
	 *
	 * @return array<string, bool>
	 */
	private static function get_selected_statuses_from_request() {
		$selected = WC_Zip4_Subscriptions::get_default_status_selection();
		$input    = isset( $_POST['statuses'] ) ? wp_unslash( $_POST['statuses'] ) : array();

		if ( ! is_array( $input ) ) {
			return $selected;
		}

		foreach ( array_keys( $selected ) as $key ) {
			$selected[ $key ] = ! empty( $input[ $key ] );
		}

		return $selected;
	}

	/**
	 * AJAX: prepare subscription batch.
	 */
	public static function ajax_prepare_subscriptions() {
		check_ajax_referer( 'wc_zip4_subscriptions', 'nonce' );

		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'woocommerce-zip4' ) ), 403 );
		}

		if ( ! WC_Zip4_Credentials::is_configured() ) {
			wp_send_json_error(
				array(
					'message' => __( 'USPS credentials are not configured.', 'woocommerce-zip4' ),
				),
				400
			);
		}

		$selected = self::get_selected_statuses_from_request();
		$ids      = WC_Zip4_Subscriptions::get_pending_subscription_ids( $selected );

		wp_send_json_success(
			array(
				'total' => count( $ids ),
				'ids'   => $ids,
			)
		);
	}

	/**
	 * AJAX: process one subscription.
	 */
	public static function ajax_process_subscription() {
		check_ajax_referer( 'wc_zip4_subscriptions', 'nonce' );

		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'woocommerce-zip4' ) ), 403 );
		}

		$subscription_id = isset( $_POST['subscription_id'] ) ? absint( wp_unslash( $_POST['subscription_id'] ) ) : 0;
		$current         = isset( $_POST['current'] ) ? absint( wp_unslash( $_POST['current'] ) ) : 0;
		$total           = isset( $_POST['total'] ) ? absint( wp_unslash( $_POST['total'] ) ) : 0;

		if ( ! $subscription_id ) {
			wp_send_json_error( array( 'message' => __( 'Invalid subscription ID.', 'woocommerce-zip4' ) ), 400 );
		}

		$result = WC_Zip4_Subscriptions::process_subscription( $subscription_id );

		wp_send_json_success(
			array(
				'current' => $current,
				'total'   => $total,
				'updated' => ! empty( $result['updated'] ),
				'message' => $result['message'],
			)
		);
	}
}
