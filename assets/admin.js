/**
 * Polls the progress of a running scan and reloads the screen when it ends.
 *
 * The script holds no sentences: the server returns the text to show, already taken from
 * the shared strings, and it is written with textContent so nothing in it is ever parsed
 * as markup. It starts at 5 seconds, backs off toward 15, stops when the scan is done or
 * failed, and stops while the tab is hidden, because a hidden tab that polls every few
 * seconds all night is the load this plugin would otherwise put on the API.
 */
( function () {
	'use strict';

	// A second click on any button of the plugin's forms, before the page has navigated,
	// would post the same action twice. The server refuses to spend twice, and this keeps
	// the request from being made.
	document.addEventListener( 'submit', function ( event ) {
		var button = event.target && event.target.querySelector ? event.target.querySelector( 'button[type="submit"]' ) : null;
		if ( button ) {
			window.setTimeout( function () {
				button.disabled = true;
			}, 0 );
		}
	} );

	var config = window.readinessIndex;
	var root = document.querySelector( '[data-ri-poll]' );
	if ( ! config || ! root || ! window.fetch || ! window.URLSearchParams ) {
		return;
	}

	var delay = config.minMs;
	var timer = null;
	var busy = false;
	var stopped = false;

	function setText( id, text ) {
		var node = document.getElementById( id );
		if ( node && text ) {
			node.textContent = text;
		}
	}

	function schedule( ms ) {
		window.clearTimeout( timer );
		if ( stopped || document.hidden ) {
			return;
		}
		timer = window.setTimeout( poll, ms );
	}

	function backOff() {
		delay = Math.min( config.maxMs, Math.round( delay * 1.5 ) );
		schedule( delay );
	}

	function poll() {
		if ( busy || stopped || document.hidden ) {
			return;
		}
		busy = true;

		var body = new window.URLSearchParams();
		body.set( 'action', config.action );
		body.set( 'nonce', config.nonce );

		window.fetch( config.ajaxUrl, { method: 'POST', credentials: 'same-origin', body: body } )
			.then( function ( response ) {
				return response.json();
			} )
			.then( function ( json ) {
				busy = false;
				if ( ! json || ! json.success || ! json.data ) {
					backOff();
					return;
				}
				var data = json.data;
				setText( 'ri-state-title', data.title );
				setText( 'ri-state-body', data.body );
				if ( data.reload ) {
					stopped = true;
					window.location.reload();
					return;
				}
				if ( data.retry_after ) {
					schedule( Math.max( data.retry_after * 1000, config.minMs ) );
					return;
				}
				backOff();
			} )
			.catch( function () {
				busy = false;
				backOff();
			} );
	}

	document.addEventListener( 'visibilitychange', function () {
		if ( document.hidden ) {
			window.clearTimeout( timer );
		} else if ( ! stopped ) {
			delay = config.minMs;
			poll();
		}
	} );

	schedule( delay );
}() );

/**
 * Copy buttons and the hand-it-on prompt.
 *
 * Both work from text the page already holds or one same-origin request. The prompt quotes the
 * scanned site, so it is written into a <pre> with textContent and never parsed as markup. Without
 * scripts the same prompt is reached by the link in the row, which reloads the page with the row
 * open.
 */
( function () {
	'use strict';

	var config = window.readinessIndex;

	function sourceText( button ) {
		var node = document.querySelector( button.getAttribute( 'data-ri-copy-from' ) || '' );
		if ( ! node ) {
			return '';
		}
		return 'value' in node && 'string' === typeof node.value ? node.value : node.textContent;
	}

	function copy( text ) {
		if ( window.navigator.clipboard && window.navigator.clipboard.writeText ) {
			return window.navigator.clipboard.writeText( text );
		}
		return new Promise( function ( resolve, reject ) {
			var area = document.createElement( 'textarea' );
			area.value = text;
			area.setAttribute( 'readonly', 'readonly' );
			area.style.position = 'absolute';
			area.style.insetInlineStart = '-9999px';
			document.body.appendChild( area );
			area.select();
			var ok = false;
			try {
				ok = document.execCommand( 'copy' );
			} catch ( e ) {
				ok = false;
			}
			document.body.removeChild( area );
			if ( ok ) {
				resolve();
			} else {
				reject( new Error( 'copy' ) );
			}
		} );
	}

	document.addEventListener( 'click', function ( event ) {
		var button = event.target && event.target.closest ? event.target.closest( '.ri-copy' ) : null;
		if ( ! button ) {
			return;
		}
		var label = button.textContent;
		copy( sourceText( button ) ).then( function () {
			button.textContent = button.getAttribute( 'data-ri-copied' ) || label;
			window.setTimeout( function () {
				button.textContent = label;
			}, 2000 );
		}, function () {} );
	} );

	function loadPrompt( details ) {
		var box = details.querySelector( '.ri-prompt-box' );
		if ( ! config || ! box || ! box.querySelector( '.ri-prompt-load' ) || box.getAttribute( 'data-ri-loading' ) ) {
			return;
		}
		box.setAttribute( 'data-ri-loading', '1' );

		var body = new window.URLSearchParams();
		body.set( 'action', config.promptAction );
		body.set( 'nonce', config.promptNonce );
		body.set( 'check', details.getAttribute( 'data-ri-check' ) || '' );

		window.fetch( config.ajaxUrl, { method: 'POST', credentials: 'same-origin', body: body } )
			.then( function ( response ) {
				return response.json();
			} )
			.then( function ( json ) {
				box.removeAttribute( 'data-ri-loading' );
				if ( ! json || ! json.data ) {
					return;
				}
				while ( box.firstChild ) {
					box.removeChild( box.firstChild );
				}
				var line = document.createElement( 'p' );
				if ( ! json.success ) {
					line.className = 'ri-prompt-error';
					line.textContent = json.data.message || '';
					box.appendChild( line );
					return;
				}
				line.className = 'description';
				line.textContent = json.data.notice;
				var pre = document.createElement( 'pre' );
				pre.className = 'ri-prompt';
				pre.tabIndex = 0;
				pre.textContent = json.data.prompt;
				var button = document.createElement( 'button' );
				button.type = 'button';
				button.className = 'button ri-copy';
				button.setAttribute( 'data-ri-copy-from', '.ri-prompt' );
				button.setAttribute( 'data-ri-copied', json.data.copied );
				button.textContent = json.data.copy;
				var wrap = document.createElement( 'p' );
				wrap.appendChild( button );
				box.appendChild( line );
				box.appendChild( pre );
				box.appendChild( wrap );
			} )
			.catch( function () {
				box.removeAttribute( 'data-ri-loading' );
			} );
	}

	// `toggle` does not bubble, so it is listened for in the capture phase on the document.
	document.addEventListener(
		'toggle',
		function ( event ) {
			var details = event.target;
			if ( details && details.tagName === 'DETAILS' && details.open && details.hasAttribute( 'data-ri-check' ) ) {
				loadPrompt( details );
			}
		},
		true
	);
}() );
