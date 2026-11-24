# Architecture

All code is in `includes/`, one class per file, loaded from `ace-nudge.php`.

| File | Class | Job |
|---|---|---|
| `class-settings.php` | `Ace_Nudge_Settings` | Settings option `ace_nudge_settings`, defaults, brand, minimum age, followable taxonomies |
| `class-store.php` | `Ace_Nudge_Store` | Tables, consent log, suppression, HMAC tokens, withdrawal |
| `class-rest.php` | `Ace_Nudge_REST` | Public POST routes under `ace-nudge/v1` |
| `class-front.php` | `Ace_Nudge_Front` | Service worker route, confirm and unsubscribe pages, script data, follow targets |
| `class-dispatch.php` | `Ace_Nudge_Dispatch` | Cron jobs, push and email delivery, newsletter rendering |
| `class-push.php` | `Ace_Nudge_Push` | Web Push (VAPID keys in option `ace_nudge_vapid`) |
| `class-feed.php` | `Ace_Nudge_Feed` | Public cached feed behind the bell |
| `class-woo.php`, `class-woo-email.php` | | WooCommerce order updates and product-update email |
| `class-privacy.php` | | WordPress personal data exporter and eraser |
| `class-admin.php` | `Ace_Nudge_Admin` | Admin screen, counts, consent log, settings |
| `class-blocks.php` | | Follow, Alerts and Newsletter blocks |
| `class-cli.php` | | `wp ace-nudge` commands |

Front end: `assets/notify.js` (the panel, bell, bubble and `window.aceNudge.api`), `assets/notify.css`, `assets/sw.js` (service worker, served from the site root as `/?ace_nudge_sw`).

## Cron

| Hook | Schedule | Work |
|---|---|---|
| `ace_nudge_live` | every minute | Game changes and reminders, bet slip checks and price moves |
| `ace_nudge_batch` | hourly | Tips, then the push batch for new posts and updates |
| `ace_nudge_digest` | daily 08:00 Europe/London | Newsletter, and removal of unconfirmed requests older than 7 days |

## Request flow: newsletter signup

1. The browser posts to `/wp-json/ace-nudge/v1/subscribe` with `channel: email`.
2. A pending subscriber is created with a digest follow and chosen category follows. A `requested` entry goes to the consent log.
3. A confirmation email carries `?ace_nudge_confirm=TOKEN`. Opening it shows a button. Pressing the button POSTs, sets the subscriber `active` and logs `granted`.
4. The daily digest mails active subscribers who have a `digest` follow.

## Pages are cached

The page carries only what is true for everyone (which game and categories it is about). The script decides everything personal, so full-page caches stay valid.
