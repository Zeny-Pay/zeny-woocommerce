<?php

namespace Zeny\WooCommerce\Tests\Unit\Infrastructure;

use Zeny\WooCommerce\Infrastructure\Transport;
use Zeny\WooCommerce\Infrastructure\TransportFailed;
use Zeny\WooCommerce\Infrastructure\TransportResponse;

/**
 * Replays queued responses (or failures) and records every request.
 */
final class FakeTransport implements Transport {

	/** @var list<TransportResponse|TransportFailed> */
	private $queue = array();

	/** @var list<array{method: string, url: string, headers: array<string, string>, body: ?string, timeout: int}> */
	public $requests = array();

	/**
	 * Called on every send, to let a test move its clock.
	 *
	 * @var callable|null
	 */
	public $onSend;

	/**
	 * @param array<string, string> $headers
	 * @param array<mixed>|string   $body
	 */
	public function respond( int $status, $body, array $headers = array() ): self {
		$this->queue[] = new TransportResponse( $status, $headers, is_string( $body ) ? $body : (string) json_encode( $body ) );
		return $this;
	}

	public function fail( string $code ): self {
		$this->queue[] = new TransportFailed( $code );
		return $this;
	}

	public function send( string $method, string $url, array $headers, ?string $body, int $timeout_sec ): TransportResponse {
		$this->requests[] = array(
			'method'  => $method,
			'url'     => $url,
			'headers' => $headers,
			'body'    => $body,
			'timeout' => $timeout_sec,
		);
		if ( null !== $this->onSend ) {
			( $this->onSend )();
		}
		$next = array_shift( $this->queue );
		if ( null === $next ) {
			throw new \LogicException( 'FakeTransport: no response queued' );
		}
		if ( $next instanceof TransportFailed ) {
			throw $next;
		}
		return $next;
	}
}
