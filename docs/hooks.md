# Hooks and the browser API

Anything site-specific comes through filters, so the plugin itself stays generic. Worked examples are in [the developer guide](developer-guide.md).

## Filters

### Settings, rules and wording

| Filter | Arguments | Purpose |
|---|---|---|
| `ace_nudge_defaults` | `array $defaults` | Site defaults for settings, used until a value is saved |
| `ace_nudge_min_age` | `int $age` | Minimum age. Overrides the setting |
| `ace_nudge_wording` | `array $words` | Consent wording shown and logged. Keys `push`, `orders`, `product_updates`, `reads`, `email` |
| `ace_nudge_brand` | `array $brand` | `name`, `primary`, `accent`, `page` (colours) and `logo` (URL), used in the panel and email |
| `ace_nudge_email_footer` | `string $html`, `string $link_style` | HTML placed in the newsletter footer, above the unsubscribe line |
| `ace_nudge_promo_pattern` | `string $regex` | A regular expression. Posts whose title matches are never notified, listed in the feed or put in the newsletter |

### What can be followed

| Filter | Arguments | Purpose |
|---|---|---|
| `ace_nudge_taxonomies` | `string[] $taxonomies` | Followable taxonomies. Starts as `category` and, with products enabled, `product_cat` |
| `ace_nudge_top_categories` | `array $categories` | The categories offered as switches and newsletter sections: up to 8 of `{ label, taxonomy, ids }` |
| `ace_nudge_follow_every_term` | `string[] $taxonomies` | Taxonomies that get a follow button per term on a post, rather than only the first |
| `ace_nudge_targets` | `array $targets` | The follow targets the current page offers |
| `ace_nudge_site_follows` | `array $follows` | Extra opt-in alerts from other plugins, as `id => label`. Sent with `Ace_Nudge_Dispatch::broadcast()` |

### Updates, feed and recommendations

Recommendations (`tips` in the identifiers) are attached to posts, each with a label and a price.

| Filter | Arguments | Purpose |
|---|---|---|
| `ace_nudge_is_real_update` | `bool $real`, `WP_Post $after`, `WP_Post $before` | Whether an edit is worth telling followers. Default: a new title or 200 characters of text added or removed |
| `ace_nudge_update_reason` | `string $reason`, `WP_Post $after`, `WP_Post $before` | Names a logged update in the feed. Default `updated`; the script also labels `tips`, `prices` and `result` |
| `ace_nudge_feed_items` | `array $items`, `string $since` | Items in the feed, keyed by post id. Add `result`, `tips` or whole items |
| `ace_nudge_post_tips` | `array $tips`, `int $post_id` | The recommendations attached to a post, each with `runner` (its label) and `price`. Without a callback no recommendation alerts are sent |
| `ace_nudge_tip_notice` | `string $notice` | Text appended to a recommendation push body, for example a site's required notice. Default empty |

### Selection list

A `slip` is a list of selections the visitor builds on the site (a saved list, basket or watch list) whose items have a current state and a price that can change. All of it is off unless the site supplies these filters.

