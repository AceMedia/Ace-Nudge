# Developer guide

NUDGE keeps site rules out of the plugin. A site, theme or companion plugin shapes it through filters, one action, a few static methods and a browser API. The full lists are in [Hooks and the browser API](hooks.md). The examples here were checked against the 0.7.2 source.

Guard calls into NUDGE so your code keeps working without it:

```php
if ( function_exists( 'ace_nudge_mode' ) && 'off' !== ace_nudge_mode() ) {
	// NUDGE is running.
}
```

## Rules for a regulated site

```php
// A minimum age, which the settings screen cannot lower.
add_filter( 'ace_nudge_min_age', fn() => 18 );

// Never notify about promotional posts.
add_filter( 'ace_nudge_promo_pattern', fn() => '/\b(free bet|bonus|promo code)\b/i' );

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
	$taxonomies[] = 'team';
	return $taxonomies;
} );
add_filter( 'ace_nudge_follow_every_term', fn( $taxonomies ) => array_merge( $taxonomies, array( 'team' ) ) );
```

Terms in extra taxonomies are followed as type `category` with the term id, and are listed by name in the manage panel.

## Shaping the follow buttons

`ace_nudge_targets` receives the targets for the current page. Each is an array with `type`, `key` (`type:id`), `label`, and `id` or `post_id`. A target may also carry presentation keys the script understands:

| Key | Effect |
|---|---|
| `colour` | Border and label colour. Pale colours are darkened for the label so it stays readable on white |
| `contrast` | Text colour on the filled, following state |
| `icon` | Image URL shown at 22 by 22 pixels |
| `side` | `home`, `centre` or `away`: orders buttons in one row, for a versus page |
| `days` | The follow lapses after this many days (30 at most) |

```php
add_filter( 'ace_nudge_targets', function ( $targets ) {
	foreach ( $targets as &$target ) {
		if ( 'category' === $target['type'] ) {
			$colour = get_term_meta( (int) $target['id'], 'team_colour', true );
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
	$follows['round-updates'] = 'Round updates';
	return $follows;
} );

// 2. Send it. broadcast() returns false during quiet hours, so try again later.
function example_send_round_update() {
	if ( ! class_exists( 'Ace_Nudge_Dispatch' ) ) {
		return;
	}
	$sent = Ace_Nudge_Dispatch::broadcast( 'round-updates', array(
		'title' => 'Round 3 is open',
		'body'  => 'Make your picks before Saturday.',
		'url'   => home_url( '/predictor/' ),
		'tag'   => 'round-updates',
	) );
	if ( ! $sent ) {
		wp_schedule_single_event( time() + HOUR_IN_SECONDS, 'example_send_round_update' );
	}
}
add_action( 'example_send_round_update', 'example_send_round_update' );
```

Each reader's daily cap applies. NUDGE's own dialog does not list these switches, so offer the switch in your own interface with the browser API:

