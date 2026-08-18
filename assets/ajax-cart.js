/**
 * Add to cart from a product page without reloading it.
 *
 * The shop grid's buttons already work this way — WooCommerce's own setting
 * covers those. A product page's form does not: it posts, the page reloads, and
 * anything that was going to open on top of it never gets the chance.
 *
 * The request goes to WooCommerce's own add_to_cart endpoint, and the response
 * carries the fragments core uses to redraw every mini cart on the page. All
 * this file adds is the event core fires everywhere else, so a product page
 * behaves like the rest of the shop.
 */
( function ( $ ) {
	'use strict';

	var settings = window.wcdAjaxCart || {};

	function endpoint( action ) {
		return String( settings.ajaxUrl || '' ).replace( '%%endpoint%%', action );
	}

	$( document ).on( 'submit', 'form.cart', function ( event ) {
		var form = $( this );

		if ( ! settings.ajaxUrl ) {
			return;
		}

		// A grouped form adds several products at once, and an external product
		// is a link to somewhere else. Neither is a single add, so both go the
		// ordinary way.
		if ( form.hasClass( 'grouped_form' ) ) {
			return;
		}

		// Core's endpoint takes a variation's own id in place of the parent's,
		// and works out the rest itself.
		var variation = form.find( 'input[name="variation_id"]' ).val();
		var id = variation && parseInt( variation, 10 ) > 0
			? variation
			: form.find( '[name="add-to-cart"]' ).val();

		if ( ! id ) {
			return;
		}

		event.preventDefault();

		var button = form.find( '.single_add_to_cart_button' ).first();

		button.addClass( 'loading' );

		$.ajax( {
			type: 'POST',
			url: endpoint( 'add_to_cart' ),
			data: {
				product_id: id,
				quantity: form.find( 'input[name="quantity"]' ).val() || 1
			},
			success: function ( response ) {
				if ( ! response ) {
					return;
				}

				// Core answers a rejected add with the product's own URL, where
				// its error notice will be waiting.
				if ( response.error && response.product_url ) {
					window.location = response.product_url;

					return;
				}

				if ( response.fragments ) {
					$.each( response.fragments, function ( selector, html ) {
						$( selector ).replaceWith( html );
					} );
				}

				$( document.body ).trigger( 'wc_fragments_refreshed' );
				$( document.body ).trigger( 'added_to_cart', [
					response.fragments,
					response.cart_hash,
					button
				] );
			},
			error: function () {
				// Something between here and the shop broke. The plain form still
				// works, so fall back to it rather than losing the add.
				form.off( 'submit' ).trigger( 'submit' );
			},
			complete: function () {
				button.removeClass( 'loading' );
			}
		} );
	} );
} )( jQuery );
