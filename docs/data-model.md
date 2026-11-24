# Data model

Four tables, named with the WordPress table prefix followed by `ace_nudge_`. The schema version is stored in option `ace_nudge_db` (currently `1`). Times are UTC.

## subscribers

| Column | Type | Notes |
|---|---|---|
| `id` | bigint unsigned | Primary key |
| `channel` | varchar(10) | `push` or `email` |
| `status` | varchar(10) | `pending` or `active`. Push rows are always `active` |
| `email` | varchar(190), null | Email channel only |
| `endpoint` | text, null | Push channel only: the push service URL |
| `endpoint_hash` | char(64) | SHA-256 of the endpoint, or of the lowercased, trimmed address. Unique, so one row per address or browser |
| `p256dh` | varchar(120), null | Push channel only: the browser's public key |
| `auth` | varchar(60), null | Push channel only: the browser's auth secret |
| `created_at` | datetime | |
| `last_sent_at` | datetime, null | Last grouped push or newsletter |
| `sent_day` | char(10), null | The UTC day `sent_count` belongs to |
| `sent_count` | smallint unsigned | Grouped pushes sent on `sent_day`, for the daily cap |

Keys: primary `id`, unique `endpoint_hash`, index `channel_status (channel, status)`.

## follows

| Column | Type | Notes |
|---|---|---|
| `id` | bigint unsigned | Primary key |
| `subscriber_id` | bigint unsigned | |
| `type` | varchar(10) | See below |
| `object_id` | varchar(190) | Text, because some ids are not numbers |
| `meta` | text, null | JSON |
| `created_at` | datetime | |

Keys: primary `id`, unique `one_follow (subscriber_id, type, object_id)`, index `type_object (type, object_id)`.

| Type | `object_id` | Meaning |
|---|---|---|
| `post` | Post id | One story, product or other enabled post |
| `category` | Term id | A term in any followable taxonomy |
| `ptype` | Post type name | Everything new in a post type |
| `site` | `all`, `reads`, `slipprices`, `slipremind`, `tips`, or an id from `ace_nudge_site_follows` | Whole-site and opt-in switches |
| `read` | Post id | A post this browser opened, kept only while `site:reads` is on, for 30 days |
| `fixture` | Fixture id | A scheduled live event the visitor can follow, with a start time, states and a score. `meta` holds its label, post, url, start time and the groups it belongs to. Removed when the event ends |
| `digest` | `daily` or `weekly` | Newsletter frequency |
| `order` | Order id | Push updates for one WooCommerce order. Removed when the order is completed, refunded or cancelled |
| `customer` | User id | Push updates for all of a signed-in customer's orders |
| `wc` | `product_updates` | Back in stock and price drop emails for products bought |
| `slip` | `slip` | A summary, in `meta`, of the visitor's selection list: a list of selections the visitor builds on the site (a saved list, basket or watch list) whose items have a current state and a price that can change. One per subscriber |

A follow can lapse after a set time through `meta.until` (a Unix time), cleared hourly by `Ace_Nudge_Dispatch::purge_lapsed()`. A subscriber may hold at most 30 follows, not counting `read` and `slip`.

## consent_log

| Column | Type | Notes |
|---|---|---|
| `id` | bigint unsigned | Primary key |
| `subject_hash` | char(64) | The subscriber's `endpoint_hash`. Never an address or endpoint |
| `channel` | varchar(10) | `push` or `email` |
| `action` | varchar(20) | See below |
| `wording_version` | varchar(30) | `Ace_Nudge_Store::WORDING_VERSION` at the time |
| `wording` | text | The exact text shown |
| `age_confirmed` | tinyint | 1 when the age check had been passed |
| `created_at` | datetime | |

Actions: `requested` (newsletter asked for), `granted` (push subscribed, or newsletter confirmed), `withdrawn`, `erased` (personal data eraser), `suppressed`, `expired` (the push service reported the subscription gone), `removed_by_admin`, `orders_on`, `reads_on`, `reads_off`, `product_updates_on`, `product_updates_off`.

The log stores only a hash, so it survives erasure as evidence.

## suppression

| Column | Type | Notes |
|---|---|---|
| `email_hash` | char(64) | Primary key. SHA-256 of the lowercased, trimmed address |
| `reason` | varchar(30) | `import` for entries added by WP-CLI |
| `created_at` | datetime | |

A suppressed address is never signed up. `wp ace-nudge suppress` withdraws a matching subscriber at once, and the digest withdraws any it meets.

## Tokens

`ID.HMAC`, where the HMAC is SHA-256 over `ace-notify|ID` keyed with `wp_salt( 'auth' )`, cut to 40 characters. The salt text predates the plugin's rename and is kept so existing subscriptions stay valid. Nothing is stored for a token. Rotating the WordPress auth salt invalidates every browser token and every link already emailed.

## Options, transients and meta

| Name | Kind | Holds |
|---|---|---|
| `ace_nudge_settings` | Option | Settings, described on the Configuration page |
| `ace_nudge_db` | Option | Schema version |
| `ace_nudge_vapid` | Option | VAPID private key (PEM) and public key |
| `ace_nudge_sent` | Option | Daily send totals per channel, 30 days |
| `ace_nudge_updated` | Option | Queue of updated posts and products for the next hourly batch |
| `ace_nudge_batch_since`, `ace_nudge_tips_since` | Options | When the hourly jobs last ran |
| `ace_nudge_fixture_state` | Option | Last seen state of each followed live event |
| `ace_nudge_update_log` | Option | Real updates from the last 5 days, for the feed (300 at most) |
| `ace_nudge_feed_v1` | Transient | The built feed, for one minute |
| `ace_nudge_rl_*` | Transients | Rate limit counters, one hour |
| `_ace_nudge_email_off` | User meta | Optional WooCommerce emails a customer switched off |

## Upgrading from the prototype

The prototype was called `ace_notify`. On install, tables named `ace_notify_*` are renamed, the options `ace_notify_vapid`, `ace_notify_fixture_state`, `ace_notify_batch_since` and `ace_notify_updated` are carried over, and the old cron hooks are cleared.
