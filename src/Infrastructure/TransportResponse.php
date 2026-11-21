<?php
/**
 * HTTP response.
 *
 * @package Zeny\WooCommerce
 */

namespace Zeny\WooCommerce\Infrastructure;

/**
 * Status, headers (names in lower case) and raw body of one response.
 */
final class TransportResponse {

	/**
	 * HTTP status.
	 *
	 * @var int
	 */
	private $status;

	/**
	 * Headers keyed by lower-case name.
	 *
	 * @var array<string, string>
	 */
	private $headers;

	/**
	 * Raw body.
	 *
	 * @var string
	 */
	private $body;

	/**
	 * Builds a response.
	 *
	 * @param int                   $status  HTTP status.
	 * @param array<string, string> $headers Headers; names are lower-cased here.
	 * @param string                $body    Raw body.
	 */
	public function __construct( int $status, array $headers, string $body ) {
		$this->status  = $status;
		$this->headers = array_change_key_case( $headers, CASE_LOWER );
		$this->body    = $body;
	}

	/**
	 * HTTP status.
	 */
	public function status(): int {
		return $this->status;
	}

	/**
	 * A header value, or null when absent.
	 *
	 * @param string $name Header name, any case.
	 */
	public function header( string $name ): ?string {
		return $this->headers[ strtolower( $name ) ] ?? null;
	}

	/**
	 * Raw body.
	 */
	public function body(): string {
		return $this->body;
	}
}
