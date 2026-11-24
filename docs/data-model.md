# Data model

Four tables, prefixed with the WordPress table prefix and `ace_nudge_`. Schema version is stored in option `ace_nudge_db`.

## subscribers

| Column | Notes |
|---|---|
| `id` | Primary key |
| `channel` | `push` or `email` |
| `status` | `pending` or `active` |
| `email` | Email channel only |
| `endpoint`, `p256dh`, `auth` | Push channel only |
| `endpoint_hash` | SHA-256. For email it is the hash of the lowercased, trimmed address. Unique, so one row per address or device |
| `created_at`, `last_sent_at`, `sent_day`, `sent_count` | Used for daily caps |

## follows

One row per thing followed: `subscriber_id`, `type`, `object_id`, `meta` (JSON), `created_at`. Unique on subscriber, type and object.

Types include `post`, `category`, `ptype`, `site`, `read`, `fixture`, `digest` (object `daily` or `weekly`) and product follows. A follow can lapse after a set time through `meta.until`, cleared by `purge_lapsed()`. `read` follows older than 30 days are removed by the batch.

## consent_log

`subject_hash`, `channel`, `action` (`requested`, `granted`, `withdrawn`, `erased`, `suppressed`), `wording_version`, `wording` (the exact text shown), `age_confirmed`, `created_at`. It stores only a hash, never the address, so it survives erasure as evidence.

## suppression

`email_hash` (primary key), `reason`, `created_at`. An address here is never signed up and any existing subscription is withdrawn the next time the digest runs.

## Tokens

`ID.HMAC` where the HMAC is SHA-256 over `ace-notify|ID` with `wp_salt('auth')`, cut to 40 characters. Nothing is stored for them. Rotating the WordPress auth salt invalidates every link already sent.
