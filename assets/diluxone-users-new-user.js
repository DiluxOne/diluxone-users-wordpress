/**
 * Add New User: the username is the e-mail, so its row fills itself from the
 * e-mail field and steps aside, with a line saying why. The server sets the
 * same value again before WordPress reads it; this only keeps the screen
 * honest about it.
 */
( function () {
	'use strict';

	document.addEventListener( 'DOMContentLoaded', function () {
		var login = document.getElementById( 'user_login' );
		var mail = document.getElementById( 'email' );

		if ( ! login || ! mail ) {
			return;
		}

		var sync = function () {
			login.value = mail.value;
		};

		mail.addEventListener( 'input', sync );
		sync();

		var row = login.closest( 'tr' );

		if ( ! row ) {
			return;
		}

		row.style.display = 'none';

		var cell = mail.closest( 'td' );

		if ( cell && window.diluxOneUsersNewUser ) {
			var note = document.createElement( 'p' );
			note.className = 'description';
			note.textContent = window.diluxOneUsersNewUser.note;
			cell.appendChild( note );
		}
	} );
}() );
