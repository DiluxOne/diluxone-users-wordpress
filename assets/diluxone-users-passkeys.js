/**
 * Passkeys: the browser side.
 *
 * There is little code here because the browser does nearly all of it. All
 * that happens here is translating the strings the server sends into the
 * ArrayBuffers the API expects, and back again when the answer returns.
 *
 * Registration uses `getPublicKey()`, which returns the key already in DER
 * format: that way the server does not have to interpret the attestation
 * object, the most fragile part of WebAuthn.
 */
( function () {
	'use strict';

	var data = window.diluxOneUsersPasskeys || null;

	if ( ! data || ! window.PublicKeyCredential ) {
		return;
	}

	function fromB64url( text ) {
		var normal = text.replace( /-/g, '+' ).replace( /_/g, '/' );
		var bytes  = atob( normal );
		var buffer = new Uint8Array( bytes.length );

		for ( var i = 0; i < bytes.length; i++ ) {
			buffer[ i ] = bytes.charCodeAt( i );
		}

		return buffer.buffer;
	}

	function toB64url( buffer ) {
		var bytes = new Uint8Array( buffer );
		var text  = '';

		for ( var i = 0; i < bytes.length; i++ ) {
			text += String.fromCharCode( bytes[ i ] );
		}

		return btoa( text ).replace( /\+/g, '-' ).replace( /\//g, '_' ).replace( /=+$/, '' );
	}

	function ask( body ) {
		body.action = 'diluxone_users_passkeys';
		body.nonce  = data.nonce;

		return fetch( data.ajax, {
			method: 'POST',
			credentials: 'same-origin',
			headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
			body: new URLSearchParams( body ).toString()
		} ).then( function ( r ) { return r.json(); } );
	}

	function say( box, text, error ) {
		if ( ! box ) {
			window.alert( text );
			return;
		}

		box.textContent = text;
		box.className = 'diluxone-users-notice diluxone-users-notice--' + ( error ? 'error' : 'ok' );
		box.hidden = false;
	}

	/* ── Registration ─────────────────────────────────────────────── */

	function register( button ) {
		var box = document.querySelector( '[data-diluxone-users-passkey-notice]' );

		button.disabled = true;

		ask( { step: 'register-options' } ).then( function ( r ) {
			if ( ! r.success ) {
				// The site's own words, already translated: said as they are.
				var said = new Error( r.data.message );
				said.fromSite = true;
				throw said;
			}

			var o = r.data;
			var options = {
				challenge: fromB64url( o.challenge ),
				rp: o.rp,
				user: {
					id: fromB64url( o.user.id ),
					name: o.user.name,
					displayName: o.user.displayName
				},
				// -7 is ECDSA P-256 and -257 is RSA. They are the two the
				// server verifies; offering others would be offering something
				// that is going to fail.
				pubKeyCredParams: [
					{ type: 'public-key', alg: -7 },
					{ type: 'public-key', alg: -257 }
				],
				excludeCredentials: ( o.excludeCredentials || [] ).map( function ( c ) {
					return { id: fromB64url( c.id ), type: 'public-key' };
				} ),
				authenticatorSelection: {
					userVerification: o.userVerification,
					residentKey: o.residentKey
				},
				timeout: 120000
			};

			if ( o.authenticatorAttachment ) {
				options.authenticatorSelection.authenticatorAttachment = o.authenticatorAttachment;
			}

			return navigator.credentials.create( { publicKey: options } );
		} ).then( function ( cred ) {
			var key = cred.response.getPublicKey ? cred.response.getPublicKey() : null;

			if ( ! key ) {
				throw new Error( data.texts.old );
			}

			// The name the person typed. If they left it empty the server
			// proposes the device, so nothing is invented here.
			var label = document.querySelector( '[data-diluxone-users-passkey-label]' );

			return ask( {
				step: 'register',
				id: cred.id,
				label: label ? label.value : '',
				publicKey: toB64url( key ),
				algorithm: cred.response.getPublicKeyAlgorithm(),
				clientDataJSON: new TextDecoder().decode( cred.response.clientDataJSON )
			} );
		} ).then( function ( r ) {
			if ( ! r.success ) {
				// The site's own words, already translated: said as they are.
				var said = new Error( r.data.message );
				said.fromSite = true;
				throw said;
			}

			window.location.reload();
		} ).catch( function ( e ) {
			button.disabled = false;
			// The browser's own errors come in English and in its words; the
			// site says its own sentence instead.
			say( box, e.fromSite && e.message ? e.message : data.texts.error, true );
		} );
	}

	/* ── Signing in ───────────────────────────────────────────────── */

	function signIn( button ) {
		var box = document.querySelector( '[data-diluxone-users-passkey-notice]' );

		button.disabled = true;

		ask( { step: 'login-options' } ).then( function ( r ) {
			if ( ! r.success ) {
				// The site's own words, already translated: said as they are.
				var said = new Error( r.data.message );
				said.fromSite = true;
				throw said;
			}

			return navigator.credentials.get( {
				publicKey: {
					challenge: fromB64url( r.data.challenge ),
					rpId: r.data.rpId,
					userVerification: r.data.userVerification,
					timeout: 120000
				}
			} );
		} ).then( function ( cred ) {
			return ask( {
				step: 'login',
				id: cred.id,
				clientDataJSON: new TextDecoder().decode( cred.response.clientDataJSON ),
				authenticatorData: toB64url( cred.response.authenticatorData ),
				signature: toB64url( cred.response.signature )
			} );
		} ).then( function ( r ) {
			if ( ! r.success ) {
				// The site's own words, already translated: said as they are.
				var said = new Error( r.data.message );
				said.fromSite = true;
				throw said;
			}

			window.location.href = r.data.redirect;
		} ).catch( function ( e ) {
			button.disabled = false;
			// The browser's own errors come in English and in its words; the
			// site says its own sentence instead.
			say( box, e.fromSite && e.message ? e.message : data.texts.error, true );
		} );
	}

	document.addEventListener( 'click', function ( event ) {
		var registration = event.target.closest( '[data-diluxone-users-passkey="register"]' );

		if ( registration ) {
			event.preventDefault();
			register( registration );
			return;
		}

		var login = event.target.closest( '[data-diluxone-users-passkey="login"]' );

		if ( login ) {
			event.preventDefault();
			signIn( login );
		}
	} );
}() );
