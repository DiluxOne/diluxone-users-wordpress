/**
 * The social buttons, in a popup.
 *
 * A live button is a plain link to the round trip. Pressed, it opens the same
 * trip in a window of its own and the page stays where it was; when the
 * browser gives no window (a popup blocker) the click goes ahead and the whole
 * window makes the trip, as it would with no script at all.
 *
 * The popup ends on a page of the site that says only that it is over:
 * `in` (signed in, nowhere else to go: this page reloads in place) or `next`
 * (this page goes to the resume address, which sends it on to the second
 * step, the required fields, the error or the destination). It is believed
 * only from this page's own origin and from the window it opened, or from this
 * origin's own channel — the way back when the provider's page cut the popup
 * off from its opener.
 */
( function () {
	'use strict';

	var NAME = 'diluxone-users-sso';
	var popup = null;
	var resume = '';
	var done = false;
	var channel = null;

	/** A window centred on this one, the size a provider's screen expects. */
	function features() {
		var width = 520;
		var height = 680;
		var left = Math.max( 0, ( window.screenX || 0 ) + ( ( window.outerWidth || width ) - width ) / 2 );
		var top = Math.max( 0, ( window.screenY || 0 ) + ( ( window.outerHeight || height ) - height ) / 2 );

		return 'popup=yes,width=' + width + ',height=' + height + ',left=' + Math.round( left ) + ',top=' + Math.round( top );
	}

	/** The popup's answer, as it is written: two fields, nothing else read. */
	function kindOf( data ) {
		if ( ! data || 'object' !== typeof data || 'diluxone-users-sso' !== data.type ) {
			return '';
		}

		return 'in' === data.kind || 'next' === data.kind ? data.kind : '';
	}

	function answer( kind ) {
		if ( done || ! popup ) {
			return;
		}

		done = true;

		try {
			if ( ! popup.closed ) {
				popup.close();
			}
		} catch ( e ) {}

		if ( 'in' === kind ) {
			window.location.reload();
		} else {
			window.location.assign( resume );
		}
	}

	document.addEventListener( 'click', function ( event ) {
		var link = event.target && event.target.closest ? event.target.closest( 'a[data-diluxone-users-sso-popup]' ) : null;

		if ( ! link || event.defaultPrevented || 0 !== event.button || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey ) {
			return;
		}

		var opened = window.open( link.getAttribute( 'data-diluxone-users-sso-popup' ), NAME, features() );

		// Blocked: the link goes ahead, in the whole window.
		if ( ! opened ) {
			return;
		}

		event.preventDefault();

		popup = opened;
		resume = link.getAttribute( 'data-diluxone-users-sso-resume' ) || link.href;
		done = false;

		try {
			opened.focus();
		} catch ( e ) {}
	} );

	window.addEventListener( 'message', function ( event ) {
		if ( event.origin !== window.location.origin || ! popup || event.source !== popup ) {
			return;
		}

		var kind = kindOf( event.data );

		if ( kind ) {
			answer( kind );
		}
	} );

	if ( 'function' === typeof window.BroadcastChannel ) {
		channel = new window.BroadcastChannel( NAME );

		channel.addEventListener( 'message', function ( event ) {
			var kind = kindOf( event.data );

			if ( ! kind || ! popup || done ) {
				return;
			}

			// Heard: the popup can close instead of going on by itself.
			channel.postMessage( { type: 'diluxone-users-sso-heard' } );
			answer( kind );
		} );
	}
}() );
