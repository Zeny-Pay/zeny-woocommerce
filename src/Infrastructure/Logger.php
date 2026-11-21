<?php
/**
 * Plugin logger.
 *
 * @package Zeny\WooCommerce
 */

namespace Zeny\WooCommerce\Infrastructure;

/**
 * Writes to the WooCommerce log under the source "zeny". Errors are always
 * written; debug lines only when debug logging is on. Callers pass codes and
 * ids only, never a key, secret or request body.
 */
final class Logger {

	/**
	 * Whether debug lines are written.
	 *
	 * @var bool
	 */
	private $debug;

	/**
	 * Builds the logger.
	 *
	 * @param bool $debug Write debug lines.
	 */
	public function __construct( bool $debug ) {
		$this->debug = $debug;
	}

	/**
	 * Logs a debug line when debug logging is on.
	 *
	 * @param string               $message Message.
	 * @param array<string, mixed> $context Ids and codes.
	 */
	public function debug( string $message, array $context = array() ): void {
		if ( $this->debug ) {
			$this->write( 'debug', $message, $context );
		}
	}

	/**
	 * Logs an error.
	 *
	 * @param string               $message Message.
	 * @param array<string, mixed> $context Ids and codes.
	 */
	public function error( string $message, array $context = array() ): void {
		$this->write( 'error', $message, $context );
	}

	/**
	 * Writes one line.
	 *
	 * @param string               $level   Log level.
	 * @param string               $message Message.
	 * @param array<string, mixed> $context Ids and codes.
	 */
	private function write( string $level, string $message, array $context ): void {
		if ( ! function_exists( 'wc_get_logger' ) ) {
			return;
		}
		$line = array() === $context ? $message : $message . ' ' . wp_json_encode( $context );
		wc_get_logger()->log( $level, $line, array( 'source' => 'zeny' ) );
	}
}
