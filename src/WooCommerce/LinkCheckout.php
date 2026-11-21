<?php
/**
 * Checkout through a payment link.
 *
 * @package Zeny\WooCommerce
 */

namespace Zeny\WooCommerce\WooCommerce;

use InvalidArgumentException;
use JsonException;
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
 * active link while it asks for the current total, or resends the same
 * request under the same idempotency key when the answer was lost. A new
 * link (attempt n+1) is made only when the previous one expired, belongs to
 * the other environment, asks for an old total (it is canceled first), or
 * its create was definitely refused. A failed read never drops a link.
 */
final class LinkCheckout {

	// A stored request this close to its expiry is not resent: the link would be dead on arrival.
	private const MIN_REMAINING_SEC = 60;

	// Idempotency keys are kept for 24 hours; a replay later than this could create a second link.
	private const MAX_REPLAY_AGE_SEC = 23 * 3600;

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
		} catch ( InvalidArgumentException | JsonException $e ) {
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
	 * @throws JsonException When the link request cannot be encoded.
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
	 * A 404 drops the link; any other read error propagates and leaves it in place.
	 *
	 * @param WC_Order $order   Order.
	 * @param string   $link_id Stored link id.
	 * @throws ZenyApiException When the link cannot be read.
	 */
	private function reuse( WC_Order $order, string $link_id ): ?string {
		if ( $this->links->mode( $order ) !== $this->api->mode() ) {
			// A link of the other environment cannot be read or canceled with this key.
			$this->abandon( $order, $link_id, false );
			return null;
		}
		try {
			$link = $this->api->retrievePaymentLink( $link_id );
		} catch ( ZenyApiException $e ) {
			if ( 404 !== $e->statusCode() ) {
				throw $e;
			}
			// This key cannot reach the link (it belongs to another project): it is gone for
			// this shop. Its id stays in the order's link list, so a payment through it still finds the order.
			$this->logger->error(
				'The stored payment link is not found with this key; starting a new one.',
				array(
					'order' => $order->get_id(),
					'link'  => $link_id,
				)
			);
			$this->abandon( $order, $link_id, false );
			return null;
		}
		if ( (int) ( $link['paidCount'] ?? 0 ) > 0 ) {
			// Paid, and the webhook is on its way: never take a second payment.
			return $order->get_checkout_order_received_url();
		}
		if ( 'active' !== ( $link['status'] ?? '' ) ) {
			$this->abandon( $order, $link_id, false );
			return null;
		}
		$amount = Money::fromApi( $link['amount'] ?? null );
		if ( null === $amount || ! $amount->equals( self::total( $order ) ) ) {
			// The order changed since the link was made (a resumed cart): the link must not take the old amount.
			$this->abandon( $order, $link_id, true );
			return null;
		}
		$this->logger->debug(
			'Reusing the active payment link.',
			array(
				'order' => $order->get_id(),
				'link'  => $link_id,
			)
		);
		return $this->links->linkUrl( $order );
	}

	/**
	 * Stops using a link and moves to the next attempt. An active link is
	 * canceled first, best effort: a link that cannot be canceled stays in the
	 * order's link list, so a payment through it still reaches the order.
	 *
	 * @param WC_Order $order   Order.
	 * @param string   $link_id Link id.
	 * @param bool     $cancel  Cancel the link first.
	 */
	private function abandon( WC_Order $order, string $link_id, bool $cancel ): void {
		if ( $cancel ) {
			try {
				$this->api->cancelPaymentLink( $link_id );
				/* translators: %s: payment link id. */
				$order->add_order_note( sprintf( __( 'Zeny payment link %s canceled because the order total changed.', 'zeny' ), $link_id ) );
			} catch ( ZenyApiException $e ) {
				$this->logger->error(
					'Could not cancel the replaced payment link.',
					array(
						'order'     => $order->get_id(),
						'link'      => $link_id,
						'code'      => $e->errorCode(),
						'requestId' => $e->requestId(),
					)
				);
				/* translators: 1: payment link id, 2: error code. */
				$order->add_order_note( sprintf( __( 'Zeny could not cancel the replaced payment link %1$s (%2$s). A payment through it is put on hold for you to check.', 'zeny' ), $link_id, $e->errorCode() ) );
			}
		}
		$this->links->nextAttempt( $order );
		$order->save();
	}

	/**
	 * Creates the link of the current attempt and stores it on the order.
	 *
	 * @param WC_Order $order Order.
	 * @throws ZenyApiException When the API call fails.
	 * @throws JsonException When the body cannot be encoded.
	 */
	private function create( WC_Order $order ): string {
		$body = $this->requestBody( $order );
		$key  = IdempotencyKey::forOrderAttempt( $this->siteHash, $order->get_id(), $this->links->attempt( $order ) );
		try {
			$link = $this->api->createPaymentLink( $body, $key );
		} catch ( JsonException $e ) {
			// Never sent, so nothing to replay: drop the stored body, or every resubmit fails the same way.
			$this->links->nextAttempt( $order );
			$order->save();
			throw $e;
		} catch ( ZenyApiException $e ) {
			// A refused create did nothing: the next submit is a new attempt, so a fixed
			// setting takes effect. An unknown outcome keeps its key and body for the replay.
			if ( ! $e->isOutcomeUnknown() ) {
				$this->links->nextAttempt( $order );
				$order->save();
			}
			throw $e;
		}

		$id  = is_string( $link['id'] ?? null ) ? $link['id'] : '';
		$url = is_string( $link['url'] ?? null ) ? $link['url'] : '';
		if ( '' === $id || 0 !== strpos( $url, 'https://' ) ) {
			throw new ZenyApiException( 'api_error', 'unexpected_response', 'The payment link has no id or https URL.', 201, null, $key->value() );
		}
		$expires = is_string( $link['expiresAt'] ?? null ) ? strtotime( $link['expiresAt'] ) : false;

		$this->links->saveLink( $order, $id, $url, $this->api->mode(), false === $expires ? null : $expires );
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
	 * The body for the current attempt. The one already sent is resent when its
	 * answer was lost (the same key needs the same body), as long as it still
	 * asks for the order total, has time left, and is young enough for the API
	 * to remember its key. Otherwise a new attempt with a new body, stored
	 * before sending.
	 *
	 * @param WC_Order $order Order.
	 * @return array<string, mixed>
	 * @throws InvalidArgumentException When the order cannot be turned into a link.
	 */
	private function requestBody( WC_Order $order ): array {
		$pending = $this->links->pendingRequest( $order );
		if ( null !== $pending ) {
			if ( $this->replayable( $pending['body'], $pending['sentAt'], self::total( $order ) ) ) {
				return $pending['body'];
			}
			$this->links->nextAttempt( $order );
		}

		$now  = time();
		$body = $this->spec( $order )->toRequestBody( $now );
		$this->links->savePendingRequest( $order, $body, $now );
		$order->save();
		return $body;
	}

	/**
	 * Whether a stored request may be resent under its key.
	 *
	 * @param array<string, mixed> $body    Stored body.
	 * @param int                  $sent_at When it was first sent.
	 * @param Money                $total   Current order total.
	 */
	private function replayable( array $body, int $sent_at, Money $total ): bool {
		$now     = time();
		$expires = is_string( $body['expiresAt'] ?? null ) ? strtotime( $body['expiresAt'] ) : false;
		$amount  = Money::fromApi( $body['amount'] ?? null );
		return $now - $sent_at <= self::MAX_REPLAY_AGE_SEC
			&& false !== $expires && $expires - $now > self::MIN_REMAINING_SEC
			&& null !== $amount && $amount->equals( $total );
	}

	/**
	 * Store text with invalid UTF-8 stripped, so it can be JSON-encoded.
	 *
	 * @param string $text Text from the store.
	 */
	private static function utf8( string $text ): string {
		return wp_check_invalid_utf8( $text, true );
	}

	/**
	 * The order total as IDR money.
	 *
	 * @param WC_Order $order Order.
	 * @throws InvalidArgumentException When the total is not a plain decimal.
	 */
	private static function total( WC_Order $order ): Money {
		return Money::idr( (string) wc_format_decimal( $order->get_total(), 2 ) );
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
			$items[] = self::utf8( wp_specialchars_decode( $item->get_name(), ENT_QUOTES ) );
		}
		return new LinkSpec(
			/* translators: 1: order number, 2: shop name. */
			sprintf( __( 'Order #%1$s – %2$s', 'zeny' ), self::utf8( (string) $order->get_order_number() ), self::utf8( wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ) ) ),
			self::total( $order ),
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
		$order->add_order_note(
			/* translators: 1: error code, 2: request id. */
			sprintf( __( 'Zeny request failed while starting the payment: %1$s (request %2$s).', 'zeny' ), $e->errorCode(), $reference )
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
