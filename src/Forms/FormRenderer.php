<?php
/**
 * Form HTML renderer.
 *
 * @package MailPilot
 */

declare( strict_types=1 );

namespace MailPilot\Forms;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Renders a form to accessible, mobile-optimised HTML.
 *
 * Output posts to admin-post.php so it works without JavaScript; conditional
 * logic and display behaviour are progressive enhancements carried on data
 * attributes and handled by the bundled script.
 */
final class FormRenderer {

	/**
	 * Render the form markup.
	 *
	 * @param Form   $form        Form to render.
	 * @param string $attribution Source surface for analytics (e.g. `form`, `elementor`).
	 */
	public function render( Form $form, string $attribution = 'form' ): string {
		if ( ! $form->id ) {
			return '';
		}

		$this->enqueue_assets();

		$pro_popups_active = $this->pro_popups_active();
		$out                = '';

		if ( DisplayTypeMode::needs_pro_trigger( $form->display_type ) && ! $pro_popups_active ) {
			$out .= $this->pro_upsell_hint( $form->display_type );
		}

		// Pro's PopupModule wraps popup-type forms in its own overlay/trigger
		// chrome when active; only add Free's fallback positioning when it
		// isn't, so the two never compete for the same `position: fixed`.
		$fallback_positioned = ! $pro_popups_active && DisplayTypeMode::gets_free_fallback_position( $form->display_type );

		$action  = esc_url( admin_url( 'admin-post.php' ) );
		$classes = 'mailpilot-form mailpilot-display-' . sanitize_html_class( $form->display_type );
		if ( $fallback_positioned ) {
			$classes .= ' mailpilot-fallback-positioned';
		}

		$out .= sprintf( '<form class="%s" method="post" action="%s" data-form-id="%d" novalidate>', esc_attr( $classes ), $action, (int) $form->id );
		$out .= '<input type="hidden" name="action" value="mailpilot_form_submit" />';
		$out .= sprintf( '<input type="hidden" name="form_id" value="%d" />', (int) $form->id );
		$out .= sprintf( '<input type="hidden" name="attribution" value="%s" />', esc_attr( sanitize_key( $attribution ) ) );
		$out .= wp_nonce_field( 'mailpilot_form_' . $form->id, 'mailpilot_nonce', true, false );

		if ( $fallback_positioned ) {
			$out .= sprintf(
				'<button type="button" class="mailpilot-dismiss" data-dismiss-form aria-label="%s">&times;</button>',
				esc_attr__( 'Dismiss', 'brainstudioz-mailpilot' )
			);
		}

		// Honeypot — bots fill it, humans do not; the field is visually hidden.
		$out .= '<div aria-hidden="true" style="position:absolute;left:-9999px"><label>' . esc_html__( 'Leave this field empty', 'brainstudioz-mailpilot' ) . '<input type="text" name="mailpilot_hp" tabindex="-1" autocomplete="off" /></label></div>';

		foreach ( $form->fields as $field ) {
			$out .= $this->render_field( $field );
		}

		$button = (string) $form->setting( 'button_text', __( 'Subscribe', 'brainstudioz-mailpilot' ) );
		$out   .= sprintf(
			'<div class="mailpilot-field mailpilot-submit"><button type="submit">%s</button></div>',
			esc_html( $button )
		);

		$out .= '</form>';

		return $out;
	}

