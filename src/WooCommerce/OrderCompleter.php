<?php
/**
 * Marks orders paid from verified payments.
 *
 * @package Zeny\WooCommerce
 */

namespace Zeny\WooCommerce\WooCommerce;

use WC_Order;
use Zeny\WooCommerce\Domain\Money;
use Zeny\WooCommerce\Domain\PaymentCheck;

/**
 * The one place an order becomes paid. It takes a payment that is already
 * verified (a signed webhook, or a read from the API) and completes the order
 * only when PaymentCheck passes. It never completes an order twice: an order
 * already paid by the same payment is left alone, another payment on a paid
 * order and money taken for a cancelled order are put to the merchant.
 * Callers hold the order's lock and pass a freshly loaded order.
 */
final class OrderCompleter {

	public const COMPLETED   = 'completed';
	public const ALREADY     = 'already_paid';
	public const DUPLICATE   = 'duplicate_event';
	public const HELD        = 'on_hold';
	public const EXTRA       = 'extra_payment';
	public const NOT_ALLOWED = 'not_allowed';

	/**
	 * Order meta access.
	 *
	 * @var OrderLinks
	 */
	private $links;

	/**
	 * Mode of the configured key.
	 *
	 * @var string
	 */
	private $keyMode;

	/**
	 * Builds the completer.
	 *
	 * @param OrderLinks $links    Order meta access.
	 * @param string     $key_mode Mode of the configured key.
	 */
	public function __construct( OrderLinks $links, string $key_mode ) {
		$this->links   = $links;
		$this->keyMode = $key_mode;
	}

	/**
	 * Applies a payment.paid event. Saves the order.
	 *
	 * @param WC_Order     $order    Order holding the payment's link.
	 * @param array<mixed> $payment  data.object of the event.
	 * @param string       $mode     Mode of the event.
	 * @param string       $event_id Event id, stored so a redelivery is skipped.
	 * @return string One of the outcome constants.
	 */
	public function applyEvent( WC_Order $order, array $payment, string $mode, string $event_id ): string {
		if ( $this->links->hasEvent( $order, $event_id ) ) {
			return self::DUPLICATE;
		}
		$outcome = $this->apply( $order, $payment, $mode, true );
		$this->links->addEvent( $order, $event_id );
		$order->save();
		return $outcome;
	}

	/**
	 * Applies a payment an admin confirmed by id, read from the API with the
	 * configured key. A payment that does not pay the order changes nothing.
	 * Saves the order.
	 *
	 * @param WC_Order     $order   Order.
	 * @param array<mixed> $payment Payment from GET /payments/{id}.
	 * @return string One of the outcome constants.
	 */
	public function applyConfirmed( WC_Order $order, array $payment ): string {
		$outcome = $this->apply( $order, $payment, $this->keyMode, false );
		$order->save();
		return $outcome;
	}

	/**
	 * Shared rules.
	 *
	 * @param WC_Order     $order   Order.
	 * @param array<mixed> $payment Payment object.
	 * @param string       $mode    Mode the payment came from.
	 * @param bool         $hold    Put a mismatch on hold (webhook) or refuse it (manual).
	 */
	private function apply( WC_Order $order, array $payment, string $mode, bool $hold ): string {
		$payment_id = is_string( $payment['id'] ?? null ) ? $payment['id'] : '';
		$paid_by    = $order->get_transaction_id();

		if ( $order->is_paid() || '' !== $paid_by ) {
			if ( $paid_by === $payment_id ) {
				return self::ALREADY;
			}
			$order->add_order_note(
				/* translators: 1: new payment id, 2: payment id the order was paid with. */
				sprintf( __( 'Zeny payment %1$s arrived for this order, which is already paid by %2$s. The shopper may have paid twice: check both payments in the Zeny dashboard and refund one there.', 'zeny' ), $payment_id, $paid_by )
			);
			return self::EXTRA;
		}

		$problem = $this->problem( $order, $payment, $mode );
		if ( null === $problem && $order->has_status( array( 'cancelled', 'failed', 'refunded' ) ) ) {
			$problem = 'order_' . $order->get_status();
		}
		if ( null !== $problem ) {
			if ( ! $hold ) {
				return self::NOT_ALLOWED;
			}
			$order->update_status( 'on-hold', self::problemNote( $problem, $payment_id ) );
			return self::HELD;
		}

		$order->add_order_note(
			/* translators: 1: payment id, 2: Sandbox or Production. */
			sprintf( __( 'Zeny payment %1$s received (%2$s).', 'zeny' ), $payment_id, LinkCheckout::modeLabel( $mode ) )
		);
		$order->payment_complete( $payment_id );
		return self::COMPLETED;
	}

	/**
	 * The PaymentCheck problem, or null.
	 *
	 * @param WC_Order     $order   Order.
	 * @param array<mixed> $payment Payment.
	 * @param string       $mode    Mode the payment came from.
	 */
	private function problem( WC_Order $order, array $payment, string $mode ): ?string {
		if ( Money::IDR !== $order->get_currency() ) {
			return PaymentCheck::AMOUNT_MISMATCH;
		}
		$total = Money::idr( (string) wc_format_decimal( $order->get_total(), 2 ) );
		return PaymentCheck::problem( $payment, $mode, $this->links->linkId( $order ), $total, $this->keyMode );
	}

	/**
	 * The order note for a payment that cannot pay the order.
	 *
	 * @param string $problem    Problem code.
	 * @param string $payment_id Payment id.
	 */
	private static function problemNote( string $problem, string $payment_id ): string {
		switch ( $problem ) {
			case PaymentCheck::AMOUNT_MISMATCH:
				/* translators: %s: payment id. */
				$reason = __( 'Zeny payment %s does not match the order total in IDR.', 'zeny' );
				break;
			case PaymentCheck::MODE_MISMATCH:
				/* translators: %s: payment id. */
				$reason = __( 'Zeny payment %s comes from another environment (Sandbox or Production) than the saved API key.', 'zeny' );
				break;
			case PaymentCheck::LINK_MISMATCH:
				/* translators: %s: payment id. */
				$reason = __( 'Zeny payment %s was not made through this order\'s payment link.', 'zeny' );
				break;
			case PaymentCheck::NOT_PAID:
				/* translators: %s: payment id. */
				$reason = __( 'Zeny payment %s is not paid.', 'zeny' );
				break;
			default:
				/* translators: %s: payment id. */
				$reason = __( 'Zeny payment %s was received after the order was cancelled.', 'zeny' );
		}
		return sprintf( $reason, $payment_id ) . ' ' . __( 'The order was not marked paid. Check the payment in the Zeny dashboard, then complete or refund it.', 'zeny' );
	}
}
