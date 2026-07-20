<?php
/**
 * Form definition entity.
 *
 * @package MailPilot
 */

declare( strict_types=1 );

namespace MailPilot\Forms;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * A form definition — a row of `wp_mailpilot_forms` with its fields, post-submit
 * actions, and presentation settings.
 */
final class Form {

	/**
	 * @param int|null              $id           Primary key.
	 * @param string                $title        Admin title.
	 * @param string                $status       `draft` | `published`.
	 * @param string                $display_type inline|popup|floating_bar|slide_in|full_screen.
	 * @param array<int, Field>     $fields       Field definitions.
	 * @param array<string, mixed>  $actions      Post-submit actions.
	 * @param array<string, mixed>  $settings     Presentation/behaviour settings.
	 */
	public function __construct(
		public ?int $id = null,
		public string $title = '',
		public string $status = 'draft',
		public string $display_type = 'inline',
		public array $fields = [],
		public array $actions = [],
		public array $settings = [],
	) {}

	/**
	 * Build from a raw DB row.
	 *
	 * @param array<string, mixed>|object $row Row from `wp_mailpilot_forms`.
	 */
	public static function fromRow( array|object $row ): self {
		$row    = (array) $row;
		$fields = json_decode( (string) ( $row['fields'] ?? '[]' ), true );
		$fields = is_array( $fields ) ? array_map( [ Field::class, 'fromArray' ], $fields ) : [];

		return new self(
			id: isset( $row['id'] ) ? (int) $row['id'] : null,
			title: (string) ( $row['title'] ?? '' ),
			status: (string) ( $row['status'] ?? 'draft' ),
			display_type: (string) ( $row['display_type'] ?? 'inline' ),
			fields: $fields,
			actions: self::decode( $row['actions'] ?? '' ),
			settings: self::decode( $row['settings'] ?? '' ),
		);
	}

	/**
	 * A setting value.
	 *
	 * @param string $key     Setting key.
	 * @param mixed  $default Fallback.
	 */
	public function setting( string $key, mixed $default = null ): mixed {
		return $this->settings[ $key ] ?? $default;
	}

	/**
	 * An action value.
	 *
	 * @param string $key     Action key.
	 * @param mixed  $default Fallback.
	 */
	public function action( string $key, mixed $default = null ): mixed {
		return $this->actions[ $key ] ?? $default;
	}

	/**
	 * The email field key, if the form has one.
	 */
	public function email_field_key(): ?string {
		foreach ( $this->fields as $field ) {
			if ( FieldType::Email === $field->type ) {
				return $field->key;
			}
		}

		return null;
	}

	/**
	 * Whether the form includes a GDPR consent field.
	 */
	public function has_gdpr_field(): bool {
		foreach ( $this->fields as $field ) {
			if ( FieldType::Gdpr === $field->type ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Decode a JSON column to an array.
	 *
	 * @param mixed $value Raw value.
	 * @return array<string, mixed>
	 */
	private static function decode( mixed $value ): array {
		$decoded = json_decode( (string) $value, true );

		return is_array( $decoded ) ? $decoded : [];
	}
}
