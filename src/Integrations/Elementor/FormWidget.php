<?php
/**
 * Elementor MailPilot form widget.
 *
 * @package MailPilot
 */

declare( strict_types=1 );

namespace MailPilot\Integrations\Elementor;

use Elementor\Controls_Manager;
use Elementor\Group_Control_Border;
use Elementor\Group_Control_Typography;
use Elementor\Repeater;
use Elementor\Widget_Base;
use MailPilot\Forms\Field;
use MailPilot\Forms\FieldType;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Drag-and-drop widget that renders a MailPilot form — bound to an existing
 * `wp_mailpilot_forms` form or built inline — with content and style controls and a
 * live editor preview. Submissions flow through the normal engine pipeline and
 * are attributed to Elementor for analytics (Task 4.6).
 */
final class FormWidget extends Widget_Base {

	public function get_name(): string {
		return 'mailpilot_form';
	}

	public function get_title(): string {
		return __( 'MailPilot Form', 'brainstudioz-mailpilot' );
	}

	public function get_icon(): string {
		return 'eicon-email-field';
	}

	/**
	 * @return array<int, string>
	 */
	public function get_categories(): array {
		return [ ElementorModule::CATEGORY ];
	}

	/**
	 * @return array<int, string>
	 */
	public function get_keywords(): array {
		return [ 'mailpilot', 'form', 'newsletter', 'subscribe', 'email' ];
	}

	/**
	 * Register content + style controls.
	 */
	protected function register_controls(): void {
		$this->register_content_controls();
		$this->register_style_controls();
	}

	/**
	 * Content controls: form selection, inline fields, button, redirect, actions.
	 */
	private function register_content_controls(): void {
		$this->start_controls_section(
			'section_content',
			[ 'label' => __( 'Form', 'brainstudioz-mailpilot' ) ]
		);

		$this->add_control(
			'form_source',
			[
				'label'   => __( 'Source', 'brainstudioz-mailpilot' ),
				'type'    => Controls_Manager::SELECT,
				'default' => 'existing',
				'options' => [
					'existing' => __( 'Existing form', 'brainstudioz-mailpilot' ),
					'inline'   => __( 'Build inline', 'brainstudioz-mailpilot' ),
				],
			]
		);

		$this->add_control(
			'form_id',
			[
				'label'     => __( 'Choose form', 'brainstudioz-mailpilot' ),
				'type'      => Controls_Manager::SELECT,
				'options'   => $this->form_options(),
				'default'   => '',
				'condition' => [ 'form_source' => 'existing' ],
			]
		);

		$this->add_control(
			'form_title',
			[
				'label'       => __( 'Form Title', 'brainstudioz-mailpilot' ),
				'type'        => Controls_Manager::TEXT,
				'placeholder' => __( 'e.g. Newsletter Signup', 'brainstudioz-mailpilot' ),
				'description' => __( 'Admin-facing name for the form this widget creates — shown in the MailPilot Forms list and reports. Leave blank to auto-name it after this widget.', 'brainstudioz-mailpilot' ),
				'condition'   => [ 'form_source' => 'inline' ],
			]
		);

		// Inline field builder.
		$repeater = new Repeater();
		$repeater->add_control(
			'field_type',
			[
				'label'   => __( 'Type', 'brainstudioz-mailpilot' ),
				'type'    => Controls_Manager::SELECT,
				'default' => 'email',
				'options' => $this->field_type_options(),
			]
		);
		$repeater->add_control(
			'field_label',
			[
				'label'   => __( 'Label', 'brainstudioz-mailpilot' ),
				'type'    => Controls_Manager::TEXT,
				'default' => __( 'Email', 'brainstudioz-mailpilot' ),
			]
		);
		$repeater->add_control(
			'field_placeholder',
			[
				'label' => __( 'Placeholder', 'brainstudioz-mailpilot' ),
				'type'  => Controls_Manager::TEXT,
			]
		);
		$repeater->add_control(
			'field_required',
			[
				'label'        => __( 'Required', 'brainstudioz-mailpilot' ),
				'type'         => Controls_Manager::SWITCHER,
				'return_value' => 'yes',
			]
		);

		$this->add_control(
			'inline_fields',
			[
				'label'       => __( 'Fields', 'brainstudioz-mailpilot' ),
				'type'        => Controls_Manager::REPEATER,
				'fields'      => $repeater->get_controls(),
				'default'     => [
					[ 'field_type' => 'email', 'field_label' => __( 'Email', 'brainstudioz-mailpilot' ), 'field_required' => 'yes' ],
				],
				'title_field' => '{{{ field_label }}}',
				'condition'   => [ 'form_source' => 'inline' ],
			]
		);

		$this->add_control(
			'button_text',
			[
				'label'   => __( 'Button text', 'brainstudioz-mailpilot' ),
				'type'    => Controls_Manager::TEXT,
				'default' => __( 'Subscribe', 'brainstudioz-mailpilot' ),
			]
		);

		$this->add_control(
			'redirect',
			[
				'label'       => __( 'Redirect URL', 'brainstudioz-mailpilot' ),
				'type'        => Controls_Manager::URL,
				'placeholder' => 'https://',
			]
		);

		$this->add_control(
			'apply_tags',
			[
				'label'       => __( 'Apply tags (comma-separated)', 'brainstudioz-mailpilot' ),
				'type'        => Controls_Manager::TEXT,
				'description' => __( 'Used for inline forms.', 'brainstudioz-mailpilot' ),
				'condition'   => [ 'form_source' => 'inline' ],
			]
		);

		$this->end_controls_section();
	}

