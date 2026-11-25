<?php
/**
 * Small pure helpers with no WordPress dependency, so they can be tested from the command line
 * (see tests/run.php): client address resolution, CIDR matching and quiet hours.
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

class Ace_Nudge_Util {
	/** Cloudflare's published edge ranges. Their header is only believed when the request really came from one. */
	const CLOUDFLARE = array(
		'173.245.48.0/20', '103.21.244.0/22', '103.22.200.0/22', '103.31.4.0/22', '141.101.64.0/18', '108.162.192.0/18',
		'190.93.240.0/20', '188.114.96.0/20', '197.234.240.0/22', '198.41.128.0/17', '162.158.0.0/15', '104.16.0.0/13',
		'104.24.0.0/14', '172.64.0.0/13', '131.0.72.0/22',
		'2400:cb00::/32', '2606:4700::/32', '2803:f800::/32', '2405:b500::/32', '2405:8100::/32', '2a06:98c0::/29', '2c0f:f248::/32',
	);

	/** Whether an address (v4 or v6) sits inside a CIDR range. Anything malformed is a no. */
	public static function ip_in_cidr( $ip, $cidr ) {
		$parts = explode( '/', (string) $cidr, 2 );
		$net   = @inet_pton( $parts[0] );
		$addr  = @inet_pton( (string) $ip );
		if ( false === $net || false === $addr || strlen( $net ) !== strlen( $addr ) ) {
			return false;
		}
		$bits = isset( $parts[1] ) ? (int) $parts[1] : strlen( $net ) * 8;
		if ( $bits < 0 || $bits > strlen( $net ) * 8 ) {
			return false;
		}
		$bytes = intdiv( $bits, 8 );
		if ( $bytes && substr( $net, 0, $bytes ) !== substr( $addr, 0, $bytes ) ) {
			return false;
		}
		$rest = $bits % 8;
		if ( ! $rest ) {
			return true;
		}
		$mask = ( 0xFF << ( 8 - $rest ) ) & 0xFF;
		return ( ord( $net[ $bytes ] ) & $mask ) === ( ord( $addr[ $bytes ] ) & $mask );
	}

	/**
	 * The visitor's address for rate limiting. CF-Connecting-IP is only used when the connection itself
	 * comes from a trusted proxy (Cloudflare by default), because anyone else can send that header.
	 *
	 * @param array $server  $_SERVER.
	 * @param array $trusted CIDR ranges of proxies whose header may be believed.
	 */
	public static function client_ip( array $server, array $trusted ) {
		$remote = (string) ( $server['REMOTE_ADDR'] ?? '' );
		$header = (string) ( $server['HTTP_CF_CONNECTING_IP'] ?? '' );
		if ( '' !== $header && false !== filter_var( $header, FILTER_VALIDATE_IP ) ) {
			foreach ( $trusted as $range ) {
				if ( self::ip_in_cidr( $remote, $range ) ) {
					return $header;
				}
			}
		}
		return $remote;
	}

	/** Whether an hour (0 to 23) falls inside quiet hours. Equal start and end switch quiet hours off; start above end wraps midnight. */
	public static function is_quiet( $hour, $start, $end ) {
		$hour  = (int) $hour;
		$start = (int) $start;
		$end   = (int) $end;
		return $start !== $end && ( $start > $end ? ( $hour >= $start || $hour < $end ) : ( $hour >= $start && $hour < $end ) );
	}
}
