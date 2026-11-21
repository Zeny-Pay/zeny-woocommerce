<?php
/**
 * Transport over the WordPress HTTP API.
 *
 * @package Zeny\WooCommerce
 */

namespace Zeny\WooCommerce\Infrastructure;

/**
 * Sends requests with wp_remote_request, so the shop's proxy and CA settings apply.
 */
final class WpHttpTransport implements Transport {

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
	public function send( string $method, string $url, array $headers, ?string $body, int $timeout_sec ): TransportResponse {
		$args = array(
			'method'      => $method,
			'headers'     => $headers,
			'timeout'     => $timeout_sec,
			// A redirect would resend the bearer key to another URL; it is answered as an error instead.
			'redirection' => 0,
			'user-agent'  => $headers['User-Agent'] ?? 'zeny-woocommerce',
		);
		if ( null !== $body ) {
			$args['body'] = $body;
		}

		$response = wp_remote_request( $url, $args );
		if ( is_wp_error( $response ) ) {
			$message = $response->get_error_message();
			$timeout = false !== stripos( $message, 'timed out' ) || false !== stripos( $message, 'cURL error 28' );
			throw new TransportFailed( $timeout ? 'timeout' : 'network_error' );
		}

		$headers_out = array();
		foreach ( wp_remote_retrieve_headers( $response ) as $name => $value ) {
			$headers_out[ (string) $name ] = is_array( $value ) ? (string) reset( $value ) : (string) $value;
		}
		return new TransportResponse(
			(int) wp_remote_retrieve_response_code( $response ),
			$headers_out,
			(string) wp_remote_retrieve_body( $response )
		);
	}
}
