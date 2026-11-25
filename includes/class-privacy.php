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
			global $wpdb;
			$follows = array();
			foreach ( $wpdb->get_results( $wpdb->prepare( 'SELECT type, object_id, created_at FROM ' . Ace_Nudge_Store::t( 'follows' ) . ' WHERE subscriber_id = %d ORDER BY id', $sub->id ) ) as $f ) {
				$follows[] = array( 'name' => $f->type . ' (' . $f->created_at . ' UTC)', 'value' => $f->object_id );
			}
			if ( $follows ) {
				$data[] = array( 'group_id' => 'ace-nudge-follows', 'group_label' => 'Newsletter choices', 'item_id' => 'ace-nudge-follows-' . $sub->id, 'data' => $follows );
			}
		}
		// The consent log holds a hash of the address, so it is found by hashing the address given.
		$log = array();
		global $wpdb;
		foreach ( $wpdb->get_results( $wpdb->prepare( 'SELECT id, channel, action, wording_version, wording, created_at FROM ' . Ace_Nudge_Store::t( 'consent_log' ) . ' WHERE subject_hash = %s ORDER BY id', Ace_Nudge_Store::email_hash( $email ) ) ) as $e ) {
			$log[] = array( 'group_id' => 'ace-nudge-consent', 'group_label' => 'Consent record', 'item_id' => 'ace-nudge-consent-' . $e->id, 'data' => array(
				array( 'name' => 'When (UTC)', 'value' => $e->created_at ),
				array( 'name' => 'What', 'value' => $e->channel . ': ' . $e->action ),
				array( 'name' => 'Wording shown (' . $e->wording_version . ')', 'value' => $e->wording ),
			) );
		}
		$data = array_merge( $data, $log );
		return array( 'data' => $data, 'done' => true );
	}

	public static function erase( $email ) {
		$sub = self::find( $email );
		if ( $sub ) {
			Ace_Nudge_Store::withdraw( $sub, 'erased' );
		}
		// The consent log entry (a hash, never the address) stays as evidence of what was agreed and when.
		return array( 'items_removed' => (bool) $sub, 'items_retained' => (bool) $sub, 'messages' => $sub ? array( 'Ace NUDGE keeps a hashed record that consent was given and withdrawn, without the address, as evidence.' ) : array(), 'done' => true );
	}
}
