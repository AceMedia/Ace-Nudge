# Architecture

All PHP is in `includes/`, one class per file, loaded from `ace-nudge.php`. There is no build step and no bundled third-party code.

## Files and classes

| File | Class | Job |
|---|---|---|
| `ace-nudge.php` | (bootstrap) | Plugin header, `ACE_NUDGE_VERSION`, `ACE_NUDGE_DIR`, `ACE_NUDGE_URL`, `ace_nudge_mode()`, loads and boots the classes |
| `class-settings.php` | `Ace_Nudge_Settings` | Option `ace_nudge_settings`, defaults, brand, minimum age, followable taxonomies, extra site follows |
| `class-store.php` | `Ace_Nudge_Store` | Tables, consent wording and log, suppression check, HMAC tokens, withdrawal |
| `class-rest.php` | `Ace_Nudge_Rest` | Public POST routes under `ace-nudge/v1` |
| `class-front.php` | `Ace_Nudge_Front` | Service worker route, confirm and unsubscribe pages, script data, follow targets, the header bell's stand-in |
| `class-dispatch.php` | `Ace_Nudge_Dispatch` | Cron jobs, push and email delivery, newsletter rendering, `broadcast()` |
| `class-push.php` | `Ace_Nudge_Push` | Web Push: VAPID keys (option `ace_nudge_vapid`), payload encryption, the test-mode guard |
| `class-feed.php` | `Ace_Nudge_Feed` | Public cached feed behind the bell (`GET /feed`), the update log |
| `class-woo.php` | `Ace_Nudge_Woo` | WooCommerce order updates, email choices, product-update opt-in |
| `class-woo-email.php` | `Ace_Nudge_Product_Update_Email` | The back in stock and price drop email, a `WC_Email` |
| `class-privacy.php` | `Ace_Nudge_Privacy` | WordPress personal data exporter and eraser |
| `class-admin.php` | `Ace_Nudge_Admin` | Admin screen: overview, subscribers, consent log, settings |
| `class-blocks.php` | `Ace_Nudge_Blocks` | Follow, Alerts and Newsletter blocks |
| `class-cli.php` | `Ace_Nudge_CLI` | `wp ace-nudge` commands |

Front end:

- `assets/notify.js`: the dialog, follow buttons, bell, notification list, bubble, first-visit tour and `window.aceNudge.api`. Vanilla JavaScript, loaded deferred in the footer.
- `assets/notify.css`: styles, themed through custom properties.
- `assets/sw.js`: the service worker. It is served from the site root as `/?ace_nudge_sw=1` so that it can control the whole site.
- `assets/blocks.js`: editor registration for the three blocks.
- `templates/emails/`: the WooCommerce product-update email, HTML and plain text.

## What loads when

| Mode | Loaded |
|---|---|
| Any, including `off` | Table install check on `init`, blocks (which render nothing in `off`), privacy exporter and eraser, admin screen, WP-CLI commands |
| `test` and `on` | Front end assets and routes, REST routes, cron jobs, feed, WooCommerce integration |

`Ace_Nudge_Store::maybe_install()` runs on `init` and creates or updates the tables whenever option `ace_nudge_db` does not match the schema version. There is no activation hook.

## Cron

| Hook | Schedule | Work |
|---|---|---|
| `ace_nudge_minute` | Every minute (custom schedule `ace_nudge_minute`) | Game changes and kick-off reminders, bet slip reminders, and every fifth minute bet slip price checks |
| `ace_nudge_hourly` | Hourly | Tip alerts, then the grouped push batch for new posts and updates. Also clears reading history older than 30 days and lapsed follows, and fires `ace_nudge_updates_ready` |
| `ace_nudge_digest` | Daily, first run at 08:00 Europe/London | Newsletter, and removal of unconfirmed requests older than 7 days |

Events are scheduled on `init` when missing, in `test` and `on` modes only. The minute job needs a real system cron calling `wp-cron.php` every minute; see [Operations](operations.md).

## Request flow: following from a page

1. `Ace_Nudge_Front::targets()` works out what the page is about and passes it to the script as `aceNudge.targets`.
2. The script draws a follow button per target, in the Follow Button block or, with `auto_bar` on, after the page title.
3. The first click runs the age check (if a minimum age is set), then the consent switch, then the browser's permission prompt.
4. The browser registers the service worker, subscribes with the public VAPID key and posts the subscription to `/subscribe`. The answer is a token, kept in `localStorage`.
5. The follow is posted to `/follow` with the token. The server resolves the target itself, so a client cannot follow anything that is not followable.

## Request flow: newsletter sign-up

1. The browser posts to `/wp-json/ace-nudge/v1/subscribe` with `channel: email`.
2. A pending subscriber is created with a `digest` follow and any chosen category follows. A `requested` entry goes to the consent log.
3. A confirmation email carries `/?ace_nudge_confirm=TOKEN`. Opening it shows a button. Pressing the button POSTs, sets the subscriber `active` and logs `granted`.
4. The daily digest mails active subscribers who have a `digest` follow.

## Pages are cached

The page carries only what is true for everyone (which game and categories it is about). The script decides everything personal, so full-page caches stay valid. For the same reason the public routes cannot use a nonce: sign-up is rate limited, and everything after subscribing needs the subscription's own token.

The notification list works the same way. `GET /feed` gives one public answer for everyone. The browser keeps what it has seen and read in `localStorage` and works out its own unread list, so nothing about a reader's history is sent to the server unless they switch on "Updates to what I've read".
