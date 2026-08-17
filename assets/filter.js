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

	/**
	 * Reads the options currently ticked, grouped by parameter name.
	 */
	function collect( root ) {
		var groups = {};

		root.querySelectorAll( '.wcd-item.is-on > .wcd-opt' ).forEach( function ( option ) {
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

	function countSelected( root ) {
		return root.querySelectorAll( '.wcd-item.is-on' ).length;
	}

	/**
	 * Builds the URL for the current selection.
	 */
	function buildUrl( root ) {
		var base = config.baseUrl || window.location.pathname;
		var groups = collect( root );
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
	function refreshApply( root ) {
		var apply = root.querySelector( '[data-wcd-apply]' );

		if ( ! apply ) {
			return;
		}

		var total = countSelected( root );

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
	function toggle( root, option ) {
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

			root.querySelectorAll( '.wcd-opt[data-group="' + group + '"]' ).forEach( function ( sibling ) {
				var siblingItem = sibling.closest( '.wcd-item' );

				if ( siblingItem ) {
					siblingItem.classList.remove( 'is-on' );
					sibling.setAttribute( 'aria-pressed', 'false' );
				}
			} );
		}

		item.classList.toggle( 'is-on', ! isOn );
		option.setAttribute( 'aria-pressed', isOn ? 'false' : 'true' );

		refreshApply( root );
	}

	/* --------------------------------------------------------------------
	 * Drawer open and close
	 * ----------------------------------------------------------------- */

	var focusable = 'a[href], button:not([disabled]), input, select, textarea, [tabindex]:not([tabindex="-1"])';

	function isDrawer( root ) {
		// Auto mode becomes a static panel on wide screens, where there is no
		// drawer to open and nothing to trap focus inside.
		if ( root.getAttribute( 'data-mode' ) !== 'auto' ) {
			return root.getAttribute( 'data-mode' ) === 'drawer';
		}

		return window.matchMedia( '( max-width: 980px )' ).matches;
	}

	function open( root ) {
		if ( ! isDrawer( root ) ) {
			return;
		}

		root.classList.add( 'is-open' );
		document.documentElement.classList.add( 'wcd-locked' );

		var trigger = root.querySelector( '[data-wcd-open]' );

		if ( trigger ) {
			trigger.setAttribute( 'aria-expanded', 'true' );
		}

		var close = root.querySelector( '[data-wcd-close]' );

		if ( close ) {
			close.focus();
		}
	}

	function close( root ) {
		root.classList.remove( 'is-open' );
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
	function trapFocus( root, event ) {
		var panel = root.querySelector( '[data-wcd-panel]' );

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

	function setup( root ) {
		root.classList.add( 'wcd-js' );
		refreshApply( root );

		root.addEventListener( 'click', function ( event ) {
			var open_btn = event.target.closest( '[data-wcd-open]' );

			if ( open_btn ) {
				event.preventDefault();
				open( root );

				return;
			}

			if ( event.target.closest( '[data-wcd-close]' ) || event.target.closest( '[data-wcd-scrim]' ) ) {
				event.preventDefault();
				close( root );

				return;
			}

			var apply = event.target.closest( '[data-wcd-apply]' );

			if ( apply ) {
				event.preventDefault();
				window.location.href = buildUrl( root );

				return;
			}

			var option = event.target.closest( '.wcd-opt' );

			if ( option ) {
				// The href stays as the fallback for a browser without JS; here
				// the choice is only remembered until Apply is pressed.
				event.preventDefault();
				toggle( root, option );
			}
		} );

		document.addEventListener( 'keydown', function ( event ) {
			if ( ! root.classList.contains( 'is-open' ) ) {
				return;
			}

			if ( event.key === 'Escape' ) {
				close( root );

				return;
			}

			if ( event.key === 'Tab' ) {
				trapFocus( root, event );
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