	/**
	 * Stable extension point: open a submission `<form>` (hidden inputs, nonce,
	 * honeypot) without the fields/submit — so an add-on (e.g. Pro's popup
	 * design renderer) can compose its own layout between {@see self::form_open()}
	 * and {@see self::form_close()}. Part of the versioned extension API.
	 *
	 * @param Form   $form        The form.
	 * @param string $attribution Analytics attribution key.
	 */
	public function form_open( Form $form, string $attribution = 'popup' ): string {
		if ( ! $form->id ) {
			return '';
		}

		$this->enqueue_assets();

		$action  = esc_url( admin_url( 'admin-post.php' ) );
		$classes = 'mailpilot-form mailpilot-popup-form mailpilot-display-' . sanitize_html_class( $form->display_type );

		$out  = sprintf( '<form class="%s" method="post" action="%s" data-form-id="%d" novalidate>', esc_attr( $classes ), $action, (int) $form->id );
		$out .= '<input type="hidden" name="action" value="mailpilot_form_submit" />';
		$out .= sprintf( '<input type="hidden" name="form_id" value="%d" />', (int) $form->id );
		$out .= sprintf( '<input type="hidden" name="attribution" value="%s" />', esc_attr( sanitize_key( $attribution ) ) );
		$out .= wp_nonce_field( 'mailpilot_form_' . $form->id, 'mailpilot_nonce', true, false );
		$out .= '<div aria-hidden="true" style="position:absolute;left:-9999px"><label>' . esc_html__( 'Leave this field empty', 'brainstudioz-mailpilot' ) . '<input type="text" name="mailpilot_hp" tabindex="-1" autocomplete="off" /></label></div>';

		return $out;
	}

	/**
	 * The form's input fields only (no wrapper/submit). Composed by add-ons.
	 *
	 * @param Form $form The form.
	 */
	public function fields_html( Form $form ): string {
		$out = '';
		foreach ( $form->fields as $field ) {
			$out .= $this->render_field( $field );
		}

		return $out;
	}

	/**
	 * The submit button only. Composed by add-ons.
	 *
	 * @param Form   $form  The form.
	 * @param string $label Optional button label (defaults to the form's).
	 */
	public function submit_html( Form $form, string $label = '' ): string {
		$label = '' !== trim( $label ) ? $label : (string) $form->setting( 'button_text', __( 'Subscribe', 'brainstudioz-mailpilot' ) );

		return sprintf(
			'<div class="mailpilot-field mailpilot-submit"><button type="submit">%s</button></div>',
			esc_html( $label )
		);
	}

	/**
	 * Close a form opened with {@see self::form_open()}.
	 */
	public function form_close(): string {
		return '</form>';
	}

	/**
	 * Render a single field.
	 *
	 * @param Field $field Field to render.
	 */
	private function render_field( Field $field ): string {
		$id   = 'mp-' . sanitize_html_class( $field->key );
		$name = 'fields[' . esc_attr( $field->key ) . ']';
		$req  = $field->required ? ' required aria-required="true"' : '';

		// Conditional-logic data attributes for progressive show/hide.
		$cond = '';
		if ( $field->conditional ) {
			$cond = sprintf(
				' data-condition-field="%s" data-condition-operator="%s" data-condition-value="%s" style="display:none"',
				esc_attr( $field->conditional['field'] ),
				esc_attr( $field->conditional['operator'] ),
				esc_attr( $field->conditional['value'] )
			);
		}

		// Hidden fields render without a wrapper/label.
		if ( FieldType::Hidden === $field->type ) {
			return sprintf( '<input type="hidden" name="%s" value="%s" />', esc_attr( $name ), esc_attr( $field->default ) );
		}

		// A Name field set to "split" mode renders as two inputs instead of one.
		if ( FieldType::Name === $field->type && 'split' === $field->name_mode ) {
			return $this->render_name_split_field( $field, $id, $cond );
		}

		$control = $this->control( $field, $id, $name, $req );

		$label = '';
		if ( '' !== $field->label && FieldType::Gdpr !== $field->type ) {
			$label = sprintf(
				'<label for="%s">%s%s</label>',
				esc_attr( $id ),
				esc_html( $field->label ),
				$field->required ? ' <span class="mailpilot-required" aria-hidden="true">*</span>' : ''
			);
		}

		return sprintf(
			'<div class="mailpilot-field mailpilot-field-%s"%s>%s%s</div>',
			esc_attr( $field->type->value ),
			$cond,
			$label,
			$control
		);
	}

