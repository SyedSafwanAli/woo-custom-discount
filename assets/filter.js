/**
 * Filter panel behaviour.
 *
 * Deliberately small. Every option is already a working link, so the filter
 * functions with JavaScript switched off — this only adds the drawer toggle on
 * small screens and a "loading" state so a slow page feels responsive.
 */
( function () {
	'use strict';

	function bindToggle( root ) {
		var button = root.querySelector( '[data-wcd-toggle]' );

		if ( ! button ) {
			return;
		}

		button.addEventListener( 'click', function () {
			var open = root.classList.toggle( 'is-open' );

			button.setAttribute( 'aria-expanded', open ? 'true' : 'false' );
		} );
	}

	function bindLinks( root ) {
		root.addEventListener( 'click', function ( event ) {
			var link = event.target.closest( '.wcd-filter__link' );

			if ( ! link ) {
				return;
			}

			// Navigation is about to happen; marking the panel busy stops
			// someone clicking three boxes in a row and losing two of them.
			root.classList.add( 'is-busy' );
		} );
	}

	function start() {
		var roots = document.querySelectorAll( '[data-wcd-filter]' );

		Array.prototype.forEach.call( roots, function ( root ) {
			bindToggle( root );
			bindLinks( root );
		} );
	}

	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', start );
	} else {
		start();
	}
} )();
