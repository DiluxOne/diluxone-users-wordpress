/**
 * The social sign-in test's result window: Close reloads the dashboard that
 * opened it, so the provider's card shows the new state, and closes itself.
 */
( function () {
	'use strict';

	var button = document.querySelector( '[data-diluxone-users-sso-test-close]' );

	if ( ! button ) {
		return;
	}

	button.addEventListener( 'click', function () {
		if ( window.opener ) {
			window.opener.location.reload();
		}

		window.close();
	} );
}() );
