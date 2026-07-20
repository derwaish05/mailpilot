<?php
/**
 * Form field type enum.
 *
 * @package MailPilot
 */

declare( strict_types=1 );

namespace MailPilot\Forms;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The field types the Form Builder supports.
 *
 * @see doc/02-requirements.md (Module 2 — Form Builder).
 */
enum FieldType: string {

	case Email    = 'email';
	case Name     = 'name';
	case Phone    = 'phone';
	case Company  = 'company';
	case Website  = 'website';
	case Textarea = 'textarea';
	case Dropdown = 'dropdown';
	case Radio    = 'radio';
	case Checkbox = 'checkbox';
	case Date     = 'date';
	case Number   = 'number';
	case Hidden   = 'hidden';
	case Gdpr     = 'gdpr';

	/**
	 * Resolve from arbitrary input, defaulting to a text-like Name field.
	 *
	 * @param string|null $value Raw type string.
	 */
	public static function fromString( ?string $value ): self {
		return self::tryFrom( (string) $value ) ?? self::Name;
	}

	/**
	 * The HTML input type used when rendering this field, where applicable.
	 */
	public function html_input_type(): string {
		return match ( $this ) {
			self::Email   => 'email',
			self::Phone   => 'tel',
			self::Website => 'url',
			self::Date    => 'date',
			self::Number  => 'number',
			self::Hidden  => 'hidden',
			default       => 'text',
		};
	}

	/**
	 * Whether this field maps to a choice control with options.
	 */
	public function has_options(): bool {
		return in_array( $this, [ self::Dropdown, self::Radio, self::Checkbox ], true );
	}

	/**
	 * The standard subscriber attribute this field maps to, if any.
	 *
	 * Returns null for custom fields (stored in subscriber meta).
	 */
	public function subscriber_field(): ?string {
		return match ( $this ) {
			self::Email   => 'email',
			self::Phone   => 'phone',
			self::Company => 'company',
			default       => null,
		};
	}
}
