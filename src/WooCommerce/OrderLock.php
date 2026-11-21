<?php
/**
 * Per-order mutual exclusion.
 *
 * @package Zeny\WooCommerce
 */

namespace Zeny\WooCommerce\WooCommerce;

/**
 * A lock per order, so a double-clicked checkout or two deliveries of one
 * webhook never act on the same order at once. It is a row in the options
 * table inserted with INSERT IGNORE: the unique option_name makes the insert
 * atomic, which add_option() (an upsert) is not. The row holds the time it
 * was taken and a random owner token; release() deletes only the row this
 * instance wrote, so an owner whose lock was taken over cannot free the new
 * owner's. A lock older than STALE_SEC is taken over, so a crashed request
 * cannot block an order forever; STALE_SEC is well above the longest
 * checkout (ZenyApi::CHECKOUT_BUDGET_SEC plus database work).
 */
final class OrderLock {

	public const STALE_SEC = 60;

	private const PREFIX  = 'zeny_order_lock_';
	private const POLL_MS = 200;

	/**
	 * How long acquire() waits for the lock.
	 *
	 * @var int
	 */
	private $waitMs;

	/**
	 * Row value this instance wrote, per order id.
	 *
	 * @var array<int, string>
	 */
	private $held = array();

	/**
	 * Builds the lock.
	 *
	 * @param int $wait_ms How long acquire() waits, in milliseconds.
	 */
	public function __construct( int $wait_ms = 10000 ) {
		$this->waitMs = $wait_ms;
	}

	/**
	 * Takes the order's lock, waiting up to the wait time.
	 *
	 * @param int $order_id Order id.
	 * @return bool Whether the lock is now held.
	 */
	public function acquire( int $order_id ): bool {
		global $wpdb;
		$name     = self::PREFIX . $order_id;
		$deadline = microtime( true ) + $this->waitMs / 1000;
		do {
			// phpcs:disable WordPress.DB.DirectDatabaseQuery -- an atomic lock cannot go through the options cache.
			$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name = %s AND CAST(SUBSTRING_INDEX(option_value, '|', 1) AS UNSIGNED) < %d", $name, time() - self::STALE_SEC ) );
			$value = time() . '|' . bin2hex( random_bytes( 16 ) );
			$taken = $wpdb->query( $wpdb->prepare( "INSERT IGNORE INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, 'off')", $name, $value ) );
			// phpcs:enable
			if ( 1 === $taken ) {
				$this->held[ $order_id ] = $value;
				return true;
			}
			if ( microtime( true ) >= $deadline ) {
				return false;
			}
			usleep( self::POLL_MS * 1000 );
		} while ( true );
	}

	/**
	 * Releases the order's lock if this instance still owns it.
	 *
	 * @param int $order_id Order id.
	 */
	public function release( int $order_id ): void {
		global $wpdb;
		if ( ! isset( $this->held[ $order_id ] ) ) {
			return;
		}
		$wpdb->delete( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->options,
			array(
				'option_name'  => self::PREFIX . $order_id,
				'option_value' => $this->held[ $order_id ],
			)
		);
		unset( $this->held[ $order_id ] );
	}
}
