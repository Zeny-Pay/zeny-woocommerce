<?php
/**
 * HTTP transport port.
 *
 * @package Zeny\WooCommerce
 */

namespace Zeny\WooCommerce\Infrastructure;

/**
 * Sends one HTTP request. ZenyApi owns retries and error mapping, so a
 * transport only reports what happened: a response, or no response at all.
 */
interface Transport {

	/**
	 * Sends the request once.
	 *
	 * @param string                $method      HTTP method.
	 * @param string                $url         Absolute URL.
	 * @param array<string, string> $headers     Request headers.
	 * @param string|null           $body        Raw body, or null for none.
	 * @param int                   $timeout_sec Whole-request timeout.
	 * @throws TransportFailed When no response arrived.
	 */
	public function send( string $method, string $url, array $headers, ?string $body, int $timeout_sec ): TransportResponse;
}
