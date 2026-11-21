<?php
/**
 * Webhook verification error.
 *
 * @package Zeny\WooCommerce
 */

namespace Zeny\WooCommerce\Domain;

use RuntimeException;

/**
 * A webhook that failed verification. The message is one of the codes
 * signature_missing, signature_malformed, signature_mismatch,
 * timestamp_out_of_tolerance or invalid_payload, and never quotes the request.
 */
final class WebhookVerificationFailed extends RuntimeException {
}
