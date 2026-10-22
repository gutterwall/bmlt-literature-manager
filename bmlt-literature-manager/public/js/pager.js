/**
 * BMLT Literature Manager — shared pager for long item lists.
 *
 * Adds an "Items per page" selector (20 / 50 / 100 / All), a "Showing X–Y of Z" status and
 * Previous / page / Next controls above and below a list. Purely presentational: callers decide
 * which items exist; the pager only says which slice is visible. Without JavaScript nothing is
 * paginated, so every item stays reachable.
 *
 * Usage:
 *   var pager = window.bmltLitPager.create( { list: element, perPage: 20, storageKey: 'key',
 *       strings: {...}, onChange: function () { render(); } } );
 *   var visible = pager.slice( allMatchingItems );   // call from render()
 * @version 6.2.0
 */
( function () {
	'use strict';

	var CHOICES = [ 20, 50, 100, 0 ]; // 0 = All.
	var seq = 0;

	function fmt( str ) {
		var args = arguments;
		var i = 0;
		return String( str || '' ).replace( /%(\d+\$)?s/g, function ( m, pos ) {
			var idx = pos ? parseInt( pos, 10 ) : ++i;
			return args[ idx ] !== undefined ? String( args[ idx ] ) : '';
		} );
	}

	function normalize( n ) {
		n = parseInt( n, 10 );
		return CHOICES.indexOf( n ) === -1 ? 20 : n;
	}

	function make( tag, cls, text ) {
		var e = document.createElement( tag );
		if ( cls ) {
			e.className = cls;
		}
		if ( text ) {
			e.textContent = text;
		}
		return e;
	}

	function create( opts ) {
		var S = opts.strings || {};
		var id = 'bmlt-lit-pager-' + ( ++seq );
		var perPage = normalize( opts.perPage );
		var page = 1;
		var total = 0;
		var pagesBuilt = 0;

		if ( opts.storageKey ) {
			try {
				var saved = window.localStorage.getItem( opts.storageKey );
				if ( saved !== null ) {
					perPage = normalize( saved );
				}
			} catch ( e ) {
				// Storage unavailable (private mode, blocked): use the default.
			}
		}

		function pageCount() {
			return perPage ? Math.max( 1, Math.ceil( total / perPage ) ) : 1;
		}

		function go( p, fromBottom ) {
			page = p;
			opts.onChange();
			if ( fromBottom ) {
				// Return to the top of the list so the new page is read from its start.
				top.scrollIntoView( { block: 'start' } );
				status.setAttribute( 'tabindex', '-1' );
				status.focus( { preventScroll: true } );
			}
		}

		function buildNav( label, fromBottom ) {
			var nav = make( 'nav', 'bmlt-lit-pager__nav' );
			nav.setAttribute( 'aria-label', label );
			var prev = make( 'button', 'bmlt-lit-button bmlt-lit-button--small bmlt-lit-pager__prev', S.prev );
			prev.type = 'button';
			var next = make( 'button', 'bmlt-lit-button bmlt-lit-button--small bmlt-lit-pager__next', S.next );
			next.type = 'button';
			var selId = id + ( fromBottom ? '-page-b' : '-page-t' );
			var selLabel = make( 'label', 'bmlt-lit-sr-only', S.page );
			selLabel.htmlFor = selId;
			var select = make( 'select', 'bmlt-lit-select bmlt-lit-pager__select' );
			select.id = selId;
			var of = make( 'span', 'bmlt-lit-pager__of' );
			prev.addEventListener( 'click', function () {
				go( Math.max( 1, page - 1 ), fromBottom );
			} );
			next.addEventListener( 'click', function () {
				go( Math.min( pageCount(), page + 1 ), fromBottom );
			} );
			select.addEventListener( 'change', function () {
				go( parseInt( select.value, 10 ) || 1, fromBottom );
			} );
			nav.appendChild( prev );
			nav.appendChild( selLabel );
			nav.appendChild( select );
			nav.appendChild( of );
			nav.appendChild( next );
			return { el: nav, prev: prev, next: next, select: select, of: of };
		}

		// Top bar: page size, status, optional note, navigation.
		var top = make( 'div', 'bmlt-lit-pager bmlt-lit-pager--top' );
		var sizeWrap = make( 'p', 'bmlt-lit-pager__size' );
		var sizeLabel = make( 'label', '', S.perPage );
		sizeLabel.htmlFor = id + '-size';
		var size = make( 'select', 'bmlt-lit-select bmlt-lit-pager__select' );
		size.id = id + '-size';
		CHOICES.forEach( function ( c ) {
			var o = make( 'option', '', c ? String( c ) : S.all );
			o.value = String( c );
			o.selected = c === perPage;
			size.appendChild( o );
		} );
		sizeWrap.appendChild( sizeLabel );
		sizeWrap.appendChild( size );
		var status = make( 'p', 'bmlt-lit-pager__status' );
		status.setAttribute( 'aria-live', 'polite' );
		var note = make( 'p', 'bmlt-lit-pager__note' );
		note.setAttribute( 'role', 'status' );
		note.hidden = true;
		var topNav = buildNav( S.pages, false );
		top.appendChild( sizeWrap );
		top.appendChild( status );
		top.appendChild( topNav.el );
		top.appendChild( note );

		// Bottom bar: navigation only.
		var bottom = make( 'div', 'bmlt-lit-pager bmlt-lit-pager--bottom' );
		var bottomNav = buildNav( S.pagesEnd, true );
		bottom.appendChild( bottomNav.el );

		opts.list.parentNode.insertBefore( top, opts.list );
		opts.list.parentNode.insertBefore( bottom, opts.list.nextSibling );

		size.addEventListener( 'change', function () {
			perPage = normalize( size.value );
			page = 1;
			if ( opts.storageKey ) {
				try {
					window.localStorage.setItem( opts.storageKey, String( perPage ) );
				} catch ( e ) {
					// Ignore storage errors.
				}
			}
			opts.onChange();
		} );

		function update() {
			var pages = pageCount();
			if ( page > pages ) {
				page = pages;
			}
			if ( page < 1 ) {
				page = 1;
			}
			[ topNav, bottomNav ].forEach( function ( n ) {
				if ( pagesBuilt !== pages ) {
					n.select.textContent = '';
					for ( var p = 1; p <= pages; p++ ) {
						var o = make( 'option', '', String( p ) );
						o.value = String( p );
						n.select.appendChild( o );
					}
				}
				n.select.value = String( page );
				n.of.textContent = fmt( S.ofPages, pages );
				n.prev.disabled = page <= 1;
				n.next.disabled = page >= pages;
				n.el.hidden = pages <= 1;
			} );
			pagesBuilt = pages;
			bottom.hidden = pages <= 1;
			top.hidden = total === 0;
			var start = total ? ( perPage ? ( page - 1 ) * perPage + 1 : 1 ) : 0;
			var end = perPage ? Math.min( total, page * perPage ) : total;
			status.textContent = total ? fmt( S.showing, start, end, total ) : '';
		}

		return {
			slice: function ( arr ) {
				total = arr.length;
				update();
				return perPage ? arr.slice( ( page - 1 ) * perPage, page * perPage ) : arr.slice();
			},
			reset: function () {
				page = 1;
			},
			setNote: function ( text ) {
				note.textContent = text || '';
				note.hidden = ! text;
			}
		};
	}

	window.bmltLitPager = { create: create, choices: CHOICES };
} )();
