=== Ace NUDGE ===
Contributors: shanerounce
Requires at least: 6.5
Requires PHP: 8.1
Stable tag: 0.3.4
License: GPLv2 or later

Notifications, Updates, Digests, Gated Email. Opt-in browser notifications and an email newsletter.

== Description ==
Visitors follow a story, product, category or game, or the whole site, without an account. Consent is logged with the exact wording shown. WooCommerce products add back in stock and price drop alerts.
On WooCommerce sites, customers can turn on order updates for a device from checkout or their account, and choose optional emails, all through WooCommerce's own email system.
Blocks: Follow Button, Alerts Button, Newsletter Button. Site rules (age check, wording, branding) come through filters listed in ace-nudge.php.

== Browser API ==
Other scripts can read and change what this browser follows through window.aceNudge.api:

* follows() lists each follow with a key, its keys, a type and a label.
* status() gives push permission, whether this browser is subscribed, the scope, and ready once the server has answered.
* unfollow(entry) or unfollow(type, id) removes a follow.
* open(section) opens NUDGE's own steps: manage (the default), push, interests or newsletter.
* on(fn) calls fn with the follow keys after every change.
* info(), pushBlocker(), ageNeeded(), confirmAge(year), pushOn(), pushOff(), categories(), site(which), setSite(which, on), setCategory(label, on) and newsletter({ email, frequency, categories }) let a settings panel run every alert control itself, with NUDGE's rules.

When the site has a settings panel (window.aceSettings with its Alerts tab), the header bell, the alerts and footer links, ?ace_nudge_manage and a Follow button that is already following open that tab instead of NUDGE's own dialog. The first follow from an article still runs NUDGE's own opt-in steps. The bell shows how many things the browser follows, and when NUDGE is quiet the bell still opens the tab, which says why.

Each change also fires ace-nudge:change on document with { status, follows }. In test mode without a preview, or after a failed age check, api.available is false and status().reason says why.
