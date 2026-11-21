<?php
/**
 * PSR-4 autoloader for the plugin's classes.
 *
 * @package Zeny\WooCommerce
 */

namespace Zeny\WooCommerce;

/**
 * Maps Zeny\WooCommerce\Foo\Bar to src/Foo/Bar.php. The plugin ships without
 * Composer at runtime, so another plugin's vendor folder can never clash with it.
 */
final class Autoloader {

	private const PREFIX = 'Zeny\\WooCommerce\\';

	/**
	 * Registers the autoloader for classes under $base_dir.
	 *
	 * @param string $base_dir Absolute path of the src directory.
	 */
	public static function register( string $base_dir ): void {
		spl_autoload_register(
			static function ( string $class_name ) use ( $base_dir ): void {
				if ( 0 !== strpos( $class_name, self::PREFIX ) ) {
					return;
				}
				$relative = substr( $class_name, strlen( self::PREFIX ) );
				$file     = $base_dir . '/' . str_replace( '\\', '/', $relative ) . '.php';
				if ( is_readable( $file ) ) {
					require_once $file;
				}
			}
		);
	}
}
