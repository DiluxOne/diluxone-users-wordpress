<?php
/**
 * The tabs of a screen, registered instead of written down.
 *
 * A screen used to carry its tabs in an array in its own file, which meant
 * the file had to know about every feature that might appear on it. Passkeys
 * lived inside the sign-in screen; the social buttons lived inside the social
 * screen; and taking one out meant editing a file that had nothing to do with
 * it.
 *
 * Now a feature registers its own panel from its own file. The screen asks
 * what there is and draws that. Two things follow, and the second is the
 * reason for the first:
 *
 *   - A feature that is not installed leaves no gap. Its file is not there,
 *     so it never registered, so the tab does not exist — rather than a tab
 *     that exists and apologises.
 *   - Anything can add one from outside: an add-on, a site's own plugin, a
 *     theme. The registry is the seam, and it is the same call the plugin
 *     uses for its own.
 *
 * @package DiluxOneUsers
 */

defined( 'ABSPATH' ) || exit;

/**
 * The id of the form a panel's fields live in.
 *
 * It is named rather than anonymous because of what sits beside it: a button
 * in the preview column is outside the form, and `form="…"` is how HTML
 * lets it send that form anyway. Without an id there is no way for the two
 * columns to be one form without nesting them, and nesting them is what the
 * column layout exists to avoid.
 */
const DILUXONE_USERS_PANEL_FORM = 'diluxone-users-panel-form';

/** The name of the window a preview is shown in, for a button that aims at it. */
const DILUXONE_USERS_PANEL_FRAME = 'diluxone-users-preview-frame';

/** Where the values of a trial run wait for the page that is about to read them. */
const DILUXONE_USERS_PREVIEW_TRY = 'diluxone_users_try_';

/**
 * The registry itself.
 *
 * A static array and not an option: panels are code, and code that is not
 * loaded has no panel. Kept private — everything else goes through the two
 * functions below.
 *
 * @param string                    $screen Screen slug, or '' to read everything.
 * @param string                    $id     Panel id when writing.
 * @param array<string, mixed>|null $panel  The panel when writing, null when reading.
 * @return array<string, array<string, mixed>>
 */
function diluxone_users_panel_registry( string $screen = '', string $id = '', ?array $panel = null ): array {
	static $panels = array();

	if ( null !== $panel ) {
		$panels[ $screen ][ $id ] = $panel;

		return array();
	}

	return $panels[ $screen ] ?? array();
}

/**
 * Adds one tab to one screen.
 *
 * Call it on `admin_menu` at an early priority, which is what
 * `diluxone_users_panels_ready()` is for. Not at load time: the plugin loads
 * its files with a glob, so load time means alphabetical order, and a file
 * that sorts before this one would be calling a function that does not exist
 * yet. It did, and it took the whole site down with it.
 *
 * @param string                                                                                                                                        $screen Which screen, e.g. 'diluxone-users-login'.
 * @param string                                                                                                                                        $id     The tab's slug, which ends up in the URL.
 * @param array{label: string, render: callable, save?: callable, preview?: callable, preview_src?: string, note?: string, position?: int, form?: bool} $panel
 */
function diluxone_users_register_panel( string $screen, string $id, array $panel ): void {
	diluxone_users_panel_registry(
		$screen,
		$id,
		wp_parse_args(
			$panel,
			array(
				'label'       => $id,
				'render'      => '',
				// A panel with nothing to save — a summary, a preview — says
				// so, and the screen leaves out the form and the button. One
				// that does save returns nothing, or `false` when it refused
				// what was sent and wrote nothing.
				'save'        => '',
				// What goes in the column beside the fields. A panel without
				// one runs the full width.
				'preview'     => '',
				// A whole page of its own to show instead — wp-login.php is
				// one. It is a URL, so nothing is redrawn as the fields move:
				// the page is what it is until it is saved.
				'preview_src' => '',
				// A line under the preview, in the admin's voice. It lives out
				// here and not inside the preview because inside the preview
				// it would be part of what is being previewed.
				'note'        => '',
				'form'        => true,
				'position'    => 50,
			)
		)
	);
}

/**
 * Every panel on a screen, in order.
 *
 * @param string $screen
 * @return array<string, array<string, mixed>>
 */
function diluxone_users_panels( string $screen ): array {
	// On a network, only the tabs that belong where the admin is looked at
	// from: the network's in Network Admin, a site's on that site.
	$panels = diluxone_users_panels_here( diluxone_users_panel_registry( $screen ), $screen );

	uasort( $panels, static fn( array $a, array $b ): int => $a['position'] <=> $b['position'] );

	/**
	 * Filters the panels of one screen, once they are in order.
	 *
	 * Registering is the way in; this is for taking one out or moving it.
	 *
	 * @param array<string, array<string, mixed>> $panels
	 * @param string                              $screen
	 */
	return (array) apply_filters( 'diluxone_users_panels', $panels, $screen );
}

