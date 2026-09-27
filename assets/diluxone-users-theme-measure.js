/**
 * The theme's colours, measured where the palette cannot say them.
 *
 * Probes the content area the way the theme paints it — a link, a button, a
 * field, the text and the ground — sets what it finds as the plugin's custom
 * properties on <html>, and keeps them in the browser so the next page starts
 * with them (see includes/theme-measure.php). No theme is named here.
 */
( function () {
	'use strict';

	var config = window.diluxOneUsersThemeColors;

	if ( ! config || ! config.roles ) {
		return;
	}

	var roles = config.roles;
	var key = config.key;
	var doc = document;
	var root = doc.documentElement;
	var host = doc.querySelector( '.entry-content, main, #primary, #content, .site-content' ) || doc.body;

	function rgb( c ) {
		var m = String( c ).match( /rgba?\(([^)]+)\)/ );
		if ( ! m ) {
			return null;
		}
		var p = m[ 1 ].split( /[ ,\/]+/ ).filter( Boolean ).map( parseFloat );
		return { r: p[ 0 ], g: p[ 1 ], b: p[ 2 ], a: p.length > 3 ? p[ 3 ] : 1 };
	}

	function clear( c ) {
		var v = rgb( c );
		return ! v || v.a === 0;
	}

	function css( v ) {
		return 'rgb(' + Math.round( v.r ) + ', ' + Math.round( v.g ) + ', ' + Math.round( v.b ) + ')';
	}

	function mix( a, b, t ) {
		a = rgb( a );
		b = rgb( b );
		return css( { r: a.r + ( b.r - a.r ) * t, g: a.g + ( b.g - a.g ) * t, b: a.b + ( b.b - a.b ) * t } );
	}

	function lum( c ) {
		var v = rgb( c );
		return ( 0.2126 * v.r + 0.7152 * v.g + 0.0722 * v.b ) / 255;
	}

	function ground( el ) {
		while ( el ) {
			var c = getComputedStyle( el ).backgroundColor;
			if ( ! clear( c ) ) {
				return c;
			}
			el = el.parentElement;
		}
		return 'rgb(255, 255, 255)';
	}

	// The markup a probe is built from is this file's own constant, never
	// anything read from the page or the server.
	function probe( html ) {
		var box = doc.createElement( 'div' );
		box.setAttribute( 'aria-hidden', 'true' );
		box.style.cssText = 'position:absolute;left:-9999px;top:0;visibility:hidden;pointer-events:none';
		box.innerHTML = html;
		host.appendChild( box );
		var el = box.querySelector( '[data-p]' );
		var cs = getComputedStyle( el );
		var out = { color: cs.color, bg: cs.backgroundColor, border: cs.borderTopColor, width: parseFloat( cs.borderTopWidth ) || 0 };
		box.remove();
		return out;
	}

	var text = getComputedStyle( host ).color;
	var surface = ground( host );
	var link = probe( '<a href="#" data-p>x</a>' ).color;
	var button = probe( '<div class="wp-block-button"><a class="wp-block-button__link wp-element-button" href="#" data-p>x</a></div>' );
	var field = probe( '<input type="text" data-p>' );
	var accent = ! clear( button.bg ) && button.bg !== surface ? button.bg : link;
	var found = {
		accent: accent,
		'accent-ink': ! clear( button.bg ) && button.bg === accent ? button.color : ( lum( accent ) > 0.6 ? '#16181d' : '#ffffff' ),
		text: text,
		surface: surface,
		'surface-alt': mix( surface, text, 0.04 ),
		muted: mix( text, surface, 0.35 ),
		border: field.width > 0 && ! clear( field.border ) && field.border !== text ? field.border : mix( surface, text, 0.15 ),
	};
	var kept = {};

	roles.forEach( function ( r ) {
		if ( ! found[ r ] ) {
			return;
		}
		root.style.setProperty( '--diluxone-users-' + r, found[ r ] );
		kept[ r ] = found[ r ];
		if ( r === 'accent' ) {
			root.style.setProperty( '--diluxone-users-accent-bg', found[ r ] );
			kept[ 'accent-bg' ] = found[ r ];
		}
	} );

	try {
		localStorage.setItem( key, JSON.stringify( kept ) );
	} catch ( e ) {}
}() );
