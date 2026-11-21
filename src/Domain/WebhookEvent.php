<?php
/**
 * Webhook event envelope.
 *
 * @package Zeny\WooCommerce
 */

namespace Zeny\WooCommerce\Domain;

use InvalidArgumentException;

/**
 * A verified event: its id (the same on every delivery), type, mode, creation
 * time and data.object. Unknown types pass through; callers skip them.
 */
final class WebhookEvent {

	public const PAYMENT_PAID = 'payment.paid';

	/**
	 * Decoded body.
	 *
	 * @var array<mixed>
	 */
	private $payload;

	/**
	 * Use WebhookEvent::fromArray().
	 *
	 * @param array<mixed> $payload Decoded body with id and type checked.
	 */
	private function __construct( array $payload ) {
		$this->payload = $payload;
	}

	/**
	 * Reads an event from its decoded body.
	 *
	 * @param array<mixed> $payload Decoded body.
	 * @throws InvalidArgumentException When id or type is missing.
	 */
	public static function fromArray( array $payload ): self {
		foreach ( array( 'id', 'type' ) as $field ) {
			if ( ! isset( $payload[ $field ] ) || ! is_string( $payload[ $field ] ) || '' === $payload[ $field ] ) {
				throw new InvalidArgumentException( 'An event needs an id and a type.' );
			}
		}
		return new self( $payload );
	}

	/**
	 * The event id (evt_…).
	 */
	public function id(): string {
		return $this->payload['id'];
	}

	/**
	 * The event type, such as payment.paid.
	 */
	public function type(): string {
		return $this->payload['type'];
	}

	/**
	 * "test" or "live" as sent, "" when absent.
	 */
	public function mode(): string {
		return isset( $this->payload['mode'] ) && is_string( $this->payload['mode'] ) ? $this->payload['mode'] : '';
	}

	/**
	 * When the event was written, as Unix time, or null when unreadable.
	 */
	public function createdAt(): ?int {
		if ( ! isset( $this->payload['createdAt'] ) || ! is_string( $this->payload['createdAt'] ) ) {
			return null;
		}
		$time = strtotime( $this->payload['createdAt'] );
		return false === $time ? null : $time;
	}

	/**
	 * The object the event is about, [] when absent.
	 *
	 * @return array<mixed>
	 */
	public function object(): array {
		$data = $this->payload['data'] ?? null;
		return is_array( $data ) && isset( $data['object'] ) && is_array( $data['object'] ) ? $data['object'] : array();
	}
}
