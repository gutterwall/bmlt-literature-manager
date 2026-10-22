/**
 * BMLT Literature Manager — admin enhancements (vanilla JS, no dependencies).
 * @version 6.3.0
 */
( function () {
	'use strict';

	var A = window.bmltLitAdmin || { strings: {} };
	var S = A.strings;

	function ready( fn ) {
		if ( document.readyState === 'loading' ) {
			document.addEventListener( 'DOMContentLoaded', fn );
		} else {
			fn();
		}
	}

	function p( text, strong ) {
		var el = document.createElement( 'p' );
		if ( strong ) {
			var s = document.createElement( 'strong' );
			s.textContent = text;
			el.appendChild( s );
		} else {
			el.textContent = text;
		}
		return el;
	}

	ready( function () {
		// Confirmation prompts for destructive actions.
		document.addEventListener( 'click', function ( e ) {
			var el = e.target.closest ? e.target.closest( '[data-bmlt-confirm]' ) : null;
			if ( el && ! window.confirm( el.getAttribute( 'data-bmlt-confirm' ) || S.confirm ) ) {
				e.preventDefault();
			}
		} );

		// AJAX connection test (falls back to the normal form post without JS).
		var testBtn = document.querySelector( '.bmlt-lit-test-button' );
		var result = document.querySelector( '.bmlt-lit-test-result' );
		if ( testBtn && result && window.fetch && A.restUrl ) {
			testBtn.addEventListener( 'click', function ( e ) {
				e.preventDefault();
				testBtn.disabled = true;
				result.textContent = '';
				result.appendChild( p( S.testing ) );
				fetch( A.restUrl + 'admin/bmlt-test', {
					method: 'POST',
					credentials: 'same-origin',
					headers: { 'X-WP-Nonce': A.nonce, Accept: 'application/json' }
				} ).then( function ( r ) {
					return r.json();
				} ).then( function ( d ) {
					result.textContent = '';
					var box = document.createElement( 'div' );
					box.className = 'notice inline ' + ( d.success ? 'notice-success' : 'notice-error' );
					box.appendChild( p( d.success ? S.success : S.failed, true ) );
					if ( d.version ) {
						box.appendChild( p( S.version + ': ' + d.version ) );
					}
					if ( typeof d.service_bodies !== 'undefined' ) {
						box.appendChild( p( S.bodies + ': ' + d.service_bodies ) );
						box.appendChild( p( S.meetings + ': ' + d.meetings + ' ' + ( d.meetings_scope === 'selected' ? S.scopeSel : S.scopeAll ) ) );
					}
					( d.messages || ( d.message ? [ d.message ] : [] ) ).forEach( function ( m ) {
						box.appendChild( p( m ) );
					} );
					if ( d.success ) {
						box.appendChild( p( S.reloadSb ) );
					}
					result.appendChild( box );
				} ).catch( function () {
					result.textContent = '';
					result.appendChild( p( S.failed, true ) );
				} ).then( function () {
					testBtn.disabled = false;
				} );
			} );
		}

		// Service body filter.
		var sbFilter = document.querySelector( '.bmlt-lit-sb-filter' );
		if ( sbFilter ) {
			sbFilter.addEventListener( 'input', function () {
				var term = sbFilter.value.toLowerCase().trim();
				Array.prototype.forEach.call( document.querySelectorAll( '.bmlt-lit-sb' ), function ( l ) {
					l.hidden = term !== '' && l.getAttribute( 'data-name' ).indexOf( term ) === -1;
				} );
			} );
		}

		// Inventory +/- steppers.
		document.addEventListener( 'click', function ( e ) {
			var btn = e.target.closest ? e.target.closest( '.bmlt-lit-step' ) : null;
			if ( ! btn ) {
				return;
			}
			e.preventDefault();
			var input = btn.parentNode.querySelector( 'input[type="number"]' );
			var v = ( parseInt( input.value, 10 ) || 0 ) + parseInt( btn.getAttribute( 'data-step' ), 10 );
			input.value = String( Math.max( 0, v ) );
		} );

		// JSON editor helpers.
		var json = document.getElementById( 'bmlt-lit-json' );
		var status = document.querySelector( '.bmlt-lit-json-status' );
		var validate = document.querySelector( '.bmlt-lit-json-validate' );
		var copy = document.querySelector( '.bmlt-lit-json-copy' );
		if ( json && validate ) {
			validate.addEventListener( 'click', function () {
				try {
					var d = JSON.parse( json.value );
					if ( ! d || typeof d !== 'object' || ! Array.isArray( d.items ) ) {
						throw new Error( '"items" list missing' );
					}
					status.textContent = S.validJson;
				} catch ( err ) {
					status.textContent = S.badJson + ' ' + err.message;
				}
			} );
		}
		if ( json && copy ) {
			copy.addEventListener( 'click', function () {
				var done = function () {
					status.textContent = S.copied;
				};
				if ( navigator.clipboard && navigator.clipboard.writeText ) {
					navigator.clipboard.writeText( json.value ).then( done, function () {
						status.textContent = S.copyFail;
					} );
				} else {
					json.select();
					try {
						document.execCommand( 'copy' );
						done();
					} catch ( err ) {
						status.textContent = S.copyFail;
					}
				}
			} );
		}

		// Import type toggles.
		var radios = document.querySelectorAll( 'input[name="type"][value="catalog"], input[name="type"][value="inventory"]' );
		var catBox = document.querySelector( '.bmlt-lit-import-catalog' );
		var invBox = document.querySelector( '.bmlt-lit-import-inventory' );
		function toggle() {
			var inv = document.querySelector( 'input[name="type"][value="inventory"]:checked' );
			if ( catBox ) {
				catBox.hidden = !! inv;
			}
			if ( invBox ) {
				invBox.hidden = ! inv;
			}
		}
		Array.prototype.forEach.call( radios, function ( r ) {
			r.addEventListener( 'change', toggle );
		} );
		if ( catBox || invBox ) {
			toggle();
		}
	} );
} )();
