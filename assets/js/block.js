/**
 * MailPilot form block (editor). Server-rendered: a form picker plus a live
 * ServerSideRender preview. No build step — uses the global wp.* APIs.
 */
( function ( blocks, element, components, blockEditor, serverSideRender, i18n ) {
	'use strict';

	var el = element.createElement;
	var __ = i18n.__;
	var forms = ( window.MailPilotBlock && window.MailPilotBlock.forms ) || [];

	blocks.registerBlockType( 'mailpilot/form', {
		apiVersion: 2,
		title: __( 'MailPilot Form', 'mailpilot' ),
		icon: 'email-alt',
		category: 'widgets',
		attributes: { formId: { type: 'integer', default: 0 } },

		edit: function ( props ) {
			var formId = props.attributes.formId;

			var options = [ { value: 0, label: __( '— Select a form —', 'mailpilot' ) } ].concat(
				forms.map( function ( f ) {
					return { value: f.value, label: f.label };
				} )
			);

			var controls = el(
				blockEditor.InspectorControls,
				{},
				el(
					components.PanelBody,
					{ title: __( 'Form', 'mailpilot' ) },
					el( components.SelectControl, {
						label: __( 'Choose form', 'mailpilot' ),
						value: formId,
						options: options,
						onChange: function ( value ) {
							props.setAttributes( { formId: parseInt( value, 10 ) || 0 } );
						},
					} )
				)
			);

			var preview = formId
				? el( serverSideRender, { block: 'mailpilot/form', attributes: { formId: formId } } )
				: el( 'p', {}, __( 'Select a MailPilot form to embed.', 'mailpilot' ) );

			return el( 'div', blockEditor.useBlockProps(), controls, preview );
		},

		save: function () {
			return null; // Rendered in PHP.
		},
	} );
} )(
	window.wp.blocks,
	window.wp.element,
	window.wp.components,
	window.wp.blockEditor,
	window.wp.serverSideRender,
	window.wp.i18n
);
