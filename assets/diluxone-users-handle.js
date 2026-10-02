/**
 * The public name, as it is being typed.
 *
 * Two different things: the address it will end up as — worked out right
 * here, asking the server for nothing, so there is no flicker on every
 * keystroke — and whether it is free, which only the server knows. The second
 * one is asked for on its own, after a pause following the last keystroke,
 * and also on demand from the link.
 */
( function () {
	'use strict';

	var data  = window.diluxOneUsersHandle || null;
	var field = document.getElementById( 'diluxone-users-handle' );
	var preview = document.querySelector( '[data-diluxone-users-handle-preview]' );
	var link  = document.querySelector( '[data-diluxone-users-handle-url]' );

	if ( ! data || ! field || ! preview || ! link ) {
		return;
	}

	var checkLink = document.querySelector( '[data-diluxone-users-handle-check]' );
	var notice    = document.querySelector( '[data-diluxone-users-handle-notice]' );
	var initial   = field.value;
	var timer     = null;
	var request   = 0;

	function clean( text ) {
		var t = text.toLowerCase().trim();

		// Accents are stripped, as WordPress strips them from every address.
		// `normalize` separates the letter from its mark and then the mark is
		// thrown away.
		t = t.normalize( 'NFD' ).replace( /[̀-ͯ]/g, '' );

		t = t.replace( /\s+/g, '-' );
		t = t.replace( /[^a-z0-9._-]/g, '' );

		return t.replace( /-{2,}/g, '-' ).replace( /^-+|-+$/g, '' );
	}

	function say( text, state ) {
		if ( ! notice ) {
			return;
		}

		notice.textContent = text;
		notice.className   = '' === text ? '' : 'diluxone-users-handle__notice is-' + state;
	}

	function draw() {
		var slug = clean( field.value );
		var url  = data.base + slug + '/';

		preview.hidden     = '' === slug;
		link.textContent   = url;
		link.href          = url;

		if ( checkLink ) {
			checkLink.href = url;
		}
	}

	function ask() {
		var slug = clean( field.value );

		if ( '' === slug ) {
			say( '', '' );
			return;
		}

		var mine = ++request;
		var body = new URLSearchParams();

		body.append( 'action', 'diluxone_users_handle_check' );
		body.append( 'nonce', data.nonce );
		body.append( 'handle', field.value );

		say( data.checking, 'waiting' );

		fetch( data.ajax, {
			method: 'POST',
			credentials: 'same-origin',
			headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
			body: body.toString()
		} )
			.then( function ( r ) { return r.json(); } )
			.then( function ( r ) {
				// An old answer arriving late cannot tread on the new one.
				if ( mine !== request ) {
					return;
				}

				if ( ! r || ! r.success ) {
					say( data.error, 'waiting' );
					return;
				}

				say( r.data.reason, r.data.free ? 'free' : 'taken' );
			} )
			.catch( function () {
				if ( mine === request ) {
					say( data.error, 'waiting' );
				}
			} );
	}

	field.addEventListener( 'input', function () {
		draw();
		window.clearTimeout( timer );

		// While nothing has changed there is nothing to report: going into the
		// profile and finding your own name marked as taken would be absurd.
		if ( field.value === initial ) {
			say( '', '' );
			return;
		}

		timer = window.setTimeout( ask, 600 );
	} );

	if ( checkLink ) {
		checkLink.addEventListener( 'click', function ( e ) {
			e.preventDefault();
			window.clearTimeout( timer );
			ask();
		} );
	}

	draw();
}() );
