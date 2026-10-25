/**
 * The page a social popup ends on: it tells the page that opened it, and closes.
 *
 * What it says is `{ type, kind }` and nothing more, to the site's own origin
 * only (never `*`), and on that origin's own channel for when the provider's
 * page cut the popup off from its opener. Nobody to tell — the page that
 * opened it is gone — and the popup goes on by itself, to the resume address.
 */
( function () {
	'use strict';

	var body = document.body;

	if ( ! body || ! body.hasAttribute( 'data-diluxone-users-sso-bridge' ) ) {
		return;
	}

	var NAME = 'diluxone-users-sso';
	var origin = body.getAttribute( 'data-origin' ) || '';
	var resume = body.getAttribute( 'data-resume' ) || '';
	var message = { type: 'diluxone-users-sso', kind: 'in' === body.getAttribute( 'data-kind' ) ? 'in' : 'next' };
	var told = false;
	var channel = null;

	function goOn() {
		if ( resume ) {
			window.location.replace( resume );
		}
	}

	try {
		if ( origin && window.opener && ! window.opener.closed ) {
			window.opener.postMessage( message, origin );
			told = true;
		}
	} catch ( e ) {}

	if ( 'function' === typeof window.BroadcastChannel ) {
		channel = new window.BroadcastChannel( NAME );

		channel.addEventListener( 'message', function ( event ) {
			if ( event.data && 'diluxone-users-sso-heard' === event.data.type ) {
				told = true;
				window.close();
			}
		} );

		channel.postMessage( message );
	}

	if ( told ) {
		window.close();

		return;
	}

	// Nobody answered on the channel in time: the popup is the page now.
	window.setTimeout( function () {
		if ( ! told ) {
			goOn();
		}
	}, channel ? 1500 : 0 );
}() );
