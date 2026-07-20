<?php
/**
 * Form field definition.
 *
 * @package MailPilot
 */

declare( strict_types=1 );

namespace MailPilot\Forms;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * A single field in a form definition.
 *
 * Field controls (required, placeholder, validation, default, conditional
 * logic) are carried here and consumed by the renderer and submission handler.
 */
final class Field {

	/**
	 * @param string                                                          $key         Unique field key within the form.
	 * @param FieldType                                                        $type        Field type.
	 * @param string                                                          $label       Visible label.
	 * @param bool                                                            $required    Whether the field is required.
	 * @param string                                                          $placeholder Placeholder text.
	 * @param string                                                          $default     Default value.
	 * @param array<int, array{value:string,label:string}>                    $options     Choice options.
	 * @param string                                                          $validation  Validation rule slug (email|url|number|none).
	 * @param array{field:string,operator:string,value:string}|null           $conditional Conditional-logic rule (show when …).
	 * @param string                                                          $name_mode   For `FieldType::Name` only: `single` (one input) or
	 *                                                                                     `split` (separate First name / Last name inputs).
	 *                                                                                     Ignored by every other field type.
	 */
	public function __construct(
		public string $key,
		public FieldType $type,
		public string $label = '',
		public bool $required = false,
		public string $placeholder = '',
		public string $default = '',
		public array $options = [],
		public string $validation = 'none',
		public ?array $conditional = null,
		public string $name_mode = 'single',
	) {}

	/**
	 * Build a field from a stored array.
	 *
	 * @param array<string, mixed> $data Field definition.
	 */
	public static function fromArray( array $data ): self {
		$options = [];
		foreach ( (array) ( $data['options'] ?? [] ) as $opt ) {
			if ( is_array( $opt ) ) {
				$options[] = [
					'value' => (string) ( $opt['value'] ?? '' ),
					'label' => (string) ( $opt['label'] ?? $opt['value'] ?? '' ),
				];
			} else {
				$options[] = [
					'value' => (string) $opt,
					'label' => (string) $opt,
				];
			}
		}

		$conditional = null;
		if ( ! empty( $data['conditional']['field'] ) ) {
			$conditional = [
				'field'    => (string) $data['conditional']['field'],
				'operator' => (string) ( $data['conditional']['operator'] ?? 'is' ),
				'value'    => (string) ( $data['conditional']['value'] ?? '' ),
			];
		}

		return new self(
			key: (string) ( $data['key'] ?? '' ),
			type: FieldType::fromString( $data['type'] ?? null ),
			label: (string) ( $data['label'] ?? '' ),
			required: ! empty( $data['required'] ),
			placeholder: (string) ( $data['placeholder'] ?? '' ),
			default: (string) ( $data['default'] ?? '' ),
			options: $options,
			validation: (string) ( $data['validation'] ?? 'none' ),
			conditional: $conditional,
			name_mode: 'split' === ( $data['name_mode'] ?? 'single' ) ? 'split' : 'single',
		);
	}

	/**
	 * Export to a storable array.
	 *
	 * @return array<string, mixed>
	 */
	public function toArray(): array {
		return [
			'key'         => $this->key,
			'type'        => $this->type->value,
			'label'       => $this->label,
			'required'    => $this->required,
			'placeholder' => $this->placeholder,
			'default'     => $this->default,
			'options'     => $this->options,
			'validation'  => $this->validation,
			'conditional' => $this->conditional,
			'name_mode'   => $this->name_mode,
		];
	}
}
