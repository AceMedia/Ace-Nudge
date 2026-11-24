# Operations

## WP-CLI (`wp ace-nudge`)

| Command | Purpose |
|---|---|
| `wp ace-nudge status` | Row counts for subscribers, follows, consent log and suppression |
| `wp ace-nudge suppress <file>` | Import suppressed addresses or SHA-256 hashes, one per line, and withdraw matching subscribers |
| `wp ace-nudge preview [--days=<days>]` | Print the newsletter as HTML (7 days by default). Sends nothing |
| `wp ace-nudge test_push` | Send a test push to every push subscriber the current mode allows, and print each HTTP status. `0` means not allowed or not delivered |

The commands are available in every mode, including `off`.

## Going live

1. In `test` mode, visit the site with `?ace_nudge_preview=1` and walk through following, the notification list and the newsletter sign-up.
2. Check `wp ace-nudge preview` and the confirmation email, with your address in `ACE_NUDGE_TEST_EMAILS`.
3. List your own subscriber id in `ACE_NUDGE_TEST_PUSH` and run `wp ace-nudge test_push`.
4. Confirm mail authentication (SPF, DKIM, DMARC) for the sending domain and a working `wp_mail` transport.
5. Set `ACE_NUDGE_MODE` to `on`.
6. Confirm the three cron hooks are scheduled: `wp cron event list`.

## Cron

`ace_nudge_minute` only does its job if WP-Cron really runs every minute. Set `DISABLE_WP_CRON` and call it from the system scheduler:

```
* * * * * cd /path/to/site && wp cron event run --due-now --quiet
```

Without that, live event alerts and selection list reminders arrive late on quiet sites. The hourly and daily jobs tolerate ordinary WP-Cron.

## Limits

| Limit | Value |
|---|---|
| Grouped pushes per browser per day | `daily_cap` setting, default 3. Counted per UTC day |
| Quiet hours | `quiet_start` to `quiet_end`, default 21:00 to 08:00. Evaluated in the Europe/London time zone |
| Grouped alerts | One message per person per hourly run, whatever was published or changed |
| Follows per subscription | 30 |
| Weekly newsletter | Mondays only |
| Newsletter sections | Up to 8 top categories, 3 stories each |
| Feed | 5 days, up to 60 new and 80 changed posts |
| Sign-up rate limit | 20 calls an hour per visitor address |

Live event alerts and WooCommerce order updates are service messages: they skip the daily cap and quiet hours.

## Deployment

- Install from a release zip, or check the repository out into `wp-content/plugins/ace-nudge` (a git submodule works well). There is no build step.
- Define `ACE_NUDGE_MODE` per environment in `wp-config.php`, and keep staging and development copies on `off` or `test` so a copied database never notifies real subscribers.
- The VAPID keys live in the database (option `ace_nudge_vapid`). A copy of the production database carries them, along with the subscribers, so the mode guard matters on copies.
- Tokens depend on the WordPress auth salt. Keep the salt the same across deployments of one site, or every browser subscription and emailed link stops working.
- The service worker is served by WordPress at `/?ace_nudge_sw=1`. Make sure a page cache or CDN does not cache that address for long, and does not strip the query string.
- If a full-page cache keys on query strings, leave `ref` out of the key: push links carry `?ref=nudge-ID`.
- Exclude `/wp-json/ace-nudge/v1/` POST routes from any cache. `GET /feed` may be cached for up to a minute.
- Behind a CDN or proxy, the rate limiter reads the visitor address from `CF-Connecting-IP` when present, otherwise `REMOTE_ADDR`. Make sure one of them is the real visitor address.
- After deploying a new version, no migration step is needed: the table check runs on `init`.

## Known limits

- No send queue. The newsletter loops over every active subscriber in one cron run.
- No bounce or complaint intake. Hard bounces must be added with `suppress`.
- No manual campaigns, segments or subscriber export.
- Changing the WordPress auth salt breaks every link already emailed and every browser token.
- Quiet hours and the newsletter's first run use the Europe/London time zone, not the site's.
- Deactivating the plugin leaves its tables, options and scheduled events in place. There is no uninstall routine.

See also [Troubleshooting](troubleshooting.md).
