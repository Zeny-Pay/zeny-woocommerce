# Zeny for WooCommerce

Take payments in a WooCommerce store through Zeny payment links. At checkout
the shopper is sent to a Zeny payment page for the order and chooses QRIS, a
virtual account or an e-wallet there. The order is marked paid when Zeny
confirms the payment with a signed webhook.

Requirements: PHP 7.4 or later, WordPress 6.3 or later, WooCommerce 8.0 or
later, and a store that sells in Indonesian rupiah (IDR). Works with the
classic checkout and the Checkout block, and with both order storage modes
(HPOS and posts).

## Install

1. Download `zeny-woocommerce-<version>.zip` from the
   [releases](https://github.com/Zeny-Pay/zeny-woocommerce/releases).
2. In WordPress, go to **Plugins > Add New Plugin > Upload Plugin**, choose the
   zip and activate it.
3. Go to **WooCommerce > Settings > Payments > Zeny**.

## Settings

| Setting                | What it does                                                                                       |
| ---------------------- | -------------------------------------------------------------------------------------------------- |
| Enable                 | Shows Zeny at checkout.                                                                            |
| Title, Description     | What shoppers see at checkout.                                                                     |
| Secret API key         | From the Zeny dashboard. `sk_test_…` uses Sandbox, `sk_live_…` uses Production.                    |
| Webhook secret         | The `whsec_…` secret of the webhook endpoint (see below).                                          |
| Link lifetime          | Minutes a shopper has to pay, 15 to 10080 (7 days). Default 60.                                    |
| Payment methods        | Instrument codes to offer, such as `QRIS, BCA, GOPAY`. Empty offers every method.                  |
| Required payer details | Name, email or phone the shopper must enter on the Zeny page.                                      |
| Debug log              | Writes API calls and webhooks to **WooCommerce > Status > Logs** (`zeny`). Secrets are never logged. |

Saved secrets are never shown again: leave a secret field blank to keep it.
The settings page shows the mode (Sandbox or Production) and has a
**Test connection** button.

## Webhook setup

1. Copy the **Webhook URL** from the Zeny settings page. It is
   `https://<your-store>/wp-json/zeny/v1/webhook`.
2. In the Zeny dashboard, add a webhook endpoint with that URL and the
   `payment.paid` event.
3. Paste the endpoint's `whsec_…` secret into **Webhook secret** and save.

Zeny sends webhooks to https addresses only, so the store must be on https for
orders to be marked paid on their own.

## How a payment works

1. At checkout the plugin creates one payment link for the order, for the
   order total, and sends the shopper to it. Paying again for the same order
   reuses the link while it is active.
2. The shopper pays on the Zeny page and returns to the order-received page,
   which shows "We are confirming your payment" until the order is paid.
3. Zeny sends `payment.paid`. The plugin checks the signature, that the
   payment went through the order's link, that it collected the order total in
   IDR, and that it comes from the same environment as the key. Then the order
   is marked paid. Anything else puts the order **on hold** with a note.

When WooCommerce cancels an unpaid order, its payment link is canceled too.

If a webhook never arrives, open the order, enter the Zeny payment id
(`pay_…`) in the **Zeny** box and click **Confirm payment**. The plugin reads
the payment from Zeny and applies the same checks.

## Testing in Sandbox

Use an `sk_test_` key and the webhook secret of a Sandbox endpoint. Place an
order, pay it on the Sandbox payment page, and check that the order moves to
Processing. Sandbox and Production are separate: a Sandbox payment never pays
an order while a live key is saved.

## FAQ

**Can I refund from WooCommerce?** No. The Zeny API has no refund route yet.
Refund from the Zeny dashboard, then mark the order refunded in WooCommerce.

**Why is Zeny missing at checkout?** The store currency is not IDR, the
gateway is off, or there is no valid secret key. An admin notice says which.

**A shopper paid twice for one order.** The order is paid once and gets a note
naming the second payment. Refund it from the Zeny dashboard.

**A payment arrived after the order was cancelled.** The order is put on hold
with a note, because money was taken. Complete it or refund the payment.

## Limits

- IDR only.
- No refunds, subscriptions or pre-orders.
- One payment link per checkout attempt; a link takes one payment.

## Development

No local PHP is needed; every tool runs in Docker. See [AGENTS.md](AGENTS.md).

```sh
bin/lint     # PHPCS (WordPress Coding Standards) + PHPStan
bin/test     # unit tests on PHP 7.4 and 8.3, integration on WordPress + WooCommerce
bin/build    # build/zeny-woocommerce-<version>.zip, checked for its contents
```

A release is a tag `vX.Y.Z` on `main`: the Release workflow builds the zip and
attaches it to a GitHub release.
