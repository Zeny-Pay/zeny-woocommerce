<?php

namespace Zeny\WooCommerce\Tests\Integration;

use Zeny\WooCommerce\WooCommerce\AdminNotices;
use Zeny\WooCommerce\WooCommerce\OrderLock;

final class CheckoutTest extends IntegrationTestCase {

	public function test_gateway_is_registered_and_available_with_idr_and_a_key(): void {
		$gateways = WC()->payment_gateways()->payment_gateways();
		$this->assertArrayHasKey( 'zeny', $gateways );
		$this->assertTrue( $this->gateway()->is_available() );
		$this->assertNotContains( 'refunds', $this->gateway()->supports );
	}

	public function test_place_order_creates_one_link_and_redirects_to_it(): void {
		$order  = $this->order();
		$result = $this->gateway()->process_payment( $order->get_id() );

		$this->assertSame( 'success', $result['result'] );
		$creates = StubZeny::requests( 'POST', '/payment-links' );
		$this->assertCount( 1, $creates );
		$link_id = 'pl_' . substr( sha1( (string) $creates[0]['key'] ), 0, 16 );
		$this->assertSame( 'https://pay.zeny.test/l/' . $link_id, $result['redirect'] );
		$this->assertMatchesRegularExpression( '/^wc-[0-9a-f]{12}-' . $order->get_id() . '-1$/', (string) $creates[0]['key'] );
		$this->assertSame( 'Bearer ' . self::API_KEY, $creates[0]['auth'] );

		$body = $creates[0]['body'];
		$this->assertSame( array( 'value' => '150000.00', 'currency' => 'IDR' ), $body['amount'] );
		$this->assertSame( 1, $body['maxPayments'] );
		$this->assertStringStartsWith( 'Order #' . $order->get_order_number(), $body['name'] );
		$this->assertSame( 'Kopi Susu 1L', $body['description'] );

		$order = $this->reload( $order );
		$this->assertSame( $link_id, $order->get_meta( '_zeny_payment_link_id' ) );
		$this->assertSame( $result['redirect'], $order->get_meta( '_zeny_payment_link_url' ) );
		$this->assertSame( 'test', $order->get_meta( '_zeny_mode' ) );
		$this->assertSame( 'pending', $order->get_status() );
		$this->assertNoteContains( $order, $link_id );
	}

	public function test_settings_shape_the_link(): void {
		$this->configure(
			array(
				'link_lifetime'         => '30',
				'instruments'           => 'qris, bca',
				'required_payer_fields' => array( 'email', 'name' ),
			)
		);
		$this->gateway()->process_payment( $this->order()->get_id() );

		$body = StubZeny::requests( 'POST', '/payment-links' )[0]['body'];
		$this->assertSame( array( 'QRIS', 'BCA' ), $body['instruments'] );
		$this->assertSame( array( 'name', 'email' ), $body['requiredPayerFields'] );
		$expires = strtotime( $body['expiresAt'] );
		$this->assertEqualsWithDelta( time() + 30 * 60, $expires, 5 );
	}

	// Review focus 2: a double submit or back-button resubmit gets the same link.
	public function test_resubmitting_the_same_order_reuses_the_active_link(): void {
		$order  = $this->order();
		$first  = $this->gateway()->process_payment( $order->get_id() );
		$second = $this->gateway()->process_payment( $order->get_id() );

		$this->assertSame( 'success', $second['result'] );
		$this->assertSame( $first['redirect'], $second['redirect'] );
		$this->assertCount( 1, StubZeny::requests( 'POST', '/payment-links' ) );
	}

	// Review focus 2: a create whose answer was lost is retried with the same key and body.
	public function test_lost_answer_is_retried_with_the_same_key_and_body(): void {
		StubZeny::failNext( 'POST', '/payment-links', 500, 1, true );
		$order  = $this->order();
		$result = $this->gateway()->process_payment( $order->get_id() );

		$creates = StubZeny::requests( 'POST', '/payment-links' );
		$this->assertSame( 'success', $result['result'] );
		$this->assertCount( 2, $creates );
		$this->assertSame( $creates[0]['key'], $creates[1]['key'] );
		$this->assertSame( $creates[0]['body'], $creates[1]['body'] );
		$this->assertCount( 1, array_unique( array_column( array_column( $creates, 'body' ), 'expiresAt' ) ) );
	}

