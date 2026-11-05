<?php
/**
 * Back in stock or price drop. Copy to yourtheme/woocommerce/emails/ to override.
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

do_action( 'woocommerce_email_header', $email_heading, $email );
?>
<p><?php echo esc_html( 'stock' === $reason ? sprintf( '%s is back in stock.', $product->get_name() ) : sprintf( '%s has dropped in price.', $product->get_name() ) ); ?></p>
<p><?php echo wp_kses_post( $product->get_price_html() ); ?></p>
<p><a href="<?php echo esc_url( $product->get_permalink() ); ?>"><?php echo esc_html( 'View product' ); ?></a></p>
<?php if ( $additional_content ) : ?>
	<p><?php echo wp_kses_post( wptexturize( $additional_content ) ); ?></p>
<?php endif; ?>
<p><a href="<?php echo esc_url( $unsubscribe ); ?>"><?php echo esc_html( 'Unsubscribe' ); ?></a></p>
<?php
do_action( 'woocommerce_email_footer', $email );
