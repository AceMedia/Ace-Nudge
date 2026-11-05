<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

echo esc_html( $email_heading ) . "\n\n";
echo esc_html( 'stock' === $reason ? sprintf( '%s is back in stock.', $product->get_name() ) : sprintf( '%s has dropped in price.', $product->get_name() ) ) . "\n\n";
echo esc_url( $product->get_permalink() ) . "\n\n";
echo esc_html( 'Unsubscribe: ' ) . esc_url( $unsubscribe ) . "\n";
