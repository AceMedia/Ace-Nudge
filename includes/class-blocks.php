<?php
/**
 * Blocks: a follow button for the current page, an alerts button and a newsletter button.
 * Rendered on the server and wired up by the front-end script, so they work on cached pages.
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

class Ace_Nudge_Blocks {
	public static function boot() {
		add_action( 'init', array( __CLASS__, 'register' ) );
	}

	public static function register() {
		wp_register_script( 'ace-nudge-blocks', ACE_NUDGE_URL . 'assets/blocks.js', array( 'wp-blocks', 'wp-element', 'wp-block-editor', 'wp-components', 'wp-i18n' ), (string) filemtime( ACE_NUDGE_DIR . '/assets/blocks.js' ), true );
		foreach ( array( 'follow', 'alerts-button', 'newsletter-button' ) as $name ) {
			register_block_type( ACE_NUDGE_DIR . '/blocks/' . $name, array( 'render_callback' => array( __CLASS__, 'render' ) ) );
		}
	}

	public static function render( $attributes, $content, $block ) {
		if ( 'off' === ace_nudge_mode() ) {
			return '';
		}
		$kind    = substr( $block->name, strlen( 'ace-nudge/' ) );
		$wrapper = get_block_wrapper_attributes( array(
			'class'          => 'ace-nudge-block',
			'data-ace-nudge' => str_replace( '-button', '', $kind ),
		) );
		if ( 'follow' === $kind ) {
			return "<div $wrapper></div>";
		}
		return "<div $wrapper><button type=\"button\">" . esc_html( $attributes['label'] ?? '' ) . '</button></div>';
	}
}
