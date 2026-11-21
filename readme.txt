=== Zeny for WooCommerce ===
Contributors: zeny
Tags: payments, qris, indonesia, woocommerce, payment gateway
Requires at least: 6.3
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.0.0
License: Proprietary

Take payments through Zeny payment links. Shoppers choose QRIS, a virtual account or an e-wallet on the Zeny payment page.

== Description ==

At checkout the shopper is sent to a Zeny payment page for the order and chooses how to pay there. The order is marked paid when Zeny confirms the payment with a signed webhook.

* Classic checkout and the Checkout block.
* HPOS compatible.
* Sandbox and Production, picked by the secret key.
* Indonesian translation included.

The store must sell in Indonesian rupiah (IDR).

== Installation ==

1. Upload the plugin zip under Plugins > Add New Plugin > Upload Plugin and activate it.
2. Go to WooCommerce > Settings > Payments > Zeny.
3. Paste your secret API key (sk_test_ for Sandbox, sk_live_ for Production).
4. In the Zeny dashboard, add a webhook endpoint with the Webhook URL shown on the settings page and the payment.paid event.
5. Paste the endpoint's whsec_ secret into Webhook secret and save.

== Frequently Asked Questions ==

= Can I refund from WooCommerce? =

No. Refund from the Zeny dashboard, then mark the order refunded in WooCommerce.

= Why is Zeny missing at checkout? =

The store currency is not IDR, the gateway is off, or there is no valid secret key. An admin notice says which.

= The webhook did not arrive. =

Open the order, enter the Zeny payment id (pay_...) in the Zeny box and click Confirm payment.

== Changelog ==

= 1.0.0 =
* First release: payment-link checkout, webhook order completion, manual confirmation, link cancel on order cancel, Indonesian translation.
