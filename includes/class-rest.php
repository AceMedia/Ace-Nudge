<?php
/**
 * Public endpoints. Pages are cached, so these cannot use a nonce: requests are rate limited,
 * and everything after subscribing needs the subscription's own token.
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

class Ace_Nudge_Rest {
	const MAX_FOLLOWS = 30;
	const MAX_READS   = 100;

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
			'slip'      => 'slip',
			'slip_done' => 'slip_done',
		) as $route => $cb ) {
			register_rest_route( 'ace-nudge/v1', '/' . $route, array( 'methods' => 'POST', 'callback' => array( __CLASS__, $cb ), 'permission_callback' => $open ) );
		}
	}

	private static function limited( $bucket, $max ) {
		// The proxy header is only believed from a trusted proxy (Cloudflare's ranges by default; ace_nudge_trusted_proxies).
		$ip  = Ace_Nudge_Util::client_ip( $_SERVER, array_values( (array) apply_filters( 'ace_nudge_trusted_proxies', Ace_Nudge_Util::CLOUDFLARE ) ) );
		$key = 'ace_nudge_rl_' . md5( $bucket . '|' . $ip . '|' . wp_salt( 'nonce' ) );
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
		// The column holds 190 characters; a longer address would fail to save and be sent a link that cannot work.
		if ( ! is_email( $email ) || strlen( $email ) > 190 ) {
			return new WP_Error( 'email', 'Please enter a valid email address.', array( 'status' => 400 ) );
		}
		if ( Ace_Nudge_Store::is_suppressed( $email ) || ! Ace_Nudge_Dispatch::email_allowed( $email ) ) {
			return $done;
		}
		$s         = Ace_Nudge_Store::t( 'subscribers' );
		$f         = Ace_Nudge_Store::t( 'follows' );
		$hash      = Ace_Nudge_Store::email_hash( $email );
		$frequency = 'weekly' === $r->get_param( 'frequency' ) ? 'weekly' : 'daily';
		$cats      = array();
		foreach ( array_slice( (array) $r->get_param( 'categories' ), 0, self::MAX_FOLLOWS ) as $cat ) {
			$term = get_term( (int) $cat );
			if ( $term && ! is_wp_error( $term ) && in_array( $term->taxonomy, Ace_Nudge_Settings::taxonomies(), true ) ) {
				$cats[] = (int) $cat;
			}
		}
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT id, status FROM $s WHERE endpoint_hash = %s", $hash ) );
		if ( $row && 'active' === $row->status ) {
			if ( $wpdb->get_var( $wpdb->prepare( "SELECT 1 FROM $f WHERE subscriber_id = %d AND type = 'digest'", $row->id ) ) ) {
				return $done;
			}
			// Active only for product emails (an opt-in at checkout): the newsletter still needs its own confirmation.
			$wpdb->delete( $f, array( 'subscriber_id' => $row->id, 'type' => 'digestreq' ), array( '%d', '%s' ) );
			self::add_follow( (int) $row->id, 'digestreq', $frequency, array( 'cats' => $cats ) );
			Ace_Nudge_Store::log( $hash, 'email', 'requested', true );
			$id = (int) $row->id;
		} elseif ( ! $row ) {
			// INSERT IGNORE: two requests at once for one address must not leave one of them holding a dead link.
			$wpdb->query( $wpdb->prepare( "INSERT IGNORE INTO $s (channel, status, email, endpoint_hash, created_at) VALUES ('email', 'pending', %s, %s, %s)", $email, $hash, current_time( 'mysql', true ) ) );
			$id = (int) $wpdb->insert_id;
			if ( $id ) {
				self::add_follow( $id, 'digest', $frequency, array() );
				foreach ( $cats as $cat ) {
					self::add_follow( $id, 'category', (string) $cat, array() );
				}
				Ace_Nudge_Store::log( $hash, 'email', 'requested', true );
			} else {
				$id = (int) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM $s WHERE endpoint_hash = %s", $hash ) );
				if ( ! $id ) {
					return $done;
				}
			}
		} else {
			$id = (int) $row->id;
		}
		// One confirmation email per address every ten minutes, so this form cannot be used to flood someone's inbox.
		$throttle = 'ace_nudge_cf_' . substr( $hash, 0, 32 );
		if ( ! get_transient( $throttle ) ) {
			set_transient( $throttle, 1, 10 * MINUTE_IN_SECONDS );
			Ace_Nudge_Dispatch::send_confirmation( $email, Ace_Nudge_Store::token( $id ) );
		}
		return $done;
	}

	private static function add_follow( $id, $type, $object, array $meta ) {
		return Ace_Nudge_Store::add_follow( $id, $type, $object, $meta );
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
			// slipprices: price changes on the slip; slipremind: a reminder before the slip's first event;
			// tips: new tips in what they follow. All off by default.
			// Other plugins add their own opt-in alerts here (ace_nudge_site_follows: id => label).
			$ids = array_merge( array( 'all', 'reads', 'slipprices', 'slipremind', 'tips' ), array_keys( Ace_Nudge_Settings::extra_site_follows() ) );
			return in_array( $id, $ids, true ) ? array( 'site', $id, array() ) : null;
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
		$count = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . Ace_Nudge_Store::t( 'follows' ) . " WHERE subscriber_id = %d AND type NOT IN ('read','slip')", $sub->id ) );
		if ( $count >= self::MAX_FOLLOWS ) {
			return new WP_Error( 'follow', 'You are following the maximum number of things.', array( 'status' => 400 ) );
		}
		// A follow can be for a while only (the calculator follows a team for a week after a bet).
		$days = (int) $r->get_param( 'days' );
		$meta = $target[2];
		if ( $days > 0 ) {
			$meta['until'] = time() + min( 30, $days ) * DAY_IN_SECONDS;
		}
		self::add_follow( $sub->id, $target[0], $target[1], $meta );
		// Following a team from a game's page also covers that game's kick-off and score alerts.
		if ( 'category' === $target[0] && $r->get_param( 'post_id' ) ) {
			$fixture = Ace_Nudge_Front::fixture_for_post( (int) $r->get_param( 'post_id' ) );
			if ( $fixture ) {
				self::add_follow( $sub->id, 'fixture', $fixture['id'], $fixture );
			}
		}
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
		$wpdb->delete( Ace_Nudge_Store::t( 'follows' ), array( 'subscriber_id' => $sub->id, 'type' => $target[0], 'object_id' => $target[1] ), array( '%d', '%s', '%s' ) );
		// Switching this off forgets what was read, not just stops collecting it.
		if ( 'site' === $target[0] && 'reads' === $target[1] ) {
			$wpdb->delete( Ace_Nudge_Store::t( 'follows' ), array( 'subscriber_id' => $sub->id, 'type' => 'read' ) );
			Ace_Nudge_Store::log( $sub->endpoint_hash, 'push', 'reads_off' );
		}
		return self::state( $r );
	}

	/**
	 * A site's selection list keeps a person's picks on their device; with notifications on it sends a
	 * summary here so they can be reminded, once, fifteen minutes before the first game or race on
	 * it (only if they switched the reminder on). Nothing is placed from here.
	 */
	public static function slip( WP_REST_Request $r ) {
		global $wpdb;
		$sub = Ace_Nudge_Store::subscriber_from_token( $r->get_param( 'token' ) );
		if ( ! $sub || 'push' !== $sub->channel ) {
			return new WP_Error( 'slip', 'Notifications are not on in this browser.', array( 'status' => 400 ) );
		}
		if ( self::limited( 'slip', 240 ) ) {
			return new WP_Error( 'slip', 'Too many updates.', array( 'status' => 429 ) );
		}
		$count = (int) $r->get_param( 'count' );
		$follows = Ace_Nudge_Store::t( 'follows' );
		if ( $count < 1 ) {
			$wpdb->delete( $follows, array( 'subscriber_id' => $sub->id, 'type' => 'slip' ), array( '%d', '%s' ) );
			return array( 'ok' => true, 'slip' => false );
		}
		$old   = json_decode( (string) $wpdb->get_var( $wpdb->prepare( "SELECT meta FROM $follows WHERE subscriber_id = %d AND type = 'slip' AND object_id = 'slip'", $sub->id ) ), true );
		$picks = array_slice( array_values( array_filter( (array) $r->get_param( 'picks' ), 'is_array' ) ), 0, 20 );
		$picks = array_map( fn( $p ) => array(
			'runner' => sanitize_text_field( (string) ( $p['runner'] ?? '' ) ),
			'event'  => sanitize_text_field( (string) ( $p['event'] ?? '' ) ),
			'start'  => (int) ( $p['start'] ?? 0 ),
			// For price-change alerts, when the person asked for them: the market, the selection and
			// the price on their slip. Nothing else about the slip travels.
			'market' => preg_match( '/^\d{1,4}\.\d{1,15}$/', (string) ( $p['market'] ?? '' ) ) ? (string) $p['market'] : '',
			'sel'    => ctype_digit( (string) ( $p['sel'] ?? '' ) ) ? (string) $p['sel'] : '',
			'dec'    => round( max( 0, (float) ( $p['dec'] ?? 0 ) ), 3 ),
		), $picks );
		$starts = array_filter( array_map( fn( $p ) => $p['start'], $picks ), fn( $t ) => $t > time() );
		$meta = array(
			'count'      => min( 20, $count ),
			'type'       => sanitize_text_field( (string) $r->get_param( 'type' ) ),
			'returns'    => round( (float) $r->get_param( 'returns' ), 2 ),
			// A reminder opens this address, so it has to stay on this site.
			'url'        => wp_validate_redirect( esc_url_raw( (string) $r->get_param( 'url' ) ), home_url( '/' ) ),
			'picks'      => $picks,
			'first'      => $starts ? min( $starts ) : 0,
			'updated_at' => time(),
			'kick_sent'  => is_array( $old ) ? (int) ( $old['kick_sent'] ?? 0 ) : 0,
			// The last price alerted for each pick, so one change is told once.
			'told'       => is_array( $old ) && is_array( $old['told'] ?? null ) ? $old['told'] : array(),
		);
		$wpdb->delete( $follows, array( 'subscriber_id' => $sub->id, 'type' => 'slip' ), array( '%d', '%s' ) );
		self::add_follow( $sub->id, 'slip', 'slip', $meta );
		return array( 'ok' => true, 'slip' => true );
	}

	/** The person placed or cleared the slip: no reminders. */
	public static function slip_done( WP_REST_Request $r ) {
		global $wpdb;
		$sub = Ace_Nudge_Store::subscriber_from_token( $r->get_param( 'token' ) );
		if ( $sub ) {
			$wpdb->delete( Ace_Nudge_Store::t( 'follows' ), array( 'subscriber_id' => $sub->id, 'type' => 'slip' ), array( '%d', '%s' ) );
		}
		return array( 'ok' => true );
	}

	public static function state( WP_REST_Request $r ) {
		global $wpdb;
		$sub = Ace_Nudge_Store::subscriber_from_token( $r->get_param( 'token' ) );
		if ( ! $sub ) {
			return array( 'subscribed' => false, 'follows' => array() );
		}
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT type, object_id, meta FROM ' . Ace_Nudge_Store::t( 'follows' ) . " WHERE subscriber_id = %d AND type NOT IN ('read','slip')", $sub->id ) );
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
		if ( self::limited( 'orders', 60 ) ) {
			return new WP_Error( 'orders', 'Too many requests.', array( 'status' => 429 ) );
		}
		$order = wc_get_order( (int) $r->get_param( 'order_id' ) );
		$given = (string) $r->get_param( 'order_key' );
		// An order with no key must not match an empty one.
		if ( $order && '' !== $given && hash_equals( (string) $order->get_order_key(), $given ) ) {
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
			// Only the latest are kept (they also lapse after 30 days), so one browser cannot grow the table without end.
			$wpdb->query( $wpdb->prepare( "DELETE FROM $t WHERE subscriber_id = %d AND type = 'read' AND id NOT IN ( SELECT id FROM ( SELECT id FROM $t WHERE subscriber_id = %d AND type = 'read' ORDER BY id DESC LIMIT %d ) keep )", $sub->id, $sub->id, self::MAX_READS ) );
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
