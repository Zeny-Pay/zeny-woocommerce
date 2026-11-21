<?php
/**
 * Zeny environment.
 *
 * @package Zeny\WooCommerce
 */

namespace Zeny\WooCommerce\Domain;

/**
 * The environment a key, link or event belongs to: Sandbox ("test") or Production ("live").
 */
final class Mode {

	public const TEST = 'test';
	public const LIVE = 'live';

	/**
	 * Returns the mode named by $value, or null when it is not one we know.
	 *
	 * @param string $value Mode as the API spells it.
	 */
	public static function fromString( string $value ): ?string {
		return in_array( $value, array( self::TEST, self::LIVE ), true ) ? $value : null;
	}
}
