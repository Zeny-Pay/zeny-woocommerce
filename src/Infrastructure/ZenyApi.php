<?php
/**
 * Zeny Merchant API client.
 *
 * @package Zeny\WooCommerce
 */

namespace Zeny\WooCommerce\Infrastructure;

use InvalidArgumentException;
use Zeny\WooCommerce\Domain\ApiKey;
use Zeny\WooCommerce\Domain\IdempotencyKey;

/**
 * The Merchant API v1 routes the plugin uses, through one pipeline: bearer
 * auth, an Idempotency-Key on every POST reused on every retry, retries of
 * transient failures only, and typed errors.
 */
final class ZenyApi {

	public const BASE_URLS = array(
		'live' => 'https://api.usezeny.com',
		'test' => 'https://snorlax.usezeny.com',
	);

	private const PREFIX      = '/api/merchant/v1';
	private const TIMEOUT_SEC = 30;
	private const LOOPBACK    = array( 'localhost', '127.0.0.1', '[::1]' );

	/**
	 * Secret key.
	 *
	 * @var ApiKey
	 */
	private $key;

	/**
	 * HTTP transport.
	 *
	 * @var Transport
	 */
	private $transport;

	/**
	 * User-Agent header.
	 *
	 * @var string
	 */
	private $userAgent;

	/**
	 * Base URL without a trailing slash.
	 *
	 * @var string
	 */
	private $baseUrl;

	/**
	 * Sleeps for the given milliseconds.
	 *
	 * @var callable(int): void
	 */
	private $sleep;

	/**
	 * Returns a number in [0, 1).
	 *
	 * @var callable(): float
	 */
	private $random;

	/**
	 * Builds the client.
	 *
	 * @param ApiKey                   $key        Secret key; its prefix picks the host.
	 * @param Transport                $transport  HTTP transport.
	 * @param string                   $user_agent User-Agent header.
	 * @param string|null              $base_url   Host override: https, or http on a loopback host.
	 * @param callable(int): void|null $sleep      Sleep in milliseconds; for tests.
	 * @param callable(): float|null   $random     Random number in [0, 1); for tests.
	 * @throws InvalidArgumentException When $base_url is not allowed.
	 */
	public function __construct( ApiKey $key, Transport $transport, string $user_agent, ?string $base_url = null, ?callable $sleep = null, ?callable $random = null ) {
		$this->key       = $key;
		$this->transport = $transport;
		$this->userAgent = $user_agent;
		$this->baseUrl   = self::checkBaseUrl( $base_url ?? self::BASE_URLS[ $key->mode() ] );
		$this->sleep     = $sleep ?? static function ( int $ms ): void {
			usleep( $ms * 1000 );
		};
		$this->random    = $random ?? static function (): float {
			return random_int( 0, 999999 ) / 1000000;
		};
	}

	/**
	 * The key's mode: "test" (Sandbox) or "live" (Production).
	 */
	public function mode(): string {
		return $this->key->mode();
	}

	/**
	 * POST /payment-links. Resending the same key and body returns the same link.
	 *
	 * @param array<string, mixed> $body CreatePaymentLinkRequest.
	 * @param IdempotencyKey       $key  Idempotency-Key.
	 * @return array<mixed> PaymentLink.
	 * @throws ZenyApiException When the call fails.
	 */
	public function createPaymentLink( array $body, IdempotencyKey $key ): array {
		return $this->request( 'POST', '/payment-links', $body, $key );
	}

	/**
	 * GET /payment-links/{id}.
	 *
	 * @param string $id Link id (pl_…).
	 * @return array<mixed> PaymentLink.
	 * @throws ZenyApiException When the call fails.
	 */
	public function retrievePaymentLink( string $id ): array {
		return $this->request( 'GET', '/payment-links/' . self::id( $id, 'pl_' ) );
	}

