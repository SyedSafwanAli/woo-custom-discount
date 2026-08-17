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

	/**
	 * Reads the options currently ticked, grouped by parameter name.
	 */
	function collect( scope ) {
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

		return groups;
	}

	/**
	 * Builds the URL for the current selection.
	 */
	function buildUrl( scope ) {
		var base = config.baseUrl || window.location.pathname;
		var groups = collect( scope );
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
	function refreshApply( scope ) {
		var apply = scope.querySelector( '[data-wcd-apply]' );

		if ( ! apply ) {
			return;
		}

		var total = scope.querySelectorAll( '.wcd-item.is-on' ).length;

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
	function toggle( scope, option ) {
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

		refreshApply( scope );
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

	function setup( root ) {
		root.classList.add( 'wcd-js' );

		// Only a real drawer is lifted out. Auto mode has to stay in the layout,
		// because on a wide screen it is the sidebar.
		var scope = root.getAttribute( 'data-mode' ) === 'drawer' ? portal( root ) : root;

		refreshApply( scope );

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
				window.location.href = buildUrl( scope );

				return;
			}

			var option = event.target.closest( '.wcd-opt' );

			if ( option ) {
				// The href stays as the fallback for a browser without JS; here
				// the choice is only remembered until Apply is pressed.
				event.preventDefault();
				toggle( scope, option );
			}
		};

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
