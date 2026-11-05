<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/** Testing and suppression tools for Ace Notifications. */
class Ace_Nudge_CLI {
	/**
	 * Import a suppression file: one email address or SHA-256 hash per line.
	 *
	 * <file>
	 */
	public function suppress( $args ) {
		global $wpdb;
		$n = 0;
		foreach ( file( $args[0], FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES ) as $line ) {
			$line = trim( $line );
			$hash = preg_match( '/^[a-f0-9]{64}$/', $line ) ? $line : Ace_Nudge_Store::email_hash( $line );
			$n   += (int) $wpdb->query( $wpdb->prepare( 'INSERT IGNORE INTO ' . Ace_Nudge_Store::t( 'suppression' ) . ' (email_hash, reason, created_at) VALUES (%s, %s, %s)', $hash, 'import', current_time( 'mysql', true ) ) );
			$sub  = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . Ace_Nudge_Store::t( 'subscribers' ) . ' WHERE endpoint_hash = %s', $hash ) );
			if ( $sub ) {
				Ace_Nudge_Store::withdraw( $sub, 'suppressed' );
			}
		}
		WP_CLI::success( "$n added." );
	}

	/** Counts of subscribers, follows and consent log entries. */
	public function status() {
		global $wpdb;
		foreach ( array( 'subscribers', 'follows', 'consent_log', 'suppression' ) as $t ) {
			WP_CLI::log( $t . ': ' . $wpdb->get_var( 'SELECT COUNT(*) FROM ' . Ace_Nudge_Store::t( $t ) ) );
		}
	}

	/**
	 * Print the newsletter as HTML for checking. Sends nothing.
	 *
	 * [--days=<days>]
	 */
	public function preview( $args, $assoc ) {
		echo Ace_Nudge_Dispatch::render_digest( Ace_Nudge_Dispatch::digest_sections( array(), (int) ( $assoc['days'] ?? 7 ) ), home_url( '/' ) );
	}

	/** Send a test notification to every push subscriber. */
	public function test_push() {
		global $wpdb;
		foreach ( $wpdb->get_results( 'SELECT * FROM ' . Ace_Nudge_Store::t( 'subscribers' ) . " WHERE channel = 'push'" ) as $sub ) {
			WP_CLI::log( '#' . $sub->id . ' HTTP ' . Ace_Nudge_Push::send( $sub, array( 'title' => 'Test', 'body' => 'Ace Notifications test', 'url' => home_url( '/' ), 'tag' => 'test' ) ) );
		}
	}
}
WP_CLI::add_command( 'ace-nudge', 'Ace_Nudge_CLI' );
