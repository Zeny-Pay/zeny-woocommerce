<?php
/**
 * Transport failure.
 *
 * @package Zeny\WooCommerce
 */

namespace Zeny\WooCommerce\Infrastructure;

use RuntimeException;

/**
 * No response arrived. The message is "timeout" or "network_error" only: the
 * underlying error can quote the URL or headers, so it is never kept.
 */
final class TransportFailed extends RuntimeException {
}
