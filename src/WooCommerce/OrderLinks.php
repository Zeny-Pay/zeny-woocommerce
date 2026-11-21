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

	public const LINK_ID      = '_zeny_payment_link_id';
	public const LINK_URL     = '_zeny_payment_link_url';
	public const LINK_EXPIRES = '_zeny_link_expires_at';
	public const MODE         = '_zeny_mode';
	public const ATTEMPT      = '_zeny_link_attempt';
	public const REQUEST      = '_zeny_link_request';
	public const EVENTS       = '_zeny_event_ids';
	// One meta row per link the order ever had, so a payment through any of them finds the order.
	public const ALL_LINKS = '_zeny_payment_link';

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
	 * Every link id the order ever had, oldest first.
	 *
	 * @param WC_Order $order Order.
	 * @return list<string>
	 */
	public function linkIds( WC_Order $order ): array {
		$saved   = array();
		$unsaved = array();
		foreach ( $order->get_meta( self::ALL_LINKS, false ) as $meta ) {
			$data = $meta instanceof \WC_Meta_Data ? $meta->get_data() : array();
			if ( ! is_string( $data['value'] ?? null ) || '' === $data['value'] ) {
				continue;
			}
			// The two order stores return meta rows in different orders; row ids follow insertion.
			if ( empty( $data['id'] ) ) {
				$unsaved[] = $data['value'];
			} else {
				$saved[ (int) $data['id'] ] = $data['value'];
			}
		}
		ksort( $saved );
		return array_merge( array_values( $saved ), $unsaved );
	}

	/**
	 * When the current link stops taking payments (Unix time), or null when unknown or never.
	 *
	 * @param WC_Order $order Order.
	 */
	public function linkExpiresAt( WC_Order $order ): ?int {
		$value = $order->get_meta( self::LINK_EXPIRES );
		return is_numeric( $value ) ? (int) $value : null;
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
	 * @param int|null $expires_at When the link expires (Unix time), null for never.
	 */
	public function saveLink( WC_Order $order, string $id, string $url, string $mode, ?int $expires_at ): void {
		if ( ! in_array( $id, $this->linkIds( $order ), true ) ) {
			$order->add_meta_data( self::ALL_LINKS, $id );
		}
		$order->update_meta_data( self::LINK_ID, $id );
		$order->update_meta_data( self::LINK_URL, $url );
		if ( null === $expires_at ) {
			$order->delete_meta_data( self::LINK_EXPIRES );
		} else {
			$order->update_meta_data( self::LINK_EXPIRES, (string) $expires_at );
		}
		$order->update_meta_data( self::MODE, $mode );
		$order->update_meta_data( self::ATTEMPT, (string) $this->attempt( $order ) );
		$order->delete_meta_data( self::REQUEST );
	}

	/**
	 * Moves to the next attempt, so the next checkout creates a new link under
	 * a new idempotency key. The current link stops being current but stays in
	 * linkIds(), so a late payment through it still finds the order.
	 *
	 * @param WC_Order $order Order.
	 */
	public function nextAttempt( WC_Order $order ): void {
		$order->update_meta_data( self::ATTEMPT, (string) ( $this->attempt( $order ) + 1 ) );
		$order->delete_meta_data( self::LINK_ID );
		$order->delete_meta_data( self::LINK_URL );
		$order->delete_meta_data( self::LINK_EXPIRES );
		$order->delete_meta_data( self::REQUEST );
	}

	/**
	 * The create request sent for the current attempt when no link came back,
	 * with when it was sent. Resending the body unchanged lets the API replay
	 * the result.
	 *
	 * @param WC_Order $order Order.
	 * @return array{body: array<string, mixed>, sentAt: int}|null
	 */
	public function pendingRequest( WC_Order $order ): ?array {
		$stored = $order->get_meta( self::REQUEST );
		if ( ! is_array( $stored ) || ( $stored['attempt'] ?? 0 ) !== $this->attempt( $order ) || ! is_array( $stored['body'] ?? null ) ) {
			return null;
		}
		return array(
			'body'   => $stored['body'],
			'sentAt' => (int) ( $stored['sentAt'] ?? 0 ),
		);
	}

	/**
	 * Stores the body about to be sent for the current attempt, and when.
	 *
	 * @param WC_Order             $order   Order.
	 * @param array<string, mixed> $body    Request body.
	 * @param int                  $sent_at Unix time of sending.
	 */
	public function savePendingRequest( WC_Order $order, array $body, int $sent_at ): void {
		$order->update_meta_data(
			self::REQUEST,
			array(
				'attempt' => $this->attempt( $order ),
				'body'    => $body,
				'sentAt'  => $sent_at,
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
	 * The order that has (or had) a link, or null.
	 *
	 * @param string $link_id Link id.
	 */
	public function findByLinkId( string $link_id ): ?WC_Order {
		if ( '' === $link_id ) {
			return null;
		}
		// meta_key/meta_value, not meta_query: the posts order store ignores meta_query and returns every order.
		$orders = wc_get_orders(
			array(
				'limit'      => 1,
				'type'       => 'shop_order',
				// "any" leaves out trashed orders, which can still be paid through a link they hold.
				'status'     => array_merge( array_keys( wc_get_order_statuses() ), array( 'trash' ) ),
				'meta_key'   => self::ALL_LINKS, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- one lookup per webhook.
				'meta_value' => $link_id, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
			)
		);
		$order  = is_array( $orders ) ? reset( $orders ) : false;
		// Checked again, so a query that ignored the filter can never hand back another order.
		return $order instanceof WC_Order && in_array( $link_id, $this->linkIds( $order ), true ) ? $order : null;
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
