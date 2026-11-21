<?php

namespace Zeny\WooCommerce\Tests\Unit\Domain;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Zeny\WooCommerce\Domain\LinkSpec;
use Zeny\WooCommerce\Domain\Money;

final class LinkSpecTest extends TestCase {

	private const NOW = 1792552500; // 2026-10-21T03:15:00Z

	public function test_builds_the_full_request_body(): void {
		$spec = new LinkSpec(
			'Order #1001 – Toko Kopi',
			Money::idr( '150000' ),
			array( 'Kopi Susu 1L', 'Croissant' ),
			'https://shop.example/checkout/order-received/1001/?key=wc_order_x',
			'https://shop.example/checkout/order-pay/1001/?pay_for_order=true&key=wc_order_x',
			60,
			array( 'QRIS', 'BCA' ),
			array( 'email', 'name' )
		);

		$this->assertSame(
			array(
				'name'                => 'Order #1001 – Toko Kopi',
				'amount'              => array(
					'value'    => '150000.00',
					'currency' => 'IDR',
				),
				'maxPayments'         => 1,
				'expiresAt'           => '2026-10-21T04:15:00Z',
				'description'         => 'Kopi Susu 1L, Croissant',
				'successRedirectUrl'  => 'https://shop.example/checkout/order-received/1001/?key=wc_order_x',
				'expiredRedirectUrl'  => 'https://shop.example/checkout/order-pay/1001/?pay_for_order=true&key=wc_order_x',
				'instruments'         => array( 'QRIS', 'BCA' ),
				'requiredPayerFields' => array( 'name', 'email' ),
			),
			$spec->toRequestBody( self::NOW )
		);
	}

	public function test_minimal_body_omits_empty_optional_fields(): void {
		$body = $this->spec( array( 'items' => array(), 'success' => null, 'expired' => null ) )->toRequestBody( self::NOW );
		$this->assertSame( array( 'name', 'amount', 'maxPayments', 'expiresAt' ), array_keys( $body ) );
	}

	public function test_http_redirect_urls_are_omitted(): void {
		$body = $this->spec( array( 'success' => 'http://shop.example/thanks', 'expired' => 'http://shop.example/pay' ) )->toRequestBody( self::NOW );
		$this->assertArrayNotHasKey( 'successRedirectUrl', $body );
		$this->assertArrayNotHasKey( 'expiredRedirectUrl', $body );
	}

	/**
	 * @dataProvider unusableUrls
	 */
	public function test_unusable_redirect_urls_are_omitted( string $url ): void {
		$body = $this->spec( array( 'success' => $url ) )->toRequestBody( self::NOW );
		$this->assertArrayNotHasKey( 'successRedirectUrl', $body );
	}

	/**
	 * @return array<string, array{string}>
	 */
	public function unusableUrls(): array {
		return array(
			'no host'  => array( 'https://' ),
			'relative' => array( '/checkout' ),
			'too long' => array( 'https://shop.example/' . str_repeat( 'a', 2030 ) ),
			'space'    => array( 'https://shop.example/a b' ),
		);
	}

	public function test_https_reports_whether_the_success_url_is_kept(): void {
		$this->assertTrue( $this->spec( array() )->hasSuccessRedirect() );
		$this->assertFalse( $this->spec( array( 'success' => 'http://shop.example/' ) )->hasSuccessRedirect() );
	}

	public function test_name_is_trimmed_to_120_characters_without_control_characters(): void {
		$name = "  Order #1\t– " . str_repeat( 'é', 200 ) . '  ';
		$body = $this->spec( array( 'name' => $name ) )->toRequestBody( self::NOW );
		$this->assertSame( 120, mb_strlen( $body['name'] ) );
		$this->assertStringStartsWith( 'Order #1 – é', $body['name'] );
	}

	public function test_name_cut_at_a_space_is_trimmed_again(): void {
		$body = $this->spec( array( 'name' => str_repeat( 'a', 119 ) . ' b' ) )->toRequestBody( self::NOW );
		$this->assertSame( str_repeat( 'a', 119 ), $body['name'] );
	}

