<?php
/**
 * The theme's colours, measured where the palette cannot say them.
 *
 * Reading a palette by name works for themes that call their colours primary,
 * contrast, base. Many do not — some number them, classic themes publish no
 * palette at all — and for those the only honest source of "the theme's
 * colours" is what the theme actually paints. So the visitor's browser asks
 * it: the colour of a link and the ground of a button in the content area,
 * the text and the ground of the page, the edge of a field. Those answers
 * become the plugin's properties on <html>, where they win over its own
 * defaults, and are kept in the browser so the next page starts with them.
 *
 * No theme is named here: the measurement is the same for every theme.
 *
 * @package DiluxOneUsers
 */

defined( 'ABSPATH' ) || exit;

/** The key the measured colours are kept under: it changes with the theme. */
function diluxone_users_measure_key(): string {
	$theme = wp_get_theme();

	return 'diluxone-users-theme-colors:' . $theme->get_stylesheet() . ':' . $theme->get( 'Version' ) . ':' . DILUXONE_USERS_VERSION;
}

/**
 * Before anything is painted: the colours measured on an earlier page, if
 * the browser kept them. Without this the plugin's own blue would show for a
 * moment on every page until the measurement below replaced it.
 *
 * They are kept once per colour scheme, and the set read is the one for the
 * scheme the page opens in: a page that opens dark never starts with the
 * text measured on a light one.
 *
 * Inline and in the head, on purpose: a file would be one more request in
 * front of the first paint, which is the moment this exists for. It is a
 * script of its own in the queue all the same, so it prints where WordPress
 * prints scripts and a site's content-security policy can reach it.
 */
function diluxone_users_measure_early(): void {
	if ( is_admin() ) {
		return;
	}

	$signal = diluxone_users_dark_signal();
	$door   = array() !== $signal && diluxone_users_door_drawn();

	if ( array() === diluxone_users_color_measured_roles() && ! $door ) {
		return;
	}

	wp_register_script( 'diluxone-users-theme-colors-early', false, array(), DILUXONE_USERS_VERSION, false );
	wp_enqueue_script( 'diluxone-users-theme-colors-early' );

	if ( array() === $signal ) {
		wp_add_inline_script(
			'diluxone-users-theme-colors-early',
			'(function(k){try{var d=window.matchMedia&&window.matchMedia("(prefers-color-scheme: dark)").matches;var v=JSON.parse(localStorage.getItem(k+(d?":dark":":light"))||"null");if(!v)return;var s=document.documentElement.style;for(var r in v){s.setProperty("--diluxone-users-"+r,v[r]);}}catch(e){}})(' . wp_json_encode( diluxone_users_measure_key() ) . ');'
		);

		return;
	}

	wp_add_inline_script(
		'diluxone-users-theme-colors-early',
		diluxone_users_measure_early_signal() . '(' . wp_json_encode( diluxone_users_measure_key() ) . ',' . wp_json_encode( $signal ) . ',' . ( $door ? '1' : '0' ) . ');'
	);
}
add_action( 'wp_enqueue_scripts', 'diluxone_users_measure_early', 1 );

/**
 * The same, for a site that says it is dark with a switch of its own
 * (diluxone_users_dark_signal()): the scheme is the one the switch has put
 * on the page, and the device's only when it has put neither.
 *
 * On the page of its own the site's switch may never run, because the
 * theme's template is not there. So when neither signal is on the page, the
 * scheme the site last showed (kept by the measurer, below) is put there: the
 * class added, or the attribute set, which lets the theme's own CSS keyed on
 * it apply as well. A signal the site already put there itself wins. A
 * signal that goes on <body> waits for <body>, which the head has not got
 * yet, and on the site's pages the scheme it last showed stands in until
 * the measurer reads the page.
 *
 * With no light signal, a page without the dark one is light; with one, a
 * page with neither is the device's.
 *
 * Applied with classList and setAttribute, never as markup, and the signal
 * itself is a short list of characters (diluxone_users_dark_signal_parse()).
 */
function diluxone_users_measure_early_signal(): string {
	return '(function(k,g,door){try{'
		. 'var d=document,r=d.documentElement,ls=localStorage;'
		. 'function el(s){return"body"===s.on?d.body:r;}'
		. 'function has(s){var e=s&&el(s);return!!e&&("class"===s.kind?e.classList.contains(s.name):e.hasAttribute(s.name)&&(null===s.value||e.getAttribute(s.name)===s.value));}'
		. 'function put(s){var e=el(s);if("class"===s.kind){e.classList.add(s.name);}else{e.setAttribute(s.name,null===s.value?"":s.value);}}'
		. 'var m=ls.getItem(k+":scheme");m="dark"===m||"light"===m?m:"";'
		. 'var c=has(g.dark)?"dark":has(g.light)?"light":"";'
		. 'var unseen=!d.body&&("body"===g.dark.on||!!(g.light&&"body"===g.light.on));'
		. 'if(!c&&door&&m){c=m;var s=g[m];if(s){if(el(s)){put(s);}else{var o=new MutationObserver(function(){if(d.body){o.disconnect();if(!has(g.dark)&&!has(g.light)){put(s);}}});o.observe(r,{childList:true});}}}'
		. 'if(!c&&unseen&&m){c=m;}'
		. 'if(!c&&!door&&!unseen&&!g.light){c="light";}'
		. 'if(!c){c=window.matchMedia&&window.matchMedia("(prefers-color-scheme: dark)").matches?"dark":"light";}'
		. 'var v=JSON.parse(ls.getItem(k+":"+c)||"null");if(!v)return;var st=r.style;for(var x in v){st.setProperty("--diluxone-users-"+x,v[x]);}'
		. '}catch(e){}})';
}

/**
 * After the page is drawn: measure, apply, keep. Only on pages that carry the
 * plugin's own stylesheet — anywhere else there is nothing of it to paint —
 * which is only known once the page is drawn, so it is queued from the
 * footer, before WordPress prints the footer's scripts.
 *
 * With the site's own switch it also runs where the palette answered every
 * part, to keep the scheme the site showed for the page of its own; and on
 * that page it measures nothing of its own surface: it applies what the
 * site's pages measured for the scheme in force.
 */
function diluxone_users_measure(): void {
	$roles  = diluxone_users_color_measured_roles();
	$signal = diluxone_users_dark_signal();

	if ( ( array() === $roles && array() === $signal ) || is_admin() || ! ( wp_style_is( 'diluxone-users', 'done' ) || wp_style_is( 'diluxone-users', 'enqueued' ) ) ) {
		return;
	}

	$config = array(
		'roles' => array_values( $roles ),
		'key'   => diluxone_users_measure_key(),
	);

	if ( array() !== $signal ) {
		$config['signal'] = $signal;
		$config['door']   = diluxone_users_door_drawn() ? '1' : '';
	}

	wp_enqueue_script( 'diluxone-users-theme-colors', DILUXONE_USERS_URL . 'assets/diluxone-users-theme-measure.js', array(), diluxone_users_asset_version( 'assets/diluxone-users-theme-measure.js' ), true );
	wp_localize_script( 'diluxone-users-theme-colors', 'diluxOneUsersThemeColors', $config );
}
add_action( 'wp_footer', 'diluxone_users_measure', 5 );
