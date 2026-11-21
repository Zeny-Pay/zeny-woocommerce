<?php

namespace Zeny\WooCommerce\Tests\Integration;

use PHPUnit\Framework\TestCase;
use WC_Order;
use WC_Product_Simple;
use Zeny\WooCommerce\WooCommerce\Gateway;

/**
 * Real WordPress + WooCommerce, the stub Zeny API, and helpers to build orders.
 */
abstract class IntegrationTestCase extends TestCase {

	protected const API_KEY = 'sk_test_good';
	protected const SECRET  = 'whsec_test';

	protected function setUp(): void {
		StubZeny::reset();
		wp_set_current_user( 0 );
		wc_clear_notices();
		update_option( 'woocommerce_currency', 'IDR' );
		update_option( 'home', 'http://localhost:8080' );
		update_option( 'siteurl', 'http://localhost:8080' );
		$this->configure( array() );
	}

	/**
	 * @param array<string, string|list<string>> $overrides Gateway settings.
	 */
	protected function configure( array $overrides ): void {
		update_option(
			'woocommerce_zeny_settings',
			array_merge(
				array(
					'enabled'               => 'yes',
					'title'                 => 'Zeny',
					'description'           => 'Pay with QRIS, virtual account or e-wallet.',
					'api_key'               => self::API_KEY,
					'webhook_secret'        => self::SECRET,
					'link_lifetime'         => '60',
					'instruments'           => '',
					'required_payer_fields' => array(),
					'debug'                 => 'no',
				),
				$overrides
			)
		);
	}

	protected function gateway(): Gateway {
		return new Gateway();
	}

	protected function order( string $price = '150000' ): WC_Order {
		$product = new WC_Product_Simple();
		$product->set_name( 'Kopi Susu 1L' );
		$product->set_regular_price( $price );
		$product->save();

		$order = wc_create_order();
		$order->add_product( $product, 1 );
		$order->set_currency( get_woocommerce_currency() );
		$order->set_payment_method( 'zeny' );
		$order->set_billing_email( 'buyer@example.com' );
		$order->calculate_totals( false );
		$order->save();
		return $order;
	}

	protected function reload( WC_Order $order ): WC_Order {
		$fresh = wc_get_order( $order->get_id() );
		$this->assertInstanceOf( WC_Order::class, $fresh );
		return $fresh;
	}

	/**
	 * @return list<string>
	 */
	protected function notes( WC_Order $order ): array {
		return array_map(
			static function ( $note ): string {
				return $note->content;
			},
			wc_get_order_notes( array( 'order_id' => $order->get_id() ) )
		);
	}

	protected function assertNoteContains( WC_Order $order, string $text ): void {
		$this->assertStringContainsString( $text, implode( "\n", $this->notes( $order ) ) );
	}
}
