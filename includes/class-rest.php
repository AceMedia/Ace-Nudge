<?php
/**
 * Public endpoints. Pages are cached, so these cannot use a nonce: requests are rate limited,
 * and everything after subscribing needs the subscription's own token.
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

class Ace_Nudge_Rest {
	const MAX_FOLLOWS = 30;

	public static function boot() {
		add_action( 'rest_api_init', array( __CLASS__, 'routes' ) );
	}

	public static function routes() {
		$open = '__return_true';
		foreach ( array(
			'subscribe' => 'subscribe',
			'follow'    => 'follow',
			'unfollow'  => 'unfollow',
			'withdraw'  => 'withdraw',
			'state'     => 'state',
			'read'      => 'read',
			'orders'    => 'orders',
		) as $route => $cb ) {
			register_rest_route( 'ace-nudge/v1', '/' . $route, array( 'methods' => 'POST', 'callback' => array( __CLASS__, $cb ), 'permission_callback' => $open ) );
		}
	}

	private static function limited( $bucket, $max ) {
		$key = 'ace_nudge_rl_' . md5( $bucket . '|' . ( $_SERVER['HTTP_CF_CONNECTING_IP'] ?? $_SERVER['REMOTE_ADDR'] ?? '' ) . '|' . wp_salt( 'nonce' ) );
		$n   = (int) get_transient( $key );
		if ( $n >= $max ) {
			return true;
		}
		set_transient( $key, $n + 1, HOUR_IN_SECONDS );
		return false;
	}

	public static function subscribe( WP_REST_Request $r ) {
		if ( self::limited( 'subscribe', 20 ) ) {
			return new WP_Error( 'busy', 'Too many requests.', array( 'status' => 429 ) );
		}
		// Order updates are about a purchase, not marketing, so they don't need the age check.
		$orders_only = 'orders' === $r->get_param( 'purpose' ) && 'email' !== $r->get_param( 'channel' );
		$age_ok      = $orders_only || ! Ace_Nudge_Settings::min_age() || true === $r->get_param( 'age_confirmed' );
		if ( ! $age_ok || true !== $r->get_param( 'consent' ) || '' !== (string) $r->get_param( 'website' ) ) {
			return new WP_Error( 'consent', 'Consent and age confirmation are required.', array( 'status' => 400 ) );
		}
		return 'email' === $r->get_param( 'channel' ) ? self::subscribe_email( $r ) : self::subscribe_push( $r );
	}

	private static function subscribe_push( WP_REST_Request $r ) {
		global $wpdb;
		$endpoint = esc_url_raw( (string) $r->get_param( 'endpoint' ) );
		$p256dh   = (string) $r->get_param( 'p256dh' );
		$auth     = (string) $r->get_param( 'auth' );
		$host     = (string) wp_parse_url( $endpoint, PHP_URL_HOST );
		// Only real push services, or this endpoint becomes a way to make the server call anywhere.
		if ( 0 !== strpos( $endpoint, 'https://' ) || ! preg_match( '/(^|\.)(googleapis\.com|mozilla\.com|windows\.com|push\.apple\.com)$/', $host )
			|| 65 !== strlen( Ace_Nudge_Push::unb64( $p256dh ) ) || 16 !== strlen( Ace_Nudge_Push::unb64( $auth ) ) ) {
			return new WP_Error( 'subscription', 'Invalid subscription.', array( 'status' => 400 ) );
		}
		$hash = hash( 'sha256', $endpoint );
		$id   = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM ' . Ace_Nudge_Store::t( 'subscribers' ) . ' WHERE endpoint_hash = %s', $hash ) );
		if ( ! $id ) {
			$wpdb->insert( Ace_Nudge_Store::t( 'subscribers' ), array(
				'channel' => 'push', 'status' => 'active', 'endpoint' => $endpoint, 'endpoint_hash' => $hash,
				'p256dh' => $p256dh, 'auth' => $auth, 'created_at' => current_time( 'mysql', true ),
			) );
			$id = (int) $wpdb->insert_id;
		}
		'orders' === $r->get_param( 'purpose' )
			? Ace_Nudge_Store::log( $hash, 'push', 'orders_on', false, 'orders' )
			: Ace_Nudge_Store::log( $hash, 'push', 'granted', true );
		return array( 'token' => Ace_Nudge_Store::token( $id ) );
	}

	/** Double opt-in. The answer is the same whatever happens, so addresses can't be probed. */
	private static function subscribe_email( WP_REST_Request $r ) {
		global $wpdb;
		$done  = array( 'pending' => true );
		$email = sanitize_email( (string) $r->get_param( 'email' ) );
		if ( ! is_email( $email ) ) {
			return new WP_Error( 'email', 'Please enter a valid email address.', array( 'status' => 400 ) );
		}
		if ( Ace_Nudge_Store::is_suppressed( $email ) || ! Ace_Nudge_Dispatch::email_allowed( $email ) ) {
			return $done;
		}
		$hash = Ace_Nudge_Store::email_hash( $email );
		$row  = $wpdb->get_row( $wpdb->prepare( 'SELECT id, status FROM ' . Ace_Nudge_Store::t( 'subscribers' ) . ' WHERE endpoint_hash = %s', $hash ) );
		if ( $row && 'active' === $row->status ) {
			return $done;
		}
		if ( ! $row ) {
			$wpdb->insert( Ace_Nudge_Store::t( 'subscribers' ), array(
				'channel' => 'email', 'status' => 'pending', 'email' => $email, 'endpoint_hash' => $hash,
				'created_at' => current_time( 'mysql', true ),
			) );
			$id = (int) $wpdb->insert_id;
			$frequency = 'weekly' === $r->get_param( 'frequency' ) ? 'weekly' : 'daily';
			self::add_follow( $id, 'digest', $frequency, array() );
			foreach ( array_slice( (array) $r->get_param( 'categories' ), 0, self::MAX_FOLLOWS ) as $cat ) {
				$term = get_term( (int) $cat );
				if ( $term && ! is_wp_error( $term ) && in_array( $term->taxonomy, Ace_Nudge_Settings::taxonomies(), true ) ) {
					self::add_follow( $id, 'category', (string) (int) $cat, array() );
				}
			}
			Ace_Nudge_Store::log( $hash, 'email', 'requested', true );
		} else {
			$id = (int) $row->id;
		}
		Ace_Nudge_Dispatch::send_confirmation( $email, Ace_Nudge_Store::token( $id ) );
		return $done;
	}

	private static function add_follow( $id, $type, $object, array $meta ) {
		global $wpdb;
		$wpdb->query( $wpdb->prepare(
			'INSERT IGNORE INTO ' . Ace_Nudge_Store::t( 'follows' ) . ' (subscriber_id, type, object_id, meta, created_at) VALUES (%d, %s, %s, %s, %s)',
			$id, $type, $object, wp_json_encode( $meta ), current_time( 'mysql', true )
		) );
	}

	/** Resolve what is being followed on the server, so a client can't name anything else. */
	private static function target( WP_REST_Request $r ) {
		$type = (string) $r->get_param( 'type' );
		if ( 'category' === $type ) {
			$term = get_term( (int) $r->get_param( 'id' ) );
			return $term && ! is_wp_error( $term ) && in_array( $term->taxonomy, Ace_Nudge_Settings::taxonomies(), true ) ? array( 'category', (string) $term->term_id, array() ) : null;
		}
		if ( 'ptype' === $type ) {
			$id = (string) $r->get_param( 'id' );
			return in_array( $id, Ace_Nudge_Settings::post_types(), true ) ? array( 'ptype', $id, array() ) : null;
		}
		if ( 'site' === $type ) {
			$id = (string) $r->get_param( 'id' );
			return in_array( $id, array( 'all', 'reads' ), true ) ? array( 'site', $id, array() ) : null;
		}
		if ( 'post' === $type ) {
			$post = get_post( (int) $r->get_param( 'post_id' ) );
			return $post && in_array( $post->post_type, Ace_Nudge_Settings::post_types(), true ) && 'publish' === $post->post_status ? array( 'post', (string) $post->ID, array() ) : null;
		}
		if ( 'fixture' === $type ) {
			$fixture = Ace_Nudge_Front::fixture_for_post( (int) $r->get_param( 'post_id' ) );
			return $fixture ? array( 'fixture', $fixture['id'], $fixture ) : null;
		}
		return null;
	}

	public static function follow( WP_REST_Request $r ) {
		global $wpdb;
		$sub    = Ace_Nudge_Store::subscriber_from_token( $r->get_param( 'token' ) );
		$target = self::target( $r );
		if ( ! $sub || ! $target ) {
			return new WP_Error( 'follow', 'Could not follow that.', array( 'status' => 400 ) );
		}
		$count = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . Ace_Nudge_Store::t( 'follows' ) . " WHERE subscriber_id = %d AND type <> 'read'", $sub->id ) );
		if ( $count >= self::MAX_FOLLOWS ) {
			return new WP_Error( 'follow', 'You are following the maximum number of things.', array( 'status' => 400 ) );
		}
		self::add_follow( $sub->id, $target[0], $target[1], $target[2] );
		if ( 'site' === $target[0] && 'reads' === $target[1] ) {
			Ace_Nudge_Store::log( $sub->endpoint_hash, 'push', 'reads_on', true );
		}
		return self::state( $r );
	}

	public static function unfollow( WP_REST_Request $r ) {
		global $wpdb;
		$sub = Ace_Nudge_Store::subscriber_from_token( $r->get_param( 'token' ) );
		// Removing only touches the caller's own row, so an existing key is enough: a game that
		// has since dropped off its post can still be unfollowed.
		$key    = explode( ':', (string) $r->get_param( 'key' ), 2 );
		$target = 2 === count( $key ) ? array( $key[0], $key[1] ) : self::target( $r );
		if ( ! $sub || ! $target ) {
			return new WP_Error( 'follow', 'Could not unfollow that.', array( 'status' => 400 ) );
		}
		$wpdb->delete( Ace_Nudge_Store::t( 'follows' ), array( 'subscriber_id' => $sub->id, 'type' => $target[0], 'object_id' => $target[1] ) );
		// Switching this off forgets what was read, not just stops collecting it.
		if ( 'site' === $target[0] && 'reads' === $target[1] ) {
			$wpdb->delete( Ace_Nudge_Store::t( 'follows' ), array( 'subscriber_id' => $sub->id, 'type' => 'read' ) );
			Ace_Nudge_Store::log( $sub->endpoint_hash, 'push', 'reads_off' );
		}
		return self::state( $r );
	}

	public static function state( WP_REST_Request $r ) {
		global $wpdb;
		$sub = Ace_Nudge_Store::subscriber_from_token( $r->get_param( 'token' ) );
		if ( ! $sub ) {
			return array( 'subscribed' => false, 'follows' => array() );
		}
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT type, object_id, meta FROM ' . Ace_Nudge_Store::t( 'follows' ) . " WHERE subscriber_id = %d AND type <> 'read'", $sub->id ) );
		return array(
			'subscribed' => true,
			'follows'    => array_map( fn( $f ) => $f->type . ':' . $f->object_id, $rows ),
			'items'      => array_values( array_filter( array_map( array( __CLASS__, 'describe' ), $rows ) ) ),
		);
	}

	/** Games, stories and post types, named for the manage panel. Categories have their own toggles. */
	private static function describe( $f ) {
		$key = $f->type . ':' . $f->object_id;
		if ( 'fixture' === $f->type ) {
			$meta = (array) json_decode( (string) $f->meta, true );
			return array( 'key' => $key, 'type' => 'fixture', 'post_id' => (int) ( $meta['post_id'] ?? 0 ), 'label' => (string) ( $meta['label'] ?? 'A game' ) );
		}
		if ( 'post' === $f->type ) {
			return array( 'key' => $key, 'type' => 'post', 'post_id' => (int) $f->object_id, 'label' => html_entity_decode( get_the_title( (int) $f->object_id ) ) );
		}
		if ( 'category' === $f->type ) {
			$term = get_term( (int) $f->object_id );
			return $term && ! is_wp_error( $term ) && ! in_array( $term->taxonomy, Ace_Nudge_Settings::menu_taxonomies(), true )
				? array( 'key' => $key, 'type' => 'category', 'id' => $term->term_id, 'label' => html_entity_decode( $term->name ) )
				: null;
		}
		if ( 'order' === $f->type ) {
			return array( 'key' => $key, 'type' => 'order', 'label' => 'Updates for order #' . $f->object_id );
		}
		if ( 'customer' === $f->type ) {
			return array( 'key' => $key, 'type' => 'customer', 'label' => 'Updates for my orders' );
		}
		if ( 'ptype' === $f->type && ( $object = get_post_type_object( $f->object_id ) ) ) {
			return array( 'key' => $key, 'type' => 'ptype', 'id' => $f->object_id, 'label' => 'All ' . strtolower( $object->labels->name ) );
		}
		return null;
	}

	/** Tie this browser to one order (guests prove it with the order key) or to the signed-in customer. */
	public static function orders( WP_REST_Request $r ) {
		$sub = Ace_Nudge_Store::subscriber_from_token( $r->get_param( 'token' ) );
		if ( ! $sub || ! function_exists( 'wc_get_order' ) ) {
			return new WP_Error( 'orders', 'Could not turn on order updates.', array( 'status' => 400 ) );
		}
		$order = wc_get_order( (int) $r->get_param( 'order_id' ) );
		if ( $order && hash_equals( $order->get_order_key(), (string) $r->get_param( 'order_key' ) ) ) {
			self::add_follow( $sub->id, 'order', (string) $order->get_id(), array() );
		} elseif ( get_current_user_id() ) {
			self::add_follow( $sub->id, 'customer', (string) get_current_user_id(), array() );
		} else {
			return new WP_Error( 'orders', 'Could not turn on order updates.', array( 'status' => 403 ) );
		}
		return self::state( $r );
	}

	/** Only recorded for someone who switched on updates to stories they've read. */
	public static function read( WP_REST_Request $r ) {
		global $wpdb;
		$sub  = Ace_Nudge_Store::subscriber_from_token( $r->get_param( 'token' ) );
		$post = get_post( (int) $r->get_param( 'post_id' ) );
		$t    = Ace_Nudge_Store::t( 'follows' );
		if ( $sub && $post && 'publish' === $post->post_status && $wpdb->get_var( $wpdb->prepare( "SELECT 1 FROM $t WHERE subscriber_id = %d AND type = 'site' AND object_id = 'reads'", $sub->id ) ) ) {
			self::add_follow( $sub->id, 'read', (string) $post->ID, array() );
		}
		return array( 'ok' => true );
	}

	public static function withdraw( WP_REST_Request $r ) {
		$sub = Ace_Nudge_Store::subscriber_from_token( $r->get_param( 'token' ) );
		if ( $sub ) {
			Ace_Nudge_Store::withdraw( $sub );
		}
		return array( 'subscribed' => false );
	}
}
