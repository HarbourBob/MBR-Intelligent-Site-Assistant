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

		return new Promise( function ( resolve, reject ) {
			var controller = new AbortController();
			var timeoutId  = window.setTimeout( function () {
				controller.abort();
			}, 30000 );

			fetch( config.restUrl, {
				method:  'POST',
				headers: {
					'Content-Type': 'application/json',
					'X-WP-Nonce':   config.nonce || ''
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
						var httpErr = new Error( 'HTTP ' + response.status );
						httpErr.code = 'http';
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
	 * @param {number} feedback -1, 0, or 1.
	 * @return {Promise}
	 */
	function sendFeedback( queryId, feedback ) {
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
					'Content-Type': 'application/json',
					'X-WP-Nonce':   config.nonce || ''
				},
				credentials: 'same-origin',
				body:        JSON.stringify( { query_id: queryId, feedback: feedback } ),
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

		this._bindEvents();
	}

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
			// ESC closes
			document.addEventListener( 'keydown', function ( e ) {
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

		// Message.
		if ( response.message ) {
			children.push( el( 'div', { className: 'mbr-isa-chat__bubble-msg', text: response.message } ) );
		}

		// Results list.
		if ( response.results && response.results.length ) {
			children.push( this._buildResultsList( response.results ) );
		}

		// Suggestions.
		if ( response.suggestions && response.suggestions.length ) {
			children.push( this._buildSuggestions( response.suggestions ) );
		}

		// Feedback controls — only for responses the server assigned a
		// query_id to (i.e. real search results or intent matches, not
		// empty-query greetings or error replies).
		if ( response.query_id ) {
			children.push( this._buildFeedback( response.query_id ) );
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
				rel:       'noopener noreferrer',
				text:      title
			} );

			var snippetNode = el( 'div', { className: 'mbr-isa-chat__result-snippet', html: snip } );

			items.push( el( 'li', { className: 'mbr-isa-chat__result' }, [ link, snippetNode ] ) );
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
	Controller.prototype._buildFeedback = function ( queryId ) {
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

		up.addEventListener( 'click', function () { self._submitFeedback( strip, queryId, 1 ); } );
		down.addEventListener( 'click', function () { self._submitFeedback( strip, queryId, -1 ); } );

		return strip;
	};

	/**
	 * POST feedback and swap the strip for a confirmation / error message.
	 */
	Controller.prototype._submitFeedback = function ( strip, queryId, value ) {
		if ( ! strip || strip.getAttribute( 'data-submitted' ) === '1' ) return;
		strip.setAttribute( 'data-submitted', '1' );
		strip.classList.add( 'is-submitting' );

		// Disable both buttons while in flight.
		var buttons = strip.querySelectorAll( 'button' );
		for ( var i = 0; i < buttons.length; i++ ) {
			buttons[ i ].disabled = true;
		}

		sendFeedback( queryId, value )
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