/**
 * Draws a whole screen out of its panels.
 *
 * Every screen built this way behaves the same: the tabs, the current one,
 * its form, its own saving, its notice. A screen with one panel shows no
 * tabs — one tab is not navigation, it is furniture.
 *
 * @param string $screen Screen slug.
 * @param string $title  What the heading says.
 */
function diluxone_users_screen_panels( string $screen, string $title ): void {
	$panels = diluxone_users_panels( $screen );

	if ( array() === $panels ) {
		return;
	}

	$labels  = wp_list_pluck( $panels, 'label' );
	$current = diluxone_users_tab( $labels );
	$panel   = $panels[ $current ];

	// The menu already asks for the capability before this page is drawn;
	// the save asks again itself, so it does not depend on how it was reached.
	if (
		is_callable( $panel['save'] )
		&& current_user_can( diluxone_users_admin_cap() )
		&& isset( $_POST['diluxone_users_panel_nonce'] )
		&& wp_verify_nonce( sanitize_key( wp_unslash( $_POST['diluxone_users_panel_nonce'] ) ), 'diluxone_users_panel_' . $screen )
	) {
		/*
		 * A save that refused says so by returning false, and then the screen
		 * does not congratulate it. The check is against `false` and not
		 * against anything falsy on purpose: a save with nothing to report
		 * returns nothing, and nothing is how almost all of them are written.
		 * So the panels that never learnt to answer keep their "Saved.", and
		 * the two that guard their input — a registration open with no door,
		 * a second step with no way of sending the code — stop showing it
		 * over the error explaining that nothing was written.
		 */
		if ( false !== call_user_func( $panel['save'] ) ) {
			diluxone_users_notice( __( 'Saved.', 'diluxone-users' ) );
		}

		// Read again: what is on screen has to be what was just written.
		$panels = diluxone_users_panels( $screen );
		$panel  = $panels[ $current ];
	}

	diluxone_users_screen_open( $title, $screen, count( $labels ) > 1 ? $labels : array(), $current );

	$form    = ! empty( $panel['form'] ) && is_callable( $panel['save'] );
	$preview = is_callable( $panel['preview'] ) || '' !== $panel['preview_src'];

	/*
	 * With a preview, the two columns wrap the form rather than the other way
	 * round: the form lives inside the left-hand column and the preview sits
	 * outside it. That is what lets a preview of the account area — which is
	 * a form itself — sit beside the settings instead of underneath them. A
	 * form inside a form is thrown away by the browser; two siblings are not.
	 */
	if ( $form ) {
		diluxone_users_ui_save( DILUXONE_USERS_PANEL_FORM );
	}

	/*
	 * The button is drawn by whichever second column the panel ends up with,
	 * and which one that is is only known once the panel has been drawn: the
	 * rail is opened from inside it. So the panel is drawn into a buffer, and
	 * a button still waiting afterwards goes on the preview if there is one,
	 * or on a rail of its own if the panel had nothing else to put beside
	 * itself — every tab that saves has its button in the same place.
	 */
	ob_start();

	if ( $form ) {
		printf( '<form method="post" id="%s">', esc_attr( DILUXONE_USERS_PANEL_FORM ) );
		wp_nonce_field( 'diluxone_users_panel_' . $screen, 'diluxone_users_panel_nonce' );
	}

	if ( is_callable( $panel['render'] ) ) {
		call_user_func( $panel['render'] );
	}

	if ( $form ) {
		echo '</form>';
	}

	$drawn = (string) ob_get_clean();
	$alone = ! $preview && diluxone_users_ui_save_pending();

	if ( $preview || $alone ) {
		echo '<div class="diluxone-users-studio"><div class="diluxone-users-studio__fields">';
	}

	// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- the panel escaped what it printed.
	echo $drawn;

	if ( $alone ) {
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped inside.
		echo '</div><div class="diluxone-users-studio__aside">' . diluxone_users_ui_save_box() . '</div></div>';
	}

	if ( $preview ) {
		/*
		 * The live attributes only go on a preview the server can redraw. A
		 * panel that shows a real page cannot be redrawn from here — it is
		 * fetched by the browser — so without this the script asked for a
		 * drawing on every keystroke and was answered "no such thing" every
		 * time. That panel has its own way of showing what was chosen.
		 */
		echo '</div><div class="diluxone-users-studio__preview"';

		if ( is_callable( $panel['preview'] ) ) {
			printf(
				' data-diluxone-users-live="%1$s" data-diluxone-users-live-screen="%2$s" data-diluxone-users-live-nonce="%3$s"',
				esc_attr( $current ),
				esc_attr( $screen ),
				esc_attr( wp_create_nonce( 'diluxone_users_preview' ) )
			);
		}

		echo '>';
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped inside.
		echo diluxone_users_ui_save_box();
		diluxone_users_preview_stage( $panel, $screen, $current );
		echo '</div></div>';
	}

	/**
	 * Fires after a panel has been drawn, inside the screen's wrapper.
	 *
	 * @param string $screen
	 */
	do_action( 'diluxone_users_after_panel_' . $current, $screen );

	diluxone_users_screen_close();
}

