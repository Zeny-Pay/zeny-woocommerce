<?php

namespace Zeny\WooCommerce\Tests\Unit\Domain;

use PHPUnit\Framework\TestCase;
use Zeny\WooCommerce\Domain\ApiKey;
use Zeny\WooCommerce\Domain\InvalidCredential;
use Zeny\WooCommerce\Domain\Mode;
use Zeny\WooCommerce\Domain\WebhookSecret;

final class CredentialsTest extends TestCase {

	public function test_test_key_selects_test_mode(): void {
		$key = ApiKey::parse( 'sk_test_abc123' );
		$this->assertSame( Mode::TEST, $key->mode() );
		$this->assertSame( 'sk_test_abc123', $key->value() );
	}

	public function test_live_key_selects_live_mode(): void {
		$this->assertSame( Mode::LIVE, ApiKey::parse( 'sk_live_xyz' )->mode() );
	}

	/**
	 * @dataProvider invalidKeys
	 */
	public function test_rejects_invalid_keys_without_echoing_them( string $raw ): void {
		try {
			ApiKey::parse( $raw );
			$this->fail( 'expected InvalidCredential' );
		} catch ( InvalidCredential $e ) {
			if ( ! in_array( $raw, array( '', 'sk_test_' ), true ) ) {
				$this->assertStringNotContainsString( $raw, $e->getMessage() );
			}
			$this->assertNotSame( '', $e->getMessage() );
		}
	}

	/**
	 * @return array<string, array{string}>
	 */
	public function invalidKeys(): array {
		return array(
			'empty'          => array( '' ),
			'no secret part' => array( 'sk_test_' ),
			'publishable'    => array( 'pk_test_abc' ),
			'unknown mode'   => array( 'sk_prod_abc' ),
			'space inside'   => array( 'sk_test_ab cd' ),
			'newline'        => array( "sk_test_abc\n" ),
			'non ascii'      => array( 'sk_test_ábc' ),
		);
	}

	public function test_key_never_prints_the_secret(): void {
		$key = ApiKey::parse( 'sk_live_supersecret' );
		$this->assertSame( 'sk_live_***', (string) $key );
		$this->assertStringNotContainsString( 'supersecret', print_r( $key, true ) );
		$this->assertStringNotContainsString( 'supersecret', var_export( $key->__debugInfo(), true ) );
	}

	public function test_webhook_secret_accepts_whsec_and_masks_it(): void {
		$secret = WebhookSecret::parse( 'whsec_abc' );
		$this->assertSame( 'whsec_abc', $secret->value() );
		$this->assertSame( 'whsec_***', (string) $secret );
		$this->assertStringNotContainsString( 'abc', print_r( $secret, true ) );
	}

	/**
	 * @dataProvider invalidSecrets
	 */
	public function test_webhook_secret_rejects_other_values( string $raw ): void {
		$this->expectException( InvalidCredential::class );
		WebhookSecret::parse( $raw );
	}

	/**
	 * @return array<string, array{string}>
	 */
	public function invalidSecrets(): array {
		return array(
			'empty'       => array( '' ),
			'prefix only' => array( 'whsec_' ),
			'api key'     => array( 'sk_test_abc' ),
			'space'       => array( 'whsec_a b' ),
		);
	}

	public function test_mode_parses_known_values_only(): void {
		$this->assertSame( Mode::LIVE, Mode::fromString( 'live' ) );
		$this->assertSame( Mode::TEST, Mode::fromString( 'test' ) );
		$this->assertNull( Mode::fromString( 'staging' ) );
	}
}
