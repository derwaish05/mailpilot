<?php
/**
 * Configured provider connection.
 *
 * @package MailPilot
 */

declare( strict_types=1 );

namespace MailPilot\Providers;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * A configured connection to a provider — a row of `wp_mailpilot_provider_connections`
 * with decrypted credentials and the list/group/tag/field mapping.
 */
final class ProviderConnection {

	/**
	 * @param int|null              $id          Primary key.
	 * @param string                $provider    Provider slug (e.g. `mailchimp`).
	 * @param string                $label       Admin label.
	 * @param string                $status      `active` | `inactive`.
	 * @param array<string, mixed>  $credentials Decrypted credentials (e.g. api_key).
	 * @param array<string, mixed>  $settings    Provider settings (lists, groups, double opt-in).
	 * @param array<string, string> $field_map   Local field key => provider field key.
	 */
	public function __construct(
		public ?int $id,
		public string $provider,
		public string $label = '',
		public string $status = 'active',
		public array $credentials = [],
		public array $settings = [],
		public array $field_map = [],
	) {}

	/**
	 * A single credential value.
	 *
	 * @param string $key     Credential key.
	 * @param string $default Fallback.
	 */
	public function credential( string $key, string $default = '' ): string {
		return isset( $this->credentials[ $key ] ) ? (string) $this->credentials[ $key ] : $default;
	}

	/**
	 * A single setting value.
	 *
	 * @param string $key     Setting key.
	 * @param mixed  $default Fallback.
	 */
	public function setting( string $key, mixed $default = null ): mixed {
		return $this->settings[ $key ] ?? $default;
	}

	/**
	 * Whether the connection is active.
	 */
	public function is_active(): bool {
		return 'active' === $this->status;
	}

	/**
	 * Whether double opt-in is enabled for this connection.
	 */
	public function double_opt_in(): bool {
		return (bool) $this->setting( 'double_opt_in', false );
	}

	/**
	 * Configured list ids for this connection.
	 *
	 * @return array<int, string>
	 */
	public function lists(): array {
		$lists = $this->setting( 'lists', [] );

		return is_array( $lists ) ? array_map( 'strval', $lists ) : [];
	}
}
