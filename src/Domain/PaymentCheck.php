<?php
/**
 * Rules a payment must meet to pay an order.
 *
 * @package Zeny\WooCommerce
 */

namespace Zeny\WooCommerce\Domain;

/**
 * Decides whether a payment pays an order. It must come from the key's
 * environment, be paid through the order's own link, and have collected
 * exactly the order total in IDR. Anything else is a problem for a person to
 * look at, never a paid order.
 */
final class PaymentCheck {

	public const MODE_MISMATCH   = 'mode_mismatch';
	public const LINK_MISMATCH   = 'link_mismatch';
	public const NOT_PAID        = 'payment_not_paid';
	public const AMOUNT_MISMATCH = 'amount_mismatch';

	/**
	 * The first problem found, or null when the payment pays the order.
	 *
	 * @param array<mixed> $payment       Payment object from the API.
	 * @param string       $payment_mode  Mode the payment was read in (event mode, or the key's for an API read).
	 * @param string       $link_id       Link id stored on the order.
	 * @param Money        $order_total   Order total.
	 * @param string       $expected_mode Mode of the configured key.
	 */
	public static function problem( array $payment, string $payment_mode, string $link_id, Money $order_total, string $expected_mode ): ?string {
		if ( null === Mode::fromString( $payment_mode ) || $payment_mode !== $expected_mode ) {
			return self::MODE_MISMATCH;
		}
		if ( '' === $link_id || ( $payment['paymentLink'] ?? null ) !== $link_id ) {
			return self::LINK_MISMATCH;
		}
		if ( 'paid' !== ( $payment['status'] ?? null ) ) {
			return self::NOT_PAID;
		}
		// amountReceived is what was collected; amount is only what was asked.
		$received = Money::fromApi( $payment['amountReceived'] ?? null );
		if ( null === $received || ! $received->equals( $order_total ) ) {
			return self::AMOUNT_MISMATCH;
		}
		return null;
	}
}
