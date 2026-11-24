<?php
/**
 * Tables, consent wording and the consent log.
 *
 * Regulated sites need evidence of consent, so every grant and withdrawal is
 * logged with the exact wording shown. The log holds hashes, never an address or endpoint.
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

class Ace_Nudge_Store {
	const DB_VERSION      = '1';
	const WORDING_VERSION = '2026-11-05-draft';

	public static function t( $name ) {
		global $wpdb;
		return $wpdb->prefix . 'ace_nudge_' . $name;
	}

	public static function maybe_install() {
		if ( get_option( 'ace_nudge_db' ) === self::DB_VERSION ) {
			return;
		}
		global $wpdb;
		self::migrate_prototype();
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$c = $wpdb->get_charset_collate();
		dbDelta( 'CREATE TABLE ' . self::t( 'subscribers' ) . " (
			id bigint unsigned NOT NULL AUTO_INCREMENT,
			channel varchar(10) NOT NULL,
			status varchar(10) NOT NULL,
			email varchar(190) NULL,
			endpoint text NULL,
			endpoint_hash char(64) NOT NULL,
			p256dh varchar(120) NULL,
			auth varchar(60) NULL,
			created_at datetime NOT NULL,
			last_sent_at datetime NULL,
			sent_day char(10) NULL,
			sent_count smallint unsigned NOT NULL DEFAULT 0,
			PRIMARY KEY  (id),
			UNIQUE KEY endpoint_hash (endpoint_hash),
			KEY channel_status (channel,status)
		) $c;" );
		dbDelta( 'CREATE TABLE ' . self::t( 'follows' ) . " (
			id bigint unsigned NOT NULL AUTO_INCREMENT,
			subscriber_id bigint unsigned NOT NULL,
			type varchar(10) NOT NULL,
			object_id varchar(190) NOT NULL,
			meta text NULL,
			created_at datetime NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY one_follow (subscriber_id,type,object_id),
			KEY type_object (type,object_id)
		) $c;" );
		dbDelta( 'CREATE TABLE ' . self::t( 'consent_log' ) . " (
			id bigint unsigned NOT NULL AUTO_INCREMENT,
			subject_hash char(64) NOT NULL,
			channel varchar(10) NOT NULL,
			action varchar(20) NOT NULL,
			wording_version varchar(30) NOT NULL,
			wording text NOT NULL,
			age_confirmed tinyint unsigned NOT NULL DEFAULT 0,
			created_at datetime NOT NULL,
			PRIMARY KEY  (id),
			KEY subject_hash (subject_hash)
		) $c;" );
		dbDelta( 'CREATE TABLE ' . self::t( 'suppression' ) . " (
			email_hash char(64) NOT NULL,
			reason varchar(30) NOT NULL,
			created_at datetime NOT NULL,
			PRIMARY KEY  (email_hash)
		) $c;" );
		update_option( 'ace_nudge_db', self::DB_VERSION, false );
	}

	/** The prototype was called ace_notify. Keep its keys, or browsers can't stay subscribed. */
	private static function migrate_prototype() {
		global $wpdb;
		foreach ( array( 'subscribers', 'follows', 'consent_log', 'suppression' ) as $t ) {
			$old = $wpdb->prefix . 'ace_notify_' . $t;
			if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $old ) ) === $old ) {
				$wpdb->query( "RENAME TABLE `$old` TO `" . self::t( $t ) . '`' );
			}
		}
		foreach ( array( 'vapid', 'fixture_state', 'batch_since', 'updated' ) as $o ) {
			$value = get_option( 'ace_notify_' . $o, null );
			if ( null !== $value ) {
				update_option( 'ace_nudge_' . $o, $value, false );
				delete_option( 'ace_notify_' . $o );
			}
		}
		delete_option( 'ace_notify_db' );
		foreach ( array( 'minute', 'hourly', 'digest' ) as $h ) {
			wp_clear_scheduled_hook( 'ace_notify_' . $h );
		}
	}

	/** The wording shown beside each unticked box. Draft until compliance approves it. */
	public static function wording( $channel ) {
		$name  = Ace_Nudge_Settings::brand()['name'];
		$words = apply_filters( 'ace_nudge_wording', array(
			'push'  => "Send me browser notifications from $name about what I follow. I can turn them off at any time.",
			'orders' => 'Send order updates for my purchases to this device.',
			'product_updates' => 'Email me when something I\'ve bought is back in stock or drops in price. I can unsubscribe at any time.',
			'reads' => 'Remembers which pages you open on this browser for 30 days, only to tell you when one changes.',
			'email' => "Email me the $name newsletter. I can unsubscribe at any time using the link in every email.",
		) );
		return $words[ $channel ] ?? '';
	}

	public static function log( $subject, $channel, $action, $age_confirmed = false, $wording = '' ) {
		global $wpdb;
		$wpdb->insert( self::t( 'consent_log' ), array(
			'subject_hash'    => $subject,
			'channel'         => $channel,
			'action'          => $action,
			'wording_version' => self::WORDING_VERSION,
			'wording'         => self::wording( $wording ?: $channel ),
			'age_confirmed'   => $age_confirmed ? 1 : 0,
			'created_at'      => current_time( 'mysql', true ),
		) );
	}

	public static function email_hash( $email ) {
		return hash( 'sha256', strtolower( trim( $email ) ) );
	}

	public static function is_suppressed( $email ) {
		global $wpdb;
		return (bool) $wpdb->get_var( $wpdb->prepare( 'SELECT 1 FROM ' . self::t( 'suppression' ) . ' WHERE email_hash = %s', self::email_hash( $email ) ) );
	}

	/** A token proves ownership of a subscription without one being stored. The salt text predates the rename. */
	public static function token( $id ) {
		return $id . '.' . substr( hash_hmac( 'sha256', 'ace-notify|' . $id, wp_salt( 'auth' ) ), 0, 40 );
	}

	public static function subscriber_from_token( $token ) {
		global $wpdb;
		$id = (int) strtok( (string) $token, '.' );
		if ( ! $id || ! hash_equals( self::token( $id ), (string) $token ) ) {
			return null;
		}
		return $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::t( 'subscribers' ) . ' WHERE id = %d', $id ) );
	}

	/** Withdrawal erases the subscription and its follows. Only the hashed log entry stays. */
	public static function withdraw( $sub, $action = 'withdrawn' ) {
		global $wpdb;
		self::log( $sub->endpoint_hash, $sub->channel, $action );
		$wpdb->delete( self::t( 'follows' ), array( 'subscriber_id' => $sub->id ) );
		$wpdb->delete( self::t( 'subscribers' ), array( 'id' => $sub->id ) );
	}
}
