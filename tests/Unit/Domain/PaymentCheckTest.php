<?php

namespace Zeny\WooCommerce\Tests\Unit\Domain;

use PHPUnit\Framework\TestCase;
use Zeny\WooCommerce\Domain\Money;
use Zeny\WooCommerce\Domain\PaymentCheck;

final class PaymentCheckTest extends TestCase {

	public function test_matching_paid_payment_passes(): void {
		$this->assertNull( $this->check( array() ) );
	}

	/**
	 * @dataProvider mismatches
	 *
	 * @param array<string, mixed> $override Payment field overrides, or the event mode under "_mode".
	 */
	public function test_each_mismatch_is_named( array $override, string $problem ): void {
		$this->assertSame( $problem, $this->check( $override ) );
	}

	/**
	 * @return array<string, array{array<string, mixed>, string}>
	 */
	public function mismatches(): array {
		return array(
			'other mode'          => array( array( '_mode' => 'live' ), PaymentCheck::MODE_MISMATCH ),
			'unknown mode'        => array( array( '_mode' => '' ), PaymentCheck::MODE_MISMATCH ),
			'other link'          => array( array( 'paymentLink' => 'pl_other' ), PaymentCheck::LINK_MISMATCH ),
			'no link'             => array( array( 'paymentLink' => '' ), PaymentCheck::LINK_MISMATCH ),
			'pending'             => array( array( 'status' => 'pending' ), PaymentCheck::NOT_PAID ),
			'less received'       => array( array( 'amountReceived' => array( 'value' => '149999.00', 'currency' => 'IDR' ) ), PaymentCheck::AMOUNT_MISMATCH ),
			'more received'       => array( array( 'amountReceived' => array( 'value' => '150000.01', 'currency' => 'IDR' ) ), PaymentCheck::AMOUNT_MISMATCH ),
			'other currency'      => array( array( 'amountReceived' => array( 'value' => '150000.00', 'currency' => 'USD' ) ), PaymentCheck::AMOUNT_MISMATCH ),
			'amount missing'      => array( array( 'amountReceived' => null ), PaymentCheck::AMOUNT_MISMATCH ),
			'amount not a string' => array( array( 'amountReceived' => array( 'value' => 150000, 'currency' => 'IDR' ) ), PaymentCheck::AMOUNT_MISMATCH ),
		);
	}

	/**
	 * @param array<string, mixed> $override Overrides.
	 */
	private function check( array $override ): ?string {
		$mode = array_key_exists( '_mode', $override ) ? $override['_mode'] : 'test';
		unset( $override['_mode'] );
		$payment = array_merge(
			array(
				'id'             => 'pay_1',
				'status'         => 'paid',
				'paymentLink'    => 'pl_1',
				'amount'         => array( 'value' => '150000.00', 'currency' => 'IDR' ),
				'amountReceived' => array( 'value' => '150000.00', 'currency' => 'IDR' ),
			),
			$override
		);
		return PaymentCheck::problem( $payment, $mode, 'pl_1', Money::idr( '150000' ), 'test' );
	}
}
