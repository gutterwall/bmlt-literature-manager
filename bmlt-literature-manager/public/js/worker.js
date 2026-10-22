/**
 * BMLT Literature Manager — literature-worker workspace and order history.
 *
 * Talks to /wp-json/bmlt-lit/v1/ with the logged-in user's REST nonce. All permissions are enforced
 * on the server; this script only provides the interface. Data is inserted with textContent (no HTML injection).
 * @version 6.3.0
 */
( function () {
	'use strict';

	var C = window.bmltLitWorker;
	if ( ! C ) {
		return;
	}
	var S = C.strings;

	/* ---------- Helpers ---------- */

	function el( tag, attrs, children ) {
		var node = document.createElement( tag );
		if ( attrs ) {
			Object.keys( attrs ).forEach( function ( k ) {
				var v = attrs[ k ];
				if ( v === null || v === undefined || v === false ) {
					return;
				}
				if ( k === 'text' ) {
					node.textContent = v;
				} else if ( k === 'className' ) {
					node.className = v;
				} else if ( k.indexOf( 'on' ) === 0 && typeof v === 'function' ) {
					node.addEventListener( k.substring( 2 ), v );
				} else if ( v === true ) {
					node.setAttribute( k, '' );
				} else {
					node.setAttribute( k, String( v ) );
				}
			} );
		}
		( children || [] ).forEach( function ( c ) {
			if ( c === null || c === undefined || c === false ) {
				return;
			}
			node.appendChild( typeof c === 'string' || typeof c === 'number' ? document.createTextNode( String( c ) ) : c );
		} );
		return node;
	}

	function api( path, options ) {
		options = options || {};
		var url = C.restUrl + path;
		var init = {
			method: options.method || 'GET',
			credentials: 'same-origin',
			headers: { 'X-WP-Nonce': C.nonce, Accept: 'application/json' }
		};
		if ( options.params ) {
			var qs = Object.keys( options.params ).filter( function ( k ) {
				return options.params[ k ] !== '' && options.params[ k ] !== null && options.params[ k ] !== undefined;
			} ).map( function ( k ) {
				return encodeURIComponent( k ) + '=' + encodeURIComponent( options.params[ k ] );
			} ).join( '&' );
			if ( qs ) {
				url += ( url.indexOf( '?' ) === -1 ? '?' : '&' ) + qs;
			}
		}
		if ( options.body ) {
			init.headers[ 'Content-Type' ] = 'application/json';
			init.body = JSON.stringify( options.body );
		}
		return fetch( url, init ).then( function ( res ) {
			return res.json().catch( function () {
				return {};
			} ).then( function ( json ) {
				if ( ! res.ok ) {
					throw new Error( ( json && json.message ) ? json.message : S.error );
				}
				return json;
			} );
		} );
	}

	var ICONS = { in_stock: '\u2713', low_stock: '\u26A0', special_order: '!', partial: '!', inactive: '\u2715' };

	function badge( status ) {
		var def = C.stock[ status ] || { label: status };
		return el( 'span', { className: 'bmlt-lit-stock bmlt-lit-stock--' + status }, [
			el( 'span', { className: 'bmlt-lit-stock__icon', 'aria-hidden': 'true', text: ICONS[ status ] || '!' } ),
			' ',
			el( 'span', { className: 'bmlt-lit-stock__label', text: def.label } )
		] );
	}

	function statusPill( status, label ) {
		return el( 'span', { className: 'bmlt-lit-status-pill bmlt-lit-status-pill--' + status, text: label } );
	}

	/**
	 * Cell for the wrapping (fluid) Inventory and Catalog lists.
	 *
	 * @param {string} label    Field label shown above the value.
	 * @param {Array}  children Content.
	 * @param {string} size     Layout hint: item | wide | narrow | actions.
	 */
	function fcell( label, children, size ) {
		return el( 'td', { role: 'cell', 'data-label': label, className: size ? 'bmlt-lit-cell--' + size : null }, children );
	}

	function td( label, children ) {
		return el( 'td', { 'data-label': label }, children );
	}

	function debounce( fn, ms ) {
		var t;
		return function () {
			var args = arguments;
			clearTimeout( t );
			t = setTimeout( function () {
				fn.apply( null, args );
			}, ms );
		};
	}

	/* ---------- Orders ---------- */

	function OrdersApp( root ) {
		var form = root.querySelector( '.bmlt-lit-orders-filters' );
		var tbody = root.querySelector( '.bmlt-lit-orders-table tbody' );
		var status = root.querySelector( '.bmlt-lit-status-line' );
		var btnPrev = root.querySelector( '.bmlt-lit-load-previous' );
		var btnMore = root.querySelector( '.bmlt-lit-load-more' );
		var detail = root.querySelector( '.bmlt-lit-order-detail' );
		var state = { before: 0, page: 1, mode: 'period', loaded: 0, periodStart: 0, periods: [] };

		function filters() {
			var data = {};
			Array.prototype.forEach.call( form.elements, function ( f ) {
				if ( f.name ) {
					data[ f.name ] = f.value;
				}
			} );
			return data;
		}

		function renderRows( orders ) {
			orders.forEach( function ( o ) {
				var openBtn = el( 'button', {
					type: 'button',
					className: 'bmlt-lit-link-button',
					text: o.order_number,
					onclick: function () {
						openOrder( o.id );
					}
				} );
				var tr = el( 'tr', { className: o.has_special ? 'bmlt-lit-row--special' : '' }, [
					td( S.orderNumber, [ openBtn ] ),
					td( S.date, [ o.date ] ),
					td( S.area, [ o.area_name ] ),
					td( S.meeting, [ o.meeting_name ] ),
					td( S.contact, [ o.contact_name ] ),
					td( S.total, [ o.total ] ),
					td( S.special, [ o.has_special ? badge( 'special_order' ) : S.no ] ),
					td( S.status, [ statusPill( o.status, o.status_label ) ] )
				] );
				tbody.appendChild( tr );
			} );
		}

		function load( reset ) {
			if ( reset ) {
				tbody.textContent = '';
				state.before = 0;
				state.page = 1;
				state.loaded = 0;
				state.periods = [];
			}
			var params = filters();
			params.page = state.page;
			if ( state.before ) {
				params.before = state.before;
			}
			status.textContent = S.loading;
			btnPrev.hidden = true;
			btnMore.hidden = true;
			return api( 'orders', { params: params } ).then( function ( res ) {
				renderRows( res.orders || [] );
				state.loaded += ( res.orders || [] ).length;
				state.mode = res.mode;
				if ( res.mode === 'period' ) {
					state.periodStart = res.period_start;
					if ( state.page === 1 ) {
						state.periods.push( res.period_label );
					}
					btnMore.hidden = ! res.has_more;
					btnPrev.hidden = res.has_more || ! res.has_older;
					var range = state.periods.length ? state.periods[ state.periods.length - 1 ] : '';
					var first = state.periods.length ? state.periods[ 0 ] : '';
					var label = state.periods.length > 1 ? range.split( ' \u2013 ' )[ 0 ] + ' \u2013 ' + first.split( ' \u2013 ' )[ 1 ] : range;
					status.textContent = state.loaded ? ( S.period + ' ' + label + ' (' + state.loaded + ' ' + S.orders + ')' ) : ( S.noOrders + ( res.has_older ? '' : ' ' + S.noOlder ) );
				} else {
					btnMore.hidden = ! res.has_more;
					status.textContent = state.loaded ? ( state.loaded + ' / ' + res.total + ' ' + S.orders ) : S.noOrders;
				}
			} ).catch( function ( err ) {
				status.textContent = err.message;
			} );
		}

		btnPrev.addEventListener( 'click', function () {
			state.before = state.periodStart;
			state.page = 1;
			load( false );
		} );
		btnMore.addEventListener( 'click', function () {
			state.page += 1;
			load( false );
		} );
		form.addEventListener( 'submit', function ( e ) {
			e.preventDefault();
			load( true );
		} );
		form.addEventListener( 'reset', function () {
			setTimeout( function () {
				load( true );
			}, 0 );
		} );

		/* Order detail. */

		function openOrder( id ) {
			detail.hidden = false;
			detail.textContent = S.loading;
			api( 'orders/' + id ).then( renderDetail ).catch( function ( err ) {
				detail.textContent = err.message;
			} );
		}

		function renderDetail( o ) {
			detail.textContent = '';
			var msg = el( 'p', { className: 'bmlt-lit-status-line', role: 'status', 'aria-live': 'polite' } );

			var head = el( 'div', { className: 'bmlt-lit-order-detail__head' }, [
				el( 'h3', { text: S.orderNumber + ' ' + o.order_number } ),
				el( 'button', {
					type: 'button',
					className: 'bmlt-lit-button bmlt-lit-button--small',
					text: S.close,
					onclick: function () {
						detail.hidden = true;
						detail.textContent = '';
					}
				} )
			] );
			detail.appendChild( head );

			if ( o.has_special ) {
				detail.appendChild( el( 'div', { className: 'bmlt-lit-special-warning', role: 'note' }, [
					el( 'p', { className: 'bmlt-lit-special-warning__title', text: '! ' + S.specialBanner } ),
					el( 'p', { text: S.specialNote } )
				] ) );
			}

			var dl = el( 'dl', { className: 'bmlt-lit-dl' } );
			[
				[ S.status, statusPill( o.status, o.status_label ) ],
				[ S.date, o.date ],
				[ S.area, o.area_name ],
				[ S.meeting, o.meeting_name + ( o.meeting_when ? ' \u2014 ' + o.meeting_when : '' ) + ( o.meeting_location ? ' \u2014 ' + o.meeting_location : '' ) ],
				[ S.contact, o.contact_name ],
				[ S.phone, o.contact_phone ? el( 'a', { href: 'tel:' + o.contact_phone.replace( /[^0-9+]/g, '' ), text: o.contact_phone } ) : '' ],
				[ S.email, o.contact_email ? el( 'a', { href: 'mailto:' + o.contact_email, text: o.contact_email } ) : '' ],
				[ S.notes, o.notes || '\u2014' ],
				o.fulfilled ? [ S.fulfilledOn, o.fulfilled + ( o.fulfilled_by ? ' (' + o.fulfilled_by + ')' : '' ) ] : null
			].forEach( function ( pair ) {
				if ( ! pair ) {
					return;
				}
				dl.appendChild( el( 'div', null, [ el( 'dt', { text: pair[ 0 ] } ), el( 'dd', null, [ pair[ 1 ] ] ) ] ) );
			} );
			detail.appendChild( dl );

			var tbodyItems = el( 'tbody' );
			o.items.forEach( function ( it ) {
				var stockCell;
				if ( it.special > 0 && it.in_stock > 0 ) {
					stockCell = [ badge( 'partial' ), ' ' + it.in_stock + ' ' + S.fromStock + ' / ' + it.special + ' ' + S.special ];
				} else if ( it.special > 0 ) {
					stockCell = [ badge( 'special_order' ) ];
				} else {
					stockCell = [ badge( it.stock_status === 'low_stock' ? 'low_stock' : 'in_stock' ) ];
				}
				tbodyItems.appendChild( el( 'tr', { className: it.is_special ? 'bmlt-lit-row--special' : '' }, [
					td( S.sku, [ it.sku ] ),
					td( S.literature, [ it.name ] ),
					td( S.qty, [ String( it.quantity ) ] ),
					td( S.unitPrice, [ it.unit_price ] ),
					td( S.lineTotal, [ it.line_total ] ),
					td( S.stockStatus, stockCell )
				] ) );
			} );
			detail.appendChild( el( 'div', { className: 'bmlt-lit-table-wrap' }, [
				el( 'table', { className: 'bmlt-lit-table bmlt-lit-table--stack' }, [
					el( 'caption', { className: 'bmlt-lit-sr-only', text: S.items } ),
					el( 'thead', null, [ el( 'tr', null, [ S.sku, S.literature, S.qty, S.unitPrice, S.lineTotal, S.stockStatus ].map( function ( h ) {
						return el( 'th', { scope: 'col', text: h } );
					} ) ) ] ),
					tbodyItems
				] )
			] ) );
			detail.appendChild( el( 'dl', { className: 'bmlt-lit-totals' }, [
				el( 'div', null, [ el( 'dt', { text: S.items } ), el( 'dd', { text: String( o.item_count ) } ) ] ),
				el( 'div', null, [ el( 'dt', { text: S.subtotal } ), el( 'dd', { text: o.subtotal } ) ] ),
				el( 'div', { className: 'bmlt-lit-totals__total' }, [ el( 'dt', { text: S.total } ), el( 'dd', { text: o.total } ) ] )
			] ) );

			var actions = el( 'p', { className: 'bmlt-lit-actions' } );
			actions.appendChild( el( 'a', { className: 'bmlt-lit-button', href: o.print_url, target: '_blank', rel: 'noopener', text: S.print } ) );
			if ( o.admin_url ) {
				actions.appendChild( el( 'a', { className: 'bmlt-lit-button', href: o.admin_url, text: S.openAdmin } ) );
			}
			detail.appendChild( actions );

			if ( o.can_manage ) {
				if ( o.is_final ) {
					detail.appendChild( el( 'p', { className: 'bmlt-lit-help', text: S.finalNote } ) );
				} else {
					var noteInput = el( 'textarea', { className: 'bmlt-lit-textarea', rows: '2', id: 'bmlt-lit-snote-' + o.id, maxlength: '2000' } );
					var notify = el( 'input', { type: 'checkbox', id: 'bmlt-lit-notify-' + o.id } );
					var statusBox = el( 'div', { className: 'bmlt-lit-status-actions' }, [
						el( 'p', { className: 'bmlt-lit-field' }, [ el( 'label', { 'for': 'bmlt-lit-snote-' + o.id, text: S.statusNote } ), noteInput ] ),
						el( 'p', { className: 'bmlt-lit-field bmlt-lit-field--check' }, [ notify, el( 'label', { 'for': 'bmlt-lit-notify-' + o.id, text: S.notify } ) ] )
					] );
					var row = el( 'p', { className: 'bmlt-lit-actions' } );
					[
						[ 'reviewed', S.markReviewed, '' ],
						[ 'waiting_special', S.markWaiting, '' ],
						[ 'ready', S.markReady, '' ],
						[ 'partial', S.markPartial, '' ],
						[ 'fulfilled', S.markFulfilled, S.confirmFulfill ],
						[ 'cancelled', S.cancelOrder, S.confirmCancel ]
					].forEach( function ( a ) {
						if ( a[ 0 ] === o.status ) {
							return;
						}
						row.appendChild( el( 'button', {
							type: 'button',
							className: 'bmlt-lit-button bmlt-lit-button--small' + ( a[ 0 ] === 'cancelled' ? ' bmlt-lit-button--danger' : ( a[ 0 ] === 'fulfilled' ? ' bmlt-lit-button--primary' : '' ) ),
							text: a[ 1 ],
							onclick: function ( e ) {
								if ( a[ 2 ] && ! window.confirm( a[ 2 ] ) ) {
									return;
								}
								e.target.disabled = true;
								api( 'orders/' + o.id + '/status', { method: 'POST', body: { status: a[ 0 ], note: noteInput.value, notify: notify.checked } } ).then( function ( res ) {
									renderDetail( Object.assign( res.order, { print_url: o.print_url, admin_url: o.admin_url, can_manage: o.can_manage } ) );
									detail.querySelector( '.bmlt-lit-status-line' ).textContent = res.message;
									load( true );
								} ).catch( function ( err ) {
									e.target.disabled = false;
									msg.textContent = err.message;
								} );
							}
						} ) );
					} );
					statusBox.appendChild( row );
					detail.appendChild( statusBox );
				}

				var tools = el( 'p', { className: 'bmlt-lit-actions' } );
				tools.appendChild( el( 'button', {
					type: 'button',
					className: 'bmlt-lit-button bmlt-lit-button--small',
					text: S.resend,
					onclick: function ( e ) {
						e.target.disabled = true;
						api( 'orders/' + o.id + '/resend', { method: 'POST' } ).then( function ( res ) {
							msg.textContent = res.message;
							e.target.disabled = false;
						} ).catch( function ( err ) {
							msg.textContent = err.message;
							e.target.disabled = false;
						} );
					}
				} ) );
				detail.appendChild( tools );

				var emailText = el( 'textarea', { className: 'bmlt-lit-textarea', rows: '3', id: 'bmlt-lit-email-' + o.id, maxlength: '5000' } );
				var noteText = el( 'textarea', { className: 'bmlt-lit-textarea', rows: '2', id: 'bmlt-lit-note-' + o.id, maxlength: '5000' } );
				detail.appendChild( el( 'div', { className: 'bmlt-lit-grid' }, [
					el( 'div', null, [
						el( 'p', { className: 'bmlt-lit-field' }, [ el( 'label', { 'for': 'bmlt-lit-email-' + o.id, text: S.emailRequester } ), emailText ] ),
						el( 'button', {
							type: 'button',
							className: 'bmlt-lit-button bmlt-lit-button--small',
							text: S.send,
							onclick: function ( e ) {
								e.target.disabled = true;
								api( 'orders/' + o.id + '/email', { method: 'POST', body: { message: emailText.value } } ).then( function ( res ) {
									emailText.value = '';
									msg.textContent = res.message;
									e.target.disabled = false;
								} ).catch( function ( err ) {
									msg.textContent = err.message;
									e.target.disabled = false;
								} );
							}
						} )
					] ),
					el( 'div', null, [
						el( 'p', { className: 'bmlt-lit-field' }, [ el( 'label', { 'for': 'bmlt-lit-note-' + o.id, text: S.addNote } ), noteText ] ),
						el( 'button', {
							type: 'button',
							className: 'bmlt-lit-button bmlt-lit-button--small',
							text: S.addNote,
							onclick: function ( e ) {
								e.target.disabled = true;
								api( 'orders/' + o.id + '/notes', { method: 'POST', body: { note: noteText.value } } ).then( function ( res ) {
									renderDetail( Object.assign( res.order, { print_url: o.print_url, admin_url: o.admin_url, can_manage: o.can_manage } ) );
									detail.querySelector( '.bmlt-lit-status-line' ).textContent = res.message;
								} ).catch( function ( err ) {
									msg.textContent = err.message;
									e.target.disabled = false;
								} );
							}
						} )
					] )
				] ) );
			}

			detail.appendChild( msg );

			var hist = el( 'ul', { className: 'bmlt-lit-history' } );
			( o.history || [] ).forEach( function ( h ) {
				hist.appendChild( el( 'li', null, [ el( 'span', { className: 'bmlt-lit-history__meta', text: h.date + ' \u2014 ' + h.user } ), h.note ] ) );
			} );
			detail.appendChild( el( 'h4', { text: S.history } ) );
			detail.appendChild( hist );
			detail.focus();
			if ( detail.scrollIntoView ) {
				detail.scrollIntoView( { behavior: 'smooth', block: 'start' } );
			}
		}

		return { load: load };
	}

	/* ---------- Inventory ---------- */

	function InventoryApp( root ) {
		var areaSel = root.querySelector( '.bmlt-lit-inv-area' );
		var search = root.querySelector( '.bmlt-lit-inv-search' );
		var lowOnly = root.querySelector( '.bmlt-lit-inv-low' );
		var list = root.querySelector( '.bmlt-lit-inv-list' );
		var status = root.querySelector( '.bmlt-lit-status-line' );
		var rows = [];
		var tracked = true;
		var uid = 0;
		var pager = window.bmltLitPager.create( {
			list: list,
			perPage: C.perPage,
			storageKey: 'bmltLitPerPage.inventory',
			strings: C.pager,
			onChange: function () {
				render();
			}
		} );

		function stateClass( r ) {
			return { special_order: ' is-special', low_stock: ' is-low', inactive: ' is-inactive' }[ r.status ] || '';
		}

		// One "Label: field" pair. Read-only values use a span; editable ones a real <label for>.
		function field( labelText, control, extraClass, forId ) {
			var label = forId ?
				el( 'label', { className: 'bmlt-lit-inv-field__label', 'for': forId, text: labelText } ) :
				el( 'span', { className: 'bmlt-lit-inv-field__label', text: labelText } );
			return el( 'div', { className: 'bmlt-lit-inv-field' + ( extraClass ? ' ' + extraClass : '' ) }, [
				label,
				el( 'div', { className: 'bmlt-lit-inv-field__control' }, [ control ] )
			] );
		}

		function buildItem( r ) {
			var id = 'bmlt-lit-inv-' + ( ++uid );
			var badgeWrap = el( 'span', { className: 'bmlt-lit-inv-item__badge' }, [ badge( r.status ) ] );
			var qty = el( 'input', { type: 'number', className: 'bmlt-lit-input', min: '0', step: '1', inputmode: 'numeric', value: String( r.on_hand ), id: id + '-qty' } );
			var low = el( 'input', { type: 'number', className: 'bmlt-lit-input', min: '0', step: '1', inputmode: 'numeric', value: String( r.low_stock_threshold ), id: id + '-low' } );
			var reason = el( 'input', { type: 'text', className: 'bmlt-lit-input', maxlength: '200', id: id + '-reason' } );
			var availVal = el( 'strong', { className: 'bmlt-lit-inv-field__value', text: String( r.available ) } );
			var note = el( 'span', { className: 'bmlt-lit-inline-msg', role: 'status', 'aria-live': 'polite' } );
			var save = el( 'button', { type: 'submit', className: 'bmlt-lit-button bmlt-lit-button--small bmlt-lit-button--primary', text: S.save } );
			var step = function ( d ) {
				return el( 'button', {
					type: 'button',
					className: 'bmlt-lit-qty__btn',
					'aria-label': ( d > 0 ? S.increase : S.decrease ) + ' \u2013 ' + r.name,
					text: d > 0 ? '+' : '\u2212',
					onclick: function () {
						qty.value = String( Math.max( 0, ( parseInt( qty.value, 10 ) || 0 ) + d ) );
					}
				} );
			};

			var special = el( 'input', { type: 'checkbox', id: id + '-special' } );
			special.checked = !! r.special;
			var fieldsFor = tracked ? [
				field( S.price, el( 'span', { className: 'bmlt-lit-inv-field__value', text: r.price_formatted } ) ),
				field( S.onHand, el( 'span', { className: 'bmlt-lit-qty' }, [ step( -1 ), qty, step( 1 ) ] ), 'bmlt-lit-inv-field--qty', id + '-qty' ),
				field( S.reserved, el( 'span', { className: 'bmlt-lit-inv-field__value', text: String( r.reserved ) } ) ),
				field( S.available, availVal ),
				field( S.lowThreshold, low, '', id + '-low' ),
				field( S.reason, reason, 'bmlt-lit-inv-field--wide', id + '-reason' ),
				el( 'div', { className: 'bmlt-lit-inv-field bmlt-lit-inv-field--actions' }, [ save, note ] )
			] : [
				field( S.price, el( 'span', { className: 'bmlt-lit-inv-field__value', text: r.price_formatted } ) ),
				el( 'div', { className: 'bmlt-lit-inv-field bmlt-lit-inv-field--check' }, [
					special,
					el( 'label', { 'for': id + '-special', className: 'bmlt-lit-inv-field__label bmlt-lit-inv-field__label--check', text: S.specialForArea } )
				] ),
				field( S.reason, reason, 'bmlt-lit-inv-field--wide', id + '-reason' ),
				el( 'div', { className: 'bmlt-lit-inv-field bmlt-lit-inv-field--actions' }, [ save, note ] )
			];

			var item = el( 'form', { className: 'bmlt-lit-inv-item' + stateClass( r ), role: 'listitem', 'aria-labelledby': id + '-title', novalidate: true }, [
				el( 'div', { className: 'bmlt-lit-inv-item__head' }, [
					el( 'h4', { className: 'bmlt-lit-inv-item__title', id: id + '-title', text: r.name } ),
					el( 'span', { className: 'bmlt-lit-inv-item__sku', text: S.sku + ' ' + r.sku } ),
					badgeWrap
				] ),
				el( 'div', { className: 'bmlt-lit-inv-grid' }, fieldsFor )
			] );

			item.addEventListener( 'submit', function ( e ) {
				e.preventDefault();
				save.disabled = true;
				note.className = 'bmlt-lit-inline-msg';
				note.textContent = S.loading;
				api( 'inventory', {
					method: 'POST',
					body: tracked ?
						{ area: areaSel.value, item_id: r.item_id, set: qty.value, low_stock_threshold: low.value, reason: reason.value } :
						{ area: areaSel.value, item_id: r.item_id, special: special.checked, reason: reason.value }
				} ).then( function ( res ) {
					r.on_hand = res.row.on_hand;
					r.reserved = res.row.reserved;
					r.available = res.row.available;
					r.low_stock_threshold = res.row.low_stock_threshold;
					r.special = res.row.special;
					if ( res.row.status ) {
						r.status = res.row.status;
					}
					availVal.textContent = String( r.available );
					badgeWrap.textContent = '';
					badgeWrap.appendChild( badge( r.status ) );
					item.className = 'bmlt-lit-inv-item' + stateClass( r );
					reason.value = '';
					note.className = 'bmlt-lit-inline-msg is-ok';
					note.textContent = res.message;
				} ).catch( function ( err ) {
					note.className = 'bmlt-lit-inline-msg is-error';
					note.textContent = err.message;
				} ).then( function () {
					save.disabled = false;
				} );
			} );
			return item;
		}

		function render() {
			list.textContent = '';
			var matching = rows.filter( function ( r ) {
				return ! lowOnly.checked || r.status === 'low_stock' || r.status === 'special_order';
			} );
			pager.slice( matching ).forEach( function ( r ) {
				list.appendChild( buildItem( r ) );
			} );
			list.hidden = ! matching.length;
			status.textContent = matching.length ? '' : S.noItems;
		}

		function load() {
			if ( ! areaSel.value ) {
				status.textContent = S.chooseArea;
				return;
			}
			status.textContent = S.loading;
			api( 'inventory', { params: { area: areaSel.value, search: search.value } } ).then( function ( res ) {
				rows = res.rows || [];
				tracked = res.tracked !== false;
				lowOnly.closest( '.bmlt-lit-field' ).hidden = ! tracked;
				if ( ! tracked ) {
					lowOnly.checked = false;
				}
				pager.reset();
				render();
			} ).catch( function ( err ) {
				status.textContent = err.message;
			} );
		}

		areaSel.addEventListener( 'change', load );
		search.addEventListener( 'input', debounce( load, 350 ) );
		lowOnly.addEventListener( 'change', function () {
			pager.reset();
			render();
		} );
		return { load: load };
	}

	/* ---------- Catalog ---------- */

	function CatalogApp( root ) {
		var catalogSel = root.querySelector( '.bmlt-lit-cat-catalog' );
		var search = root.querySelector( '.bmlt-lit-cat-search' );
		var tbody = root.querySelector( '.bmlt-lit-cat-table tbody' );
		var status = root.querySelector( '.bmlt-lit-status-line' );
		var form = root.querySelector( '.bmlt-lit-item-form' );
		var formTitle = form.querySelector( '.bmlt-lit-item-form__title' );
		var formMsg = form.querySelector( '.bmlt-lit-form-message' );
		var addBtn = root.querySelector( '.bmlt-lit-cat-add' );
		var all = [];
		var pager = window.bmltLitPager.create( {
			list: root.querySelector( '.bmlt-lit-cat-table' ).parentNode,
			perPage: C.perPage,
			storageKey: 'bmltLitPerPage.catalog',
			strings: C.pager,
			onChange: function () {
				render();
			}
		} );
		var fields = [ 'id', 'area_id', 'sku', 'name', 'category', 'price', 'sort_order', 'naws_id', 'image_url', 'reference_url', 'short_description', 'long_description' ];

		function openForm( item ) {
			form.hidden = false;
			formMsg.textContent = '';
			formTitle.textContent = item ? S.editItem : S.addItem;
			fields.forEach( function ( f ) {
				var input = form.elements[ f ];
				if ( ! input ) {
					return;
				}
				if ( item ) {
					input.value = item[ f ] !== undefined ? String( item[ f ] ) : '';
				} else {
					input.value = f === 'sort_order' ? '0' : ( f === 'area_id' ? input.options[ 0 ].value : '' );
				}
			} );
			form.elements.is_active.checked = item ? !! item.is_active : true;
			form.elements.area_id.disabled = false;
			form.elements.sku.focus();
		}

		function closeForm() {
			form.hidden = true;
		}

		function render() {
			tbody.textContent = '';
			pager.slice( all ).forEach( function ( it ) {
				var actions = el( 'span', { className: 'bmlt-lit-actions' } );
				if ( it.can_edit ) {
					actions.appendChild( el( 'button', {
						type: 'button',
						className: 'bmlt-lit-button bmlt-lit-button--small',
						text: S.edit,
						onclick: function () {
							openForm( it );
						}
					} ) );
					actions.appendChild( el( 'button', {
						type: 'button',
						className: 'bmlt-lit-button bmlt-lit-button--small',
						text: it.is_active ? S.deactivate : S.activate,
						onclick: function () {
							api( 'items/' + it.id + '/active', { method: 'POST', body: { active: ! it.is_active } } ).then( function ( res ) {
								status.textContent = res.message;
								load();
							} ).catch( function ( err ) {
								status.textContent = err.message;
							} );
						}
					} ) );
					if ( it.can_delete ) {
						actions.appendChild( el( 'button', {
							type: 'button',
							className: 'bmlt-lit-button bmlt-lit-button--small bmlt-lit-button--danger',
							text: S.delete,
							onclick: function () {
								if ( ! window.confirm( S.confirmDelete ) ) {
									return;
								}
								api( 'items/' + it.id, { method: 'DELETE' } ).then( function ( res ) {
									status.textContent = res.message;
									load();
								} ).catch( function ( err ) {
									status.textContent = err.message;
								} );
							}
						} ) );
					}
				}
				tbody.appendChild( el( 'tr', { role: 'row', className: it.is_active ? '' : 'bmlt-lit-row--inactive' }, [
					fcell( S.sku, [ it.sku ], 'narrow' ),
					fcell( S.literature, [ el( 'strong', { text: it.name } ) ], 'item' ),
					fcell( S.category, [ it.category ] ),
					fcell( S.catalog, [ it.catalog ] ),
					fcell( S.price, [ it.price_formatted ], 'narrow' ),
					fcell( S.sort, [ String( it.sort_order ) ], 'narrow' ),
					fcell( S.status, [ it.is_active ? S.active : badge( 'inactive' ) ] ),
					fcell( S.actions, [ actions ], 'actions' )
				] ) );
			} );
			status.textContent = all.length ? '' : S.noItems;
		}

		function load() {
			status.textContent = S.loading;
			api( 'items', { params: { catalog: catalogSel.value, search: search.value } } ).then( function ( res ) {
				all = res.items || [];
				pager.reset();
				render();
			} ).catch( function ( err ) {
				status.textContent = err.message;
			} );
		}

		form.addEventListener( 'submit', function ( e ) {
			e.preventDefault();
			var body = {};
			fields.forEach( function ( f ) {
				if ( form.elements[ f ] && f !== 'id' ) {
					body[ f ] = form.elements[ f ].value;
				}
			} );
			body.is_active = form.elements.is_active.checked ? 1 : 0;
			var id = form.elements.id.value;
			formMsg.textContent = S.loading;
			api( id ? 'items/' + id : 'items', { method: 'POST', body: body } ).then( function ( res ) {
				formMsg.textContent = res.message;
				status.textContent = res.message;
				closeForm();
				load();
			} ).catch( function ( err ) {
				formMsg.textContent = err.message;
			} );
		} );
		form.querySelector( '.bmlt-lit-item-cancel' ).addEventListener( 'click', closeForm );
		addBtn.addEventListener( 'click', function () {
			openForm( null );
		} );
		catalogSel.addEventListener( 'change', load );
		search.addEventListener( 'input', debounce( load, 350 ) );
		return { load: load };
	}

	/* ---------- Boot ---------- */

	function boot() {
		var loaders = {};
		var ordersRoot = document.querySelector( '[data-bmlt-lit-orders]' );
		if ( ordersRoot && C.caps.viewOrders ) {
			loaders.orders = OrdersApp( ordersRoot );
		}
		var invRoot = document.querySelector( '[data-bmlt-lit-inventory]' );
		if ( invRoot && C.caps.inventory ) {
			loaders.inventory = InventoryApp( invRoot );
		}
		var catRoot = document.querySelector( '[data-bmlt-lit-catalog]' );
		if ( catRoot && C.caps.catalog ) {
			loaders.catalog = CatalogApp( catRoot );
		}
		var loaded = {};

		function activate( name ) {
			if ( loaders[ name ] && ! loaded[ name ] ) {
				loaded[ name ] = true;
				loaders[ name ].load( true );
			}
		}

		var worker = document.querySelector( '[data-bmlt-lit-worker]' );
		if ( worker ) {
			var tabs = Array.prototype.slice.call( worker.querySelectorAll( '.bmlt-lit-tab' ) );
			var select = function ( tab, focus ) {
				tabs.forEach( function ( t ) {
					var on = t === tab;
					t.setAttribute( 'aria-selected', on ? 'true' : 'false' );
					t.tabIndex = on ? 0 : -1;
					var panel = worker.querySelector( '[data-panel="' + t.getAttribute( 'data-tab' ) + '"]' );
					if ( panel ) {
						panel.hidden = ! on;
					}
				} );
				if ( focus ) {
					tab.focus();
				}
				activate( tab.getAttribute( 'data-tab' ) );
			};
			tabs.forEach( function ( t, i ) {
				t.addEventListener( 'click', function () {
					select( t, false );
				} );
				t.addEventListener( 'keydown', function ( e ) {
					var idx = -1;
					if ( e.key === 'ArrowRight' ) {
						idx = ( i + 1 ) % tabs.length;
					} else if ( e.key === 'ArrowLeft' ) {
						idx = ( i - 1 + tabs.length ) % tabs.length;
					} else if ( e.key === 'Home' ) {
						idx = 0;
					} else if ( e.key === 'End' ) {
						idx = tabs.length - 1;
					}
					if ( idx >= 0 ) {
						e.preventDefault();
						select( tabs[ idx ], true );
					}
				} );
			} );
			var current = tabs.filter( function ( t ) {
				return t.getAttribute( 'aria-selected' ) === 'true';
			} )[ 0 ];
			if ( current ) {
				activate( current.getAttribute( 'data-tab' ) );
			}
		} else if ( loaders.orders ) {
			activate( 'orders' );
		}
	}

	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', boot );
	} else {
		boot();
	}
} )();
