/**
 * MailPilot drag-and-drop form builder.
 *
 * A no-build-step vanilla-JS app: a field palette, a sortable canvas (HTML5
 * drag-and-drop), and an inspector for field/form settings. Reads and writes
 * forms through the Forms REST endpoints.
 */
( function () {
	'use strict';

	var cfg = window.MailPilotBuilder || {};
	var __ = ( window.wp && window.wp.i18n && window.wp.i18n.__ ) ? window.wp.i18n.__ : function ( s ) { return s; };

	var POPUP_TYPES = [ 'popup', 'floating_bar', 'slide_in', 'full_screen' ];
	var OPERATORS = [ 'is', 'is_not', 'contains', 'not_empty' ];

	// --- state ---------------------------------------------------------------
	var state = {
		form: {
			id: 0,
			title: '',
			status: 'draft',
			display_type: 'inline',
			fields: [],
			actions: { apply_tags: [], providers: [], webhook: '', redirect: '' },
			settings: { button_text: 'Subscribe', success_message: 'Thanks for subscribing!', double_opt_in: false, popup: {} },
		},
		selected: -1,
		tab: 'field',
		dragFrom: null, // index of a canvas card being dragged.
	};

	// --- helpers -------------------------------------------------------------
	function el( tag, attrs, children ) {
		var node = document.createElement( tag );
		attrs = attrs || {};
		Object.keys( attrs ).forEach( function ( k ) {
			if ( k === 'class' ) { node.className = attrs[ k ]; }
			else if ( k === 'text' ) { node.textContent = attrs[ k ]; }
			else if ( k === 'html' ) { node.innerHTML = attrs[ k ]; }
			else if ( k.indexOf( 'on' ) === 0 ) { node.addEventListener( k.slice( 2 ).toLowerCase(), attrs[ k ] ); }
			else if ( attrs[ k ] !== null && attrs[ k ] !== false ) { node.setAttribute( k, attrs[ k ] ); }
		} );
		( children || [] ).forEach( function ( c ) {
			if ( c ) { node.appendChild( typeof c === 'string' ? document.createTextNode( c ) : c ); }
		} );
		return node;
	}

	function typeLabel( type ) {
		var found = ( cfg.fieldTypes || [] ).filter( function ( t ) { return t.type === type; } )[ 0 ];
		return found ? found.label : type;
	}

	function hasOptions( type ) {
		var found = ( cfg.fieldTypes || [] ).filter( function ( t ) { return t.type === type; } )[ 0 ];
		return !! ( found && found.hasOptions );
	}

	function slug( s ) {
		return ( s || '' ).toString().toLowerCase().replace( /[^a-z0-9_]+/g, '_' ).replace( /^_+|_+$/g, '' );
	}

	function newField( type ) {
		return {
			key: '',
			type: type,
			label: typeLabel( type ),
			required: type === 'email',
			placeholder: '',
			default: '',
			validation: 'none',
			options: hasOptions( type ) ? [ { value: 'Option 1', label: 'Option 1' } ] : [],
			conditional: null,
			name_mode: 'single',
		};
	}

	// --- REST ----------------------------------------------------------------
	function api( path, method, body ) {
		return fetch( cfg.restBase + path, {
			method: method,
			headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': cfg.nonce },
			credentials: 'same-origin',
			body: body ? JSON.stringify( body ) : undefined,
		} ).then( function ( r ) { return r.json(); } );
	}

	function load() {
		if ( cfg.formId > 0 ) {
			api( '/' + cfg.formId, 'GET' ).then( function ( data ) {
				if ( data && data.id ) {
					state.form = normalize( data );
				}
				state.selected = state.form.fields.length ? 0 : -1;
				renderAll();
			} );
		} else {
			state.form.fields = [ newField( 'email' ) ];
			state.selected = 0;
			renderAll();
		}
	}

	function normalize( data ) {
		var f = data || {};
		f.actions = f.actions || {};
		f.settings = f.settings || {};
		return {
			id: f.id || 0,
			title: f.title || '',
			status: f.status || 'draft',
			display_type: f.display_type || 'inline',
			fields: ( f.fields || [] ).map( function ( x ) { x.options = x.options || []; return x; } ),
			actions: {
				apply_tags: f.actions.apply_tags || [],
				providers: f.actions.providers || [],
				webhook: f.actions.webhook || '',
				redirect: f.actions.redirect || '',
				lead_magnet: f.actions.lead_magnet || 0,
			},
			settings: {
				button_text: f.settings.button_text || 'Subscribe',
				success_message: f.settings.success_message || 'Thanks for subscribing!',
				double_opt_in: !! f.settings.double_opt_in,
				popup: f.settings.popup || {},
			},
		};
	}

	function save() {
		// Backfill empty keys from labels.
		state.form.fields.forEach( function ( fld ) {
			if ( ! fld.key ) { fld.key = slug( fld.label ) || fld.type; }
		} );

		var path = state.form.id > 0 ? '/' + state.form.id : '';
		var btn = document.getElementById( 'mp-save' );
		if ( btn ) { btn.disabled = true; btn.textContent = __( 'Saving…', 'mailpilot' ); }

		api( path, 'POST', state.form ).then( function ( data ) {
			if ( data && data.id ) {
				var wasNew = ! state.form.id;
				state.form = normalize( data );
				notice( __( 'Form saved.', 'mailpilot' ), 'success' );
				if ( wasNew ) {
					window.history.replaceState( {}, '', cfg.editUrlBase + '&form=' + data.id );
					cfg.formId = data.id;
				}
				renderAll();
			} else {
				notice( __( 'Save failed.', 'mailpilot' ), 'error' );
			}
		} ).catch( function () {
			notice( __( 'Network error while saving.', 'mailpilot' ), 'error' );
		} ).finally( function () {
			if ( btn ) { btn.disabled = false; btn.textContent = __( 'Save Form', 'mailpilot' ); }
		} );
	}

	function notice( msg, type ) {
		var bar = document.getElementById( 'mp-notice' );
		if ( ! bar ) { return; }
		bar.className = 'mp-notice mp-notice-' + type;
		bar.textContent = msg;
		bar.style.display = 'block';
		window.setTimeout( function () { bar.style.display = 'none'; }, 3000 );
	}

	// --- render: skeleton ----------------------------------------------------
	function renderAll() {
		var root = document.getElementById( 'mailpilot-builder-root' );
		root.className = '';
		root.innerHTML = '';

		root.appendChild( topbar() );
		root.appendChild( el( 'div', { id: 'mp-notice', class: 'mp-notice', style: 'display:none' } ) );

		var cols = el( 'div', { class: 'mp-builder' }, [
			palette(),
			el( 'div', { id: 'mp-canvas-col', class: 'mp-canvas-col' } ),
			el( 'div', { id: 'mp-inspector-col', class: 'mp-inspector-col' } ),
		] );
		root.appendChild( cols );

		renderCanvas();
		renderInspector();
	}

	function topbar() {
		var f = state.form;
		var displayOpts = ( cfg.displayTypes || [] ).map( function ( d ) {
			return el( 'option', { value: d.value, text: d.label, selected: f.display_type === d.value ? 'selected' : null } );
		} );

		return el( 'div', { class: 'mp-topbar' }, [
			el( 'a', { class: 'button', href: cfg.listUrl, text: '← ' + __( 'Forms', 'mailpilot' ) } ),
			el( 'input', {
				type: 'text', class: 'mp-title', value: f.title, placeholder: __( 'Form title', 'mailpilot' ),
				oninput: function ( e ) { f.title = e.target.value; },
			} ),
			el( 'select', { class: 'mp-status', onchange: function ( e ) { f.status = e.target.value; } }, [
				el( 'option', { value: 'draft', text: __( 'Draft', 'mailpilot' ), selected: f.status === 'draft' ? 'selected' : null } ),
				el( 'option', { value: 'published', text: __( 'Published', 'mailpilot' ), selected: f.status === 'published' ? 'selected' : null } ),
			] ),
			el( 'select', { class: 'mp-display', onchange: function ( e ) { f.display_type = e.target.value; renderInspector(); } }, displayOpts ),
			el( 'button', { id: 'mp-save', class: 'button button-primary', text: __( 'Save Form', 'mailpilot' ), onclick: save } ),
		] );
	}

	// --- render: palette -----------------------------------------------------
	function palette() {
		var items = ( cfg.fieldTypes || [] ).map( function ( t ) {
			return el( 'div', {
				class: 'mp-palette-item', draggable: 'true', 'data-type': t.type, text: t.label,
				ondragstart: function ( e ) { e.dataTransfer.setData( 'text/mp-new', t.type ); state.dragFrom = null; },
				onclick: function () { addField( t.type ); },
			} );
		} );

		return el( 'div', { class: 'mp-palette' }, [
			el( 'h3', { text: __( 'Fields', 'mailpilot' ) } ),
			el( 'p', { class: 'mp-hint', text: __( 'Click or drag onto the form.', 'mailpilot' ) } ),
		].concat( items ) );
	}

	// --- render: canvas ------------------------------------------------------
	function renderCanvas() {
		var col = document.getElementById( 'mp-canvas-col' );
		col.innerHTML = '';

		var canvas = el( 'div', {
			id: 'mp-canvas', class: 'mp-canvas',
			ondragover: function ( e ) { e.preventDefault(); canvas.classList.add( 'mp-drop' ); },
			ondragleave: function () { canvas.classList.remove( 'mp-drop' ); },
			ondrop: function ( e ) {
				e.preventDefault();
				canvas.classList.remove( 'mp-drop' );
				var newType = e.dataTransfer.getData( 'text/mp-new' );
				if ( newType ) { addField( newType ); }
			},
		} );

		if ( ! state.form.fields.length ) {
			canvas.appendChild( el( 'div', { class: 'mp-empty', text: __( 'Drag fields here to start building.', 'mailpilot' ) } ) );
		}

		state.form.fields.forEach( function ( fld, i ) {
			canvas.appendChild( fieldCard( fld, i ) );
		} );

		col.appendChild( canvas );
	}

	function fieldCard( fld, i ) {
		var card = el( 'div', {
			class: 'mp-card' + ( state.selected === i ? ' mp-selected' : '' ),
			draggable: 'true',
			'data-index': i,
			onclick: function () { state.selected = i; state.tab = 'field'; renderCanvas(); renderInspector(); },
			ondragstart: function ( e ) { state.dragFrom = i; e.dataTransfer.effectAllowed = 'move'; },
			ondragover: function ( e ) { e.preventDefault(); card.classList.add( 'mp-over' ); },
			ondragleave: function () { card.classList.remove( 'mp-over' ); },
			ondrop: function ( e ) {
				e.preventDefault();
				e.stopPropagation();
				card.classList.remove( 'mp-over' );
				if ( state.dragFrom !== null && state.dragFrom !== i ) { moveField( state.dragFrom, i ); }
				state.dragFrom = null;
			},
		}, [
			el( 'span', { class: 'mp-handle', text: '⠿' } ),
			el( 'span', { class: 'mp-card-label', text: ( fld.label || __( '(no label)', 'mailpilot' ) ) } ),
			el( 'span', { class: 'mp-card-type', text: typeLabel( fld.type ) + ( fld.required ? ' *' : '' ) } ),
			el( 'button', {
				class: 'mp-card-del', title: __( 'Remove', 'mailpilot' ), text: '×',
				onclick: function ( e ) { e.stopPropagation(); removeField( i ); },
			} ),
		] );
		return card;
	}

	// --- field operations ----------------------------------------------------
	function addField( type ) {
		state.form.fields.push( newField( type ) );
		state.selected = state.form.fields.length - 1;
		state.tab = 'field';
		renderCanvas();
		renderInspector();
	}

	function removeField( i ) {
		state.form.fields.splice( i, 1 );
		if ( state.selected >= state.form.fields.length ) { state.selected = state.form.fields.length - 1; }
		renderCanvas();
		renderInspector();
	}

	function moveField( from, to ) {
		var moved = state.form.fields.splice( from, 1 )[ 0 ];
		state.form.fields.splice( to, 0, moved );
		state.selected = to;
		renderCanvas();
		renderInspector();
	}

	// --- render: inspector ---------------------------------------------------
	function renderInspector() {
		var col = document.getElementById( 'mp-inspector-col' );
		col.innerHTML = '';

		var tabs = el( 'div', { class: 'mp-tabs' }, [
			tabBtn( 'field', __( 'Field', 'mailpilot' ) ),
			tabBtn( 'form', __( 'Form', 'mailpilot' ) ),
		] );
		col.appendChild( tabs );

		col.appendChild( state.tab === 'form' ? formPanel() : fieldPanel() );
	}

	function tabBtn( id, label ) {
		return el( 'button', {
			class: 'mp-tab' + ( state.tab === id ? ' active' : '' ), text: label,
			onclick: function () { state.tab = id; renderInspector(); },
		} );
	}

	function field( labelText, control ) {
		return el( 'div', { class: 'mp-row' }, [ el( 'label', { text: labelText } ), control ] );
	}

	function textInput( value, onInput, placeholder ) {
		return el( 'input', { type: 'text', value: value || '', placeholder: placeholder || '', oninput: onInput } );
	}

	function fieldPanel() {
		if ( state.selected < 0 || ! state.form.fields[ state.selected ] ) {
			return el( 'div', { class: 'mp-panel' }, [ el( 'p', { class: 'mp-hint', text: __( 'Select a field to edit it.', 'mailpilot' ) } ) ] );
		}

		var fld = state.form.fields[ state.selected ];
		var rows = [];

		rows.push( field( __( 'Label', 'mailpilot' ), textInput( fld.label, function ( e ) {
			fld.label = e.target.value;
			var card = document.querySelector( '.mp-card[data-index="' + state.selected + '"] .mp-card-label' );
			if ( card ) { card.textContent = fld.label || __( '(no label)', 'mailpilot' ); }
		} ) ) );

		if ( fld.type === 'name' ) {
			rows.push( field( __( 'Name field', 'mailpilot' ), select(
				[ 'single', 'split' ],
				fld.name_mode || 'single',
				function ( v ) { fld.name_mode = v; },
				[ __( '1 field (Full name)', 'mailpilot' ), __( '2 fields (First name / Last name)', 'mailpilot' ) ]
			) ) );
		}

		rows.push( field( __( 'Key', 'mailpilot' ), textInput( fld.key, function ( e ) { fld.key = slug( e.target.value ); }, __( 'auto from label', 'mailpilot' ) ) ) );
		rows.push( field( __( 'Placeholder', 'mailpilot' ), textInput( fld.placeholder, function ( e ) { fld.placeholder = e.target.value; } ) ) );
		rows.push( field( __( 'Default value', 'mailpilot' ), textInput( fld.default, function ( e ) { fld.default = e.target.value; } ) ) );

		rows.push( field( __( 'Required', 'mailpilot' ), el( 'input', {
			type: 'checkbox', checked: fld.required ? 'checked' : null,
			onchange: function ( e ) {
				fld.required = e.target.checked;
				var t = document.querySelector( '.mp-card[data-index="' + state.selected + '"] .mp-card-type' );
				if ( t ) { t.textContent = typeLabel( fld.type ) + ( fld.required ? ' *' : '' ); }
			},
		} ) ) );

		rows.push( field( __( 'Validation', 'mailpilot' ), select( [ 'none', 'email', 'url', 'number' ], fld.validation, function ( v ) { fld.validation = v; } ) ) );

		if ( hasOptions( fld.type ) ) {
			rows.push( optionsEditor( fld ) );
		}

		rows.push( conditionalEditor( fld ) );

		return el( 'div', { class: 'mp-panel' }, rows );
	}

	function select( values, current, onChange, labels ) {
		var opts = values.map( function ( v, idx ) {
			return el( 'option', { value: v, text: labels ? labels[ idx ] : v, selected: current === v ? 'selected' : null } );
		} );
		return el( 'select', { onchange: function ( e ) { onChange( e.target.value ); } }, opts );
	}

	function optionsEditor( fld ) {
		var wrap = el( 'div', { class: 'mp-options' }, [ el( 'label', { text: __( 'Options', 'mailpilot' ) } ) ] );

		fld.options.forEach( function ( opt, idx ) {
			wrap.appendChild( el( 'div', { class: 'mp-opt-row' }, [
				textInput( opt.value, function ( e ) { opt.value = e.target.value; opt.label = e.target.value; }, __( 'Option', 'mailpilot' ) ),
				el( 'button', { class: 'button-link mp-opt-del', text: '×', onclick: function () { fld.options.splice( idx, 1 ); renderInspector(); } } ),
			] ) );
		} );

		wrap.appendChild( el( 'button', { class: 'button', text: __( '+ Add option', 'mailpilot' ), onclick: function () {
			fld.options.push( { value: 'Option ' + ( fld.options.length + 1 ), label: 'Option ' + ( fld.options.length + 1 ) } );
			renderInspector();
		} } ) );

		return wrap;
	}

	function conditionalEditor( fld ) {
		var enabled = !! ( fld.conditional && fld.conditional.field );
		var others = state.form.fields.filter( function ( f, i ) { return i !== state.selected && f.key; } );

		var wrap = el( 'div', { class: 'mp-cond' }, [
			el( 'label', {}, [
				el( 'input', {
					type: 'checkbox', checked: enabled ? 'checked' : null,
					onchange: function ( e ) {
						fld.conditional = e.target.checked ? { field: ( others[ 0 ] && others[ 0 ].key ) || '', operator: 'is', value: '' } : null;
						renderInspector();
					},
				} ),
				document.createTextNode( ' ' + __( 'Conditional logic', 'mailpilot' ) ),
			] ),
		] );

		if ( enabled ) {
			wrap.appendChild( el( 'p', { class: 'mp-hint', text: __( 'Show this field when:', 'mailpilot' ) } ) );
			wrap.appendChild( select( others.map( function ( f ) { return f.key; } ), fld.conditional.field, function ( v ) { fld.conditional.field = v; }, others.map( function ( f ) { return f.label || f.key; } ) ) );
			wrap.appendChild( select( OPERATORS, fld.conditional.operator, function ( v ) { fld.conditional.operator = v; } ) );
			if ( fld.conditional.operator !== 'not_empty' ) {
				wrap.appendChild( textInput( fld.conditional.value, function ( e ) { fld.conditional.value = e.target.value; }, __( 'value', 'mailpilot' ) ) );
			}
		}

		return wrap;
	}

	// --- render: form settings panel ----------------------------------------
	function formPanel() {
		var f = state.form;
		var s = f.settings;
		var rows = [];

		rows.push( field( __( 'Button text', 'mailpilot' ), textInput( s.button_text, function ( e ) { s.button_text = e.target.value; } ) ) );
		rows.push( field( __( 'Success message', 'mailpilot' ), textInput( s.success_message, function ( e ) { s.success_message = e.target.value; } ) ) );
		rows.push( field( __( 'Double opt-in', 'mailpilot' ), el( 'input', { type: 'checkbox', checked: s.double_opt_in ? 'checked' : null, onchange: function ( e ) { s.double_opt_in = e.target.checked; } } ) ) );

		rows.push( el( 'h4', { text: __( 'Actions', 'mailpilot' ) } ) );
		rows.push( field( __( 'Apply tags (comma-separated)', 'mailpilot' ), textInput( ( f.actions.apply_tags || [] ).join( ', ' ), function ( e ) {
			f.actions.apply_tags = e.target.value.split( ',' ).map( function ( t ) { return t.trim(); } ).filter( Boolean );
		} ) ) );

		// Providers as checkboxes.
		var provWrap = el( 'div', { class: 'mp-providers' } );
		( cfg.providers || [] ).forEach( function ( p ) {
			provWrap.appendChild( el( 'label', {}, [
				el( 'input', {
					type: 'checkbox', value: p.id, checked: ( f.actions.providers || [] ).indexOf( p.id ) > -1 ? 'checked' : null,
					onchange: function ( e ) {
						var id = parseInt( e.target.value, 10 );
						f.actions.providers = ( f.actions.providers || [] ).filter( function ( x ) { return x !== id; } );
						if ( e.target.checked ) { f.actions.providers.push( id ); }
					},
				} ),
				document.createTextNode( ' ' + p.label ),
			] ) );
		} );
		if ( ! ( cfg.providers || [] ).length ) {
			provWrap.appendChild( el( 'p', { class: 'mp-hint', text: __( 'No provider connections configured.', 'mailpilot' ) } ) );
		}
		rows.push( field( __( 'Send to providers', 'mailpilot' ), provWrap ) );

		rows.push( field( __( 'Webhook URL', 'mailpilot' ), textInput( f.actions.webhook, function ( e ) { f.actions.webhook = e.target.value; }, 'https://' ) ) );
		rows.push( field( __( 'Redirect URL', 'mailpilot' ), textInput( f.actions.redirect, function ( e ) { f.actions.redirect = e.target.value; }, 'https://' ) ) );
		rows.push( field( __( 'Lead magnet ID', 'mailpilot' ), textInput( f.actions.lead_magnet || '', function ( e ) { f.actions.lead_magnet = parseInt( e.target.value, 10 ) || 0; }, __( 'deliver this magnet on submit (Pro)', 'mailpilot' ) ) ) );

		// Popup behaviour when a popup display type is selected.
		if ( POPUP_TYPES.indexOf( f.display_type ) > -1 ) {
			s.popup = s.popup || {};
			var p = s.popup;
			var idList = function ( str ) {
				return ( str || '' ).split( ',' ).map( function ( x ) { return parseInt( x.trim(), 10 ); } ).filter( function ( n ) { return ! isNaN( n ); } );
			};

			rows.push( el( 'h4', { text: __( 'Popup behaviour', 'mailpilot' ) } ) );
			rows.push( field( __( 'Trigger', 'mailpilot' ), select( [ 'time_delay', 'scroll', 'exit_intent', 'click' ], p.trigger || 'time_delay', function ( v ) { p.trigger = v; } ) ) );
			rows.push( field( __( 'Trigger value', 'mailpilot' ), textInput( p.trigger_value || '5', function ( e ) { p.trigger_value = e.target.value; }, __( 'seconds / scroll % / selector', 'mailpilot' ) ) ) );
			rows.push( field( __( 'Frequency', 'mailpilot' ), select( [ 'always', 'once', 'daily', 'weekly' ], p.frequency || 'daily', function ( v ) { p.frequency = v; } ) ) );

			rows.push( el( 'h4', { text: __( 'Display rules', 'mailpilot' ) } ) );
			rows.push( field( __( 'Show on', 'mailpilot' ), select( [ 'all', 'front', 'posts', 'pages', 'products' ], p.display || 'all', function ( v ) { p.display = v; } ) ) );
			rows.push( field( __( 'Specific page/post IDs', 'mailpilot' ), textInput( ( p.include || [] ).join( ', ' ), function ( e ) { p.include = idList( e.target.value ); }, __( 'comma-separated IDs', 'mailpilot' ) ) ) );
			rows.push( field( __( 'Category IDs', 'mailpilot' ), textInput( ( p.categories || [] ).join( ', ' ), function ( e ) { p.categories = idList( e.target.value ); }, __( 'comma-separated', 'mailpilot' ) ) ) );
			rows.push( field( __( 'Tag IDs', 'mailpilot' ), textInput( ( p.tags || [] ).join( ', ' ), function ( e ) { p.tags = idList( e.target.value ); }, __( 'comma-separated', 'mailpilot' ) ) ) );
			rows.push( field( __( 'Product IDs', 'mailpilot' ), textInput( ( p.product_ids || [] ).join( ', ' ), function ( e ) { p.product_ids = idList( e.target.value ); }, __( 'comma-separated', 'mailpilot' ) ) ) );

			// A/B testing.
			p.ab_test = p.ab_test || { enabled: false, variant_b: 0 };
			rows.push( el( 'h4', { text: __( 'A/B test', 'mailpilot' ) } ) );
			rows.push( field( __( 'Enable A/B test', 'mailpilot' ), el( 'input', { type: 'checkbox', checked: p.ab_test.enabled ? 'checked' : null, onchange: function ( e ) { p.ab_test.enabled = e.target.checked; } } ) ) );
			rows.push( field( __( 'Variant B form ID', 'mailpilot' ), textInput( p.ab_test.variant_b || '', function ( e ) { p.ab_test.variant_b = parseInt( e.target.value, 10 ) || 0; }, __( 'optional — defaults to same form', 'mailpilot' ) ) ) );
		}

		if ( f.id ) {
			rows.push( el( 'div', { class: 'mp-shortcode' }, [
				el( 'label', { text: __( 'Shortcode', 'mailpilot' ) } ),
				el( 'code', { text: '[mailpilot_form id="' + f.id + '"]' } ),
			] ) );
		}

		return el( 'div', { class: 'mp-panel' }, rows );
	}

	// --- boot ----------------------------------------------------------------
	if ( document.getElementById( 'mailpilot-builder-root' ) ) {
		load();
	}
} )();
