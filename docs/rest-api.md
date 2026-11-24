# REST API

Namespace `ace-nudge/v1`. Every route is public (`permission_callback` is `__return_true`) and rate limited per visitor. Mutating routes use POST.

| Route | Method | Purpose |
|---|---|---|
| `/subscribe` | POST | `channel` `push` or `email`, `consent: true`, `age_confirmed`. Push adds `endpoint`, `p256dh`, `auth`. Email adds `email`, `frequency` (`daily` or `weekly`), `categories[]`, and a honeypot field `website` |
| `/follow` | POST | Follow a target for this subscriber |
| `/unfollow` | POST | Remove a follow |
| `/withdraw` | POST | `token`. Deletes the subscription and its follows |
| `/state` | POST | What this browser follows, for the panel |
| `/read` | POST | Mark a notification read |
| `/orders` | POST | WooCommerce order update preference |
| `/slip`, `/slip_done` | POST | Bet slip reminders and price alerts (sites with a slip) |
| `/feed` | GET | Public, cached feed of the last few days' new posts and real updates |

## Email signup behaviour

`/subscribe` for email returns `{ "pending": true }` whether the address is new, already active, suppressed or not allowed in the current mode, so addresses cannot be probed. An invalid address returns a 400. Consent and, where a minimum age is set, age confirmation are required or the call returns a 400.

## Mail links

| Link | Effect |
|---|---|
| `/?ace_nudge_confirm=TOKEN` | Shows a button. A POST activates the subscription |
| `/?ace_nudge_unsub=TOKEN` | Shows a button. A POST withdraws it. Mail clients' one-click unsubscribe POSTs here |
