<?php
/**
 * Everything that sends: game alerts, batched category alerts and the email digest.
 *
 * Scores are read from the snapshot Ace Sports Feed already cached, so following a game adds
 * no outbound calls. Sites can hold back promotional wording on posts with ace_nudge_promo_pattern.
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

class Ace_Nudge_Dispatch {
	const BATCH_HOOK = 'ace_nudge_hourly';
	const LIVE_HOOK  = 'ace_nudge_minute';
	const MAIL_HOOK  = 'ace_nudge_digest';

	public static function boot() {
		add_filter( 'cron_schedules', function ( $s ) {
			$s['ace_nudge_minute'] = array( 'interval' => MINUTE_IN_SECONDS, 'display' => 'Every minute (Ace Notifications)' );
			return $s;
		} );
		add_action( 'init', array( __CLASS__, 'schedule' ) );
		add_action( self::LIVE_HOOK, array( __CLASS__, 'fixtures_tick' ) );
		add_action( self::LIVE_HOOK, array( __CLASS__, 'slips_tick' ) );
		add_action( self::BATCH_HOOK, array( __CLASS__, 'category_batch' ) );
		add_action( self::MAIL_HOOK, array( __CLASS__, 'digest' ) );
		add_action( 'post_updated', array( __CLASS__, 'note_update' ), 10, 3 );
		add_action( 'woocommerce_before_product_object_save', array( __CLASS__, 'note_product_change' ) );
	}

	public static function schedule() {
		if ( ! wp_next_scheduled( self::LIVE_HOOK ) ) { wp_schedule_event( time() + 60, 'ace_nudge_minute', self::LIVE_HOOK ); }
		if ( ! wp_next_scheduled( self::BATCH_HOOK ) ) { wp_schedule_event( time() + 300, 'hourly', self::BATCH_HOOK ); }
		if ( ! wp_next_scheduled( self::MAIL_HOOK ) ) {
			$next = new DateTimeImmutable( 'tomorrow 08:00', new DateTimeZone( 'Europe/London' ) );
			wp_schedule_event( $next->getTimestamp(), 'daily', self::MAIL_HOOK );
		}
	}

	/**
	 * A push link names the post it is about (ref=nudge-ID), so opening it marks that notification read.
	 * ref is a tracking parameter the page cache already leaves out of its keys, and the browser takes
	 * it off the address as soon as the page loads.
	 */
	public static function opened( $url, $post_id ) {
		return $post_id ? add_query_arg( 'ref', 'nudge-' . (int) $post_id, $url ) : $url;
	}

	public static function is_promotional( $text ) {
		$pattern = (string) apply_filters( 'ace_nudge_promo_pattern', '' );
		return '' !== $pattern && preg_match( $pattern, $text );
	}

	public static function email_allowed( $email ) {
		if ( 'on' === ace_nudge_mode() ) {
			return true;
		}
		$allowed = defined( 'ACE_NUDGE_TEST_EMAILS' ) ? (array) ACE_NUDGE_TEST_EMAILS : array();
		return in_array( strtolower( $email ), array_map( 'strtolower', $allowed ), true );
	}

	public static function deliver( $sub, array $message, $urgency = 'normal' ) {
		$status = Ace_Nudge_Push::send( $sub, $message, $urgency );
		if ( $status >= 200 && $status < 300 ) {
			self::count_sent( 'push' );
		}
		if ( 404 === $status || 410 === $status ) {
			Ace_Nudge_Store::withdraw( $sub, 'expired' );
		}
		return $status >= 200 && $status < 300;
	}

	/** Daily send counts for the admin overview. Totals only, kept for 30 days. */
	public static function count_sent( $channel ) {
		$log = (array) get_option( 'ace_nudge_sent', array() );
		$day = gmdate( 'Y-m-d' );
		$log[ $day ][ $channel ] = (int) ( $log[ $day ][ $channel ] ?? 0 ) + 1;
		update_option( 'ace_nudge_sent', array_slice( $log, -30, null, true ), false );
	}

	/**
	 * Bet slip reminders: once, half an hour after the slip last changed with nothing placed; and
	 * once, fifteen minutes before the first game or race on it. Slips whose events have all
	 * started, or that have not changed for two days, are forgotten.
	 */
	public static function slips_tick() {
		global $wpdb;
		$follows = Ace_Nudge_Store::t( 'follows' );
		$subs    = Ace_Nudge_Store::t( 'subscribers' );
		$rows    = $wpdb->get_results( "SELECT f.id AS follow_id, f.meta, s.* FROM $follows f JOIN $subs s ON s.id = f.subscriber_id WHERE f.type = 'slip' AND s.channel = 'push' AND s.status = 'active'" );
		$now     = time();
		foreach ( $rows as $row ) {
			$meta = json_decode( (string) $row->meta, true );
			if ( ! is_array( $meta ) || empty( $meta['count'] ) ) {
				continue;
			}
			$first = (int) ( $meta['first'] ?? 0 );
			if ( ( $first && $first < $now ) || (int) ( $meta['updated_at'] ?? 0 ) < $now - 2 * DAY_IN_SECONDS ) {
				$wpdb->delete( $follows, array( 'id' => $row->follow_id ), array( '%d' ) );
				continue;
			}
			$n    = (int) $meta['count'];
			$what = $n . ' pick' . ( 1 === $n ? '' : 's' ) . ( ! empty( $meta['type'] ) ? ' as a ' . strtolower( (string) $meta['type'] ) : '' ) . ( ! empty( $meta['returns'] ) ? ', returns £' . number_format( (float) $meta['returns'], 2 ) : '' );
			$sent = false;
			if ( empty( $meta['kick_sent'] ) && $first && $first - $now <= 15 * MINUTE_IN_SECONDS ) {
				$pick = null;
				foreach ( (array) $meta['picks'] as $p ) {
					if ( (int) $p['start'] === $first ) {
						$pick = $p;
						break;
					}
				}
				$when = $pick && ! empty( $pick['event'] ) ? $pick['event'] . ' starts in ' . max( 1, (int) ceil( ( $first - $now ) / MINUTE_IN_SECONDS ) ) . ' minutes' : 'The first game on your slip starts soon';
				$sent = self::deliver( $row, array( 'title' => 'Your bet slip', 'body' => $when . '. ' . $what . ' are still waiting.', 'url' => $meta['url'], 'tag' => 'slip' ), 'high' );
				$meta['kick_sent'] = $now;
			} elseif ( empty( $meta['idle_sent'] ) && (int) $meta['updated_at'] < $now - 30 * MINUTE_IN_SECONDS ) {
				$sent = self::deliver( $row, array( 'title' => 'Still thinking it over?', 'body' => $what . ' are on your bet slip.', 'url' => $meta['url'], 'tag' => 'slip' ) );
				$meta['idle_sent'] = $now;
			} else {
				continue;
			}
			$wpdb->update( $follows, array( 'meta' => wp_json_encode( $meta ) ), array( 'id' => $row->follow_id ), array( '%s' ), array( '%d' ) );
			if ( $sent ) {
				$wpdb->update( $subs, array( 'last_sent_at' => current_time( 'mysql', true ) ), array( 'id' => $row->id ) );
			}
		}
	}

	/** Compare each followed game with what was last seen and alert on real changes. */
	public static function fixtures_tick() {
		global $wpdb;
		$follows = Ace_Nudge_Store::t( 'follows' );
		$subs    = Ace_Nudge_Store::t( 'subscribers' );
		$ids     = $wpdb->get_col( "SELECT DISTINCT object_id FROM $follows WHERE type = 'fixture'" );
		if ( ! $ids ) {
			return;
		}
		$seen = (array) get_option( 'ace_nudge_fixture_state', array() );
		foreach ( $ids as $fid ) {
			$meta = json_decode( (string) $wpdb->get_var( $wpdb->prepare( "SELECT meta FROM $follows WHERE type = 'fixture' AND object_id = %s LIMIT 1", $fid ) ), true );
			$item = self::scoreboard_item( (array) ( $meta['hubs'] ?? array() ), $fid );
			$seen = self::send_reminder( $fid, (array) $meta, $item, $seen );
			if ( ! $item ) {
				continue;
			}
			$now     = array( 'state' => (string) ( $item['state'] ?? '' ), 'h' => (int) ( $item['home_score'] ?? 0 ), 'a' => (int) ( $item['away_score'] ?? 0 ) );
			$message = isset( $seen[ $fid ]['state'] ) ? self::fixture_message( $seen[ $fid ], $now, $item ) : null;
			$seen[ $fid ] = $now + array_intersect_key( (array) ( $seen[ $fid ] ?? array() ), array( 'reminded' => 1 ) );
			if ( $message ) {
				$message['url'] = self::opened( $meta['url'] ?? home_url( '/' ), (int) ( $meta['post_id'] ?? 0 ) );
				$rows = $wpdb->get_results( $wpdb->prepare(
					"SELECT s.* FROM $subs s JOIN $follows f ON f.subscriber_id = s.id WHERE f.type = 'fixture' AND f.object_id = %s AND s.channel = 'push' AND s.status = 'active'",
					$fid
				) );
				foreach ( $rows as $sub ) {
					self::deliver( $sub, $message, 'high' );
				}
			}
			if ( 'post' === $now['state'] ) {
				$wpdb->delete( $follows, array( 'type' => 'fixture', 'object_id' => $fid ), array( '%s', '%s' ) );
				unset( $seen[ $fid ] );
			}
		}
		update_option( 'ace_nudge_fixture_state', $seen, false );
	}

	/** The next reminder due, in minutes before kick-off, or 0. Only the latest step ever sends. */
	public static function reminder_due( $minutes_left, $last_sent ) {
		foreach ( array( 5, 30, 60 ) as $step ) {
			if ( $minutes_left <= $step ) {
				return $minutes_left > 0 && ( ! $last_sent || $step < $last_sent ) ? $step : 0;
			}
		}
		return 0;
	}

	private static function send_reminder( $fid, array $meta, $item, array $seen ) {
		global $wpdb;
		$kickoff = (int) ( $item['kickoff'] ?? $meta['kickoff'] ?? 0 );
		if ( ! $kickoff || ( $item && 'pre' !== ( $item['state'] ?? 'pre' ) ) ) {
			return $seen;
		}
		$left = (int) ceil( ( $kickoff - time() ) / MINUTE_IN_SECONDS );
		$step = self::reminder_due( $left, (int) ( $seen[ $fid ]['reminded'] ?? 0 ) );
		if ( ! $step ) {
			return $seen;
		}
		$seen[ $fid ]['reminded'] = $step;
		$when = 60 === $step ? 'in an hour' : 'in ' . $step . ' minutes';
		$msg  = array( 'title' => 'Starts ' . $when, 'body' => (string) ( $item['title'] ?? $meta['label'] ?? '' ), 'url' => self::opened( $meta['url'] ?? home_url( '/' ), (int) ( $meta['post_id'] ?? 0 ) ), 'tag' => 'fixture' );
		$subs = Ace_Nudge_Store::t( 'subscribers' );
		$rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT s.* FROM $subs s JOIN " . Ace_Nudge_Store::t( 'follows' ) . " f ON f.subscriber_id = s.id WHERE f.type = 'fixture' AND f.object_id = %s AND s.channel = 'push' AND s.status = 'active'",
			$fid
		) );
		foreach ( $rows as $sub ) {
			self::deliver( $sub, $msg, 'high' );
		}
		return $seen;
	}

	private static function scoreboard_item( array $hubs, $fid ) {
		foreach ( $hubs as $hub ) {
			$snap = get_transient( 'ace_sf_live_' . $hub );
			if ( ! is_array( $snap ) ) {
				$snap = get_transient( 'ace_sf_live_' . $hub . '_stale' );
			}
			if ( is_array( $snap ) && isset( $snap[ $fid ] ) ) {
				return $snap[ $fid ];
			}
		}
		return null;
	}

	public static function fixture_message( array $was, array $now, array $item ) {
		$line = $item['home'] . ' ' . $now['h'] . '-' . $now['a'] . ' ' . $item['away'];
		if ( 'post' === $now['state'] && 'post' !== $was['state'] ) {
			return array( 'title' => 'Full time', 'body' => $line, 'tag' => 'fixture' );
		}
		if ( 'in' === $now['state'] && 'pre' === $was['state'] ) {
			return array( 'title' => 'Kick-off', 'body' => $item['title'], 'tag' => 'fixture' );
		}
		if ( 'in' === $now['state'] && ( $now['h'] !== $was['h'] || $now['a'] !== $was['a'] ) ) {
			$up = $now['h'] + $now['a'] > $was['h'] + $was['a'];
			return array( 'title' => $up ? 'Goal' : 'Score corrected', 'body' => $line, 'tag' => 'fixture' );
		}
		return null;
	}

	/** Only a real change counts: a new title or a good chunk of text, not a typo fix. */
	public static function is_real_update( $before, $after ) {
		if ( 'publish' !== $after->post_status || 'publish' !== $before->post_status || 'product' === $after->post_type || ! in_array( $after->post_type, Ace_Nudge_Settings::post_types(), true ) ) {
			return false;
		}
		$diff = abs( strlen( wp_strip_all_tags( $after->post_content ) ) - strlen( wp_strip_all_tags( $before->post_content ) ) );
		return (bool) apply_filters( 'ace_nudge_is_real_update', $before->post_title !== $after->post_title || $diff >= 200, $after, $before );
	}

	public static function note_update( $post_id, $after, $before ) {
		if ( self::is_real_update( $before, $after ) && ! self::is_promotional( $after->post_title ) ) {
			$queue             = (array) get_option( 'ace_nudge_updated', array() );
			$queue[ $post_id ] = 'updated';
			update_option( 'ace_nudge_updated', $queue, false );
		}
	}

	/** What about a product change is worth telling someone: 'stock', 'price' or ''. */
	public static function product_reason( array $was, array $changes ) {
		if ( ( $changes['stock_status'] ?? '' ) === 'instock' && ( $was['stock_status'] ?? '' ) !== 'instock' ) {
			return 'stock';
		}
		foreach ( array( 'sale_price', 'regular_price' ) as $field ) {
			if ( isset( $changes[ $field ] ) && '' !== $changes[ $field ] ) {
				$before = (float) ( $was['price'] ?? 0 );
				if ( $before > 0 && (float) $changes[ $field ] < $before ) {
					return 'price';
				}
			}
		}
		return '';
	}

	public static function note_product_change( $product ) {
		if ( ! in_array( 'product', Ace_Nudge_Settings::post_types(), true ) || ! $product->get_id() || 'publish' !== $product->get_status() ) {
			return;
		}
		$reason = self::product_reason( $product->get_data(), $product->get_changes() );
		if ( $reason ) {
			// People follow and buy the parent, whichever variation changed.
			$queue         = (array) get_option( 'ace_nudge_updated', array() );
			$id            = $product->get_parent_id() ?: $product->get_id();
			$queue[ $id ] = $reason;
			update_option( 'ace_nudge_updated', $queue, false );
		}
	}

	/** One message per person per run, whatever was published or changed, and nothing overnight. */
	public static function category_batch() {
		global $wpdb;
		$hour = (int) ( new DateTimeImmutable( 'now', new DateTimeZone( 'Europe/London' ) ) )->format( 'G' );
		$start = (int) Ace_Nudge_Settings::get( 'quiet_start' );
		$end   = (int) Ace_Nudge_Settings::get( 'quiet_end' );
		if ( $start !== $end && ( $start > $end ? ( $hour >= $start || $hour < $end ) : ( $hour >= $start && $hour < $end ) ) ) {
			return;
		}
		$follows = Ace_Nudge_Store::t( 'follows' );
		$subs    = Ace_Nudge_Store::t( 'subscribers' );
		$wpdb->query( $wpdb->prepare( "DELETE FROM $follows WHERE type = 'read' AND created_at < %s", gmdate( 'Y-m-d H:i:s', time() - 30 * DAY_IN_SECONDS ) ) );
		self::purge_lapsed();

		$since = (int) get_option( 'ace_nudge_batch_since', time() - HOUR_IN_SECONDS );
		update_option( 'ace_nudge_batch_since', time(), false );
		$updated = (array) get_option( 'ace_nudge_updated', array() );
		delete_option( 'ace_nudge_updated' );
		do_action( 'ace_nudge_updates_ready', $updated );
		$posts = get_posts( array(
			'post_type' => Ace_Nudge_Settings::post_types(), 'post_status' => 'publish', 'posts_per_page' => 50, 'no_found_rows' => true,
			'date_query' => array( array( 'after' => gmdate( 'Y-m-d H:i:s', $since ), 'column' => 'post_date_gmt' ) ),
		) );
		$fresh  = array();
		$by_cat = array();
		foreach ( $posts as $p ) {
			if ( 'post' === $p->post_type && self::is_promotional( $p->post_title ) ) {
				continue;
			}
			$fresh[ $p->ID ]                = $p;
			$by_cat[ 'ptype:' . $p->post_type ][ $p->ID ] = $p;
			foreach ( wp_get_object_terms( $p->ID, Ace_Nudge_Settings::taxonomies(), array( 'fields' => 'ids' ) ) as $cat ) {
				$by_cat[ $cat ][ $p->ID ] = $p;
			}
		}
		if ( ! $fresh && ! $updated ) {
			return;
		}
		$today = gmdate( 'Y-m-d' );
		$rows  = $wpdb->get_results( "SELECT s.*, GROUP_CONCAT(CONCAT(f.type, ':', f.object_id)) AS wants FROM $subs s JOIN $follows f ON f.subscriber_id = s.id WHERE s.channel = 'push' AND s.status = 'active' AND f.type IN ('category','ptype','site','post','read') GROUP BY s.id" );
		foreach ( $rows as $sub ) {
			$sent = $sub->sent_day === $today ? (int) $sub->sent_count : 0;
			if ( $sent >= (int) Ace_Nudge_Settings::get( 'daily_cap' ) ) {
				continue;
			}
			$msg = self::batch_message( explode( ',', $sub->wants ), $fresh, $by_cat, $updated );
			if ( $msg && self::deliver( $sub, $msg ) ) {
				$wpdb->update( $subs, array( 'sent_day' => $today, 'sent_count' => $sent + 1, 'last_sent_at' => current_time( 'mysql', true ) ), array( 'id' => $sub->id ) );
			}
		}
	}

	/** Follows made for a set number of days (meta.until) end quietly when their time is up. */
	public static function purge_lapsed() {
		global $wpdb;
		$follows = Ace_Nudge_Store::t( 'follows' );
		$rows    = $wpdb->get_results( "SELECT id, meta FROM $follows WHERE meta LIKE '%\"until\"%'" );
		foreach ( $rows as $row ) {
			$meta = json_decode( (string) $row->meta, true );
			if ( ! empty( $meta['until'] ) && (int) $meta['until'] < time() ) {
				$wpdb->delete( $follows, array( 'id' => $row->id ), array( '%d' ) );
			}
		}
	}

	/** What one person should hear about in this run, or null. */
	public static function batch_message( array $wants, array $fresh, array $by_cat, array $updated ) {
		$new     = in_array( 'site:all', $wants, true ) ? $fresh : array();
		$changes = array();
		$link    = home_url( '/' );
		foreach ( $wants as $want ) {
			[ $type, $id ] = array_pad( explode( ':', $want, 2 ), 2, '' );
			if ( 'category' === $type && ! empty( $by_cat[ $id ] ) ) {
				$new += $by_cat[ $id ];
				$link = get_category_link( (int) $id );
			}
			if ( 'ptype' === $type && ! empty( $by_cat[ 'ptype:' . $id ] ) ) {
				$new += $by_cat[ 'ptype:' . $id ];
				$link = (string) get_post_type_archive_link( $id ) ?: $link;
			}
			if ( in_array( $type, array( 'post', 'read' ), true ) && isset( $updated[ (int) $id ] ) ) {
				$changes[ (int) $id ] = $updated[ (int) $id ];
			}
		}
		$changes = array_diff_key( $changes, $new );
		$total   = count( $new ) + count( $changes );
		$name    = Ace_Nudge_Settings::brand()['name'];
		if ( ! $total ) {
			return null;
		}
		if ( 1 === $total ) {
			$post = $new ? reset( $new ) : get_post( (int) array_key_first( $changes ) );
			return array(
				'title' => $new ? $name : ( array( 'stock' => 'Back in stock', 'price' => 'Price drop' )[ reset( $changes ) ] ?? 'Updated' ),
				'body'  => html_entity_decode( get_the_title( $post ) ),
				'url'   => self::opened( get_permalink( $post ), $post->ID ),
				'tag'   => 'news',
			);
		}
		$parts = array();
		if ( $new ) {
			$parts[] = count( $new ) . ( 1 === count( $new ) ? ' new item' : ' new items' );
		}
		if ( $changes ) {
			$parts[] = count( $changes ) . ' updated';
		}
		return array( 'title' => $name, 'body' => implode( ' and ', $parts ) . ' in what you follow', 'url' => $link, 'tag' => 'news' );
	}

	public static function send_confirmation( $email, $token ) {
		$url = add_query_arg( 'ace_nudge_confirm', rawurlencode( $token ), home_url( '/' ) );
		$name = Ace_Nudge_Settings::brand()['name'];
		$age  = Ace_Nudge_Settings::min_age();
		wp_mail( $email, "Confirm your $name newsletter", "Please confirm you asked for the $name newsletter" . ( $age ? " and that you are $age or over" : '' ) . ":\n\n$url\n\nIf this wasn't you, ignore this email and you won't hear from us again." );
	}

	public static function digest() {
		global $wpdb;
		$subs    = Ace_Nudge_Store::t( 'subscribers' );
		$follows = Ace_Nudge_Store::t( 'follows' );
		$weekly_day = '1' === wp_date( 'N' );
		// Unconfirmed requests are not kept.
		$wpdb->query( $wpdb->prepare( "DELETE FROM $subs WHERE channel = 'email' AND status = 'pending' AND created_at < %s", gmdate( 'Y-m-d H:i:s', time() - 7 * DAY_IN_SECONDS ) ) );
		$rows = $wpdb->get_results( "SELECT * FROM $subs WHERE channel = 'email' AND status = 'active'" );
		foreach ( $rows as $sub ) {
			if ( Ace_Nudge_Store::is_suppressed( $sub->email ) ) {
				Ace_Nudge_Store::withdraw( $sub, 'suppressed' );
				continue;
			}
			if ( ! self::email_allowed( $sub->email ) ) {
				continue;
			}
			// A customer who only asked for product emails isn't on the newsletter.
			if ( ! $wpdb->get_var( $wpdb->prepare( "SELECT 1 FROM $follows WHERE subscriber_id = %d AND type = 'digest'", $sub->id ) ) ) {
				continue;
			}
			$weekly = (bool) $wpdb->get_var( $wpdb->prepare( "SELECT 1 FROM $follows WHERE subscriber_id = %d AND type = 'digest' AND object_id = 'weekly'", $sub->id ) );
			if ( $weekly && ! $weekly_day ) {
				continue;
			}
			$cats = $wpdb->get_col( $wpdb->prepare( "SELECT object_id FROM $follows WHERE subscriber_id = %d AND type = 'category'", $sub->id ) );
			self::send_digest( $sub, $cats, $weekly ? 7 : 1 );
		}
	}

	/** The digest's sections: up to three stories from each chosen top category, or from all of them. */
	public static function digest_sections( array $cats, $days ) {
		$sections = array();
		$seen     = array();
		foreach ( Ace_Nudge_Front::top_categories() as $top ) {
			if ( $cats && ! array_intersect( $top['ids'], array_map( 'intval', $cats ) ) ) {
				continue;
			}
			$posts = get_posts( array(
				'post_type' => Ace_Nudge_Settings::post_types(), 'post_status' => 'publish', 'posts_per_page' => 8, 'no_found_rows' => true,
				'tax_query' => array( array( 'taxonomy' => $top['taxonomy'], 'terms' => $top['ids'] ) ),
				'date_query' => array( array( 'after' => gmdate( 'Y-m-d H:i:s', time() - $days * DAY_IN_SECONDS ), 'column' => 'post_date_gmt' ) ),
			) );
			$keep = array();
			foreach ( $posts as $p ) {
				if ( count( $keep ) < 3 && empty( $seen[ $p->ID ] ) && ! ( 'post' === $p->post_type && self::is_promotional( $p->post_title ) ) ) {
					$seen[ $p->ID ] = true;
					$keep[]         = $p;
				}
			}
			if ( $keep ) {
				$sections[] = array( 'label' => $top['label'], 'url' => get_term_link( (int) $top['ids'][0] ), 'posts' => $keep );
			}
		}
		return $sections;
	}

	/**
	 * Tables and inline styles, because that is what email clients honour. Colours and logo come
	 * from ace_nudge_brand. Web fonts don't load in most clients, so headings fall back to a heavy sans.
	 */
	public static function render_digest( array $sections, $unsub ) {
		$brand  = Ace_Nudge_Settings::brand();
		$green  = $brand['primary'];
		$bright = $brand['accent'];
		$name   = esc_html( $brand['name'] );
		$font   = "Arial, Helvetica, sans-serif";
		$logo   = $brand['logo'];
		$body   = '';
		foreach ( $sections as $section ) {
			$body .= '<tr><td style="padding:24px 24px 8px;font:900 22px/1.2 ' . $font . ';text-transform:uppercase;color:' . $green . ';border-top:4px solid ' . $bright . '">' . esc_html( $section['label'] ) . '</td></tr>';
			foreach ( $section['posts'] as $p ) {
				$img  = get_the_post_thumbnail_url( $p, 'medium' );
				$link = esc_url( get_permalink( $p ) );
				$body .= '<tr><td style="padding:8px 24px 16px"><table role="presentation" width="100%" cellpadding="0" cellspacing="0"><tr>';
				if ( $img ) {
					$body .= '<td width="132" valign="top" style="padding-right:12px"><a href="' . $link . '"><img src="' . esc_url( $img ) . '" width="120" alt="" style="display:block;width:120px;height:auto;border-radius:8px;border:0"></a></td>';
				}
				$body .= '<td valign="top" style="font:700 16px/1.35 ' . $font . '"><a href="' . $link . '" style="color:#000;text-decoration:none">' . esc_html( get_the_title( $p ) ) . '</a></td></tr></table></td></tr>';
			}
			$body .= '<tr><td style="padding:0 24px 24px"><a href="' . esc_url( $section['url'] ) . '" style="display:inline-block;padding:10px 16px;border-radius:8px;background:' . $green . ';color:#fff;font:700 14px ' . $font . ';text-decoration:none">More ' . esc_html( $section['label'] ) . '</a></td></tr>';
		}
		$link_style = 'color:' . $green . ';font-weight:700';
		$footer     = (string) apply_filters( 'ace_nudge_email_footer', '', $link_style );
		$masthead   = $logo
			? '<img src="' . esc_url( $logo ) . '" height="40" alt="' . $name . '" style="display:block;height:40px;width:auto;border:0;color:#fff;font:700 20px ' . $font . '">'
			: '<span style="color:#fff;font:700 22px ' . $font . ';text-decoration:none">' . $name . '</span>';
		return '<!doctype html><html lang="' . esc_attr( get_bloginfo( 'language' ) ) . '"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>' . $name . '</title></head>'
			. '<body style="margin:0;padding:0;background:' . $brand['page'] . '"><table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:' . $brand['page'] . '"><tr><td align="center" style="padding:16px 8px">'
			. '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:600px;background:#fff;border-radius:12px;overflow:hidden">'
			. '<tr><td style="padding:20px 24px;background:' . $green . '"><a href="' . esc_url( home_url( '/' ) ) . '" style="text-decoration:none">' . $masthead . '</a></td></tr>'
			. $body
			. '<tr><td style="padding:20px 24px;background:#f3f5f4;font:400 13px/1.6 ' . $font . ';color:#222">'
			. ( '' !== $footer ? $footer . '<br><br>' : '' )
			. 'You asked for this newsletter from ' . $name . '. <a style="' . $link_style . '" href="' . esc_url( $unsub ) . '">Unsubscribe</a>'
			. '</td></tr></table></td></tr></table></body></html>';
	}

	public static function send_digest( $sub, array $cats, $days ) {
		global $wpdb;
		$sections = self::digest_sections( $cats, $days );
		if ( ! $sections ) {
			return false;
		}
		$unsub = add_query_arg( 'ace_nudge_unsub', rawurlencode( Ace_Nudge_Store::token( $sub->id ) ), home_url( '/' ) );
		$ok    = wp_mail( $sub->email, 'Your ' . Ace_Nudge_Settings::brand()['name'] . ' round-up', self::render_digest( $sections, $unsub ), array(
			'Content-Type: text/html; charset=UTF-8',
			'List-Unsubscribe: <' . esc_url_raw( $unsub ) . '>',
			'List-Unsubscribe-Post: List-Unsubscribe=One-Click',
		) );
		if ( $ok ) {
			self::count_sent( 'email' );
			$wpdb->update( Ace_Nudge_Store::t( 'subscribers' ), array( 'last_sent_at' => current_time( 'mysql', true ) ), array( 'id' => $sub->id ) );
		}
		return $ok;
	}
}
