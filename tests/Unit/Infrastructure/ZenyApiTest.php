<?php

namespace Zeny\WooCommerce\Tests\Unit\Infrastructure;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Zeny\WooCommerce\Domain\ApiKey;
use Zeny\WooCommerce\Domain\IdempotencyKey;
use Zeny\WooCommerce\Infrastructure\ZenyApi;
use Zeny\WooCommerce\Infrastructure\ZenyApiException;

final class ZenyApiTest extends TestCase {

	private const LINK = array(
		'id'     => 'pl_1',
		'object' => 'payment_link',
		'url'    => 'https://pay.usezeny.com/l/pl_1',
		'status' => 'active',
	);

	/** @var FakeTransport */
	private $transport;

	/** @var list<int> */
	private $sleeps = array();

	/** @var float */
	private $clock = 1000.0;

	protected function setUp(): void {
		$this->transport = new FakeTransport();
		$this->sleeps    = array();
		$this->clock     = 1000.0;
	}

	public function test_create_posts_the_body_with_auth_and_idempotency_key(): void {
		$this->transport->respond( 201, self::LINK, array( 'x-request-id' => 'req_1' ) );

		$link = $this->api()->createPaymentLink( array( 'name' => 'Order #1' ), IdempotencyKey::parse( 'wc-abc-1-1' ) );

		$this->assertSame( self::LINK, $link );
		$request = $this->transport->requests[0];
		$this->assertSame( 'POST', $request['method'] );
		$this->assertSame( 'https://snorlax.usezeny.com/api/merchant/v1/payment-links', $request['url'] );
		$this->assertSame( '{"name":"Order #1"}', $request['body'] );
		$this->assertSame( 30, $request['timeout'] );
		$this->assertSame( 'Bearer sk_test_abc', $request['headers']['Authorization'] );
		$this->assertSame( 'wc-abc-1-1', $request['headers']['Idempotency-Key'] );
		$this->assertSame( 'application/json', $request['headers']['Content-Type'] );
		$this->assertSame( 'application/json', $request['headers']['Accept'] );
		$this->assertSame( 'zeny-woocommerce/1.0.0', $request['headers']['User-Agent'] );
	}

	public function test_live_key_uses_the_production_host(): void {
		$this->transport->respond( 200, self::LINK );
		$this->api( 'sk_live_abc' )->retrievePaymentLink( 'pl_1' );
		$this->assertSame( 'https://api.usezeny.com/api/merchant/v1/payment-links/pl_1', $this->transport->requests[0]['url'] );
	}

	public function test_get_routes_send_no_body_and_no_idempotency_key(): void {
		$this->transport->respond( 200, array( 'id' => 'pay_1' ) )->respond( 200, array( 'data' => array() ) );
		$api = $this->api();
		$api->retrievePayment( 'pay_1' );
		$api->listPaymentLinks( 1 );

		$this->assertSame( 'https://snorlax.usezeny.com/api/merchant/v1/payments/pay_1', $this->transport->requests[0]['url'] );
		$this->assertSame( 'https://snorlax.usezeny.com/api/merchant/v1/payment-links?limit=1', $this->transport->requests[1]['url'] );
		foreach ( $this->transport->requests as $request ) {
			$this->assertSame( 'GET', $request['method'] );
			$this->assertNull( $request['body'] );
			$this->assertArrayNotHasKey( 'Idempotency-Key', $request['headers'] );
			$this->assertArrayNotHasKey( 'Content-Type', $request['headers'] );
		}
	}

	public function test_cancel_posts_with_a_generated_key_reused_on_retry(): void {
		$this->transport->respond( 503, array( 'error' => array( 'type' => 'overloaded', 'code' => 'overloaded' ) ) )
			->respond( 200, array( 'id' => 'pl_1', 'status' => 'canceled' ) );

		$this->api()->cancelPaymentLink( 'pl_1' );

		$this->assertCount( 2, $this->transport->requests );
		$first = $this->transport->requests[0];
		$this->assertSame( 'https://snorlax.usezeny.com/api/merchant/v1/payment-links/pl_1/cancel', $first['url'] );
		$this->assertSame( '{}', $first['body'] );
		$this->assertMatchesRegularExpression( '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/', $first['headers']['Idempotency-Key'] );
		$this->assertSame( $first['headers']['Idempotency-Key'], $this->transport->requests[1]['headers']['Idempotency-Key'] );
	}

