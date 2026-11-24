# Ace NUDGE

Notifications, Updates, Digests, Gated Email. Opt-in browser notifications and an email newsletter for WordPress, with consent evidence and an admin overview.

Visitors follow a story, product, category, game or the whole site, with no account. Consent is logged with the exact wording shown. On WooCommerce sites, customers can also turn on order updates, back-in-stock and price-drop alerts.

Current version: **0.7.1**. Requires WordPress 6.5 and PHP 8.1. Licence GPL-2.0-or-later.

## What it does

| Channel | How a person joins | What they get |
|---|---|---|
| Browser push | Follow button, bell or alerts panel, then the browser's permission prompt | Alerts for what they follow, within daily caps and quiet hours |
| Email newsletter | Newsletter button or panel, then a double opt-in email | A daily or weekly round-up by category |
| WooCommerce email | Checkout, account page or registration | Order updates, back in stock, price drops, through WooCommerce's own mailer |
| In-page bell | Automatic | A cached feed of new posts and real updates, read or dismissed per browser |

Every email carries `List-Unsubscribe` and one-click `List-Unsubscribe-Post` headers. Links only act on a POST, so mail scanners cannot confirm or unsubscribe anyone.

## Modes

Set `ACE_NUDGE_MODE` in `wp-config.php`.

- `off` (default): nothing is printed or sent. Admin screens still work.
- `test`: the prompt only shows after visiting `?ace_nudge_preview=1`. Email only goes to addresses in `ACE_NUDGE_TEST_EMAILS`. Push only goes to subscriber ids in `ACE_NUDGE_TEST_PUSH`. `ACE_NUDGE_SHOW_ALL` shows the prompt to everyone.
- `on`: open to visitors.

## Install

Copy or submodule the plugin into `wp-content/plugins/ace-nudge`, activate it, set the mode, then open **Ace NUDGE** in the admin menu. Tables are created on activation. Site rules such as an age check, wording and branding come through filters, listed in [docs/hooks.md](docs/hooks.md).

## Documentation

- [Architecture](docs/architecture.md): files, classes, cron and request flow
- [Data model](docs/data-model.md): the four tables
- [REST API](docs/rest-api.md): routes, parameters and responses
- [Hooks and the browser API](docs/hooks.md): filters, actions, `window.aceNudge.api`
- [Privacy and consent](docs/privacy.md): what is stored, erased and logged
- [Operations](docs/operations.md): WP-CLI, cron, caps, going live, troubleshooting
- [Shared mailing list design](docs/shared-list-integration.md): using NUDGE as the list for other ACE plugins
- [Changelog](CHANGELOG.md)

The same pages are published in the GitHub wiki.

## Blocks

Follow Button, Alerts Button and Newsletter Button. Each is server-rendered and lets a site place a control where it wants one.

## Known limits

Sending is one `wp_mail` call per subscriber inside a cron run, with no queue. There is no bounce or complaint handling and no manual campaign composer. See [the shared list design](docs/shared-list-integration.md) for the plan.

## Download

Each release has an installable zip on the [Releases page](https://github.com/AceMedia/Ace-Nudge/releases). In WordPress choose Plugins, Add New, Upload Plugin, pick `ace-nudge-VERSION.zip`, then activate. To build one yourself run `bin/build-zip.sh`, which writes `dist/ace-nudge-VERSION.zip`. Pushing a tag such as `v0.7.1` builds and publishes the release automatically.
