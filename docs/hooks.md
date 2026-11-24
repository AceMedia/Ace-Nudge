# Hooks and the browser API

## Filters

| Filter | Purpose |
|---|---|
| `ace_nudge_defaults` | Site defaults for settings |
| `ace_nudge_min_age` | Minimum age. Overrides the setting |
| `ace_nudge_wording` | Consent wording shown and logged |
| `ace_nudge_brand` | Name, colours and logo used in the panel and email |
| `ace_nudge_email_footer` | Footer of the newsletter |
| `ace_nudge_promo_pattern` | Regex. Posts whose title matches are never sent |
| `ace_nudge_taxonomies`, `ace_nudge_top_categories` | What can be followed and offered |
| `ace_nudge_follow_every_term` | Taxonomies that get a button per term, such as teams |
| `ace_nudge_targets` | The follow targets a page offers |
| `ace_nudge_site_follows` | Extra opt-in alerts from other plugins (`id => label`) |
| `ace_nudge_slip_config`, `ace_nudge_slip_state` | A site's bet slip |
| `ace_nudge_feed_items` | Items in the bell feed |
| `ace_nudge_is_real_update`, `ace_nudge_update_reason` | What counts as an update, and how it is named |
| `ace_nudge_post_tips` | Tips attached to posts |
| `ace_nudge_onboarding` | Extra steps in the first-visit tour |
| `ace_nudge_bell_selector`, `ace_nudge_host_selector`, `ace_nudge_footer_selector`, `ace_nudge_bubble_quiet_selector` | Where the bell and bubble sit |
| `ace_nudge_manifest_url` | Web app manifest, needed for iPhone notifications |

## Action

`ace_nudge_updates_ready` fires with the updates about to be batched.

## Constants

`ACE_NUDGE_MODE`, `ACE_NUDGE_TEST_EMAILS`, `ACE_NUDGE_TEST_PUSH`, `ACE_NUDGE_SHOW_ALL`.

## Browser API: `window.aceNudge.api`

`follows()`, `status()`, `unfollow(entry)` or `unfollow(type, id)`, `open(section)` (`manage`, `push`, `interests`, `newsletter`), `on(fn)`, `info()`, `pushBlocker()`, `ageNeeded()`, `confirmAge(year)`, `pushOn()`, `pushOff()`, `categories()`, `site(which)`, `setSite(which, on)`, `setCategory(label, on)`, `newsletter({ email, frequency, categories })`.

Notifications: `notifications()`, `unread()`, `markRead(id)`, `markAllRead()`, `dismiss(id)`.

Events on `document`: `ace-nudge:change` with `{ status, follows }` and `ace-nudge:notifications` with `{ items, unread }`. Where NUDGE stays quiet (test mode, failed age check) `api.available` is false and `status().reason` says why.

When a site has `window.aceSettings` with an Alerts tab, the bell and manage links open that tab instead of NUDGE's own dialog.
