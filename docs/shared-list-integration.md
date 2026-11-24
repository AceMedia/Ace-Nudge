# Shared mailing list design

Status: proposal. Nothing here is built yet.

## Goal

Make NUDGE the single mailing list for the ACE plugins. Other plugins collect addresses and ask for consent. NUDGE owns consent evidence, double opt-in, suppression and unsubscribe.

## Today

| Plugin | Holds addresses | Consent | Suppression | Unsubscribe |
|---|---|---|---|---|
| Ace NUDGE | Yes (`subscribers`) | Logged with wording | Yes | Yes, one click |
| A customer engagement plugin | `basket_recovery`, `form_submissions`, `chat_conversations`, `calls` | None for marketing | None | Basket emails only |
| A forms plugin | `ace_form_entries.contact_email` | Per form field only | None | None |

## Design

### 1. A small public API in NUDGE

Other plugins call these behind `function_exists()` so NUDGE stays optional.

- `ace_nudge_request_subscription( string $email, array $args ): bool`. Args: `source` (for example `form:contact`), `wording`, `frequency`, `categories`. Creates a pending subscriber and sends the double opt-in email. Same answer whatever happens, as the REST route does.
- `ace_nudge_is_suppressed( string $email ): bool`.
- `ace_nudge_status( string $email ): string` returning `none`, `pending`, `active` or `suppressed`.

### 2. Schema

Add `source varchar(40)` to `subscribers`, and store the source in `consent_log`. No other change. The address hash is already SHA-256 of the lowercased, trimmed address, so other plugins can compare hashes without sharing addresses.

### 3. Capture points

- **Content Enhancer:** a form option "Offer the newsletter" with an unticked checkbox and its wording. On a successful submission with the box ticked, call `ace_nudge_request_subscription`. The form entry keeps only that the box was ticked.
- **Customer Engagement:** the same option on captured forms, and an optional checkbox at WooCommerce checkout. Basket recovery stays a one-off transactional reminder and is not a subscription.

### 4. Suppression honoured everywhere

Before any non-essential email, Engagement and Content Enhancer call `ace_nudge_is_suppressed`. A global "never email me" through NUDGE's unsubscribe page adds the address to suppression for all of them.

### 5. Gaps to close in NUDGE first

1. A send queue with batches, so a large list does not run inside one cron request.
2. Bounce and complaint intake (for example SES notifications through SNS) writing to `suppression`.
3. Subscriber export and import, with consent evidence required for imports.
4. Optional manual campaigns and segments by follow.

## Order of work

1. Public API and `source` column.
2. Content Enhancer capture.
3. Engagement capture and suppression checks.
4. Queue and bounce intake.
5. Export, then campaigns.

## Privacy constraints

No pre-ticked boxes. No sign-up from a form without the visitor ticking. Existing addresses in form entries or baskets are never imported into the list.
