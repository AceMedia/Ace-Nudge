# Developer guide

*NUDGE* keeps site rules out of the plugin. A site, theme or companion plugin shapes it through filters, one action, a few static methods and a browser API. The full lists are in [Hooks and the browser API](hooks.md). The examples here were checked against the 0.7.2 source.

Guard calls into *NUDGE* so your code keeps working without it:

```php
if ( function_exists( 'ace_nudge_mode' ) && 'off' !== ace_nudge_mode() ) {
	// NUDGE is running.
}
```

## Site rules

```php
// A minimum age, which the settings screen cannot lower.
add_filter( 'ace_nudge_min_age', fn() => 16 );

// Never notify about promotional posts.
add_filter( 'ace_nudge_promo_pattern', fn() => '/\b(sponsored|advertorial|promo code)\b/i' );

// The wording shown beside each switch, which is also what the consent log stores.
add_filter( 'ace_nudge_wording', function ( $words ) {
	$words['push'] = 'Send me browser notifications about what I follow. I can turn them off at any time.';
	return $words;
} );
```

The wording keys are `push`, `orders`, `product_updates`, `reads` and `email`.

## Following more than categories

Make a custom taxonomy followable, with a button for every term on a post:

```php
add_filter( 'ace_nudge_taxonomies', function ( $taxonomies ) {
	$taxonomies[] = 'topic';
	return $taxonomies;
} );
add_filter( 'ace_nudge_follow_every_term', fn( $taxonomies ) => array_merge( $taxonomies, array( 'topic' ) ) );
```

Terms in extra taxonomies are followed as type `category` with the term id, and are listed by name in the manage panel.

## Shaping the follow buttons

`ace_nudge_targets` receives the targets for the current page. Each is an array with `type`, `key` (`type:id`), `label`, and `id` or `post_id`. A target may also carry presentation keys the script understands:

| Key | Effect |
|---|---|
| `colour` | Border and label colour. Pale colours are darkened for the label so it stays readable on white |
| `contrast` | Text colour on the filled, following state |
| `icon` | Image URL shown at 22 by 22 pixels |
| `side` | `home`, `centre` or `away`: orders buttons in one row, for a page that sets two sides against each other |
| `days` | The follow lapses after this many days (30 at most) |

```php
add_filter( 'ace_nudge_targets', function ( $targets ) {
	foreach ( $targets as &$target ) {
		if ( 'category' === $target['type'] ) {
			$colour = get_term_meta( (int) $target['id'], 'topic_colour', true );
			if ( $colour ) {
				$target['colour']   = $colour;
				$target['contrast'] = '#ffffff';
			}
		}
	}
	return $targets;
} );
```

The server resolves every follow itself from `type`, `id` and `post_id`, so a target you add must be one the server accepts: a term in a followable taxonomy, an enabled post type, a published post of an enabled type, or a site switch.

## Your own opt-in alert

Another plugin can offer an alert of its own, off until a reader switches it on, and send to everyone who did.

```php
// 1. Name it. Ids are lower case letters, digits and hyphens, 40 characters at most.
add_filter( 'ace_nudge_site_follows', function ( $follows ) {
	$follows['sale-alerts'] = 'Sale alerts';
	return $follows;
} );

// 2. Send it. broadcast() returns false during quiet hours, so try again later.
function example_send_sale_alert() {
	if ( ! class_exists( 'Ace_Nudge_Dispatch' ) ) {
		return;
	}
	$sent = Ace_Nudge_Dispatch::broadcast( 'sale-alerts', array(
		'title' => 'The winter sale is open',
		'body'  => 'Selected lines are reduced until Sunday.',
		'url'   => home_url( '/sale/' ),
		'tag'   => 'sale-alerts',
	) );
	if ( ! $sent ) {
		wp_schedule_single_event( time() + HOUR_IN_SECONDS, 'example_send_sale_alert' );
	}
}
add_action( 'example_send_sale_alert', 'example_send_sale_alert' );
```

