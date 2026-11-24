# Troubleshooting

## Nothing appears on the site

- Check the mode on the admin screen's first line. `off` prints nothing.
- In `test` mode, visit any page with `?ace_nudge_preview=1` once. The browser remembers it.
- A browser that failed the age check is remembered and shown nothing. Clear the site's `localStorage` key `aceNudge.age` to test again.
- The bell only appears when `ace_nudge_bell_selector` returns a selector that matches an element.
- Follow buttons only appear on pages with something to follow: an enabled post type's single pages and archives, and followable term archives. Check "Add them to pages automatically" or place the Follow Button block.

## The browser will not subscribe

- The site must be on HTTPS and at the root of its domain.
- Open `/?ace_nudge_sw=1`. It should return JavaScript. If a cache or security rule strips the query string or blocks it, the service worker cannot register.
- Notifications blocked in the browser's site settings cannot be asked for again by a page. The visitor has to allow them there.
- On iPhone and iPad the site must be added to the Home Screen, and needs a manifest. See [Push notifications](push-notifications.md).
- A 400 "Invalid subscription" from `/subscribe` means the push endpoint is not on a known push service host.

## Pushes are not arriving

- `wp ace-nudge test_push` prints the push service's status for each subscriber. `0` means the mode did not allow the send, or the request failed. In `test` mode the subscriber id must be in `ACE_NUDGE_TEST_PUSH`.
- `wp cron event list` should show `ace_nudge_minute`, `ace_nudge_hourly` and `ace_nudge_digest`. If WP-Cron only runs on page views, alerts are late. See [Operations](operations.md).
- Grouped alerts wait out quiet hours and stop at the daily cap.
- An edit only counts as an update when the title changes or about 200 characters of text are added or removed. Change that with `ace_nudge_is_real_update`.
- Titles matching `ace_nudge_promo_pattern` are never sent.
- The server needs the OpenSSL extension and outbound HTTPS.

## The newsletter is not arriving

- Outside `on` mode, only addresses in `ACE_NUDGE_TEST_EMAILS` get the confirmation or the newsletter. The sign-up still answers "check your inbox".
- A suppressed address is never mailed.
- The subscriber must be confirmed: look for "Newsletter, unconfirmed" on the Overview tab.
- Weekly subscribers are mailed on Mondays. Nobody is mailed when there are no new posts in their sections.
- `wp ace-nudge preview` shows what would be sent. If it is empty, there is nothing in the top categories for the period.
- Check that `wp_mail()` works at all, and the sending domain's SPF, DKIM and DMARC.

## Links in emails stopped working

Tokens are derived from the WordPress auth salt. If the salt changed, every confirmation and unsubscribe link sent before the change is invalid, and browsers have to subscribe again.

## The unread count looks wrong

The list is worked out in each browser from the public feed and that browser's own record of what it has seen. It covers the last 5 days, and the feed is cached for a minute. Clearing site data resets it.

## A development copy and real subscribers

A copied production database contains real subscribers and the real VAPID keys. Keep the copy's `ACE_NUDGE_MODE` at `off` or `test`. In `test` nothing goes to a push subscriber that is not listed in `ACE_NUDGE_TEST_PUSH`, and the newsletter only goes to `ACE_NUDGE_TEST_EMAILS`.