	// Review focus 2: after a failed checkout the shopper submits again with the same key and body.
	public function test_resubmit_after_a_failed_checkout_sends_the_same_key_and_body(): void {
		StubZeny::failNext( 'POST', '/payment-links', 503, 3 );
		$order = $this->order();
		$fail  = $this->gateway()->process_payment( $order->get_id() );
		$this->assertSame( 'failure', $fail['result'] );
		$this->assertCount( 1, wc_get_notices( 'error' ) );
		$this->assertStringContainsString( 'req_err', wc_get_notices( 'error' )[0]['notice'] );
		$this->assertNoteContains( $this->reload( $order ), 'internal_error' );

		$ok      = $this->gateway()->process_payment( $order->get_id() );
		$creates = StubZeny::requests( 'POST', '/payment-links' );
		$this->assertSame( 'success', $ok['result'] );
		$this->assertCount( 4, $creates );
		$this->assertCount( 1, array_unique( array_column( $creates, 'key' ) ) );
		$this->assertSame( $creates[0]['body'], $creates[3]['body'] );
	}

	// Review focus 2: a concurrent submit waits for the first, then reuses its link.
	public function test_submit_while_another_holds_the_order_lock_fails_softly(): void {
		$order = $this->order();
		$lock  = new OrderLock( 0 );
		$this->assertTrue( $lock->acquire( $order->get_id() ) );
		try {
			$result = $this->gateway()->process_payment( $order->get_id() );
		} finally {
			$lock->release( $order->get_id() );
		}
		$this->assertSame( 'failure', $result['result'] );
		$this->assertSame( array(), StubZeny::requests( 'POST', '/payment-links' ) );
	}

	public function test_order_lock_is_exclusive_and_released(): void {
		$lock = new OrderLock( 0 );
		$this->assertTrue( $lock->acquire( 99001 ) );
		$this->assertFalse( $lock->acquire( 99001 ) );
		$lock->release( 99001 );
		$this->assertTrue( $lock->acquire( 99001 ) );
		$lock->release( 99001 );
	}

	public function test_expired_link_starts_a_new_attempt(): void {
		$order = $this->order();
		$this->gateway()->process_payment( $order->get_id() );
		$old = $this->reload( $order )->get_meta( '_zeny_payment_link_id' );
		StubZeny::updateLink( $old, array( 'status' => 'expired' ) );

		$result  = $this->gateway()->process_payment( $order->get_id() );
		$creates = StubZeny::requests( 'POST', '/payment-links' );

		$this->assertCount( 2, $creates );
		$this->assertStringEndsWith( '-2', (string) $creates[1]['key'] );
		$new = $this->reload( $order )->get_meta( '_zeny_payment_link_id' );
		$this->assertNotSame( $old, $new );
		$this->assertSame( 'https://pay.zeny.test/l/' . $new, $result['redirect'] );
	}

	public function test_link_already_paid_sends_the_shopper_to_the_order_received_page(): void {
		$order = $this->order();
		$this->gateway()->process_payment( $order->get_id() );
		StubZeny::updateLink( $this->reload( $order )->get_meta( '_zeny_payment_link_id' ), array( 'paidCount' => 1, 'status' => 'closed' ) );

		$result = $this->gateway()->process_payment( $order->get_id() );

		$this->assertSame( 'success', $result['result'] );
		$this->assertSame( $this->reload( $order )->get_checkout_order_received_url(), $result['redirect'] );
		$this->assertCount( 1, StubZeny::requests( 'POST', '/payment-links' ) );
	}

	// Review focus 4: no https, no success redirect, checkout still works.
	public function test_http_site_creates_the_link_without_redirect_urls_and_warns_the_admin(): void {
		$result = $this->gateway()->process_payment( $this->order()->get_id() );

		$this->assertSame( 'success', $result['result'] );
		$body = StubZeny::requests( 'POST', '/payment-links' )[0]['body'];
		$this->assertArrayNotHasKey( 'successRedirectUrl', $body );
		$this->assertArrayNotHasKey( 'expiredRedirectUrl', $body );
		$this->assertStringContainsString( 'https', $this->adminNotices() );
	}

