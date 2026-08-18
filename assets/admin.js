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

	/* --------------------------------------------------------------------
	 * Finding a batch among many
	 *
	 * The picker stays a plain <select> in the markup: it is the record of what
	 * can be added, it is what the change handler above listens to, and it is
	 * what works with scripts off. This puts a searchable list in front of it and
	 * hands the choice back to the select, so nothing below has to know.
	 *
	 * Three batches need no search. Thirty do, and scrolling a native dropdown
	 * looking for one month is the thing this is here to stop.
	 * ----------------------------------------------------------------- */

	var openPicker = null;

	function closePicker() {
		if ( ! openPicker ) {
			return;
		}

		openPicker.wrap.classList.remove( 'is-open' );
		openPicker.pop.remove();
		openPicker = null;
	}

	/**
	 * The options a row can still add — the ones not already on it as chips.
	 */
	function availableOptions( select ) {
		return Array.prototype.filter.call( select.options, function ( option ) {
			return option.value && ! option.hidden;
		} );
	}

	function buildPicker( wrap, select, button ) {
		var pop = document.createElement( 'div' );
		pop.className = 'wcd-pick';

		var search = document.createElement( 'input' );
		search.type = 'search';
		search.className = 'wcd-pick__search';
		search.placeholder = button.getAttribute( 'data-search-label' ) || 'Search batches';
		search.setAttribute( 'aria-label', search.placeholder );

		var list = document.createElement( 'div' );
		list.className = 'wcd-pick__list';

		var empty = document.createElement( 'p' );
		empty.className = 'wcd-pick__empty';
		empty.textContent = button.getAttribute( 'data-empty-label' ) || 'Nothing matches';
		empty.hidden = true;

		availableOptions( select ).forEach( function ( option ) {
			var item = document.createElement( 'button' );

			item.type = 'button';
			item.className = 'wcd-pick__item';
			item.value = option.value;
			item.textContent = option.getAttribute( 'data-label' ) || option.textContent;

			item.addEventListener( 'click', function () {
				select.value = option.value;
				select.dispatchEvent( new Event( 'change', { bubbles: true } ) );
				closePicker();
				button.focus();
			} );

			list.appendChild( item );
		} );

		function filter() {
			var needle = search.value.trim().toLowerCase();
			var shown = 0;

			Array.prototype.forEach.call( list.children, function ( item ) {
				var hit = ! needle || item.textContent.toLowerCase().indexOf( needle ) !== -1;

				item.hidden = ! hit;

				if ( hit ) {
					++shown;
				}
			} );

			empty.hidden = shown > 0;
		}

		search.addEventListener( 'input', filter );

		// Enter takes the first match, which is what a search box that has just
		// narrowed thirty rows to one should do.
		search.addEventListener( 'keydown', function ( event ) {
			if ( event.key !== 'Enter' ) {
				return;
			}

			event.preventDefault();

			var first = Array.prototype.find.call( list.children, function ( item ) {
				return ! item.hidden;
			} );

			if ( first ) {
				first.click();
			}
		} );

		pop.appendChild( search );
		pop.appendChild( list );
		pop.appendChild( empty );

		wrap.appendChild( pop );
		wrap.classList.add( 'is-open' );

		openPicker = { wrap: wrap, pop: pop };

		search.focus();
	}

	function bindPickers() {
		document.addEventListener( 'click', function ( event ) {
			var button = event.target.closest( '[data-wcd-pick]' );

			if ( ! button ) {
				// A click anywhere else closes an open one, unless it landed
				// inside the popup itself.
				if ( ! event.target.closest( '.wcd-pick' ) ) {
					closePicker();
				}

				return;
			}

			event.preventDefault();

			var wrap = button.closest( '[data-wcd-batches]' );
			var select = wrap ? wrap.querySelector( '[data-wcd-add]' ) : null;
			var wasOpen = openPicker && openPicker.wrap === wrap;

			closePicker();

			if ( ! select || wasOpen ) {
				return;
			}

			buildPicker( wrap, select, button );
		} );

		document.addEventListener( 'keydown', function ( event ) {
			if ( event.key === 'Escape' ) {
				closePicker();
			}
		} );

		// Only now does the select step back, so a page whose script never
		// arrives keeps a picker that works.
		Array.prototype.forEach.call(
			document.querySelectorAll( '[data-wcd-batches]' ),
			function ( wrap ) {
				wrap.classList.add( 'has-picker' );
			}
		);
	}

	function start() {
		bindBands();
		bindChips();
		bindPickers();

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
