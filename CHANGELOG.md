# Changelog

All notable changes to Ace NUDGE. Each released version has its own section, headed with the bare version number, because the release workflow takes its notes from the section with that exact heading. Dates are the dates of the commits that set each version.

## 0.7.3

Released 2026-11-24.

- Changed: nothing site-specific is left in the plugin. The notice appended to recommendation pushes, the intro the bubble waits for, and the title of selection list notifications now come from the filters `ace_nudge_tip_notice`, `ace_nudge_hold_selector` and `ace_nudge_slip_title`, all empty or neutral by default.
- Changed: the notification bubble is styled through the custom properties `--ace-nudge-bubble-bg`, `-fg`, `-line` and `-shadow`. The bell count uses the site's primary colour, and a site restyles it with its own CSS rule.
- Documentation and illustrations: neutral wording throughout, a full wiki, and a new Ace and NUDGE lockup on the banner and overview.

## 0.7.2

Released 2026-11-24.

- Fixed: in test mode, back in stock and price drop emails went to real customers. They now follow the same rule as the newsletter, so outside `on` mode they only reach addresses in `ACE_NUDGE_TEST_EMAILS`.
- Fixed: the confirmation link said "Thanks, you're subscribed" for any token, and a push subscriber's token posted to it logged an email consent. Only a pending email request can now be confirmed. An invalid or expired link shows an error, and an already active subscriber is not logged twice.

## 0.7.1

Released 2026-11-16. Documentation and release tooling added 2026-11-24.

- The bubble's label is a small flag across its left edge. It pokes out from behind the bubble, comes in front and tucks back over the edge with a small spring and tilt, and leaves the same way in reverse before the bubble goes.
- README, `docs/`, this changelog, `CONTRIBUTING.md`, `SECURITY.md`, the release zip script and the release workflow. The version constant and the readme's stable tag match the plugin header.

## 0.7.0

Released 2026-11-16.

- First-visit tour: a reader who follows nothing yet is shown round once, with a bubble from the bell and then one for each step a site adds through `ace_nudge_onboarding`. Each runs its time or is closed before the next.
- The bubble's picture, words and close button are top aligned, and its label rides a flag at its left edge.

## 0.6.2

Released 2026-11-16.

- The bubble shows how long it has left as a bar along its foot. Pointing at it or focusing inside holds the bar, and leaving carries on from there. When it runs out the bubble slides off to the side.
- A site can colour the bubble, its blur, the bar and the chip through custom properties.

## 0.6.1

Released 2026-11-10.

- The header bell's place is held from the footer as the page is parsed, and `notify.js` takes the stand-in over, so a header menu that fits its items to the room is not fitted twice when the bell arrives.

## 0.6.0

Released 2026-11-09, with fixes to 2026-11-10.

- Other plugins can offer their own opt-in alerts through `ace_nudge_site_follows` and send them with `Ace_Nudge_Dispatch::broadcast()`, which waits out quiet hours and keeps to the daily cap.
- Bubble: the close button sits centred down the right, clear of the pointer, with a larger X.
- Settings save bar: the companion plugin that supplies it is found under its newer constant names as well as the older ones.

## 0.5.0

Released 2026-11-09.

- The selection list reminder is opt-in (`site:slipremind`). It is sent once before the first item starts, without totals, and respects quiet hours and the daily cap. The idle reminder was removed.

## 0.4.9

Released 2026-11-09.

- The notification bubble and its sound wait for a page's first-load intro to finish, then arrive a moment after.

## 0.4.8

Released 2026-11-09.

- The tab in view checks the feed once a minute, so new notifications arrive in place without a refresh. The feed caches for a minute.

## 0.4.7

Released 2026-11-09.

- Fix: the bell bubble shows again. The sound choice read the newest item before it was set.

## 0.4.6

Released 2026-11-09.

- Selection list and site notifications sound different, the sound plays straight away where the browser already allows it, and the feed is checked again every four minutes while the page is in view.

## 0.4.5

Released 2026-11-09.

- The notification sound can be switched off, kept in this browser. Switching it on plays it once.

## 0.4.4

Released 2026-11-09.

- A short sound as a notification comes in, made in the browser, once the page may play sound.

## 0.4.3

Released 2026-11-08.

- Bell bubble: a reload shows it again for what is unread, so new notifications can be previewed. Ordinary visits still tell each item once.

## 0.4.2

Released 2026-11-08.

- A site's selection list and recommendations join the notification list. Listed items report price moves, items closing, items starting, results and their article being updated, from the site's cached state. Recommendations come from recent articles in subjects the reader follows or reads, learnt on the device and switchable off.
- Opt-in alerts for selection list price changes and new recommendations.

## 0.4.1

Released 2026-11-08.

- Push guard: in `test` mode a push only goes to subscriber ids listed in `ACE_NUDGE_TEST_PUSH`, and nothing goes when the mode is `off`, so a development copy never notifies real devices.

## 0.4.0

Released 2026-11-08.

- Notifications beside the bell: a public, cached feed of the last few days' new posts and real updates, with a filter for site reasons such as results. The browser keeps what it has seen and read, works out its unread list from what it follows, counts it on the bell and names the newest in a bubble once.
- Push links carry `ref=nudge-ID`, so opening one marks it read.

## 0.3.5

Released 2026-11-08.

- A pale follow button colour keeps the button's border, and its label is darkened just enough to read on white.
- Bell count: white text on the badge in light mode.

## 0.3.4

Released 2026-11-07, with fixes to 2026-11-08.

- Alerts can be managed in a site's own settings panel: the bell and every manage link open its Alerts tab, the bell shows a count, and the browser API gains push, age check, interests and newsletter controls.
- Fix: the minimum age is treated as a number in the age check.
- Bell count colours for light and dark mode, placed from the icon's centre so it stays on the bell at any button size.

## 0.3.3

Released 2026-11-07.

- Browser API for other scripts: `follows()`, `status()`, `unfollow()`, `open(section)` and an `ace-nudge:change` event.

## 0.3.2

Released 2026-11-05, with additions to 2026-11-07.

- Follow ids are stored as text, and a change to a product variation is credited to its parent.
- A follow API for other scripts, and follows that lapse after a set number of days.
- Selection list reminders.
- Follow buttons: one row on pages that set two sides against each other, and a target's own colours and icon.

## 0.3.1

Released 2026-11-05.

- Per-term follow buttons can sit on a side, and following a term from a scheduled event's page also covers that event.

## 0.3.0

Released 2026-11-05.

- A per-site age limit.
- WooCommerce: order updates on a device and email choices, suggested at sign-up, checkout and in the account area.
- Extra taxonomies can be followed per term and managed in the panel.
- A newsletter switch and site defaults.
- The generic confirmation no longer uses wording meant for scheduled events.

## 0.2.0

Released 2026-11-05. The first version in this repository; the earlier prototype was not published here.

- Opt-in browser notifications and an email newsletter: follow a story, product, category or scheduled event, or the whole site, with a consent log and an admin overview.
- The settings screen loads the shared save bar when present, and same-named categories are merged.
