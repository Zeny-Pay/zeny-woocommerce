<?php

namespace Zeny\WooCommerce\Tests\Unit\Domain;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Zeny\WooCommerce\Domain\Money;

final class MoneyTest extends TestCase {

	/**
	 * @dataProvider canonical
	 */
	public function test_formats_idr_at_two_decimals( string $input, string $expected ): void {
		$this->assertSame( $expected, Money::idr( $input )->value() );
		$this->assertSame( 'IDR', Money::idr( $input )->currency() );
	}

	/**
	 * @return array<string, array{string, string}>
	 */
	public function canonical(): array {
		return array(
			'whole'          => array( '150000', '150000.00' ),
			'one decimal'    => array( '150000.5', '150000.50' ),
			'two decimals'   => array( '150000.25', '150000.25' ),
			'leading zeros'  => array( '0150000.00', '150000.00' ),
			'zero'           => array( '0', '0.00' ),
			'large'          => array( '99999999999999', '99999999999999.00' ),
		);
	}

	/**
	 * @dataProvider invalid
	 */
	public function test_rejects_values_that_are_not_plain_decimals( string $input ): void {
		$this->expectException( InvalidArgumentException::class );
		Money::idr( $input );
	}

	/**
	 * @return array<string, array{string}>
	 */
	public function invalid(): array {
		return array(
			'empty'          => array( '' ),
			'negative'       => array( '-1.00' ),
			'three decimals' => array( '1.005' ),
			'separator'      => array( '150,000' ),
			'exponent'       => array( '1e5' ),
			'spaces'         => array( ' 100' ),
			'dot only'       => array( '1.' ),
		);
	}

	public function test_from_api_requires_a_money_object(): void {
		$money = Money::fromApi( array( 'value' => '85000.00', 'currency' => 'IDR' ) );
		$this->assertNotNull( $money );
		$this->assertTrue( $money->equals( Money::idr( '85000' ) ) );
		$this->assertNull( Money::fromApi( null ) );
		$this->assertNull( Money::fromApi( array( 'value' => 85000, 'currency' => 'IDR' ) ) );
		$this->assertNull( Money::fromApi( array( 'value' => 'abc', 'currency' => 'IDR' ) ) );
		$this->assertNull( Money::fromApi( array( 'value' => '1.00' ) ) );
	}

	public function test_equality_needs_value_and_currency(): void {
		$usd = Money::fromApi( array( 'value' => '85000.00', 'currency' => 'USD' ) );
		$this->assertNotNull( $usd );
		$this->assertFalse( $usd->equals( Money::idr( '85000' ) ) );
		$this->assertFalse( Money::idr( '85000.01' )->equals( Money::idr( '85000' ) ) );
	}

	public function test_is_positive(): void {
		$this->assertTrue( Money::idr( '0.01' )->isPositive() );
		$this->assertFalse( Money::idr( '0.00' )->isPositive() );
	}

	public function test_to_api_shape(): void {
		$this->assertSame( array( 'value' => '85000.00', 'currency' => 'IDR' ), Money::idr( '85000' )->toArray() );
	}
}