/**
 * The moment to register a panel.
 *
 * Every panel — the plugin's own and an add-on's — is registered here, on
 * `admin_init`. By then every plugin has loaded, so nothing depends on which
 * file came first.
 *
 * `admin_init` and not `admin_menu`, which was the first attempt: admin_menu
 * does not run on an AJAX request, so the preview endpoint asked for the
 * panels, found none, and answered 404 to every keystroke. admin_init runs
 * for both, and still before the menu is built.
 */
function diluxone_users_panels_ready(): void {
	/**
	 * Fires when panels can be registered.
	 *
	 * @since 1.0.0
	 */
	do_action( 'diluxone_users_register_panels' );
}
add_action( 'admin_init', 'diluxone_users_panels_ready', 1 );

/**
 * A preview, as a page of its own.
 *
 * This is the whole reason the previews are in an iframe. The sign-in frames
 * break out to the full width of the window the way any cover does, with
 * `calc(50% - 50vw)` — and a preview drawn inside the admin column reads the
 * *admin's* window: half of 1900px of photo inside a 460px box, with the form
 * pushed off the side and clipped. Nothing was wrong with the markup; it was
 * being shown a window that was not its own.
 *
 * In here `50vw` is half of the preview, because the preview is the window.
 *
 * @param string $body The preview's markup.
 * @return string A complete document.
 */
function diluxone_users_preview_document( string $body ): string {
	// The document's own queues, not the dashboard's: what they print goes
	// into this document and nowhere else, and printing it does not mark the
	// dashboard's copies as done.
	$styles = new WP_Styles();
	$deps   = array( 'diluxone-users-preview-front', 'diluxone-users-preview-social' );

	$styles->add( 'diluxone-users-preview-front', DILUXONE_USERS_URL . 'assets/diluxone-users.css', array(), diluxone_users_asset_version( 'assets/diluxone-users.css' ) );

	// The social buttons come with their own sheet on the front end too.
	$styles->add( 'diluxone-users-preview-social', DILUXONE_USERS_URL . 'assets/diluxone-users-social.css', array( 'diluxone-users-preview-front' ), diluxone_users_asset_version( 'assets/diluxone-users-social.css' ) );

	$vars = diluxone_users_preview_theme_url();

	if ( '' !== $vars ) {
		$styles->add( 'diluxone-users-preview-theme', $vars, array( 'diluxone-users-preview-social' ), null );
		$deps[] = 'diluxone-users-preview-theme';
	}

	/*
	 * The padding is the page's margin and it is what the breakout undoes: a
	 * cover reaches the edge of this document exactly as it reaches the edge
	 * of the browser on the site.
	 *
	 * The rest is the same CSS the front end is given, built in the same
	 * place. Here it cannot be added to the sheet — the sheet is a file
	 * inside this document and a colour chosen a second ago would show as the
	 * colour saved a week ago — so it rides with the document instead.
	 */
	$styles->add( 'diluxone-users-preview', false, $deps, DILUXONE_USERS_VERSION );
	$styles->add_inline_style(
		'diluxone-users-preview',
		'html{background:#fff}'
		. 'body{margin:0;padding:40px 24px;background:#fff;color:#1e1e1e;'
		. 'font:16px/1.6 -apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,Helvetica,Arial,sans-serif;}'
		. diluxone_users_style_css()
	);

	ob_start();
	$styles->do_items( array( 'diluxone-users-preview' ) );
	$head = (string) ob_get_clean();

	// The scripts the markup asked for while it was drawn, after the markup.
	$tail    = '';
	$wanted  = diluxone_users_preview_scripts( null, true );
	$scripts = array(
		'diluxone-users-ways' => 'assets/diluxone-users-ways.js',
	);

	if ( array() !== $wanted ) {
		$queue = new WP_Scripts();

		foreach ( $wanted as $handle ) {
			if ( isset( $scripts[ $handle ] ) ) {
				$queue->add( $handle, DILUXONE_USERS_URL . $scripts[ $handle ], array(), diluxone_users_asset_version( $scripts[ $handle ] ) );
			}
		}

		ob_start();
		$queue->do_items( $wanted );
		$tail = (string) ob_get_clean();
	}

	return '<!DOCTYPE html><html ' . get_language_attributes( 'html' ) . '><head><meta charset="'
		. esc_attr( get_bloginfo( 'charset' ) ) . '">'
		. $head
		. '</head><body>'
		. $body
		. $tail
		. '</body></html>';
}

/**
 * The scripts a preview's markup asked for while it was being drawn.
 *
 * @param string|null $handle One more to ask for; null to only read.
 * @param bool        $take   Read and forget, for the document that prints them.
 * @return array<int, string>
 */
