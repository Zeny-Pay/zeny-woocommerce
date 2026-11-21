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
	 * Cancels the order's link, if it has one in the key's environment.
	 *
	 * @param WC_Order $order Cancelled order.
	 */
	public function cancel( WC_Order $order ): void {
		$link_id = $this->links->linkId( $order );
		if ( Gateway::ID !== $order->get_payment_method() || '' === $link_id || $this->links->mode( $order ) !== $this->api->mode() ) {
			return;
		}
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
			$note = 'payment_link_has_payments' === $e->errorCode()
				/* translators: %s: payment link id. */
				? __( 'Zeny payment link %s took a payment, so it was not canceled. Check the payment in the Zeny dashboard and refund it there if needed.', 'zeny' )
				/* translators: %s: payment link id. */
				: __( 'Zeny could not cancel payment link %s. It stops taking payments when it expires.', 'zeny' );
			$order->add_order_note( sprintf( $note, $link_id ) );
		}
	}
}
