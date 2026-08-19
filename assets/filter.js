/**
 * Filter drawer.
 *
 * Every option is already a working link, so the filter functions with
 * JavaScript switched off — one click filters, one page load. What this adds is
 * the drawer, and batching: tick several things, then Apply once, which is a
 * single page load instead of one per tick.
 *
 * The drawer styling only activates once the `wcd-js` class is on the wrapper,
 * so a browser that never runs this file gets a plain, readable panel rather
 * than a drawer with no way to open it.
 */
( function () {
	'use strict';

	var config = window.wcdFilter || {};
	var strings = config.strings || {};

	var focusable = 'a[href], button:not([disabled]), input, select, textarea, [tabindex]:not([tabindex="-1"])';

	/* --------------------------------------------------------------------
	 * Price range slider
	 * ----------------------------------------------------------------- */

	/**
	 * The slider's bounds and current values.
	 */
	function readRange( form ) {
		var lo = form.querySelector( '[data-wcd-thumb="min"]' );
		var hi = form.querySelector( '[data-wcd-thumb="max"]' );

		if ( ! lo || ! hi ) {
			return null;
		}

		return {
			form: form,
			lo: lo,
			hi: hi,
			numLo: form.querySelector( '[data-wcd-num="min"]' ),
			numHi: form.querySelector( '[data-wcd-num="max"]' ),
			fill: form.querySelector( '[data-wcd-fill]' ),
			boundMin: parseFloat( form.getAttribute( 'data-bound-min' ) ),
			boundMax: parseFloat( form.getAttribute( 'data-bound-max' ) )
		};
	}

	/**
	 * Redraws the filled section and mirrors the values into the number fields.
	 */
	function paintRange( range ) {
		var span = range.boundMax - range.boundMin;
		var lo = parseFloat( range.lo.value );
		var hi = parseFloat( range.hi.value );

		if ( range.fill && span > 0 ) {
			range.fill.style.left = ( ( lo - range.boundMin ) / span ) * 100 + '%';
			range.fill.style.right = ( ( range.boundMax - hi ) / span ) * 100 + '%';
		}

		if ( range.numLo ) {
			range.numLo.value = Math.round( lo );
		}

		if ( range.numHi ) {
			range.numHi.value = Math.round( hi );
		}
	}

	/**
	 * Keeps the two handles from crossing over each other.
	 */
	function clampRange( range, moved ) {
		var lo = parseFloat( range.lo.value );
		var hi = parseFloat( range.hi.value );

		if ( lo <= hi ) {
			return;
		}

		// Whichever handle was dragged wins; the other is pushed to meet it,
		// rather than the pair swapping and the handle jumping out from under
		// the pointer.
		if ( moved === 'min' ) {
			range.hi.value = lo;
		} else {
			range.lo.value = hi;
		}
	}

	function setupRange( scope, onChange ) {
		var form = scope.querySelector( '[data-wcd-range]' );

		if ( ! form ) {
			return null;
		}

		var range = readRange( form );

		if ( ! range ) {
			return null;
		}

		paintRange( range );

		[ [ range.lo, 'min' ], [ range.hi, 'max' ] ].forEach( function ( pair ) {
			pair[ 0 ].addEventListener( 'input', function () {
				clampRange( range, pair[ 1 ] );
				paintRange( range );
				onChange();
			} );
		} );

		// Typing a number moves the matching handle.
		[ [ range.numLo, range.lo ], [ range.numHi, range.hi ] ].forEach( function ( pair ) {
			if ( ! pair[ 0 ] ) {
				return;
			}

			pair[ 0 ].addEventListener( 'change', function () {
				var value = parseFloat( pair[ 0 ].value );

				if ( isNaN( value ) ) {
					return;
				}

				pair[ 1 ].value = Math.min( range.boundMax, Math.max( range.boundMin, value ) );

				clampRange( range, pair[ 1 ] === range.lo ? 'min' : 'max' );
				paintRange( range );
				onChange();
			} );
		} );

		return range;
	}

	/**
	 * The range as a URL value, or null when it still spans everything.
	 */
	function rangeValue( range ) {
		if ( ! range ) {
			return null;
		}

		var lo = Math.round( parseFloat( range.lo.value ) );
		var hi = Math.round( parseFloat( range.hi.value ) );

		if ( lo <= range.boundMin && hi >= range.boundMax ) {
			return null;
		}

		return lo + '-' + hi;
	}

	/**
	 * Reads the options currently ticked, grouped by parameter name.
	 */
	function collect( scope, range ) {
		var groups = {};

		scope.querySelectorAll( '.wcd-item.is-on > .wcd-opt' ).forEach( function ( option ) {
			var group = option.getAttribute( 'data-group' );
			var value = option.getAttribute( 'data-value' );

			if ( ! group || ! value ) {
				return;
			}

			if ( ! groups[ group ] ) {
				groups[ group ] = [];
			}

			groups[ group ].push( value );
		} );

		var price = rangeValue( range );

		if ( price ) {
			groups.price = [ price ];
		}

		return groups;
	}

	/**
	 * Builds the URL for the current selection.
	 */
	function buildUrl( scope, range ) {
		var base = config.baseUrl || window.location.pathname;
		var groups = collect( scope, range );
		var parts = [];

		Object.keys( groups ).forEach( function ( group ) {
			parts.push(
				encodeURIComponent( group ) + '=' + encodeURIComponent( groups[ group ].join( ',' ) )
			);
		} );

		if ( ! parts.length ) {
			return config.clearUrl || base;
		}

		return base + ( base.indexOf( '?' ) === -1 ? '?' : '&' ) + parts.join( '&' );
	}

	/**
	 * Keeps the Apply button honest about what it is going to do.
	 */
	function refreshApply( scope, range ) {
		var apply = scope.querySelector( '[data-wcd-apply]' );

		if ( ! apply ) {
			return;
		}

		var total = scope.querySelectorAll( '.wcd-item.is-on' ).length;

		// A narrowed price range counts as a filter too, or Apply would claim to
		// show all products while quietly carrying one.
		if ( rangeValue( range ) ) {
			total += 1;
		}

		if ( total === 0 ) {
			apply.textContent = strings.showAll || 'Show all products';
		} else if ( total === 1 ) {
			apply.textContent = strings.applyOne || 'Apply 1 filter';
		} else {
			apply.textContent = ( strings.applyCount || 'Apply %d filters' ).replace( '%d', total );
		}
	}

	/**
	 * Ticks or unticks one option without leaving the page.
	 */
	function toggle( scope, option, range ) {
		var item = option.closest( '.wcd-item' );

		if ( ! item ) {
			return;
		}

		var isOn = item.classList.contains( 'is-on' );
		var multi = option.getAttribute( 'data-multi' ) !== '0';

		// A single-choice group — sort order, or the in-stock switch — can only
		// hold one value, so the others in it are cleared first.
		if ( ! multi && ! isOn ) {
			var group = option.getAttribute( 'data-group' );

			scope.querySelectorAll( '.wcd-opt[data-group="' + group + '"]' ).forEach( function ( sibling ) {
				var siblingItem = sibling.closest( '.wcd-item' );

				if ( siblingItem ) {
					siblingItem.classList.remove( 'is-on' );
					sibling.setAttribute( 'aria-pressed', 'false' );
				}
			} );
		}

		item.classList.toggle( 'is-on', ! isOn );
		option.setAttribute( 'aria-pressed', isOn ? 'false' : 'true' );

		refreshApply( scope, range );
	}

	/* --------------------------------------------------------------------
	 * Open and close
	 * ----------------------------------------------------------------- */

	function isDrawer( root ) {
		var mode = root.getAttribute( 'data-mode' );

		if ( mode !== 'auto' ) {
			return mode === 'drawer';
		}

		// Auto becomes a static panel on wide screens, where there is no drawer
		// to open and nothing to trap focus inside.
		return window.matchMedia( '( max-width: 980px )' ).matches;
	}

	function open( root, scope ) {
		if ( ! isDrawer( root ) ) {
			return;
		}

		scope.classList.add( 'is-open' );
		document.documentElement.classList.add( 'wcd-locked' );

		var trigger = root.querySelector( '[data-wcd-open]' );

		if ( trigger ) {
			trigger.setAttribute( 'aria-expanded', 'true' );
		}

		var close_btn = scope.querySelector( '[data-wcd-close]' );

		if ( close_btn ) {
			close_btn.focus();
		}
	}

	function close( root, scope ) {
		scope.classList.remove( 'is-open' );
		document.documentElement.classList.remove( 'wcd-locked' );

		var trigger = root.querySelector( '[data-wcd-open]' );

		if ( trigger ) {
			trigger.setAttribute( 'aria-expanded', 'false' );
			trigger.focus();
		}
	}

	/**
	 * Keeps Tab inside the open drawer, so focus cannot wander onto the page
	 * hidden behind it.
	 */
	function trapFocus( scope, event ) {
		var panel = scope.querySelector( '[data-wcd-panel]' );

		if ( ! panel ) {
			return;
		}

		var nodes = Array.prototype.filter.call(
			panel.querySelectorAll( focusable ),
			function ( node ) {
				return node.offsetParent !== null;
			}
		);

		if ( ! nodes.length ) {
			return;
		}

		var first = nodes[ 0 ];
		var last = nodes[ nodes.length - 1 ];

		if ( event.shiftKey && document.activeElement === first ) {
			event.preventDefault();
			last.focus();
		} else if ( ! event.shiftKey && document.activeElement === last ) {
			event.preventDefault();
			first.focus();
		}
	}

	/* --------------------------------------------------------------------
	 * Wiring
	 * ----------------------------------------------------------------- */

	/**
	 * Moves the drawer to the end of the body.
	 *
	 * A fixed-position element is only fixed to the viewport, and only stacks
	 * against the whole page, while none of its ancestors has a transform,
	 * filter or perspective. Divi puts transforms on sections, so a drawer left
	 * inside the layout gets trapped in that ancestor's stacking context — where
	 * a z-index of a million still loses to a nav bar of nine thousand, and the
	 * site's floating menu tabs end up drawn over the top of it.
	 *
	 * Lifting it out of the layout is what modal libraries all do, and for this
	 * reason.
	 *
	 * @return {Element} The element the panel now lives in.
	 */
	function portal( root ) {
		var panel = root.querySelector( '[data-wcd-panel]' );

		if ( ! panel ) {
			return root;
		}

		var host = document.createElement( 'div' );

		// The stylesheet is keyed on the mode classes, so they travel with it.
		host.className = root.className + ' wcd-portal';
		host.setAttribute( 'data-wcd-portal', panel.id || '' );

		var scrim = root.querySelector( '[data-wcd-scrim]' );

		if ( scrim ) {
			host.appendChild( scrim );
		}

		host.appendChild( panel );
		document.body.appendChild( host );

		return host;
	}


	/* ------------------------------------------------------------------
	 * Applying a filter without reloading the page
	 *
	 * Every option is still a link and every apply still produces a real URL:
	 * that is what makes the filter work with the script switched off, keeps
	 * the address bar shareable, and lets the back button behave. What changes
	 * here is only how the page gets from one URL to the next — the same
	 * document is fetched and the parts that differ are swapped in, instead of
	 * the browser throwing the page away and building it again.
	 * --------------------------------------------------------------- */

	// The product grid, the count above it and the pagination below, in one
	// piece on this theme. The fallbacks are for a shop that is not built with
	// Divi's module.
	var SWAP = [
		'.et_pb_shop .woocommerce',
		'.woocommerce-result-count',
		'.woocommerce-ordering',
		'ul.products',
		'.woocommerce-pagination'
	];

	var busy = false;

	function grid() {
		for ( var i = 0; i < SWAP.length; i++ ) {
			var found = document.querySelector( SWAP[ i ] );

			if ( found ) {
				return found;
			}
		}

		return null;
	}

	/**
	 * Swaps in everything that differs, and tells the page it happened.
	 */
	function paint( html ) {
		var incoming = document.implementation.createHTMLDocument( '' );

		incoming.documentElement.innerHTML = html;

		var swapped = false;

		SWAP.forEach( function ( selector ) {
			var here = document.querySelector( selector );
			var there = incoming.querySelector( selector );

			if ( ! here || ! there ) {
				return;
			}

			// The first match that works is the whole region; once that is in,
			// the narrower selectors would only put the same nodes back.
			if ( swapped ) {
				return;
			}

			here.replaceWith( there );
			swapped = selector === SWAP[ 0 ];
		} );

		// The bar carries the active-filter chips and the number on the trigger.
		// Its contents are replaced rather than the bar itself, because the bar
		// is where the click handler lives.
		var bar = document.querySelector( '.wcd-bar' );
		var newBar = incoming.querySelector( '.wcd-bar' );

		if ( bar && newBar ) {
			bar.innerHTML = newBar.innerHTML;
		}

		// Counts beside each option, when the shop shows them.
		var counts = document.querySelectorAll( '.wcd-opt__count' );
		var newCounts = incoming.querySelectorAll( '.wcd-opt__count' );

		if ( counts.length && counts.length === newCounts.length ) {
			counts.forEach( function ( node, i ) {
				node.textContent = newCounts[ i ].textContent;
			} );
		}

		// Other scripts hang their work off these: lazy images, the badges
		// plugin, our own countdowns. Without them the new cards arrive inert.
		panels.forEach( function ( panel ) {
			panel();
		} );

		document.dispatchEvent( new CustomEvent( 'wcd:filtered', { bubbles: true } ) );

		if ( window.jQuery ) {
			window.jQuery( document.body ).trigger( 'wcd_filtered' );
			window.jQuery( window ).trigger( 'resize' );
		}
	}

	/**
	 * Fetches a URL and shows it, leaving the address bar and history correct.
	 *
	 * @param {string}  url    Where to go.
	 * @param {boolean} push   Whether this is a new step in the history.
	 */
	function go( url, push ) {
		var target = grid();

		// With nothing recognisable to replace, the ordinary navigation is not
		// a failure — it is the fallback working.
		if ( ! target || ! window.fetch || ! window.history || ! window.history.pushState ) {
			window.location.href = url;

			return;
		}

		if ( busy ) {
			return;
		}

		busy = true;
		target.classList.add( 'wcd-loading' );

		fetch( url, { credentials: 'same-origin', headers: { 'X-Requested-With': 'XMLHttpRequest' } } )
			.then( function ( response ) {
				if ( ! response.ok ) {
					throw new Error( response.status );
				}

				return response.text();
			} )
			.then( function ( html ) {
				paint( html );

				if ( push ) {
					window.history.pushState( { wcd: true }, '', url );
				}

				var top = grid();

				if ( top ) {
					var y = top.getBoundingClientRect().top + window.pageYOffset - 100;

					window.scrollTo( { top: y < 0 ? 0 : y, behavior: 'smooth' } );
				}
			} )
			.catch( function () {
				// A failed fetch should not leave the shopper stranded on a
				// page that ignored their click.
				window.location.href = url;
			} )
			.then( function () {
				busy = false;

				var now = grid();

				if ( now ) {
					now.classList.remove( 'wcd-loading' );
				}
			} );
	}

	// Paging and the chips are links inside the swapped region, so they are
	// caught here rather than bound to nodes that get replaced.
	document.addEventListener( 'click', function ( event ) {
		if ( event.metaKey || event.ctrlKey || event.shiftKey || event.which > 1 ) {
			return;
		}

		var link = event.target.closest(
			'.woocommerce-pagination a, .wcd-chips a, .wcd-chip, .wcd-btn--ghost'
		);

		if ( ! link || ! link.href ) {
			return;
		}

		event.preventDefault();
		go( link.href, true );
	} );

	window.addEventListener( 'popstate', function () {
		go( window.location.href, false );
	} );

	// Panels that need putting back in step after the results change. The panel
	// is lifted out to the body and its listeners live on nodes that must not be
	// replaced, so it is re-read from the URL rather than swapped for new markup.
	var panels = [];

	/**
	 * Makes the panel agree with the address bar.
	 *
	 * Without this, clearing a filter left its box still ticked: the chips and
	 * the results were replaced, but the drawer was never told.
	 */
	function resync( scope, range ) {
		var params = new URLSearchParams( window.location.search );

		scope.querySelectorAll( '.wcd-opt' ).forEach( function ( option ) {
			var group = option.getAttribute( 'data-group' );
			var value = option.getAttribute( 'data-value' );
			var item = option.closest( '.wcd-item' );

			if ( ! group || ! value || ! item ) {
				return;
			}

			var chosen = ( params.get( group ) || '' ).split( ',' ).indexOf( value ) !== -1;

			item.classList.toggle( 'is-on', chosen );
			option.setAttribute( 'aria-pressed', chosen ? 'true' : 'false' );
		} );

		// The price handles are not options, so they are put back separately.
		if ( range ) {
			var price = ( params.get( 'price' ) || '' ).split( '-' );

			var chosen = price.length === 2 && price[ 0 ] !== '' && price[ 1 ] !== '';

			range.lo.value = chosen ? price[ 0 ] : range.boundMin;
			range.hi.value = chosen ? price[ 1 ] : range.boundMax;

			clampRange( range, range.lo );
			paintRange( range );
		}

		refreshApply( scope, range );
	}

	function setup( root ) {
		root.classList.add( 'wcd-js' );

		// Only a real drawer is lifted out. Auto mode has to stay in the layout,
		// because on a wide screen it is the sidebar.
		var scope = root.getAttribute( 'data-mode' ) === 'drawer' ? portal( root ) : root;

		var range = setupRange( scope, function () {
			refreshApply( scope, range );
		} );

		refreshApply( scope, range );

		// Enter in a number field submits the form. With the script running,
		// that has to go through the same URL builder as Apply, or the other
		// filters in the drawer would be dropped.
		var form = scope.querySelector( '[data-wcd-range]' );

		if ( form ) {
			form.addEventListener( 'submit', function ( event ) {
				event.preventDefault();
				go( buildUrl( scope, range ), true );
			} );
		}

		var onClick = function ( event ) {
			if ( event.target.closest( '[data-wcd-open]' ) ) {
				event.preventDefault();
				open( root, scope );

				return;
			}

			if ( event.target.closest( '[data-wcd-close]' ) || event.target.closest( '[data-wcd-scrim]' ) ) {
				event.preventDefault();
				close( root, scope );

				return;
			}

			if ( event.target.closest( '[data-wcd-apply]' ) ) {
				event.preventDefault();

				if ( isDrawer( root ) ) {
					close( root, scope );
				}

				go( buildUrl( scope, range ), true );

				return;
			}

			var option = event.target.closest( '.wcd-opt' );

			if ( option ) {
				// The href stays as the fallback for a browser without JS; here
				// the choice is only remembered until Apply is pressed.
				event.preventDefault();
				toggle( scope, option, range );
			}
		};

		panels.push( function () {
			resync( scope, range );
		} );

		root.addEventListener( 'click', onClick );

		if ( scope !== root ) {
			scope.addEventListener( 'click', onClick );
		}

		document.addEventListener( 'keydown', function ( event ) {
			if ( ! scope.classList.contains( 'is-open' ) ) {
				return;
			}

			if ( event.key === 'Escape' ) {
				close( root, scope );

				return;
			}

			if ( event.key === 'Tab' ) {
				trapFocus( scope, event );
			}
		} );
	}

	function start() {
		document.querySelectorAll( '[data-wcd-filter]' ).forEach( setup );
	}

	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', start );
	} else {
		start();
	}
} )();
