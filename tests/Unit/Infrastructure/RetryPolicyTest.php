<?php

namespace Zeny\WooCommerce\Tests\Unit\Infrastructure;

use PHPUnit\Framework\TestCase;
use Zeny\WooCommerce\Infrastructure\RetryPolicy;

final class RetryPolicyTest extends TestCase {

	/**
	 * @dataProvider outcomes
	 */
	public function test_is_retryable( ?int $status, ?string $code, bool $expected ): void {
		$this->assertSame( $expected, RetryPolicy::isRetryable( $status, $code ) );
	}

	/**
	 * @return array<string, array{?int, ?string, bool}>
	 */
	public function outcomes(): array {
		return array(
			'network error'          => array( null, 'network_error', true ),
			'timeout'                => array( null, 'timeout', true ),
			'500'                    => array( 500, 'internal_error', true ),
			'503'                    => array( 503, 'overloaded', true ),
			'429'                    => array( 429, 'rate_limited', true ),
			'409 in progress'        => array( 409, 'idempotency_key_in_progress', true ),
			'409 other'              => array( 409, 'payment_link_has_payments', false ),
			'400'                    => array( 400, 'field_required', false ),
			'401'                    => array( 401, 'token_invalid', false ),
			'404'                    => array( 404, 'payment_not_found', false ),
			'422 key reused'         => array( 422, 'idempotency_key_reused', false ),
			'2xx unexpected payload' => array( 200, 'unexpected_response', false ),
			'3xx redirect'           => array( 302, 'unexpected_response', false ),
		);
	}

	/**
	 * @dataProvider retryAfter
	 */
	public function test_parse_retry_after( ?string $header, ?int $expected ): void {
		$now = 1792552500; // Wed, 21 Oct 2026 03:15:00 GMT
		$this->assertSame( $expected, RetryPolicy::parseRetryAfter( $header, $now ) );
	}

	/**
	 * @return array<string, array{?string, ?int}>
	 */
	public function retryAfter(): array {
		return array(
			'absent'          => array( null, null ),
			'seconds'         => array( '3', 3 ),
			'zero'            => array( '0', 0 ),
			'padded'          => array( ' 5 ', 5 ),
			'capped'          => array( '3600', 60 ),
			'http date'       => array( 'Wed, 21 Oct 2026 03:15:10 GMT', 10 ),
			'past date'       => array( 'Wed, 21 Oct 2026 03:14:00 GMT', 0 ),
			'far date capped' => array( 'Thu, 22 Oct 2026 03:15:00 GMT', 60 ),
			'fraction'        => array( '1.5', null ),
			'signed'          => array( '+5', null ),
			'garbage'         => array( 'soon', null ),
		);
	}

	public function test_delay_honours_retry_after_including_zero(): void {
		$this->assertSame( 3000, RetryPolicy::delayMs( 0, 3, 0.5 ) );
		$this->assertSame( 0, RetryPolicy::delayMs( 1, 0, 0.5 ) );
	}

	public function test_delay_is_full_jitter_exponential_and_capped(): void {
		$this->assertSame( 0, RetryPolicy::delayMs( 0, null, 0.0 ) );
		$this->assertSame( 250, RetryPolicy::delayMs( 0, null, 0.5 ) );
		$this->assertSame( 500, RetryPolicy::delayMs( 1, null, 0.5 ) );
		$this->assertSame( 4000, RetryPolicy::delayMs( 10, null, 0.5 ) );
	}
}
