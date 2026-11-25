# Configuration

Configuration comes from three places: constants in `wp-config.php`, the settings screen, and filters in site code. Filters are listed in [Hooks and the browser API](hooks.md).

## Constants

```php
define( 'ACE_NUDGE_MODE', 'test' );
define( 'ACE_NUDGE_TEST_EMAILS', array( 'me@example.com' ) );
define( 'ACE_NUDGE_TEST_PUSH', array( 12 ) );
define( 'ACE_NUDGE_SHOW_ALL', false );
```

| Constant | Default | Purpose |
|---|---|---|
| `ACE_NUDGE_MODE` | `off` | `off`, `test` or `on`. Any other value means `off` |
| `ACE_NUDGE_TEST_EMAILS` | none | Addresses that may sign up for and receive the newsletter outside `on` mode. Compared without regard to case |
| `ACE_NUDGE_TEST_PUSH` | none | Subscriber ids (from the Subscribers tab) that may receive push in `test` mode |
| `ACE_NUDGE_REMOVE_DATA` | `false` | When defined as true, deleting the plugin also drops the subscriber, follow, consent log and suppression tables. Leave it off if the consent log may be needed as evidence |
| `ACE_NUDGE_SHOW_ALL` | `false` | In `test` mode, shows the front end to every visitor without the preview address, and keeps past live events followable. Sending stays limited to the test lists |

### Modes

| Mode | Front end | REST and cron | Email | Push |
|---|---|---|---|---|
| `off` | Nothing printed. Blocks render nothing | Not registered | None | None |
| `test` | Only for a browser that visited `?ace_nudge_preview=1`, or everyone with `ACE_NUDGE_SHOW_ALL` | Running | Test addresses only | Test subscriber ids only |
| `on` | Everyone | Running | Everyone subscribed | Everyone subscribed |

The admin screens, WP-CLI commands and the personal data exporter and eraser work in every mode.

## Settings screen

**Ace NUDGE** in the admin menu, Settings tab. It needs the `manage_options` capability. Values are stored in the option `ace_nudge_settings`.

| Key | Screen label | Default | Range | Purpose |
|---|---|---|---|---|
| `post_types` | Notify about new | `post` | Any public post type except attachments | Post types that can be followed, appear in grouped alerts, the feed and the newsletter |
| `daily_cap` | Most grouped alerts a day, per browser | 3 | 1 to 24 | Live event alerts and order updates are not counted |
| `quiet_start` | Quiet hours, from | 21 | 0 to 23 | Grouped alerts wait until quiet hours end |
| `quiet_end` | Quiet hours, to | 8 | 0 to 23 | Set both the same to turn quiet hours off |
| `min_age` | Age limit | 0 | 0 to 99 | Asks for a year of birth before following or signing up. 0 means no check. The `ace_nudge_min_age` filter takes priority, and the screen says so when it does |
| `newsletter` | Offer the newsletter sign-up | On | | Switch off if the site has its own newsletter |
| `auto_bar` | Add follow buttons to pages automatically | On | | A page with the Follow Button block always uses the block instead |
| `order_statuses` | Order updates on devices | `processing`, `completed`, `refunded`, `cancelled` | WooCommerce order statuses | Statuses that send a push. Shown with WooCommerce only |
| `wc_optional_emails` | Emails customers can switch off | `customer_note` | Non-essential customer emails | Shown with WooCommerce only |

Quiet hours are evaluated in the site's time zone (Settings, General).

Site code can change the defaults, which apply until the settings are first saved:

```php
add_filter( 'ace_nudge_defaults', function ( $defaults ) {
	$defaults['daily_cap']  = 2;
	$defaults['newsletter'] = 0;
	return $defaults;
} );
```

## What follows from the post types

- `post` enabled: the `category` taxonomy can be followed, stories can be followed for real updates, and scheduled live events (follow type `fixture`) can be followed where a companion plugin supplies them.
- `product` enabled (WooCommerce): `product_cat` can be followed, and products can be followed for back in stock and price drops.
- Any other public post type: single items and the whole type ("all events", say) can be followed.

Further taxonomies come through `ace_nudge_taxonomies`.

## Admin screens

| Tab | Shows |
|---|---|
| Overview | Totals: browser subscribers, newsletter confirmed and unconfirmed, withdrawals and sends over 30 days, what people follow by type, and the top categories, live events, stories and newsletter frequencies |
| Subscribers | 50 per page. Channel, a part-hidden address or the push service's host, status, follow counts, dates, and a Remove link |
| Consent log | The latest 100 entries with the wording shown |
| Settings | The table above |

The settings form uses a shared save bar script when a companion plugin has registered one under the handle `ace-savebar`, and an ordinary Save button otherwise.

## Blocks

| Block | Name | Attributes | Output |
|---|---|---|---|
| Follow Button | `ace-nudge/follow` | none | A follow button for each thing the current page is about |
| Alerts Button | `ace-nudge/alerts-button` | `label` (default "Get alerts") | Opens the alerts panel |
| Newsletter Button | `ace-nudge/newsletter-button` | `label` (default "Get the newsletter") | Opens the newsletter sign-up |

All three are in the Widgets category, support left, centre and right alignment and margin, and are rendered on the server so they work on cached pages. They render nothing in `off` mode.

## Branding and placement

```php
add_filter( 'ace_nudge_brand', function ( $brand ) {
	return array(
		'name'    => 'Example News',
		'primary' => '#14532d',
		'accent'  => '#22c55e',
		'page'    => '#f1f5f9',
		'logo'    => 'https://example.com/logo-white.png',
	) + $brand;
} );

// Put the bell before the header's search button, and the footer links after the footer menu.
add_filter( 'ace_nudge_bell_selector', fn() => '.site-header .search-toggle' );
add_filter( 'ace_nudge_footer_selector', fn() => '.site-footer nav' );
```

Without a bell selector there is no bell, and so no notification list or bubble. The follow buttons, blocks and `?ace_nudge_manage=1` still work.

The notification bubble is white with dark text unless the site's stylesheet sets these custom properties:

| Custom property | Sets |
|---|---|
| `--ace-nudge-bubble-bg` | Background |
| `--ace-nudge-bubble-fg` | Text colour |
| `--ace-nudge-bubble-line` | Border colour |
| `--ace-nudge-bubble-shadow` | Box shadow |

```css
:root {
	--ace-nudge-bubble-bg: #0f172a;
	--ace-nudge-bubble-fg: #f8fafc;
	--ace-nudge-bubble-line: #334155;
	--ace-nudge-bubble-shadow: 0 8px 24px -8px rgba(0, 0, 0, .6);
}
```

The count on the bell can be restyled with the site's own CSS rule on `.ace-nudge-bell .ace-nudge-bell__count`:

```css
.ace-nudge-bell .ace-nudge-bell__count { background: #b91c1c; color: #ffffff; }
```

To make the bubble and its sound wait, for example while a page intro plays, see `ace_nudge_hold_selector` in [Hooks and the browser API](hooks.md).
