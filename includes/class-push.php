<?php
/**
 * Web Push: VAPID (RFC 8292) and aes128gcm payload encryption (RFC 8291).
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

class Ace_Nudge_Push {
	const SPKI = '3059301306072a8648ce3d020106082a8648ce3d030107034200';

	public static function b64( $raw ) {
		return rtrim( strtr( base64_encode( $raw ), '+/', '-_' ), '=' );
	}

	public static function unb64( $s ) {
		return (string) base64_decode( strtr( $s, '-_', '+/' ) );
	}

	private static function raw_public( $key ) {
		$d = openssl_pkey_get_details( $key )['ec'];
		return "\x04" . str_pad( $d['x'], 32, "\0", STR_PAD_LEFT ) . str_pad( $d['y'], 32, "\0", STR_PAD_LEFT );
	}

	private static function new_key() {
		return openssl_pkey_new( array( 'private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1' ) );
	}

	public static function keys() {
		$keys = get_option( 'ace_nudge_vapid' );
		if ( ! is_array( $keys ) || empty( $keys['private'] ) ) {
			$key = self::new_key();
			openssl_pkey_export( $key, $pem );
			$keys = array( 'private' => $pem, 'public' => self::b64( self::raw_public( $key ) ) );
			update_option( 'ace_nudge_vapid', $keys, false );
		}
		return $keys;
	}

	public static function encrypt( $payload, $p256dh, $auth ) {
		$ua_public = self::unb64( $p256dh );
		$ua_pem    = "-----BEGIN PUBLIC KEY-----\n" . chunk_split( base64_encode( hex2bin( self::SPKI ) . $ua_public ), 64, "\n" ) . "-----END PUBLIC KEY-----\n";
		$local     = self::new_key();
		$as_public = self::raw_public( $local );
		$shared    = openssl_pkey_derive( openssl_pkey_get_public( $ua_pem ), $local, 32 );
		if ( false === $shared ) {
			return null;
		}
		$salt  = random_bytes( 16 );
		$ikm   = hash_hkdf( 'sha256', $shared, 32, "WebPush: info\0" . $ua_public . $as_public, self::unb64( $auth ) );
		$cek   = hash_hkdf( 'sha256', $ikm, 16, "Content-Encoding: aes128gcm\0", $salt );
		$nonce = hash_hkdf( 'sha256', $ikm, 12, "Content-Encoding: nonce\0", $salt );
		$tag   = '';
		$body  = openssl_encrypt( $payload . "\x02", 'aes-128-gcm', $cek, OPENSSL_RAW_DATA, $nonce, $tag, '', 16 );
		return $salt . pack( 'N', 4096 ) . chr( 65 ) . $as_public . $body . $tag;
	}

	private static function vapid_header( $endpoint ) {
		$keys = self::keys();
		$p    = wp_parse_url( $endpoint );
		$jwt  = self::b64( '{"typ":"JWT","alg":"ES256"}' ) . '.' . self::b64( wp_json_encode( array(
			'aud' => $p['scheme'] . '://' . $p['host'],
			'exp' => time() + 12 * HOUR_IN_SECONDS,
			'sub' => 'mailto:' . get_option( 'admin_email' ),
		) ) );
		openssl_sign( $jwt, $der, openssl_pkey_get_private( $keys['private'] ), OPENSSL_ALGO_SHA256 );
		return 'vapid t=' . $jwt . '.' . self::b64( self::der_to_raw( $der ) ) . ', k=' . $keys['public'];
	}

	private static function der_to_raw( $der ) {
		$offset = 2;
		$raw    = '';
		for ( $i = 0; $i < 2; $i++ ) {
			$len    = ord( $der[ $offset + 1 ] );
			$raw   .= str_pad( ltrim( substr( $der, $offset + 2, $len ), "\0" ), 32, "\0", STR_PAD_LEFT );
			$offset += 2 + $len;
		}
		return $raw;
	}

	/**
	 * Whether a push may really go to this subscriber. Only when NUDGE is on; in test mode only to the
	 * subscriber ids listed in ACE_NUDGE_TEST_PUSH (none by default), so a development copy of a site
	 * never notifies real devices.
	 */
	public static function allowed( $sub ) {
		if ( 'on' === ace_nudge_mode() ) {
			return true;
		}
		if ( 'test' !== ace_nudge_mode() || ! defined( 'ACE_NUDGE_TEST_PUSH' ) ) {
			return false;
		}
		return in_array( (int) ( $sub->id ?? 0 ), array_map( 'intval', (array) ACE_NUDGE_TEST_PUSH ), true );
	}

	/** Returns the HTTP status, or 0 if the request never completed or the push is not allowed. */
	public static function send( $sub, array $message, $urgency = 'normal' ) {
		if ( ! self::allowed( $sub ) ) {
			return 0;
		}
		$body = self::encrypt( wp_json_encode( $message ), $sub->p256dh, $sub->auth );
		if ( null === $body ) {
			return 0;
		}
		$res = wp_remote_post( $sub->endpoint, array(
			'timeout' => 10,
			'headers' => array(
				'Authorization'    => self::vapid_header( $sub->endpoint ),
				'Content-Encoding' => 'aes128gcm',
				'Content-Type'     => 'application/octet-stream',
				'TTL'              => 'high' === $urgency ? '600' : '21600',
				'Urgency'          => $urgency,
			),
			'body'    => $body,
		) );
		return is_wp_error( $res ) ? 0 : (int) wp_remote_retrieve_response_code( $res );
	}
}
