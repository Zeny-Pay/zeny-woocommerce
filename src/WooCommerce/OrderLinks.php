<?php
/**
 * Zeny data stored on orders.
 *
 * @package Zeny\WooCommerce
 */

namespace Zeny\WooCommerce\WooCommerce;

use WC_Order;

/**
 * Reads and writes the plugin's order meta through the order API, so it
 * works with HPOS and the posts table alike. Setters do not save the order.
 */
final class OrderLinks {

	public const LINK_ID  = '_zeny_payment_link_id';
	public const LINK_URL = '_zeny_payment_link_url';
	public const MODE     = '_zeny_mode';
	public const ATTEMPT  = '_zeny_link_attempt';
	public const REQUEST  = '_zeny_link_request';
	public const EVENTS   = '_zeny_event_ids';

	// Enough to recognise a redelivery; an order sees a handful of events.
	private const EVENTS_KEPT = 50;

	/**
	 * Link id (pl_…), "" when none.
	 *
	 * @param WC_Order $order Order.
	 */
	public function linkId( WC_Order $order ): string {
		return (string) $order->get_meta( self::LINK_ID );
	}

	/**
	 * Link URL, "" when none.
	 *
	 * @param WC_Order $order Order.
	 */
	public function linkUrl( WC_Order $order ): string {
		return (string) $order->get_meta( self::LINK_URL );
	}

	/**
	 * Mode the link was created in, "" when none.
	 *
	 * @param WC_Order $order Order.
	 */
	public function mode( WC_Order $order ): string {
		return (string) $order->get_meta( self::MODE );
	}

	/**
	 * Current checkout attempt, from 1.
	 *
	 * @param WC_Order $order Order.
	 */
	public function attempt( WC_Order $order ): int {
		return max( 1, (int) $order->get_meta( self::ATTEMPT ) );
	}

	/**
	 * Records the link created for the current attempt.
	 *
	 * @param WC_Order $order Order.
	 * @param string   $id    Link id.
	 * @param string   $url   Link URL.
	 * @param string   $mode  Key mode.
	 */
	public function saveLink( WC_Order $order, string $id, string $url, string $mode ): void {
		$order->update_meta_data( self::LINK_ID, $id );
		$order->update_meta_data( self::LINK_URL, $url );
		$order->update_meta_data( self::MODE, $mode );
		$order->update_meta_data( self::ATTEMPT, (string) $this->attempt( $order ) );
		$order->delete_meta_data( self::REQUEST );
	}

	/**
	 * Moves to the next attempt, dropping the previous link, so the next
	 * checkout creates a new link under a new idempotency key.
	 *
	 * @param WC_Order $order Order.
	 */
	public function nextAttempt( WC_Order $order ): void {
		$order->update_meta_data( self::ATTEMPT, (string) ( $this->attempt( $order ) + 1 ) );
		$order->delete_meta_data( self::LINK_ID );
		$order->delete_meta_data( self::LINK_URL );
		$order->delete_meta_data( self::REQUEST );
	}

	/**
	 * The request body sent for the current attempt, if one was sent and no
	 * link came back. Resending it unchanged lets the API replay the result.
	 *
	 * @param WC_Order $order Order.
	 * @return array<string, mixed>|null
	 */
	public function pendingRequest( WC_Order $order ): ?array {
		$stored = $order->get_meta( self::REQUEST );
		if ( ! is_array( $stored ) || ( $stored['attempt'] ?? 0 ) !== $this->attempt( $order ) || ! is_array( $stored['body'] ?? null ) ) {
			return null;
		}
		return $stored['body'];
	}

	/**
	 * Stores the body about to be sent for the current attempt.
	 *
	 * @param WC_Order             $order Order.
	 * @param array<string, mixed> $body  Request body.
	 */
	public function savePendingRequest( WC_Order $order, array $body ): void {
		$order->update_meta_data(
			self::REQUEST,
			array(
				'attempt' => $this->attempt( $order ),
				'body'    => $body,
			)
		);
	}

	/**
	 * Whether a webhook event was already handled for this order.
	 *
	 * @param WC_Order $order    Order.
	 * @param string   $event_id Event id.
	 */
	public function hasEvent( WC_Order $order, string $event_id ): bool {
		return in_array( $event_id, $this->events( $order ), true );
	}

	/**
	 * Records a handled webhook event.
	 *
	 * @param WC_Order $order    Order.
	 * @param string   $event_id Event id.
	 */
	public function addEvent( WC_Order $order, string $event_id ): void {
		$events   = $this->events( $order );
		$events[] = $event_id;
		$order->update_meta_data( self::EVENTS, array_slice( $events, -self::EVENTS_KEPT ) );
	}

	/**
	 * The order that holds a link, or null.
	 *
	 * @param string $link_id Link id.
	 */
	public function findByLinkId( string $link_id ): ?WC_Order {
		if ( '' === $link_id ) {
			return null;
		}
		$orders = wc_get_orders(
			array(
				'limit'      => 1,
				'type'       => 'shop_order',
				'meta_query' => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- one indexed lookup per webhook.
					array(
						'key'   => self::LINK_ID,
						'value' => $link_id,
					),
				),
			)
		);
		$order  = is_array( $orders ) ? reset( $orders ) : false;
		return $order instanceof WC_Order ? $order : null;
	}

	/**
	 * Handled event ids.
	 *
	 * @param WC_Order $order Order.
	 * @return list<string>
	 */
	private function events( WC_Order $order ): array {
		$events = $order->get_meta( self::EVENTS );
		return is_array( $events ) ? array_values( array_map( 'strval', $events ) ) : array();
	}
}