	/**
	 * POST /payment-links/{id}/cancel. Only a link without payments can be canceled.
	 *
	 * @param string $id Link id (pl_…).
	 * @return array<mixed> PaymentLink.
	 * @throws ZenyApiException When the call fails.
	 */
	public function cancelPaymentLink( string $id ): array {
		$path = '/payment-links/' . self::id( $id, 'pl_' ) . '/cancel';
		return $this->request( 'POST', $path, array(), IdempotencyKey::parse( self::uuid() ) );
	}

	/**
	 * GET /payments/{id}.
	 *
	 * @param string $id Payment id (pay_…).
	 * @return array<mixed> Payment.
	 * @throws ZenyApiException When the call fails.
	 */
	public function retrievePayment( string $id ): array {
		return $this->request( 'GET', '/payments/' . self::id( $id, 'pay_' ) );
	}

	/**
	 * GET /payment-links?limit=N. Used to test the key.
	 *
	 * @param int $limit Page size, 1 to 100.
	 * @return array<mixed> ListPaymentLinksResponse.
	 * @throws ZenyApiException When the call fails.
	 */
	public function listPaymentLinks( int $limit ): array {
		return $this->request( 'GET', '/payment-links?limit=' . max( 1, min( 100, $limit ) ) );
	}

	/**
	 * Sends a request, retrying what is safe to retry.
	 *
	 * @param string              $method HTTP method.
	 * @param string              $path   Path below /api/merchant/v1.
	 * @param array<mixed>|null   $body   JSON body for a POST.
	 * @param IdempotencyKey|null $key    Idempotency-Key for a POST.
	 * @return array<mixed>
	 * @throws ZenyApiException When the call fails.
	 */
	private function request( string $method, string $path, ?array $body = null, ?IdempotencyKey $key = null ): array {
		// Encoded once, so every retry sends the same bytes with the same key.
		$raw     = null === $body ? null : self::encode( $body );
		$headers = $this->headers( null !== $raw, $key );
		for ( $attempt = 0; ; $attempt++ ) {
			$retry_after = null;
			try {
				$response = $this->transport->send( $method, $this->baseUrl . self::PREFIX . $path, $headers, $raw, self::TIMEOUT_SEC );
				$decoded  = json_decode( $response->body(), true );
				$status   = $response->status();
				if ( $status >= 200 && $status < 300 && self::isObject( $decoded ) ) {
					return $decoded;
				}
				$error       = self::apiError( $response, $decoded, $key );
				$retry_after = RetryPolicy::parseRetryAfter( $response->header( 'retry-after' ), time() );
			} catch ( TransportFailed $failure ) {
				$error = new ZenyApiException(
					'connection_error',
					$failure->getMessage(),
					'Could not reach the Zeny API; the outcome is unknown.',
					null,
					null,
					null === $key ? null : $key->value()
				);
			}
			if ( $attempt >= RetryPolicy::MAX_RETRIES || ! RetryPolicy::isRetryable( $error->statusCode(), $error->errorCode() ) ) {
				throw $error;
			}
			( $this->sleep )( RetryPolicy::delayMs( $attempt, $retry_after, ( $this->random )() ) );
		}
	}

	/**
	 * Request headers.
	 *
	 * @param bool                $has_body Whether a JSON body is sent.
	 * @param IdempotencyKey|null $key      Idempotency-Key.
	 * @return array<string, string>
	 */
	private function headers( bool $has_body, ?IdempotencyKey $key ): array {
		$headers = array(
			'Authorization' => 'Bearer ' . $this->key->value(),
			'Accept'        => 'application/json',
			'User-Agent'    => $this->userAgent,
		);
		if ( $has_body ) {
			$headers['Content-Type'] = 'application/json';
		}
		if ( null !== $key ) {
			$headers['Idempotency-Key'] = $key->value();
		}
		return $headers;
	}

