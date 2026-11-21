<?php

namespace Zeny\WooCommerce\Tests\Unit\Domain;

use PHPUnit\Framework\TestCase;
use Zeny\WooCommerce\Domain\WebhookSecret;
use Zeny\WooCommerce\Domain\WebhookVerificationFailed;
use Zeny\WooCommerce\Domain\WebhookVerifier;

final class WebhookVerifierTest extends TestCase {

	// The golden vector every Zeny SDK checks against.
	private const SECRET = 'whsec_test';
	private const TS     = '1792552500';
	private const BODY   = '{"a":1}';
	private const SIG    = 'sha256=f5dd82795177768db12e8bc75c61f78f34b289461ed7966a038c48b9fa972f4e';

	public function test_golden_vector_verifies(): void {
		$this->assertSame( self::SIG, WebhookVerifier::sign( self::SECRET, self::TS, self::BODY ) );
		$payload = $this->verifier()->verifyPayload( self::BODY, self::SIG, self::TS, (int) self::TS );
		$this->assertSame( array( 'a' => 1 ), $payload );
	}

	public function test_uppercase_hex_is_accepted(): void {
		$upper = 'sha256=' . strtoupper( substr( self::SIG, 7 ) );
		$this->assertSame( array( 'a' => 1 ), $this->verifier()->verifyPayload( self::BODY, $upper, self::TS, (int) self::TS ) );
	}

	/**
	 * @dataProvider tolerance
	 */
	public function test_tolerance_edges( int $offset, bool $ok ): void {
		$now = (int) self::TS + $offset;
		if ( ! $ok ) {
			$this->expectFailure( 'timestamp_out_of_tolerance' );
		}
		$this->assertSame( array( 'a' => 1 ), $this->verifier()->verifyPayload( self::BODY, self::SIG, self::TS, $now ) );
	}

	/**
	 * @return array<string, array{int, bool}>
	 */
	public function tolerance(): array {
		return array(
			'300 s after'  => array( 300, true ),
			'301 s after'  => array( 301, false ),
			'300 s before' => array( -300, true ),
			'301 s before' => array( -301, false ),
		);
	}

	/**
	 * @dataProvider badHeaders
	 */
	public function test_bad_headers( ?string $sig, ?string $ts, string $code ): void {
		$this->expectFailure( $code );
		$this->verifier()->verifyPayload( self::BODY, $sig, $ts, (int) self::TS );
	}

	/**
	 * @return array<string, array{?string, ?string, string}>
	 */
	public function badHeaders(): array {
		$hex = substr( self::SIG, 7 );
		return array(
			'missing signature'      => array( null, self::TS, 'signature_missing' ),
			'empty signature'        => array( '', self::TS, 'signature_missing' ),
			'missing timestamp'      => array( self::SIG, null, 'signature_missing' ),
			'non-digit timestamp'    => array( self::SIG, '1792552500.0', 'timestamp_out_of_tolerance' ),
			'negative timestamp'     => array( self::SIG, '-1792552500', 'timestamp_out_of_tolerance' ),
			'no prefix'              => array( $hex, self::TS, 'signature_malformed' ),
			'other algorithm'        => array( 'sha1=' . $hex, self::TS, 'signature_malformed' ),
			'short hex'              => array( 'sha256=' . substr( $hex, 2 ), self::TS, 'signature_malformed' ),
			'not hex'                => array( 'sha256=' . str_repeat( 'g', 64 ), self::TS, 'signature_malformed' ),
			'two signatures'         => array( self::SIG . ',' . self::SIG, self::TS, 'signature_malformed' ),
			'wrong signature'        => array( 'sha256=' . str_repeat( '0', 64 ), self::TS, 'signature_mismatch' ),
		);
	}

	public function test_body_changed_by_one_byte_fails(): void {
		$this->expectFailure( 'signature_mismatch' );
		$this->verifier()->verifyPayload( '{"a":2}', self::SIG, self::TS, (int) self::TS );
	}

	public function test_secret_is_used_whole_including_prefix(): void {
		$this->expectFailure( 'signature_mismatch' );
		$verifier = new WebhookVerifier( WebhookSecret::parse( 'whsec_other' ) );
		$verifier->verifyPayload( self::BODY, self::SIG, self::TS, (int) self::TS );
	}

	/**
	 * @dataProvider badBodies
	 */
	public function test_signed_body_that_is_not_a_json_object_is_invalid( string $body ): void {
		$this->expectFailure( 'invalid_payload' );
		$sig = WebhookVerifier::sign( self::SECRET, self::TS, $body );
		$this->verifier()->verifyPayload( $body, $sig, self::TS, (int) self::TS );
	}

	/**
	 * @return array<string, array{string}>
	 */
	public function badBodies(): array {
		return array(
			'not json' => array( 'nope' ),
			'array'    => array( '[1,2]' ),
			'string'   => array( '"x"' ),
			'empty'    => array( '' ),
		);
	}

	public function test_verify_returns_an_event(): void {
		$body  = '{"id":"evt_1","object":"event","type":"payment.paid","mode":"test","createdAt":"2026-10-20T03:55:00Z","data":{"object":{"id":"pay_1"}}}';
		$event = $this->verifier()->verify( $body, WebhookVerifier::sign( self::SECRET, self::TS, $body ), self::TS, (int) self::TS );
		$this->assertSame( 'evt_1', $event->id() );
		$this->assertSame( 'payment.paid', $event->type() );
	}

	public function test_verify_rejects_an_event_without_id_or_type(): void {
		$this->expectFailure( 'invalid_payload' );
		$this->verifier()->verify( self::BODY, self::SIG, self::TS, (int) self::TS );
	}

	private function verifier(): WebhookVerifier {
		return new WebhookVerifier( WebhookSecret::parse( self::SECRET ) );
	}

	private function expectFailure( string $code ): void {
		$this->expectException( WebhookVerificationFailed::class );
		$this->expectExceptionMessage( $code );
	}
}
