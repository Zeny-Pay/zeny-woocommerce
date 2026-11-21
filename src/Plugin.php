<?php
/**
 * Plugin entry point.
 *
 * @package Zeny\WooCommerce
 */

namespace Zeny\WooCommerce;

/**
 * Wires the plugin into WordPress once every plugin has loaded.
 */
final class Plugin {

	/**
	 * Hooks the plugin in, or shows an admin notice when WooCommerce is missing.
	 */
	public static function boot(): void {
		load_plugin_textdomain( 'zeny', false, dirname( plugin_basename( ZENY_WC_FILE ) ) . '/languages' );

		if ( ! class_exists( 'WooCommerce' ) ) {
			add_action( 'admin_notices', array( self::class, 'renderMissingWooCommerceNotice' ) );
			return;
		}
	}

	/**
	 * Tells an administrator that the plugin needs WooCommerce.
	 */
	public static function renderMissingWooCommerceNotice(): void {
		if ( ! current_user_can( 'activate_plugins' ) ) {
			return;
		}
		printf(
			'<div class="notice notice-error"><p>%s</p></div>',
			esc_html__( 'Zeny for WooCommerce needs WooCommerce. Install and activate WooCommerce to take Zeny payments.', 'zeny' )
		);
	}
}
