# Operations

## WP-CLI (`wp ace-nudge`)

| Command | Purpose |
|---|---|
| `status` | Row counts for subscribers, follows, consent log and suppression |
| `suppress FILE` | Import suppressed addresses or hashes and withdraw matching subscribers |
| `preview [--days=N]` | Print the newsletter as HTML. Sends nothing |
| `test-push` | Send a test push |

## Going live

1. Check `wp ace-nudge preview` and the confirmation email in `test` mode with `ACE_NUDGE_TEST_EMAILS`.
2. Confirm mail authentication (SPF, DKIM, DMARC) for the sending domain and a working `wp_mail` transport.
3. Set `ACE_NUDGE_MODE` to `on`.
4. Confirm the three cron hooks are scheduled (`wp cron event list`).

## Limits

Daily cap per subscriber (default 3 pushes), quiet hours 21:00 to 08:00 UK time, one message per person per batch run, weekly newsletters on Mondays only.

## Known limits

- No send queue. The newsletter loops over every active subscriber in one cron run.
- No bounce or complaint intake. Hard bounces must be added with `suppress`.
- No manual campaigns, segments or subscriber export.
- Changing the WordPress auth salt breaks every link already emailed.

## Deployments

IEG production runs NUDGE from a dploy overlay on AceHost rather than the submodule. Update both the overlay and the current release when changing it. Other sites use the `assets/plugins/Ace-Nudge` submodule.
