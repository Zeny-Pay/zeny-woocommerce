<?php

namespace Zeny\WooCommerce\Tests\Integration;

use WC_Order;
use WP_REST_Request;
use WP_REST_Response;
use Zeny\WooCommerce\Domain\WebhookVerifier;
use Zeny\WooCommerce\WooCommerce\OrderLock;

final class WebhookTest extends IntegrationTestCase {

	public function test_payment_paid_marks_the_order_paid(): void {
		$order    = $this->orderWithLink();
		$response = $this->deliver( $this->paidEvent( $order ) );

		$this->assertSame( 200, $response->get_status() );
		$order = $this->reload( $order );
		$this->assertTrue( $order->is_paid() );
		$this->assertSame( 'pay_1', $order->get_transaction_id() );
		$this->assertNoteContains( $order, 'pay_1' );
	}

	// Review focus 1: the event beats process_payment saving the link, so Zeny must retry.
	public function test_event_for_a_link_no_order_holds_yet_answers_500(): void {
		$order = $this->order();
		$event = $this->paidEvent( $order, array( 'paymentLink' => 'pl_not_saved_yet' ) );

		$this->assertSame( 500, $this->deliver( $event )->get_status() );
		$this->assertFalse( $this->reload( $order )->is_paid() );

		// The retry, once the link is stored, completes the order.
		$order->update_meta_data( '_zeny_payment_link_id', 'pl_not_saved_yet' );
		$order->update_meta_data( '_zeny_mode', 'test' );
		$order->save();
		$this->assertSame( 200, $this->deliver( $event )->get_status() );
		$this->assertTrue( $this->reload( $order )->is_paid() );
	}

	// Review focus 1: a delivery that cannot take the order lock is retried, not dropped.
	public function test_event_while_the_order_is_locked_answers_500(): void {
		$order = $this->orderWithLink();
		$lock  = new OrderLock( 0 );
		$this->assertTrue( $lock->acquire( $order->get_id() ) );
		try {
			add_filter( 'zeny_webhook_lock_wait_ms', '__return_zero' );
			$status = $this->deliver( $this->paidEvent( $order ) )->get_status();
		} finally {
			remove_filter( 'zeny_webhook_lock_wait_ms', '__return_zero' );
			$lock->release( $order->get_id() );
		}
		$this->assertSame( 500, $status );
		$this->assertFalse( $this->reload( $order )->is_paid() );
	}

	// Review focus 3: the same event twice completes once.
	public function test_duplicate_event_is_acknowledged_and_ignored(): void {
		$order = $this->orderWithLink();
		$event = $this->paidEvent( $order );
		$this->deliver( $event );
		$notes = count( $this->notes( $order ) );

		$this->assertSame( 200, $this->deliver( $event )->get_status() );
		$this->assertCount( $notes, $this->notes( $this->reload( $order ) ) );
	}

	// Review focus 3: a new event for an already-paid order does not complete it again.
	public function test_event_for_an_already_paid_order_is_a_no_op(): void {
		$order = $this->orderWithLink();
		$this->deliver( $this->paidEvent( $order ) );
		$completed = did_action( 'woocommerce_payment_complete' );

		$response = $this->deliver( $this->paidEvent( $order, array(), 'evt_2' ) );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( $completed, did_action( 'woocommerce_payment_complete' ) );
		$this->assertTrue( $this->reload( $order )->is_paid() );
	}

	// Review focus 3: a second, different payment on a paid order is flagged for a refund.
	public function test_second_payment_on_a_paid_order_is_flagged(): void {
		$order = $this->orderWithLink();
		$this->deliver( $this->paidEvent( $order ) );
		$this->deliver( $this->paidEvent( $order, array( 'id' => 'pay_2' ), 'evt_2' ) );

		$order = $this->reload( $order );
		$this->assertSame( 'pay_1', $order->get_transaction_id() );
		$this->assertNoteContains( $order, 'pay_2' );
	}

	// Review focus 3: money taken for a cancelled order puts it on hold for the merchant.
	public function test_payment_for_a_cancelled_order_puts_it_on_hold(): void {
		$order = $this->orderWithLink();
		$order->update_status( 'cancelled' );

		$this->assertSame( 200, $this->deliver( $this->paidEvent( $order ) )->get_status() );
		$order = $this->reload( $order );
		$this->assertSame( 'on-hold', $order->get_status() );
		$this->assertFalse( $order->is_paid() );
		$this->assertNoteContains( $order, 'cancelled' );
	}

	/**
	 * @dataProvider mismatches
	 *
	 * @param array<string, mixed> $payment Payment overrides.
	 */
	public function test_mismatched_payment_puts_the_order_on_hold( array $payment, string $mode ): void {
		$order = $this->orderWithLink();

		$this->assertSame( 200, $this->deliver( $this->paidEvent( $order, $payment, 'evt_1', $mode ) )->get_status() );
		$order = $this->reload( $order );
		$this->assertSame( 'on-hold', $order->get_status() );
		$this->assertFalse( $order->is_paid() );
		$this->assertSame( '', $order->get_transaction_id() );
	}

	/**
	 * @return array<string, array{array<string, mixed>, string}>
	 */
	public function mismatches(): array {
		return array(
			'amount'   => array( array( 'amountReceived' => array( 'value' => '1000.00', 'currency' => 'IDR' ) ), 'test' ),
			'currency' => array( array( 'amountReceived' => array( 'value' => '150000.00', 'currency' => 'USD' ) ), 'test' ),
			'mode'     => array( array(), 'live' ),
		);
	}

