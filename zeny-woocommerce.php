<?php
/**
 * Plugin Name:          Zeny for WooCommerce
 * Plugin URI:           https://usezeny.com
 * Description:          Take payments through Zeny payment links. Shoppers choose QRIS, virtual account or e-wallet on the Zeny pay page.
 * Version:              1.0.0
 * Requires at least:    6.3
 * Requires PHP:         7.4
 * Requires Plugins:     woocommerce
 * Author:               Zeny
 * Author URI:           https://usezeny.com
 * Text Domain:          zeny
 * Domain Path:          /languages
 * WC requires at least: 8.0
 * WC tested up to:      11.3
 * License:              Proprietary
 *
 * @package Zeny\WooCommerce
 */

defined( 'ABSPATH' ) || exit;

define( 'ZENY_WC_VERSION', '1.0.0' );
define( 'ZENY_WC_FILE', __FILE__ );

require_once __DIR__ . '/src/Autoloader.php';
Zeny\WooCommerce\Autoloader::register( __DIR__ . '/src' );

// Declared before WooCommerce initialises, as its FeaturesUtil requires.
add_action(
	'before_woocommerce_init',
	static function () {
		if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', __FILE__, true );
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'cart_checkout_blocks', __FILE__, true );
		}
	}
);

add_action( 'plugins_loaded', array( Zeny\WooCommerce\WooCommerce\Plugin::class, 'boot' ) );
