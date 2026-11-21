<?php
/**
 * Webhook signature verification.
 *
 * @package Zeny\WooCommerce
 */

namespace Zeny\WooCommerce\Domain;

use InvalidArgumentException;

/**
 * Checks a Zeny webhook: Webhook-Signature is "sha256=" and the hex
 * HMAC-SHA256 of "{Webhook-Timestamp}.{raw body}", keyed with the whole
 * whsec_ secret, and the timestamp is within the tolerance of now. Verifying
 * does not stop a replay inside the window, so callers store each event id once.
 */
final class WebhookVerifier {

	private const PREFIX = 'sha256=';

	/**
	 * Endpoint secret.
	 *
	 * @var WebhookSecret
	 */
	private $secret;

	/**
	 * Largest allowed gap between now and the timestamp, either way, in seconds.
	 *
	 * @var int
	 */
	private $toleranceSec;

	/**
	 * Builds a verifier.
	 *
	 * @param WebhookSecret $secret        Endpoint secret.
	 * @param int           $tolerance_sec Allowed clock gap in seconds.
	 */
	public function __construct( WebhookSecret $secret, int $tolerance_sec = 300 ) {
		$this->secret       = $secret;
		$this->toleranceSec = $tolerance_sec;
	}

	/**
	 * The Webhook-Signature value for a body, as Zeny computes it.
	 *
	 * @param string $secret    Whole whsec_ secret.
	 * @param string $timestamp Webhook-Timestamp value.
	 * @param string $raw_body  Raw request body.
	 */
	public static function sign( string $secret, string $timestamp, string $raw_body ): string {
		return self::PREFIX . hash_hmac( 'sha256', $timestamp . '.' . $raw_body, $secret );
	}

	/**
	 * Verifies the request and returns its event.
	 *
	 * @param string      $raw_body  Raw request body, byte for byte.
	 * @param string|null $signature Webhook-Signature header.
	 * @param string|null $timestamp Webhook-Timestamp header.
	 * @param int         $now       Current Unix time.
	 * @throws WebhookVerificationFailed When any check fails.
	 */
	public function verify( string $raw_body, ?string $signature, ?string $timestamp, int $now ): WebhookEvent {
		$payload = $this->verifyPayload( $raw_body, $signature, $timestamp, $now );
		try {
			return WebhookEvent::fromArray( $payload );
		} catch ( InvalidArgumentException $e ) {
			throw new WebhookVerificationFailed( 'invalid_payload' );
		}
	}

	/**
	 * Verifies the request and returns its decoded JSON object.
	 *
	 * @param string      $raw_body  Raw request body, byte for byte.
	 * @param string|null $signature Webhook-Signature header.
	 * @param string|null $timestamp Webhook-Timestamp header.
	 * @param int         $now       Current Unix time.
	 * @return array<mixed>
	 * @throws WebhookVerificationFailed When any check fails.
	 */
	public function verifyPayload( string $raw_body, ?string $signature, ?string $timestamp, int $now ): array {
		if ( null === $timestamp || '' === $timestamp || null === $signature || '' === $signature ) {
			throw new WebhookVerificationFailed( 'signature_missing' );
		}
		if ( 1 !== preg_match( '/^\d{1,12}$/D', $timestamp ) || abs( $now - (int) $timestamp ) > $this->toleranceSec ) {
			throw new WebhookVerificationFailed( 'timestamp_out_of_tolerance' );
		}
		$hex = 0 === strpos( $signature, self::PREFIX ) ? strtolower( substr( $signature, strlen( self::PREFIX ) ) ) : '';
		if ( 1 !== preg_match( '/^[0-9a-f]{64}$/D', $hex ) ) {
			throw new WebhookVerificationFailed( 'signature_malformed' );
		}
		if ( ! hash_equals( self::sign( $this->secret->value(), $timestamp, $raw_body ), self::PREFIX . $hex ) ) {
			throw new WebhookVerificationFailed( 'signature_mismatch' );
		}
		$payload = json_decode( $raw_body, true );
		if ( ! is_array( $payload ) || ( array() !== $payload && array_keys( $payload ) === range( 0, count( $payload ) - 1 ) ) ) {
			throw new WebhookVerificationFailed( 'invalid_payload' );
		}
		return $payload;
	}
}