	/**
	 * @dataProvider retryable
	 *
	 * @param callable(FakeTransport): void $first Queues the failing first answer.
	 */
	public function test_retries_transient_failures_with_the_same_key_and_body( callable $first ): void {
		$first( $this->transport );
		$this->transport->respond( 201, self::LINK );

		$link = $this->api()->createPaymentLink( array( 'name' => 'Order #1' ), IdempotencyKey::parse( 'wc-abc-1-1' ) );

		$this->assertSame( 'pl_1', $link['id'] );
		$this->assertCount( 2, $this->transport->requests );
		$this->assertSame( $this->transport->requests[0]['headers']['Idempotency-Key'], $this->transport->requests[1]['headers']['Idempotency-Key'] );
		$this->assertSame( $this->transport->requests[0]['body'], $this->transport->requests[1]['body'] );
		$this->assertCount( 1, $this->sleeps );
	}

	/**
	 * @return array<string, array{callable(FakeTransport): void}>
	 */
	public function retryable(): array {
		$error = static function ( string $type, string $code ): array {
			return array(
				'error' => array(
					'type' => $type,
					'code' => $code,
				),
			);
		};
		return array(
			'network error'   => array( static function ( FakeTransport $t ): void { $t->fail( 'network_error' ); } ),
			'timeout'         => array( static function ( FakeTransport $t ): void { $t->fail( 'timeout' ); } ),
			'500'             => array( static function ( FakeTransport $t ) use ( $error ): void { $t->respond( 500, $error( 'api_error', 'internal_error' ) ); } ),
			'502 not json'    => array( static function ( FakeTransport $t ): void { $t->respond( 502, '<html>Bad gateway</html>' ); } ),
			'429'             => array( static function ( FakeTransport $t ) use ( $error ): void { $t->respond( 429, $error( 'rate_limit', 'rate_limited' ) ); } ),
			'409 in progress' => array( static function ( FakeTransport $t ) use ( $error ): void { $t->respond( 409, $error( 'conflict', 'idempotency_key_in_progress' ) ); } ),
		);
	}

	public function test_retry_after_sets_the_delay_and_is_capped_at_60_seconds(): void {
		$this->transport->respond( 429, array(), array( 'retry-after' => '3' ) )
			->respond( 429, array(), array( 'retry-after' => '600' ) )
			->respond( 201, self::LINK );

		$this->api()->createPaymentLink( array(), IdempotencyKey::parse( 'k' ) );

		$this->assertSame( array( 3000, 60000 ), $this->sleeps );
	}

	public function test_gives_up_after_two_retries_with_the_last_error(): void {
		$this->transport->fail( 'timeout' )->fail( 'timeout' )->fail( 'timeout' );
		try {
			$this->api()->createPaymentLink( array(), IdempotencyKey::parse( 'wc-abc-1-1' ) );
			$this->fail( 'expected ZenyApiException' );
		} catch ( ZenyApiException $e ) {
			$this->assertCount( 3, $this->transport->requests );
			$this->assertSame( 'connection_error', $e->type() );
			$this->assertSame( 'timeout', $e->errorCode() );
			$this->assertNull( $e->statusCode() );
			$this->assertSame( 'wc-abc-1-1', $e->idempotencyKey() );
			$this->assertTrue( $e->isOutcomeUnknown() );
		}
	}

	/**
	 * @dataProvider final4xx
	 */
	public function test_does_not_retry_final_errors( int $status, string $type, string $code ): void {
		$this->transport->respond(
			$status,
			array(
				'error' => array(
					'type'      => $type,
					'code'      => $code,
					'message'   => 'name: value is required',
					'param'     => 'name',
					'requestId' => 'req_body',
				),
			),
			array( 'x-request-id' => 'req_header' )
		);
		try {
			$this->api()->createPaymentLink( array(), IdempotencyKey::parse( 'k' ) );
			$this->fail( 'expected ZenyApiException' );
		} catch ( ZenyApiException $e ) {
			$this->assertCount( 1, $this->transport->requests );
			$this->assertSame( array(), $this->sleeps );
			$this->assertSame( $status, $e->statusCode() );
			$this->assertSame( $type, $e->type() );
			$this->assertSame( $code, $e->errorCode() );
			$this->assertSame( 'name: value is required', $e->getMessage() );
			$this->assertSame( 'req_body', $e->requestId() );
			$this->assertFalse( $e->isOutcomeUnknown() );
		}
	}

