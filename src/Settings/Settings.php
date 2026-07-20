<?php
/**
 * Settings repository and registration.
 *
 * @package MailPilot
 */

declare( strict_types=1 );

namespace MailPilot\Settings;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Reads/writes plugin settings via the options API with sanitisation.
 *
 * All settings live under a single option array so reads are a single,
 * cacheable query.
 */
final class Settings {

	/**
	 * Option name holding the settings array.
	 */
	public const OPTION = 'mailpilot_settings';

	/**
	 * Settings group used by register_setting / settings_fields.
	 */
	public const GROUP = 'mailpilot_settings_group';

	/**
	 * Cached settings for the current request.
	 *
	 * @var array<string, mixed>|null
	 */
	private ?array $cache = null;

	/**
	 * Register the option with WordPress, including the sanitise callback.
	 */
	public function register_hooks(): void {
		add_action( 'admin_init', [ $this, 'register' ] );
	}

	/**
	 * Register the setting and its sanitiser.
	 */
	public function register(): void {
		register_setting(
			self::GROUP,
			self::OPTION,
			[
				'type'              => 'array',
				'sanitize_callback' => [ $this, 'sanitize' ],
				'default'           => $this->defaults(),
			]
		);
	}

	/**
	 * Default settings.
	 *
	 * @return array<string, mixed>
	 */
	public function defaults(): array {
		return [
			'ai_provider'        => 'anthropic',
			'ai_api_key'         => '',
			'double_opt_in'      => false,
			'sync_all_providers' => false,
			'default_country'    => '',
			'delete_data_on_uninstall' => false,
		];
	}

	/**
	 * Retrieve a single setting.
	 *
	 * @param string $key     Setting key.
	 * @param mixed  $default Fallback when unset.
	 * @return mixed
	 */
	public function get( string $key, mixed $default = null ): mixed {
		$all = $this->all();

		return $all[ $key ] ?? $default;
	}

	/**
	 * All settings, merged over defaults.
	 *
	 * @return array<string, mixed>
	 */
	public function all(): array {
		if ( null === $this->cache ) {
			$stored      = get_option( self::OPTION, [] );
			$this->cache = array_merge( $this->defaults(), is_array( $stored ) ? $stored : [] );
		}

		return $this->cache;
	}

	/**
	 * Persist a single setting.
	 *
	 * @param string $key   Setting key.
	 * @param mixed  $value Value to store.
	 */
	public function set( string $key, mixed $value ): void {
		$all         = $this->all();
		$all[ $key ] = $value;
		$this->save( $all );
	}

	/**
	 * Persist the full settings array.
	 *
	 * @param array<string, mixed> $values Settings.
	 */
	public function save( array $values ): void {
		$clean       = $this->sanitize( $values );
		$this->cache = $clean;
		update_option( self::OPTION, $clean );
	}

	/**
	 * Sanitise the settings array.
	 *
	 * Secrets are encrypted here so they are never written in plaintext.
	 *
	 * @param mixed $input Raw input.
	 * @return array<string, mixed>
	 */
	public function sanitize( mixed $input ): array {
		$input  = is_array( $input ) ? $input : [];
		$output = $this->defaults();

		if ( isset( $input['ai_provider'] ) ) {
			$provider               = sanitize_key( (string) $input['ai_provider'] );
			$output['ai_provider']  = in_array( $provider, [ 'anthropic', 'openai' ], true ) ? $provider : 'anthropic';
		}

		if ( isset( $input['ai_api_key'] ) ) {
			$output['ai_api_key'] = $this->store_secret( (string) $input['ai_api_key'] );
		}

		$output['double_opt_in']            = ! empty( $input['double_opt_in'] );
		$output['sync_all_providers']       = ! empty( $input['sync_all_providers'] );
		$output['delete_data_on_uninstall'] = ! empty( $input['delete_data_on_uninstall'] );

		// Default country for internationalising local phone numbers. Stored as
		// an ISO-3166 alpha-2 code, or blank when unset/unrecognised.
		if ( isset( $input['default_country'] ) ) {
			$country = strtoupper( (string) preg_replace( '/[^A-Za-z]/', '', (string) $input['default_country'] ) );
			$country = substr( $country, 0, 2 );
			$output['default_country'] = \MailPilot\Providers\PhoneNumber::is_supported_country( $country ) ? $country : '';
		}

		return $output;
	}

	/**
	 * Encrypt a secret for storage, preserving an already-stored value when the
	 * field is submitted blank (so editing the form does not wipe the key).
	 *
	 * @param string $value Submitted value.
	 */
	private function store_secret( string $value ): string {
		$encryption = mailpilot()->encryption();

		if ( '' === trim( $value ) ) {
			$existing = $this->all()['ai_api_key'] ?? '';

			return is_string( $existing ) ? $existing : '';
		}

		// If the value is already ciphertext (unchanged round-trip), keep it.
		if ( $encryption->is_encrypted( $value ) ) {
			return $value;
		}

		return $encryption->encrypt( $value );
	}

	/**
	 * Retrieve and decrypt a stored secret.
	 *
	 * @param string $key Setting key.
	 */
	public function get_secret( string $key ): string {
		$value = (string) $this->get( $key, '' );

		return mailpilot()->encryption()->decrypt( $value );
	}
}
