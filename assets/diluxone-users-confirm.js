/**
 * Asks before a form that cannot be taken back is sent, in the page.
 *
 * The form names its question with `data-diluxone-users-dialog`: the id of a
 * `<dialog>` written and translated on the server, with a button that goes
 * ahead (`data-diluxone-users-dialog-ok`) and one that does not
 * (`data-diluxone-users-dialog-cancel`). Escape, and a click outside the box,
 * are "no" as well. Without the script, or in a browser with no `<dialog>`,
 * the form is sent as it is, and what it starts still has to be confirmed by
 * e-mail.
 */
( function () {
	'use strict';

	document.addEventListener( 'submit', function ( event ) {
		var form = event.target;
		var id = form.getAttribute ? form.getAttribute( 'data-diluxone-users-dialog' ) : null;
		var dialog = id ? document.getElementById( id ) : null;

		if ( ! dialog || typeof dialog.showModal !== 'function' || form.hasAttribute( 'data-diluxone-users-asked' ) ) {
			return;
		}

		event.preventDefault();

		var ok = dialog.querySelector( '[data-diluxone-users-dialog-ok]' );
		var cancel = dialog.querySelector( '[data-diluxone-users-dialog-cancel]' );

		function close() {
			dialog.close();
		}

		ok.onclick = function () {
			form.setAttribute( 'data-diluxone-users-asked', '' );
			close();
			form.submit();
		};
		cancel.onclick = close;

		// A click on the backdrop lands on the dialog itself, not on its content.
		dialog.onclick = function ( click ) {
			if ( click.target === dialog ) {
				close();
			}
		};

		dialog.showModal();
		cancel.focus();
	} );
}() );
