<?php
/**
 * Webhook endpoint.
 *
 * @package Zeny\WooCommerce
 */

namespace Zeny\WooCommerce\WooCommerce;

use Throwable;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;
use Zeny\WooCommerce\Domain\WebhookEvent;
use Zeny\WooCommerce\Domain\WebhookVerificationFailed;
use Zeny\WooCommerce\Domain\WebhookVerifier;

/**
 * POST /wp-json/zeny/v1/webhook. The signature is the authentication, so the
 * route is public. It answers 200 only once the order is saved (or the event
 * needs nothing), 400 for a request that fails verification, and 500 whenever
 * it could not act yet, so Zeny delivers the event again.
 */
final class WebhookController {

	public const NAMESPACE = 'zeny/v1';
	public const ROUTE     = '/webhook';

	/**
	 * Registers the route.
	 */
	public static function register(): void {
		register_rest_route(
			self::NAMESPACE,
			self::ROUTE,
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( self::class, 'handle' ),
				'permission_callback' => '__return_true',
			)
		);
	}

	/**
	 * The URL to add in the Zeny dashboard.
	 */
	public static function url(): string {
		return rest_url( self::NAMESPACE . self::ROUTE );
	}

	/**
	 * Handles one delivery.
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public static function handle( WP_REST_Request $request ): WP_REST_Response {
		$logger = Plugin::logger();
		$secret = Plugin::settings()->webhookSecret();
		if ( null === $secret ) {
			$logger->error( 'Webhook received, but no webhook secret is saved.' );
			return self::answer( 500, 'not_configured' );
		}

		try {
			$event = ( new WebhookVerifier( $secret ) )->verify(
				$request->get_body(),
				$request->get_header( 'webhook_signature' ),
				$request->get_header( 'webhook_timestamp' ),
				time()
			);
		} catch ( WebhookVerificationFailed $e ) {
			$logger->debug( 'Webhook rejected.', array( 'reason' => $e->getMessage() ) );
			return self::answer( 400, $e->getMessage() );
		}

		if ( WebhookEvent::PAYMENT_PAID !== $event->type() ) {
			return self::answer( 200, 'ignored' );
		}

		try {
			return self::paymentPaid( $event );
		} catch ( Throwable $e ) {
			$logger->error( 'Webhook could not be processed.', array( 'event' => $event->id() ) );
			return self::answer( 500, 'error' );
		}
	}

	/**
	 * Applies payment.paid to the order holding the payment's link.
	 *
	 * @param WebhookEvent $event Verified event.
	 */
	private static function paymentPaid( WebhookEvent $event ): WP_REST_Response {
		$logger  = Plugin::logger();
		$payment = $event->object();
		$link_id = is_string( $payment['paymentLink'] ?? null ) ? $payment['paymentLink'] : '';
		if ( 0 !== strpos( $link_id, 'pl_' ) ) {
			// A payment created through the API, not a link: not a WooCommerce order.
			return self::answer( 200, 'ignored' );
		}

		$api   = Plugin::api();
		$links = new OrderLinks();
		$order = $links->findByLinkId( $link_id );
		if ( null === $api || null === $order ) {
			// The event can beat process_payment storing the link. Zeny retries, by then it is stored.
			$logger->error(
				'No order holds the paid link yet.',
				array(
					'event' => $event->id(),
					'link'  => $link_id,
				)
			);
			return self::answer( 500, 'order_not_found' );
		}

		/**
		 * Filters how long a webhook waits for an order another request is working on.
		 *
		 * @param int $wait_ms Milliseconds.
		 */
		$lock = new OrderLock( (int) apply_filters( 'zeny_webhook_lock_wait_ms', 10000 ) );
		if ( ! $lock->acquire( $order->get_id() ) ) {
			return self::answer( 500, 'order_busy' );
		}
		try {
			$fresh = wc_get_order( $order->get_id() );
			if ( ! $fresh instanceof \WC_Order ) {
				return self::answer( 500, 'order_not_found' );
			}
			$outcome = ( new OrderCompleter( $links, $api->mode() ) )->applyEvent( $fresh, $payment, $event->mode(), $event->id() );
		} finally {
			$lock->release( $order->get_id() );
		}
		$logger->debug(
			'Webhook applied.',
			array(
				'event'   => $event->id(),
				'order'   => $order->get_id(),
				'outcome' => $outcome,
			)
		);
		return self::answer( 200, $outcome );
	}

	/**
	 * A small JSON answer.
	 *
	 * @param int    $status HTTP status.
	 * @param string $result Short code.
	 */
	private static function answer( int $status, string $result ): WP_REST_Response {
		return new WP_REST_Response( array( 'result' => $result ), $status );
	}
}
