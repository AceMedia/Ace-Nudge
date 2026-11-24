# Push notifications

NUDGE sends Web Push itself: VAPID (RFC 8292) for identification and `aes128gcm` payload encryption (RFC 8291), written against PHP's OpenSSL functions. There is no third-party push service or library.

## Requirements

- HTTPS. Browsers only allow service workers and push on secure origins.
- PHP with the OpenSSL extension, including elliptic curve support for `prime256v1`.
- The site served from the root of its domain. The service worker is registered with scope `/`.
- Outbound HTTPS from the server to the browsers' push services.

## VAPID keys

There is nothing to set up. The first time the front end loads in `test` or `on` mode, a P-256 key pair is generated and stored in the option `ace_nudge_vapid` (`private` as PEM, `public` as base64url). The public key is handed to the script, and the JWT's `sub` is `mailto:` plus the site's admin email.

Keep that option. Browsers subscribe against the public key, so replacing it orphans every existing subscription. When moving a site, move the option with the database.

## Subscribing

1. A visitor follows something, or presses the bell or an Alerts Button.
2. If a minimum age is set, they are asked for a year of birth.
3. They switch on the consent toggle, which starts off, and press Continue.
4. The browser shows its own permission prompt.
5. The script registers `/?ace_nudge_sw=1`, subscribes, and posts the endpoint and keys to `/subscribe`.

The server accepts endpoints only on known push service hosts (`googleapis.com`, `mozilla.com`, `windows.com`, `push.apple.com`), so the route cannot be used to make the server call arbitrary addresses.

## iPhone and iPad

Safari on iOS only allows notifications for a site added to the Home Screen. Give NUDGE a web app manifest:

```php
add_filter( 'ace_nudge_manifest_url', fn() => home_url( '/manifest.webmanifest' ) );
```

In a normal Safari tab, NUDGE explains how to add the site to the Home Screen instead of asking for permission.

## What is sent

Every push is a small JSON message: `title`, `body`, `url`, `tag`. The service worker shows it with one action, "Stop alerts", which opens `/?ace_nudge_manage=1`. Opening the notification opens `url`.

| Alert | When | Cap and quiet hours |
|---|---|---|
| Grouped alert | Hourly: new posts in what a browser follows, and real updates to followed or read posts. One message per person per run | Apply |
| Product alert | Hourly: a followed product is back in stock or drops in price | Apply |
| Tip alert | Hourly, for `site:tips`, on sites that supply tips | Apply |
| Extra site alert | When another plugin calls `Ace_Nudge_Dispatch::broadcast()` | Apply |
| Bet slip reminder | Once, 15 minutes before the first event on the slip, for `site:slipremind` | Apply |
| Bet slip price alert | Every 5 minutes, for `site:slipprices`, on sites that supply prices | Apply |
| Game alert | Each minute: reminders at 60, 30 and 5 minutes, kick-off, score changes, full time | Skipped |
| Order update | When a WooCommerce order changes status | Skipped |

Game alerts and order updates are sent with high urgency and a 10 minute lifetime at the push service. Everything else is normal urgency with a 6 hour lifetime.

A push service answering 404 or 410 means the subscription has gone, and the subscriber is withdrawn with an `expired` log entry.

## The bell, the list and the bubble

Where a site sets `ace_nudge_bell_selector`, a bell button is placed before that element. It shows an unread count, worked out in the browser from the public feed and what the browser follows. A small bubble under the bell names the newest unread item once, with a bar showing its time left and an optional sound that readers can switch off. A reader who follows nothing yet is shown a short tour once, starting at the bell.

The tab in view checks the feed once a minute, so new items arrive without a refresh. Background tabs do nothing; push covers those.

## Testing

In `test` mode push only goes to the subscriber ids in `ACE_NUDGE_TEST_PUSH`. Subscribe your own browser after visiting `?ace_nudge_preview=1`, find its id on the Subscribers tab, list it, then run:

```
wp ace-nudge test_push
```

Each line shows the subscriber id and the push service's HTTP status. `201` is success. `0` means the mode did not allow it or the request failed.
