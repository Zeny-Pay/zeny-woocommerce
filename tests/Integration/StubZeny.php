<?php

namespace Zeny\WooCommerce\Tests\Integration;

/**
 * Reads and arranges the stub Zeny API's state (see stub-server.php).
 */
final class StubZeny {

	public const BASE_URL = 'http://127.0.0.1:8099';
	private const STATE   = '/tmp/zeny-stub/state.json';

	public static function reset(): void {
		self::write( array() );
	}

	/**
	 * Requests the stub received, oldest first, optionally only one method + path prefix.
	 *
	 * @return list<array{method: string, path: string, query: string, key: ?string, auth: ?string, body: mixed}>
	 */
	public static function requests( ?string $method = null, string $path = '' ): array {
		$all = self::read()['requests'] ?? array();
		return array_values(
			array_filter(
				$all,
				static function ( array $r ) use ( $method, $path ): bool {
					return ( null === $method || $r['method'] === $method ) && 0 === strpos( $r['path'], '/api/merchant/v1' . $path );
				}
			)
		);
	}

	/**
	 * Makes the next $count calls to method + path answer $status. With $commit,
	 * the call takes effect first, so the client cannot know it did.
	 */
	public static function failNext( string $method, string $path, int $status, int $count = 1, bool $commit = false, string $code = 'internal_error' ): void {
		$state               = self::read();
		$state['failures'][] = array(
			'method' => $method,
			'path'   => '/api/merchant/v1' . $path,
			'status' => $status,
			'count'  => $count,
			'commit' => $commit,
			'code'   => $code,
		);
		self::write( $state );
	}

	/**
	 * @param array<string, mixed> $fields Fields to change on a link.
	 */
	public static function updateLink( string $id, array $fields ): void {
		$state                 = self::read();
		$state['links'][ $id ] = array_merge( $state['links'][ $id ], $fields );
		self::write( $state );
	}

	/**
	 * @return array<string, mixed>
	 */
	public static function link( string $id ): array {
		return self::read()['links'][ $id ];
	}

	/**
	 * @param array<string, mixed> $payment Payment object.
	 */
	public static function addPayment( array $payment ): void {
		$state                                = self::read();
		$state['payments'][ $payment['id'] ] = $payment;
		self::write( $state );
	}

	/**
	 * @return array<string, mixed>
	 */
	private static function read(): array {
		$raw = @file_get_contents( self::STATE );
		return is_string( $raw ) ? (array) json_decode( $raw, true ) : array();
	}

	/**
	 * @param array<string, mixed> $state
	 */
	private static function write( array $state ): void {
		file_put_contents( self::STATE, json_encode( $state ), LOCK_EX );
	}
}