	/**
	 * @return array<string, array{int, string, string}>
	 */
	public function final4xx(): array {
		return array(
			'400'            => array( 400, 'invalid_request', 'field_required' ),
			'401'            => array( 401, 'authentication', 'token_invalid' ),
			'403'            => array( 403, 'permission', 'merchant_not_live' ),
			'409 has paid'   => array( 409, 'conflict', 'payment_link_has_payments' ),
			'422 key reused' => array( 422, 'idempotency_error', 'idempotency_key_reused' ),
		);
	}

	public function test_in_progress_conflict_has_an_unknown_outcome(): void {
		$this->transport->respond( 409, array( 'error' => array( 'type' => 'conflict', 'code' => 'idempotency_key_in_progress' ) ) )
			->respond( 409, array( 'error' => array( 'type' => 'conflict', 'code' => 'idempotency_key_in_progress' ) ) )
			->respond( 409, array( 'error' => array( 'type' => 'conflict', 'code' => 'idempotency_key_in_progress' ) ) );
		try {
			$this->api()->createPaymentLink( array(), IdempotencyKey::parse( 'k' ) );
			$this->fail( 'expected ZenyApiException' );
		} catch ( ZenyApiException $e ) {
			$this->assertTrue( $e->isOutcomeUnknown() );
		}
	}

	public function test_error_without_the_error_shape_is_unexpected_response_with_header_request_id(): void {
		$this->transport->respond( 404, 'not found', array( 'x-request-id' => 'req_h' ) );
		try {
			$this->api()->retrievePayment( 'pay_1' );
			$this->fail( 'expected ZenyApiException' );
		} catch ( ZenyApiException $e ) {
			$this->assertSame( 'api_error', $e->type() );
			$this->assertSame( 'unexpected_response', $e->errorCode() );
			$this->assertSame( 404, $e->statusCode() );
			$this->assertSame( 'req_h', $e->requestId() );
		}
	}

	/**
	 * @dataProvider oddSuccess
	 */
	public function test_success_that_is_not_a_json_object_is_not_retried( string $body ): void {
		$this->transport->respond( 200, $body );
		$this->expectException( ZenyApiException::class );
		$this->expectExceptionMessage( 'not a JSON object' );
		try {
			$this->api()->retrievePaymentLink( 'pl_1' );
		} finally {
			$this->assertCount( 1, $this->transport->requests );
		}
	}

	/**
	 * @return array<string, array{string}>
	 */
	public function oddSuccess(): array {
		return array(
			'html'  => array( '<html></html>' ),
			'array' => array( '[1]' ),
			'empty' => array( '' ),
		);
	}

	public function test_errors_never_carry_the_api_key(): void {
		$this->transport->fail( 'network_error' )->fail( 'network_error' )->fail( 'network_error' );
		try {
			$this->api()->retrievePaymentLink( 'pl_1' );
		} catch ( ZenyApiException $e ) {
			$this->assertStringNotContainsString( 'sk_test_abc', $e->getMessage() . $e->getTraceAsString() );
		}
	}

	/**
	 * @dataProvider badIds
	 */
	public function test_ids_are_checked_before_any_request( string $method, string $id ): void {
		try {
			$this->api()->$method( $id );
			$this->fail( 'expected InvalidArgumentException' );
		} catch ( InvalidArgumentException $e ) {
			$this->assertSame( array(), $this->transport->requests );
		}
	}

	/**
	 * @return array<string, array{string, string}>
	 */
	public function badIds(): array {
		return array(
			'empty link'       => array( 'retrievePaymentLink', '' ),
			'prefix only'      => array( 'retrievePaymentLink', 'pl_' ),
			'payment as link'  => array( 'cancelPaymentLink', 'pay_1' ),
			'link as payment'  => array( 'retrievePayment', 'pl_1' ),
			'path traversal'   => array( 'retrievePayment', 'pay_1/../../x' ),
		);
	}

	public function test_base_url_override_accepts_https_or_loopback_http(): void {
		$this->transport->respond( 200, self::LINK )->respond( 200, self::LINK );
		$this->api( 'sk_test_abc', 'https://zeny.example/' )->retrievePaymentLink( 'pl_1' );
		$this->api( 'sk_test_abc', 'http://127.0.0.1:8099' )->retrievePaymentLink( 'pl_1' );
		$this->assertSame( 'https://zeny.example/api/merchant/v1/payment-links/pl_1', $this->transport->requests[0]['url'] );
		$this->assertSame( 'http://127.0.0.1:8099/api/merchant/v1/payment-links/pl_1', $this->transport->requests[1]['url'] );
	}

