<?php
/**
 * Plugin Name: Ace NUDGE
 * Description: Notifications, Updates, Digests, Gated Email. Opt-in browser notifications and an email newsletter: follow a story, product, category or game, or the whole site, with consent evidence and an admin overview.
 * Version: 0.3.2
 * Author: Shane Rounce
 * Requires at least: 6.5
 * Requires PHP: 8.1
 * License: GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: ace-nudge
 *
 * ACE_NUDGE_MODE in wp-config.php:
 *   off   (default) nothing is printed or sent. Admin screens still work.
 *   test  the prompt only shows after visiting ?ace_nudge_preview=1, and email only goes to
 *         the addresses in ACE_NUDGE_TEST_EMAILS. ACE_NUDGE_SHOW_ALL shows the prompt to everyone.
 *   on    open to visitors.
 *
 * Anything site-specific comes through filters, so regulated sites add their own rules:
 *   ace_nudge_min_age (overrides the setting), ace_nudge_wording, ace_nudge_promo_pattern, ace_nudge_email_footer,
 *   ace_nudge_brand, ace_nudge_bell_selector, ace_nudge_host_selector, ace_nudge_top_categories.
 *
 * An age question runs in the browser and only the fact that it was passed reaches the server.
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

define( 'ACE_NUDGE_VERSION', '0.3.0' );
define( 'ACE_NUDGE_DIR', __DIR__ );
define( 'ACE_NUDGE_URL', plugin_dir_url( __FILE__ ) );

foreach ( array( 'settings', 'store', 'push', 'rest', 'dispatch', 'front', 'blocks', 'privacy', 'woo' ) as $part ) {
	require_once __DIR__ . '/includes/class-' . $part . '.php';
}
if ( is_admin() ) {
	require_once __DIR__ . '/includes/class-admin.php';
	Ace_Nudge_Admin::boot();
}
if ( defined( 'WP_CLI' ) && WP_CLI ) {
	require_once __DIR__ . '/includes/class-cli.php';
}

function ace_nudge_mode() {
	$mode = defined( 'ACE_NUDGE_MODE' ) ? (string) ACE_NUDGE_MODE : 'off';
	return in_array( $mode, array( 'test', 'on' ), true ) ? $mode : 'off';
}

add_action( 'init', array( 'Ace_Nudge_Store', 'maybe_install' ) );
Ace_Nudge_Blocks::boot();
Ace_Nudge_Privacy::boot();

if ( 'off' !== ace_nudge_mode() ) {
	Ace_Nudge_Front::boot();
	Ace_Nudge_Rest::boot();
	Ace_Nudge_Dispatch::boot();
	add_action( 'plugins_loaded', array( 'Ace_Nudge_Woo', 'boot' ) );
}
