/**
 * The theme's colours, measured where the palette cannot say them.
 *
 * Probes the content area the way the theme paints it — a link, a button, a
 * field, the text and the ground — sets what it finds as the plugin's custom
 * properties on <html>, and keeps them in the browser so the next page starts
 * with them (see includes/theme-measure.php). No theme is named here.
 *
 * The answer is the theme's of the moment, so it is asked again when the
 * moment changes: the system's dark mode turning on, or the theme's own switch
 * changing a class, a `data-theme` or a style on <html> or <body>. What is
 * kept is kept once per scheme, so a dark page starts with the dark answer.
 *
 * A site with a switch of its own may say how it marks dark (`signal`, from
 * Design › Your brand): then the scheme is the one that signal says, and the
 * device's only when the page has neither the dark nor the light one (with
 * no light one, a page without the dark one is light). The
 * scheme seen last is kept too, for the sign-in page of its own (`door`):
 * it measures itself like any page, since it loads the theme's stylesheet,
 * but keeps nothing, and where the site's switch did not run there (neither
 * signal on it), what the site's pages measured for the scheme they showed
 * last is applied.
 */
( function () {
	'use strict';

	var config = window.diluxOneUsersThemeColors;

	if ( ! config || ! config.roles ) {
		return;
	}

	var roles = config.roles;
	var key = config.key;
	var signal = config.signal || null;
	var door = !! config.door && !! signal;
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

	var dark = window.matchMedia ? window.matchMedia( '(prefers-color-scheme: dark)' ) : null;
	var last = '';
	var watcher = null;
	var pending = false;

	function element( s ) {
		return s.on === 'body' ? doc.body : root;
	}

	function has( s ) {
		var el = s && element( s );
		if ( ! el ) {
			return false;
		}
		if ( s.kind === 'class' ) {
			return el.classList.contains( s.name );
		}
		return el.hasAttribute( s.name ) && ( s.value === null || el.getAttribute( s.name ) === s.value );
	}

	function remembered() {
		try {
			var m = localStorage.getItem( key + ':scheme' );
			return m === 'dark' || m === 'light' ? m : '';
		} catch ( e ) {
			return '';
		}
	}

	function scheme() {
		if ( signal ) {
			if ( has( signal.dark ) ) {
				return 'dark';
			}
			if ( has( signal.light ) ) {
				return 'light';
			}
			if ( door ) {
				// The site's switch may not run here: the scheme it showed last.
				if ( remembered() ) {
					return remembered();
				}
			} else if ( ! signal.light ) {
				// Only a dark signal: a page without it is light.
				return 'light';
			}
		}
		return dark && dark.matches ? 'dark' : 'light';
	}

	function stored( s ) {
		try {
			return JSON.parse( localStorage.getItem( key + ':' + s ) || 'null' ) || {};
		} catch ( e ) {
			return {};
		}
	}

	// On "A page of its own" the plugin repaints <body> with its own text and
	// ground, and the host inherits them: measured as they are, the plugin
	// would read its own light-scheme text back as the theme's. So the door's
	// class is off for the moment of the reading, and back before any paint.
	function measure() {
		var body = doc.body;
		var own = body && body.classList.contains( 'diluxone-users-door' );

		if ( ! own ) {
			return read();
		}

		body.classList.remove( 'diluxone-users-door' );
		try {
			return read();
		} finally {
			body.classList.add( 'diluxone-users-door' );
		}
	}

	function read() {
		var text = getComputedStyle( host ).color;
		var surface = ground( host );
		var link = probe( '<a href="#" data-p>x</a>' ).color;
		var button = probe( '<div class="wp-block-button"><a class="wp-block-button__link wp-element-button" href="#" data-p>x</a></div>' );
		var field = probe( '<input type="text" data-p>' );
		var accent = ! clear( button.bg ) && button.bg !== surface ? button.bg : link;

		return {
			accent: accent,
			'accent-ink': ! clear( button.bg ) && button.bg === accent ? button.color : ( lum( accent ) > 0.6 ? '#16181d' : '#ffffff' ),
			text: text,
			surface: surface,
			'surface-alt': mix( surface, text, 0.04 ),
			muted: mix( text, surface, 0.35 ),
			border: field.width > 0 && ! clear( field.border ) && field.border !== text ? field.border : mix( surface, text, 0.15 ),
		};
	}

	// The page of its own loads the theme's stylesheet, so it measures itself
	// like any page; only when the site's switch did not run there (neither
	// signal on it) does it take what the site's pages kept for the scheme
	// they showed last, and nothing of a scheme it is not in.
	function borrowed() {
		return door && ! has( signal.dark ) && ! has( signal.light ) && '' !== remembered() && Object.keys( stored( remembered() ) ).length > 0;
	}

	function keptHere() {
		var all = stored( scheme() );
		var kept = {};

		roles.forEach( function ( r ) {
			[ r, r === 'accent' ? 'accent-bg' : '' ].forEach( function ( p ) {
				if ( p ) {
					kept[ p ] = all[ p ] || '';
				}
			} );
		} );

		return kept;
	}

	function measured() {
		var found = measure();
		var kept = {};

		roles.forEach( function ( r ) {
			if ( ! found[ r ] ) {
				return;
			}
			kept[ r ] = found[ r ];
			if ( r === 'accent' ) {
				kept[ 'accent-bg' ] = found[ r ];
			}
		} );

		var now = scheme();

		// What is kept is the site's pages' to write: the page of its own reads
		// it, and its own measurement is for itself.
		if ( door ) {
			return kept;
		}

		try {
			localStorage.setItem( key + ':' + now, JSON.stringify( kept ) );
			if ( signal ) {
				localStorage.setItem( key + ':scheme', now );
			}
		} catch ( e ) {}

		return kept;
	}

	function apply() {
		pending = false;

		// The measurement and what it writes change <html> and <body>, which
		// the watcher would hear: its own, so it is not listening meanwhile.
		if ( watcher ) {
			watcher.disconnect();
		}
		try {
			write( borrowed() ? keptHere() : measured() );
		} finally {
			if ( watcher ) {
				watcher.takeRecords();
				watch();
			}
		}
	}

	function write( kept ) {
		var json = JSON.stringify( kept );

		if ( json === last ) {
			return;
		}
		last = json;

		Object.keys( kept ).forEach( function ( r ) {
			if ( kept[ r ] ) {
				root.style.setProperty( '--diluxone-users-' + r, kept[ r ] );
			} else {
				root.style.removeProperty( '--diluxone-users-' + r );
			}
		} );
	}

	// A theme's switch may change several things at once, and a stylesheet
	// may take a frame to apply: one measurement, on the next frame.
	function again() {
		if ( pending ) {
			return;
		}
		pending = true;
		( window.requestAnimationFrame || setTimeout )( apply );
	}

	function watch() {
		var names = [ 'class', 'data-theme', 'style' ];
		if ( signal ) {
			[ signal.dark, signal.light ].forEach( function ( s ) {
				if ( s && s.kind === 'attr' && names.indexOf( s.name ) < 0 ) {
					names.push( s.name );
				}
			} );
		}
		var what = { attributes: true, attributeFilter: names };
		watcher.observe( root, what );
		if ( doc.body ) {
			watcher.observe( doc.body, what );
		}
	}

	apply();

	if ( dark ) {
		if ( dark.addEventListener ) {
			dark.addEventListener( 'change', again );
		} else if ( dark.addListener ) {
			dark.addListener( again );
		}
	}

	if ( window.MutationObserver ) {
		watcher = new MutationObserver( again );
		watch();
	}

	// A site's switch may print after this, or set its signal once the
	// document is read: once more then.
	if ( signal && doc.readyState === 'loading' ) {
		doc.addEventListener( 'DOMContentLoaded', again );
	}
}() );
