/**
 * MailPilot front-end forms: conditional logic + AJAX submit (progressive).
 *
 * Forms work without JavaScript (they post to admin-post.php). This script is
 * a progressive enhancement: it evaluates conditional-logic rules and submits
 * via fetch when available, showing inline success/error messages.
 */
( function () {
	'use strict';

	function fieldValue( form, key ) {
		var el = form.querySelector( '[name="fields[' + key + ']"]' );
		if ( ! el ) {
			return '';
		}
		if ( el.type === 'checkbox' ) {
			return el.checked ? el.value : '';
		}
		return el.value || '';
	}

	function evaluate( rule, actual ) {
		switch ( rule.operator ) {
			case 'is':
				return actual === rule.value;
			case 'is_not':
				return actual !== rule.value;
			case 'contains':
				return actual.indexOf( rule.value ) !== -1;
			case 'not_empty':
				return actual !== '';
			default:
				return true;
		}
	}

	function applyConditions( form ) {
		var conditional = form.querySelectorAll( '[data-condition-field]' );
		conditional.forEach( function ( wrap ) {
			var rule = {
				field: wrap.getAttribute( 'data-condition-field' ),
				operator: wrap.getAttribute( 'data-condition-operator' ),
				value: wrap.getAttribute( 'data-condition-value' ),
			};
			var show = evaluate( rule, fieldValue( form, rule.field ) );
			wrap.style.display = show ? '' : 'none';
		} );
	}

	function message( form, text, type ) {
		var box = form.querySelector( '.mailpilot-message' );
		if ( ! box ) {
			box = document.createElement( 'div' );
			form.insertBefore( box, form.firstChild );
		}
		box.className = 'mailpilot-message mailpilot-message-' + type;
		box.textContent = text;
	}

	// Free-tier "floating bar" / "slide in" forms (mailpilot-fallback-positioned)
	// get a session-only dismiss — not persisted across visits like Pro's
	// frequency capping, just "don't show it again for this tab session".
	function dismissKey( form ) {
		return 'mailpilot_dismissed_' + form.getAttribute( 'data-form-id' );
	}

	function applyFallbackDismiss( form ) {
		if ( ! form.classList.contains( 'mailpilot-fallback-positioned' ) ) {
			return;
		}

		try {
			if ( window.sessionStorage.getItem( dismissKey( form ) ) ) {
				form.hidden = true;
				return;
			}
		} catch ( e ) {
			// sessionStorage unavailable (privacy mode, etc.) — always show.
		}

		var dismiss = form.querySelector( '[data-dismiss-form]' );
		if ( dismiss ) {
			dismiss.addEventListener( 'click', function () {
				form.hidden = true;
				try {
					window.sessionStorage.setItem( dismissKey( form ), '1' );
				} catch ( e ) {
					// Ignore — worst case it reappears on the next page.
				}
			} );
		}
	}

	function onSubmit( e ) {
		var form = e.target;
		if ( ! window.fetch || ! form.classList.contains( 'mailpilot-form' ) ) {
			return; // Fall back to a normal POST.
		}
		e.preventDefault();

		var button = form.querySelector( 'button[type="submit"]' );
		if ( button ) {
			button.disabled = true;
		}

		// Use getAttribute, not form.action: the form has a hidden field named
		// "action" (required by admin-post.php), which shadows the .action URL
		// property and would otherwise resolve to "[object HTMLInputElement]".
		var endpoint = form.getAttribute( 'action' );

		fetch( endpoint, {
			method: 'POST',
			body: new FormData( form ),
			credentials: 'same-origin',
			headers: { 'X-Requested-With': 'XMLHttpRequest' },
		} )
			.then( function ( r ) {
				return r.json().catch( function () {
					return { success: r.ok };
				} );
			} )
			.then( function ( data ) {
				if ( data && data.success ) {
					message( form, ( data.data && data.data.message ) || 'Thanks for subscribing!', 'success' );
					if ( data.data && data.data.redirect ) {
						window.location.href = data.data.redirect;
						return;
					}
					form.reset();
				} else {
					message( form, ( data && data.data && data.data.message ) || 'Something went wrong.', 'error' );
				}
			} )
			.catch( function () {
				message( form, 'Network error. Please try again.', 'error' );
			} )
			.finally( function () {
				if ( button ) {
					button.disabled = false;
				}
			} );
	}

	document.addEventListener( 'DOMContentLoaded', function () {
		var forms = document.querySelectorAll( '.mailpilot-form' );
		forms.forEach( function ( form ) {
			applyConditions( form );
			applyFallbackDismiss( form );
			form.addEventListener( 'input', function () {
				applyConditions( form );
			} );
			form.addEventListener( 'change', function () {
				applyConditions( form );
			} );
			form.addEventListener( 'submit', onSubmit );
		} );
	} );
} )();
