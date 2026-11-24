# WooCommerce

With WooCommerce active and NUDGE in `test` or `on` mode, customers get three things: order updates on a device, a say over optional emails, and product-update emails. Essential order emails are never touched.

## Order updates on a device

A customer can turn on push notifications for an order, or for all their orders, without following anything else. This is a service message, so it is not age checked, it skips the daily cap and quiet hours, and it is not treated as consent to marketing alerts.

Where it is offered:

| Place | Control | Result |
|---|---|---|
| Order received page | "Turn on order updates" button | Follows that order, proved by its order key, so guests can use it |
| My account dashboard and View order | The same button | Follows all of the signed-in customer's orders |
| Edit account form | The same button | As above |
| Checkout and registration forms | A tick box | The browser asks for permission straight away, and the order or account is linked on the next page |
| Block checkout | A tick box placed before the checkout's buttons | As above |

A push goes out when an order moves to one of the statuses ticked under "Order updates on devices" (by default processing, completed, refunded and cancelled). It reads "Order #123: Your order is now: Completed" and links to the order. A single-order follow ends when the order is completed, refunded or cancelled.

## Optional emails

Under "Emails customers can switch off", choose which non-essential customer emails customers may opt out of. The default is the customer note email. Order confirmations, invoices, refunds, account and password emails are essential and are never listed.

Signed-in customers then see a tick box for each on their Edit account form, under "Updates". Their choice is stored in the user meta `_ace_nudge_email_off` and applied through WooCommerce's own `woocommerce_email_enabled_{id}` filter, for orders that belong to an account. Templates and sending stay in WooCommerce, Settings, Emails.

## Product updates

### For buyers, by email

Customers can opt in to "Email me when something I've bought is back in stock or drops in price" at checkout (classic and block), at registration and on the Edit account form. There is no confirmation email, because the address is the customer's own.

When a product they bought comes back in stock or drops in price, they get the "Product update (Ace NUDGE)" email. It is an ordinary WooCommerce email:

- It appears under WooCommerce, Settings, Emails, where it can be disabled and its subject and heading changed.
- It uses the store's email header, footer and styles.
- Its templates can be overridden by copying `templates/emails/product-update.php` and `templates/emails/plain/product-update.php` to `yourtheme/woocommerce/emails/`.
- It carries an unsubscribe link.

### For followers, by push

With the `product` post type enabled in the settings, visitors can follow a product or a product category. A followed product sends "Back in stock" or "Price drop" in the hourly batch.

### What counts

- Back in stock: the stock status changes to in stock from anything else.
- Price drop: the sale or regular price is set to less than the product's previous active price.
- A change to a variation is credited to its parent product, since people follow and buy the parent.

Changes are queued when the product is saved and handled in the next hourly run, outside quiet hours.

## On the block checkout

The product-update opt-in is registered as an additional checkout field, `ace-nudge/product-updates`, in the order section. It needs a WooCommerce version that provides `woocommerce_register_additional_checkout_field()`.

## Testing

Push to devices and the product-update email both follow the test guard: outside `on` mode the email only reaches addresses in `ACE_NUDGE_TEST_EMAILS` (from 0.7.2; earlier versions did not check, so disable it under WooCommerce, Settings, Emails on a test copy with real customers).
