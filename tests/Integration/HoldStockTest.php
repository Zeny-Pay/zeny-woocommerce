<?php

namespace Zeny\WooCommerce\Tests\Integration;

use Automattic\WooCommerce\Utilities\OrderUtil;
use WC_Order;

/**
 * WooCommerce cancels unpaid orders after the hold-stock time. A Zeny order
 * whose link is still open is kept until a later pass after the link expired.
 */
final class HoldStockTest extends IntegrationTestCase {

	protected function setUp(): void {
		parent::setUp();
		update_option( 'woocommerce_manage_stock', 'yes' );
		update_option( 'woocommerce_hold_stock_minutes', '1' );
	}

	public function test_order_with_an_open_link_survives_the_unpaid_order_pass(): void {
		$order = $this->staleOrderWithLink();

		wc_cancel_unpaid_orders();

		$this->assertSame( 'pending', $this->reload( $order )->get_status() );
	}

	public function test_order_is_cancelled_on_a_pass_after_its_link_expired(): void {
		$order = $this->staleOrderWithLink();
		$order->update_meta_data( '_zeny_link_expires_at', (string) ( time() - 1 ) );
		$order->save();
		$this->makeStale( $order );

		wc_cancel_unpaid_orders();

		$this->assertSame( 'cancelled', $this->reload( $order )->get_status() );
	}

	/**
	 * @dataProvider decisions
	 */
	public function test_filter_decisions( bool $cancel, string $method, ?int $expires_in, bool $expected ): void {
		$order = $this->order();
		$order->set_payment_method( $method );
		if ( null !== $expires_in ) {
			$order->update_meta_data( '_zeny_payment_link_id', 'pl_x' );
			$order->update_meta_data( '_zeny_mode', 'test' );
			$order->update_meta_data( '_zeny_link_expires_at', (string) ( time() + $expires_in ) );
		}
		$order->save();

		$this->assertSame( $expected, apply_filters( 'woocommerce_cancel_unpaid_order', $cancel, $order ) );
	}

	/**
	 * @return array<string, array{bool, string, ?int, bool}>
	 */
	public function decisions(): array {
		return array(
			'open link'            => array( true, 'zeny', 600, false ),
			'expired link'         => array( true, 'zeny', -1, true ),
			'no link'              => array( true, 'zeny', null, true ),
			'other gateway'        => array( true, 'cod', 600, true ),
			'woo already said no'  => array( false, 'zeny', -1, false ),
		);
	}

	public function test_order_whose_link_is_of_the_other_environment_is_not_kept(): void {
		$order = $this->staleOrderWithLink();
		$this->configure( array( 'api_key' => 'sk_live_good' ) );

		$this->assertTrue( apply_filters( 'woocommerce_cancel_unpaid_order', true, $this->reload( $order ) ) );
	}

	public function test_order_is_not_kept_without_a_usable_key(): void {
		$order = $this->staleOrderWithLink();
		$this->configure( array( 'api_key' => '' ) );

		$this->assertTrue( apply_filters( 'woocommerce_cancel_unpaid_order', true, $this->reload( $order ) ) );
	}

	private function staleOrderWithLink(): WC_Order {
		$order = $this->order();
		$order->set_created_via( 'checkout' );
		$order->save();
		$this->gateway()->process_payment( $order->get_id() );
		$order = $this->reload( $order );
		$this->assertGreaterThan( time(), (int) $order->get_meta( '_zeny_link_expires_at' ) );
		$this->makeStale( $order );
		return $order;
	}

	// The unpaid-order query reads the last-modified time, which every save resets.
	private function makeStale( WC_Order $order ): void {
		global $wpdb;
		$past = gmdate( 'Y-m-d H:i:s', time() - 2 * HOUR_IN_SECONDS );
		if ( OrderUtil::custom_orders_table_usage_is_enabled() ) {
			$wpdb->update( $wpdb->prefix . 'wc_orders', array( 'date_updated_gmt' => $past ), array( 'id' => $order->get_id() ) );
		} else {
			$wpdb->update(
				$wpdb->posts,
				array(
					'post_modified'     => $past,
					'post_modified_gmt' => $past,
				),
				array( 'ID' => $order->get_id() )
			);
		}
		wp_cache_flush();
	}
}
