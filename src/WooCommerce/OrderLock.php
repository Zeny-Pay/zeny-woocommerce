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
 * atomic, which add_option() (an upsert) is not. A lock older than its
 * stale age is taken over, so a crashed request cannot block an order forever.
 */
final class OrderLock {

	private const PREFIX    = 'zeny_order_lock_';
	private const POLL_MS   = 200;
	private const STALE_SEC = 120;

	/**
	 * How long acquire() waits for the lock.
	 *
	 * @var int
	 */
	private $waitMs;

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
			$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name = %s AND option_value < %d", $name, time() - self::STALE_SEC ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- an atomic lock cannot go through the options cache.
			$taken = $wpdb->query( $wpdb->prepare( "INSERT IGNORE INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %d, 'off')", $name, time() ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			if ( 1 === $taken ) {
				return true;
			}
			if ( microtime( true ) >= $deadline ) {
				return false;
			}
			usleep( self::POLL_MS * 1000 );
		} while ( true );
	}

	/**
	 * Releases the order's lock.
	 *
	 * @param int $order_id Order id.
	 */
	public function release( int $order_id ): void {
		global $wpdb;
		$wpdb->delete( $wpdb->options, array( 'option_name' => self::PREFIX . $order_id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}
}
