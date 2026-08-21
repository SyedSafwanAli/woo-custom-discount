/**
 * Expiry chooser on a product page.
 *
 * The rows do not replace WooCommerce's dropdown — they drive it. Every click
 * sets the select and fires a change, which is what WooCommerce's own variation
 * script listens for. Price, stock, availability and add-to-cart all keep
 * working exactly as they do without this file, and nothing about the checkout
 * has to be reimplemented to make a table look better than a dropdown.
 */
( function ( $ ) {
	'use strict';

	function selectFor( group, form ) {
		return form.find( 'select[name="attribute_' + group.data( 'attribute' ) + '"]' );
	}

	function sync( form ) {
		form.find( '.wcd-swatches' ).each( function () {
			var group = $( this );
			var select = selectFor( group, form );

			if ( ! select.length ) {
				return;
			}

			var current = select.val();

			group.find( '.wcd-choice__row' ).each( function () {
				var row = $( this );
				var value = row.data( 'value' );
				var chosen = value === current;

				row.toggleClass( 'is-active', chosen );
				row.attr( 'aria-checked', chosen ? 'true' : 'false' );

				// WooCommerce rules combinations out as they are chosen, by
				// taking them out of the select. A row for one of those should
				// not look available.
				var available = select.find( 'option[value="' + value + '"]' ).length > 0;

				row.toggleClass( 'is-unavailable', ! available );
			} );
		} );
	}

	function choose( row ) {
		var form = row.closest( 'form.variations_form' );
		var group = row.closest( '.wcd-swatches' );
		var select = selectFor( group, form );

		if ( ! select.length || row.hasClass( 'is-unavailable' ) ) {
			return;
		}

		var value = row.data( 'value' );

		// Choosing the current one again clears it, which is how the dropdown's
		// own blank option behaves.
		select.val( select.val() === value ? '' : value ).trigger( 'change' );

		sync( form );
	}


	/**
	 * The price shown above the chooser.
	 *
	 * On a variable product WooCommerce prints a range there — "Rs 2,697 –
	 * Rs 4,495" — because until an expiry is picked, that really is the answer.
	 * Once one is picked the variation prints its own price just below, and the
	 * range above stops being true: it goes on offering a spread when the
	 * shopper has already narrowed it to one number.
	 *
	 * Found by looking for the price that is not inside the form, since the
	 * variation's own price is.
	 */
	function rangeOf( form ) {
		var found = form.data( 'wcdRange' );

		if ( found ) {
			return found;
		}

		var scope = form.closest( '.product' );

		if ( ! scope.length ) {
			scope = $( 'body' );
		}

		// Divi's Theme Builder prints the variation's own price outside the
		// form, not inside it, so "not in the form" is not enough on its own to
		// tell the two apart.
		var range = scope.find( '.price' ).filter( function () {
			var el = $( this );

			return el.closest( 'form.variations_form' ).length === 0
				&& el.closest( '.woocommerce-variation-price' ).length === 0
				&& el.closest( '.wcd-choice, .wcd-swatches' ).length === 0;
		} ).first();

		form.data( 'wcdRange', range );

		return range;
	}

	function showRange( form, on ) {
		rangeOf( form ).toggleClass( 'wcd-range-hidden', ! on );
	}

	$( document ).on( 'click', '.wcd-choice__row', function ( event ) {
		event.preventDefault();
		choose( $( this ) );
	} );

	// A row is a control, so it answers to the keyboard like one.
	$( document ).on( 'keydown', '.wcd-choice__row', function ( event ) {
		if ( event.key === ' ' || event.key === 'Enter' ) {
			event.preventDefault();
			choose( $( this ) );
		}
	} );

	$( document ).on( 'woocommerce_update_variation_values found_variation reset_data show_variation', 'form.variations_form', function () {
		sync( $( this ) );
	} );

	// One expiry chosen: the range above has been answered, so it goes.
	$( document ).on( 'show_variation', 'form.variations_form', function () {
		showRange( $( this ), false );
	} );

	// Cleared again — the range is the honest answer once more.
	$( document ).on( 'hide_variation reset_data', 'form.variations_form', function () {
		showRange( $( this ), true );
	} );

	$( function () {
		$( 'form.variations_form' ).each( function () {
			sync( $( this ) );
		} );
	} );
} )( jQuery );
