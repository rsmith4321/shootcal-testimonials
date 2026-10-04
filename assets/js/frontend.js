/**
 * ShootCal Testimonials behaviour.
 *
 * Three responsibilities, all enhancement rather than requirement:
 *
 * 1. Mark each section as script-enabled, which turns on quote clamping and reveals
 *    the open control. Without this the full review is already on the page.
 * 2. Open the locally bundled PhotoSwipe module on demand, retaining the native dialog
 *    as a failure fallback.
 * 3. Reveal at least nine reviews per batch while scrolling or using View more, then
 *    fetch the next capped public WordPress page. Keyboard activation moves focus.
 * 4. Open a dialog-mode submission form from its trigger button.
 *
 * The lightbox reads loaded markup. Category and page links use normal same-site
 * navigation; no interaction calls a review provider.
 */
( function () {
	'use strict';

	function columnsFor( section ) {
		var grid = section.querySelector( '.sct-testimonials__grid' );
		var columns = grid ? getComputedStyle( grid ).gridTemplateColumns.split( /\s+/ ).length : 1;
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

	/**
	 * Copy the quote and photo into a dialog the first time it opens.
	 *
	 * Both are omitted from the server-rendered dialog so the complete review text and the
	 * image each appear in the HTML exactly once rather than twice. Populating lazily keeps
	 * the initial payload down on pages with many testimonials.
	 */
	function populateDialog( card, dialog ) {
		if ( dialog.getAttribute( 'data-sct-filled' ) ) {
			return;
		}

		dialog.setAttribute( 'data-sct-filled', '1' );

		var quoteTarget = dialog.querySelector( '[data-sct-dialog-quote]' );
		var quoteSource = card.querySelector( '[data-sct-quote]' );

		if ( quoteTarget && quoteSource ) {
			quoteTarget.textContent = quoteSource.textContent;
		}

		var mediaTarget = dialog.querySelector( '[data-sct-dialog-media]' );
		var mediaSource = card.querySelector( '[data-sct-media] img' );

		if ( mediaTarget && mediaSource ) {
			var image = mediaSource.cloneNode( true );

			// The card lazily loads its photo; inside a modal it is the focal element.
			image.removeAttribute( 'loading' );
			mediaTarget.appendChild( image );
		}
	}

	function openDialog( card ) {
		var dialog = dialogFor( card );

		if ( dialog && typeof dialog.showModal === 'function' && ! dialog.open ) {
			populateDialog( card, dialog );
			dialog.sctReturnFocus = card.querySelector( '[data-sct-open]' );
			dialog.showModal();
			var content = dialog.querySelector( '.sct-dialog__inner' );
			if ( content ) { content.focus( { preventScroll: true } ); }
		}
	}

	function wireDialog( dialog ) {
		dialog.addEventListener( 'close', function () { if ( dialog.sctReturnFocus ) { dialog.sctReturnFocus.focus(); } } );
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

	var photoSwipeModule;

	/** Load the locally bundled PhotoSwipe core only when a review is opened. */
	function photoSwipe() {
		if ( ! photoSwipeModule ) {
			photoSwipeModule = import( ( window.sctFrontend || {} ).photoSwipeUrl ).then( function ( module ) {
				return module.default;
			} );
		}
		return photoSwipeModule;
	}

	function slideMarkup( card ) {
		var dialog = dialogFor( card );
		if ( ! dialog ) { return ''; }
		populateDialog( card, dialog );
		var inner = dialog.querySelector( '.sct-dialog__inner' ).cloneNode( true );
		inner.setAttribute( 'tabindex', '0' );
		var close = inner.querySelector( '[data-sct-close]' );
		if ( close ) { close.remove(); }
		var image = inner.querySelector( '[data-sct-dialog-media] img' );
		if ( image && card.getAttribute( 'data-sct-photo' ) ) {
			image.removeAttribute( 'srcset' );
			image.removeAttribute( 'sizes' );
			image.src = card.getAttribute( 'data-sct-photo' );
		}
		var slide = document.createElement( 'article' );
		slide.className = 'sct-review-slide';
		slide.setAttribute( 'tabindex', '0' );
		var heading = inner.querySelector( '.sct-dialog__heading' );
		if ( heading ) { slide.setAttribute( 'aria-label', heading.textContent.trim() ); heading.removeAttribute( 'id' ); }
		slide.appendChild( inner );
		return slide.outerHTML;
	}

	function continuationSlide( section ) {
		var next = section.querySelector( '[data-sct-next]' );
		if ( ! next ) { return null; }
		var wrapper = document.createElement( 'div' );
		wrapper.className = 'sct-review-continue';
		var title = document.createElement( 'h2' );
		title.textContent = ( window.sctFrontend || {} ).moreReviews || 'More reviews are available';
		var link = document.createElement( 'a' );
		link.href = next.href;
		link.textContent = ( window.sctFrontend || {} ).continueReviews || 'Continue to the next reviews';
		wrapper.appendChild( title );
		wrapper.appendChild( link );
		return { html: wrapper.outerHTML };
	}

	function openReview( card ) {
		var section = card.closest( '.sct-testimonials' );
		if ( ! section || section.getAttribute( 'data-sct-opening' ) ) { return; }
		section.setAttribute( 'data-sct-opening', '1' );
		var opener = card.querySelector( '[data-sct-open]' );
		photoSwipe().then( function ( PhotoSwipe ) {
			var cards = Array.prototype.slice.call( section.querySelectorAll( '[data-sct-card]' ) );
			var slides = cards.map( function ( item ) { return { html: slideMarkup( item ) }; } );
			var next = continuationSlide( section );
			if ( next ) { slides.push( next ); }
			var gallery = new PhotoSwipe( {
				dataSource: slides,
				index: cards.indexOf( card ),
				mainClass: 'sct-pswp',
				loop: false,
				showHideAnimationType: 'none',
				closeOnVerticalDrag: false,
				bgOpacity: 0.94
			} );
			gallery.addFilter( 'preventPointerEvent', function ( prevent, event, pointerType ) {
				var target = event.target;
				if ( pointerType === 'move' && event.type !== 'mousemove' && target && target.closest && target.closest( '.sct-review-slide .sct-dialog__inner' ) ) {
					return false;
				}
				return prevent;
			} );
			gallery.on( 'wheel', function ( event ) {
				var original = event.originalEvent;
				var target = original.target;
				var scroller = target && target.closest && target.closest( '.sct-review-slide .sct-dialog__inner' );
				if ( scroller && ! original.ctrlKey ) {
					scroller.scrollTop += original.deltaY * ( original.deltaMode === 1 ? 16 : 1 );
					event.preventDefault();
				}
			} );
			gallery.on( 'change', function () {
				if ( section.sctRevealer ) { section.sctRevealer.revealThrough( gallery.currIndex ); }
			} );
			gallery.on( 'destroy', function () {
				if ( opener && opener.isConnected ) { opener.focus(); }
			} );
			gallery.init();
		} ).catch( function () {
			// A native review dialog remains usable if module loading is unavailable.
			openDialog( card );
		} ).finally( function () { section.removeAttribute( 'data-sct-opening' ); } );
	}

	function wireCard( card ) {
		var opener = card.querySelector( '[data-sct-open]' );

		if ( opener ) {
			opener.addEventListener( 'click', function ( event ) {
				// Stop the card handler firing a second time for the same click.
				event.stopPropagation();
				openReview( card );
			} );
		}

		// Mouse only. Keyboard and assistive technology use the open control, which is a
		// real button, so the card itself is deliberately not focusable.
		card.addEventListener( 'click', function ( event ) {
			// Never hijack a real link, such as the source attribution.
			if ( event.target.closest && event.target.closest( 'a, button' ) ) {
				return;
			}

			openReview( card );
		} );
	}

	/**
	 * Open dialog-mode submission forms from their trigger.
	 *
	 * The trigger is a plain link to the no-script rendering of the same form, so
	 * preventing the default only upgrades a navigation that already works into a modal.
	 * Close button and backdrop come from wireDialog, shared with the review dialogs.
	 */
	function wireFormDialogs() {
		Array.prototype.forEach.call( document.querySelectorAll( '[data-sct-form-trigger]' ), function ( trigger ) {
			var dialog = document.getElementById( trigger.getAttribute( 'data-sct-form-trigger' ) );

			if ( ! dialog || typeof dialog.showModal !== 'function' ) {
				return;
			}

			if ( ! dialog.getAttribute( 'data-sct-wired' ) ) {
				dialog.setAttribute( 'data-sct-wired', '1' );
				wireDialog( dialog );
			}

			trigger.addEventListener( 'click', function ( event ) {
				event.preventDefault();

				if ( ! dialog.open ) {
					dialog.sctReturnFocus = trigger;
					dialog.showModal();
				}

				// The honeypot is the first input in the form but is off-screen and out of
				// the tab order, so it must not receive the opening focus.
				var first = dialog.querySelector( '.sct-form input:not( [tabindex="-1"] ):not( [type="hidden"] ), .sct-form select, .sct-form textarea' );

				if ( first ) {
					first.focus();
				}
			} );
		} );
	}

	function wireMore( section ) {
		var button = section.querySelector( '[data-sct-more]' );
		if ( ! button ) { return; }
		var wrap = button.parentNode;
		var grid = section.querySelector( '.sct-testimonials__grid' );
		var observer;
		var loading = false;
		var failed = false;
		var advancing = false;

		var live = document.createElement( 'p' );
		live.className = 'sct-live';
		live.setAttribute( 'role', 'status' );
		live.setAttribute( 'aria-live', 'polite' );
		section.appendChild( live );

		function announce() {
			var remaining = section.querySelectorAll( '.sct-testimonial--hidden' ).length;
			var loaded = section.querySelectorAll( '[data-sct-card]' ).length;
			var total = Number( section.getAttribute( 'data-sct-total' ) ) || loaded;
			var shown = loaded - remaining;
			var messages = window.sctFrontend || {};
			var template = shown === 1 ? messages.shownSingular : messages.shownPlural;
			live.textContent = ( template || '%shown% of %total% testimonials shown' )
				.replace( '%shown%', shown ).replace( '%total%', total );
			if ( ! remaining && ! section.querySelector( '[data-sct-next]' ) ) {
				if ( observer ) { observer.disconnect(); }
				wrap.remove();
			}
		}

		function revealBatch( focus ) {
			var hidden = section.querySelectorAll( '.sct-testimonial--hidden' );
			if ( ! hidden.length ) { return false; }

			var reveal = Math.min( Math.ceil( 9 / columnsFor( section ) ) * columnsFor( section ), hidden.length );
			for ( var i = 0; i < reveal; i++ ) {
				hidden[ i ].classList.remove( 'sct-testimonial--hidden' );
			}
			announce();
			if ( focus ) { hidden[ 0 ].setAttribute( 'tabindex', '-1' ); hidden[ 0 ].focus(); }
			return true;
		}

		function fetchPage() {
			var next = section.querySelector( '[data-sct-next]' );
			if ( ! next || loading ) { return Promise.resolve( false ); }
			loading = true;
			button.disabled = true;
			live.textContent = ( window.sctFrontend || {} ).loadingReviews || 'Loading more reviews…';
			return fetch( next.href, { credentials: 'omit', headers: { Accept: 'text/html' } } ).then( function ( response ) {
				if ( ! response.ok ) { throw new Error( 'Review page unavailable' ); }
				return response.text();
			} ).then( function ( html ) {
				var page = new DOMParser().parseFromString( html, 'text/html' );
				var incoming = page.getElementById( section.id );
				if ( ! incoming ) { throw new Error( 'Review section missing' ); }
				var known = {};
				Array.prototype.forEach.call( grid.querySelectorAll( '[data-sct-post]' ), function ( card ) { known[ card.getAttribute( 'data-sct-post' ) ] = true; } );
				var added = 0;
				Array.prototype.forEach.call( incoming.querySelectorAll( '[data-sct-card]' ), function ( card ) {
					if ( known[ card.getAttribute( 'data-sct-post' ) ] ) { return; }
					known[ card.getAttribute( 'data-sct-post' ) ] = true;
					card.classList.add( 'sct-testimonial--hidden' );
					grid.appendChild( card );
					wireCard( card );
					var dialog = page.getElementById( card.querySelector( '[data-sct-open]' ).getAttribute( 'aria-controls' ) );
					if ( dialog ) { section.appendChild( dialog ); wireDialog( dialog ); }
					added++;
				} );
				var following = incoming.querySelector( '[data-sct-next]' );
				if ( following ) { next.href = following.href; } else { next.remove(); }
				var status = section.querySelector( '.sct-pages__status' );
				var incomingStatus = incoming.querySelector( '.sct-pages__status' );
				if ( status && incomingStatus ) { status.textContent = incomingStatus.textContent; }
				section.setAttribute( 'data-sct-total', incoming.getAttribute( 'data-sct-total' ) || section.getAttribute( 'data-sct-total' ) );
				if ( ! added && following ) { throw new Error( 'Review page contained no new cards' ); }
				failed = false;
				announce();
				return added > 0;
			} ).catch( function () {
				failed = true;
				live.textContent = ( window.sctFrontend || {} ).loadFailed || 'More reviews could not be loaded. Use Next reviews to continue.';
				return false;
			} ).finally( function () { loading = false; button.disabled = false; } );
		}

		function advance( focus ) {
			if ( revealBatch( focus ) ) { return Promise.resolve( true ); }
			return fetchPage().then( function ( added ) { return added ? revealBatch( focus ) : false; } );
		}

		button.addEventListener( 'click', function () { advance( true ); } );
		section.sctRevealer = {
			revealThrough: function ( index ) {
				var cards = section.querySelectorAll( '[data-sct-card]' );
				while ( cards[ index ] && cards[ index ].classList.contains( 'sct-testimonial--hidden' ) ) { revealBatch( false ); }
			}
		};
		function pump() {
			if ( ! wrap.isConnected || loading || failed || advancing ) { return; }
			if ( wrap.getBoundingClientRect().top > window.innerHeight + 240 ) { return; }
			advancing = true;
			if ( observer ) { observer.unobserve( wrap ); }
			advance( false ).then( function ( changed ) {
				window.setTimeout( function () {
					advancing = false;
					if ( ! wrap.isConnected || failed ) { return; }
					if ( observer ) { observer.observe( wrap ); }
					if ( changed ) { pump(); }
				}, 120 );
			} );
		}
		if ( 'IntersectionObserver' in window ) {
			observer = new IntersectionObserver( function ( entries ) {
				if ( entries[ 0 ].isIntersecting ) { pump(); }
			}, { rootMargin: '240px 0px' } );
			observer.observe( wrap );
		}
		window.addEventListener( 'scroll', pump, { passive: true } );
		window.addEventListener( 'resize', pump );
		pump();
	}

	function init( section ) {
		if ( section.getAttribute( 'data-sct-ready' ) ) {
			return;
		}

		section.setAttribute( 'data-sct-ready', '1' );
		if ( typeof HTMLDialogElement === 'undefined' || typeof HTMLDialogElement.prototype.showModal !== 'function' ) {
			return;
		}
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
		// Form dialogs live on pages with or without a testimonial list, so they are
		// wired independently of the section walk below.
		wireFormDialogs();
		Array.prototype.forEach.call( document.querySelectorAll( '.sct-filter__dropdown' ), function ( dropdown ) {
			var summary = dropdown.querySelector( 'summary' );
			dropdown.addEventListener( 'keydown', function ( event ) {
				if ( event.key === 'Escape' && dropdown.open ) { dropdown.open = false; summary.focus(); event.preventDefault(); }
			} );
			document.addEventListener( 'click', function ( event ) { if ( ! dropdown.contains( event.target ) ) { dropdown.open = false; } } );
			dropdown.addEventListener( 'focusout', function ( event ) { if ( ! dropdown.contains( event.relatedTarget ) ) { dropdown.open = false; } } );
		} );

		Array.prototype.forEach.call( document.querySelectorAll( '.sct-testimonials' ), init );
	}

	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', boot );
	} else {
		boot();
	}
}() );
