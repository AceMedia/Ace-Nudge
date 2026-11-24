# REST API

Namespace `ace-nudge/v1`. Routes exist only in `test` and `on` modes. Every route is public (`permission_callback` is `__return_true`), because cached pages cannot carry a nonce. Instead:

- `/subscribe` is rate limited to 20 calls an hour per visitor address, and `/slip` to 240.
- Every other mutating route needs the subscription's own `token`, returned by `/subscribe`.

Bodies are JSON. Errors are standard `WP_Error` responses with a `message`.

## Routes

| Route | Method | Purpose |
|---|---|---|
| `/subscribe` | POST | Subscribe a browser to push, or request the newsletter |
| `/follow` | POST | Follow a target |
| `/unfollow` | POST | Remove a follow |
| `/state` | POST | What this subscription follows |
| `/withdraw` | POST | Delete the subscription and its follows |
| `/read` | POST | Record that a post was opened, for readers who asked for updates to what they read |
| `/orders` | POST | Tie this browser to a WooCommerce order or customer |
| `/slip` | POST | Store a summary of the browser's bet slip |
| `/slip_done` | POST | Forget the slip |
| `/feed` | GET | Public, cached feed of the last 5 days' new posts and real updates |

## POST /subscribe

Common parameters: `channel` (`email`, anything else means push), `consent` (must be `true`), `age_confirmed` (must be `true` when a minimum age is set), `website` (a honeypot, must be empty).

Push adds:

| Parameter | Notes |
|---|---|
| `endpoint` | Must be `https` on a known push service host: `googleapis.com`, `mozilla.com`, `windows.com` or `push.apple.com` |
| `p256dh` | Base64url, 65 bytes decoded |
| `auth` | Base64url, 16 bytes decoded |
| `purpose` | `orders` for an order-updates-only subscription. This skips the age check and logs `orders_on` instead of `granted` |

Returns `{ "token": "ID.HMAC" }`. Subscribing the same endpoint again returns the same subscription.

Email adds:

| Parameter | Notes |
|---|---|
| `email` | The address |
| `frequency` | `weekly`, otherwise `daily` |
| `categories` | Term ids, 30 at most, each in a followable taxonomy |

Returns `{ "pending": true }` whether the address is new, already active, suppressed or not allowed in the current mode, so addresses cannot be probed. An invalid address returns a 400. Missing consent or age confirmation returns a 400. Over the rate limit returns a 429.

## POST /follow

| Parameter | Notes |
|---|---|
| `token` | Required |
| `type` | `category`, `ptype`, `site`, `post` or `fixture` |
| `id` | Term id for `category`, post type name for `ptype`, switch id for `site` |
| `post_id` | For `post` and `fixture`. With `category`, also follows the game that post covers, if any |
| `days` | Optional. The follow lapses after this many days, 30 at most |

The server resolves the target: the term must be in a followable taxonomy, the post type enabled, the post published, the site switch known. A subscriber may hold 30 follows. Returns the same as `/state`.

## POST /unfollow

`token`, and either `key` (`type:object_id`, as listed by `/state`) or the same `type`, `id` and `post_id` as `/follow`. Unfollowing `site:reads` also deletes the stored reading history. Returns the same as `/state`.

## POST /state

`token`. Returns:

```json
{
  "subscribed": true,
  "follows": ["site:all", "category:12", "post:345"],
  "items": [
    { "key": "post:345", "type": "post", "post_id": 345, "label": "A story title" }
  ]
}
```

`follows` lists every follow except `read` and `slip`. `items` names the ones the manage panel shows as rows: games, posts, post types, orders, the customer follow, and terms outside the standard category taxonomies. An unknown token returns `{ "subscribed": false, "follows": [] }`.

## POST /withdraw

`token`. Deletes the subscriber and all follows, logs `withdrawn`, returns `{ "subscribed": false }`.

## POST /read

`token`, `post_id`. Stores a `read` follow for a published post, only when the subscriber has `site:reads` on. Always returns `{ "ok": true }`.

## POST /orders

`token`, and either `order_id` with `order_key` (a guest proves the order with its key), or a signed-in request (cookie plus `X-WP-Nonce`), which follows all of that customer's orders. Returns the same as `/state`, or a 403 when neither applies. Needs WooCommerce.

## POST /slip and /slip_done

For sites with a bet slip. The site's own slip script calls these; see [the developer guide](developer-guide.md).

| Parameter | Notes |
|---|---|
| `token` | A push subscription |
| `count` | Number of picks. Below 1 forgets the slip |
| `type` | Bet type label, such as a double |
| `returns` | Stored, never sent in a notification |
| `url` | Where a reminder should lead |
| `picks` | Up to 20 of `{ runner, event, start, market, sel, dec }`. `start` is a Unix time, `market` and `sel` identify the price for price alerts, `dec` is the decimal price on the slip |

`/slip` returns `{ "ok": true, "slip": true }`. `/slip_done` takes `token` and returns `{ "ok": true }`.

## GET /feed

No parameters. Cached for one minute on the server and sent with `Cache-Control: public, max-age=60`.

```json
{
  "days": 5,
  "now": 1790000000,
  "items": [
    {
      "id": 345, "url": "https://example.com/a-story/", "title": "A story title",
      "thumb": "https://example.com/thumb.jpg", "type": "post", "terms": [12, 40],
      "fixture": "", "published": 1789990000, "modified": 1789995000,
      "updated": 1789995000, "reason": "updated"
    }
  ]
}
```

`reason` is `new` until a real update is logged, then `updated` or whatever `ace_nudge_update_reason` returns. `soft: 1` marks an edit found only by its modified time, which counts as a change for readers who already saw the post but never as news. Sites may add `result`, `tips` and further items through `ace_nudge_feed_items`.

## Front-end query parameters

| Address | Effect |
|---|---|
| `/?ace_nudge_sw=1` | Serves the service worker |
| `/?ace_nudge_confirm=TOKEN` | Shows a button. A POST activates the newsletter subscription |
| `/?ace_nudge_unsub=TOKEN` | Shows a button. A POST withdraws it. Mail clients' one-click unsubscribe POSTs here |
| `?ace_nudge_preview=1` | In `test` mode, switches NUDGE on for this browser |
| `?ace_nudge_manage=1` | Opens the manage panel. The notification's "Stop alerts" action leads here |
| `?ref=nudge-ID` | Added to push links. Marks that notification read, then is removed from the address |
