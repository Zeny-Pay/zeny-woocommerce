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

	// The shopper only gets a link URL after the order stores it, so a link no order holds is not ours.
	public function test_event_for_a_link_no_order_holds_is_acknowledged_and_ignored(): void {
		$order    = $this->orderWithLink();
		$response = $this->deliver( $this->paidEvent( $order, array( 'paymentLink' => 'pl_from_elsewhere' ) ) );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( array( 'ignored' => 'unknown_link' ), $response->get_data() );
		$this->assertFalse( $this->reload( $order )->is_paid() );
		$this->assertSame( 'pending', $this->reload( $order )->get_status() );
	}

	// Merchants must be able to find a paid but unmatched link with debug logging off.
	public function test_unknown_paid_link_is_logged_as_an_error_with_ids_only(): void {
		$order = $this->orderWithLink();
		$this->deliver( $this->paidEvent( $order, array( 'paymentLink' => 'pl_unmatched_7' ), 'evt_unmatched_7' ) );

		$log = $this->zenyLog();
		$this->assertMatchesRegularExpression( '/ERROR.*pl_unmatched_7/', $log );
		$this->assertStringContainsString( 'evt_unmatched_7', $log );
		$this->assertStringNotContainsString( 'amountReceived', $log );
	}

	// A trashed order still holds its links: the money it took must reach a person.
	public function test_payment_for_a_trashed_order_puts_it_on_hold(): void {
		$order = $this->orderWithLink();
		$event = $this->paidEvent( $order );
		$id    = $order->get_id();
		$order->delete( false ); // Trashes it and sets the object's id to 0.
		$order = wc_get_order( $id );
		$this->assertInstanceOf( WC_Order::class, $order );
		$this->assertSame( 'trash', $order->get_status() );

		$this->assertSame( 200, $this->deliver( $event )->get_status() );

		$order = $this->reload( $order );
		$this->assertSame( 'on-hold', $order->get_status() );
		$this->assertFalse( $order->is_paid() );
		$this->assertNoteContains( $order, 'trash' );
		$this->assertMatchesRegularExpression( '/ERROR.*trash.*"order":' . $order->get_id() . '/', $this->zenyLog() );
	}

	public function test_event_without_a_usable_api_key_answers_500_so_zeny_retries(): void {
		$order = $this->orderWithLink();
		$this->configure( array( 'api_key' => '' ) );
		$this->assertSame( 500, $this->deliver( $this->paidEvent( $order ) )->get_status() );
		$this->assertFalse( $this->reload( $order )->is_paid() );
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

	public function test_payment_for_an_order_marked_paid_by_hand_gets_its_own_note(): void {
		$order = $this->orderWithLink();
		$order->set_status( 'processing' );
		$order->save();
		$this->assertSame( '', $order->get_transaction_id() );

		$this->assertSame( 200, $this->deliver( $this->paidEvent( $order ) )->get_status() );

		$order = $this->reload( $order );
		$this->assertSame( 'processing', $order->get_status() );
		$this->assertSame( '', $order->get_transaction_id() );
		$this->assertNoteContains( $order, 'already marked paid without a Zeny payment' );
	}

	/**
	 * @dataProvider roles
	 */
	public function test_manual_confirmation_needs_the_right_to_edit_orders( string $role, bool $allowed ): void {
		$order   = $this->orderWithLink();
		$user_id = wp_insert_user(
			array(
				'user_login' => 'zeny_' . $role . '_' . wp_generate_password( 6, false ),
				'user_pass'  => wp_generate_password(),
				'role'       => $role,
			)
		);
		$this->assertIsInt( $user_id );
		wp_set_current_user( $user_id );

		$this->assertSame( $allowed, \Zeny\WooCommerce\WooCommerce\OrderMetaBox::canConfirm( $order ) );
	}

	/**
	 * @return array<string, array{string, bool}>
	 */
	public function roles(): array {
		return array(
			'administrator' => array( 'administrator', true ),
			'shop manager'  => array( 'shop_manager', true ),
			'customer'      => array( 'customer', false ),
			'editor'        => array( 'editor', false ),
		);
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

	// The order keeps every link it had: a late payment through a replaced link still finds it.
	public function test_payment_through_a_replaced_link_reaches_the_order(): void {
		$order = $this->orderWithLink();
		$old   = $order->get_meta( '_zeny_payment_link_id' );
		$order->set_total( '90000' );
		$order->save();
		$this->gateway()->process_payment( $order->get_id() );
		$this->assertNotSame( $old, $this->reload( $order )->get_meta( '_zeny_payment_link_id' ) );

		// Paid through the old link at the old amount: found, and held for the merchant.
		$this->assertSame( 200, $this->deliver( $this->paidEvent( $order, array( 'paymentLink' => $old ) ) )->get_status() );
		$this->assertSame( 'on-hold', $this->reload( $order )->get_status() );
	}

	public function test_manual_confirmation_accepts_a_payment_through_a_replaced_link(): void {
		$order = $this->orderWithLink();
		$old   = $order->get_meta( '_zeny_payment_link_id' );
		$order->set_total( '90000' );
		$order->save();
		$this->gateway()->process_payment( $order->get_id() );
		$this->assertNotSame( $old, $this->reload( $order )->get_meta( '_zeny_payment_link_id' ) );
		StubZeny::addPayment(
			$this->payment(
				$order,
				array(
					'paymentLink'    => $old,
					'amountReceived' => array( 'value' => '90000.00', 'currency' => 'IDR' ),
				)
			)
		);

		$this->assertNull( $this->confirm( $order, 'pay_1' ) );
		$this->assertTrue( $this->reload( $order )->is_paid() );
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

	private function zenyLog(): string {
		$files = glob( wp_upload_dir()['basedir'] . '/wc-logs/zeny-*.log' );
		$log   = '';
		foreach ( is_array( $files ) ? $files : array() as $file ) {
			$log .= (string) file_get_contents( $file );
		}
		return $log;
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
