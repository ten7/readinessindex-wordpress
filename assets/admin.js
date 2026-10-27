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
