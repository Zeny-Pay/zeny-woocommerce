<?php

namespace Zeny\WooCommerce\Tests\Integration;

use Zeny\WooCommerce\Domain\WebhookVerifier;

/**
 * The webhook and the order-status check through real HTTP requests to the
 * shop (php -S, see run.sh), so header parsing and the raw body are WordPress's own.
 */
final class HttpTest extends IntegrationTestCase {

	private const SHOP = 'http://127.0.0.1:8080';

	public function test_signed_webhook_over_http_marks_the_order_paid(): void {
		$order = $this->order();
		$this->gateway()->process_payment( $order->get_id() );
		$order = $this->reload( $order );
		$body  = (string) wp_json_encode(
			array(
				'id'        => 'evt_http_1',
				'object'    => 'event',
				'type'      => 'payment.paid',
				'mode'      => 'test',
				'createdAt' => gmdate( 'Y-m-d\TH:i:s\Z' ),
				'data'      => array(
					'object' => array(
						'id'             => 'pay_http_1',
						'status'         => 'paid',
						'paymentLink'    => $order->get_meta( '_zeny_payment_link_id' ),
						'amountReceived' => array( 'value' => '150000.00', 'currency' => 'IDR' ),
					),
				),
			)
		);
		$ts = (string) time();

		$bad = $this->post( $body, 'sha256=' . str_repeat( 'a', 64 ), $ts );
		$this->assertSame( 400, wp_remote_retrieve_response_code( $bad ) );
		wp_cache_flush();
		$this->assertFalse( $this->reload( $order )->is_paid() );

		$ok = $this->post( $body, WebhookVerifier::sign( self::SECRET, $ts, $body ), $ts );
		$this->assertSame( 200, wp_remote_retrieve_response_code( $ok ), wp_remote_retrieve_body( $ok ) );
		// Another process changed the order; drop this process's cached copy.
		wp_cache_flush();
		$this->assertTrue( $this->reload( $order )->is_paid() );
		$this->assertSame( 'pay_http_1', $this->reload( $order )->get_transaction_id() );
	}

	public function test_order_status_check_over_http(): void {
		$order = $this->order();
		$url   = self::SHOP . '/wp-admin/admin-ajax.php?action=zeny_order_status&order=' . $order->get_id();

		$wrong = wp_remote_get( $url . '&key=wrong', array( 'timeout' => 20 ) );
		$this->assertSame( 404, wp_remote_retrieve_response_code( $wrong ) );

		$right = wp_remote_get( $url . '&key=' . rawurlencode( $order->get_order_key() ), array( 'timeout' => 20 ) );
		$this->assertSame( 200, wp_remote_retrieve_response_code( $right ) );
		$this->assertSame(
			array(
				'success' => true,
				'data'    => array( 'paid' => false ),
			),
			json_decode( wp_remote_retrieve_body( $right ), true )
		);
	}

	/**
	 * @return array<mixed>
	 */
	private function post( string $body, string $signature, string $timestamp ): array {
		$response = wp_remote_post(
			self::SHOP . '/?rest_route=/zeny/v1/webhook',
			array(
				'headers' => array(
					'Content-Type'      => 'application/json',
					'Webhook-Signature' => $signature,
					'Webhook-Timestamp' => $timestamp,
					'Webhook-Event'     => 'payment.paid',
				),
				'body'    => $body,
				'timeout' => 20,
			)
		);
		$this->assertIsArray( $response );
		return $response;
	}
}
