<?php
/**
 * WooCommerce: order updates on a device, and email choices that lean on WooCommerce's own system.
 * Essential order emails are never touched. No newsletter onboarding here.
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

class Ace_Nudge_Woo {
	const ESSENTIAL  = array( 'customer_on_hold_order', 'customer_processing_order', 'customer_completed_order', 'customer_refunded_order', 'customer_partially_refunded_order', 'customer_invoice', 'customer_reset_password', 'customer_new_account', 'customer_failed_order', 'customer_cancelled_order' );
	const PREFS_META = '_ace_nudge_email_off';
	const FIELD      = 'ace-nudge/product-updates';

	public static function boot() {
		if ( ! class_exists( 'WooCommerce' ) ) {
			return;
		}
		add_action( 'woocommerce_order_status_changed', array( __CLASS__, 'status_changed' ), 10, 4 );
		add_action( 'woocommerce_thankyou', array( __CLASS__, 'thankyou_prompt' ), 5 );
		add_action( 'woocommerce_edit_account_form', array( __CLASS__, 'account_fields' ) );
		add_action( 'woocommerce_save_account_details', array( __CLASS__, 'save_account' ) );
		// After customer details, because the payment section is replaced whenever checkout refreshes.
		add_action( 'woocommerce_checkout_after_customer_details', array( __CLASS__, 'checkout_field' ) );
		add_action( 'woocommerce_register_form', array( __CLASS__, 'register_fields' ) );
		add_action( 'woocommerce_created_customer', array( __CLASS__, 'created_customer' ) );
		add_action( 'woocommerce_account_dashboard', array( __CLASS__, 'account_prompt' ) );
		add_action( 'woocommerce_view_order', array( __CLASS__, 'account_prompt' ), 5 );
		add_action( 'woocommerce_checkout_order_processed', array( __CLASS__, 'classic_checkout' ), 10, 1 );
		add_action( 'woocommerce_store_api_checkout_order_processed', array( __CLASS__, 'block_checkout' ) );
		add_action( 'woocommerce_init', array( __CLASS__, 'block_field' ) );
		add_filter( 'woocommerce_email_classes', array( __CLASS__, 'email_class' ) );
		add_action( 'ace_nudge_updates_ready', array( __CLASS__, 'product_emails' ) );
		add_action( 'init', function () {
			foreach ( (array) Ace_Nudge_Settings::get( 'wc_optional_emails' ) as $id ) {
				if ( ! in_array( $id, self::ESSENTIAL, true ) ) {
					add_filter( 'woocommerce_email_enabled_' . $id, array( __CLASS__, 'email_enabled' ), 20, 3 );
				}
			}
		} );
	}

	/** Customer-facing WooCommerce emails that aren't essential. Security and payment prompts never qualify. */
	public static function optional_email_choices() {
		$out = array();
		foreach ( WC()->mailer()->get_emails() as $email ) {
			if ( $email->is_customer_email() && ! in_array( $email->id, self::ESSENTIAL, true ) && 'ace_nudge_product_update' !== $email->id && ! preg_match( '/verify|auth|failed|renewal|_pos_/', $email->id ) ) {
				$out[ $email->id ] = $email->get_title();
			}
		}
		return $out;
	}

	public static function status_message( $order, $status ) {
		return array(
			'title' => 'Order #' . $order->get_order_number(),
			'body'  => 'Your order is now: ' . wc_get_order_status_name( $status ),
			'url'   => $order->get_user_id() ? $order->get_view_order_url() : $order->get_checkout_order_received_url(),
			'tag'   => 'order-' . $order->get_id(),
		);
	}

	/** Order updates are service messages, so they skip the daily limit and quiet hours. */
	public static function status_changed( $order_id, $from, $to, $order ) {
		global $wpdb;
		if ( ! in_array( $to, (array) Ace_Nudge_Settings::get( 'order_statuses' ), true ) ) {
			return;
		}
		$s    = Ace_Nudge_Store::t( 'subscribers' );
		$f    = Ace_Nudge_Store::t( 'follows' );
		$rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT DISTINCT s.* FROM $s s JOIN $f f ON f.subscriber_id = s.id WHERE s.channel = 'push' AND ( ( f.type = 'order' AND f.object_id = %s ) OR ( f.type = 'customer' AND f.object_id = %s AND %d > 0 ) )",
			(string) $order_id, (string) $order->get_user_id(), $order->get_user_id()
		) );
		foreach ( $rows as $sub ) {
			Ace_Nudge_Dispatch::deliver( $sub, self::status_message( $order, $to ), 'high' );
		}
		if ( in_array( $to, array( 'completed', 'refunded', 'cancelled' ), true ) ) {
			$wpdb->delete( $f, array( 'type' => 'order', 'object_id' => (string) $order_id ) );
		}
	}

	private static function device_button( array $data ) {
		$attrs = '';
		foreach ( $data as $k => $v ) {
			$attrs .= ' data-' . $k . '="' . esc_attr( $v ) . '"';
		}
		echo '<div class="ace-nudge-orders" data-ace-nudge="orders"' . $attrs . '><p>' . esc_html( Ace_Nudge_Store::wording( 'orders' ) ) . '</p><button type="button" class="button">' . esc_html__( 'Turn on order updates', 'ace-nudge' ) . '</button><p data-ace-nudge-note role="status"></p></div>'; // phpcs:ignore WordPress.Security.EscapeOutput
	}

	public static function thankyou_prompt( $order_id ) {
		$order = wc_get_order( $order_id );
		if ( $order ) {
			self::device_button( array( 'order' => $order->get_id(), 'key' => $order->get_order_key() ) );
		}
	}

	public static function account_fields() {
		$user = get_current_user_id();
		$off  = (array) get_user_meta( $user, self::PREFS_META, true );
		$all  = self::optional_email_choices();
		echo '<fieldset class="ace-nudge-account"><legend>' . esc_html__( 'Updates', 'ace-nudge' ) . '</legend>';
		foreach ( (array) Ace_Nudge_Settings::get( 'wc_optional_emails' ) as $id ) {
			if ( isset( $all[ $id ] ) ) {
				printf( '<p><label><input type="checkbox" name="ace_nudge_emails[]" value="%s" %s> %s</label></p>', esc_attr( $id ), checked( ! in_array( $id, $off, true ), true, false ), esc_html( $all[ $id ] ) );
			}
		}
		printf( '<input type="hidden" name="ace_nudge_account" value="1"><p><label><input type="checkbox" name="ace_nudge_product_updates" value="1" %s> %s</label></p>', checked( (bool) self::product_subscriber( wp_get_current_user()->user_email ), true, false ), esc_html( Ace_Nudge_Store::wording( 'product_updates' ) ) );
		self::device_button( array( 'nonce' => wp_create_nonce( 'wp_rest' ) ) );
		echo '</fieldset>';
	}

	public static function save_account( $user_id ) {
		if ( empty( $_POST['ace_nudge_account'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification -- WooCommerce checks the form's nonce.
			return;
		}
		$on  = array_map( 'sanitize_key', (array) ( $_POST['ace_nudge_emails'] ?? array() ) ); // phpcs:ignore WordPress.Security.NonceVerification
		$off = array_values( array_diff( (array) Ace_Nudge_Settings::get( 'wc_optional_emails' ), $on ) );
		update_user_meta( $user_id, self::PREFS_META, $off );
		self::set_product_updates( (string) get_userdata( $user_id )->user_email, ! empty( $_POST['ace_nudge_product_updates'] ) ); // phpcs:ignore WordPress.Security.NonceVerification
	}

	/** WooCommerce's own switch for each email, honouring what the customer chose. */
	public static function email_enabled( $enabled, $object, $email = null ) {
		if ( ! $enabled || ! $email || ! ( $object instanceof WC_Order ) || ! $object->get_user_id() ) {
			return $enabled;
		}
		return ! in_array( $email->id, (array) get_user_meta( $object->get_user_id(), self::PREFS_META, true ), true );
	}

	public static function product_subscriber( $email ) {
		global $wpdb;
		$s = Ace_Nudge_Store::t( 'subscribers' );
		$f = Ace_Nudge_Store::t( 'follows' );
		return $wpdb->get_row( $wpdb->prepare( "SELECT s.* FROM $s s JOIN $f f ON f.subscriber_id = s.id WHERE s.endpoint_hash = %s AND f.type = 'wc' AND f.object_id = 'product_updates'", Ace_Nudge_Store::email_hash( $email ) ) );
	}

	/** Checkout and account emails are already the customer's own, so there's no confirmation step. */
	public static function set_product_updates( $email, $on ) {
		global $wpdb;
		$s    = Ace_Nudge_Store::t( 'subscribers' );
		$f    = Ace_Nudge_Store::t( 'follows' );
		$hash = Ace_Nudge_Store::email_hash( $email );
		$sub  = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $s WHERE endpoint_hash = %s", $hash ) );
		if ( ! $on ) {
			if ( $sub && self::product_subscriber( $email ) ) {
				$wpdb->delete( $f, array( 'subscriber_id' => $sub->id, 'type' => 'wc', 'object_id' => 'product_updates' ) );
				Ace_Nudge_Store::log( $hash, 'email', 'product_updates_off', false, 'product_updates' );
			}
			return;
		}
		if ( ! is_email( $email ) || Ace_Nudge_Store::is_suppressed( $email ) || self::product_subscriber( $email ) ) {
			return;
		}
		if ( ! $sub ) {
			$wpdb->insert( $s, array( 'channel' => 'email', 'status' => 'active', 'email' => $email, 'endpoint_hash' => $hash, 'created_at' => current_time( 'mysql', true ) ) );
			$id = (int) $wpdb->insert_id;
		} else {
			$id = (int) $sub->id;
		}
		$wpdb->insert( $f, array( 'subscriber_id' => $id, 'type' => 'wc', 'object_id' => 'product_updates', 'meta' => '[]', 'created_at' => current_time( 'mysql', true ) ) );
		Ace_Nudge_Store::log( $hash, 'email', 'product_updates_on', false, 'product_updates' );
	}

	/**
	 * Ticking asks the browser straight away, as it only allows that on a click. The device is
	 * tied to the order or account on the next page, once one exists.
	 */
	private static function device_tick() {
		printf( '<p class="form-row ace-nudge-tick" data-ace-nudge="device"><label class="woocommerce-form__label woocommerce-form__label-for-checkbox checkbox"><input type="checkbox" class="woocommerce-form__input woocommerce-form__input-checkbox"> <span>%s</span></label><span data-ace-nudge-note role="status"></span></p>', esc_html( Ace_Nudge_Store::wording( 'orders' ) ) );
	}

	private static function product_tick() {
		printf( '<p class="form-row"><label class="woocommerce-form__label woocommerce-form__label-for-checkbox checkbox"><input type="checkbox" class="woocommerce-form__input woocommerce-form__input-checkbox" name="ace_nudge_product_updates" value="1"> <span>%s</span></label></p>', esc_html( Ace_Nudge_Store::wording( 'product_updates' ) ) );
	}

	public static function register_fields() {
		self::device_tick();
		self::product_tick();
	}

	public static function created_customer( $customer_id ) {
		if ( ! empty( $_POST['ace_nudge_product_updates'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification -- WooCommerce checks the form's nonce.
			self::set_product_updates( (string) get_userdata( $customer_id )->user_email, true );
		}
	}

	public static function account_prompt() {
		self::device_button( array( 'nonce' => wp_create_nonce( 'wp_rest' ) ) );
	}

	public static function checkout_field() {
		self::device_tick();
		self::product_tick();
	}

	public static function classic_checkout( $order_id ) {
		$order = wc_get_order( $order_id );
		if ( $order && ! empty( $_POST['ace_nudge_product_updates'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification -- WooCommerce checks the checkout nonce.
			self::set_product_updates( $order->get_billing_email(), true );
		}
	}

	public static function block_field() {
		if ( function_exists( 'woocommerce_register_additional_checkout_field' ) ) {
			woocommerce_register_additional_checkout_field( array(
				'id'       => self::FIELD,
				'label'    => Ace_Nudge_Store::wording( 'product_updates' ),
				'location' => 'order',
				'type'     => 'checkbox',
			) );
		}
	}

	public static function block_checkout( $order ) {
		if ( $order->get_meta( '_wc_other/' . self::FIELD ) ) {
			self::set_product_updates( $order->get_billing_email(), true );
		}
	}

	public static function email_class( $classes ) {
		require_once __DIR__ . '/class-woo-email.php';
		$classes['Ace_Nudge_Product_Update_Email'] = new Ace_Nudge_Product_Update_Email();
		return $classes;
	}

	/** People who opted in and bought the product. Uses WooCommerce's lookup tables, which cover guests. */
	public static function buyers_who_opted_in( $product_id ) {
		global $wpdb;
		$s = Ace_Nudge_Store::t( 'subscribers' );
		$f = Ace_Nudge_Store::t( 'follows' );
		return $wpdb->get_results( $wpdb->prepare(
			"SELECT DISTINCT s.* FROM {$wpdb->prefix}wc_order_product_lookup l
			JOIN {$wpdb->prefix}wc_customer_lookup c ON c.customer_id = l.customer_id
			JOIN $s s ON s.email = c.email AND s.channel = 'email'
			JOIN $f f ON f.subscriber_id = s.id AND f.type = 'wc' AND f.object_id = 'product_updates'
			WHERE l.product_id = %d",
			$product_id
		) );
	}

	public static function product_emails( array $updated ) {
		$mailer = WC()->mailer()->get_emails();
		$email  = $mailer['Ace_Nudge_Product_Update_Email'] ?? null;
		if ( ! $email ) {
			return;
		}
		foreach ( $updated as $id => $reason ) {
			$product = in_array( $reason, array( 'stock', 'price' ), true ) ? wc_get_product( $id ) : null;
			if ( $product ) {
				foreach ( self::buyers_who_opted_in( $id ) as $sub ) {
					$email->trigger( $sub, $product, $reason );
				}
			}
		}
	}
}
