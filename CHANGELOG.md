# Changelog

## 1.0.0

- Zeny payment gateway for WooCommerce, classic checkout and Checkout block.
- One payment link per order, reused on resubmit; idempotent create with retries.
- Orders completed from verified `payment.paid` webhooks; mismatched payments go on hold.
- Order-received page waits for the confirmation; manual confirmation by payment id.
- Payment link canceled when WooCommerce cancels an unpaid order.
- Settings with masked secrets, mode badge, webhook URL and connection test.
- HPOS compatible. Indonesian translation.