function diluxone_users_preview_scripts( ?string $handle = null, bool $take = false ): array {
	static $wanted = array();

	if ( null !== $handle ) {
		$wanted[ $handle ] = true;
	}

	$out = array_map( 'strval', array_keys( $wanted ) );

	if ( $take ) {
		$wanted = array();
	}

	return $out;
}

/**
 * The custom properties the plugin's own colours are standing on.
 *
 * A theme is allowed to publish its palette as properties of its own —
 * `var(--ast-global-color-0)` — and the plugin passes those straight through,
 * which is what lets the site's colours follow the theme live, dark mode
 * included. On the site that works, because the theme's stylesheet is right
 * there declaring them.
 *
 * In the preview it did not, and it was the worst kind of not working: the
 * document in the frame is the plugin's own, with the plugin's own sheet and
 * nothing of the theme's, so `var(--ast-global-color-0)` resolved to nothing,
 * every token built on it became invalid, and the accent, the cover and the
 * ink on it all fell back to whatever they inherit. A name in white on a blue
 * band came out grey on white — a preview that says "your theme's colours" and
 * shows something the site has never looked like.
 *
 * So the frame is told what those properties are. This is the list of names to
 * ask about: whatever the plugin's own CSS points at and does not declare.
 *
 * @return array<int, string>
 */
function diluxone_users_preview_theme_vars(): array {
	preg_match_all( '/var\(\s*(--[a-z0-9_-]+)/i', diluxone_users_style_css(), $found );

	$names = array();

	foreach ( $found[1] as $name ) {
		// The plugin's own are declared by the plugin's own sheet, which is in
		// the frame already. Only the ones from somewhere else are missing.
		if ( 0 === strpos( $name, '--diluxone-users-' ) ) {
			continue;
		}

		$names[ $name ] = $name;
	}

	return array_values( $names );
}

/**
 * Where the frame asks for them.
 *
 * A stylesheet and not a value copied into the document, because only the
 * front end knows: the properties are declared by whatever the theme prints
 * while it is drawing a page, and the dashboard is not a page the theme draws.
 * So the browser fetches one small sheet from the front end, where the theme
 * is answering — one request, cached, and nothing for the dashboard to guess
 * at or keep in step.
 *
 * Empty when there is nothing to ask about, which is most sites: a palette of
 * hexes needs no help, and then no request is made at all.
 */
function diluxone_users_preview_theme_url(): string {
	if ( array() === diluxone_users_preview_theme_vars() ) {
		return '';
	}

	return add_query_arg(
		array(
			'diluxone-users-vars' => '1',
			'_wpnonce'            => wp_create_nonce( 'diluxone_users_preview' ),
		),
		home_url( '/' )
	);
}

/**
 * Those properties, read out of a page the theme has just drawn.
 *
 * Only what is declared on the document itself — `:root`, `html`, `body` — and
 * only the names that were asked for, following a property that points at
 * another one until it lands on a colour. Everything else in the theme's CSS
 * is left where it is: this is the palette arriving, not the theme's design
 * moving into the preview.
 *
 * Inline CSS only. A property that lives in a stylesheet file is not in here
 * and cannot be, but a palette is a thing a site changes from its settings, so
 * in practice it is printed with the page — which is also why it can be read
 * at all.
 *
 * @param string            $html  What the theme printed into the head.
 * @param array<int,string> $names The properties to bring back.
 */
function diluxone_users_preview_vars_css( string $html, array $names ): string {
	if ( array() === $names || ! preg_match_all( '#<style[^>]*>(.*?)</style>#is', $html, $styles ) ) {
		return '';
	}

	$css = (string) preg_replace( '#/\*.*?\*/#s', '', implode( "\n", $styles[1] ) );

	/*
	 * What an at-rule holds is left where it is. A theme that answers
	 * `prefers-color-scheme` has two palettes and the frame is showing one
	 * page, so taking the last one written would be showing the site's dark
	 * colours to somebody looking at it in daylight — confidently wrong, which
	 * is the failure this whole thing is about. What the document declares
	 * plainly is the answer that is always true.
	 */
	$css = (string) preg_replace( '/@[a-z-]+[^{]*\{(?:[^{}]*\{[^{}]*\})*[^{}]*\}/is', '', $css );

	preg_match_all( '/([^{}]*)\{([^{}]*)\}/', $css, $blocks, PREG_SET_ORDER );

	$declared = array();

	foreach ( $blocks as $block ) {
		if ( ! preg_match( '/(^|,)\s*(:root|html|body)\s*(,|$)/i', trim( $block[1] ) ) ) {
			continue;
		}

		preg_match_all( '/(--[a-z0-9_-]+)\s*:\s*([^;]+)/i', $block[2], $lines, PREG_SET_ORDER );

		foreach ( $lines as $line ) {
			$value = diluxone_users_color_value( trim( $line[2] ) );

			// A property that is its own value is a theme declaring a fallback
			// for itself; taking it would be writing the same nothing again.
			if ( '' === $value || 'var(' . $line[1] . ')' === str_replace( ' ', '', $value ) ) {
				continue;
			}

			// Later wins, as it does in the browser: a palette is usually
			// printed twice, once as the theme's default and once as the site's
			// answer.
			$declared[ $line[1] ] = $value;
		}
	}

	$wanted = $names;
	$seen   = array();
	$out    = '';

	while ( array() !== $wanted ) {
		$name = (string) array_shift( $wanted );

		if ( isset( $seen[ $name ] ) || ! isset( $declared[ $name ] ) ) {
			continue;
		}

		$seen[ $name ] = true;
		$out          .= $name . ':' . $declared[ $name ] . ';';

		// A property that points at another one brings that one along, or the
		// chain ends here and the colour is lost at the last link.
		if ( preg_match( '/^var\(\s*(--[a-z0-9_-]+)/i', $declared[ $name ], $next ) ) {
			$wanted[] = $next[1];
		}
	}

	return '' === $out ? '' : ':root{' . $out . '}';
}

