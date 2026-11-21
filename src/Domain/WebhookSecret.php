<?php
/**
 * Zeny webhook endpoint secret.
 *
 * @package Zeny\WooCommerce
 */

namespace Zeny\WooCommerce\Domain;

/**
 * A validated endpoint secret (whsec_…). The whole string, prefix included, is
 * the HMAC key. Printing or dumping it never shows the secret.
 */
final class WebhookSecret {

	private const PATTERN = '/^whsec_[\x21-\x7E]+$/D';

	/**
	 * The secret.
	 *
	 * @var string
	 */
	private $value;

	/**
	 * Use WebhookSecret::parse().
	 *
	 * @param string $value Validated secret.
	 */
	private function __construct( string $value ) {
		$this->value = $value;
	}

	/**
	 * Validates $raw as a whsec_ secret.
	 *
	 * @param string $raw Secret as typed by the merchant.
	 * @throws InvalidCredential When $raw is not a webhook secret.
	 */
	public static function parse( string $raw ): self {
		if ( 1 !== preg_match( self::PATTERN, $raw ) ) {
			throw new InvalidCredential( 'The webhook secret must start with whsec_.' );
		}
		return new self( $raw );
	}

	/**
	 * The secret itself. Read it only to compute a signature.
	 */
	public function value(): string {
		return $this->value;
	}

	/**
	 * A redacted form that is safe to log.
	 */
	public function __toString(): string {
		return 'whsec_***';
	}

	/**
	 * Keeps var_dump() and print_r() from showing the secret.
	 *
	 * @return array<string, string>
	 */
	public function __debugInfo(): array {
		return array( 'value' => (string) $this );
	}
}
