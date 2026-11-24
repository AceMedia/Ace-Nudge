=== Ace NUDGE ===
Contributors: shanerounce
Tags: notifications, web push, newsletter, follow, woocommerce
Requires at least: 6.5
Tested up to: 6.8
Requires PHP: 8.1
Stable tag: 0.7.2
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Notifications, Updates, Digests, Gated Email. Opt-in browser notifications and an email newsletter, with consent evidence.

== Description ==
Visitors follow a story, product, category or game, or the whole site, without an account. Consent is logged with the exact wording shown.

* Browser push sent by the plugin itself (VAPID), within a daily cap and quiet hours. No third-party service.
* A daily or weekly email newsletter by category, with double opt-in and one-click unsubscribe.
* A bell with a list of new posts and real updates, worked out in the browser from a public cached feed, so pages stay cacheable.
* An optional age check that runs in the browser. Only the fact that it was passed reaches the server.
* An admin overview, subscriber list and consent log.
* WordPress personal data export and erasure.

On WooCommerce sites, customers can turn on order updates for a device from checkout or their account, choose which optional emails they receive, and ask for an email when something they bought is back in stock or drops in price, all through WooCommerce's own email system.

Blocks: Follow Button, Alerts Button, Newsletter Button.

Site rules (age check, wording, branding, what can be followed) come through filters. The documentation is in the docs folder and at https://github.com/AceMedia/Ace-Nudge/wiki.

== Installation ==
1. Upload the plugin zip under Plugins, Add New, Upload Plugin, and activate it.
2. Add define( 'ACE_NUDGE_MODE', 'test' ); to wp-config.php. The default is off, which prints and sends nothing.
3. Visit the site with ?ace_nudge_preview=1 to try it. List your address in ACE_NUDGE_TEST_EMAILS and your subscriber id in ACE_NUDGE_TEST_PUSH to receive the newsletter and push in test mode.
4. Review the settings under Ace NUDGE in the admin menu, then set ACE_NUDGE_MODE to on.

Web Push needs HTTPS, the PHP OpenSSL extension and a site served from the root of its domain. Game alerts need WP-Cron to run every minute.

== Frequently Asked Questions ==

= Does it need an account with a push or email service? =
No. Push is sent straight to the browsers' push services, and email goes through wp_mail().

= Does it work with page caching? =
Yes. The page carries nothing personal, and the public endpoints do not use nonces.

= Does it work on iPhone? =
Only for a site added to the Home Screen, which needs a web app manifest. Supply one with the ace_nudge_manifest_url filter.

== Browser API ==
Other scripts can read and change what this browser follows through window.aceNudge.api:

* follows() lists each follow with a key, its keys, a type and a label.
* status() gives push permission, whether this browser is subscribed, the scope, and ready once the server has answered.
* follow(target) follows through the usual steps. unfollow(entry) or unfollow(type, id) removes a follow.
* open(section) opens NUDGE's own steps: manage (the default), push, interests or newsletter.
* on(fn) calls fn with the follow keys after every change.
* notifications(), unread(), markRead(id), markAllRead() and dismiss(id) work with the notification list.
* info(), pushBlocker(), ageNeeded(), confirmAge(year), pushOn(), pushOff(), categories(), site(which), setSite(which, on), setCategory(label, on) and newsletter({ email, frequency, categories }) let a settings panel run every alert control itself, with NUDGE's rules.

When the site has a settings panel (window.aceSettings with its Alerts tab), the header bell, the alerts and footer links, ?ace_nudge_manage and a Follow button that is already following open that tab instead of NUDGE's own dialog. The first follow from an article still runs NUDGE's own opt-in steps. The bell shows how many notifications are unread, and when NUDGE is quiet the bell still opens the tab, which says why.

Each change also fires ace-nudge:change on document with { status, follows }. In test mode without a preview, or after a failed age check, api.available is false and status().reason says why.

== Changelog ==

= 0.7.2 =
Test mode now also limits product update emails to the test addresses. The confirmation link only confirms a pending email request.

= 0.7.1 =
The bubble's label is a small flag across its left edge. Documentation and release tooling.

= 0.7.0 =
First-visit tour, extendable through ace_nudge_onboarding.

= 0.6.2 =
The bubble shows its time left as a bar and holds while pointed at. Custom properties for its colours.

= 0.6.1 =
The header bell's place is held as the page is parsed.

= 0.6.0 =
Other plugins can offer their own opt-in alerts (ace_nudge_site_follows).

The full history is in CHANGELOG.md.
