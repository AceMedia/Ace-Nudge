<?php
/**
 * WordPress's own export and erase tools (Tools > Export/Erase Personal Data) for email subscribers.
 * Browser subscribers are anonymous, so they withdraw from the browser itself.
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

class Ace_Nudge_Privacy {
	public static function boot() {
		add_filter( 'wp_privacy_personal_data_exporters', function ( $e ) {
			$e['ace-nudge'] = array( 'exporter_friendly_name' => 'Ace NUDGE', 'callback' => array( __CLASS__, 'export' ) );
			return $e;
		} );
		add_filter( 'wp_privacy_personal_data_erasers', function ( $e ) {
			$e['ace-nudge'] = array( 'eraser_friendly_name' => 'Ace NUDGE', 'callback' => array( __CLASS__, 'erase' ) );
			return $e;
		} );
	}

	private static function find( $email ) {
		global $wpdb;
		return $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . Ace_Nudge_Store::t( 'subscribers' ) . ' WHERE endpoint_hash = %s', Ace_Nudge_Store::email_hash( $email ) ) );
	}

	public static function export( $email ) {
		$sub  = self::find( $email );
		$data = array();
		if ( $sub ) {
			$data[] = array(
				'group_id'    => 'ace-nudge',
				'group_label' => 'Newsletter',
				'item_id'     => 'ace-nudge-' . $sub->id,
				'data'        => array(
					array( 'name' => 'Email', 'value' => $sub->email ),
					array( 'name' => 'Status', 'value' => $sub->status ),
					array( 'name' => 'Signed up', 'value' => $sub->created_at ),
				),
			);
		}
		return array( 'data' => $data, 'done' => true );
	}

	public static function erase( $email ) {
		$sub = self::find( $email );
		if ( $sub ) {
			Ace_Nudge_Store::withdraw( $sub, 'erased' );
		}
		return array( 'items_removed' => (bool) $sub, 'items_retained' => false, 'messages' => array(), 'done' => true );
	}
}