	/**
	 * Render a Name field configured for two inputs (First name / Last name)
	 * instead of one.
	 *
	 * Submitted as `fields[<key>][first]` / `fields[<key>][last]` — a nested
	 * array rather than the single string every other field type posts.
	 * `FormSubmissionHandler` reads both keys directly for this mode instead
	 * of splitting one string on whitespace. Only the first-name input honours
	 * the field's `required` setting; last name is always optional, matching
	 * common form conventions.
	 *
	 * @param Field  $field Field (type Name, name_mode split).
	 * @param string $id    Base element id.
	 * @param string $cond  Conditional-logic data attributes.
	 */
	private function render_name_split_field( Field $field, string $id, string $cond ): string {
		$name = 'fields[' . esc_attr( $field->key ) . ']';
		$req  = $field->required ? ' required aria-required="true"' : '';

		$legend = '';
		if ( '' !== $field->label ) {
			$legend = sprintf(
				'<span class="mailpilot-name-legend">%s%s</span>',
				esc_html( $field->label ),
				$field->required ? ' <span class="mailpilot-required" aria-hidden="true">*</span>' : ''
			);
		}

		$first = sprintf(
			'<div class="mailpilot-name-part"><label for="%1$s-first">%2$s</label><input type="text" id="%1$s-first" name="%3$s[first]" value="" placeholder="%2$s"%4$s inputmode="text" /></div>',
			esc_attr( $id ),
			esc_html__( 'First name', 'brainstudioz-mailpilot' ),
			esc_attr( $name ),
			$req
		);

		$last = sprintf(
			'<div class="mailpilot-name-part"><label for="%1$s-last">%2$s</label><input type="text" id="%1$s-last" name="%3$s[last]" value="" placeholder="%2$s" inputmode="text" /></div>',
			esc_attr( $id ),
			esc_html__( 'Last name', 'brainstudioz-mailpilot' ),
			esc_attr( $name )
		);

		return sprintf(
			'<div class="mailpilot-field mailpilot-field-name mailpilot-field-name-split"%s>%s<div class="mailpilot-name-group">%s%s</div></div>',
			$cond,
			$legend,
			$first,
			$last
		);
	}

	/**
	 * Render the input control for a field.
	 *
	 * @param Field  $field Field.
	 * @param string $id    Element id.
	 * @param string $name  Element name.
	 * @param string $req   Required attributes string.
	 */
	private function control( Field $field, string $id, string $name, string $req ): string {
		$placeholder = '' !== $field->placeholder ? sprintf( ' placeholder="%s"', esc_attr( $field->placeholder ) ) : '';

		return match ( $field->type ) {
			FieldType::Textarea => sprintf(
				'<textarea id="%s" name="%s"%s%s>%s</textarea>',
				esc_attr( $id ),
				esc_attr( $name ),
				$placeholder,
				$req,
				esc_textarea( $field->default )
			),
			FieldType::Dropdown => $this->select( $field, $id, $name, $req ),
			FieldType::Radio, FieldType::Checkbox => $this->choices( $field, $name ),
			FieldType::Gdpr => sprintf(
				'<label class="mailpilot-gdpr"><input type="checkbox" name="%s" value="1"%s /> %s</label>',
				esc_attr( $name ),
				$req,
				esc_html( '' !== $field->label ? $field->label : __( 'I consent to receiving emails.', 'brainstudioz-mailpilot' ) )
			),
			default => sprintf(
				'<input type="%s" id="%s" name="%s" value="%s"%s%s%s />',
				esc_attr( $field->type->html_input_type() ),
				esc_attr( $id ),
				esc_attr( $name ),
				esc_attr( $field->default ),
				$placeholder,
				$req,
				'inputmode="' . esc_attr( $this->inputmode( $field->type ) ) . '"'
			),
		};
	}

