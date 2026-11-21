<?php
/**
 * Zeny secret API key.
 *
 * @package Zeny\WooCommerce
 */

namespace Zeny\WooCommerce\Domain;

/**
 * A validated secret key. Its prefix picks the environment: sk_test_ is
 * Sandbox, sk_live_ is Production. Printing or dumping it never shows the secret.
 */
final class ApiKey {

	// Printable ASCII only: anything else would break the Authorization header.
	private const PATTERN = '/^sk_(live|test)_[\x21-\x7E]+$/D';

	/**
	 * The secret.
	 *
	 * @var string
	 */
	private $value;

	/**
	 * Mode::TEST or Mode::LIVE.
	 *
	 * @var string
	 */
	private $mode;

	/**
	 * Use ApiKey::parse().
	 *
	 * @param string $value Validated key.
	 * @param string $mode  Mode from the prefix.
	 */
	private function __construct( string $value, string $mode ) {
		$this->value = $value;
		$this->mode  = $mode;
	}

	/**
	 * Validates $raw as an sk_test_ or sk_live_ key.
	 *
	 * @param string $raw Key as typed by the merchant.
	 * @throws InvalidCredential When $raw is not a secret key.
	 */
	public static function parse( string $raw ): self {
		if ( 1 !== preg_match( self::PATTERN, $raw, $match ) ) {
			throw new InvalidCredential( 'The API key must be a secret key starting with sk_test_ or sk_live_.' );
		}
		return new self( $raw, Mode::LIVE === $match[1] ? Mode::LIVE : Mode::TEST );
	}

	/**
	 * The secret itself. Read it only to build the Authorization header.
	 */
	public function value(): string {
		return $this->value;
	}

	/**
	 * Mode::TEST (Sandbox) or Mode::LIVE (Production).
	 */
	public function mode(): string {
		return $this->mode;
	}

	/**
	 * A redacted form that is safe to log.
	 */
	public function __toString(): string {
		return 'sk_' . $this->mode . '_***';
	}

	/**
	 * Keeps var_dump() and print_r() from showing the secret.
	 *
	 * @return array<string, string>
	 */
	public function __debugInfo(): array {
		return array(
			'value' => (string) $this,
			'mode'  => $this->mode,
		);
	}
}
