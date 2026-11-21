<?php
/**
 * Idempotency-Key header value.
 *
 * @package Zeny\WooCommerce
 */

namespace Zeny\WooCommerce\Domain;

use InvalidArgumentException;

/**
 * The Idempotency-Key a POST carries: 1 to 255 printable ASCII characters.
 * Sending the same key again makes the API answer with the first result
 * instead of acting twice.
 */
final class IdempotencyKey {

	/**
	 * The key.
	 *
	 * @var string
	 */
	private $value;

	/**
	 * Use a named constructor.
	 *
	 * @param string $value Validated key.
	 */
	private function __construct( string $value ) {
		$this->value = $value;
	}

	/**
	 * Validates a key.
	 *
	 * @param string $raw Key.
	 * @throws InvalidArgumentException When $raw is not 1-255 printable ASCII characters.
	 */
	public static function parse( string $raw ): self {
		if ( 1 !== preg_match( '/^[\x20-\x7E]{1,255}$/D', $raw ) ) {
			throw new InvalidArgumentException( 'An idempotency key must be 1-255 printable ASCII characters.' );
		}
		return new self( $raw );
	}

	/**
	 * The key for creating the payment link of one checkout attempt of an order:
	 * wc-{site hash}-{order id}-{attempt}. A resubmit of the same attempt sends
	 * the same key and gets the same link back; only a new attempt makes a new one.
	 * The site hash keeps two shops on one Zeny project from sharing keys.
	 *
	 * @param string $site_hash Short hex hash of the site URL.
	 * @param int    $order_id  WooCommerce order id.
	 * @param int    $attempt   Attempt number, from 1.
	 * @throws InvalidArgumentException When a part is out of range.
	 */
	public static function forOrderAttempt( string $site_hash, int $order_id, int $attempt ): self {
		if ( 1 !== preg_match( '/^[0-9a-f]{1,32}$/D', $site_hash ) || $order_id < 1 || $attempt < 1 ) {
			throw new InvalidArgumentException( 'Invalid order attempt for an idempotency key.' );
		}
		return new self( sprintf( 'wc-%s-%d-%d', $site_hash, $order_id, $attempt ) );
	}

	/**
	 * The key as sent in the header.
	 */
	public function value(): string {
		return $this->value;
	}
}
