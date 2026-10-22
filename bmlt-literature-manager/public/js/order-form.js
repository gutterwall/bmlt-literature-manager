/**
 * BMLT Literature Manager — public order form.
 *
 * Tabs for each group (category) of literature, search across all groups, special-order items sorted
 * to the bottom for the selected Area, special-order flags, pagination and a running total.
 * Prices, availability and totals are always recalculated on the server (Review step).
 * @version 6.3.0
 */
( function () {
	'use strict';

	function formatMoney( cents, cur ) {
		var decimals = Math.max( 0, Math.min( 4, parseInt( cur.decimals, 10 ) || 0 ) );
		var parts = ( cents / 100 ).toFixed( decimals ).split( '.' );
		parts[ 0 ] = parts[ 0 ].replace( /\B(?=(\d{3})+(?!\d))/g, cur.thou );
		var num = parts.length > 1 ? parts[ 0 ] + cur.dec + parts[ 1 ] : parts[ 0 ];
		return cur.position === 'after' ? num + cur.symbol : cur.symbol + num;
	}

	function sprintf( str ) {
		var args = Array.prototype.slice.call( arguments, 1 );
		var i = 0;
		return String( str ).replace( /%(\d+\$)?s/g, function ( m, pos ) {
			var idx = pos ? parseInt( pos, 10 ) - 1 : i++;
			return args[ idx ] !== undefined ? String( args[ idx ] ) : '';
		} );
	}

	function specialBadge( label, extra ) {
		var span = document.createElement( 'span' );
		span.className = 'bmlt-lit-stock bmlt-lit-stock--special_order';
		var icon = document.createElement( 'span' );
		icon.className = 'bmlt-lit-stock__icon';
		icon.setAttribute( 'aria-hidden', 'true' );
		icon.textContent = '!';
		var text = document.createElement( 'span' );
		text.className = 'bmlt-lit-stock__label';
		text.textContent = label;
		span.appendChild( icon );
		span.appendChild( document.createTextNode( ' ' ) );
		span.appendChild( text );
		if ( extra ) {
			var ex = document.createElement( 'span' );
			ex.className = 'bmlt-lit-stock__extra';
			ex.textContent = ' ' + extra;
			span.appendChild( ex );
		}
		return span;
	}

	function init( form ) {
		var dataEl = form.querySelector( '.bmlt-lit-data' );
		if ( ! dataEl ) {
			return;
		}
		var data;
		try {
			data = JSON.parse( dataEl.textContent );
		} catch ( e ) {
			return;
		}
		var S = data.strings;
		var root = form.closest( '.bmlt-lit-order-form' ) || form;
		var select = form.querySelector( '.bmlt-lit-meeting-select' );
		var meetingSearch = form.querySelector( '.bmlt-lit-meeting-search' );
		var areaInfo = form.querySelector( '.bmlt-lit-area-info' );
		var listEl = form.querySelector( '.bmlt-lit-items' );
		var items = Array.prototype.slice.call( form.querySelectorAll( '.bmlt-lit-item' ) );
		var itemSearch = form.querySelector( '.bmlt-lit-item-search' );
		var noResults = form.querySelector( '.bmlt-lit-no-results' );
		var warning = form.querySelector( '.bmlt-lit-special-warning' );
		var sumCount = form.querySelector( '.bmlt-lit-sum-count' );
		var sumTotal = form.querySelector( '.bmlt-lit-sum-total' );
		var deliveryExtra = form.querySelector( '.bmlt-lit-delivery__extra' );
		var hint = form.querySelector( '.bmlt-lit-choose-meeting-hint' );
		var submit = form.querySelector( '.bmlt-lit-submit' );
		var areaIds = Object.keys( data.areas );
		var singleArea = areaIds.length === 1 ? parseInt( areaIds[ 0 ], 10 ) : 0;
		var ALL = '\u0000all';
		var activeCat = null;
		var sortKey = null;
		var sortDir = 1;

		items.forEach( function ( item, i ) {
			item.setAttribute( 'data-order', String( i ) );
		} );

		// Reveal JS-only controls.
		Array.prototype.forEach.call( form.querySelectorAll( '.bmlt-lit-filters, .bmlt-lit-qty__btn' ), function ( el ) {
			el.hidden = false;
		} );
		if ( meetingSearch && data.meetings.length > 15 ) {
			meetingSearch.closest( '.bmlt-lit-meeting-search-wrap' ).hidden = false;
		}

		/* ---------- Category tabs ---------- */

		var categories = [];
		items.forEach( function ( item ) {
			var c = item.getAttribute( 'data-category' ) || '';
			if ( categories.indexOf( c ) === -1 ) {
				categories.push( c );
			}
		} );
		var tabBar = null;
		var tabs = [];
		if ( listEl && categories.length > 1 ) {
			categories.unshift( ALL );
			tabBar = document.createElement( 'div' );
			tabBar.className = 'bmlt-lit-cat-tabs';
			tabBar.setAttribute( 'role', 'tablist' );
			tabBar.setAttribute( 'aria-label', S.groups );
			categories.forEach( function ( c, i ) {
				var b = document.createElement( 'button' );
				b.type = 'button';
				b.className = 'bmlt-lit-cat-tab';
				b.id = data.formId + '-tab-' + i;
				b.setAttribute( 'role', 'tab' );
				b.setAttribute( 'aria-controls', listEl.id );
				b.setAttribute( 'data-cat', c );
				b.textContent = c === ALL ? S.allGroups : ( c || S.other );
				if ( c === ALL ) {
					b.className += ' bmlt-lit-cat-tab--all';
				}
				b.addEventListener( 'click', function () {
					selectTab( c, false );
				} );
				b.addEventListener( 'keydown', function ( e ) {
					var visible = tabs.filter( function ( t ) {
						return ! t.hidden;
					} );
					var idx = visible.indexOf( b );
					var next = -1;
					if ( e.key === 'ArrowRight' ) {
						next = ( idx + 1 ) % visible.length;
					} else if ( e.key === 'ArrowLeft' ) {
						next = ( idx - 1 + visible.length ) % visible.length;
					} else if ( e.key === 'Home' ) {
						next = 0;
					} else if ( e.key === 'End' ) {
						next = visible.length - 1;
					}
					if ( next >= 0 ) {
						e.preventDefault();
						selectTab( visible[ next ].getAttribute( 'data-cat' ), true );
					}
				} );
				tabs.push( b );
				tabBar.appendChild( b );
			} );
			var head = form.querySelector( '.bmlt-lit-items__head' ) || listEl;
			head.parentNode.insertBefore( tabBar, head );
			listEl.setAttribute( 'role', 'tabpanel' );
		}

		function selectTab( c, focus ) {
			activeCat = c;
			if ( itemSearch && itemSearch.value ) {
				itemSearch.value = '';
			}
			tabs.forEach( function ( t ) {
				var on = t.getAttribute( 'data-cat' ) === c;
				t.setAttribute( 'aria-selected', on ? 'true' : 'false' );
				t.tabIndex = on ? 0 : -1;
				if ( on ) {
					listEl.setAttribute( 'aria-labelledby', t.id );
					if ( focus ) {
						t.focus();
					}
				}
			} );
			if ( pager ) {
				pager.reset();
			}
			filterItems();
		}

		var pager = ( listEl && window.bmltLitPager ) ? window.bmltLitPager.create( {
			list: listEl,
			perPage: data.perPage,
			storageKey: 'bmltLitPerPage.order',
			strings: data.pager,
			onChange: function () {
				filterItems();
			}
		} ) : null;

		// Keep the column headings directly above the items (below the pager controls).
		var headEl = form.querySelector( '.bmlt-lit-items__head' );
		if ( headEl && listEl ) {
			listEl.parentNode.insertBefore( headEl, listEl );
		}

		/* ---------- Area / stock ---------- */

		function currentArea() {
			if ( select && select.value ) {
				var opt = select.options[ select.selectedIndex ];
				if ( opt && opt.getAttribute( 'data-area' ) ) {
					return parseInt( opt.getAttribute( 'data-area' ), 10 );
				}
			}
			return singleArea;
		}

		function stockFor( item, area ) {
			var id = item.getAttribute( 'data-item-id' );
			var st = ( area && data.stock[ area ] && data.stock[ area ][ id ] ) ? data.stock[ area ][ id ] : null;
			return { status: st ? st[ 0 ] : 'special_order', available: st ? parseInt( st[ 1 ], 10 ) || 0 : 0 };
		}

		function lineInfo( item, area, qty ) {
			var st = stockFor( item, area );
			var fromStock = Math.min( qty, st.available );
			var special = qty - fromStock;
			if ( special > 0 && data.excess === 'whole_line' ) {
				special = qty;
			}
			return { status: st.status, special: special };
		}

		function inArea( item, area ) {
			var itemArea = parseInt( item.getAttribute( 'data-area-id' ), 10 ) || 0;
			return ! area || itemArea === 0 || itemArea === area;
		}

		function rebuildMeetings( term ) {
			var selected = select.value;
			term = ( term || '' ).toLowerCase().trim();
			while ( select.options.length > 0 ) {
				select.remove( 0 );
			}
			Array.prototype.slice.call( select.querySelectorAll( 'optgroup' ) ).forEach( function ( g ) {
				select.removeChild( g );
			} );
			var first = document.createElement( 'option' );
			first.value = '';
			first.textContent = S.chooseMeeting;
			select.appendChild( first );
			var groups = {};
			var count = 0;
			data.meetings.forEach( function ( m ) {
				var areaName = data.areaNames[ m.area ] || '';
				if ( term && ( m.label + ' ' + areaName ).toLowerCase().indexOf( term ) === -1 && String( m.id ) !== selected ) {
					return;
				}
				if ( ! groups[ m.area ] ) {
					groups[ m.area ] = document.createElement( 'optgroup' );
					groups[ m.area ].label = areaName;
					select.appendChild( groups[ m.area ] );
				}
				var o = document.createElement( 'option' );
				o.value = String( m.id );
				o.setAttribute( 'data-area', String( m.area ) );
				o.textContent = m.label;
				o.selected = String( m.id ) === selected;
				groups[ m.area ].appendChild( o );
				count++;
			} );
			if ( count === 0 ) {
				first.textContent = S.noMeetings;
			}
		}

		function applyArea() {
			var area = currentArea();
			if ( hint ) {
				hint.hidden = !! area;
			}
			if ( areaInfo ) {
				areaInfo.textContent = '';
				if ( area && data.areas[ area ] ) {
					areaInfo.appendChild( document.createTextNode( sprintf( S.areaLabel, data.areas[ area ].name ) ) );
					if ( data.areas[ area ].extra ) {
						var small = document.createElement( 'small' );
						small.textContent = data.areas[ area ].extra;
						areaInfo.appendChild( small );
					}
				}
			}
			if ( deliveryExtra ) {
				deliveryExtra.textContent = area && data.areas[ area ] ? data.areas[ area ].extra : '';
			}
			items.forEach( function ( item ) {
				var ok = inArea( item, area );
				var input = item.querySelector( '.bmlt-lit-qty__input' );
				item.setAttribute( 'data-out-of-area', ok ? '0' : '1' );
				if ( ! ok ) {
					input.value = '0'; // Other Areas' catalog items cannot be ordered for this meeting.
				}
				input.disabled = ! ok;
			} );
			// Hide tabs that have nothing for this Area; keep the active tab valid.
			var firstVisible = null;
			tabs.forEach( function ( t ) {
				var c = t.getAttribute( 'data-cat' );
				if ( c === ALL ) {
					t.hidden = false;
					if ( firstVisible === null ) {
						firstVisible = ALL;
					}
					return;
				}
				var has = items.some( function ( item ) {
					return item.getAttribute( 'data-category' ) === c && item.getAttribute( 'data-out-of-area' ) !== '1';
				} );
				t.hidden = ! has;
				if ( has && firstVisible === null ) {
					firstVisible = c;
				}
			} );
			if ( tabs.length && ( activeCat === null || tabs.some( function ( t ) {
				return t.hidden && t.getAttribute( 'data-cat' ) === activeCat;
			} ) ) ) {
				activeCat = firstVisible;
			}
			if ( tabs.length ) {
				tabs.forEach( function ( t ) {
					var on = t.getAttribute( 'data-cat' ) === activeCat;
					t.setAttribute( 'aria-selected', on ? 'true' : 'false' );
					t.tabIndex = on ? 0 : -1;
					if ( on ) {
						listEl.setAttribute( 'aria-labelledby', t.id );
					}
				} );
			}
			if ( pager ) {
				pager.reset();
			}
			filterItems();
			recalc();
		}

		/* ---------- Filtering, sorting, paging ---------- */

		function filterItems() {
			var area = currentArea();
			var term = itemSearch ? itemSearch.value.toLowerCase().trim() : '';
			var searching = term !== '';
			if ( tabBar ) {
				tabBar.classList.toggle( 'is-searching', searching );
			}
			var matching = items.filter( function ( item ) {
				if ( item.getAttribute( 'data-out-of-area' ) === '1' ) {
					return false;
				}
				if ( searching ) {
					return item.getAttribute( 'data-search' ).indexOf( term ) !== -1; // Search spans all groups.
				}
				return activeCat === null || activeCat === ALL || item.getAttribute( 'data-category' ) === activeCat;
			} );
			// Default: catalog order with special orders (for the selected Area) at the bottom.
			// A clicked column heading sorts by that column instead (ties keep catalog order).
			var orderOf = function ( el ) {
				return parseInt( el.getAttribute( 'data-order' ), 10 );
			};
			var specialOf = function ( el ) {
				return area && stockFor( el, area ).status === 'special_order' ? 1 : 0;
			};
			matching.sort( function ( a, b ) {
				var c = 0;
				if ( sortKey === 'name' ) {
					c = nameOf( a ).localeCompare( nameOf( b ), undefined, { numeric: true, sensitivity: 'base' } );
				} else if ( sortKey === 'price' ) {
					c = ( parseInt( a.getAttribute( 'data-price-cents' ), 10 ) || 0 ) - ( parseInt( b.getAttribute( 'data-price-cents' ), 10 ) || 0 );
				} else if ( sortKey === 'special' ) {
					c = specialOf( a ) - specialOf( b );
				} else if ( sortKey === 'qty' ) {
					c = qtyOf( a ) - qtyOf( b );
				} else {
					return ( specialOf( a ) - specialOf( b ) ) || ( orderOf( a ) - orderOf( b ) );
				}
				return ( c * sortDir ) || ( orderOf( a ) - orderOf( b ) );
			} );
			var onPage = pager ? pager.slice( matching ) : matching;
			// Re-order the DOM so the visible order matches (hidden items keep their quantities and are still submitted).
			var rest = items.filter( function ( item ) {
				return matching.indexOf( item ) === -1;
			} );
			matching.concat( rest ).forEach( function ( item ) {
				listEl.appendChild( item );
				item.hidden = onPage.indexOf( item ) === -1;
			} );
			if ( noResults ) {
				noResults.hidden = matching.length > 0;
			}
			updateOffPage();
		}

		function updateOffPage() {
			if ( ! pager ) {
				return;
			}
			var n = 0;
			items.forEach( function ( item ) {
				var input = item.querySelector( '.bmlt-lit-qty__input' );
				if ( item.hidden && ! input.disabled && ( parseInt( input.value, 10 ) || 0 ) > 0 ) {
					n++;
				}
			} );
			pager.setNote( n ? sprintf( data.pager.offPage, n ) : '' );
		}

		function nameOf( el ) {
			var n = el.querySelector( '.bmlt-lit-item__name' );
			return n ? n.textContent : '';
		}

		function qtyOf( el ) {
			var input = el.querySelector( '.bmlt-lit-qty__input' );
			return input && ! input.disabled ? ( parseInt( input.value, 10 ) || 0 ) : 0;
		}

		/* ---------- Sortable column headings ---------- */

		var sortButtons = [];
		var sortLive = null;
		// First click direction: names/prices low→high; quantities and special orders largest/first.
		var firstDir = { name: 1, price: 1, qty: -1, special: -1 };
		if ( headEl ) {
			headEl.removeAttribute( 'aria-hidden' );
			headEl.setAttribute( 'role', 'group' );
			headEl.setAttribute( 'aria-label', S.sortBy );
			var sortLabel = document.createElement( 'span' );
			sortLabel.className = 'bmlt-lit-sort__label';
			sortLabel.setAttribute( 'aria-hidden', 'true' );
			sortLabel.textContent = S.sortBy;
			headEl.insertBefore( sortLabel, headEl.firstChild );
			sortLive = document.createElement( 'span' );
			sortLive.className = 'bmlt-lit-sr-only';
			sortLive.setAttribute( 'aria-live', 'polite' );
			headEl.appendChild( sortLive );
			Array.prototype.forEach.call( headEl.querySelectorAll( '[data-sort]' ), function ( span ) {
				var key = span.getAttribute( 'data-sort' );
				var label = span.textContent.trim();
				var btn = document.createElement( 'button' );
				btn.type = 'button';
				btn.className = 'bmlt-lit-sort';
				btn.setAttribute( 'data-label', label );
				btn.setAttribute( 'data-key', key );
				var text = document.createElement( 'span' );
				text.textContent = label;
				var arrow = document.createElement( 'span' );
				arrow.className = 'bmlt-lit-sort__arrow';
				arrow.setAttribute( 'aria-hidden', 'true' );
				btn.appendChild( text );
				btn.appendChild( arrow );
				span.textContent = '';
				span.appendChild( btn );
				btn.addEventListener( 'click', function () {
					if ( sortKey !== key ) {
						sortKey = key;
						sortDir = firstDir[ key ] || 1;
					} else if ( sortDir === ( firstDir[ key ] || 1 ) ) {
						sortDir = -sortDir;
					} else {
						sortKey = null; // Third click: back to the default order.
					}
					updateSortButtons( true );
					if ( pager ) {
						pager.reset();
					}
					filterItems();
				} );
				sortButtons.push( btn );
			} );
			updateSortButtons( false );
		}

		function updateSortButtons( announce ) {
			var current = '';
			sortButtons.forEach( function ( btn ) {
				var key = btn.getAttribute( 'data-key' );
				var label = btn.getAttribute( 'data-label' );
				var on = key === sortKey;
				var asc = on && sortDir === 1;
				btn.classList.toggle( 'is-sorted', on );
				btn.querySelector( '.bmlt-lit-sort__arrow' ).textContent = on ? ( asc ? ' \u25B2' : ' \u25BC' ) : '';
				btn.setAttribute( 'aria-label', label + ', ' + ( on ? ( asc ? S.sortAsc : S.sortDesc ) : S.sortNone ) );
				if ( on ) {
					current = sprintf( S.sortedBy, label, asc ? S.sortAsc : S.sortDesc );
				}
			} );
			if ( announce && sortLive ) {
				sortLive.textContent = current || S.defaultOrder;
			}
		}

		/* ---------- Flags and totals ---------- */

		function recalc() {
			var area = currentArea();
			var totalCents = 0;
			var count = 0;
			var anySpecial = false;
			items.forEach( function ( item ) {
				var input = item.querySelector( '.bmlt-lit-qty__input' );
				var max = parseInt( input.getAttribute( 'max' ), 10 ) || 500;
				var qty = parseInt( input.value, 10 );
				if ( isNaN( qty ) || qty < 0 ) {
					qty = 0;
				}
				if ( qty > max ) {
					qty = max;
					input.value = String( max );
				}
				if ( input.disabled ) {
					qty = 0;
				}
				totalCents += ( parseInt( item.getAttribute( 'data-price-cents' ), 10 ) || 0 ) * qty;
				count += qty;

				var flag = item.querySelector( '.bmlt-lit-item__flag' );
				var info = area ? lineInfo( item, area, qty ) : null;
				var isSpecialItem = !! ( info && info.status === 'special_order' );
				var partial = !! ( info && ! isSpecialItem && qty > 0 && info.special > 0 );
				if ( flag && data.showStock ) {
					flag.textContent = '';
					if ( isSpecialItem ) {
						flag.appendChild( specialBadge( S.special_order ) );
					} else if ( partial ) {
						flag.appendChild( specialBadge( S.special_order, sprintf( S.partOf, info.special, qty ) ) );
					}
				}
				item.classList.toggle( 'is-special', isSpecialItem || partial );
				item.classList.toggle( 'has-qty', qty > 0 );
				if ( qty > 0 && ( isSpecialItem || partial ) ) {
					anySpecial = true;
				}
			} );
			if ( sumCount ) {
				sumCount.textContent = String( count );
			}
			if ( sumTotal ) {
				sumTotal.textContent = formatMoney( totalCents, data.currency );
			}
			if ( warning ) {
				warning.hidden = ! anySpecial;
			}
			updateOffPage();
		}

		/* ---------- Events ---------- */

		if ( select ) {
			select.addEventListener( 'change', applyArea );
		}
		if ( meetingSearch ) {
			meetingSearch.addEventListener( 'input', function () {
				rebuildMeetings( meetingSearch.value );
			} );
		}
		if ( itemSearch ) {
			itemSearch.addEventListener( 'input', function () {
				if ( pager ) {
					pager.reset();
				}
				filterItems();
			} );
		}
		form.addEventListener( 'input', function ( e ) {
			if ( e.target.classList && e.target.classList.contains( 'bmlt-lit-qty__input' ) ) {
				recalc();
			}
		} );
		form.addEventListener( 'click', function ( e ) {
			var btn = e.target.closest ? e.target.closest( '.bmlt-lit-qty__btn' ) : null;
			if ( ! btn ) {
				return;
			}
			e.preventDefault();
			var input = btn.parentNode.querySelector( '.bmlt-lit-qty__input' );
			if ( input.disabled ) {
				return;
			}
			var max = parseInt( input.getAttribute( 'max' ), 10 ) || 500;
			var v = ( parseInt( input.value, 10 ) || 0 ) + parseInt( btn.getAttribute( 'data-step' ), 10 );
			input.value = String( Math.max( 0, Math.min( max, v ) ) );
			recalc();
		} );
		form.addEventListener( 'submit', function () {
			if ( submit ) {
				window.setTimeout( function () {
					submit.disabled = true;
					submit.textContent = S.submitting;
				}, 0 );
			}
		} );

		var errors = root.querySelector( '.bmlt-lit-errors' );
		if ( errors ) {
			errors.focus();
		}
		applyArea();
	}

	function initReview( form ) {
		var stale = form.querySelector( '.bmlt-lit-review__stale' );
		var refresh = form.querySelector( '.bmlt-lit-review__refresh' );
		var inputs = Array.prototype.slice.call( form.querySelectorAll( '.bmlt-lit-review__qty' ) );
		form.addEventListener( 'input', function () {
			var changed = inputs.some( function ( i ) {
				return String( parseInt( i.value, 10 ) || 0 ) !== i.getAttribute( 'data-original' );
			} );
			if ( stale ) {
				stale.hidden = ! changed;
			}
			if ( refresh ) {
				refresh.classList.toggle( 'bmlt-lit-button--primary', changed );
			}
		} );
	}

	function boot() {
		Array.prototype.forEach.call( document.querySelectorAll( 'form[data-bmlt-lit-order-form]' ), init );
		Array.prototype.forEach.call( document.querySelectorAll( '.bmlt-lit-review__form' ), initReview );
		var reviewTitle = document.querySelector( '.bmlt-lit-review__title' );
		if ( reviewTitle && window.location.hash !== '#bmlt-lit-confirmation' ) {
			reviewTitle.focus();
		}
	}

	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', boot );
	} else {
		boot();
	}
} )();
