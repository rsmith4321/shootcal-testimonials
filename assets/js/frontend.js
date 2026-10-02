/**
 * ShootCal Testimonials behaviour.
 *
 * Three responsibilities, all enhancement rather than requirement:
 *
 * 1. Mark each section as script-enabled, which turns on quote clamping and reveals
 *    the open control. Without this the full review is already on the page.
 * 2. Open and close the native dialog from the card, the open control or the close
 *    button, including a click on the backdrop.
 * 3. Reveal one row at a time for View more, with focus moved to the first newly
 *    revealed card and a live-region announcement.
 *
 * No request is made anywhere in this file. A visitor click cannot trigger a database
 * query or a call to a review provider.
 */
( function () {
	'use strict';

	function columnsFor( section ) {
		var columns = parseInt( section.getAttribute( 'data-sct-columns' ), 10 );
		return columns > 0 ? columns : 3;
	}

	/**
	 * Resolve the dialog belonging to one card.
	 *
	 * Dialogs are rendered as siblings of their card, never inside it, because the card
	 * clips overflow and gains a transform on hover, either of which would break the
	 * fixed positioning a modal dialog depends on. The authoritative link is the
	 * aria-controls id on the open control.
	 */
	function dialogFor( card ) {
		var opener = card.querySelector( '[data-sct-open]' );
		var id = opener ? opener.getAttribute( 'aria-controls' ) : null;

		return id ? document.getElementById( id ) : null;
	}

	function openDialog( card ) {
		var dialog = dialogFor( card );

		if ( dialog && typeof dialog.showModal === 'function' && ! dialog.open ) {
			dialog.showModal();
		}
	}

	function wireDialog( dialog ) {
		var close = dialog.querySelector( '[data-sct-close]' );

		if ( close ) {
			close.addEventListener( 'click', function () {
				dialog.close();
			} );
		}

		// A click landing on the dialog element itself rather than its inner wrapper
		// means the backdrop was clicked, since the inner box covers the dialog area.
		dialog.addEventListener( 'click', function ( event ) {
			if ( event.target === dialog ) {
				dialog.close();
			}
		} );
	}

	function wireCard( card ) {
		var opener = card.querySelector( '[data-sct-open]' );

		if ( opener ) {
			opener.addEventListener( 'click', function ( event ) {
				// Stop the card handler firing a second time for the same click.
				event.stopPropagation();
				openDialog( card );
			} );
		}

		// Mouse only. Keyboard and assistive technology use the open control, which is a
		// real button, so the card itself is deliberately not focusable.
		card.addEventListener( 'click', function ( event ) {
			// Never hijack a real link, such as the source attribution.
			if ( event.target.closest && event.target.closest( 'a, button' ) ) {
				return;
			}

			openDialog( card );
		} );
	}

	function wireMore( section ) {
		var button = section.querySelector( '[data-sct-more]' );

		if ( ! button ) {
			return;
		}

		var live = document.createElement( 'p' );
		live.className = 'sct-live';
		live.setAttribute( 'role', 'status' );
		live.setAttribute( 'aria-live', 'polite' );
		section.appendChild( live );

		button.addEventListener( 'click', function () {
			var hidden = section.querySelectorAll( '.sct-testimonial--hidden' );

			if ( ! hidden.length ) {
				return;
			}

			var reveal = Math.min( columnsFor( section ), hidden.length );
			var first = null;

			for ( var i = 0; i < reveal; i++ ) {
				hidden[ i ].classList.remove( 'sct-testimonial--hidden' );

				if ( ! first ) {
					first = hidden[ i ];
				}
			}

			var remaining = section.querySelectorAll( '.sct-testimonial--hidden' ).length;
			var total = section.querySelectorAll( '.sct-testimonial' ).length;

			live.textContent = ( total - remaining ) + ' of ' + total + ' testimonials shown';

			if ( first ) {
				first.setAttribute( 'tabindex', '-1' );
				first.focus( { preventScroll: false } );
			}

			if ( ! remaining ) {
				var wrap = button.parentNode;
				button.remove();

				if ( wrap && wrap.parentNode ) {
					wrap.remove();
				}
			}
		} );
	}

	function init( section ) {
		if ( section.getAttribute( 'data-sct-ready' ) ) {
			return;
		}

		section.setAttribute( 'data-sct-ready', '1' );
		section.classList.add( 'sct-js' );

		Array.prototype.forEach.call( section.querySelectorAll( '.sct-testimonial' ), wireCard );

		// Dialogs sit outside the section, so walk the controls to find and wire each
		// one exactly once even when several shortcodes share a page.
		Array.prototype.forEach.call( section.querySelectorAll( '[data-sct-open]' ), function ( opener ) {
			var id = opener.getAttribute( 'aria-controls' );
			var dialog = id ? document.getElementById( id ) : null;

			if ( dialog && ! dialog.getAttribute( 'data-sct-wired' ) ) {
				dialog.setAttribute( 'data-sct-wired', '1' );
				wireDialog( dialog );
			}
		} );

		wireMore( section );
	}

	function boot() {
		Array.prototype.forEach.call( document.querySelectorAll( '.sct-testimonials' ), init );
	}

	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', boot );
	} else {
		boot();
	}
}() );
