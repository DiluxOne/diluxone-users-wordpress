/**
 * Asks before a form that cannot be taken back is sent. The question is the
 * form's own `data-diluxone-users-confirm`, written and translated on the
 * server; without JavaScript the form is sent as it is, and what it starts
 * still has to be confirmed by e-mail.
 */
( function () {
	'use strict';

	document.addEventListener( 'submit', function ( event ) {
		var form = event.target;

		if ( form.hasAttribute && form.hasAttribute( 'data-diluxone-users-confirm' ) && ! window.confirm( form.getAttribute( 'data-diluxone-users-confirm' ) ) ) {
			event.preventDefault();
		}
	} );
}() );
