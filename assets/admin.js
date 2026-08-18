/**
 * Admin helpers.
 *
 * One job: hide the fields that do not apply to the chosen scope. A campaign on
 * the whole store has no use for a product list, and a form that shows every
 * field with "used only when…" underneath makes the reader do the filtering.
 */
( function () {
	'use strict';

	function syncScope( form ) {
		var chosen = form.querySelector( '[data-wcd-scope]:checked' );

		if ( ! chosen ) {
			return;
		}

		form.querySelectorAll( '[data-wcd-show-for]' ).forEach( function ( row ) {
			var wanted = row.getAttribute( 'data-wcd-show-for' );

			row.hidden = wanted !== chosen.value;
		} );
	}

	/* --------------------------------------------------------------------
	 * Filter bands: add and remove rows without a page reload
	 * ----------------------------------------------------------------- */

	var nextIndex = Date.now();

	function addBand( name ) {
		var template = document.querySelector( '[data-wcd-bucket-template="' + name + '"]' );
		var table = document.querySelector( '[data-wcd-buckets="' + name + '"]' );

		if ( ! template || ! table ) {
			return;
		}

		var body = table.querySelector( '[data-wcd-bucket-rows]' );

		if ( ! body ) {
			return;
		}

		// The save routine reads rows in order and ignores their keys, so the
		// index only has to be unique — a counter is enough.
		var html = template.innerHTML.replace( /__INDEX__/g, String( nextIndex++ ) );

		body.insertAdjacentHTML( 'beforeend', html );

		var added = body.lastElementChild;
		var label = added ? added.querySelector( 'input[type="text"]' ) : null;

		if ( label ) {
			label.focus();
		}
	}

	function bindBands() {
		document.addEventListener( 'click', function ( event ) {
			var add = event.target.closest( '[data-wcd-add-bucket]' );

			if ( add ) {
				event.preventDefault();
				addBand( add.getAttribute( 'data-wcd-add-bucket' ) );

				return;
			}

			var remove = event.target.closest( '[data-wcd-remove-bucket]' );

			if ( ! remove ) {
				return;
			}

			event.preventDefault();

			var row = remove.closest( '[data-wcd-bucket-row]' );
			var body = row ? row.parentNode : null;

			if ( ! row || ! body ) {
				return;
			}

			// Taking the row out of the form is what deletes the band; nothing is
			// gone until Save, so there is no need to ask twice.
			row.remove();

			// Never leave the table with nothing to type into.
			if ( ! body.querySelector( '[data-wcd-bucket-row]' ) ) {
				addBand( body.closest( '[data-wcd-buckets]' ).getAttribute( 'data-wcd-buckets' ) );
			}
		} );
	}

	/* --------------------------------------------------------------------
	 * Batch chips on the product list
	 * ----------------------------------------------------------------- */

	/**
	 * Builds a chip, hidden input and all, so adding one needs no page reload.
	 */
	function makeChip( productId, batchId, label, removeLabel ) {
		var chip = document.createElement( 'span' );

		chip.className = 'wcd-bchip';
		chip.setAttribute( 'data-batch', batchId );

		var text = document.createElement( 'span' );
		text.className = 'wcd-bchip__label';
		text.textContent = label;

		var button = document.createElement( 'button' );
		button.type = 'button';
		button.className = 'wcd-bchip__x';
		button.setAttribute( 'data-wcd-remove', '' );
		button.setAttribute( 'aria-label', removeLabel );
		button.innerHTML = '&times;';

		var input = document.createElement( 'input' );
		input.type = 'hidden';
		input.name = 'batches[' + productId + '][]';
		input.value = batchId;

		chip.appendChild( text );
		chip.appendChild( button );
		chip.appendChild( input );

		return chip;
	}

	function bindChips() {
		document.addEventListener( 'change', function ( event ) {
			var picker = event.target.closest( '[data-wcd-add]' );

			if ( ! picker || ! picker.value ) {
				return;
			}

			var row = picker.closest( '[data-wcd-batches]' );
			var chips = row ? row.querySelector( '[data-wcd-chips]' ) : null;

			if ( ! row || ! chips ) {
				return;
			}

			var option = picker.options[ picker.selectedIndex ];

			chips.appendChild(
				makeChip(
					row.getAttribute( 'data-product' ),
					picker.value,
					option.getAttribute( 'data-label' ) || option.textContent,
					option.getAttribute( 'data-label' ) || option.textContent
				)
			);

			// Hidden rather than removed, so it can come back if the chip goes.
			option.hidden = true;

			picker.value = '';
			row.classList.add( 'is-dirty' );
		} );

		document.addEventListener( 'click', function ( event ) {
			var remove = event.target.closest( '[data-wcd-remove]' );

			if ( ! remove ) {
				return;
			}

			event.preventDefault();

			var chip = remove.closest( '.wcd-bchip' );
			var row = remove.closest( '[data-wcd-batches]' );

			if ( ! chip || ! row ) {
				return;
			}

			var batchId = chip.getAttribute( 'data-batch' );
			var picker = row.querySelector( '[data-wcd-add]' );

			if ( picker ) {
				var option = picker.querySelector( 'option[value="' + batchId + '"]' );

				if ( option ) {
					option.hidden = false;
				}
			}

			chip.remove();
			row.classList.add( 'is-dirty' );
		} );
	}

	function start() {
		bindBands();
		bindChips();

		document.querySelectorAll( '.wcd-form' ).forEach( function ( form ) {
			if ( ! form.querySelector( '[data-wcd-scope]' ) ) {
				return;
			}

			syncScope( form );

			form.addEventListener( 'change', function ( event ) {
				if ( event.target.matches( '[data-wcd-scope]' ) ) {
					syncScope( form );
				}
			} );
		} );
	}

	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', start );
	} else {
		start();
	}
} )();
