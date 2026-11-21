<?php
/**
 * Retry rules.
 *
 * @package Zeny\WooCommerce
 */

namespace Zeny\WooCommerce\Infrastructure;

use DateTimeImmutable;
use DateTimeZone;

/**
 * Which failures may be retried and how long to wait, as the Zeny SDKs do.
 * Only failures whose retry cannot act twice qualify: every POST carries the
 * same Idempotency-Key on each try.
 */
final class RetryPolicy {

	public const MAX_RETRIES         = 2;
	public const BASE_DELAY_MS       = 500;
	public const MAX_DELAY_MS        = 8000;
	public const MAX_RETRY_AFTER_SEC = 60;

	// The one 4xx that is safe to retry: the first request with this key is still running.
	private const IN_PROGRESS = 'idempotency_key_in_progress';

	/**
	 * Whether an attempt may be retried: no response (null status), any 5xx,
	 * 429, or an idempotency key still in progress.
	 *
	 * @param int|null    $status HTTP status, null when no response arrived.
	 * @param string|null $code   Error code from the body.
	 */
	public static function isRetryable( ?int $status, ?string $code ): bool {
		return null === $status || $status >= 500 || 429 === $status || self::IN_PROGRESS === $code;
	}

	/**
	 * Reads Retry-After (delta-seconds or an IMF-fixdate) as whole seconds,
	 * capped at 60. Null when absent or unreadable.
	 *
	 * @param string|null $header Header value.
	 * @param int         $now    Current Unix time.
	 */
	public static function parseRetryAfter( ?string $header, int $now ): ?int {
		if ( null === $header ) {
			return null;
		}
		$value = trim( $header );
		if ( 1 === preg_match( '/^\d+$/D', $value ) ) {
			return (int) min( (int) $value, self::MAX_RETRY_AFTER_SEC );
		}
		if ( 1 !== preg_match( '/^[A-Za-z]{3}, \d{2} [A-Za-z]{3} \d{4} \d{2}:\d{2}:\d{2} GMT$/D', $value ) ) {
			return null;
		}
		$date = DateTimeImmutable::createFromFormat( 'D, d M Y H:i:s \G\M\T', $value, new DateTimeZone( 'UTC' ) );
		if ( false === $date ) {
			return null;
		}
		return max( 0, min( $date->getTimestamp() - $now, self::MAX_RETRY_AFTER_SEC ) );
	}

	/**
	 * Milliseconds to wait before retry number $attempt + 1. A Retry-After,
	 * even 0, wins; otherwise full-jitter exponential backoff.
	 *
	 * @param int      $attempt         0-based attempt that just failed.
	 * @param int|null $retry_after_sec Parsed Retry-After.
	 * @param float    $random          A number in [0, 1).
	 */
	public static function delayMs( int $attempt, ?int $retry_after_sec, float $random ): int {
		if ( null !== $retry_after_sec ) {
			return $retry_after_sec * 1000;
		}
		$window = min( self::MAX_DELAY_MS, self::BASE_DELAY_MS * ( 2 ** min( $attempt, 10 ) ) );
		return (int) floor( $random * $window );
	}
}