/**
 * The front-end request that answers with them.
 *
 * It draws the head of a real page and throws everything away except the
 * properties that were asked for. A real page because that is the only place
 * the theme is asked to say what its colours are: `wp_head()` on the front end
 * is the moment a theme prints its palette, and there is no admin equivalent.
 *
 * For an administrator and nobody else, cached for a few minutes by the
 * browser so that typing in a colour box is not a page render per keystroke.
 */
function diluxone_users_preview_vars_request(): void {
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- the nonce is checked below, and the answer is the same for everybody who may have it.
	if ( ! isset( $_GET['diluxone-users-vars'] ) ) {
		return;
	}

	$nonce = isset( $_GET['_wpnonce'] ) ? sanitize_key( wp_unslash( $_GET['_wpnonce'] ) ) : '';

	if ( ! current_user_can( 'manage_options' ) || ! wp_verify_nonce( $nonce, 'diluxone_users_preview' ) ) {
		wp_die( '', '', array( 'response' => 403 ) );
	}

	ob_start();
	wp_head();
	$head = (string) ob_get_clean();

	if ( ! headers_sent() ) {
		header( 'Content-Type: text/css; charset=' . get_bloginfo( 'charset' ) );
		header( 'Cache-Control: private, max-age=300' );
	}

	// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- CSS, and every name and value in it was matched against a pattern on the way out of the page.
	echo diluxone_users_preview_vars_css( $head, diluxone_users_preview_theme_vars() );
	exit;
}
add_action( 'template_redirect', 'diluxone_users_preview_vars_request' );

/**
 * The preview column: a window with the page inside it, to scale.
 *
 * Two sizes and not a slider, because there are two questions being asked of
 * a design — does it hold together wide, and does it survive a phone — and a
 * slider makes you find the answer instead of handing it over. The scaling
 * itself is the browser's, from the script: the column's width divided by the
 * width being pretended at.
 *
 * @param array<string, mixed> $panel
 * @param string               $screen Which screen it belongs to.
 * @param string               $id     Which panel, so a trial run knows what to draw.
 */
