<?php
/**
 * Checkout through a payment link.
 *
 * @package Zeny\WooCommerce
 */

namespace Zeny\WooCommerce\WooCommerce;

use InvalidArgumentException;
use WC_Order;
use Zeny\WooCommerce\Domain\IdempotencyKey;
use Zeny\WooCommerce\Domain\LinkSpec;
use Zeny\WooCommerce\Domain\Money;
use Zeny\WooCommerce\Infrastructure\Logger;
use Zeny\WooCommerce\Infrastructure\ZenyApi;
use Zeny\WooCommerce\Infrastructure\ZenyApiException;

/**
 * Finds or creates the order's payment link and returns where to send the
 * shopper. One order has one live link at a time: a resubmit reuses the
 * active link, or resends the same request under the same idempotency key
 * when the answer was lost, and a new link (attempt n+1) is only made once
 * the previous one expired or was canceled.
 */
final class LinkCheckout {

	// A stored request this close to its expiry is not resent: the link would be dead on arrival.
	private const MIN_REMAINING_SEC = 60;

	/**
	 * API client.
	 *
	 * @var ZenyApi
	 */
	private $api;

	/**
	 * Gateway settings.
	 *
	 * @var Settings
	 */
	private $settings;

	/**
	 * Order meta access.
	 *
	 * @var OrderLinks
	 */
	private $links;

	/**
	 * Per-order lock.
	 *
	 * @var OrderLock
	 */
	private $lock;

	/**
	 * Logger.
	 *
	 * @var Logger
	 */
	private $logger;

	/**
	 * Short hash of the site URL, for idempotency keys.
	 *
	 * @var string
	 */
	private $siteHash;

	/**
	 * Builds the checkout.
	 *
	 * @param ZenyApi    $api       API client.
	 * @param Settings   $settings  Gateway settings.
	 * @param OrderLinks $links     Order meta access.
	 * @param OrderLock  $lock      Per-order lock.
	 * @param Logger     $logger    Logger.
	 * @param string     $site_hash Short hex hash of the site URL.
	 */
	public function __construct( ZenyApi $api, Settings $settings, OrderLinks $links, OrderLock $lock, Logger $logger, string $site_hash ) {
		$this->api      = $api;
		$this->settings = $settings;
		$this->links    = $links;
		$this->lock     = $lock;
		$this->logger   = $logger;
		$this->siteHash = $site_hash;
	}

	/**
	 * Returns the URL to send the shopper to: the link's pay page, or the
	 * order-received page when the link already took the payment.
	 *
	 * @param WC_Order $order Order being paid.
	 * @throws CheckoutFailed When the shopper cannot be sent to Zeny.
	 */
	public function start( WC_Order $order ): string {
		if ( Money::IDR !== $order->get_currency() ) {
			throw new CheckoutFailed( __( 'Zeny takes payments in Indonesian rupiah (IDR) only.', 'zeny' ) );
		}
		if ( ! $this->lock->acquire( $order->get_id() ) ) {
			throw new CheckoutFailed( __( 'This order is already being sent to Zeny. Wait a moment, then try again.', 'zeny' ) );
		}
		try {
			return $this->reuseOrCreate( $order );
		} catch ( ZenyApiException $e ) {
			throw $this->apiFailure( $order, $e );
		} catch ( InvalidArgumentException $e ) {
			$this->logger->error( 'Could not build the payment link.', array( 'order' => $order->get_id() ) );
			throw new CheckoutFailed( __( 'We could not start the Zeny payment for this order. Contact us to complete your order.', 'zeny' ) );
		} finally {
			$this->lock->release( $order->get_id() );
		}
	}

	/**
	 * Reuses the stored link when it can still take the payment, else creates one.
	 *
	 * @param WC_Order $order Order.
	 * @throws ZenyApiException When an API call fails.
	 * @throws InvalidArgumentException When the order cannot be turned into a link.
	 */
	private function reuseOrCreate( WC_Order $order ): string {
		$link_id = $this->links->linkId( $order );
		if ( '' !== $link_id ) {
			$redirect = $this->reuse( $order, $link_id );
			if ( null !== $redirect ) {
				return $redirect;
			}
		}
		return $this->create( $order );
	}

	/**
	 * Where to send the shopper for an existing link, or null to make a new one.
	 *
	 * @param WC_Order $order   Order.
	 * @param string   $link_id Stored link id.
	 * @throws ZenyApiException When the link cannot be read.
	 */
	private function reuse( WC_Order $order, string $link_id ): ?string {
		// A link of the other environment cannot be read with this key; start over in this one.
		if ( $this->links->mode( $order ) === $this->api->mode() ) {
			$link = $this->api->retrievePaymentLink( $link_id );
			if ( (int) ( $link['paidCount'] ?? 0 ) > 0 ) {
				// Paid, and the webhook is on its way: never take a second payment.
				return $order->get_checkout_order_received_url();
			}
			if ( 'active' === ( $link['status'] ?? '' ) ) {
				$this->logger->debug(
					'Reusing the active payment link.',
					array(
						'order' => $order->get_id(),
						'link'  => $link_id,
					)
				);
				return $this->links->linkUrl( $order );
			}
		}
		$this->links->nextAttempt( $order );
		$order->save();
		return null;
	}