	/**
	 * Maps a non-success response. The body is network input, so each field is checked.
	 *
	 * @param TransportResponse   $response Response.
	 * @param mixed               $decoded  Decoded body.
	 * @param IdempotencyKey|null $key      Idempotency-Key sent.
	 */
	private static function apiError( TransportResponse $response, $decoded, ?IdempotencyKey $key ): ZenyApiException {
		$status     = $response->status();
		$request_id = $response->header( 'x-request-id' );
		$key_value  = null === $key ? null : $key->value();
		if ( $status >= 200 && $status < 300 ) {
			return new ZenyApiException( 'api_error', 'unexpected_response', 'The Zeny API returned a response that was not a JSON object.', $status, $request_id, $key_value );
		}
		$error = is_array( $decoded ) && isset( $decoded['error'] ) && is_array( $decoded['error'] ) ? $decoded['error'] : null;
		if ( null === $error ) {
			return new ZenyApiException( 'api_error', 'unexpected_response', sprintf( 'Unexpected response from the Zeny API (HTTP %d).', $status ), $status, $request_id, $key_value );
		}
		$text = static function ( string $field ) use ( $error ): ?string {
			return isset( $error[ $field ] ) && is_string( $error[ $field ] ) ? $error[ $field ] : null;
		};
		return new ZenyApiException(
			$text( 'type' ) ?? 'api_error',
			$text( 'code' ) ?? 'unknown',
			$text( 'message' ) ?? sprintf( 'Zeny API error (HTTP %d).', $status ),
			$status,
			$text( 'requestId' ) ?? $request_id,
			$key_value
		);
	}

	/**
	 * Whether a decoded value is a JSON object (not a list or scalar).
	 *
	 * @param mixed $value Decoded JSON.
	 * @phpstan-assert-if-true array<mixed> $value
	 */
	private static function isObject( $value ): bool {
		return is_array( $value ) && ( array() === $value || array_keys( $value ) !== range( 0, count( $value ) - 1 ) );
	}

	/**
	 * JSON-encodes a body; an empty one is "{}".
	 *
	 * @param array<mixed> $body Body.
	 */
	private static function encode( array $body ): string {
		if ( array() === $body ) {
			return '{}';
		}
		// The client runs without WordPress in tests, so it cannot use wp_json_encode.
		return (string) json_encode( $body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode
	}

	/**
	 * Checks an id's prefix and characters before it goes into a path.
	 *
	 * @param string $id     Id.
	 * @param string $prefix Required prefix.
	 * @throws InvalidArgumentException When the id is not one of this resource.
	 */
	private static function id( string $id, string $prefix ): string {
		if ( 1 !== preg_match( '/^' . preg_quote( $prefix, '/' ) . '[A-Za-z0-9_-]+$/D', $id ) ) {
			throw new InvalidArgumentException( 'The id is not an id of this kind of Zeny object.' );
		}
		return $id;
	}

	/**
	 * A random UUID v4 for a POST that has no business key.
	 */
	private static function uuid(): string {
		$bytes    = random_bytes( 16 );
		$bytes[6] = chr( ord( $bytes[6] ) & 0x0f | 0x40 );
		$bytes[8] = chr( ord( $bytes[8] ) & 0x3f | 0x80 );
		return vsprintf( '%s%s-%s-%s-%s-%s%s%s', str_split( bin2hex( $bytes ), 4 ) );
	}

	/**
	 * Accepts https for any host and http only for a loopback host; never userinfo,
	 * which proxies log and which would travel next to the bearer key.
	 *
	 * @param string $url Base URL.
	 * @throws InvalidArgumentException When the URL is not allowed.
	 */
	private static function checkBaseUrl( string $url ): string {
		$parts  = parse_url( $url ); // phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url -- runs without WordPress in tests; PHP 7.4+ parses correctly.
		$parts  = is_array( $parts ) ? $parts : array();
		$scheme = $parts['scheme'] ?? '';
		$host   = $parts['host'] ?? '';
		$ok     = '' !== $host && ! isset( $parts['user'] ) && ! isset( $parts['pass'] )
			&& ( 'https' === $scheme || ( 'http' === $scheme && in_array( $host, self::LOOPBACK, true ) ) );
		if ( ! $ok ) {
			throw new InvalidArgumentException( 'The Zeny API base URL must be https (http only for localhost).' );
		}
		return rtrim( $url, '/' );
	}
}
