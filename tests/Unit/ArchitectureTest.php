<?php

namespace Zeny\WooCommerce\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Keeps src/Domain free of WordPress and WooCommerce, so its rules run and are
 * tested without either.
 */
final class ArchitectureTest extends TestCase {

	private const FORBIDDEN = array( 'WP_', 'wc_', 'WC_', 'get_option', 'wp_' );

	public function test_domain_references_no_wordpress_or_woocommerce_symbol(): void {
		$iterator = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( dirname( __DIR__, 2 ) . '/src/Domain' ) );
		foreach ( $iterator as $file ) {
			if ( 'php' !== $file->getExtension() ) {
				continue;
			}
			$found = self::forbiddenSymbolsIn( (string) file_get_contents( $file->getPathname() ) );
			$this->assertSame( array(), $found, $file->getFilename() . ' references WordPress or WooCommerce' );
		}
		$this->addToAssertionCount( 1 );
	}

	public function test_the_scan_flags_each_forbidden_symbol(): void {
		$this->assertSame(
			array( 'WP_', 'wc_', 'WC_', 'get_option', 'wp_' ),
			self::forbiddenSymbolsIn( 'new WP_Error(); wc_get_order(); new WC_Order(); get_option(); wp_remote_get();' )
		);
		$this->assertSame( array(), self::forbiddenSymbolsIn( '$key = "wc-" . $hash;' ) );
	}

	/**
	 * @return list<string>
	 */
	private static function forbiddenSymbolsIn( string $code ): array {
		return array_values(
			array_filter(
				self::FORBIDDEN,
				static function ( string $symbol ) use ( $code ): bool {
					return false !== strpos( $code, $symbol );
				}
			)
		);
	}
}
