<?php
/**
 * Regression checks that run without WordPress: php tests/run.php
 *
 * Covers the pure logic (client address and trusted proxies, quiet hours, push encryption and its size
 * limit, alert wording rules). Anything that needs the database or hooks is exercised on a site; see
 * docs/developer-guide.md.
 */

define( 'ABSPATH', __DIR__ . '/' );
define( 'DAY_IN_SECONDS', 86400 );
define( 'MINUTE_IN_SECONDS', 60 );
define( 'HOUR_IN_SECONDS', 3600 );

require __DIR__ . '/../includes/class-util.php';
require __DIR__ . '/../includes/class-push.php';
require __DIR__ . '/../includes/class-dispatch.php';

$fail = 0;
$run  = 0;
function check( $name, $ok ) {
	global $fail, $run;
	++$run;
	if ( ! $ok ) {
		++$fail;
		echo "FAIL: $name\n";
	}
}

// CIDR matching.
check( 'v4 inside', Ace_Nudge_Util::ip_in_cidr( '173.245.48.9', '173.245.48.0/20' ) );
check( 'v4 outside', ! Ace_Nudge_Util::ip_in_cidr( '173.245.64.1', '173.245.48.0/20' ) );
check( 'v4 /13 edge', Ace_Nudge_Util::ip_in_cidr( '104.23.255.255', '104.16.0.0/13' ) && ! Ace_Nudge_Util::ip_in_cidr( '104.24.0.1', '104.16.0.0/13' ) );
check( 'v6 inside', Ace_Nudge_Util::ip_in_cidr( '2606:4700:10::1', '2606:4700::/32' ) );
check( 'v6 outside', ! Ace_Nudge_Util::ip_in_cidr( '2606:4701::1', '2606:4700::/32' ) );
check( 'mixed families never match', ! Ace_Nudge_Util::ip_in_cidr( '1.2.3.4', '2606:4700::/32' ) && ! Ace_Nudge_Util::ip_in_cidr( '2606:4700::1', '1.0.0.0/8' ) );
check( 'garbage is a no', ! Ace_Nudge_Util::ip_in_cidr( 'nope', '1.0.0.0/8' ) && ! Ace_Nudge_Util::ip_in_cidr( '1.2.3.4', '1.0.0.0/99' ) );
check( 'bare address is a /32', Ace_Nudge_Util::ip_in_cidr( '10.0.0.5', '10.0.0.5' ) && ! Ace_Nudge_Util::ip_in_cidr( '10.0.0.6', '10.0.0.5' ) );

// The header is only believed from a trusted proxy.
$cf = Ace_Nudge_Util::CLOUDFLARE;
check( 'spoofed header ignored from a normal visitor', '203.0.113.9' === Ace_Nudge_Util::client_ip( array( 'REMOTE_ADDR' => '203.0.113.9', 'HTTP_CF_CONNECTING_IP' => '198.51.100.1' ), $cf ) );
check( 'header believed from Cloudflare', '198.51.100.1' === Ace_Nudge_Util::client_ip( array( 'REMOTE_ADDR' => '104.16.1.1', 'HTTP_CF_CONNECTING_IP' => '198.51.100.1' ), $cf ) );
check( 'invalid header from Cloudflare ignored', '104.16.1.1' === Ace_Nudge_Util::client_ip( array( 'REMOTE_ADDR' => '104.16.1.1', 'HTTP_CF_CONNECTING_IP' => 'x' ), $cf ) );
check( 'no header', '203.0.113.9' === Ace_Nudge_Util::client_ip( array( 'REMOTE_ADDR' => '203.0.113.9' ), $cf ) );
check( 'no server data', '' === Ace_Nudge_Util::client_ip( array(), $cf ) );
check( 'own proxy list', '198.51.100.1' === Ace_Nudge_Util::client_ip( array( 'REMOTE_ADDR' => '10.1.2.3', 'HTTP_CF_CONNECTING_IP' => '198.51.100.1' ), array( '10.0.0.0/8' ) ) );

// Quiet hours.
check( 'quiet wraps midnight (late)', Ace_Nudge_Util::is_quiet( 23, 21, 8 ) );
check( 'quiet wraps midnight (early)', Ace_Nudge_Util::is_quiet( 3, 21, 8 ) );
check( 'not quiet midday', ! Ace_Nudge_Util::is_quiet( 12, 21, 8 ) );
check( 'end hour is not quiet', ! Ace_Nudge_Util::is_quiet( 8, 21, 8 ) && Ace_Nudge_Util::is_quiet( 21, 21, 8 ) );
check( 'same day window', Ace_Nudge_Util::is_quiet( 13, 12, 14 ) && ! Ace_Nudge_Util::is_quiet( 14, 12, 14 ) );
check( 'equal start and end turns it off', ! Ace_Nudge_Util::is_quiet( 0, 5, 5 ) && ! Ace_Nudge_Util::is_quiet( 5, 5, 5 ) );

