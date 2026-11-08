<?php
/**
 * What's new and what's changed, for the notifications list beside the bell.
 *
 * One public answer for everyone: the last few days' new posts and real updates, each with what it is
 * about (terms, post type, the game it covers). The browser keeps what it has read and what it
 * follows, and works out its own unread list from this, so nothing about a reader is sent here and
 * the answer caches like a page.
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

class Ace_Nudge_Feed {
	const DAYS     = 5;
	const LOG      = 'ace_nudge_update_log';
	const CACHE    = 'ace_nudge_feed_v1';
	const MAX_NEW  = 60;
	const MAX_MOD  = 80;

	public static function boot() {
		add_action( 'rest_api_init', function () {
			register_rest_route( 'ace-nudge/v1', '/feed', array( 'methods' => 'GET', 'callback' => array( __CLASS__, 'respond' ), 'permission_callback' => '__return_true' ) );
		} );
		add_action( 'post_updated', array( __CLASS__, 'log_update' ), 11, 3 );
		add_action( 'transition_post_status', function ( $new, $old ) {
			if ( 'publish' === $new || 'publish' === $old ) {
				delete_transient( self::CACHE );
			}
		}, 10, 2 );
	}

	/**
	 * Real updates are kept for the feed's few days (the hourly alert queue empties every run). The
	 * reason starts as "updated"; a site can name it better with ace_nudge_update_reason.
	 */
	public static function log_update( $post_id, $after, $before ) {
		if ( ! Ace_Nudge_Dispatch::is_real_update( $before, $after ) || Ace_Nudge_Dispatch::is_promotional( $after->post_title ) ) {
			return;
		}
		$log             = self::log();
		$log[ $post_id ] = array( 't' => time(), 'r' => (string) apply_filters( 'ace_nudge_update_reason', 'updated', $after, $before ) );
		uasort( $log, fn( $a, $b ) => $b['t'] <=> $a['t'] );
		update_option( self::LOG, array_slice( $log, 0, 300, true ), false );
		delete_transient( self::CACHE );
	}

	private static function log() {
		$cut = time() - self::DAYS * DAY_IN_SECONDS;
		return array_filter( (array) get_option( self::LOG, array() ), fn( $e ) => is_array( $e ) && (int) ( $e['t'] ?? 0 ) >= $cut );
	}

	public static function respond() {
		$items = get_transient( self::CACHE );
		if ( ! is_array( $items ) ) {
			$items = self::build();
			set_transient( self::CACHE, $items, 5 * MINUTE_IN_SECONDS );
		}
		$res = new WP_REST_Response( array( 'days' => self::DAYS, 'now' => time(), 'items' => $items ) );
		$res->header( 'Cache-Control', 'public, max-age=300' );
		return $res;
	}

	/** The item for one post: what it is about, and when it was new or last changed. */
	private static function item( $p ) {
		$terms = wp_get_object_terms( $p->ID, Ace_Nudge_Settings::taxonomies(), array( 'fields' => 'ids' ) );
		$fixture = '';
		if ( 'post' === $p->post_type && class_exists( 'Ace_Sports_Feed' ) ) {
			$fixture = Ace_Sports_Feed::normalise_league_fixture_id( (string) get_post_meta( $p->ID, 'ace_event_tournament_fixture_id', true ) );
		}
		return array(
			'id'        => $p->ID,
			'url'       => get_permalink( $p ),
			'title'     => html_entity_decode( get_the_title( $p ), ENT_QUOTES ),
			'thumb'     => (string) get_the_post_thumbnail_url( $p, 'thumbnail' ),
			'type'      => $p->post_type,
			'terms'     => is_wp_error( $terms ) ? array() : array_map( 'intval', $terms ),
			'fixture'   => $fixture,
			'published' => (int) strtotime( $p->post_date_gmt . ' UTC' ),
			'modified'  => (int) strtotime( $p->post_modified_gmt . ' UTC' ),
			'updated'   => 0,
			'reason'    => 'new',
		);
	}

	public static function build() {
		$since = gmdate( 'Y-m-d H:i:s', time() - self::DAYS * DAY_IN_SECONDS );
		$types = array_values( array_diff( Ace_Nudge_Settings::post_types(), array( 'product' ) ) ) ?: array( 'post' );
		$items = array();
		$keep  = function ( $p ) {
			return ! ( 'post' === $p->post_type && Ace_Nudge_Dispatch::is_promotional( $p->post_title ) );
		};
		foreach ( get_posts( array( 'post_type' => $types, 'post_status' => 'publish', 'posts_per_page' => self::MAX_NEW, 'no_found_rows' => true,
			'date_query' => array( array( 'after' => $since, 'column' => 'post_date_gmt' ) ) ) ) as $p ) {
			if ( $keep( $p ) ) {
				$items[ $p->ID ] = self::item( $p );
			}
		}
		// Logged updates name their reason. Other edits well after publishing count only as a change
		// since the reader last saw the post ("soft"), never as news on their own.
		$log = self::log();
		$mod = get_posts( array( 'post_type' => $types, 'post_status' => 'publish', 'posts_per_page' => self::MAX_MOD, 'no_found_rows' => true, 'orderby' => 'modified', 'order' => 'DESC',
			'date_query' => array( array( 'after' => $since, 'column' => 'post_modified_gmt' ) ) ) );
		$logged = $log ? get_posts( array( 'post_type' => $types, 'post_status' => 'publish', 'post__in' => array_map( 'intval', array_keys( $log ) ), 'posts_per_page' => 300, 'no_found_rows' => true ) ) : array();
		foreach ( array_merge( $logged, $mod ) as $p ) {
			if ( ! $keep( $p ) ) {
				continue;
			}
			$item = $items[ $p->ID ] ?? self::item( $p );
			if ( isset( $log[ $p->ID ] ) ) {
				$item['updated'] = max( (int) $log[ $p->ID ]['t'], $item['updated'] );
				$item['reason']  = $log[ $p->ID ]['r'];
				unset( $item['soft'] );
			} elseif ( ! $item['updated'] && $item['modified'] - $item['published'] > HOUR_IN_SECONDS ) {
				$item['updated'] = $item['modified'];
				$item['soft']    = 1;
			}
			$items[ $p->ID ] = $item;
		}
		/**
		 * Sites add their own reasons, such as a result coming in for a tip in the post: set 'result' to
		 * the time and 'reason' to 'result' on the matching items (or add items for other posts).
		 */
		$items = (array) apply_filters( 'ace_nudge_feed_items', $items, $since );
		usort( $items, fn( $a, $b ) => max( $b['published'], $b['updated'], $b['result'] ?? 0 ) <=> max( $a['published'], $a['updated'], $a['result'] ?? 0 ) );
		return array_values( $items );
	}
}
