<?php
/**
 * Runs when the plugin is deleted from the Plugins screen.
 *
 * Settings, keys, queues and scheduled events always go. The tables hold the subscriber list and the
 * consent log, which is evidence a regulated site may be required to keep, so they are only dropped
 * when wp-config.php says so: define( 'ACE_NUDGE_REMOVE_DATA', true );
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) { exit; }

global $wpdb;

foreach ( array( 'ace_nudge_minute', 'ace_nudge_hourly', 'ace_nudge_digest' ) as $hook ) {
	wp_clear_scheduled_hook( $hook );
}
foreach ( array( 'ace_nudge_settings', 'ace_nudge_vapid', 'ace_nudge_fixture_state', 'ace_nudge_batch_since', 'ace_nudge_tips_since', 'ace_nudge_updated', 'ace_nudge_update_log', 'ace_nudge_sent', 'ace_nudge_db' ) as $option ) {
	delete_option( $option );
}
foreach ( array( 'live', 'batch', 'digest' ) as $lock ) {
	delete_option( 'ace_nudge_lock_' . $lock );
}
delete_transient( 'ace_nudge_feed_v1' );
$wpdb->query( $wpdb->prepare( "DELETE FROM $wpdb->options WHERE option_name LIKE %s OR option_name LIKE %s", $wpdb->esc_like( '_transient_ace_nudge_' ) . '%', $wpdb->esc_like( '_transient_timeout_ace_nudge_' ) . '%' ) );
delete_metadata( 'user', 0, '_ace_nudge_email_off', '', true );

if ( defined( 'ACE_NUDGE_REMOVE_DATA' ) && ACE_NUDGE_REMOVE_DATA ) {
	foreach ( array( 'subscribers', 'follows', 'consent_log', 'suppression' ) as $table ) {
		$wpdb->query( 'DROP TABLE IF EXISTS `' . $wpdb->prefix . 'ace_nudge_' . $table . '`' );
	}
}
