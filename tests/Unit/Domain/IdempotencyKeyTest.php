<?php

namespace Zeny\WooCommerce\Tests\Unit\Domain;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Zeny\WooCommerce\Domain\IdempotencyKey;

final class IdempotencyKeyTest extends TestCase {

	public function test_order_attempt_key_is_deterministic(): void {
		$key = IdempotencyKey::forOrderAttempt( 'abcdef012345', 42, 1 );
		$this->assertSame( 'wc-abcdef012345-42-1', $key->value() );
		$this->assertSame( $key->value(), IdempotencyKey::forOrderAttempt( 'abcdef012345', 42, 1 )->value() );
		$this->assertSame( 'wc-abcdef012345-42-2', IdempotencyKey::forOrderAttempt( 'abcdef012345', 42, 2 )->value() );
	}

	/**
	 * @dataProvider invalidAttempts
	 */
	public function test_rejects_bad_order_attempt_parts( string $site, int $order, int $attempt ): void {
		$this->expectException( InvalidArgumentException::class );
		IdempotencyKey::forOrderAttempt( $site, $order, $attempt );
	}

	/**
	 * @return array<string, array{string, int, int}>
	 */
	public function invalidAttempts(): array {
		return array(
			'empty site'   => array( '', 1, 1 ),
			'site not hex' => array( 'zzz', 1, 1 ),
			'order zero'   => array( 'abc', 0, 1 ),
			'attempt zero' => array( 'abc', 1, 0 ),
		);
	}

	public function test_parse_accepts_printable_ascii_up_to_255(): void {
		$this->assertSame( str_repeat( 'a', 255 ), IdempotencyKey::parse( str_repeat( 'a', 255 ) )->value() );
		$this->assertSame( 'a b~', IdempotencyKey::parse( 'a b~' )->value() );
	}

	/**
	 * @dataProvider invalidKeys
	 */
	public function test_parse_rejects( string $raw ): void {
		$this->expectException( InvalidArgumentException::class );
		IdempotencyKey::parse( $raw );
	}

	/**
	 * @return array<string, array{string}>
	 */
	public function invalidKeys(): array {
		return array(
			'empty'     => array( '' ),
			'too long'  => array( str_repeat( 'a', 256 ) ),
			'control'   => array( "a\tb" ),
			'non ascii' => array( 'kéy' ),
		);
	}
}