// Reminder steps (kick-off) only ever send the latest step once.
check( 'reminder 60', 60 === Ace_Nudge_Dispatch::reminder_due( 55, 0 ) );
check( 'reminder not repeated', 0 === Ace_Nudge_Dispatch::reminder_due( 55, 60 ) );
check( 'reminder steps down', 30 === Ace_Nudge_Dispatch::reminder_due( 25, 60 ) && 5 === Ace_Nudge_Dispatch::reminder_due( 4, 30 ) );
check( 'no reminder after start', 0 === Ace_Nudge_Dispatch::reminder_due( 0, 0 ) && 0 === Ace_Nudge_Dispatch::reminder_due( -3, 0 ) );
check( 'no reminder far ahead', 0 === Ace_Nudge_Dispatch::reminder_due( 300, 0 ) );

// Product change reasons.
check( 'back in stock', 'stock' === Ace_Nudge_Dispatch::product_reason( array( 'stock_status' => 'outofstock', 'price' => 10 ), array( 'stock_status' => 'instock' ) ) );
check( 'already in stock is not news', '' === Ace_Nudge_Dispatch::product_reason( array( 'stock_status' => 'instock', 'price' => 10 ), array( 'stock_status' => 'instock' ) ) );
check( 'price drop', 'price' === Ace_Nudge_Dispatch::product_reason( array( 'stock_status' => 'instock', 'price' => 10 ), array( 'sale_price' => '8' ) ) );
check( 'price rise is not news', '' === Ace_Nudge_Dispatch::product_reason( array( 'stock_status' => 'instock', 'price' => 10 ), array( 'regular_price' => '12' ) ) );

// Change lines for a pick.
check( 'change: no news', '' === Ace_Nudge_Dispatch::slip_change( array( 'runner' => 'A', 'dec' => 2.0 ), array( 'state' => 'open', 'dec' => 2.02 ) ) );
check( 'change: moved 4 percent', 'A shortened to 1.9' === Ace_Nudge_Dispatch::slip_change( array( 'runner' => 'A', 'dec' => 2.0 ), array( 'state' => 'open', 'dec' => 1.9 ) ) );
check( 'change: uses the site text for the value', 'A drifted to 5/2' === Ace_Nudge_Dispatch::slip_change( array( 'runner' => 'A', 'dec' => 2.0 ), array( 'state' => 'open', 'dec' => 3.5, 'frac' => '5/2' ) ) );
check( 'change: unnamed', 'A pick: market closed' === Ace_Nudge_Dispatch::slip_change( array(), array( 'state' => 'closed' ) ) );

// Push encryption: round trip as the browser would decrypt it (RFC 8291), and the size limit.
if ( extension_loaded( 'openssl' ) ) {
	$ua  = openssl_pkey_new( array( 'private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1' ) );
	$d   = openssl_pkey_get_details( $ua )['ec'];
	$pub = "\x04" . str_pad( $d['x'], 32, "\0", STR_PAD_LEFT ) . str_pad( $d['y'], 32, "\0", STR_PAD_LEFT );
	$auth = random_bytes( 16 );
	$body = Ace_Nudge_Push::encrypt( '{"title":"Hello"}', Ace_Nudge_Push::b64( $pub ), Ace_Nudge_Push::b64( $auth ) );
	check( 'encrypt returns a body', is_string( $body ) && strlen( $body ) > 86 );
	if ( is_string( $body ) ) {
		$salt = substr( $body, 0, 16 );
		$as   = substr( $body, 21, 65 );
		$ct   = substr( $body, 86 );
		$pem  = "-----BEGIN PUBLIC KEY-----\n" . chunk_split( base64_encode( hex2bin( Ace_Nudge_Push::SPKI ) . $as ), 64, "\n" ) . "-----END PUBLIC KEY-----\n";
		$sec  = openssl_pkey_derive( openssl_pkey_get_public( $pem ), $ua, 32 );
		$ikm  = hash_hkdf( 'sha256', $sec, 32, "WebPush: info\0" . $pub . $as, $auth );
		$cek  = hash_hkdf( 'sha256', $ikm, 16, "Content-Encoding: aes128gcm\0", $salt );
		$non  = hash_hkdf( 'sha256', $ikm, 12, "Content-Encoding: nonce\0", $salt );
		$plain = openssl_decrypt( substr( $ct, 0, -16 ), 'aes-128-gcm', $cek, OPENSSL_RAW_DATA, $non, substr( $ct, -16 ) );
		check( 'encrypt round trip', "{\"title\":\"Hello\"}\x02" === $plain );
	}
	check( 'largest payload is accepted', is_string( Ace_Nudge_Push::encrypt( str_repeat( 'a', Ace_Nudge_Push::MAX_PAYLOAD ), Ace_Nudge_Push::b64( $pub ), Ace_Nudge_Push::b64( $auth ) ) ) );
	$big = Ace_Nudge_Push::encrypt( str_repeat( 'a', Ace_Nudge_Push::MAX_PAYLOAD + 1 ), Ace_Nudge_Push::b64( $pub ), Ace_Nudge_Push::b64( $auth ) );
	check( 'oversize payload is refused, not sent to be rejected', null === $big );
} else {
	echo "SKIP: openssl not loaded\n";
}

echo "$run checks, $fail failed (PHP " . PHP_VERSION . ")\n";
exit( $fail ? 1 : 0 );
