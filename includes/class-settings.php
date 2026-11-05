<?php
/**
 * Settings, and the helpers that follow from them.
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

class Ace_Nudge_Settings {
	const OPTION = 'ace_nudge_settings';

	public static function all() {
		return wp_parse_args( (array) get_option( self::OPTION, array() ), array(
			'post_types'  => array( 'post' ),
			'daily_cap'   => 3,
			'quiet_start' => 21,
			'quiet_end'   => 8,
			'auto_bar'    => 1,
		) );
	}

	public static function get( $key ) {
		return self::all()[ $key ] ?? null;
	}

	/** Enabled post types that still exist, so WooCommerce can come and go. */
	public static function post_types() {
		return array_values( array_filter( (array) self::get( 'post_types' ), 'post_type_exists' ) );
	}

	/** Taxonomies people can follow: categories for posts, product categories for products. */
	public static function taxonomies() {
		$out = array();
		foreach ( self::post_types() as $type ) {
			if ( 'post' === $type ) {
				$out[] = 'category';
			} elseif ( 'product' === $type && taxonomy_exists( 'product_cat' ) ) {
				$out[] = 'product_cat';
			}
		}
		return apply_filters( 'ace_nudge_taxonomies', $out );
	}

	public static function min_age() {
		return max( 0, (int) apply_filters( 'ace_nudge_min_age', 0 ) );
	}

	public static function brand() {
		return wp_parse_args( apply_filters( 'ace_nudge_brand', array() ), array(
			'name'    => get_bloginfo( 'name' ),
			'primary' => '#1d2327',
			'accent'  => '#2271b1',
			'page'    => '#f0f0f1',
			'logo'    => '',
		) );
	}
}