function diluxone_users_preview_stage( array $panel, string $screen, string $id ): void {
	$src = (string) $panel['preview_src'];

	if ( '' === $src ) {
		ob_start();
		call_user_func( $panel['preview'] );
		$document = diluxone_users_preview_document( (string) ob_get_clean() );
	}
	?>
	<div class="diluxone-users-stage" data-diluxone-users-stage>
		<div class="diluxone-users-stage__bar">
			<?php
			// With a context, because "Phone" is also the name of a field
			// type: one is a width and the other is a box somebody types a
			// number into, and a translator seeing the bare word gets it
			// wrong exactly half the time.
			?>
			<button type="button" class="diluxone-users-stage__device is-on" data-diluxone-users-device="1200">
				<?php echo esc_html_x( 'Desktop', 'the preview at desktop width', 'diluxone-users' ); ?>
			</button>
			<button type="button" class="diluxone-users-stage__device" data-diluxone-users-device="390">
				<?php echo esc_html_x( 'Phone', 'the preview at phone width', 'diluxone-users' ); ?>
			</button>

			<?php
			// Beside the column the page is a thumbnail, which answers "does
			// it hold together" and not "is that line too long". This is the
			// same page, big.
			?>
			<button type="button" class="diluxone-users-stage__zoom" data-diluxone-users-zoom>
				<?php esc_html_e( 'See it big', 'diluxone-users' ); ?>
			</button>
		</div>

		<div class="diluxone-users-stage__canvas" data-diluxone-users-stage-canvas>
			<?php
			/*
			 * Not clickable and not reachable by the keyboard: it is the real
			 * page, with the real forms in it, and it is here to be looked at.
			 */
			?>
			<iframe
				class="diluxone-users-stage__frame"
				data-diluxone-users-stage-frame
				name="<?php echo esc_attr( DILUXONE_USERS_PANEL_FRAME ); ?>"
				title="<?php esc_attr_e( 'Preview', 'diluxone-users' ); ?>"
				tabindex="-1"
				scrolling="no"
				<?php if ( '' !== $src ) : ?>
					src="<?php echo esc_url( $src ); ?>"
				<?php else : ?>
					srcdoc="<?php echo esc_attr( $document ); ?>"
				<?php endif; ?>
				></iframe>
		</div>
	</div>

	<?php
	if ( '' !== $src ) :
		/*
		 * A preview that is the page itself shows what is saved, and only that: it
		 * is fetched by the browser, so nothing typed on this screen is in
		 * it. The screen used to say so in the line underneath and that was
		 * not enough — somebody unticks the box, watches the frame not change,
		 * and concludes the setting does not work. A sentence cannot argue
		 * with a picture.
		 *
		 * So there is a way to see what was chosen. The button belongs to the
		 * form in the other column and sends it somewhere else, at a window
		 * that is this frame: the answer comes back into the preview and the
		 * screen itself is not reloaded, so nothing typed is lost. Nothing is
		 * written either — the values are kept for a minute, for the one
		 * request, and the page that comes back says on its face that it is a
		 * trial.
		 */
		?>
		<p class="diluxone-users-stage__note">
			<button
				type="submit"
				class="button"
				form="<?php echo esc_attr( DILUXONE_USERS_PANEL_FORM ); ?>"
				formmethod="post"
				formtarget="<?php echo esc_attr( DILUXONE_USERS_PANEL_FRAME ); ?>"
				formaction="<?php echo esc_url( diluxone_users_preview_try_url( $screen, $id ) ); ?>"
				data-diluxone-users-try
				><?php esc_html_e( 'Show me what I chose', 'diluxone-users' ); ?></button>
		</p>
	<?php endif; ?>

	<?php if ( '' !== (string) $panel['note'] ) : ?>
		<p class="description diluxone-users-stage__note"><?php echo esc_html( (string) $panel['note'] ); ?></p>
	<?php endif; ?>

	<?php
	// Empty on purpose: what is in it is whatever the preview is showing at
	// the moment it is opened, copied across by the script. Two iframes and
	// not one moved around, because moving an iframe in the DOM reloads it
	// and a preview that flickers every time it is opened is a worse preview.
	?>
	<dialog class="diluxone-users-zoom" data-diluxone-users-zoom-box>
		<div class="diluxone-users-zoom__bar">
			<span class="diluxone-users-zoom__what"><?php echo esc_html( (string) $panel['label'] ); ?></span>
			<button type="button" class="button" data-diluxone-users-zoom-close><?php esc_html_e( 'Close', 'diluxone-users' ); ?></button>
		</div>
		<div class="diluxone-users-zoom__canvas" data-diluxone-users-zoom-canvas>
			<iframe class="diluxone-users-zoom__frame" data-diluxone-users-zoom-frame title="<?php esc_attr_e( 'Preview', 'diluxone-users' ); ?>" tabindex="-1" scrolling="no"></iframe>
		</div>
	</dialog>
	<?php
}

/**
 * The plugin's settings as the live drawing sends them.
 *
 * Only the plugin's own keys, and only as text: this decides what a preview
 * looks like, never what is stored. The script sends its own map — every
 * field, and an unticked box as a nought rather than left out — so the keys
 * arrive named and there is nothing to work out here.
 *
 * @param array<string|int, mixed> $posted The map, unslashed.
 * @return array<string, string|array<int, string>>
 */
function diluxone_users_preview_values( array $posted ): array {
	$values = array();

	foreach ( $posted as $key => $value ) {
		$key = sanitize_key( (string) $key );

		if ( 0 !== strpos( $key, 'diluxone_users_' ) ) {
			continue;
		}

		$values[ $key ] = is_array( $value )
			? array_map( 'sanitize_text_field', array_map( 'strval', $value ) )
			: sanitize_text_field( (string) $value );
	}

	return $values;
}

/**
 * Those values, standing in front of what is saved, for one request.
 *
 * Nothing is written: the filter is added, used, and gone when the request
 * ends.
 *
 * @param array<string, string|array<int, string>> $values
 */
function diluxone_users_preview_override( array $values ): callable {
	return static function ( $value, string $key ) use ( $values ) {
		return array_key_exists( $key, $values ) ? $values[ $key ] : $value;
	};
}

/**
 * Where the "show me what I chose" button sends the form.
 *
 * It goes to admin-post and not to the page itself: what comes back has to be
 * the previewed page, and the only thing that can turn a form into a page that
 * draws itself is something that keeps the values and then points at it.
 *
 * @param string $screen Screen slug.
 * @param string $id     Panel id.
 */