	/**
	 * Render a select control.
	 *
	 * @param Field  $field Field.
	 * @param string $id    Element id.
	 * @param string $name  Element name.
	 * @param string $req   Required attributes.
	 */
	private function select( Field $field, string $id, string $name, string $req ): string {
		$out = sprintf( '<select id="%s" name="%s"%s>', esc_attr( $id ), esc_attr( $name ), $req );
		if ( '' !== $field->placeholder ) {
			$out .= sprintf( '<option value="">%s</option>', esc_html( $field->placeholder ) );
		}
		foreach ( $field->options as $opt ) {
			$out .= sprintf(
				'<option value="%s"%s>%s</option>',
				esc_attr( $opt['value'] ),
				selected( $field->default, $opt['value'], false ),
				esc_html( $opt['label'] )
			);
		}

		return $out . '</select>';
	}

	/**
	 * Render radio/checkbox choices.
	 *
	 * @param Field  $field Field.
	 * @param string $name  Element name.
	 */
	private function choices( Field $field, string $name ): string {
		$type   = FieldType::Checkbox === $field->type ? 'checkbox' : 'radio';
		$suffix = FieldType::Checkbox === $field->type ? '[]' : '';
		$out    = '<div class="mailpilot-choices" role="group">';

		foreach ( $field->options as $i => $opt ) {
			$out .= sprintf(
				'<label><input type="%s" name="%s%s" value="%s"%s /> %s</label>',
				$type,
				esc_attr( $name ),
				$suffix,
				esc_attr( $opt['value'] ),
				checked( $field->default, $opt['value'], false ),
				esc_html( $opt['label'] )
			);
		}

		return $out . '</div>';
	}

	/**
	 * Mobile input mode hint per field type.
	 *
	 * @param FieldType $type Field type.
	 */
	private function inputmode( FieldType $type ): string {
		return match ( $type ) {
			FieldType::Email   => 'email',
			FieldType::Phone   => 'tel',
			FieldType::Number  => 'numeric',
			FieldType::Website => 'url',
			default            => 'text',
		};
	}

	/**
	 * Enqueue the form CSS/JS (registered in FormAssets).
	 */
	private function enqueue_assets(): void {
		if ( function_exists( 'wp_enqueue_style' ) ) {
			wp_enqueue_style( 'mailpilot-forms' );
			wp_enqueue_script( 'mailpilot-forms' );
		}
	}

	/**
	 * Whether MailPilot Pro's Popups & Lead Capture module is actually
	 * registered and unlocked — not merely whether Pro's own gating filter
	 * happens to allow the `popups` feature, which is also true when no
	 * gating add-on is installed at all (`License::can()`'s "core withholds
	 * nothing" default). Checking `mailpilot_pro_booted` alongside it is what
	 * distinguishes "Pro is absent" from "Pro is active and this is unlocked".
	 */
	private function pro_popups_active(): bool {
		return did_action( 'mailpilot_pro_booted' ) > 0 && mailpilot()->license()->can( 'popups' );
	}

	/**
	 * An editor-only explanation for a Pro-triggered display type rendered
	 * inline because Pro isn't active — mirrors the notice style used
	 * elsewhere (e.g. `FormsModule::editor_hint()`) and is invisible to
	 * regular visitors.
	 *
	 * @param string $display_type The form's configured display type.
	 */
	private function pro_upsell_hint( string $display_type ): string {
		if ( ! current_user_can( 'edit_posts' ) ) {
			return '';
		}

		$label = 'full_screen' === $display_type ? __( 'Full screen', 'brainstudioz-mailpilot' ) : __( 'Popup', 'brainstudioz-mailpilot' );

		return '<div class="mailpilot-form-notice" style="padding:12px 14px;border:1px dashed #c3c4c7;border-radius:6px;color:#646970;font-size:14px;margin:0 0 8px">'
			. esc_html(
				sprintf(
					/* translators: %s: display type label, e.g. "Popup" or "Full screen". */
					__( 'MailPilot: this form is set to display as "%s". That needs MailPilot Pro’s Popups & Lead Capture module for its trigger, frequency, and A/B testing — showing it inline instead.', 'brainstudioz-mailpilot' ),
					$label
				)
			)
			. '</div>';
	}
}
