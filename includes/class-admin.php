<?php
/**
 * The admin screens. Mostly totals: what people ask to hear about, and how many. Individual reading
 * history is never shown, only that a subscriber has the option on.
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

class Ace_Nudge_Admin {
	const SLUG = 'ace-nudge';

	public static function boot() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'savebar' ), 99 );
		add_action( 'admin_post_ace_nudge_save', array( __CLASS__, 'save' ) );
		add_action( 'admin_post_ace_nudge_remove', array( __CLASS__, 'remove' ) );
	}

	public static function menu() {
		add_menu_page( 'Ace NUDGE', 'Ace NUDGE', 'manage_options', self::SLUG, array( __CLASS__, 'page' ), 'dashicons-bell', 58 );
	}

	/** The suite's save bar ships with Ace Crawl Enhancer, which only registers it on its own screens. */
	public static function savebar( $hook ) {
		if ( 'toplevel_page_' . self::SLUG !== $hook ) {
			return;
		}
		// Crawl 1.0.98 names its constants ACECRAWL_*; earlier versions used ACE_SEO_*.
		$path = defined( 'ACECRAWL_PATH' ) ? ACECRAWL_PATH : ( defined( 'ACE_SEO_PATH' ) ? ACE_SEO_PATH : '' );
		$url  = defined( 'ACECRAWL_URL' ) ? ACECRAWL_URL : ( defined( 'ACE_SEO_URL' ) ? ACE_SEO_URL : '' );
		if ( ! wp_script_is( 'ace-savebar', 'registered' ) && $path && $url && file_exists( $path . 'build/savebar.js' ) ) {
			wp_register_script( 'ace-savebar', $url . 'build/savebar.js', array( 'jquery' ), ACE_NUDGE_VERSION, true );
		}
		if ( wp_script_is( 'ace-savebar', 'registered' ) ) {
			wp_enqueue_script( 'ace-savebar' );
		}
	}

	private static function count( $sql ) {
		global $wpdb;
		return (int) $wpdb->get_var( $sql );
	}

	/** Part of an address is enough to recognise a test or a support request. */
	public static function mask( $email ) {
		[ $user, $domain ] = array_pad( explode( '@', (string) $email, 2 ), 2, '' );
		return substr( $user, 0, 1 ) . '***@' . $domain;
	}

	public static function page() {
		$tabs = array( 'overview' => 'Overview', 'subscribers' => 'Subscribers', 'consent' => 'Consent log', 'settings' => 'Settings' );
		$tab  = sanitize_key( $_GET['tab'] ?? 'overview' );
		$tab  = isset( $tabs[ $tab ] ) ? $tab : 'overview';
		echo '<div class="wrap"><h1>Ace NUDGE</h1><p class="description">Notifications, Updates, Digests, Gated Email. Mode: <strong>' . esc_html( ace_nudge_mode() ) . '</strong></p><nav class="nav-tab-wrapper">';
		foreach ( $tabs as $key => $label ) {
			printf( '<a class="nav-tab %s" href="%s">%s</a>', $key === $tab ? 'nav-tab-active' : '', esc_url( admin_url( 'admin.php?page=' . self::SLUG . '&tab=' . $key ) ), esc_html( $label ) );
		}
		echo '</nav>';
		self::{ 'tab_' . $tab }();
		echo '</div>';
	}

	private static function table( array $head, array $rows, $empty = 'Nothing yet.' ) {
		echo '<table class="widefat striped" style="max-width:900px;margin:0 0 1.5rem"><thead><tr>';
		foreach ( $head as $h ) {
			echo '<th>' . esc_html( $h ) . '</th>';
		}
		echo '</tr></thead><tbody>';
		if ( ! $rows ) {
			echo '<tr><td colspan="' . count( $head ) . '">' . esc_html( $empty ) . '</td></tr>';
		}
		foreach ( $rows as $row ) {
			echo '<tr>';
			foreach ( $row as $cell ) {
				echo '<td>' . wp_kses_post( (string) $cell ) . '</td>';
			}
			echo '</tr>';
		}
		echo '</tbody></table>';
	}

	private static function tab_overview() {
		global $wpdb;
		$s = Ace_Nudge_Store::t( 'subscribers' );
		$f = Ace_Nudge_Store::t( 'follows' );
		$c = Ace_Nudge_Store::t( 'consent_log' );
		$since = gmdate( 'Y-m-d H:i:s', time() - 30 * DAY_IN_SECONDS );
		$sent  = array( 'push' => 0, 'email' => 0 );
		foreach ( (array) get_option( 'ace_nudge_sent', array() ) as $day ) {
			$sent['push']  += (int) ( $day['push'] ?? 0 );
			$sent['email'] += (int) ( $day['email'] ?? 0 );
		}
		$cards = array(
			'Browser subscribers' => self::count( "SELECT COUNT(*) FROM $s WHERE channel = 'push'" ),
			'Newsletter, confirmed' => self::count( "SELECT COUNT(*) FROM $s WHERE channel = 'email' AND status = 'active'" ),
			'Newsletter, unconfirmed' => self::count( "SELECT COUNT(*) FROM $s WHERE channel = 'email' AND status = 'pending'" ),
			'Withdrew, 30 days' => self::count( $wpdb->prepare( "SELECT COUNT(*) FROM $c WHERE action = 'withdrawn' AND created_at > %s", $since ) ),
			'Notifications sent, 30 days' => $sent['push'],
			'Emails sent, 30 days' => $sent['email'],
		);
		echo '<div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(170px,1fr));gap:12px;max-width:900px;margin:1.5rem 0">';
		foreach ( $cards as $label => $n ) {
			echo '<div style="background:#fff;border:1px solid #dcdcde;border-radius:8px;padding:14px"><div style="font-size:28px;font-weight:700">' . number_format_i18n( $n ) . '</div><div>' . esc_html( $label ) . '</div></div>';
		}
		echo '</div>';

		$kinds = array( 'category' => 'Categories', 'fixture' => 'Games', 'post' => 'Stories and products', 'ptype' => 'Whole post types', 'order' => 'Single orders', 'customer' => 'All orders of a customer', 'wc' => 'Product emails', 'digest' => 'Newsletter frequency', 'read' => 'Reading history entries' );
		$rows  = array();
		foreach ( $wpdb->get_results( "SELECT type, COUNT(*) n, COUNT(DISTINCT subscriber_id) p FROM $f GROUP BY type ORDER BY n DESC" ) as $r ) {
			$rows[] = array( esc_html( 'site' === $r->type ? 'Whole site and read updates' : ( $kinds[ $r->type ] ?? $r->type ) ), (int) $r->n, (int) $r->p );
		}
		echo '<h2>What people ask to hear about</h2>';
		self::table( array( 'Type', 'Follows', 'People' ), $rows );

		$top = function ( $type, $label ) use ( $wpdb, $f ) {
			$rows = array();
			foreach ( $wpdb->get_results( $wpdb->prepare( "SELECT object_id, MAX(meta) meta, COUNT(*) n FROM $f WHERE type = %s GROUP BY object_id ORDER BY n DESC LIMIT 15", $type ) ) as $r ) {
				$name = $r->object_id;
				if ( 'category' === $type && ( $term = get_term( (int) $r->object_id ) ) && ! is_wp_error( $term ) ) {
					$name = $term->name;
				} elseif ( 'post' === $type ) {
					$name = '<a href="' . esc_url( (string) get_permalink( (int) $r->object_id ) ) . '">' . esc_html( get_the_title( (int) $r->object_id ) ) . '</a>';
				} elseif ( 'fixture' === $type ) {
					$name = (string) ( json_decode( (string) $r->meta, true )['label'] ?? $r->object_id );
				} elseif ( 'digest' === $type ) {
					$name = ucfirst( $name );
				}
				$key          = wp_strip_all_tags( $name );
				$rows[ $key ] = array( 'post' === $type ? $name : esc_html( $name ), (int) $r->n + (int) ( $rows[ $key ][1] ?? 0 ) );
			}
			$rows = array_values( $rows );
			echo '<h2>' . esc_html( $label ) . '</h2>';
			self::table( array( 'Name', 'Followers' ), $rows );
		};
		$top( 'category', 'Top categories' );
		$top( 'fixture', 'Top games' );
		$top( 'post', 'Top stories and products' );
		$top( 'digest', 'Newsletter frequency' );
	}

	private static function tab_subscribers() {
		global $wpdb;
		$s     = Ace_Nudge_Store::t( 'subscribers' );
		$f     = Ace_Nudge_Store::t( 'follows' );
		$paged = max( 1, (int) ( $_GET['paged'] ?? 1 ) );
		$per   = 50;
		$total = self::count( "SELECT COUNT(*) FROM $s" );
		$subs  = $wpdb->get_results( $wpdb->prepare( "SELECT s.*, SUM(f.type = 'category') cats, SUM(f.type = 'fixture') games, SUM(f.type = 'post') stories, SUM(f.type = 'site' AND f.object_id = 'all') whole, SUM(f.type = 'site' AND f.object_id = 'reads') reads_on FROM $s s LEFT JOIN $f f ON f.subscriber_id = s.id GROUP BY s.id ORDER BY s.id DESC LIMIT %d OFFSET %d", $per, ( $paged - 1 ) * $per ) );
		$rows  = array();
		foreach ( $subs as $sub ) {
			$who = 'email' === $sub->channel ? self::mask( $sub->email ) : (string) wp_parse_url( (string) $sub->endpoint, PHP_URL_HOST );
			$url = wp_nonce_url( admin_url( 'admin-post.php?action=ace_nudge_remove&id=' . (int) $sub->id ), 'ace_nudge_remove_' . $sub->id );
			$rows[] = array(
				(int) $sub->id, esc_html( ucfirst( $sub->channel ) ), esc_html( $who ), esc_html( $sub->status ),
				(int) $sub->cats . ' categories, ' . (int) $sub->games . ' games, ' . (int) $sub->stories . ' stories' . ( $sub->whole ? ', whole site' : '' ) . ( $sub->reads_on ? ', read updates' : '' ),
				esc_html( $sub->created_at ), esc_html( (string) $sub->last_sent_at ),
				'<a href="' . esc_url( $url ) . '">Remove</a>',
			);
		}
		echo '<p>' . number_format_i18n( $total ) . ' subscribers. Addresses are part-hidden; use Tools &gt; Export Personal Data for a full request.</p>';
		self::table( array( 'ID', 'Channel', 'Who', 'Status', 'Follows', 'Joined (UTC)', 'Last sent (UTC)', '' ), $rows );
		if ( $total > $per ) {
			echo wp_kses_post( paginate_links( array( 'base' => add_query_arg( 'paged', '%#%' ), 'total' => ceil( $total / $per ), 'current' => $paged ) ) );
		}
	}

	private static function tab_consent() {
		global $wpdb;
		$rows = array();
		foreach ( $wpdb->get_results( 'SELECT * FROM ' . Ace_Nudge_Store::t( 'consent_log' ) . ' ORDER BY id DESC LIMIT 100' ) as $r ) {
			$rows[] = array( esc_html( $r->created_at ), esc_html( $r->channel ), esc_html( $r->action ), $r->age_confirmed ? 'Yes' : '', esc_html( $r->wording_version ), esc_html( substr( $r->subject_hash, 0, 10 ) ), esc_html( $r->wording ) );
		}
		echo '<p>The latest 100 entries. Hashes only, with the exact wording shown at the time.</p>';
		self::table( array( 'When (UTC)', 'Channel', 'Action', 'Age check', 'Wording version', 'Subject', 'Wording' ), $rows );
	}

	private static function tab_settings() {
		$o = Ace_Nudge_Settings::all();
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" data-ace-savebar="admin-post" data-ace-savebar-autosave="0" data-ace-savebar-reload="1">';
		echo '<input type="hidden" name="action" value="ace_nudge_save">';
		wp_nonce_field( 'ace_nudge_save' );
		echo '<table class="form-table"><tr><th>Notify about new</th><td>';
		foreach ( get_post_types( array( 'public' => true ), 'objects' ) as $type ) {
			if ( 'attachment' === $type->name ) {
				continue;
			}
			$note = array( 'post' => ' (with categories, games and story updates)', 'product' => ' (with product categories, back in stock and price drops)' )[ $type->name ] ?? '';
			printf( '<label style="display:block;margin:0 0 6px"><input type="checkbox" name="post_types[]" value="%s" %s> %s%s</label>', esc_attr( $type->name ), checked( in_array( $type->name, (array) $o['post_types'], true ), true, false ), esc_html( $type->labels->name ), esc_html( $note ) );
		}
		echo '</td></tr>';
		printf( '<tr><th><label for="ace-nudge-cap">Most grouped alerts a day, per browser</label></th><td><input id="ace-nudge-cap" type="number" min="1" max="24" name="daily_cap" value="%d" class="small-text"><p class="description">Live game alerts aren\'t counted.</p></td></tr>', (int) $o['daily_cap'] );
		printf( '<tr><th>Quiet hours (site time zone)</th><td><label>From <input type="number" min="0" max="23" name="quiet_start" value="%d" class="small-text">:00</label> <label>to <input type="number" min="0" max="23" name="quiet_end" value="%d" class="small-text">:00</label><p class="description">Grouped alerts wait until these end. Set both the same to turn this off.</p></td></tr>', (int) $o['quiet_start'], (int) $o['quiet_end'] );
		$forced = Ace_Nudge_Settings::min_age() !== (int) $o['min_age'];
		printf( '<tr><th><label for="ace-nudge-age">Age limit</label></th><td><input id="ace-nudge-age" type="number" min="0" max="99" name="min_age" value="%d" class="small-text"><p class="description">Asks for a year of birth before following or signing up. Use 0 for no age check. Order updates aren\'t age checked.%s</p></td></tr>', (int) $o['min_age'], $forced ? ' <strong>This site\'s code sets it to ' . (int) Ace_Nudge_Settings::min_age() . ', which takes priority.</strong>' : '' );
		printf( '<tr><th>Newsletter</th><td><label><input type="checkbox" name="newsletter" value="1" %s> Offer the newsletter sign-up</label><p class="description">Switch off if the site has its own newsletter.</p></td></tr>', checked( ! empty( $o['newsletter'] ), true, false ) );
		printf( '<tr><th>Follow buttons</th><td><label><input type="checkbox" name="auto_bar" value="1" %s> Add them to pages automatically</label><p class="description">A page with the Follow Button block always uses the block instead.</p></td></tr>', checked( ! empty( $o['auto_bar'] ), true, false ) );
		if ( class_exists( 'WooCommerce' ) ) {
			echo '<tr><th>Order updates on devices</th><td>';
			foreach ( wc_get_order_statuses() as $status => $label ) {
				$status = substr( $status, 3 );
				printf( '<label style="display:block;margin:0 0 6px"><input type="checkbox" name="order_statuses[]" value="%s" %s> %s</label>', esc_attr( $status ), checked( in_array( $status, (array) $o['order_statuses'], true ), true, false ), esc_html( $label ) );
			}
			echo '</td></tr><tr><th>Emails customers can switch off</th><td>';
			$optional = Ace_Nudge_Woo::optional_email_choices();
			foreach ( $optional as $id => $title ) {
				printf( '<label style="display:block;margin:0 0 6px"><input type="checkbox" name="wc_optional_emails[]" value="%s" %s> %s</label>', esc_attr( $id ), checked( in_array( $id, (array) $o['wc_optional_emails'], true ), true, false ), esc_html( $title ) );
			}
			echo '<p class="description">Order confirmations, invoices, refunds and password emails are always sent, so they aren\'t listed. Templates and sending stay in WooCommerce &gt; Settings &gt; Emails.</p></td></tr>';
		}
		echo '</table>';
		// Without the suite's save bar, for example on a site without Ace Crawl Enhancer.
		if ( ! wp_script_is( 'ace-savebar', 'enqueued' ) ) {
			submit_button();
		}
		echo '</form>';
	}

	public static function save() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Not allowed.', '', array( 'response' => 403 ) );
		}
		check_admin_referer( 'ace_nudge_save' );
		$hour = fn( $k, $d ) => isset( $_POST[ $k ] ) ? min( 23, max( 0, (int) $_POST[ $k ] ) ) : $d;
		update_option( Ace_Nudge_Settings::OPTION, array(
			'post_types'  => array_values( array_filter( array_map( 'sanitize_key', (array) ( $_POST['post_types'] ?? array() ) ), 'post_type_exists' ) ),
			'daily_cap'   => min( 24, max( 1, (int) ( $_POST['daily_cap'] ?? 3 ) ) ),
			'quiet_start' => $hour( 'quiet_start', 21 ),
			'quiet_end'   => $hour( 'quiet_end', 8 ),
			'auto_bar'    => empty( $_POST['auto_bar'] ) ? 0 : 1,
			'newsletter'  => empty( $_POST['newsletter'] ) ? 0 : 1,
			'min_age'     => min( 99, max( 0, (int) ( $_POST['min_age'] ?? 0 ) ) ),
			'order_statuses' => array_map( 'sanitize_key', (array) ( $_POST['order_statuses'] ?? ( class_exists( 'WooCommerce' ) ? array() : Ace_Nudge_Settings::get( 'order_statuses' ) ) ) ),
			'wc_optional_emails' => array_map( 'sanitize_key', (array) ( $_POST['wc_optional_emails'] ?? ( class_exists( 'WooCommerce' ) ? array() : Ace_Nudge_Settings::get( 'wc_optional_emails' ) ) ) ),
		), false );
		if ( ! empty( $_POST['ace_json'] ) ) {
			wp_send_json_success( array( 'message' => 'Saved.', 'reload' => true ) );
		}
		wp_safe_redirect( admin_url( 'admin.php?page=' . self::SLUG . '&tab=settings' ) );
		exit;
	}

	public static function remove() {
		global $wpdb;
		$id = (int) ( $_GET['id'] ?? 0 );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Not allowed.', '', array( 'response' => 403 ) );
		}
		check_admin_referer( 'ace_nudge_remove_' . $id );
		$sub = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . Ace_Nudge_Store::t( 'subscribers' ) . ' WHERE id = %d', $id ) );
		if ( $sub ) {
			Ace_Nudge_Store::withdraw( $sub, 'removed_by_admin' );
		}
		wp_safe_redirect( admin_url( 'admin.php?page=' . self::SLUG . '&tab=subscribers' ) );
		exit;
	}
}
