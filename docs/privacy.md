# Privacy and consent

## Stored

Email address and subscription state while a person is subscribed. Push endpoint and keys while a browser is subscribed. Follows. A hashed consent log.

## Consent

Nothing is pre-ticked. The wording shown is stored word for word with a version, so a later change of wording never rewrites old evidence. Newsletter signup is double opt-in and unconfirmed requests are deleted after 7 days. An age question runs in the browser and only the fact it was passed reaches the server.

## Withdrawal and erasure

`Ace_Nudge_Store::withdraw()` deletes the subscriber and their follows and keeps only a hashed log line. The WordPress personal data exporter and eraser are registered, so Tools > Export and Erase Personal Data cover the newsletter.

## Suppression

Hashed addresses that must never be mailed. Import a list with `wp ace-nudge suppress FILE`. Lines can be plain addresses or 64-character SHA-256 hashes.

## Test safety

Outside `on` mode, email only reaches `ACE_NUDGE_TEST_EMAILS` and push only reaches `ACE_NUDGE_TEST_PUSH`.