	/**
	 * Style controls: inputs and button typography/colors/spacing/borders.
	 */
	private function register_style_controls(): void {
		// Inputs.
		$this->start_controls_section(
			'section_style_inputs',
			[
				'label' => __( 'Inputs', 'brainstudioz-mailpilot' ),
				'tab'   => Controls_Manager::TAB_STYLE,
			]
		);

		$this->add_group_control(
			Group_Control_Typography::get_type(),
			[
				'name'     => 'input_typography',
				'selector' => '{{WRAPPER}} .mailpilot-form input, {{WRAPPER}} .mailpilot-form select, {{WRAPPER}} .mailpilot-form textarea',
			]
		);

		$this->add_control(
			'input_text_color',
			[
				'label'     => __( 'Text color', 'brainstudioz-mailpilot' ),
				'type'      => Controls_Manager::COLOR,
				'selectors' => [ '{{WRAPPER}} .mailpilot-form input, {{WRAPPER}} .mailpilot-form textarea' => 'color: {{VALUE}};' ],
			]
		);

		$this->add_control(
			'input_bg_color',
			[
				'label'     => __( 'Background', 'brainstudioz-mailpilot' ),
				'type'      => Controls_Manager::COLOR,
				'selectors' => [ '{{WRAPPER}} .mailpilot-form input, {{WRAPPER}} .mailpilot-form textarea' => 'background-color: {{VALUE}};' ],
			]
		);

		$this->add_group_control(
			Group_Control_Border::get_type(),
			[
				'name'     => 'input_border',
				'selector' => '{{WRAPPER}} .mailpilot-form input, {{WRAPPER}} .mailpilot-form textarea',
			]
		);

		$this->add_control(
			'field_spacing',
			[
				'label'      => __( 'Field spacing', 'brainstudioz-mailpilot' ),
				'type'       => Controls_Manager::SLIDER,
				'size_units' => [ 'px' ],
				'selectors'  => [ '{{WRAPPER}} .mailpilot-field' => 'margin-bottom: {{SIZE}}{{UNIT}};' ],
			]
		);

		$this->end_controls_section();

		// Button.
		$this->start_controls_section(
			'section_style_button',
			[
				'label' => __( 'Button', 'brainstudioz-mailpilot' ),
				'tab'   => Controls_Manager::TAB_STYLE,
			]
		);

		$this->add_group_control(
			Group_Control_Typography::get_type(),
			[
				'name'     => 'button_typography',
				'selector' => '{{WRAPPER}} .mailpilot-submit button',
			]
		);

		$this->add_control(
			'button_color',
			[
				'label'     => __( 'Text color', 'brainstudioz-mailpilot' ),
				'type'      => Controls_Manager::COLOR,
				'selectors' => [ '{{WRAPPER}} .mailpilot-submit button' => 'color: {{VALUE}};' ],
			]
		);

		$this->add_control(
			'button_bg',
			[
				'label'     => __( 'Background', 'brainstudioz-mailpilot' ),
				'type'      => Controls_Manager::COLOR,
				'selectors' => [ '{{WRAPPER}} .mailpilot-submit button' => 'background-color: {{VALUE}};' ],
			]
		);

		$this->add_group_control(
			Group_Control_Border::get_type(),
			[
				'name'     => 'button_border',
				'selector' => '{{WRAPPER}} .mailpilot-submit button',
			]
		);

		$this->end_controls_section();
	}