Each reader's daily cap applies. *NUDGE*'s own dialog does not list these switches, so offer the switch in your own interface with the browser API:

```js
const api = window.aceNudge && window.aceNudge.api;
if ( api && api.available && api.status().notifications ) {
	checkbox.checked = api.site( 'sale-alerts' );
	checkbox.addEventListener( 'change', () => api.setSite( 'sale-alerts', checkbox.checked ) );
}
```

The built-in switches `slipprices`, `slipremind` and `tips` work the same way.

## Reacting in the browser

```js
document.addEventListener( 'ace-nudge:change', ( e ) => {
	const { status, follows } = e.detail;
	console.log( status.subscribed, follows.map( ( f ) => f.label ) );
} );

document.addEventListener( 'ace-nudge:notifications', ( e ) => {
	myBadge.textContent = e.detail.unread || '';
} );

// Follow from your own button. Runs the age check and permission steps when needed.
window.aceNudge.api.follow( { type: 'category', id: 12, key: 'category:12', label: 'Travel', days: 7 } );
```

`window.aceNudge` only exists in `test` and `on` modes, and `api` is set when `notify.js` has run, so check for both.

## Naming updates and adding to the feed

By default an edit is a real update when the title changes or about 200 characters of text are added or removed.

```php
// Treat any edit to a live blog as an update.
add_filter( 'ace_nudge_is_real_update', function ( $real, $after, $before ) {
	return $real || has_term( 'live-blog', 'post_tag', $after );
}, 10, 3 );

// Name the reason. The script labels 'updated', 'tips', 'prices' and 'result'.
add_filter( 'ace_nudge_update_reason', function ( $reason, $after, $before ) {
	return get_post_meta( $after->ID, 'example_prices_changed', true ) ? 'prices' : $reason;
}, 10, 3 );

// Mark a result on a feed item. Items are keyed by post id.
add_filter( 'ace_nudge_feed_items', function ( $items, $since ) {
	foreach ( $items as $id => $item ) {
		$decided = (int) get_post_meta( $id, 'example_result_time', true );
		if ( $decided ) {
			$items[ $id ]['result'] = $decided;
			$items[ $id ]['reason'] = 'result';
		}
	}
	return $items;
}, 10, 2 );
```

## Sending product or update data elsewhere

`ace_nudge_updates_ready` fires hourly with what changed, before the grouped push goes out:

```php
add_action( 'ace_nudge_updates_ready', function ( array $updated ) {
	foreach ( $updated as $post_id => $reason ) {
		// $reason is 'updated', 'stock' or 'price'.
	}
} );
```

## A first-visit tour

A reader who follows nothing yet is shown a bubble from the bell, once. Add steps of your own:

```php
add_filter( 'ace_nudge_onboarding', function ( $steps ) {
	$steps[] = array(
		'id'     => 'settings',
		'label'  => 'Settings',
		'title'  => 'Make the site yours',
		'text'   => 'Dark mode, text size and more are in here.',
		'target' => '.site-header .settings-toggle',
		'icon'   => 'settings',
	);
	return $steps;
} );
```

`target` is `bell` or a selector, and `icon` is `bell` or `settings`. A step whose target is not on the page is skipped, and each step is shown once per browser.

## Local development

- Set `ACE_NUDGE_MODE` to `test`, add your address to `ACE_NUDGE_TEST_EMAILS`, and visit `?ace_nudge_preview=1`.
- Browsers allow service workers on `https` and on `localhost`.
- `wp ace-nudge preview` renders the newsletter. `wp cron event run ace_nudge_hourly` runs the grouped batch now.
- The script keeps its state in `localStorage` under keys starting `aceNudge.`. Clear them to start again as a new visitor.
- There is no build step. Edit `assets/notify.js` and reload; assets are versioned by file time.
