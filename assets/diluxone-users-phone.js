/**
 * The phone field: a country picker that can be searched, and the number
 * grouped as it is typed.
 *
 * What the server draws works on its own: a list of countries and a box for
 * the number, posted as they are and checked there. This adds to it and
 * takes nothing away. The list stays in the form, hidden, and is still what
 * is posted; the picker only writes it.
 *
 * Every country's rule — its dialling code, how long its numbers are, how
 * they are grouped, the trunk prefix people dial at home, the token a mobile
 * is written with there — rides on its
 * <option>, and the words the picker says on the box, translated by the
 * server. Nothing is asked of anybody and nothing comes from elsewhere.
 *
 * The picker is a button that opens a search box (a combobox) over a list of
 * countries (a listbox): typing narrows the list by name or by code, the
 * arrows, Home and End move through it, Enter chooses, Escape closes and
 * goes back to the button, and a polite live region says how many match.
 * It is only built when the plugin's stylesheet is on the page; a site that
 * turned the stylesheet off keeps the native list, which it can style.
 */
( function () {
	'use strict';

	var made = 0;

	function digitsOf( text ) {
		return String( text || '' ).replace( /\D/g, '' );
	}

	/** Lowercase and without accents, as a person searches. */
	function plain( text ) {
		return String( text || '' ).toLowerCase().normalize( 'NFD' ).replace( /[̀-ͯ]/g, '' ).trim();
	}

	function ruleOf( option ) {
		if ( ! option ) {
			return { dial: '', min: 4, max: 15, group: '', trunk: '', mobile: '' };
		}

		return {
			dial: option.getAttribute( 'data-dial' ) || '',
			min: parseInt( option.getAttribute( 'data-min' ), 10 ) || 4,
			max: parseInt( option.getAttribute( 'data-max' ), 10 ) || 15,
			group: option.getAttribute( 'data-group' ) || '',
			trunk: option.getAttribute( 'data-trunk' ) || '',
			mobile: option.getAttribute( 'data-mobile' ) || ''
		};
	}

	/**
	 * The national number and the trunk prefix typed before it, if any: the
	 * prefix goes only when what is left is still long enough to be a number
	 * of the country. A mobile token typed right after the area code goes too,
	 * and the lead a mobile carries goes in front: `typed` is the number as it
	 * was written, `token` the token and `area` how long the area code is. The
	 * same rule as diluxone_users_phone_national().
	 */
	function split( rule, number ) {
		var digits = digitsOf( number );
		var trunk = '';
		var parts = rule.mobile.indexOf( ':' ) > 0 ? rule.mobile.split( ':' ) : null;
		var length, area, mobile;

		if ( rule.trunk && 0 === digits.indexOf( rule.trunk ) && digits.length - rule.trunk.length >= rule.min ) {
			trunk = rule.trunk;
			digits = digits.slice( trunk.length );
		}

		if ( parts ) {
			length = digits.length - parts[ 0 ].length;

			if ( length >= rule.min && length <= rule.max ) {
				area = pattern( rule, length, digits )[ 0 ];
				mobile = parts[ 1 ] + digits.slice( 0, area ) + digits.slice( area + parts[ 0 ].length );

				if ( digits.substr( area, parts[ 0 ].length ) === parts[ 0 ] && mobile.length <= rule.max ) {
					return { trunk: trunk, digits: mobile, typed: digits, token: parts[ 0 ], area: area };
				}
			}
		}

		return { trunk: trunk, digits: digits, typed: digits, token: '', area: 0 };
	}

	/** The sizes of the groups: diluxone_users_phone_groups(). */
	function groups( rule, length, digits ) {
		return pattern( rule, Math.min( Math.max( length, rule.min ), rule.max ), digits || '' );
	}

	/** The groups of a number of exactly this length: diluxone_users_phone_pattern(). */
	function pattern( rule, target, digits ) {
		var patterns = rule.group ? rule.group.split( '|' ) : [];
		var fallback = null;
		var i, j, at, starts, sizes, sum, fours, out;

		for ( i = 0; i < patterns.length; i++ ) {
			at = patterns[ i ].indexOf( ':' );
			starts = at < 0 ? [] : patterns[ i ].slice( 0, at ).split( ',' );
			sizes = patterns[ i ].slice( at + 1 ).split( ' ' ).map( function ( n ) { return parseInt( n, 10 ); } );
			sum = sizes.reduce( function ( a, b ) { return a + b; }, 0 );

			if ( sum !== target ) {
				continue;
			}

			if ( ! starts.length ) {
				fallback = sizes;
				continue;
			}

			for ( j = 0; j < starts.length; j++ ) {
				if ( 0 === digits.indexOf( starts[ j ] ) ) {
					return sizes;
				}
			}
		}

		if ( fallback ) {
			return fallback;
		}

		if ( target <= 4 ) {
			return [ target ];
		}

		if ( 5 === target ) {
			return [ 2, 3 ];
		}

		fours = [ 0, 1, 2 ][ target % 3 ];
		out = [];

		for ( i = 0; i < ( target - 4 * fours ) / 3; i++ ) {
			out.push( 3 );
		}

		for ( i = 0; i < fours; i++ ) {
			out.push( 4 );
		}

		return out;
	}

	/**
	 * The number written in groups: diluxone_users_phone_format(), with a
	 * typed trunk prefix kept in front, and a typed mobile token kept after the
	 * area code, as the person wrote them.
	 */
	function format( rule, number ) {
		var parts = split( rule, number );
		var digits = parts.token ? parts.typed.slice( 0, parts.area ) + parts.typed.slice( parts.area + parts.token.length ) : parts.digits;
		var out = [];
		var at = 0;

		groups( rule, digits.length, digits ).forEach( function ( size ) {
			if ( at < digits.length ) {
				out.push( digits.slice( at, at + size ) );
				at += size;
			}
		} );

		if ( at < digits.length ) {
			out.push( digits.slice( at ) );
		}

		if ( parts.token ) {
			out.splice( 1, 0, parts.token );
		}

		return ( parts.trunk && out.length ? parts.trunk + ' ' : parts.trunk ) + out.join( ' ' );
	}

	function enhance( box ) {
		var select = box.querySelector( 'select.diluxone-users-phone__dial' );
		var input = box.querySelector( 'input.diluxone-users-phone__number' );

		if ( ! select || ! input || box.hasAttribute( 'data-diluxone-users-phone-ready' ) ) {
			return;
		}

		box.setAttribute( 'data-diluxone-users-phone-ready', '' );

		function rule() {
			return ruleOf( select.options[ select.selectedIndex ] );
		}

		/** Groups what is in the box again, keeping the caret after the same digit. */
		function regroup() {
			var value = input.value;
			var caret = null === input.selectionStart ? value.length : input.selectionStart;
			var before = digitsOf( value.slice( 0, caret ) ).length;
			var next = format( rule(), value );
			var at = 0;
			var seen = 0;

			// A number written with its "+" waits as it is, until its dialling
			// code picks the country (dial()) or nothing ever could.
			if ( next === value || input.readOnly || /^\s*\+/.test( value ) ) {
				return;
			}

			input.value = next;

			while ( at < next.length && seen < before ) {
				if ( /\d/.test( next.charAt( at ) ) ) {
					seen++;
				}

				at++;
			}

			if ( document.activeElement === input ) {
				input.setSelectionRange( at, at );
			}
		}

		/**
		 * A number written in international form ("+54 9 11 …"), pasted or
		 * typed, picks its country: the longest dialling code that matches, once
		 * no longer one could still match what was written, and what follows it
		 * stays in the box as the national number. Between countries that share
		 * a code (+1, +7, +44) the one chosen stays if it has it, else the first
		 * in the list, the field's own first: the server's rule on reading a
		 * stored value back. A code no country has is left as written.
		 */
		function dial() {
			var value = input.value;
			var digits, best, wait;

			if ( input.readOnly || select.disabled || ! /^\s*\+/.test( value ) ) {
				return;
			}

			digits = digitsOf( value );
			best = null;
			wait = false;

			Array.prototype.forEach.call( select.options, function ( option ) {
				var code = option.getAttribute( 'data-dial' ) || '';

				if ( '' === code || option.disabled ) {
					return;
				}

				if ( code.length > digits.length && 0 === code.indexOf( digits ) ) {
					wait = true;
				} else if ( 0 === digits.indexOf( code ) && ( ! best || code.length > best.getAttribute( 'data-dial' ).length ) ) {
					best = option;
				}
			} );

			if ( wait || ! best ) {
				return;
			}

			if ( select.selectedIndex >= 0 && ruleOf( select.options[ select.selectedIndex ] ).dial === best.getAttribute( 'data-dial' ) ) {
				best = select.options[ select.selectedIndex ];
			}

			input.value = digits.slice( best.getAttribute( 'data-dial' ).length );

			if ( document.activeElement === input ) {
				input.setSelectionRange( input.value.length, input.value.length );
			}

			if ( select.value !== best.value ) {
				select.value = best.value;
				select.dispatchEvent( new Event( 'change', { bubbles: true } ) );
			}
		}

		/**
		 * The browser's own check, with the words the server would send back:
		 * a number the wrong length for the country stops the form here, and
		 * the error is said under the number as the server says it.
		 */
		function check( event ) {
			var r = rule();
			var length = split( r, input.value ).digits.length;
			var code = '';

			if ( length > 0 && select.value ) {
				code = length < r.min ? 'short' : ( length > r.max ? 'long' : '' );
			}

			input.setCustomValidity( code ? box.getAttribute( 'data-' + code ) || '' : '' );

			// What the server said stays until the person changes something.
			if ( ! code && event ) {
				say( '' );
			}
		}

		function say( text ) {
			var id = ( input.id || 'diluxone-users-phone' ) + '-error';
			var error = document.getElementById( id );

			if ( ! text ) {
				if ( error ) {
					error.hidden = true;
				}

				input.removeAttribute( 'aria-invalid' );
				return;
			}

			if ( ! error ) {
				error = document.createElement( 'p' );
				error.id = id;
				error.className = 'diluxone-users-field__error';
				box.parentNode.insertBefore( error, box.nextSibling );
			}

			error.textContent = text;
			error.hidden = false;
			input.setAttribute( 'aria-invalid', 'true' );
			input.setAttribute( 'aria-describedby', id );
		}

		input.addEventListener( 'input', function ( event ) {
			dial();
			regroup();
			check( event );
		} );

		select.addEventListener( 'change', check );

		input.addEventListener( 'invalid', function () {
			say( input.validationMessage );
		} );

		check();

		// A space is the script's, not the person's: Backspace over one takes
		// the digit before it, and Delete the digit after it.
		input.addEventListener( 'keydown', function ( event ) {
			var at = input.selectionStart;
			var value = input.value;

			if ( at !== input.selectionEnd || input.readOnly ) {
				return;
			}

			if ( 'Backspace' === event.key && at > 1 && ' ' === value.charAt( at - 1 ) ) {
				event.preventDefault();
				input.value = value.slice( 0, at - 2 ) + value.slice( at );
				input.setSelectionRange( at - 2, at - 2 );
				input.dispatchEvent( new Event( 'input', { bubbles: true } ) );
			} else if ( 'Delete' === event.key && ' ' === value.charAt( at ) && at + 1 < value.length ) {
				event.preventDefault();
				input.value = value.slice( 0, at + 1 ) + value.slice( at + 2 );
				input.setSelectionRange( at, at );
				input.dispatchEvent( new Event( 'input', { bubbles: true } ) );
			}
		} );

		if ( '1' !== getComputedStyle( box ).getPropertyValue( '--diluxone-users-phone-picker' ).trim() ) {
			select.addEventListener( 'change', regroup );
			return;
		}

		picker( box, select, regroup );
	}

	function picker( box, select, regroup ) {
		var id = ( select.id || 'diluxone-users-phone-' + made ) + '-picker';
		var countries = [];
		var shown = [];
		var active = -1;
		var label = select.getAttribute( 'aria-label' ) || '';

		made++;

		var button = document.createElement( 'button' );
		var iso = document.createElement( 'span' );
		var code = document.createElement( 'span' );
		var caret = document.createElement( 'span' );
		var popup = document.createElement( 'div' );
		var search = document.createElement( 'input' );
		var list = document.createElement( 'ul' );
		var status = document.createElement( 'span' );

		button.type = 'button';
		button.id = id + '-button';
		button.className = 'diluxone-users-phone__button diluxone-users-phone__dial';
		button.setAttribute( 'aria-haspopup', 'listbox' );
		button.setAttribute( 'aria-expanded', 'false' );
		button.setAttribute( 'aria-controls', id + '-popup' );
		button.disabled = select.disabled;
		iso.className = 'diluxone-users-phone__iso';
		code.className = 'diluxone-users-phone__code';
		caret.className = 'diluxone-users-phone__caret';
		caret.setAttribute( 'aria-hidden', 'true' );
		button.appendChild( iso );
		button.appendChild( code );
		button.appendChild( caret );

		popup.id = id + '-popup';
		popup.className = 'diluxone-users-phone__popup';
		popup.hidden = true;

		search.type = 'text';
		search.id = id + '-search';
		search.className = 'diluxone-users-phone__search';
		search.setAttribute( 'role', 'combobox' );
		search.setAttribute( 'aria-autocomplete', 'list' );
		search.setAttribute( 'aria-expanded', 'false' );
		search.setAttribute( 'aria-controls', id + '-list' );
		search.setAttribute( 'aria-label', box.getAttribute( 'data-search' ) || '' );
		search.setAttribute( 'placeholder', box.getAttribute( 'data-search' ) || '' );
		search.setAttribute( 'autocomplete', 'off' );
		search.setAttribute( 'spellcheck', 'false' );

		list.id = id + '-list';
		list.className = 'diluxone-users-phone__list';
		list.setAttribute( 'role', 'listbox' );
		list.setAttribute( 'aria-label', box.getAttribute( 'data-list' ) || '' );

		status.className = 'diluxone-users-phone__status';
		status.setAttribute( 'role', 'status' );
		status.setAttribute( 'aria-live', 'polite' );

		Array.prototype.forEach.call( select.options, function ( option ) {
			if ( option.disabled || '' === option.value ) {
				return;
			}

			var item = document.createElement( 'li' );
			var name = document.createElement( 'span' );
			var dial = document.createElement( 'span' );
			var number = option.getAttribute( 'data-dial' ) || '';
			var text = option.textContent.replace( /\s*\+\d+\s*$/, '' );

			item.id = id + '-' + option.value;
			item.className = 'diluxone-users-phone__option';
			item.setAttribute( 'role', 'option' );
			item.setAttribute( 'aria-selected', 'false' );
			item.setAttribute( 'data-value', option.value );
			name.textContent = text;
			dial.className = 'diluxone-users-phone__option-code';
			dial.textContent = '+' + number;
			item.appendChild( name );
			item.appendChild( dial );

			countries.push( { item: item, value: option.value, name: plain( text ), iso: option.value.toLowerCase(), dial: number, label: text } );
		} );

		popup.appendChild( search );
		popup.appendChild( list );
		popup.appendChild( status );

		select.hidden = true;
		select.tabIndex = -1;
		select.parentNode.insertBefore( button, select );
		box.appendChild( popup );
		box.classList.add( 'is-enhanced' );

		/** The button says which country is chosen: its code and its dialling code. */
		function show() {
			var option = select.options[ select.selectedIndex ];
			var dial = option ? option.getAttribute( 'data-dial' ) || '' : '';
			var name = option ? option.textContent.replace( /\s*\+\d+\s*$/, '' ) : '';

			iso.textContent = option ? option.value : '';
			code.textContent = dial ? '+' + dial : '';
			button.setAttribute( 'aria-label', label + ': ' + name + ( dial ? ' +' + dial : '' ) );

			countries.forEach( function ( country ) {
				country.item.setAttribute( 'aria-selected', option && country.value === option.value ? 'true' : 'false' );
			} );
		}

		function point( index ) {
			if ( active >= 0 && shown[ active ] ) {
				shown[ active ].item.classList.remove( 'is-active' );
			}

			active = shown.length ? Math.max( 0, Math.min( index, shown.length - 1 ) ) : -1;

			if ( active < 0 ) {
				search.removeAttribute( 'aria-activedescendant' );
				return;
			}

			shown[ active ].item.classList.add( 'is-active' );
			search.setAttribute( 'aria-activedescendant', shown[ active ].item.id );

			if ( shown[ active ].item.scrollIntoView ) {
				shown[ active ].item.scrollIntoView( { block: 'nearest' } );
			}
		}

		/** Narrows the list to what was typed: a name, an ISO code, or a dialling code with or without its "+". */
		function narrow() {
			var query = plain( search.value );
			var number = query.replace( /^\+/, '' );
			var byCode = /^\d+$/.test( number );

			while ( list.firstChild ) {
				list.removeChild( list.firstChild );
			}

			shown = countries.filter( function ( country ) {
				if ( '' === query ) {
					return true;
				}

				return byCode ? 0 === country.dial.indexOf( number ) : -1 !== country.name.indexOf( query ) || country.iso === query;
			} );

			shown.forEach( function ( country ) {
				list.appendChild( country.item );
			} );

			status.textContent = shown.length
				? ( box.getAttribute( 'data-found' ) || '%d' ).replace( '%d', String( shown.length ) )
				: box.getAttribute( 'data-none' ) || '';

			active = -1;

			var chosen = -1;

			shown.forEach( function ( country, index ) {
				if ( 'true' === country.item.getAttribute( 'aria-selected' ) ) {
					chosen = index;
				}
			} );

			point( '' === query && chosen >= 0 ? chosen : 0 );
		}

		function isOpen() {
			return ! popup.hidden;
		}

		function open() {
			if ( button.disabled || isOpen() ) {
				return;
			}

			popup.hidden = false;
			button.setAttribute( 'aria-expanded', 'true' );
			search.setAttribute( 'aria-expanded', 'true' );
			search.value = '';
			narrow();
			search.focus();
		}

		function close( back ) {
			if ( ! isOpen() ) {
				return;
			}

			popup.hidden = true;
			button.setAttribute( 'aria-expanded', 'false' );
			search.setAttribute( 'aria-expanded', 'false' );
			search.removeAttribute( 'aria-activedescendant' );

			if ( back ) {
				button.focus();
			}
		}

		function choose( country ) {
			if ( country && select.value !== country.value ) {
				select.value = country.value;
				select.dispatchEvent( new Event( 'change', { bubbles: true } ) );
			}

			close( true );
		}

		button.addEventListener( 'click', function () {
			if ( isOpen() ) {
				close( true );
			} else {
				open();
			}
		} );

		button.addEventListener( 'keydown', function ( event ) {
			if ( 'ArrowDown' === event.key || 'ArrowUp' === event.key ) {
				event.preventDefault();
				open();
			}
		} );

		search.addEventListener( 'input', narrow );

		search.addEventListener( 'keydown', function ( event ) {
			switch ( event.key ) {
				case 'ArrowDown':
					event.preventDefault();
					point( active + 1 );
					break;
				case 'ArrowUp':
					event.preventDefault();
					point( active - 1 );
					break;
				case 'Home':
					event.preventDefault();
					point( 0 );
					break;
				case 'End':
					event.preventDefault();
					point( shown.length - 1 );
					break;
				case 'Enter':
					// Never the form's submit: Enter here is a choice.
					event.preventDefault();
					choose( shown[ active ] );
					break;
				case 'Escape':
					event.preventDefault();
					event.stopPropagation();
					close( true );
					break;
				case 'Tab':
					close( false );
					break;
			}
		} );

		list.addEventListener( 'mousedown', function ( event ) {
			// Keeps the focus in the search box until the choice is made.
			event.preventDefault();
		} );

		list.addEventListener( 'click', function ( event ) {
			var item = event.target.closest ? event.target.closest( '[role="option"]' ) : null;

			countries.forEach( function ( country ) {
				if ( country.item === item ) {
					choose( country );
				}
			} );
		} );

		document.addEventListener( 'mousedown', function ( event ) {
			if ( isOpen() && ! box.contains( event.target ) ) {
				close( false );
			}
		} );

		box.addEventListener( 'focusout', function ( event ) {
			if ( isOpen() && event.relatedTarget && ! box.contains( event.relatedTarget ) ) {
				close( false );
			}
		} );

		// Whoever changes the list — the picker, a test, another script —
		// the button and the number follow.
		select.addEventListener( 'change', function () {
			show();
			regroup();
		} );

		show();
	}

	function start() {
		Array.prototype.forEach.call( document.querySelectorAll( '[data-diluxone-users-phone]' ), enhance );
	}

	if ( 'loading' === document.readyState ) {
		document.addEventListener( 'DOMContentLoaded', start );
	} else {
		start();
	}
}() );
