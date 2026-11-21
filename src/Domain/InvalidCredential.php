<?php
/**
 * Invalid credential error.
 *
 * @package Zeny\WooCommerce
 */

namespace Zeny\WooCommerce\Domain;

use InvalidArgumentException;

/**
 * An API key or webhook secret with the wrong shape. The message never quotes
 * the value, so a mistyped secret cannot leak into logs.
 */
final class InvalidCredential extends InvalidArgumentException {
}
