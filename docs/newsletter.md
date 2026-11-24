# Newsletter

A daily or weekly round-up by category, built from the site's own posts. Switch it off with the "Offer the newsletter sign-up" setting if the site has its own newsletter.

## Signing up

1. A visitor opens the sign-up from the Newsletter Button block, the panel, the footer link or `window.aceNudge.api.open( 'newsletter' )`.
2. If a minimum age is set, they are asked for a year of birth.
3. They choose categories (or none, for a bit of everything), daily or weekly, give an address and switch on the consent toggle.
4. The server stores a pending subscriber and sends a plain text confirmation email with the subject "Confirm your SITE newsletter".
5. The link opens a page with a "Confirm my subscription" button. Pressing it activates the subscription. Opening the link alone changes nothing, so mail scanners cannot confirm anyone.

Requests that are not confirmed within 7 days are deleted. The route answers the same way whether the address is new, already subscribed or suppressed, so it cannot be used to find out who is subscribed.

## What is sent

The digest job runs daily, first at 08:00 Europe/London. Daily subscribers get the last day's posts each run, weekly subscribers the last 7 days' posts on Mondays.

- Sections are the site's top categories: up to 8 top-level terms by post count, with same-named terms merged and "Uncategorised" left out. Change them with `ace_nudge_top_categories`.
- A subscriber who chose categories gets only those sections.
- Each section holds up to 3 posts, and a post appears once.
- Titles matching `ace_nudge_promo_pattern` are left out.
- If there is nothing to send, nothing is sent.

The subject is "Your SITE round-up". The HTML uses tables and inline styles, with colours, name and logo from `ace_nudge_brand`, and an optional footer from `ace_nudge_email_footer`:

```php
add_filter( 'ace_nudge_email_footer', function ( $html, $link_style ) {
	return 'Example News Ltd, 1 Example Street. <a style="' . esc_attr( $link_style ) . '" href="https://example.com/contact/">Contact us</a>';
}, 10, 2 );
```

Preview it without sending:

```
wp ace-nudge preview --days=7 > newsletter.html
```

## Unsubscribing

Every newsletter carries an unsubscribe link, a `List-Unsubscribe` header and a one-click `List-Unsubscribe-Post` header. The link opens a page with an Unsubscribe button; mail clients' one-click unsubscribe POSTs to the same address. Unsubscribing deletes the subscriber and their follows and leaves a hashed line in the consent log.

## Delivery

Mail goes through `wp_mail()`, one call per subscriber, inside the cron run. Use a proper mail transport and authenticate the sending domain with SPF, DKIM and DMARC. There is no send queue and no bounce or complaint handling yet: add hard bounces to the suppression list with `wp ace-nudge suppress`.