	/**
	 * Creates the link of the current attempt and stores it on the order.
	 *
	 * @param WC_Order $order Order.
	 * @throws ZenyApiException When the API call fails.
	 */
	private function create( WC_Order $order ): string {
		$body = $this->requestBody( $order );
		$key  = IdempotencyKey::forOrderAttempt( $this->siteHash, $order->get_id(), $this->links->attempt( $order ) );
		$link = $this->api->createPaymentLink( $body, $key );

		$id  = is_string( $link['id'] ?? null ) ? $link['id'] : '';
		$url = is_string( $link['url'] ?? null ) ? $link['url'] : '';
		if ( '' === $id || 0 !== strpos( $url, 'https://' ) ) {
			throw new ZenyApiException( 'api_error', 'unexpected_response', 'The payment link has no id or https URL.', 201, null, $key->value() );
		}

		$this->links->saveLink( $order, $id, $url, $this->api->mode() );
		if ( ! $order->has_status( 'pending' ) ) {
			$order->set_status( 'pending' );
		}
		$order->add_order_note(
			/* translators: 1: payment link id, 2: Sandbox or Production. */
			sprintf( __( 'Zeny payment link %1$s created (%2$s). Waiting for the shopper to pay.', 'zeny' ), $id, self::modeLabel( $this->api->mode() ) )
		);
		$order->save();
		$this->logger->debug(
			'Payment link created.',
			array(
				'order' => $order->get_id(),
				'link'  => $id,
			)
		);
		return $url;
	}

	/**
	 * The body for the current attempt: the one already sent when its answer
	 * was lost (the same key needs the same body), else a new one, stored before sending.
	 *
	 * @param WC_Order $order Order.
	 * @return array<string, mixed>
	 * @throws InvalidArgumentException When the order cannot be turned into a link.
	 */
	private function requestBody( WC_Order $order ): array {
		$pending = $this->links->pendingRequest( $order );
		if ( null !== $pending ) {
			$expires = is_string( $pending['expiresAt'] ?? null ) ? strtotime( $pending['expiresAt'] ) : false;
			if ( false !== $expires && $expires - time() > self::MIN_REMAINING_SEC ) {
				return $pending;
			}
			$this->links->nextAttempt( $order );
		}

		$body = $this->spec( $order )->toRequestBody( time() );
		$this->links->savePendingRequest( $order, $body );
		$order->save();
		return $body;
	}

	/**
	 * The link spec for an order.
	 *
	 * @param WC_Order $order Order.
	 * @throws InvalidArgumentException When the total is not a positive amount.
	 */
	private function spec( WC_Order $order ): LinkSpec {
		$items = array();
		foreach ( $order->get_items() as $item ) {
			$items[] = wp_specialchars_decode( $item->get_name(), ENT_QUOTES );
		}
		return new LinkSpec(
			/* translators: 1: order number, 2: shop name. */
			sprintf( __( 'Order #%1$s – %2$s', 'zeny' ), $order->get_order_number(), wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ) ),
			Money::idr( (string) wc_format_decimal( $order->get_total(), 2 ) ),
			$items,
			$order->get_checkout_order_received_url(),
			$order->get_checkout_payment_url(),
			$this->settings->lifetimeMinutes(),
			$this->settings->instruments(),
			$this->settings->requiredPayerFields()
		);
	}

	/**
	 * Records an API failure on the order and turns it into a shopper message.
	 *
	 * @param WC_Order         $order Order.
	 * @param ZenyApiException $e     Failure.
	 */
	private function apiFailure( WC_Order $order, ZenyApiException $e ): CheckoutFailed {
		$reference = $e->requestId() ?? $e->idempotencyKey() ?? '';
		$this->logger->error(
			'Payment link request failed.',
			array(
				'order'     => $order->get_id(),
				'status'    => $e->statusCode(),
				'code'      => $e->errorCode(),
				'requestId' => $e->requestId(),
			)
		);
		// The key was used for another body; only a new attempt can go through.
		if ( 'idempotency_key_reused' === $e->errorCode() ) {
			$this->links->nextAttempt( $order );
		}
		$order->add_order_note(
			/* translators: 1: error code, 2: request id. */
			sprintf( __( 'Zeny could not create the payment link: %1$s (request %2$s).', 'zeny' ), $e->errorCode(), $reference )
		);
		$order->save();

		/* translators: %s: reference to quote to support. */
		return new CheckoutFailed( sprintf( __( 'We could not start the Zeny payment. Please try again. If it keeps failing, contact us and quote reference %s.', 'zeny' ), $reference ) );
	}

	/**
	 * Sandbox or Production.
	 *
	 * @param string $mode Mode.
	 */
	public static function modeLabel( string $mode ): string {
		return 'live' === $mode ? __( 'Production', 'zeny' ) : __( 'Sandbox', 'zeny' );
	}
}
