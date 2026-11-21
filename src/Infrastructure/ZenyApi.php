<?php
/**
 * Zeny Merchant API client.
 *
 * @package Zeny\WooCommerce
 */

namespace Zeny\WooCommerce\Infrastructure;

use InvalidArgumentException;
use JsonException;
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

	// Checkout runs while the shopper waits and while the order is locked: about 20 s in all.
	public const CHECKOUT_BUDGET_SEC           = 20;
	private const CHECKOUT_MAX_RETRIES         = 1;
	private const CHECKOUT_MAX_RETRY_AFTER_SEC = 5;

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
	 * Returns the current time in seconds.
	 *
	 * @var callable(): float
	 */
	private $now;

	/**
	 * Retries after the first try.
	 *
	 * @var int
	 */
	private $maxRetries = RetryPolicy::MAX_RETRIES;

	/**
	 * Longest Retry-After honoured, in seconds.
	 *
	 * @var int
	 */
	private $maxRetryAfterSec = RetryPolicy::MAX_RETRY_AFTER_SEC;

	/**
	 * Time (as now() reads it) after which no request is sent, or null for none.
	 *
	 * @var float|null
	 */
	private $deadline;

	/**
	 * Builds the client.
	 *
	 * @param ApiKey                   $key        Secret key; its prefix picks the host.
	 * @param Transport                $transport  HTTP transport.
	 * @param string                   $user_agent User-Agent header.
	 * @param string|null              $base_url   Host override: https, or http on a loopback host.
	 * @param callable(int): void|null $sleep      Sleep in milliseconds; for tests.
	 * @param callable(): float|null   $random     Random number in [0, 1); for tests.
	 * @param callable(): float|null   $now        Current time in seconds; for tests.
	 * @throws InvalidArgumentException When $base_url is not allowed.
	 */
	public function __construct( ApiKey $key, Transport $transport, string $user_agent, ?string $base_url = null, ?callable $sleep = null, ?callable $random = null, ?callable $now = null ) {
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
		$this->now       = $now ?? static function (): float {
			return microtime( true );
		};
	}

	/**
	 * A copy for checkout: every call it makes, retries and waits included,
	 * ends within CHECKOUT_BUDGET_SEC from now, with at most one retry and a
	 * Retry-After of at most 5 s. Webhook and admin calls keep the normal policy.
	 */
	public function withCheckoutLimits(): self {
		$copy                   = clone $this;
		$copy->maxRetries       = self::CHECKOUT_MAX_RETRIES;
		$copy->maxRetryAfterSec = self::CHECKOUT_MAX_RETRY_AFTER_SEC;
		$copy->deadline         = ( $this->now )() + self::CHECKOUT_BUDGET_SEC;
		return $copy;
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
	 * @throws JsonException When the body cannot be encoded; nothing was sent.
	 */
	public function createPaymentLink( array $body, IdempotencyKey $key ): array {
		return $this->request( 'POST', '/payment-links', self::encode( $body ), $key );
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
		return $this->request( 'POST', $path, '{}', IdempotencyKey::parse( self::uuid() ) );
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
	 * @param string|null         $raw    Encoded JSON body for a POST, sent the same on every retry.
	 * @param IdempotencyKey|null $key    Idempotency-Key for a POST.
	 * @return array<mixed>
	 * @throws ZenyApiException When the call fails.
	 */
	private function request( string $method, string $path, ?string $raw = null, ?IdempotencyKey $key = null ): array {
		$headers = $this->headers( null !== $raw, $key );
		for ( $attempt = 0; ; $attempt++ ) {
			$retry_after = null;
			$timeout     = $this->timeoutSec();
			if ( null === $timeout ) {
				throw new ZenyApiException( 'connection_error', 'timeout', 'The time for this request ran out before it was sent.', null, null, null === $key ? null : $key->value() );
			}
			try {
				$response = $this->transport->send( $method, $this->baseUrl . self::PREFIX . $path, $headers, $raw, $timeout );
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
			if ( $attempt >= $this->maxRetries || ! RetryPolicy::isRetryable( $error->statusCode(), $error->errorCode() ) ) {
				throw $error;
			}
			$delay = RetryPolicy::delayMs( $attempt, null === $retry_after ? null : min( $retry_after, $this->maxRetryAfterSec ), ( $this->random )() );
			// No point waiting when the retry could not get a second of its own afterwards.
			if ( null !== $this->deadline && ( $this->now )() + $delay / 1000 + 1 > $this->deadline ) {
				throw $error;
			}
			( $this->sleep )( $delay );
		}
	}

	/**
	 * Timeout for the next request: 30 s, or what is left of the deadline.
	 * Null when less than a second is left.
	 */
	private function timeoutSec(): ?int {
		if ( null === $this->deadline ) {
			return self::TIMEOUT_SEC;
		}
		$left = (int) floor( $this->deadline - ( $this->now )() );
		return $left < 1 ? null : min( self::TIMEOUT_SEC, $left );
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
	 * @throws JsonException When the body holds invalid UTF-8.
	 */
	private static function encode( array $body ): string {
		if ( array() === $body ) {
			return '{}';
		}
		// The client runs without WordPress in tests, so it cannot use wp_json_encode.
		$json = json_encode( $body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode
		if ( false === $json ) {
			throw new JsonException( json_last_error_msg() );
		}
		return $json;
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
