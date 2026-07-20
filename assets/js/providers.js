/**
 * MailPilot provider connection form.
 *
 * Adapts the connect/edit form to the selected provider: shows only that
 * provider's credential fields, updates the API-docs link, and fetches the
 * provider's lists live into a dropdown. When editing a saved connection it
 * fetches lists using the stored credentials (by id) — no need to re-enter the
 * API key — and leaving a credential blank keeps the saved value.
 */
( function () {
	'use strict';

	var cfg = window.MailPilotProviders || { providers: {} };
	var __ = ( window.wp && window.wp.i18n && window.wp.i18n.__ ) ? window.wp.i18n.__ : function ( s ) { return s; };

	var form = document.querySelector( '.mailpilot-connect-form' );
	var providerSel = document.getElementById( 'mp-provider' );
	if ( ! form || ! providerSel ) {
		return;
	}

	var editing      = '1' === form.getAttribute( 'data-editing' );
	var connectionId = parseInt( form.getAttribute( 'data-connection' ), 10 ) || 0;

	var guideEl   = document.getElementById( 'mp-guide' );
	var listRow   = document.querySelector( '.mp-list-row' );
	var listSel   = document.getElementById( 'mp-list' );
	var listLabel = document.getElementById( 'mp-list-label' );
	var manual    = document.getElementById( 'mp-list-manual' );
	var fetchBtn  = document.getElementById( 'mp-fetch-lists' );
	var fetchMsg  = document.getElementById( 'mp-fetch-status' );
	var current   = ( listSel && listSel.getAttribute( 'data-current' ) ) || '';

	function meta() {
		return cfg.providers[ providerSel.value ] || {
			fields: [], guide: '', listSelection: false, listLabel: 'List',
			tagSelection: false, fieldMapping: false, doubleOptIn: false,
		};
	}

	function option( value, text, selected ) {
		var o = document.createElement( 'option' );
		o.value = value;
		o.textContent = text;
		if ( selected ) { o.selected = true; }
		return o;
	}

	// Show only the credential rows the chosen provider needs; set their labels.
	function applyProvider() {
		var m = meta();
		var wanted = {};
		( m.fields || [] ).forEach( function ( f ) { wanted[ f.key ] = f; } );

		document.querySelectorAll( '.mp-cred-row' ).forEach( function ( row ) {
			var key = row.getAttribute( 'data-cred' );
			var input = row.querySelector( '.mp-cred-input' );
			var label = row.querySelector( '.mp-cred-label' );
			if ( wanted[ key ] ) {
				row.style.display = '';
				if ( label ) { label.textContent = wanted[ key ].label || key; }
				if ( input ) {
					if ( editing ) {
						// Saved value is never re-shown; blank keeps it.
						input.placeholder = __( 'Leave blank to keep the saved value', 'mailpilot' );
						input.required = false;
					} else {
						input.placeholder = wanted[ key ].placeholder || '';
						input.required = !! wanted[ key ].required;
					}
				}
			} else {
				row.style.display = 'none';
				if ( input ) { input.required = false; }
			}
		} );

		// API-docs link.
		if ( guideEl ) {
			if ( m.guide ) {
				guideEl.innerHTML = '';
				guideEl.appendChild( document.createTextNode( __( 'Get your API key ', 'mailpilot' ) ) );
				var a = document.createElement( 'a' );
				a.href = m.guide;
				a.target = '_blank';
				a.rel = 'noopener noreferrer';
				a.textContent = __( 'here', 'mailpilot' );
				guideEl.appendChild( a );
				guideEl.appendChild( document.createTextNode( '.' ) );
				guideEl.style.display = '';
			} else {
				guideEl.style.display = 'none';
			}
		}

		// Capability-gated setting rows (default tags, field mapping, double opt-in).
		document.querySelectorAll( '.mp-cap-row' ).forEach( function ( row ) {
			row.style.display = m[ row.getAttribute( 'data-cap' ) ] ? '' : 'none';
		} );

		// List selector visibility + label.
		var noun = m.listLabel || __( 'List', 'mailpilot' );
		if ( listRow ) { listRow.style.display = m.listSelection ? '' : 'none'; }
		if ( listLabel ) { listLabel.textContent = noun; }
		if ( manual ) { manual.placeholder = noun + ' ' + __( 'ID', 'mailpilot' ); }
		resetList();
	}

	// Reset the dropdown to its pre-fetch state, preserving any saved selection.
	function resetList() {
		if ( ! listSel ) { return; }
		listSel.innerHTML = '';
		listSel.appendChild( option( '', __( '— Fetch lists, or enter an ID manually —', 'mailpilot' ) ) );
		if ( current ) {
			/* translators: %s: the saved list/audience id. */
			listSel.appendChild( option( current, __( 'Current:', 'mailpilot' ) + ' ' + current, true ) );
		}
		if ( fetchMsg ) { fetchMsg.textContent = ''; }
	}

	function populate( lists ) {
		listSel.innerHTML = '';
		listSel.appendChild( option( '', __( '— Select —', 'mailpilot' ) ) );
		var matched = false;
		lists.forEach( function ( l ) {
			var isCurrent = current && String( l.id ) === String( current );
			if ( isCurrent ) { matched = true; }
			listSel.appendChild( option( l.id, l.name + ' (' + l.id + ')', isCurrent ) );
		} );
		// Keep the saved selection even if the fetch didn't return it.
		if ( current && ! matched ) {
			listSel.appendChild( option( current, __( 'Current:', 'mailpilot' ) + ' ' + current, true ) );
		}
	}

	function gatherCredentials() {
		var creds = {};
		( meta().fields || [] ).forEach( function ( f ) {
			var input = document.getElementById( 'mp-cred-' + f.key );
			if ( input ) { creds[ f.key ] = input.value; }
		} );
		return creds;
	}

	function fetchLists() {
		if ( ! listSel ) { return; }
		fetchBtn.disabled = true;
		if ( fetchMsg ) { fetchMsg.textContent = __( 'Fetching…', 'mailpilot' ); }

		// Editing a saved connection → fetch with stored credentials by id.
		var body = connectionId > 0
			? { connection: connectionId }
			: { provider: providerSel.value, credentials: gatherCredentials() };

		fetch( cfg.restBase + '/lists', {
			method: 'POST',
			headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': cfg.nonce },
			credentials: 'same-origin',
			body: JSON.stringify( body ),
		} ).then( function ( r ) { return r.json(); } ).then( function ( data ) {
			var lists = ( data && data.lists ) || [];
			populate( lists );
			if ( fetchMsg ) {
				if ( lists.length ) {
					fetchMsg.textContent = lists.length + ' ' + __( 'found', 'mailpilot' );
				} else if ( data && data.error ) {
					// Surface the provider's real reason (e.g. an auth failure).
					fetchMsg.textContent = data.error;
				} else {
					fetchMsg.textContent = __( 'No lists found — check your credentials or enter an ID manually.', 'mailpilot' );
				}
			}
		} ).catch( function () {
			if ( fetchMsg ) { fetchMsg.textContent = __( 'Could not fetch lists.', 'mailpilot' ); }
		} ).finally( function () {
			fetchBtn.disabled = false;
		} );
	}

	providerSel.addEventListener( 'change', applyProvider );
	if ( fetchBtn ) { fetchBtn.addEventListener( 'click', fetchLists ); }

	var manualToggle = document.getElementById( 'mp-manual-toggle' );
	if ( manualToggle && manual ) {
		manualToggle.addEventListener( 'click', function ( e ) {
			e.preventDefault();
			manual.style.display = ( 'none' === manual.style.display ) ? '' : 'none';
			if ( '' === manual.style.display ) { manual.focus(); }
		} );
	}

	applyProvider();

	// When editing, pull the audiences straight away using the saved key.
	if ( editing && connectionId > 0 && meta().listSelection ) {
		fetchLists();
	}
} )();
