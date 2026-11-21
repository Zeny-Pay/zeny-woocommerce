<?php
/**
 * Cancels the link of a cancelled order.
 *
 * @package Zeny\WooCommerce
 */

namespace Zeny\WooCommerce\WooCommerce;

use WC_Order;
use Zeny\WooCommerce\Infrastructure\Logger;
use Zeny\WooCommerce\Infrastructure\ZenyApi;
use Zeny\WooCommerce\Infrastructure\ZenyApiException;

/**
 * When WooCommerce cancels an unpaid order (hold-stock timeout or an admin),
 * the order's link is canceled too, so no one pays an order that is gone.
 * Best effort: a link that already took a payment cannot be canceled, and
 * the merchant is told to look at it.
 */
final class LinkCanceller {

	/**
	 * API client.
	 *
	 * @var ZenyApi
	 */
	private $api;

	/**
	 * Order meta access.
	 *
	 * @var OrderLinks
	 */
	private $links;

	/**
	 * Logger.
	 *
	 * @var Logger
	 */
	private $logger;

	/**
	 * Builds the canceller.
	 *
	 * @param ZenyApi    $api    API client.
	 * @param OrderLinks $links  Order meta access.
	 * @param Logger     $logger Logger.
	 */
	public function __construct( ZenyApi $api, OrderLinks $links, Logger $logger ) {
		$this->api    = $api;
		$this->links  = $links;
		$this->logger = $logger;
	}

	/**
	 * Cancels every link the order had in the key's environment: a replaced
	 * link whose cancel failed earlier may still take a payment. Links of the
	 * other environment cannot be reached with this key and are skipped.
	 *
	 * @param WC_Order $order Cancelled order.
	 */
	public function cancel( WC_Order $order ): void {
		if ( Gateway::ID !== $order->get_payment_method() ) {
			return;
		}
		$current = $this->links->linkId( $order );
		foreach ( $this->links->linkIds( $order ) as $link_id ) {
			if ( $this->links->linkMode( $order, $link_id ) === $this->api->mode() ) {
				$this->cancelOne( $order, $link_id, $link_id === $current );
			}
		}
	}

	/**
	 * Cancels one link, best effort. Only the current link gets a note when the
	 * cancel fails for another reason than a payment: older links are mostly
	 * expired or canceled already, and the failure is logged either way.
	 *
	 * @param WC_Order $order   Order.
	 * @param string   $link_id Link id.
	 * @param bool     $current Whether it is the order's current link.
	 */
	private function cancelOne( WC_Order $order, string $link_id, bool $current ): void {
		try {
			$this->api->cancelPaymentLink( $link_id );
			/* translators: %s: payment link id. */
			$order->add_order_note( sprintf( __( 'Zeny payment link %s canceled.', 'zeny' ), $link_id ) );
		} catch ( ZenyApiException $e ) {
			$this->logger->error(
				'Could not cancel the payment link.',
				array(
					'order'     => $order->get_id(),
					'link'      => $link_id,
					'code'      => $e->errorCode(),
					'requestId' => $e->requestId(),
				)
			);
			if ( 'payment_link_has_payments' === $e->errorCode() ) {
				/* translators: %s: payment link id. */
				$order->add_order_note( sprintf( __( 'Zeny payment link %s took a payment, so it was not canceled. Check the payment in the Zeny dashboard and refund it there if needed.', 'zeny' ), $link_id ) );
			} elseif ( $current ) {
				/* translators: %s: payment link id. */
				$order->add_order_note( sprintf( __( 'Zeny could not cancel payment link %s. It stops taking payments when it expires.', 'zeny' ), $link_id ) );
			}
		}
	}
}