| Filter | Arguments | Purpose |
|---|---|---|
| `ace_nudge_slip_config` | `array $config` | `storage` (the `localStorage` key holding the selection list) and `state` (a public endpoint giving each item's current state) |
| `ace_nudge_slip_title` | `string $title` | Title of selection list notifications. Default `Your selections` |
| `ace_nudge_slip_state` | `array $state`, `array $pairs` | The current state of the items in `$pairs` (`"market:sel" => [ market, sel ]`), read from the site's own cache. Without a callback no price alerts are sent |

### Placement

| Filter | Arguments | Purpose |
|---|---|---|
| `ace_nudge_bell_selector` | `string $selector` | The bell is inserted before this element. Empty means no bell |
| `ace_nudge_host_selector` | `string $selector` | The automatic follow bar is inserted after this element. Default: the page title |
| `ace_nudge_footer_selector` | `string $selector` | "Newsletter" and "Browser alerts" links are inserted after this element |
| `ace_nudge_bubble_quiet_selector` | `string $selector` | Pages matching this selector never show the notification bubble |
| `ace_nudge_hold_selector` | `string $selector` | A CSS selector matched against the `<html>` element. The notification bubble and its sound wait while it matches (12 seconds at most), for example while a page intro plays. Default empty |
| `ace_nudge_onboarding` | `array $steps` | Steps in the first-visit tour, each `{ id, label, title, text, target, icon }` |
| `ace_nudge_manifest_url` | `string $url` | Web app manifest, printed as `<link rel="manifest">`. Needed for notifications on iPhone and iPad |

## Action

`ace_nudge_updates_ready` fires hourly with the queue of updates about to be batched: `array $updated`, as `post_id => reason`, where reason is `updated`, `stock` or `price`.

## PHP functions and methods for other plugins

| Call | Purpose |
|---|---|
| `ace_nudge_mode()` | `off`, `test` or `on` |
| `Ace_Nudge_Dispatch::broadcast( string $id, array $message )` | Push `$message` (`title`, `body`, `url`, `tag`) to everyone who switched on the site follow `$id`. Returns `false` during quiet hours so the caller can try later |
| `Ace_Nudge_Dispatch::deliver( object $sub, array $message, string $urgency = 'normal' )` | Send one push, count it, and withdraw the subscriber if the push service says it has gone |
| `Ace_Nudge_Dispatch::opened( string $url, int $post_id )` | Adds `ref=nudge-ID` so opening the push marks it read |
| `Ace_Nudge_Store::is_suppressed( string $email )` | Whether an address is on the suppression list |
| `Ace_Nudge_Settings::get( string $key )` | One setting |

## Constants

| Constant | Purpose |
|---|---|
| `ACE_NUDGE_MODE` | `off` (default), `test` or `on` |
| `ACE_NUDGE_TEST_EMAILS` | Array of addresses that may receive the newsletter and confirmation outside `on` mode |
| `ACE_NUDGE_TEST_PUSH` | Array of subscriber ids that may receive push in `test` mode |
| `ACE_NUDGE_SHOW_ALL` | In `test` mode, shows the front end to everyone, and keeps past live events followable |
| `ACE_NUDGE_VERSION`, `ACE_NUDGE_DIR`, `ACE_NUDGE_URL` | Defined by the plugin |

## Browser API: `window.aceNudge.api`

### Follows

| Method | Purpose |
|---|---|
| `follow(target)` | Follow through the usual flow: age check, permission, then the follow. `days` on a target makes it lapse |
| `unfollow(entry)`, `unfollow(type, id)`, `unfollow(key)` | Remove a follow |
| `following(key)` | Whether a key such as `category:12` is followed |
| `follows()` | Everything this browser follows, each `{ key, keys, type, label }` |
| `status()` | `{ available, ready, push, subscribed, notifications, scope, newsletter, count }` |
| `on(fn)` | Calls `fn` with the follow keys after every change. Returns a function that removes the listener |
| `open(section)` | Opens *NUDGE*'s own steps: `manage` (the default), `push`, `interests` or `newsletter` |
| `manage()` | Opens *NUDGE*'s own manage dialog |

### Notifications

| Method | Purpose |
|---|---|
| `notifications()` | `{ items, unread, loaded }`, unread first |
| `unread()` | The unread count |
| `markRead(id)`, `markAllRead()`, `dismiss(id)` | Change the list. Kept in this browser only |
| `sound()`, `setSound(on)` | The notification sound. Switching it on plays it once |
| `recommending()`, `setRecommending(on)` | Whether recommendations are drawn from what this browser reads. Switching it off forgets what was learnt |
| `hasSlip()` | Whether the site configured a selection list |

### For a settings panel

`info()`, `pushBlocker()` (`ios`, `unsupported`, `denied` or `null`), `ageNeeded()`, `confirmAge(year)`, `pushOn()`, `pushOff()`, `categories()`, `site(which)`, `setSite(which, on)`, `setCategory(label, on)` and `newsletter({ email, frequency, categories, website })` let a site's own settings panel run every alert control, with *NUDGE*'s rules.

### Events

Events fire on `document`:

- `ace-nudge:change` with `{ status, follows }` after every change to what is followed.
- `ace-nudge:notifications` with `{ items, unread }` after every change to the list.

Where *NUDGE* stays quiet (test mode without a preview, or a failed age check) `api.available` is `false`, `api.reason` and `status().reason` say why (`test` or `age`), and the read-only methods still answer with empty values.

### Settings panel integration

When the page has a `window.aceSettings` object with an `open(tab, from)` method and an element with id `ace-accessibility-panel`, the bell, the manage links, `?ace_nudge_manage=1` and a follow button that is already following open that panel's `alerts` tab (or `alerts-notifications` when there are notifications) instead of *NUDGE*'s own dialog. The first follow from a page still runs *NUDGE*'s own opt-in steps.

## CSS custom properties

Set by the plugin from `ace_nudge_brand`: `--ace-nudge-primary`, `--ace-nudge-accent`, `--ace-nudge-page`.

A site may set: `--ace-nudge-bubble-bg`, `--ace-nudge-bubble-fg`, `--ace-nudge-bubble-line`, `--ace-nudge-bubble-blur`, `--ace-nudge-bubble-arrow-bg`, `--ace-nudge-bubble-timer`, `--ace-nudge-bubble-chip-bg`, `--ace-nudge-flag-bg`, `--ace-nudge-flag-fg`, `--ace-nudge-flag-line`. A follow target's `colour` and `contrast` set `--ace-nudge-follow` and `--ace-nudge-follow-contrast` on its button.
