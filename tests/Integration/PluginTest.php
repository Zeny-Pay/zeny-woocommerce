<?php

namespace Zeny\WooCommerce\Tests\Integration;

use PHPUnit\Framework\TestCase;

final class PluginTest extends TestCase {

	public function test_plugin_is_active_alongside_woocommerce(): void {
		$this->assertTrue( is_plugin_active( 'zeny-woocommerce/zeny-woocommerce.php' ) );
		$this->assertTrue( class_exists( 'WooCommerce' ) );
		$this->assertTrue( class_exists( \Zeny\WooCommerce\Plugin::class, false ) );
	}
}
