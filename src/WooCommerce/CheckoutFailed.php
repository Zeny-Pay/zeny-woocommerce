<?php
/**
 * Checkout failure shown to the shopper.
 *
 * @package Zeny\WooCommerce
 */

namespace Zeny\WooCommerce\WooCommerce;

use RuntimeException;

/**
 * The checkout could not send the shopper to Zeny. The message is translated
 * and safe to show the shopper.
 */
final class CheckoutFailed extends RuntimeException {
}