	public function test_https_site_sends_both_redirect_urls(): void {
		update_option( 'home', 'https://shop.example' );
		update_option( 'siteurl', 'https://shop.example' );
		$order = $this->order();
		$this->gateway()->process_payment( $order->get_id() );

		$body = StubZeny::requests( 'POST', '/payment-links' )[0]['body'];
		$this->assertSame( $this->reload( $order )->get_checkout_order_received_url(), $body['successRedirectUrl'] );
		$this->assertStringStartsWith( 'https://shop.example/', $body['expiredRedirectUrl'] );
		$this->assertStringNotContainsString( 'https', $this->adminNotices() );
	}

	// Review focus 5: wrong currency hides the gateway and tells the admin.
	public function test_non_idr_store_hides_the_gateway_and_tells_the_admin(): void {
		update_option( 'woocommerce_currency', 'USD' );
		$this->assertFalse( $this->gateway()->is_available() );
		$this->assertStringContainsString( 'IDR', $this->adminNotices() );
	}

	public function test_non_idr_order_is_refused_without_calling_zeny(): void {
		$order = $this->order();
		$order->set_currency( 'USD' );
		$order->save();
		$result = $this->gateway()->process_payment( $order->get_id() );
		$this->assertSame( 'failure', $result['result'] );
		$this->assertSame( array(), StubZeny::requests() );
	}

	// Review focus 5: a missing or malformed key hides the gateway and tells the admin.
	public function test_missing_key_hides_the_gateway_and_tells_the_admin(): void {
		foreach ( array( '', 'pk_test_wrong' ) as $key ) {
			$this->configure( array( 'api_key' => $key ) );
			$this->assertFalse( $this->gateway()->is_available() );
			$this->assertStringContainsString( 'API key', $this->adminNotices() );
		}
	}

	public function test_disabled_gateway_shows_no_notice(): void {
		$this->configure(
			array(
				'enabled' => 'no',
				'api_key' => '',
			)
		);
		update_option( 'woocommerce_currency', 'USD' );
		$this->assertSame( '', $this->adminNotices() );
	}

	public function test_api_error_keeps_the_order_pending_and_shows_a_generic_notice(): void {
		StubZeny::failNext( 'POST', '/payment-links', 400, 1, false, 'field_required' );
		$order  = $this->order();
		$result = $this->gateway()->process_payment( $order->get_id() );

		$this->assertSame( 'failure', $result['result'] );
		$notice = wc_get_notices( 'error' )[0]['notice'];
		$this->assertStringNotContainsString( 'field_required', $notice );
		$this->assertNoteContains( $this->reload( $order ), 'field_required' );
		$this->assertSame( 'pending', $this->reload( $order )->get_status() );
	}

	public function test_cancelling_an_unpaid_order_cancels_its_link(): void {
		$order = $this->order();
		$this->gateway()->process_payment( $order->get_id() );
		$link = $this->reload( $order )->get_meta( '_zeny_payment_link_id' );

		$this->reload( $order )->update_status( 'cancelled' );

		$this->assertSame( 'canceled', StubZeny::link( $link )['status'] );
		$this->assertCount( 1, StubZeny::requests( 'POST', '/payment-links/' . $link . '/cancel' ) );
	}

	public function test_cancel_refused_because_the_link_took_a_payment_leaves_a_note(): void {
		$order = $this->order();
		$this->gateway()->process_payment( $order->get_id() );
		$link = $this->reload( $order )->get_meta( '_zeny_payment_link_id' );
		StubZeny::updateLink( $link, array( 'paidCount' => 1 ) );

		$this->reload( $order )->update_status( 'cancelled' );

		$this->assertNoteContains( $this->reload( $order ), 'took a payment' );
	}

	public function test_cancelling_an_order_of_another_gateway_calls_nothing(): void {
		$order = $this->order();
		$order->set_payment_method( 'cod' );
		$order->save();
		$order->update_status( 'cancelled' );
		$this->assertSame( array(), StubZeny::requests() );
	}

	private function adminNotices(): string {
		wp_set_current_user( 1 );
		ob_start();
		AdminNotices::render();
		return (string) ob_get_clean();
	}
}
