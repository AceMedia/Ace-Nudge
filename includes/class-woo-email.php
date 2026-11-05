<?php
/**
 * Back in stock and price drop emails, as a normal WooCommerce email: it appears under
 * WooCommerce > Settings > Emails, and uses the store's header, footer and styles.
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

class Ace_Nudge_Product_Update_Email extends WC_Email {
	public $product;
	public $reason;
	public $unsubscribe;

	public function __construct() {
		$this->id             = 'ace_nudge_product_update';
		$this->customer_email = true;
		$this->title          = 'Product update (Ace NUDGE)';
		$this->description    = 'Sent to customers who opted in when something they bought is back in stock or drops in price.';
		$this->template_base  = ACE_NUDGE_DIR . '/templates/';
		$this->template_html  = 'emails/product-update.php';
		$this->template_plain = 'emails/plain/product-update.php';
		$this->placeholders   = array( '{product_name}' => '' );
		parent::__construct();
	}

	public function get_default_subject() {
		return '{product_name}: an update';
	}

	public function get_default_heading() {
		return 'An update on something you bought';
	}

	public function trigger( $sub, $product, $reason ) {
		$this->setup_locale();
		$this->product     = $product;
		$this->reason      = $reason;
		$this->recipient   = $sub->email;
		$this->unsubscribe = add_query_arg( 'ace_nudge_unsub', rawurlencode( Ace_Nudge_Store::token( $sub->id ) ), home_url( '/' ) );
		$this->placeholders['{product_name}'] = $product->get_name();
		if ( $this->is_enabled() && $this->get_recipient() && $this->send( $this->get_recipient(), $this->get_subject(), $this->get_content(), $this->get_headers(), $this->get_attachments() ) ) {
			Ace_Nudge_Dispatch::count_sent( 'email' );
		}
		$this->restore_locale();
	}

	private function template_args( $plain ) {
		return array(
			'product' => $this->product, 'reason' => $this->reason, 'unsubscribe' => $this->unsubscribe,
			'email_heading' => $this->get_heading(), 'additional_content' => $this->get_additional_content(),
			'sent_to_admin' => false, 'plain_text' => $plain, 'email' => $this,
		);
	}

	public function get_content_html() {
		return wc_get_template_html( $this->template_html, $this->template_args( false ), '', $this->template_base );
	}

	public function get_content_plain() {
		return wc_get_template_html( $this->template_plain, $this->template_args( true ), '', $this->template_base );
	}
}