	/**
	 * Render the widget on the frontend and in the editor preview.
	 */
	protected function render(): void {
		$settings = $this->get_settings_for_display();
		$is_edit  = $this->is_edit_mode();

		echo $this->build_markup( $settings, ! $is_edit ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- renderer escapes its output.
	}

	/**
	 * Build the form markup from widget settings.
	 *
	 * @param array<string, mixed> $settings   Resolved settings.
	 * @param bool                 $count_view Whether to count an analytics view.
	 */
	private function build_markup( array $settings, bool $count_view ): string {
		$module = mailpilot()->forms();

		if ( 'inline' === ( $settings['form_source'] ?? 'existing' ) ) {
			$fields = $this->inline_fields( $settings );

			if ( ! $fields ) {
				return $this->editor_hint( __( 'Add at least one field.', 'brainstudioz-mailpilot' ) );
			}

			// The element id is stable across edits/saves (unlike a hash of
			// the current, possibly mid-edit, settings) so FormsModule can
			// upsert this widget's one backing form row instead of creating
			// a new one on every live-preview render.
			$element_id = $this->get_id();
			$form_title = trim( (string) ( $settings['form_title'] ?? '' ) );

			return $module->render_inline(
				$fields,
				[
					'title'        => '' !== $form_title ? $form_title : ( 'Elementor: ' . $element_id ),
					'elementor_id' => $element_id,
					'settings'     => [ 'button_text' => (string) ( $settings['button_text'] ?? 'Subscribe' ) ],
					'actions'      => [
						'apply_tags' => array_filter( array_map( 'trim', explode( ',', (string) ( $settings['apply_tags'] ?? '' ) ) ) ),
						'redirect'   => (string) ( $settings['redirect']['url'] ?? '' ),
					],
				],
				$count_view,
				'elementor'
			);
		}

		$form_id = (int) ( $settings['form_id'] ?? 0 );
		if ( $form_id <= 0 ) {
			return $this->editor_hint( __( 'Select a MailPilot form.', 'brainstudioz-mailpilot' ) );
		}

		$html = $module->render_form( $form_id, $count_view, 'elementor' );

		return '' !== $html ? $html : $this->editor_hint( __( 'Selected form is not published.', 'brainstudioz-mailpilot' ) );
	}

	/**
	 * Build Field objects from the inline repeater.
	 *
	 * @param array<string, mixed> $settings Settings.
	 * @return array<int, Field>
	 */
	private function inline_fields( array $settings ): array {
		$fields = [];

		foreach ( (array) ( $settings['inline_fields'] ?? [] ) as $i => $row ) {
			$label = (string) ( $row['field_label'] ?? '' );
			$type  = FieldType::fromString( (string) ( $row['field_type'] ?? 'email' ) );
			$key   = sanitize_key( $label ?: $type->value . '_' . $i );

			$fields[] = new Field(
				key: $key,
				type: $type,
				label: $label,
				required: 'yes' === ( $row['field_required'] ?? '' ),
				placeholder: (string) ( $row['field_placeholder'] ?? '' ),
			);
		}

		return $fields;
	}

	/**
	 * Published-form options for the selection control.
	 *
	 * @return array<string, string>
	 */
	private function form_options(): array {
		$options = [ '' => __( '— Select —', 'brainstudioz-mailpilot' ) ];

		foreach ( mailpilot()->forms()->repository()->all() as $form ) {
			$label = $form->title ?: sprintf( 'Form #%d', (int) $form->id );

			// List drafts too, flagged, so a just-built form is findable; the
			// widget shows a "publish it" hint when a draft is selected.
			if ( 'published' !== $form->status ) {
				/* translators: %s: form title. */
				$label = sprintf( __( '%s (draft)', 'brainstudioz-mailpilot' ), $label );
			}

			$options[ (string) $form->id ] = $label;
		}

		return $options;
	}

	/**
	 * Field-type options for the inline builder.
	 *
	 * @return array<string, string>
	 */
	private function field_type_options(): array {
		$options = [];
		foreach ( FieldType::cases() as $case ) {
			$options[ $case->value ] = ucfirst( $case->value );
		}

		return $options;
	}

	/**
	 * Whether we are rendering inside the Elementor editor.
	 */
	private function is_edit_mode(): bool {
		return class_exists( '\Elementor\Plugin' )
			&& \Elementor\Plugin::$instance->editor->is_edit_mode();
	}

	/**
	 * A small placeholder shown only in the editor.
	 *
	 * @param string $message Message.
	 */
	private function editor_hint( string $message ): string {
		return $this->is_edit_mode()
			? '<div class="mailpilot-form-placeholder" style="padding:16px;border:1px dashed #c3c4c7">' . esc_html( $message ) . '</div>'
			: '';
	}
}
