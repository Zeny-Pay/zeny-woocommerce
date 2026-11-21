<?php

namespace Zeny\WooCommerce\Tests\Integration;

use PHPUnit\Framework\TestCase;

final class PluginTest extends TestCase {

	public function test_plugin_is_active_alongside_woocommerce(): void {
		$this->assertTrue( is_plugin_active( 'zeny-woocommerce/zeny-woocommerce.php' ) );
		$this->assertTrue( class_exists( 'WooCommerce' ) );
		$this->assertTrue( class_exists( \Zeny\WooCommerce\WooCommerce\Plugin::class, false ) );
	}

	public function test_suite_runs_on_the_order_store_it_names(): void {
		$hpos = \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled();
		$this->assertSame( 'hpos' === getenv( 'ORDER_STORE' ), $hpos );
	}

	public function test_webhook_route_is_registered(): void {
		$this->assertArrayHasKey( '/zeny/v1/webhook', rest_get_server()->get_routes() );
	}

	public function test_indonesian_translation_loads(): void {
		$indonesian = static function (): string {
			return 'id_ID';
		};
		// Since WordPress 6.7 the file loads just in time for determine_locale(), and 6.5+ keeps translations per locale.
		$controller = \WP_Translation_Controller::get_instance();
		$previous   = $controller->get_locale();
		add_filter( 'determine_locale', $indonesian );
		unload_textdomain( 'zeny' );
		$controller->set_locale( 'id_ID' );
		try {
			$this->assertTrue( load_plugin_textdomain( 'zeny', false, 'zeny-woocommerce/languages' ) );
			$this->assertSame( 'Produksi', __( 'Production', 'zeny' ) );
			$this->assertSame( 'Uji koneksi', __( 'Test connection', 'zeny' ) );
		} finally {
			remove_filter( 'determine_locale', $indonesian );
			$controller->set_locale( $previous );
			unload_textdomain( 'zeny' );
		}
	}
}