```js
const api = window.aceNudge && window.aceNudge.api;
if ( api && api.available && api.status().notifications ) {
	checkbox.checked = api.site( 'round-updates' );
	checkbox.addEventListener( 'change', () => api.setSite( 'round-updates', checkbox.checked ) );
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
window.aceNudge.api.follow( { type: 'category', id: 12, key: 'category:12', label: 'Cycling', days: 7 } );
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
		$settled = (int) get_post_meta( $id, 'example_result_time', true );
		if ( $settled ) {
			$items[ $id ]['result'] = $settled;
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

## Games and fixtures

Game follows are an optional integration with a companion fixtures plugin, and stay inactive without one. NUDGE itself makes no outbound calls for scores: it reads a snapshot the fixtures plugin already cached. The integration expects:

| Expected | Used for |
|---|---|
| Class `Ace_Sports_Feed` with a static `normalise_league_fixture_id( string )` | Turning a post's stored id into a fixture id of the form `league:SLUG-YYYYMMDD` |
| Class `ACE_Teams_Events_Plugin` with a constant `EVENT_TAX` | The taxonomy whose term slugs name the competitions ("hubs") a post belongs to |
| Post meta `ace_event_tournament_fixture_id` | The fixture a post covers |
| Post meta `ace_event_primary_kickoff_at` or `ace_event_kickoff_at` | Kick-off, as a Unix time |
| Transient `ace_sf_live_HUB` (and `ace_sf_live_HUB_stale`) | An array keyed by fixture id, each item with `state` (`pre`, `in` or `post`), `home`, `away`, `home_score`, `away_score`, `title` and `kickoff` |

A post that covers a game then offers a "follow this game" button. Followers get a reminder at 60, 30 and 5 minutes before kick-off, then kick-off, each score change and full time, after which the follow ends. Following a team from a game's page also follows that game. A game can be followed until the day after its date.

## A bet slip

For sites where readers build a bet slip in the browser. NUDGE never places anything and never includes potential returns in a notification. All of it is off unless the site supplies the pieces below, and the reader-facing alerts are opt-in.

### In the browser

```php
add_filter( 'ace_nudge_slip_config', fn() => array(
	'storage' => 'exampleSlip',                        // localStorage key holding { picks: [ ... ] }
	'state'   => rest_url( 'example/v1/slip-state' ),  // public, cacheable endpoint
) );
```

The script reads the picks from `window.aceSlip.picks()` when that exists, otherwise from the `storage` key. Each pick may have `market_id`, `selection_id`, `runner`, `event`, `dec` (decimal price), `sport`, `added` (milliseconds), `source` (`{ url, post_id }`) and `example` (ignored when true).

It asks the `state` endpoint for `?s=MARKET:SELECTION,MARKET:SELECTION` and expects:

```json
{ "picks": { "1.234:5678": { "state": "open", "dec": 3.5, "frac": "5/2", "inplay": false, "result": "" } } }
```

`state` is `open`, `suspended`, `closed` or `settled`, and `result` is `won`, `placed`, `lost` or `removed`. The notification list then shows results, markets closing, events starting, price moves of 1 per cent or more, and updates to the article a pick came from.

### On the server

For push alerts, the site's slip script sends a summary to `POST /slip` whenever the slip changes, and `POST /slip_done` when it is placed or cleared (parameters in [REST API](rest-api.md)). The token is the browser's NUDGE token, in `localStorage` under `aceNudge.token`.

- Reminder (`site:slipremind`): one push, 15 minutes before the first event.
- Price alerts (`site:slipprices`): every 5 minutes NUDGE asks the site for current state through `ace_nudge_slip_state` and tells each change once: a move of 4 per cent or more, a market suspended or closed, or a result.

```php
add_filter( 'ace_nudge_slip_state', function ( $state, $pairs ) {
	foreach ( $pairs as $key => $pair ) {
		[ $market, $selection ] = $pair;
		$cached = example_cached_price( $market, $selection ); // Your own cache. No outbound calls here.
		if ( $cached ) {
			$state[ $key ] = array( 'state' => 'open', 'dec' => $cached['dec'], 'frac' => $cached['frac'], 'result' => '' );
		}
	}
	return $state;
}, 10, 2 );
```

Market ids must look like `1.234567` and selection ids must be digits, or they are dropped.

### Tips

```php
// For the hourly "New tip" push (site:tips). Each tip needs 'runner' and 'price'.
add_filter( 'ace_nudge_post_tips', function ( $tips, $post_id ) {
	return (array) get_post_meta( $post_id, 'example_tips', true );
}, 10, 2 );

// For "Recommended" items in the notification list, add the tips to the feed too.
add_filter( 'ace_nudge_feed_items', function ( $items ) {
	foreach ( $items as $id => $item ) {
		$tips = (array) get_post_meta( $id, 'example_tips', true );
		if ( $tips ) {
			$items[ $id ]['tips'] = $tips; // Each: runner, price, dec, sport.
		}
	}
	return $items;
} );
```

The tip push ends with a fixed responsible gambling line and is only sent to browsers that passed the age check to subscribe.

## Local development

- Set `ACE_NUDGE_MODE` to `test`, add your address to `ACE_NUDGE_TEST_EMAILS`, and visit `?ace_nudge_preview=1`.
- Browsers allow service workers on `https` and on `localhost`.
- `wp ace-nudge preview` renders the newsletter. `wp cron event run ace_nudge_hourly` runs the grouped batch now.
- The script keeps its state in `localStorage` under keys starting `aceNudge.`. Clear them to start again as a new visitor.
- There is no build step. Edit `assets/notify.js` and reload; assets are versioned by file time.
