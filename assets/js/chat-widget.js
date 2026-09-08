/**
 * MBR Intelligent Site Assistant — Chat Widget (vanilla JS).
 *
 * One controller per .mbr-isa-chat element on the page. Supports both
 * floating-bubble and inline modes. Session ID persists across page
 * loads via sessionStorage so a conversation survives navigation.
 *
 * Expected global: window.mbrAisa = { restUrl, nonce, strings }.
 */
( function () {
	'use strict';

	if ( typeof window === 'undefined' || typeof document === 'undefined' ) {
		return;
	}

	var config = window.mbrAisa;
	if ( ! config || ! config.restUrl ) {
		return;
	}

	var SESSION_STORAGE_KEY = 'mbrAisaSessionId';

	/* ---------------------------------------------------------------------
	 * Utilities
	 * ------------------------------------------------------------------- */

	/* ---------------------------------------------------------------------
	 * Lightbox
	 *
	 * A result's thumbnail and its title now lead to different places: the
	 * title to the page the image sits on, the thumbnail to the image itself.
	 * That is the point of having both — a visitor who searched for a picture
	 * usually wants to see the picture, and sending them to the page made the
	 * thumbnail a second copy of the title link.
	 *
	 * Built here rather than deferred to a lightbox library because the plugin
	 * ships no dependencies and should not start now, and because a host
	 * theme's own lightbox cannot be relied on to exist or to be scriptable.
	 * ------------------------------------------------------------------- */

	var lightbox = null;

	function lightboxIsOpen() {
		return !! ( lightbox && lightbox.root.classList.contains( 'is-open' ) );
	}

	/**
	 * Build the lightbox once, on first use, and reuse it thereafter.
	 */
	function buildLightbox() {
		if ( lightbox ) return lightbox;

		var s = ( config && config.strings ) || {};

		/*
		 * The theme variables are declared on .mbr-isa-chat and its
		 * --theme-* modifiers, not on :root. A lightbox appended to <body>
		 * therefore resolves none of them and would come out unstyled, so it
		 * carries the base class and whichever theme modifier the widget on
		 * this page is using.
		 *
		 * .mbr-isa-chat itself only sets typography and box-sizing — no
		 * layout — so taking it here is safe. The mode and position modifiers
		 * are deliberately not copied: those are position:fixed rules for the
		 * bubble, and would fight the overlay.
		 */
		var themeClasses = 'mbr-isa-chat';
		var widgetRoot   = document.querySelector( '.mbr-isa-chat' );

		if ( widgetRoot ) {
			var found = ( widgetRoot.className || '' ).split( /\s+/ ).filter( function ( c ) {
				return c.indexOf( 'mbr-isa-chat--theme-' ) === 0;
			} );
			if ( found.length ) themeClasses += ' ' + found.join( ' ' );
		}

		var img = el( 'img', { className: 'mbr-isa-lightbox__img', alt: '' } );

		var closeBtn = el( 'button', {
			className:    'mbr-isa-lightbox__close',
			type:         'button',
			'aria-label': s.lightboxClose || 'Close image preview'
		}, [ '\u00D7' ] );

		var caption  = el( 'p',  { className: 'mbr-isa-lightbox__alt' } );
		var pageLink = el( 'a',  {
			className: 'mbr-isa-lightbox__page',
			href:      '#',
			rel:       'noopener noreferrer'
		} );

		var figure = el( 'div', { className: 'mbr-isa-lightbox__inner' }, [
			closeBtn,
			img,
			el( 'div', { className: 'mbr-isa-lightbox__caption' }, [ caption, pageLink ] )
		] );

		var root = el( 'div', {
			className:    'mbr-isa-lightbox ' + themeClasses,
			role:         'dialog',
			'aria-modal': 'true',
			'aria-label': s.lightboxLabel || 'Image preview'
		}, [ figure ] );

		// A click anywhere that is not the picture or the caption closes it —
		// the behaviour people already expect from a lightbox.
		root.addEventListener( 'click', function ( e ) {
			if ( e.target === root ) closeLightbox();
		} );
		closeBtn.addEventListener( 'click', closeLightbox );

		// Focus trap. Only two things in here are focusable, so the cycle is
		// short, but without it Tab walks off into the page behind a dialog
		// that is covering it.
		root.addEventListener( 'keydown', function ( e ) {
			if ( e.key !== 'Tab' ) return;

			var focusable = [ closeBtn ];
			if ( pageLink.getAttribute( 'href' ) !== '#' ) focusable.push( pageLink );

			var first = focusable[ 0 ];
			var last  = focusable[ focusable.length - 1 ];

			if ( e.shiftKey && document.activeElement === first ) {
				e.preventDefault();
				last.focus();
			} else if ( ! e.shiftKey && document.activeElement === last ) {
				e.preventDefault();
				first.focus();
			}
		} );

		// Appended to the body, not to the widget. The chat panel is a fixed,
		// scrolling, overflow-hidden box; a lightbox inside it would be
		// clipped to the panel and could never be larger than the thing it
		// was launched from.
		document.body.appendChild( root );

		lightbox = {
			root: root, img: img, caption: caption,
			pageLink: pageLink, closeBtn: closeBtn,
			lastFocus: null, bodyOverflow: ''
		};

		return lightbox;
	}

	/**
	 * Show an image. `pageUrl` is optional — where it is absent the caption's
	 * link to the surrounding page is hidden rather than left pointing at '#'.
	 */
	function openLightbox( src, alt, pageUrl ) {
		var lb = buildLightbox();
		var s  = ( config && config.strings ) || {};

		lb.lastFocus = document.activeElement;

		lb.img.setAttribute( 'src', src );
		lb.img.setAttribute( 'alt', alt || '' );

		lb.caption.textContent = alt || '';
		lb.caption.style.display = alt ? '' : 'none';

		if ( pageUrl ) {
			lb.pageLink.setAttribute( 'href', pageUrl );
			lb.pageLink.setAttribute( 'target', '_blank' );
			lb.pageLink.textContent = s.lightboxViewOn || 'View the page this image appears on';
			lb.pageLink.style.display = '';
		} else {
			lb.pageLink.setAttribute( 'href', '#' );
			lb.pageLink.style.display = 'none';
		}

		// Hold the page still behind the overlay, and put it back exactly as
		// it was on close — a theme may well have its own value here.
		lb.bodyOverflow = document.body.style.overflow;
		document.body.style.overflow = 'hidden';

		lb.root.classList.add( 'is-open' );
		lb.closeBtn.focus();
	}

	function closeLightbox() {
		if ( ! lightbox ) return;

		lightbox.root.classList.remove( 'is-open' );
		document.body.style.overflow = lightbox.bodyOverflow;

		// Release the image so a large file is not held in memory once it is
		// off screen.
		lightbox.img.setAttribute( 'src', '' );

		if ( lightbox.lastFocus && lightbox.lastFocus.focus ) {
			lightbox.lastFocus.focus();
		}
		lightbox.lastFocus = null;
	}

	// Escape closes the lightbox. Registered here rather than on the
	// controller because the lightbox outlives any single panel, and it must
	// run ahead of the panel's own Escape handler — which checks
	// lightboxIsOpen() and stands down while this is up, so one press closes
	// one thing.
	document.addEventListener( 'keydown', function ( e ) {
		if ( e.key === 'Escape' && lightboxIsOpen() ) {
			e.stopPropagation();
			closeLightbox();
		}
	}, true );

	/**
	 * Wire a thumbnail link to the lightbox.
	 *
	 * A factory rather than an inline listener because the result loop is ES5
	 * and declares its locals with `var`, which is function-scoped: a handler
	 * closing over those variables directly reads whatever the final iteration
	 * left behind, so every thumbnail on the page would open the last result's
	 * image and link to the last result's page. Passing them as arguments
	 * gives each handler its own binding.
	 */
	function bindLightboxTrigger( link, src, alt, pageUrl ) {
		link.addEventListener( 'click', function ( e ) {
			// Leave modified clicks alone — those are deliberate requests for
			// a new tab or window, and hijacking them is the rudest thing a
			// lightbox can do.
			if ( e.metaKey || e.ctrlKey || e.shiftKey || e.altKey || e.button !== 0 ) {
				return;
			}
			e.preventDefault();
			openLightbox( src, alt, pageUrl );
		} );
	}

	/**
	 * Build a DOM element with attrs and children.
	 */
	function el( tag, attrs, children ) {
		var node = document.createElement( tag );
		if ( attrs ) {
			for ( var key in attrs ) {
				if ( ! Object.prototype.hasOwnProperty.call( attrs, key ) ) continue;
				if ( key === 'className' ) {
					node.className = attrs[ key ];
				} else if ( key === 'text' ) {
					node.textContent = attrs[ key ];
				} else if ( key === 'html' ) {
					node.innerHTML = attrs[ key ];
				} else {
					node.setAttribute( key, attrs[ key ] );
				}
			}
		}
		if ( children ) {
			for ( var i = 0; i < children.length; i++ ) {
				var child = children[ i ];
				if ( child === null || child === undefined ) continue;
				if ( typeof child === 'string' ) {
					node.appendChild( document.createTextNode( child ) );
				} else {
					node.appendChild( child );
				}
			}
		}
		return node;
	}

	/**
	 * Read the persisted session ID, or return null if none exists.
	 */
	function readSessionId() {
		if ( ! config.persistSession ) return null;
		try {
			var v = window.sessionStorage.getItem( SESSION_STORAGE_KEY );
			return v && typeof v === 'string' ? v : null;
		} catch ( e ) {
			return null;
		}
	}

	/**
	 * Persist a session ID to sessionStorage. Silently swallows errors
	 * (incognito Safari, disabled storage, etc.).
	 */
	function writeSessionId( id ) {
		// Only persisted when query logging is on. The identifier exists to
		// group a visitor's queries in that log; with no log to group, storing
		// anything on their device buys nothing and needs a consent it has not
		// been asked for.
		if ( ! config.persistSession ) return;
		if ( ! id || typeof id !== 'string' ) return;
		try {
			window.sessionStorage.setItem( SESSION_STORAGE_KEY, id );
		} catch ( e ) { /* noop */ }
	}

	/**
	 * Make the REST call.
	 *
	 * Returns a Promise that resolves to the parsed response or rejects
	 * with an Error whose `code` property hints at the failure mode:
	 *   'rate_limited' | 'http' | 'network' | 'parse'
	 */
	function askServer( query, sessionId ) {
		var payload = { query: query };
		if ( sessionId ) payload.session_id = sessionId;

	/*
	 * Why these requests carry no X-WP-Nonce header.
	 *
	 * Both endpoints are public and unauthenticated by design — a visitor
	 * asking a question is usually not logged in, and neither route performs a
	 * capability check. The nonce therefore authorises nothing. WordPress,
	 * however, does not treat it as optional: rest_cookie_check_errors()
	 * verifies any nonce a request supplies, whatever the login state, and
	 * rejects the whole request with 403 rest_cookie_invalid_nonce when it
	 * fails to verify.
	 *
	 * That is fatal on a page-cached site. The nonce is printed into the HTML
	 * by wp_localize_script(), so the cached copy carries whatever nonce was
	 * current when the page was written. Nonces for logged-out visitors expire
	 * after 24 hours, so once a cached page outlives that window every
	 * logged-out visitor posts a stale nonce and every query fails — while an
	 * administrator, whose pages are never cached, sees the widget working
	 * perfectly. Fixed in 0.8.7 by not sending it at all.
	 *
	 * config.nonce is still localised for backward compatibility with any
	 * custom front-end integration that reads it. Sending it is not advised,
	 * for the reason above.
	 */
		return new Promise( function ( resolve, reject ) {
			var controller = new AbortController();
			var timeoutId  = window.setTimeout( function () {
				controller.abort();
			}, 30000 );

			/*
			 * No X-WP-Nonce header — see the note above this function.
			 */
			fetch( config.restUrl, {
				method:  'POST',
				headers: {
					'Content-Type': 'application/json'
				},
				credentials: 'same-origin',
				body:        JSON.stringify( payload ),
				signal:      controller.signal
			} )
				.then( function ( response ) {
					window.clearTimeout( timeoutId );
					if ( response.status === 429 ) {
						var err = new Error( 'Rate limited' );
						err.code = 'rate_limited';
						throw err;
					}
					if ( ! response.ok ) {
						/*
						 * Surface the status in the console. A visitor sees the
						 * generic message either way, but a 401 (REST disabled
						 * for logged-out users) and a 403 (something rejecting
						 * the request before it reaches this plugin) need very
						 * different fixes, and the widget is often the only
						 * place that difference is visible.
						 */
						if ( window.console && window.console.warn ) {
							window.console.warn(
								'MBR ISA: /ask returned HTTP ' + response.status +
								'. See the troubleshooting chapter of the user guide.'
							);
						}
						var httpErr = new Error( 'HTTP ' + response.status );
						httpErr.code   = 'http';
						httpErr.status = response.status;
						throw httpErr;
					}
					return response.json();
				} )
				.then( function ( data ) {
					resolve( data );
				} )
				.catch( function ( error ) {
					window.clearTimeout( timeoutId );
					if ( ! error.code ) {
						error.code = 'network';
					}
					reject( error );
				} );
		} );
	}

	/**
	 * POST feedback to the /feedback endpoint.
	 *
	 * @param {number} queryId  Query log row ID.
	 * @param {string} token    Signed token issued alongside queryId by /ask.
	 * @param {number} feedback -1, 0, or 1.
	 * @return {Promise}
	 */
	function sendFeedback( queryId, token, feedback ) {
		if ( ! config.feedbackUrl ) {
			return Promise.reject( new Error( 'Feedback URL not configured' ) );
		}

		return new Promise( function ( resolve, reject ) {
			var controller = new AbortController();
			var timeoutId  = window.setTimeout( function () {
				controller.abort();
			}, 15000 );

			fetch( config.feedbackUrl, {
				method:  'POST',
				headers: {
					'Content-Type': 'application/json'
				},
				credentials: 'same-origin',
				body:        JSON.stringify( {
					query_id: queryId,
					token:    token || '',
					feedback: feedback
				} ),
				signal:      controller.signal
			} )
				.then( function ( response ) {
					window.clearTimeout( timeoutId );
					if ( response.status === 429 ) {
						var err = new Error( 'Rate limited' );
						err.code = 'rate_limited';
						throw err;
					}
					// 409 means this query already carries a rating. From 0.9.7
					// the endpoint accepts one rating per query, so the realistic
					// way to see this is a first request that succeeded on the
					// server but whose response never arrived — the retry would
					// then report a failure for something that worked. The rating
					// is recorded either way, so treat it as success rather than
					// putting the buttons back.
					if ( response.status === 409 ) {
						return { ok: true, already_recorded: true };
					}
					if ( ! response.ok ) {
						var httpErr = new Error( 'HTTP ' + response.status );
						httpErr.code = 'http';
						throw httpErr;
					}
					return response.json();
				} )
				.then( resolve )
				.catch( function ( error ) {
					window.clearTimeout( timeoutId );
					if ( ! error.code ) error.code = 'network';
					reject( error );
				} );
		} );
	}

	/* ---------------------------------------------------------------------
	 * Chat controller (one per widget instance)
	 * ------------------------------------------------------------------- */

	function Controller( root ) {
		this.root       = root;
		this.mode       = root.getAttribute( 'data-mbr-isa-mode' ) || 'inline';
		this.log        = root.querySelector( '.mbr-isa-chat__log' );
		this.form       = root.querySelector( '.mbr-isa-chat__form' );
		this.input      = root.querySelector( '.mbr-isa-chat__input' );
		this.send       = root.querySelector( '.mbr-isa-chat__send' );
		this.bubble     = root.querySelector( '.mbr-isa-chat__bubble' );
		this.closeBtn   = root.querySelector( '.mbr-isa-chat__close' );
		this.panel      = root.querySelector( '.mbr-isa-chat__panel' );
		this.busy       = false;
		this.sessionId  = readSessionId();

		// Safety — if any required node is missing, abort setup for this instance.
		if ( ! this.log || ! this.form || ! this.input ) {
			return;
		}

		this._protectScroll();
		this._bindEvents();
	}

	/**
	 * Keep the message log scrollable on sites running a smooth-scroll library.
	 *
	 * Lenis, Locomotive Scroll, GSAP ScrollSmoother and friends bind `wheel` on
	 * window, call preventDefault(), and drive the page by transform instead.
	 * That kills every nested scroller on the site unless it opts out, so the
	 * chat log stops responding to the wheel while still being scrollable by
	 * dragging its scrollbar or by keyboard.
	 *
	 * Two defences, because the libraries differ:
	 *
	 *   1. The opt-out attributes each library looks for. Harmless when absent.
	 *   2. stopPropagation on our own wheel listener, so a window-level handler
	 *      in the bubble phase never sees the event. We let it through at the
	 *      top and bottom of the log so the page still scrolls once the log has
	 *      nowhere left to go, which is what a visitor expects.
	 *
	 * Neither helps against a capture-phase handler, which is why the
	 * attributes go on as well.
	 */
	Controller.prototype._protectScroll = function () {
		var log = this.log;

		log.setAttribute( 'data-lenis-prevent', '' );
		log.setAttribute( 'data-scroll-ignore', '' );

		log.addEventListener( 'wheel', function ( e ) {
			var atTop    = log.scrollTop <= 0;
			var atBottom = log.scrollTop + log.clientHeight >= log.scrollHeight - 1;

			// Nothing to scroll — let the page have it.
			if ( log.scrollHeight <= log.clientHeight ) {
				return;
			}
			if ( ( e.deltaY < 0 && atTop ) || ( e.deltaY > 0 && atBottom ) ) {
				return;
			}

			e.stopPropagation();
		}, { passive: true } );
	};

	Controller.prototype._bindEvents = function () {
		var self = this;

		this.form.addEventListener( 'submit', function ( e ) {
			e.preventDefault();
			self._onSubmit();
		} );

		if ( this.mode === 'floating' ) {
			if ( this.bubble ) {
				this.bubble.addEventListener( 'click', function () { self._togglePanel(); } );
			}
			if ( this.closeBtn ) {
				this.closeBtn.addEventListener( 'click', function () { self._closePanel(); } );
			}
			// ESC closes — unless the lightbox is up, which takes the key
			// first and stands this handler down so one press closes one
			// thing rather than the picture and the panel together.
			document.addEventListener( 'keydown', function ( e ) {
				if ( lightboxIsOpen() ) return;
				if ( e.key === 'Escape' && self.root.classList.contains( 'is-open' ) ) {
					self._closePanel();
				}
			} );
		}
	};

	Controller.prototype._openPanel = function () {
		this.root.classList.add( 'is-open' );
		if ( this.bubble ) this.bubble.setAttribute( 'aria-expanded', 'true' );
		if ( this.panel )  this.panel.setAttribute( 'aria-hidden', 'false' );
		var self = this;
		// Defer focus until after transition so assistive tech picks up the open state
		window.setTimeout( function () {
			if ( self.input ) self.input.focus();
		}, 50 );
	};

	Controller.prototype._closePanel = function () {
		this.root.classList.remove( 'is-open' );
		if ( this.bubble ) {
			this.bubble.setAttribute( 'aria-expanded', 'false' );
			this.bubble.focus();
		}
		if ( this.panel ) this.panel.setAttribute( 'aria-hidden', 'true' );
	};

	Controller.prototype._togglePanel = function () {
		if ( this.root.classList.contains( 'is-open' ) ) {
			this._closePanel();
		} else {
			this._openPanel();
		}
	};

	Controller.prototype._onSubmit = function () {
		if ( this.busy ) return;

		var query = ( this.input.value || '' ).trim();
		if ( ! query ) {
			this._flashError( config.strings.emptyInput );
			return;
		}

		this._renderUserTurn( query );
		this.input.value = '';
		this._setBusy( true );
		var typingNode = this._renderTyping();

		var self = this;
		askServer( query, this.sessionId )
			.then( function ( response ) {
				self._removeNode( typingNode );
				self._handleResponse( response );
			} )
			.catch( function ( error ) {
				self._removeNode( typingNode );
				self._handleError( error );
			} )
			.then( function () {
				self._setBusy( false );
			} );
	};

	Controller.prototype._handleResponse = function ( response ) {
		if ( ! response || typeof response !== 'object' ) {
			this._handleError( { code: 'parse' } );
			return;
		}

		// Persist session ID for next turn.
		if ( response.session_id ) {
			this.sessionId = response.session_id;
			writeSessionId( response.session_id );
		}

		this._renderBotTurn( response );
	};

	Controller.prototype._handleError = function ( error ) {
		var msg;
		if ( error && error.code === 'rate_limited' ) {
			msg = config.strings.rateLimited;
		} else if ( error && error.code === 'network' ) {
			msg = config.strings.networkErr;
		} else {
			msg = config.strings.genericErr;
		}
		this._renderErrorTurn( msg );
	};

	Controller.prototype._setBusy = function ( busy ) {
		this.busy = busy;
		this.root.classList.toggle( 'is-busy', busy );
		this.input.disabled = busy;
		this.send.setAttribute( 'aria-disabled', busy ? 'true' : 'false' );
	};

	/* ---------------------------------------------------------------------
	 * Rendering
	 * ------------------------------------------------------------------- */

	Controller.prototype._scrollToBottom = function () {
		this.log.scrollTop = this.log.scrollHeight;
	};

	Controller.prototype._renderUserTurn = function ( text ) {
		var turn = el( 'div', { className: 'mbr-isa-chat__turn mbr-isa-chat__turn--user' }, [
			el( 'div', { className: 'mbr-isa-chat__bubble-msg', text: text } )
		] );
		this.log.appendChild( turn );
		this._scrollToBottom();
	};

	Controller.prototype._renderTyping = function () {
		var dots = el( 'div', { className: 'mbr-isa-chat__typing' }, [
			el( 'span', { className: 'mbr-isa-chat__typing-dot' } ),
			el( 'span', { className: 'mbr-isa-chat__typing-dot' } ),
			el( 'span', { className: 'mbr-isa-chat__typing-dot' } )
		] );
		var turn = el( 'div', { className: 'mbr-isa-chat__turn mbr-isa-chat__turn--bot' }, [ dots ] );
		this.log.appendChild( turn );
		this._scrollToBottom();
		return turn;
	};

	Controller.prototype._renderBotTurn = function ( response ) {
		var children = [];

		// Confidence / intent badge.
		var badge = this._buildBadge( response );
		if ( badge ) children.push( badge );

		// Message. Server may send message_html for intent responses
		// (already sanitised with wp_kses_post() server-side); otherwise
		// fall back to the plain-text message field.
		if ( response.message_html ) {
			children.push( el( 'div', { className: 'mbr-isa-chat__bubble-msg', html: response.message_html } ) );
		} else if ( response.message ) {
			children.push( el( 'div', { className: 'mbr-isa-chat__bubble-msg', text: response.message } ) );
		}

		// Results list. When the server sends a lead-in — as it does for the
		// extra hits offered beneath an intent's canned answer — show it first,
		// so the list reads as a follow-on rather than as part of the answer
		// above it. textContent, not html: this string is ours, but the results
		// path has no business rendering markup.
		if ( response.results && response.results.length ) {
			if ( response.results_intro ) {
				children.push( el( 'div', {
					className: 'mbr-isa-chat__results-intro',
					text: response.results_intro
				} ) );
			}
			children.push( this._buildResultsList( response.results ) );
		}

		// Suggestions.
		if ( response.suggestions && response.suggestions.length ) {
			children.push( this._buildSuggestions( response.suggestions ) );
		}

		// Feedback controls — only for responses the server assigned a
		// query_id to (i.e. real search results or intent matches, not
		// empty-query greetings or error replies).
		// Both are required: the endpoint rejects a rating without a valid
		// token, so offering the buttons without one would only produce a
		// guaranteed failure.
		if ( response.query_id && response.feedback_token ) {
			children.push( this._buildFeedback( response.query_id, response.feedback_token ) );
		}

		var turn = el( 'div', { className: 'mbr-isa-chat__turn mbr-isa-chat__turn--bot' }, children );
		this.log.appendChild( turn );
		this._scrollToBottom();
	};

	Controller.prototype._renderErrorTurn = function ( message ) {
		var turn = el( 'div', { className: 'mbr-isa-chat__turn mbr-isa-chat__turn--bot' }, [
			el( 'div', { className: 'mbr-isa-chat__bubble-msg', text: message } )
		] );
		this.log.appendChild( turn );
		this._scrollToBottom();
	};

	Controller.prototype._buildBadge = function ( response ) {
		if ( response.type === 'intent' && response.intent_id ) {
			return el( 'span', {
				className: 'mbr-isa-chat__badge mbr-isa-chat__badge--intent',
				text: 'intent'
			} );
		}
		if ( response.confidence ) {
			var cls = 'mbr-isa-chat__badge mbr-isa-chat__badge--' + response.confidence;
			return el( 'span', {
				className: cls,
				text: response.confidence
			} );
		}
		return null;
	};

	Controller.prototype._buildResultsList = function ( results ) {
		var items = [];
		for ( var i = 0; i < results.length; i++ ) {
			var r = results[ i ] || {};
			var title = ( r.title || '' ).toString();
			var url   = ( r.url   || '#' ).toString();
			var snip  = ( r.snippet || '' ).toString();

			// The snippet from the server is already HTML-escaped with only
			// <mark> injected by the responder. It is safe to set as
			// innerHTML. The title and URL are set via text/href to be
			// defensive against any other injection surface.
			var link = el( 'a', {
				className: 'mbr-isa-chat__result-title',
				href:      url,
				target:    '_blank',
				rel:       'noopener noreferrer'
			} );

			/*
			 * A short label ahead of the title, marking what kind of thing the
			 * result is. Only images carry one at present.
			 *
			 * Inside the link rather than beside it, so it reads as part of
			 * the result's name to a screen reader rather than as a stray word
			 * before it — "IMAGE, Grimsby Docks Sunset" is what somebody
			 * listening to this needs to hear.
			 */
			if ( r.kind_label ) {
				link.appendChild( el( 'span', {
					className: 'mbr-isa-chat__result-kind',
					text:      ( r.kind_label || '' ).toString()
				} ) );
			}

			link.appendChild( document.createTextNode( title ) );

			var snippetNode = el( 'div', { className: 'mbr-isa-chat__result-snippet', html: snip } );

			var children = [ link, snippetNode ];

			/*
			 * Further mentions in the same document.
			 *
			 * A document gets one result, so a term appearing three times in
			 * one PDF used to be reachable only at its best-scoring passage.
			 * These are the others, each with its own deep link.
			 *
			 * Rendered as a list beneath the snippet rather than as separate
			 * results, so the reading stays "this document, and here is where
			 * else it comes up" rather than the same title three times over.
			 * A PDF mention carries its page number; anything else leads with
			 * its snippet, which is the only context it has.
			 */
			var more = ( r.more || [] );

			if ( more.length ) {
				var moreItems = [];

				for ( var m = 0; m < more.length; m++ ) {
					var entry = more[ m ] || {};

					var moreLink = el( 'a', {
						className: 'mbr-isa-chat__result-more-link',
						href:      ( entry.url || '#' ).toString(),
						target:    '_blank',
						rel:       'noopener noreferrer'
					} );

					if ( entry.label ) {
						moreLink.appendChild( el( 'span', {
							className: 'mbr-isa-chat__result-more-label',
							text:      ( entry.label || '' ).toString()
						} ) );
					}

					// Server-escaped with only <mark> injected, as above.
					moreLink.appendChild( el( 'span', {
						className: 'mbr-isa-chat__result-more-snippet',
						html:      ( entry.snippet || '' ).toString()
					} ) );

					moreItems.push( el( 'li', { className: 'mbr-isa-chat__result-more-item' }, [ moreLink ] ) );
				}

				children.push( el( 'ul', {
					className:    'mbr-isa-chat__result-more',
					'aria-label': ( ( config && config.strings && config.strings.moreMentions )
					                || 'More mentions in this document' )
				}, moreItems ) );
			}
			var liClass  = 'mbr-isa-chat__result';

			/*
			 * Image results lead with the picture.
			 *
			 * An image's snippet is its alt text, which is one short line —
			 * as a text result it reads like an empty page. Showing the
			 * thumbnail puts the actual answer first and the description
			 * under it, which is the right way round for something visual.
			 *
			 * The src comes from wp_get_attachment_image_src() server-side,
			 * so it is a URL this site generated. Set via setAttribute rather
			 * than innerHTML, like every other attribute here.
			 */
			if ( r.doc_kind === 'image' && r.thumbnail ) {
				var thumb = el( 'img', {
					className: 'mbr-isa-chat__result-thumb',
					src:       ( r.thumbnail || '' ).toString(),
					alt:       ( r.image_alt || '' ).toString(),
					loading:   'lazy',
					decoding:  'async'
				} );

				// Intrinsic dimensions so the layout does not jump as each
				// thumbnail loads. Omitted when the server could not resolve
				// them, which is better than guessing an aspect ratio.
				if ( r.thumb_w && r.thumb_h ) {
					thumb.setAttribute( 'width',  String( r.thumb_w ) );
					thumb.setAttribute( 'height', String( r.thumb_h ) );
				}

				/*
				 * The thumbnail opens the image; the title opens the page.
				 *
				 * Kept as a real anchor pointing at the image file rather than
				 * a button, so it degrades honestly: middle-click and
				 * open-in-new-tab do the sensible thing, and if the script
				 * fails the link still reaches the picture. The click handler
				 * intercepts an ordinary left click only.
				 *
				 * No longer aria-hidden. It was decorative while it duplicated
				 * the title link's destination; now that it goes somewhere
				 * else it needs its own name in the accessibility tree, or a
				 * screen reader user has no way to reach the image at all.
				 */
				var fullSrc = ( r.image_full || r.thumbnail || '' ).toString();
				var altText = ( r.image_alt || '' ).toString();
				var strings = ( config && config.strings ) || {};

				var thumbLink = el( 'a', {
					className:    'mbr-isa-chat__result-thumb-link',
					href:         fullSrc,
					'aria-label': ( strings.lightboxOpen || 'Open larger preview' )
					              + ( altText ? ': ' + altText : '' )
				}, [ thumb ] );

				bindLightboxTrigger( thumbLink, fullSrc, altText, url );

				children  = [ thumbLink, link, snippetNode ].concat( children.slice( 2 ) );
				liClass  += ' mbr-isa-chat__result--image';
			}

			items.push( el( 'li', { className: liClass }, children ) );
		}
		return el( 'ul', { className: 'mbr-isa-chat__results' }, items );
	};

	Controller.prototype._buildSuggestions = function ( suggestions ) {
		var items = [];
		for ( var i = 0; i < suggestions.length; i++ ) {
			items.push( el( 'li', { text: ( suggestions[ i ] || '' ).toString() } ) );
		}
		return el( 'div', { className: 'mbr-isa-chat__suggestions' }, [
			el( 'span', { className: 'mbr-isa-chat__suggestions-label', text: config.strings.suggestLabel } ),
			el( 'ul', null, items )
		] );
	};

	/**
	 * Build a thumbs-up / thumbs-down strip bound to a query ID.
	 * Replaces itself with a "thanks" message on success.
	 */
	Controller.prototype._buildFeedback = function ( queryId, token ) {
		var self = this;

		var prompt = el( 'span', {
			className: 'mbr-isa-chat__feedback-prompt',
			text: config.strings.feedbackPrompt
		} );

		var up = el( 'button', {
			type: 'button',
			className: 'mbr-isa-chat__feedback-btn',
			'aria-label': config.strings.feedbackYes,
			title: config.strings.feedbackYes
		}, [
			// Thumbs-up glyph
			(function () {
				var svg = document.createElementNS( 'http://www.w3.org/2000/svg', 'svg' );
				svg.setAttribute( 'viewBox', '0 0 24 24' );
				svg.setAttribute( 'width', '14' );
				svg.setAttribute( 'height', '14' );
				svg.setAttribute( 'aria-hidden', 'true' );
				svg.setAttribute( 'focusable', 'false' );
				var path = document.createElementNS( 'http://www.w3.org/2000/svg', 'path' );
				path.setAttribute( 'fill', 'currentColor' );
				path.setAttribute( 'd', 'M1 21h4V9H1v12zm22-11c0-1.1-.9-2-2-2h-6.31l.95-4.57.03-.32c0-.41-.17-.79-.44-1.06L14.17 1 7.59 7.59C7.22 7.95 7 8.45 7 9v10c0 1.1.9 2 2 2h9c.83 0 1.54-.5 1.84-1.22l3.02-7.05c.09-.23.14-.47.14-.73v-2z' );
				svg.appendChild( path );
				return svg;
			})()
		] );

		var down = el( 'button', {
			type: 'button',
			className: 'mbr-isa-chat__feedback-btn',
			'aria-label': config.strings.feedbackNo,
			title: config.strings.feedbackNo
		}, [
			(function () {
				var svg = document.createElementNS( 'http://www.w3.org/2000/svg', 'svg' );
				svg.setAttribute( 'viewBox', '0 0 24 24' );
				svg.setAttribute( 'width', '14' );
				svg.setAttribute( 'height', '14' );
				svg.setAttribute( 'aria-hidden', 'true' );
				svg.setAttribute( 'focusable', 'false' );
				var path = document.createElementNS( 'http://www.w3.org/2000/svg', 'path' );
				path.setAttribute( 'fill', 'currentColor' );
				path.setAttribute( 'd', 'M15 3H6c-.83 0-1.54.5-1.84 1.22l-3.02 7.05c-.09.23-.14.47-.14.73v2c0 1.1.9 2 2 2h6.31l-.95 4.57-.03.32c0 .41.17.79.44 1.06L9.83 23l6.58-6.59c.37-.36.59-.86.59-1.41V5c0-1.1-.9-2-2-2zm4 0v12h4V3h-4z' );
				svg.appendChild( path );
				return svg;
			})()
		] );

		var strip = el( 'div', {
			className: 'mbr-isa-chat__feedback',
			'data-query-id': String( queryId )
		}, [ prompt, up, down ] );

		up.addEventListener( 'click', function () { self._submitFeedback( strip, queryId, token, 1 ); } );
		down.addEventListener( 'click', function () { self._submitFeedback( strip, queryId, token, -1 ); } );

		return strip;
	};

	/**
	 * POST feedback and swap the strip for a confirmation / error message.
	 */
	Controller.prototype._submitFeedback = function ( strip, queryId, token, value ) {
		if ( ! strip || strip.getAttribute( 'data-submitted' ) === '1' ) return;
		strip.setAttribute( 'data-submitted', '1' );
		strip.classList.add( 'is-submitting' );

		// Disable both buttons while in flight.
		var buttons = strip.querySelectorAll( 'button' );
		for ( var i = 0; i < buttons.length; i++ ) {
			buttons[ i ].disabled = true;
		}

		sendFeedback( queryId, token, value )
			.then( function () {
				// Replace the strip with a thanks message.
				var thanks = el( 'div', {
					className: 'mbr-isa-chat__feedback mbr-isa-chat__feedback--done',
					text: config.strings.feedbackThanks
				} );
				if ( strip.parentNode ) {
					strip.parentNode.replaceChild( thanks, strip );
				}
			} )
			.catch( function () {
				// Re-enable so they can try again, and leave them be.
				strip.classList.remove( 'is-submitting' );
				strip.removeAttribute( 'data-submitted' );
				for ( var j = 0; j < buttons.length; j++ ) {
					buttons[ j ].disabled = false;
				}
			} );
	};

	Controller.prototype._flashError = function ( message ) {
		var banner = el( 'div', { className: 'mbr-isa-chat__error', text: message } );
		this.log.appendChild( banner );
		this._scrollToBottom();
		window.setTimeout( function () {
			if ( banner.parentNode ) banner.parentNode.removeChild( banner );
		}, 2500 );
	};

	Controller.prototype._removeNode = function ( node ) {
		if ( node && node.parentNode ) {
			node.parentNode.removeChild( node );
		}
	};

	/* ---------------------------------------------------------------------
	 * Boot
	 * ------------------------------------------------------------------- */

	function init() {
		var roots = document.querySelectorAll( '.mbr-isa-chat' );
		for ( var i = 0; i < roots.length; i++ ) {
			if ( roots[ i ].getAttribute( 'data-mbr-isa-inited' ) === '1' ) continue;
			roots[ i ].setAttribute( 'data-mbr-isa-inited', '1' );
			new Controller( roots[ i ] );
		}
	}

	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', init );
	} else {
		init();
	}

	// Expose a minimal API for debugging / manual re-init.
	window.MbrAisaChat = { init: init };
} )();
