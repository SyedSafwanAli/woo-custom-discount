/**
 * Countdown timers.
 *
 * The server sends only an end timestamp, so page caching cannot freeze the
 * clock. It also sends its own current time, because a visitor's device clock
 * can be minutes or hours out — without correcting for that, a sale could look
 * expired to one person and fine to another.
 */
( function () {
	'use strict';

	var config = window.wcdCountdown || {};
	var offset = 0;

	if ( typeof config.serverNow === 'number' ) {
		offset = config.serverNow * 1000 - Date.now();
	}

	function now() {
		return Date.now() + offset;
	}

	function pad( value ) {
		return value < 10 ? '0' + value : String( value );
	}

	function paint( element, remaining ) {
		var totalSeconds = Math.max( 0, Math.floor( remaining / 1000 ) );

		var parts = {
			days: Math.floor( totalSeconds / 86400 ),
			hours: Math.floor( ( totalSeconds % 86400 ) / 3600 ),
			minutes: Math.floor( ( totalSeconds % 3600 ) / 60 ),
			seconds: totalSeconds % 60
		};

		Object.keys( parts ).forEach( function ( unit ) {
			var node = element.querySelector( '[data-unit="' + unit + '"]' );

			if ( node ) {
				node.textContent = pad( parts[ unit ] );
			}
		} );
	}

	function tick( timer ) {
		var remaining = timer.ends - now();

		if ( remaining <= 0 ) {
			// Zero means the discount is over. Removing the timer is honest;
			// leaving "00 00 00 00" on screen would suggest the offer is still
			// live at the very moment it stopped being live.
			timer.element.remove();

			return false;
		}

		paint( timer.element, remaining );

		return true;
	}

	function start() {
		var nodes = document.querySelectorAll( '[data-wcd-countdown]' );

		if ( ! nodes.length ) {
			return;
		}

		var timers = [];

		Array.prototype.forEach.call( nodes, function ( element ) {
			var ends = parseInt( element.getAttribute( 'data-ends' ), 10 );

			if ( ! ends ) {
				element.remove();

				return;
			}

			var timer = { element: element, ends: ends * 1000 };

			if ( tick( timer ) ) {
				element.classList.add( 'is-live' );
				timers.push( timer );
			}
		} );

		if ( ! timers.length ) {
			return;
		}

		// One interval for every timer on the page, rather than one each.
		setInterval( function () {
			timers = timers.filter( tick );
		}, 1000 );
	}

	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', start );
	} else {
		start();
	}
} )();
