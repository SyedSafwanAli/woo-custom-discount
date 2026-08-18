/**
 * Expiry buttons on a product page.
 *
 * The buttons do not replace WooCommerce's dropdown — they drive it. Every
 * click sets the select and fires a change, which is what WooCommerce's own
 * variation script listens for. Price, stock, availability and add-to-cart all
 * keep working exactly as they do without this file, and nothing about the
 * checkout has to be reimplemented to make a row of buttons look nicer than a
 * dropdown.
 */
( function ( $ ) {
	'use strict';

	function sync( form ) {
		form.find( '.wcd-swatches' ).each( function () {
			var group = $( this );
			var select = form.find( 'select[name="attribute_' + group.data( 'attribute' ) + '"]' );

			if ( ! select.length ) {
				return;
			}

			var current = select.val();

			group.find( '.wcd-swatch' ).each( function () {
				var button = $( this );
				var value = button.data( 'value' );

				button.toggleClass( 'is-active', value === current );

				// A combination WooCommerce has ruled out should not look
				// clickable. It marks those by removing them from the select.
				var option = select.find( 'option[value="' + value + '"]' );

				button.prop( 'disabled', option.length === 0 );
				button.toggleClass( 'is-unavailable', option.length === 0 );
			} );
		} );
	}

	$( document ).on( 'click', '.wcd-swatch', function ( event ) {
		event.preventDefault();

		var button = $( this );
		var form = button.closest( 'form.variations_form' );
		var group = button.closest( '.wcd-swatches' );
		var select = form.find( 'select[name="attribute_' + group.data( 'attribute' ) + '"]' );

		if ( ! select.length ) {
			return;
		}

		// Clicking the chosen one again clears it, which is how the dropdown's
		// own blank option behaves.
		select.val( select.val() === button.data( 'value' ) ? '' : button.data( 'value' ) ).trigger( 'change' );

		sync( form );
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
