# Privacy and consent

## Stored on the server

- Email address and subscription state, while a person is subscribed.
- Push endpoint and keys, while a browser is subscribed. Browser subscribers are anonymous: there is no account, name or address.
- Follows: what each subscription asked to hear about.
- Reading history, only for a browser that switched on "Updates to what I've read", and only for 30 days. Switching it off deletes the history at once. The admin screens never show it, only that the option is on.
- A summary of the visitor's selection list, only on sites that have one and only for a browser with notifications on. It is forgotten when its items have started, after two days without a change, or when the list is completed or cleared.
- A consent log holding hashes, never an address or endpoint.
- Daily send totals. No per-message log.

## Kept in the browser only

The token, the age check result, what has been seen, read, shown and dismissed, the unread count, sound and recommendation preferences, and the interests learnt for recommendations. None of this is sent to the server.

## Consent

Nothing is pre-ticked. The wording shown is stored word for word with a version, so a later change of wording never rewrites old evidence. Each channel has its own consent: push, the newsletter, order updates, product emails and reading history are asked for and logged separately. An order-updates subscription is not treated as consent to anything else.

Newsletter sign-up is double opt-in, and unconfirmed requests are deleted after 7 days. WooCommerce product emails have no confirmation step, because the address is the customer's own checkout or account address.

## Age check

Where a minimum age is set, the browser asks for a year of birth before any follow or sign-up. Only the fact that the check was passed reaches the server. Someone born in the year that would turn the minimum age this year does not pass, as they may not have reached it yet. A failed check is remembered in the browser and the controls are removed. Order updates are not age checked, as they are about a purchase.

## Withdrawal and erasure

- Push: "Turn off and delete my subscription" in the panel, the "Stop alerts" action on a notification, or blocking notifications in the browser, which the script treats as a withdrawal on the next visit.
- Email: the unsubscribe link in every email, or the mail client's one-click unsubscribe.
- An administrator can remove any subscriber from the Subscribers tab.
- A push service reporting a subscription as gone (HTTP 404 or 410) removes it.

`Ace_Nudge_Store::withdraw()` deletes the subscriber and their follows and keeps only a hashed log line.

The WordPress personal data exporter and eraser are registered, so Tools, Export Personal Data and Erase Personal Data cover email subscribers. The export holds the address, status and sign-up time.

## Suppression

Hashed addresses that must never be mailed. Import a list with `wp ace-nudge suppress FILE`. Lines can be plain addresses or 64-character SHA-256 hashes.

## Test safety

Outside `on` mode, the newsletter and its confirmation only reach `ACE_NUDGE_TEST_EMAILS`, and push only reaches the subscriber ids in `ACE_NUDGE_TEST_PUSH`. In `off` mode nothing is sent at all. WooCommerce product-update emails go through WooCommerce's mailer and are not held back by the test list, so disable that email in WooCommerce on a test copy.