	public function test_invalid_utf8_never_reaches_the_body(): void {
		$body = $this->spec(
			array(
				'name'  => "Order #1 \xC3\x28 Kopi",
				'items' => array( "Susu \xFF\xFE", 'Roti' ),
			)
		)->toRequestBody( self::NOW );

		$this->assertTrue( mb_check_encoding( $body['name'], 'UTF-8' ) );
		$this->assertTrue( mb_check_encoding( $body['description'], 'UTF-8' ) );
		$this->assertStringStartsWith( 'Order #1', $body['name'] );
		$this->assertStringEndsWith( 'Roti', $body['description'] );
		$this->assertNotFalse( json_encode( $body ) );
	}

	public function test_empty_name_is_rejected(): void {
		$this->expectException( InvalidArgumentException::class );
		$this->spec( array( 'name' => " \n " ) );
	}

	public function test_description_is_one_line_of_at_most_500_characters(): void {
		$items = array( "Line\none", str_repeat( 'x', 600 ) );
		$body  = $this->spec( array( 'items' => $items ) )->toRequestBody( self::NOW );
		$this->assertSame( 500, mb_strlen( $body['description'] ) );
		$this->assertStringStartsWith( 'Line one, xxx', $body['description'] );
	}

	public function test_amount_must_be_positive(): void {
		$this->expectException( InvalidArgumentException::class );
		$this->spec( array( 'total' => Money::idr( '0' ) ) );
	}

	/**
	 * @dataProvider lifetimes
	 */
	public function test_lifetime_is_clamped_to_the_allowed_range( int $minutes, string $expires ): void {
		$body = $this->spec( array( 'lifetime' => $minutes ) )->toRequestBody( self::NOW );
		$this->assertSame( $expires, $body['expiresAt'] );
	}

	/**
	 * @return array<string, array{int, string}>
	 */
	public function lifetimes(): array {
		return array(
			'below 15'   => array( 1, '2026-10-21T03:30:00Z' ),
			'15'         => array( 15, '2026-10-21T03:30:00Z' ),
			'10080'      => array( 10080, '2026-10-28T03:15:00Z' ),
			'above 7 d.' => array( 20000, '2026-10-28T03:15:00Z' ),
		);
	}

	public function test_instruments_are_upper_case_unique_and_valid_codes_only(): void {
		$body = $this->spec( array( 'instruments' => array( 'qris', 'QRIS', 'bad code', '', 'gopay' ) ) )->toRequestBody( self::NOW );
		$this->assertSame( array( 'QRIS', 'GOPAY' ), $body['instruments'] );
	}

	public function test_at_most_30_instruments(): void {
		$codes = array_map(
			static function ( int $i ): string {
				return 'I' . $i;
			},
			range( 1, 40 )
		);
		$body  = $this->spec( array( 'instruments' => $codes ) )->toRequestBody( self::NOW );
		$this->assertCount( 30, $body['instruments'] );
	}

	public function test_unknown_payer_fields_are_dropped_and_known_ones_ordered(): void {
		$body = $this->spec( array( 'fields' => array( 'phone', 'address', 'name', 'phone' ) ) )->toRequestBody( self::NOW );
		$this->assertSame( array( 'name', 'phone' ), $body['requiredPayerFields'] );
	}

	/**
	 * @param array<string, mixed> $o Overrides.
	 */
	private function spec( array $o ): LinkSpec {
		return new LinkSpec(
			array_key_exists( 'name', $o ) ? $o['name'] : 'Order #1',
			array_key_exists( 'total', $o ) ? $o['total'] : Money::idr( '1000' ),
			array_key_exists( 'items', $o ) ? $o['items'] : array( 'Item' ),
			array_key_exists( 'success', $o ) ? $o['success'] : 'https://shop.example/thanks',
			array_key_exists( 'expired', $o ) ? $o['expired'] : 'https://shop.example/pay',
			array_key_exists( 'lifetime', $o ) ? $o['lifetime'] : 60,
			array_key_exists( 'instruments', $o ) ? $o['instruments'] : array(),
			array_key_exists( 'fields', $o ) ? $o['fields'] : array()
		);
	}
}