	public function test_bad_signature_is_400_and_changes_nothing(): void {
		$order = $this->orderWithLink();
		$body  = (string) wp_json_encode( $this->paidEvent( $order ) );

		$this->assertSame( 400, $this->deliverRaw( $body, 'sha256=' . str_repeat( '0', 64 ), (string) time() )->get_status() );
		$this->assertSame( 400, $this->deliverRaw( $body, null, (string) time() )->get_status() );
		$stale = (string) ( time() - 301 );
		$this->assertSame( 400, $this->deliverRaw( $body, WebhookVerifier::sign( self::SECRET, $stale, $body ), $stale )->get_status() );
		$this->assertFalse( $this->reload( $order )->is_paid() );
	}

	public function test_missing_secret_answers_500_so_zeny_retries(): void {
		$this->configure( array( 'webhook_secret' => '' ) );
		$order = $this->orderWithLink();
		$this->assertSame( 500, $this->deliver( $this->paidEvent( $order ) )->get_status() );
	}

	public function test_unknown_event_type_is_acknowledged(): void {
		$event         = $this->paidEvent( $this->orderWithLink() );
		$event['type'] = 'payment.expired';
		$this->assertSame( 200, $this->deliver( $event )->get_status() );
	}

	public function test_manual_confirmation_by_payment_id_completes_the_order(): void {
		$order = $this->orderWithLink();
		StubZeny::addPayment( $this->payment( $order ) );

		$result = $this->confirm( $order, 'pay_1' );

		$this->assertNull( $result );
		$this->assertTrue( $this->reload( $order )->is_paid() );
		$this->assertSame( 'pay_1', $this->reload( $order )->get_transaction_id() );
	}

	public function test_manual_confirmation_refuses_a_payment_of_another_link(): void {
		$order = $this->orderWithLink();
		StubZeny::addPayment( $this->payment( $order, array( 'paymentLink' => 'pl_other' ) ) );

		$this->assertNotNull( $this->confirm( $order, 'pay_1' ) );
		$this->assertFalse( $this->reload( $order )->is_paid() );
	}

	public function test_manual_confirmation_reports_an_unknown_payment(): void {
		$order = $this->orderWithLink();
		$this->assertNotNull( $this->confirm( $order, 'pay_missing' ) );
		$this->assertNotNull( $this->confirm( $order, 'not-an-id' ) );
		$this->assertFalse( $this->reload( $order )->is_paid() );
	}

	public function test_thank_you_status_check_needs_the_order_key(): void {
		$order = $this->orderWithLink();
		$check = \Zeny\WooCommerce\WooCommerce\ReturnHandler::status( $order->get_id(), 'wrong' );
		$this->assertNull( $check );

		$this->assertFalse( \Zeny\WooCommerce\WooCommerce\ReturnHandler::status( $order->get_id(), $order->get_order_key() ) );
		$this->deliver( $this->paidEvent( $order ) );
		$this->assertTrue( \Zeny\WooCommerce\WooCommerce\ReturnHandler::status( $order->get_id(), $order->get_order_key() ) );
	}

	private function orderWithLink(): WC_Order {
		$order = $this->order();
		$this->gateway()->process_payment( $order->get_id() );
		return $this->reload( $order );
	}

	private function confirm( WC_Order $order, string $payment_id ): ?string {
		return \Zeny\WooCommerce\WooCommerce\OrderMetaBox::confirm( $this->reload( $order ), $payment_id );
	}

	/**
	 * @param array<string, mixed> $override Payment overrides.
	 * @return array<string, mixed>
	 */
	private function payment( WC_Order $order, array $override = array() ): array {
		return array_merge(
			array(
				'id'             => 'pay_1',
				'object'         => 'payment',
				'status'         => 'paid',
				'paymentLink'    => $order->get_meta( '_zeny_payment_link_id' ),
				'amount'         => array( 'value' => '150000.00', 'currency' => 'IDR' ),
				'amountReceived' => array( 'value' => '150000.00', 'currency' => 'IDR' ),
			),
			$override
		);
	}

	/**
	 * @param array<string, mixed> $override Payment overrides.
	 * @return array<string, mixed>
	 */
	private function paidEvent( WC_Order $order, array $override = array(), string $id = 'evt_1', string $mode = 'test' ): array {
		return array(
			'id'              => $id,
			'object'          => 'event',
			'type'            => 'payment.paid',
			'apiVersion'      => 'v1',
			'createdAt'       => gmdate( 'Y-m-d\TH:i:s\Z' ),
			'mode'            => $mode,
			'resourceVersion' => 2,
			'data'            => array( 'object' => $this->payment( $order, $override ) ),
		);
	}

	/**
	 * @param array<string, mixed> $event Event.
	 */
	private function deliver( array $event ): WP_REST_Response {
		$body = (string) wp_json_encode( $event );
		$ts   = (string) time();
		return $this->deliverRaw( $body, WebhookVerifier::sign( self::SECRET, $ts, $body ), $ts );
	}

	private function deliverRaw( string $body, ?string $signature, string $timestamp ): WP_REST_Response {
		$request = new WP_REST_Request( 'POST', '/zeny/v1/webhook' );
		$request->set_header( 'Content-Type', 'application/json' );
		$request->set_header( 'Webhook-Timestamp', $timestamp );
		if ( null !== $signature ) {
			$request->set_header( 'Webhook-Signature', $signature );
		}
		$request->set_body( $body );
		return rest_do_request( $request );
	}
}
