<?php
/**
 * Zeny API error.
 *
 * @package Zeny\WooCommerce
 */

namespace Zeny\WooCommerce\Infrastructure;

use RuntimeException;

/**
 * A failed API call: the API's error (type, code, message, request id) or a
 * connection error (type connection_error, code timeout or network_error).
 * Branch on errorCode(); quote requestId() to Zeny support.
 */
final class ZenyApiException extends RuntimeException {

	/**
	 * Error type, such as invalid_request or connection_error.
	 *
	 * @var string
	 */
	private $type;

	/**
	 * Stable error code, such as field_required.
	 *
	 * @var string
	 */
	private $errorCode;

	/**
	 * HTTP status, null when no response arrived.
	 *
	 * @var int|null
	 */
	private $statusCode;

	/**
	 * X-Request-ID of the response.
	 *
	 * @var string|null
	 */
	private $requestId;

	/**
	 * Idempotency-Key the request carried.
	 *
	 * @var string|null
	 */
	private $idempotencyKey;

	/**
	 * Builds the error.
	 *
	 * @param string      $type            Error type.
	 * @param string      $error_code      Error code.
	 * @param string      $message         Human-readable message.
	 * @param int|null    $status_code     HTTP status.
	 * @param string|null $request_id      Request id.
	 * @param string|null $idempotency_key Idempotency-Key sent.
	 */
	public function __construct( string $type, string $error_code, string $message, ?int $status_code, ?string $request_id, ?string $idempotency_key ) {
		parent::__construct( $message );
		$this->type           = $type;
		$this->errorCode      = $error_code;
		$this->statusCode     = $status_code;
		$this->requestId      = $request_id;
		$this->idempotencyKey = $idempotency_key;
	}

	/**
	 * Error type.
	 */
	public function type(): string {
		return $this->type;
	}

	/**
	 * Stable error code.
	 */
	public function errorCode(): string {
		return $this->errorCode;
	}

	/**
	 * HTTP status, null when no response arrived.
	 */
	public function statusCode(): ?int {
		return $this->statusCode;
	}

	/**
	 * X-Request-ID, for Zeny support.
	 */
	public function requestId(): ?string {
		return $this->requestId;
	}

	/**
	 * Idempotency-Key the request carried, to retry it safely.
	 */
	public function idempotencyKey(): ?string {
		return $this->idempotencyKey;
	}

	/**
	 * Whether the request may have been carried out: no answer, a 5xx, or the
	 * first request with this key still running. Retry with the same
	 * idempotency key, or read the object. Any other error did nothing.
	 */
	public function isOutcomeUnknown(): bool {
		return null === $this->statusCode || $this->statusCode >= 500 || 'idempotency_key_in_progress' === $this->errorCode;
	}
}
