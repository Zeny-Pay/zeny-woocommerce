<?php
/**
 * Money amount.
 *
 * @package Zeny\WooCommerce
 */

namespace Zeny\WooCommerce\Domain;

use InvalidArgumentException;

/**
 * An amount with its currency, kept as a decimal string at two decimals
 * ("150000.00"), the scale the Zeny API uses for IDR. Never a float: a float
 * cannot hold every amount exactly, and the API refuses one it would round.
 */
final class Money {

	public const IDR = 'IDR';

	/**
	 * Canonical decimal string, two decimals.
	 *
	 * @var string
	 */
	private $value;

	/**
	 * Upper-case currency code.
	 *
	 * @var string
	 */
	private $currency;

	/**
	 * Use Money::idr() or Money::fromApi().
	 *
	 * @param string $value    Canonical value.
	 * @param string $currency Currency code.
	 */
	private function __construct( string $value, string $currency ) {
		$this->value    = $value;
		$this->currency = $currency;
	}

	/**
	 * An IDR amount from a plain decimal string with at most two decimals.
	 *
	 * @param string $decimal Such as "150000" or "150000.50".
	 * @throws InvalidArgumentException When $decimal is not a plain non-negative decimal.
	 */
	public static function idr( string $decimal ): self {
		return new self( self::canonical( $decimal ), self::IDR );
	}

	/**
	 * Reads a Money object of the API ({"value": "…", "currency": "…"}).
	 * Returns null for anything else, since the input comes off the network.
	 *
	 * @param mixed $money Decoded JSON.
	 */
	public static function fromApi( $money ): ?self {
		if ( ! is_array( $money ) || ! isset( $money['value'], $money['currency'] ) ) {
			return null;
		}
		if ( ! is_string( $money['value'] ) || ! is_string( $money['currency'] ) || '' === $money['currency'] ) {
			return null;
		}
		try {
			return new self( self::canonical( $money['value'] ), $money['currency'] );
		} catch ( InvalidArgumentException $e ) {
			return null;
		}
	}

	/**
	 * The decimal string, such as "150000.00".
	 */
	public function value(): string {
		return $this->value;
	}

	/**
	 * The currency code, such as "IDR".
	 */
	public function currency(): string {
		return $this->currency;
	}

	/**
	 * Whether both amounts have the same value and currency.
	 *
	 * @param Money $other Amount to compare with.
	 */
	public function equals( Money $other ): bool {
		return $this->value === $other->value && $this->currency === $other->currency;
	}

	/**
	 * Whether the amount is above zero.
	 */
	public function isPositive(): bool {
		return '0.00' !== $this->value;
	}

	/**
	 * The API's Money shape.
	 *
	 * @return array{value: string, currency: string}
	 */
	public function toArray(): array {
		return array(
			'value'    => $this->value,
			'currency' => $this->currency,
		);
	}

	/**
	 * Normalises a decimal string to two decimals without arithmetic.
	 *
	 * @param string $decimal Input.
	 * @throws InvalidArgumentException When $decimal is not a plain non-negative decimal.
	 */
	private static function canonical( string $decimal ): string {
		if ( 1 !== preg_match( '/^(\d+)(?:\.(\d{1,2}))?$/D', $decimal, $parts ) ) {
			throw new InvalidArgumentException( 'An amount must be a non-negative decimal with at most two decimals.' );
		}
		$whole = ltrim( $parts[1], '0' );
		return ( '' === $whole ? '0' : $whole ) . '.' . str_pad( $parts[2] ?? '', 2, '0' );
	}
}