	/**
	 * @dataProvider badBaseUrls
	 */
	public function test_base_url_override_rejects_unsafe_urls( string $url ): void {
		$this->expectException( InvalidArgumentException::class );
		$this->api( 'sk_test_abc', $url );
	}

	/**
	 * @return array<string, array{string}>
	 */
	public function badBaseUrls(): array {
		return array(
			'plain http'     => array( 'http://zeny.example' ),
			'user info'      => array( 'https://user:pass@zeny.example' ),
			'not a url'      => array( 'zeny.example' ),
			'other protocol' => array( 'ftp://zeny.example' ),
		);
	}

	public function test_checkout_limits_allow_one_retry(): void {
		$this->transport->fail( 'timeout' )->fail( 'timeout' )->fail( 'timeout' );
		try {
			$this->api()->withCheckoutLimits()->createPaymentLink( array(), IdempotencyKey::parse( 'k' ) );
			$this->fail( 'expected ZenyApiException' );
		} catch ( ZenyApiException $e ) {
			$this->assertCount( 2, $this->transport->requests );
		}
	}

	public function test_checkout_limits_cap_retry_after_at_5_seconds(): void {
		$this->transport->respond( 429, array(), array( 'retry-after' => '30' ) )->respond( 201, self::LINK );
		$this->api()->withCheckoutLimits()->createPaymentLink( array(), IdempotencyKey::parse( 'k' ) );
		$this->assertSame( array( 5000 ), $this->sleeps );
	}

	public function test_checkout_limits_keep_every_call_inside_the_budget(): void {
		// Each request takes 12 s; the budget is 20 s.
		$this->transport->onSend = function (): void {
			$this->clock += 12;
		};
		$this->transport->respond( 503, array() )->respond( 503, array() )->respond( 200, self::LINK );
		$api = $this->api()->withCheckoutLimits();

		try {
			$api->createPaymentLink( array(), IdempotencyKey::parse( 'k' ) );
			$this->fail( 'expected ZenyApiException' );
		} catch ( ZenyApiException $e ) {
			$this->assertCount( 2, $this->transport->requests );
			$this->assertSame( 20, $this->transport->requests[0]['timeout'] );
			// The retry may only use what is left: 20 - 12 - 0.25 s of backoff.
			$this->assertLessThanOrEqual( 8, $this->transport->requests[1]['timeout'] );
		}

		// The budget is spent: a further call fails without being sent.
		try {
			$api->retrievePaymentLink( 'pl_1' );
			$this->fail( 'expected ZenyApiException' );
		} catch ( ZenyApiException $e ) {
			$this->assertCount( 2, $this->transport->requests );
			$this->assertSame( 'timeout', $e->errorCode() );
			$this->assertNull( $e->statusCode() );
		}
	}

	public function test_no_retry_when_the_wait_would_pass_the_budget(): void {
		$this->transport->respond( 429, array(), array( 'retry-after' => '5' ) )->respond( 201, self::LINK );
		$this->transport->onSend = function (): void {
			$this->clock += 16;
		};
		$this->expectException( ZenyApiException::class );
		try {
			$this->api()->withCheckoutLimits()->createPaymentLink( array(), IdempotencyKey::parse( 'k' ) );
		} finally {
			$this->assertCount( 1, $this->transport->requests );
			$this->assertSame( array(), $this->sleeps );
		}
	}

	public function test_normal_policy_is_unchanged_by_a_checkout_copy(): void {
		$api = $this->api();
		$api->withCheckoutLimits();
		$this->transport->fail( 'timeout' )->fail( 'timeout' )->respond( 200, self::LINK );
		$api->retrievePaymentLink( 'pl_1' );
		$this->assertCount( 3, $this->transport->requests );
		$this->assertSame( 30, $this->transport->requests[0]['timeout'] );
	}

	public function test_mode_follows_the_key(): void {
		$this->assertSame( 'test', $this->api()->mode() );
		$this->assertSame( 'live', $this->api( 'sk_live_x' )->mode() );
	}

	private function api( string $key = 'sk_test_abc', ?string $base_url = null ): ZenyApi {
		return new ZenyApi(
			ApiKey::parse( $key ),
			$this->transport,
			'zeny-woocommerce/1.0.0',
			$base_url,
			function ( int $ms ): void {
				$this->sleeps[] = $ms;
				$this->clock   += $ms / 1000;
			},
			static function (): float {
				return 0.5;
			},
			function (): float {
				return $this->clock;
			}
		);
	}
}
