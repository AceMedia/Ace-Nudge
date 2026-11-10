<?php
/**
 * What the browser gets. Pages are cached, so the page carries only what is true for everyone
 * (which game and categories it is about) and the script decides the rest.
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

class Ace_Nudge_Front {
	public static function boot() {
		add_action( 'init', array( __CLASS__, 'routes' ), 1 );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'assets' ) );
		// iPhones and iPads only allow notifications for a site added to the Home Screen.
		add_action( 'wp_head', function () {
			$manifest = (string) apply_filters( 'ace_nudge_manifest_url', '' );
			if ( $manifest ) {
				echo '<link rel="manifest" href="' . esc_url( $manifest ) . '">' . "\n";
			}
		} );
	}

	/** The service worker has to come from the root to control the whole site. */
	public static function routes() {
		if ( isset( $_GET['ace_nudge_sw'] ) ) {
			header( 'Content-Type: text/javascript; charset=utf-8' );
			header( 'Cache-Control: no-cache' );
			readfile( ACE_NUDGE_DIR . '/assets/sw.js' );
			exit;
		}
		// One-click unsubscribe from mail clients posts here too; handled by link_page.
		if ( isset( $_GET['ace_nudge_confirm'] ) ) {
			self::link_page( 'confirm', (string) wp_unslash( $_GET['ace_nudge_confirm'] ) );
		}
		if ( isset( $_GET['ace_nudge_unsub'] ) ) {
			self::link_page( 'unsub', (string) wp_unslash( $_GET['ace_nudge_unsub'] ) );
		}
	}

	/** Links only act on a POST, so a mail scanner opening them changes nothing. */
	private static function link_page( $action, $token ) {
		global $wpdb;
		nocache_headers();
		$sub = Ace_Nudge_Store::subscriber_from_token( $token );
		if ( 'POST' === ( $_SERVER['REQUEST_METHOD'] ?? '' ) ) {
			if ( $sub && 'confirm' === $action ) {
				$wpdb->update( Ace_Nudge_Store::t( 'subscribers' ), array( 'status' => 'active' ), array( 'id' => $sub->id ) );
				Ace_Nudge_Store::log( $sub->endpoint_hash, 'email', 'granted', true );
			} elseif ( $sub ) {
				Ace_Nudge_Store::withdraw( $sub );
			}
			wp_die( 'confirm' === $action ? 'Thanks, you\'re subscribed.' : 'You\'re unsubscribed. We won\'t email you again.', Ace_Nudge_Settings::brand()['name'], array( 'response' => 200 ) );
		}
		$label = 'confirm' === $action ? 'Confirm my subscription' : 'Unsubscribe';
		$url   = esc_url( add_query_arg( 'ace_nudge_' . $action, rawurlencode( $token ), home_url( '/' ) ) );
		wp_die( '<form method="post" action="' . $url . '"><button type="submit">' . esc_html( $label ) . '</button></form>', Ace_Nudge_Settings::brand()['name'], array( 'response' => 200 ) );
	}

	/** The game a versus post is pinned to, if Ace Sports Feed can track it. */
	public static function fixture_for_post( $post_id ) {
		$post = get_post( $post_id );
		if ( ! $post || 'publish' !== $post->post_status || ! class_exists( 'Ace_Sports_Feed' ) || ! class_exists( 'ACE_Teams_Events_Plugin' ) ) {
			return null;
		}
		$fid = Ace_Sports_Feed::normalise_league_fixture_id( (string) get_post_meta( $post_id, 'ace_event_tournament_fixture_id', true ) );
		if ( '' === $fid ) {
			return null;
		}
		// The id ends in the kick-off date, and a game can be followed days before it appears
		// in the live snapshot. Dev only has past games, so ACE_NUDGE_SHOW_ALL keeps them followable.
		if ( ! preg_match( '/^league:(.+)-(\d{8})$/', $fid, $m ) || ( (int) $m[2] < (int) gmdate( 'Ymd', time() - DAY_IN_SECONDS ) && ! ( defined( 'ACE_NUDGE_SHOW_ALL' ) && ACE_NUDGE_SHOW_ALL ) ) ) {
			return null;
		}
		$terms = wp_get_post_terms( $post_id, ACE_Teams_Events_Plugin::EVENT_TAX, array( 'fields' => 'slugs' ) );
		if ( is_wp_error( $terms ) || ! $terms ) {
			return null;
		}
		return array(
			'id'    => $fid,
			'post_id' => $post_id,
			'hubs'  => $terms,
			'label' => str_replace( ' V ', ' v ', ucwords( str_replace( '-', ' ', $m[1] ) ) ),
			'url'   => get_permalink( $post ),
			'kickoff' => (int) ( get_post_meta( $post_id, 'ace_event_primary_kickoff_at', true ) ?: get_post_meta( $post_id, 'ace_event_kickoff_at', true ) ),
		);
	}

	/** The main categories by posts. Older and newer terms can share a name, so those are merged. */
	public static function top_categories() {
		$top = array();
		foreach ( get_terms( array( 'taxonomy' => Ace_Nudge_Settings::menu_taxonomies() ?: array( 'category' ), 'parent' => 0, 'hide_empty' => true, 'orderby' => 'count', 'order' => 'DESC', 'number' => 30 ) ) as $term ) {
			$label = html_entity_decode( $term->name );
			if ( 0 === stripos( $label, 'uncategori' ) ) {
				continue;
			}
			$top[ $label ]['label']    = $label;
			$top[ $label ]['taxonomy'] = $term->taxonomy;
			$top[ $label ]['ids'][]    = $term->term_id;
		}
		return apply_filters( 'ace_nudge_top_categories', array_slice( array_values( $top ), 0, 8 ) );
	}

	/** What can be followed from the page being viewed. */
	public static function targets() {
		$types   = Ace_Nudge_Settings::post_types();
		$targets = array();
		$term    = fn( $t ) => array( 'type' => 'category', 'id' => $t->term_id, 'key' => 'category:' . $t->term_id, 'label' => html_entity_decode( $t->name ) );
		if ( is_singular( $types ) ) {
			$id   = get_the_ID();
			$type = get_post_type();
			if ( 'post' === $type ) {
				$fixture = self::fixture_for_post( $id );
				if ( $fixture ) {
					$targets[] = array( 'type' => 'fixture', 'post_id' => $id, 'key' => 'fixture:' . $fixture['id'], 'label' => $fixture['label'] );
				}
			}
			$object    = get_post_type_object( $type );
			$noun      = array( 'post' => 'story', 'product' => 'product' )[ $type ] ?? strtolower( $object->labels->singular_name );
			$targets[] = array( 'type' => 'post', 'post_id' => $id, 'key' => 'post:' . $id, 'label' => 'this ' . $noun );
			foreach ( Ace_Nudge_Settings::taxonomies() as $tax ) {
				if ( is_object_in_taxonomy( $type, $tax ) ) {
					$terms = array_values( array_filter( (array) get_the_terms( $id, $tax ), fn( $t ) => $t instanceof WP_Term && 0 !== stripos( $t->name, 'uncategori' ) ) );
					// Some taxonomies, such as teams, get a button per term rather than just the main one.
					$each = in_array( $tax, (array) apply_filters( 'ace_nudge_follow_every_term', array() ), true ) ? $terms : array_slice( $terms, 0, 1 );
					foreach ( $each as $t ) {
						$targets[] = $term( $t );
					}
				}
			}
		} elseif ( is_category() || is_tax( Ace_Nudge_Settings::taxonomies() ) ) {
			$object = get_queried_object();
			if ( in_array( $object->taxonomy, Ace_Nudge_Settings::taxonomies(), true ) ) {
				$targets[] = $term( $object );
			}
		} elseif ( is_post_type_archive( $types ) ) {
			$object    = get_queried_object();
			$targets[] = array( 'type' => 'ptype', 'id' => $object->name, 'key' => 'ptype:' . $object->name, 'label' => 'all ' . strtolower( $object->labels->name ) );
		}
		return apply_filters( 'ace_nudge_targets', $targets );
	}

	public static function assets() {
		$targets = self::targets();
		$bell_selector = (string) apply_filters( 'ace_nudge_bell_selector', '' );
		if ( '' !== $bell_selector ) {
			add_action( 'wp_footer', function () use ( $bell_selector ) {
				self::bell_slot( $bell_selector );
			}, 5 );
		}
		$base = ACE_NUDGE_DIR . '/assets/';
		$brand = Ace_Nudge_Settings::brand();
		wp_enqueue_style( 'ace-nudge', ACE_NUDGE_URL . 'assets/notify.css', array(), (string) filemtime( $base . 'notify.css' ) );
		wp_add_inline_style( 'ace-nudge', ':root{--ace-nudge-primary:' . esc_attr( $brand['primary'] ) . ';--ace-nudge-accent:' . esc_attr( $brand['accent'] ) . ';--ace-nudge-page:' . esc_attr( $brand['page'] ) . '}' );
		wp_enqueue_script( 'ace-nudge', ACE_NUDGE_URL . 'assets/notify.js', array(), (string) filemtime( $base . 'notify.js' ), array( 'strategy' => 'defer', 'in_footer' => true ) );
		wp_localize_script( 'ace-nudge', 'aceNudge', array(
			'mode'    => defined( 'ACE_NUDGE_SHOW_ALL' ) && ACE_NUDGE_SHOW_ALL ? 'on' : ace_nudge_mode(),
			'rest'    => esc_url_raw( rest_url( 'ace-nudge/v1/' ) ),
			'sw'      => esc_url_raw( home_url( '/?ace_nudge_sw=1' ) ),
			'vapid'   => Ace_Nudge_Push::keys()['public'],
			'targets' => $targets,
			'categories' => self::top_categories(),
			'siteLabels' => Ace_Nudge_Settings::extra_site_follows(),
			'ordersWording' => Ace_Nudge_Store::wording( 'orders' ),
			'newsletter' => (bool) Ace_Nudge_Settings::get( 'newsletter' ),
			'minAge'  => Ace_Nudge_Settings::min_age(),
			'autoBar' => (bool) Ace_Nudge_Settings::get( 'auto_bar' ),
			'name'    => Ace_Nudge_Settings::brand()['name'],
			'footerAfter' => (string) apply_filters( 'ace_nudge_footer_selector', '' ),
			'bell'    => $bell_selector,
			'host'    => (string) apply_filters( 'ace_nudge_host_selector', '' ),
			'post_id' => is_singular( Ace_Nudge_Settings::post_types() ) ? get_the_ID() : 0,
			// When the post on this page last changed, so the browser knows which version it has seen.
			'post_modified' => is_singular( Ace_Nudge_Settings::post_types() ) ? (int) get_post_modified_time( 'U', true ) : 0,
			'feed'    => esc_url_raw( rest_url( 'ace-nudge/v1/feed' ) ),
			// What the post on this page is about, so the browser can learn which sports a reader reads
			// (kept on the device, and only while they allow it in Settings).
			'post_terms' => is_singular( Ace_Nudge_Settings::post_types() ) ? array_map( 'intval', (array) wp_get_object_terms( get_the_ID(), Ace_Nudge_Settings::taxonomies(), array( 'fields' => 'ids' ) ) ) : array(),
			// A site's bet slip, when it has one: where the browser keeps it ('storage') and the public,
			// cached endpoint that says how each pick stands now ('state'). Empty without one.
			'slip'    => (array) apply_filters( 'ace_nudge_slip_config', array() ),
			// Pages that are apps in their own right, where the notification bubble would be in the way.
			'quietOn' => (string) apply_filters( 'ace_nudge_bubble_quiet_selector', '' ),
			'wording' => array( 'push' => Ace_Nudge_Store::wording( 'push' ), 'email' => Ace_Nudge_Store::wording( 'email' ), 'reads' => Ace_Nudge_Store::wording( 'reads' ) ),
			'privacy' => esc_url_raw( (string) get_privacy_policy_url() ),
			// Grouped alerts wait out these hours (site time); the settings panel says so.
			'quiet'   => array( 'start' => (int) Ace_Nudge_Settings::get( 'quiet_start' ), 'end' => (int) Ace_Nudge_Settings::get( 'quiet_end' ) ),
		) );
	}

	/**
	 * The bell's place in the header, held from the moment the page is parsed. notify.js loads deferred,
	 * after scripts in the footer (a header menu that fits its items to the room, say) have measured the
	 * header without it; when the bell then arrived the room changed and the menu was fitted again,
	 * moving an item in or out of its overflow. This puts the same button in its place early, before
	 * those scripts run; notify.js takes it over (or removes it where no bell is shown).
	 *
	 * @param string $selector Where the bell goes: before this element.
	 */
	private static function bell_slot( $selector ) {
		$svg = '<svg viewBox="0 0 24 24" width="24" height="24" aria-hidden="true"><path fill="currentColor" d="M12 22a2.5 2.5 0 0 0 2.45-2h-4.9A2.5 2.5 0 0 0 12 22zm7-6v-5a7 7 0 0 0-5.5-6.84V3.5a1.5 1.5 0 0 0-3 0v.66A7 7 0 0 0 5 11v5l-2 2v1h18v-1z"/></svg>';
		echo '<script id="ace-nudge-bell-slot">(function(s,h){try{var t=document.querySelector(s);if(!t||document.querySelector(".ace-nudge-bell"))return;var b=document.createElement("button");b.type="button";b.className="ace-nudge-bell ace-nudge-bell--slot";b.setAttribute("aria-label","Alerts and newsletter");b.title="Alerts and newsletter";b.innerHTML=h;t.before(b);}catch(e){}})(' . wp_json_encode( $selector ) . ',' . wp_json_encode( $svg . '<span class="ace-nudge-bell__count" hidden></span>' ) . ');</script>' . "\n"; // phpcs:ignore WordPress.Security.EscapeOutput
	}
}
