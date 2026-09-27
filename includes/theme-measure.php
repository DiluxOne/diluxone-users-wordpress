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
 */
function diluxone_users_measure_early(): void {
	$roles = diluxone_users_color_measured_roles();
	if ( array() === $roles || is_admin() ) {
		return;
	}

	$js = '(function(k){try{var v=JSON.parse(localStorage.getItem(k)||"null");if(!v)return;var s=document.documentElement.style;for(var r in v){s.setProperty("--diluxone-users-"+r,v[r]);}}catch(e){}})(' . wp_json_encode( diluxone_users_measure_key() ) . ');';

	wp_print_inline_script_tag( $js, array( 'id' => 'diluxone-users-theme-colors-early' ) );
}
add_action( 'wp_head', 'diluxone_users_measure_early', 1 );

/**
 * After the page is drawn: measure, apply, keep. Only on pages that carry the
 * plugin's own stylesheet — anywhere else there is nothing of it to paint.
 */
function diluxone_users_measure(): void {
	$roles = diluxone_users_color_measured_roles();
	if ( array() === $roles || is_admin() || ! ( wp_style_is( 'diluxone-users', 'done' ) || wp_style_is( 'diluxone-users', 'enqueued' ) ) ) {
		return;
	}

	$js = <<<'JS'
(function (roles, key) {
  var doc = document, root = doc.documentElement;
  var host = doc.querySelector('.entry-content, main, #primary, #content, .site-content') || doc.body;
  function rgb(c) {
    var m = String(c).match(/rgba?\(([^)]+)\)/);
    if (!m) return null;
    var p = m[1].split(/[ ,\/]+/).filter(Boolean).map(parseFloat);
    return { r: p[0], g: p[1], b: p[2], a: p.length > 3 ? p[3] : 1 };
  }
  function clear(c) { var v = rgb(c); return !v || v.a === 0; }
  function css(v) { return 'rgb(' + Math.round(v.r) + ', ' + Math.round(v.g) + ', ' + Math.round(v.b) + ')'; }
  function mix(a, b, t) { a = rgb(a); b = rgb(b); return css({ r: a.r + (b.r - a.r) * t, g: a.g + (b.g - a.g) * t, b: a.b + (b.b - a.b) * t }); }
  function lum(c) { var v = rgb(c); return (0.2126 * v.r + 0.7152 * v.g + 0.0722 * v.b) / 255; }
  function ground(el) { while (el) { var c = getComputedStyle(el).backgroundColor; if (!clear(c)) return c; el = el.parentElement; } return 'rgb(255, 255, 255)'; }
  function probe(html) {
    var box = doc.createElement('div');
    box.setAttribute('aria-hidden', 'true');
    box.style.cssText = 'position:absolute;left:-9999px;top:0;visibility:hidden;pointer-events:none';
    box.innerHTML = html;
    host.appendChild(box);
    var el = box.querySelector('[data-p]'), cs = getComputedStyle(el);
    var out = { color: cs.color, bg: cs.backgroundColor, border: cs.borderTopColor, width: parseFloat(cs.borderTopWidth) || 0 };
    box.remove();
    return out;
  }
  var text = getComputedStyle(host).color;
  var surface = ground(host);
  var link = probe('<a href="#" data-p>x</a>').color;
  var button = probe('<div class="wp-block-button"><a class="wp-block-button__link wp-element-button" href="#" data-p>x</a></div>');
  var field = probe('<input type="text" data-p>');
  var accent = !clear(button.bg) && button.bg !== surface ? button.bg : link;
  var found = {
    'accent': accent,
    'accent-ink': !clear(button.bg) && button.bg === accent ? button.color : (lum(accent) > 0.6 ? '#16181d' : '#ffffff'),
    'text': text,
    'surface': surface,
    'surface-alt': mix(surface, text, 0.04),
    'muted': mix(text, surface, 0.35),
    'border': field.width > 0 && !clear(field.border) && field.border !== text ? field.border : mix(surface, text, 0.15)
  };
  var kept = {};
  roles.forEach(function (r) {
    if (!found[r]) return;
    root.style.setProperty('--diluxone-users-' + r, found[r]);
    kept[r] = found[r];
    if (r === 'accent') { root.style.setProperty('--diluxone-users-accent-bg', found[r]); kept['accent-bg'] = found[r]; }
  });
  try { localStorage.setItem(key, JSON.stringify(kept)); } catch (e) {}
})
JS;

	wp_print_inline_script_tag(
		$js . '(' . wp_json_encode( $roles ) . ',' . wp_json_encode( diluxone_users_measure_key() ) . ');',
		array( 'id' => 'diluxone-users-theme-colors' )
	);
}
add_action( 'wp_footer', 'diluxone_users_measure', 5 );
