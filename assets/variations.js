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

	$( function () {
		$( 'form.variations_form' ).each( function () {
			sync( $( this ) );
		} );
	} );
} )( jQuery );