function diluxone_users_preview_try_url( string $screen, string $id ): string {
	return add_query_arg(
		array(
			'action' => 'diluxone_users_preview_try',
			'screen' => $screen,
			'panel'  => $id,
		),
		admin_url( 'admin-post.php' )
	);
}

/**
 * What a panel would write, without writing any of it.
 *
 * A form cannot be read from the outside. An unticked box sends nothing at
 * all, so a preview built from what arrived would show every switch still on —
 * which is precisely the complaint this is here to answer, back again by
 * another road. The panel's own save is the only thing that knows a box was
 * there to be unticked, because it is the code that wrote `isset() ? 1 : 0`.
 *
 * So the save is the one that reads the form. Every write is caught on its way
 * out and handed back its own old value, which is how update_option is told
 * there is nothing to do: the answers come out, the options stay exactly as
 * they were, and nothing had to know the difference between saving and trying.
 *
 * It is a setting that a panel offering a page of its own as its preview keeps
 * its saving to options. That is what every one of them does, and it is what
 * makes a trial run possible at all.
 *
 * @param array<string, mixed> $panel
 * @return array<string, mixed> Option name => what would have been stored.
 */
function diluxone_users_preview_would_save( array $panel ): array {
	if ( ! is_callable( $panel['save'] ) ) {
		return array();
	}

	$caught = array();

	// Nothing is written — the plugin's options are caught, and anything else
	// a save touches (WordPress's own "Anyone can register", say) is refused
	// as well: a preview is not a save.
	$refuse = static function ( $value, string $option, $old ) use ( &$caught ) {
		if ( 0 === strpos( $option, 'diluxone_users_' ) ) {
			$caught[ $option ] = $value;
		}

		return $old;
	};

	// On a network the network's settings go to its own options, which have
	// no filter for every write, only one per name: so each is caught by name.
	$network        = array_keys( array_filter( diluxone_users_option_scopes(), static fn( string $scope ): bool => 'network' === $scope ) );
	$refuse_network = static function ( $value, $old, string $option ) use ( &$caught ) {
		$caught[ $option ] = $value;

		return $old;
	};

	add_filter( 'pre_update_option', $refuse, 999, 3 );

	foreach ( $network as $key ) {
		add_filter( 'pre_update_site_option_' . $key, $refuse_network, 999, 3 );
	}

	call_user_func( $panel['save'] );

	remove_filter( 'pre_update_option', $refuse, 999 );

	foreach ( $network as $key ) {
		remove_filter( 'pre_update_site_option_' . $key, $refuse_network, 999 );
	}

	return $caught;
}

/**
 * Keeps what was chosen for a minute and sends the frame to the page.
 *
 * The values do not travel in the address: there are dozens of them, some of
 * them are paragraphs, and an address that long is an address that breaks. So
 * they are put down under a key nobody can guess, the frame is sent to the
 * real page with that key, and the page picks them up on its way through.
 */
function diluxone_users_preview_try(): void {
	$screen = isset( $_GET['screen'] ) ? sanitize_key( wp_unslash( $_GET['screen'] ) ) : '';
	$id     = isset( $_GET['panel'] ) ? sanitize_key( wp_unslash( $_GET['panel'] ) ) : '';

	check_admin_referer( 'diluxone_users_panel_' . $screen, 'diluxone_users_panel_nonce' );

	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( '', '', array( 'response' => 403 ) );
	}

	$panels = diluxone_users_panels( $screen );
	$src    = isset( $panels[ $id ] ) ? (string) $panels[ $id ]['preview_src'] : '';

	if ( '' === $src ) {
		wp_die( '', '', array( 'response' => 404 ) );
	}

	$token = wp_generate_password( 24, false );

	set_transient(
		DILUXONE_USERS_PREVIEW_TRY . $token,
		array(
			'user'   => get_current_user_id(),
			'values' => diluxone_users_preview_would_save( $panels[ $id ] ),
		),
		MINUTE_IN_SECONDS
	);

	wp_safe_redirect( add_query_arg( 'diluxone-users-try', $token, $src ) );
	exit;
}
add_action( 'admin_post_diluxone_users_preview_try', 'diluxone_users_preview_try' );

/**
 * A page drawing itself with values that were never saved.
 *
 * On `init`, which is early enough for wp-login.php and for the front end
 * alike — both of them are past it before anything asks what a setting says —
 * and the same hook whichever page the frame was pointed at, so a panel that
 * previews some other real page gets this for nothing.
 *
 * Only for the administrator who pressed the button, only for the minute the
 * values live, and nothing is written at any point.
 */
