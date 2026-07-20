<?php
/**
 * Normalised result of a provider sync action.
 *
 * @package MailPilot
 */

declare( strict_types=1 );

namespace MailPilot\Providers;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * A uniform success/failure result so the engine never depends on a
 * provider's native response shape. Distinguishes *retryable* transient
 * failures (network, 429, 5xx) from permanent ones (validation, auth).
 */
final class SyncResult {

	/**
	 * @param bool                 $success   Whether the action succeeded.
	 * @param string               $message   Human-readable message.
	 * @param int|null             $code      Provider/HTTP status code.
	 * @param bool                 $retryable Whether a retry might succeed.
	 * @param array<string, mixed> $data      Raw/normalised response data.
	 */
	private function __construct(
		public bool $success,
		public string $message = '',
		public ?int $code = null,
		public bool $retryable = false,
		public array $data = [],
	) {}

	/**
	 * A successful result.
	 *
	 * @param string               $message Message.
	 * @param array<string, mixed> $data    Response data.
	 */
	public static function success( string $message = '', array $data = [] ): self {
		return new self( true, $message, 200, false, $data );
	}

	/**
	 * A permanent failure (do not retry).
	 *
	 * @param string   $message Message.
	 * @param int|null $code    Status code.
	 */
	public static function failure( string $message, ?int $code = null ): self {
		return new self( false, $message, $code, false );
	}

	/**
	 * A transient failure (safe to retry).
	 *
	 * @param string   $message Message.
	 * @param int|null $code    Status code.
	 */
	public static function transient( string $message, ?int $code = null ): self {
		return new self( false, $message, $code, true );
	}
}