function diluxone_users_preview_try_apply(): void {
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- the key IS the credential: it was made for this administrator and it is only good for a minute.
	$token = isset( $_GET['diluxone-users-try'] ) ? sanitize_key( wp_unslash( $_GET['diluxone-users-try'] ) ) : '';

	if ( '' === $token || ! current_user_can( 'manage_options' ) ) {
		return;
	}

	$kept = get_transient( DILUXONE_USERS_PREVIEW_TRY . $token );

	if ( ! is_array( $kept ) || get_current_user_id() !== (int) ( $kept['user'] ?? 0 ) ) {
		return;
	}

	add_filter( 'diluxone_users_option', diluxone_users_preview_override( (array) ( $kept['values'] ?? array() ) ), 999, 2 );

	// The page says so itself. Anywhere else it would be the dashboard's word
	// against the picture, which is the argument this whole thing lost before.
	add_action( 'wp_enqueue_scripts', 'diluxone_users_preview_try_style' );
	add_action( 'login_enqueue_scripts', 'diluxone_users_preview_try_style' );
	add_action( 'wp_footer', 'diluxone_users_preview_try_mark' );
	add_action( 'login_footer', 'diluxone_users_preview_try_mark' );
}
add_action( 'init', 'diluxone_users_preview_try_apply' );

/**
 * The look of the strip that says the page is a trial.
 *
 * It is printed into somebody else's page — WordPress's own sign-in screen,
 * or a theme's — where no stylesheet of ours may be loaded, so it brings its
 * few rules with it, through the queue.
 */
function diluxone_users_preview_try_style(): void {
	wp_register_style( 'diluxone-users-trial', false, array(), DILUXONE_USERS_VERSION );
	wp_enqueue_style( 'diluxone-users-trial' );
	wp_add_inline_style(
		'diluxone-users-trial',
		'[data-diluxone-users-trial]{position:fixed;inset:0 0 auto 0;z-index:99999;margin:0;padding:8px 12px;'
			. 'background:#1d2327;color:#fff;font:13px/1.4 -apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,sans-serif;'
			. 'text-align:center}'
	);
}

/** The strip that says the page is a trial: the only thing in it that is not the preview. */
function diluxone_users_preview_try_mark(): void {
	printf(
		'<p data-diluxone-users-trial>%s</p>',
		esc_html__( 'This is what you chose, not what is saved. Save to make it so.', 'diluxone-users' )
	);
}

/**
 * Redraws a preview with settings that have not been saved.
 *
 * The previews used to be updated in the browser: JavaScript moved a class
 * around and hid an element or two. That works for a colour and lies about
 * everything else — asking for the menu at the side did nothing at all,
 * because the markup for a menu at the side is not on the page when the saved
 * setting says tabs. There is nothing to move.
 *
 * So the server draws it again. The values from the form are pushed in front
 * of `diluxone_users_option`, the panel's own preview runs against them, and
 * what comes back is the real thing rendered with what is on screen — not a
 * guess at what it would look like. Nothing is written: the filter is added,
 * used and left behind when the request ends.
 */
function diluxone_users_preview_request(): void {
	check_ajax_referer( 'diluxone_users_preview', 'nonce' );

	if ( ! current_user_can( 'manage_options' ) ) {
		wp_send_json_error( '', 403 );
	}

	$screen = isset( $_POST['screen'] ) ? sanitize_key( wp_unslash( $_POST['screen'] ) ) : '';
	$id     = isset( $_POST['panel'] ) ? sanitize_key( wp_unslash( $_POST['panel'] ) ) : '';
	$panels = diluxone_users_panels( $screen );

	if ( ! isset( $panels[ $id ] ) || ! is_callable( $panels[ $id ]['preview'] ) ) {
		wp_send_json_error( '', 404 );
	}

	// The form as the form posts it, cleaned as it is read.
	$form = (array) map_deep( wp_unslash( $_POST['values'] ?? array() ), 'sanitize_textarea_field' );

	/*
	 * Run through the panel's own save, which writes nothing here (see
	 * diluxone_users_preview_would_save()): what comes back is exactly what
	 * saving would write, including the settings one question on the screen
	 * stands for — "where the look comes from" is two options underneath, and
	 * a list of ways in is an array. Reading the form's fields as if they were
	 * options got both wrong. A panel with no save shows the fields as sent.
	 */
	$request = $_POST;
	$_POST   = wp_slash( $form );
	$values  = diluxone_users_preview_would_save( $panels[ $id ] );
	$_POST   = $request;

	if ( array() === $values ) {
		$values = diluxone_users_preview_values( $form );
	}

	$override = diluxone_users_preview_override( $values );

	add_filter( 'diluxone_users_option', $override, 999, 2 );

	ob_start();
	call_user_func( $panels[ $id ]['preview'] );
	$html = (string) ob_get_clean();

	// The document is built while the override is still in place: the colour
	// and the corners are read from the form too.
	$document = diluxone_users_preview_document( $html );

	remove_filter( 'diluxone_users_option', $override, 999 );

	wp_send_json_success( $document );
}
add_action( 'wp_ajax_diluxone_users_preview', 'diluxone_users_preview_request' );
