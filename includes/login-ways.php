<?php
/**
 * The ways into this site, registered instead of written down.
 *
 * The sign-in template used to stack them in an order somebody typed: the
 * passkey, then the networks, then the e-mail form, then username and
 * password, with a divider between each pair. Two things were wrong with
 * that, and the second is the reason for this file.
 *
 * The first is height. Nobody decided the screen would be that tall — it is
 * what four blocks in a column add up to — and on a laptop the last way in
 * was below the fold of the one page that exists to be understood in a look.
 *
 * The second is that the template had to know about every way in there is.
 * Passkeys were an `if` in it; the networks were another; an add-on with a way
 * of its own had no way of getting drawn except by replacing the file. Now
 * each one registers itself the way a panel of the dashboard does — an id, a
 * label, a neutral icon and something that draws it — and the template asks
 * what there is. That registry is the seam: `Users+ Pro` adds its way in from
 * its own file, in the site's own order, without touching the template.
 *
 * Two arrangements come out of the same registry. Stacked is what there was,
 * and it is right while there are two ways in: tabs over two doors are more
 * friction than the doors. Tabs are what fixes the height from three ways up.
 * The site can force either, and by default the plugin counts.
 *
 * @package DiluxOneUsers
 */

defined( 'ABSPATH' ) || exit;

/**
 * Where the browser remembers which way in this person used last.
 *
 * It holds one id — `email`, `password`, the id an add-on registered — and
 * nothing else. Not an address, not a user, nothing that says who this is:
 * the cookie exists so the tab that opens is the one they already know how to
 * use, and that needs the name of a door and no more.
 */
const DILUXONE_USERS_WAY_COOKIE = 'diluxone_users_way';

/**
 * The registry itself.
 *
 * A static array and not an option, for the same reason the panels are one:
 * a way in is code, and code that is not loaded has no way in to offer. A
 * feature that is not installed leaves no gap on the screen, because it never
 * registered — rather than a tab that exists and apologises.
 *
 * @param string                    $id  Way id when writing.
 * @param array<string, mixed>|null $way The way when writing, null when reading.
 * @return array<string, array<string, mixed>>
 */
function diluxone_users_way_registry( string $id = '', ?array $way = null ): array {
	static $ways = array();

	if ( null !== $way ) {
		$ways[ $id ] = $way;

		return array();
	}

	return $ways;
}

/**
 * Adds one way into the site.
 *
 * Call it on `diluxone_users_register_ways`, which is what
 * `diluxone_users_ways_ready()` fires. Not at load time: the plugin loads its
 * files with a glob, so load time means alphabetical order and a file that
 * sorts before this one would be calling a function that does not exist yet.
 *
 * The icon is a name from `diluxone_users_icon()` and never a picture handed
 * in from outside. That is deliberate and it is the whole rule about these
 * icons: a tab strip is a row of equals, and a site with five networks cannot
 * put five logos on one tab. A name this plugin does not know draws no icon,
 * which is a tab with a word on it and still a tab.
 *
 * @param string                                                                                                                                   $id  The way's slug. It ends up in a cookie and in the site's saved order.
 * @param array{label: string, icon?: string, render: callable, available?: callable, states?: array<int, string>, outside?: bool, position?: int} $way
 */
function diluxone_users_register_way( string $id, array $way ): void {
	diluxone_users_way_registry(
		$id,
		wp_parse_args(
			$way,
			array(
				'label'     => $id,
				'icon'      => '',
				'render'    => '',
				// What the divider above this one says when they are stacked.
				// It is per way and not one word for all of them because
				// stacked it is the only label a way in gets: "or with your
				// password" over WordPress's form is what tells somebody the
				// two boxes underneath are not the two boxes above. In tabs
				// the strip says it instead and every divider goes.
				'over'      => '',
				// Whether this site offers it at all, asked every time: the
				// networks come and go with a setting, and a passkey needs
				// HTTPS. A way that answers no is not drawn and takes no room.
				'available' => '__return_true',
				// The `diluxone-users=` states this way is the author of. The
				// screen comes back from a failed attempt with one of them,
				// and the tab that opens has to be the one holding the form
				// that failed — a message about an address, over a password
				// form, explains nothing.
				'states'    => array(),
				// Outside the tabs, always visible, above them. The passkey is
				// the one the plugin ships: it is the fastest way in there is,
				// a tab would cost it a click, and inside one the browser
				// never gets to offer it as the e-mail field is focused.
				'outside'   => false,
				'position'  => 50,
			)
		)
	);
}

/**
 * The moment to register a way in.
 *
 * On `init` and not on a hook of the front end: the dashboard asks the same
 * registry to draw the list the site drags into order, and the preview asks
 * it over AJAX. All three run through `init`.
 */
function diluxone_users_ways_ready(): void {
	/**
	 * Fires when a way into the site can be registered.
	 *
	 * @since 1.0.0
	 */
	do_action( 'diluxone_users_register_ways' );
}
add_action( 'init', 'diluxone_users_ways_ready', 5 );

/**
 * The order the site dragged them into.
 *
 * @return array<int, string>
 */
function diluxone_users_way_order(): array {
	$saved = array_map( 'strval', (array) diluxone_users_option( 'diluxone_users_login_order', array() ) );

	return array_values( array_filter( $saved, static fn( string $id ): bool => '' !== $id ) );
}

/**
 * Puts a set of ways in the order they are drawn in.
 *
 * The ones that stay outside the tabs come first whatever their position
 * says: they are the top of the screen and not a row in it. The rest take the
 * order the site dragged them into, and anything the site has never seen —
 * an add-on installed this morning — follows in the order its author asked
 * for, rather than disappearing because it is not in a list saved last year.
 *
 * @param array<string, array<string, mixed>> $ways
 * @return array<string, array<string, mixed>>
 */
function diluxone_users_ways_in_order( array $ways ): array {
	uasort(
		$ways,
		static function ( array $a, array $b ): int {
			$side = ( empty( $a['outside'] ) ? 1 : 0 ) <=> ( empty( $b['outside'] ) ? 1 : 0 );

			return 0 !== $side ? $side : (int) $a['position'] <=> (int) $b['position'];
		}
	);

	$outside = array_filter( $ways, static fn( array $way ): bool => ! empty( $way['outside'] ) );
	$dragged = array();

	foreach ( diluxone_users_way_order() as $id ) {
		if ( isset( $ways[ $id ] ) && empty( $ways[ $id ]['outside'] ) ) {
			$dragged[ $id ] = $ways[ $id ];
		}
	}

	return $outside + $dragged + array_diff_key( $ways, $outside, $dragged );
}

/**
 * Every way in there is, in order, whether this site offers it or not.
 *
 * The dashboard wants this one: a way that is switched off still has a place
 * in the order, so turning it off and on again does not move it to the end.
 *
 * @return array<string, array<string, mixed>>
 */
function diluxone_users_ways_known(): array {
	return diluxone_users_ways_in_order( diluxone_users_way_registry() );
}

/**
 * The ways in this site actually offers, in order.
 *
 * @return array<string, array<string, mixed>>
 */
function diluxone_users_ways(): array {
	$ways = array_filter(
		diluxone_users_ways_known(),
		static fn( array $way ): bool => is_callable( $way['available'] ) && (bool) call_user_func( $way['available'] )
	);

	/**
	 * Filters the ways into the site, once they are in order.
	 *
	 * Registering is the way in; this is for taking one out or moving it.
	 *
	 * @param array<string, array<string, mixed>> $ways
	 */
	return (array) apply_filters( 'diluxone_users_ways', $ways );
}

/**
 * Stacked or in tabs.
 *
 * Counted rather than chosen, by default, because the answer follows from how
 * many doors there are: two doors read better one under the other than behind
 * a strip that asks you to pick before you can see either, and from three up
 * the column is taller than the laptop it is read on. The count is of the
 * ways that would become tabs — whatever stays outside them is above the
 * strip in both arrangements, so it changes nothing about this question.
 *
 * @param int $tabs How many ways would be tabs.
 * @return string stack | tabs
 */
function diluxone_users_way_layout( int $tabs ): string {
	$chosen = (string) diluxone_users_option( 'diluxone_users_login_layout' );

	if ( ! in_array( $chosen, array( 'auto', 'stack', 'tabs' ), true ) ) {
		$chosen = 'auto';
	}

	if ( 'auto' === $chosen ) {
		return $tabs >= 3 ? 'tabs' : 'stack';
	}

	return $chosen;
}

/**
 * The way in that the screen is answering about, if any.
 *
 * The page comes back from a refused attempt with `diluxone-users=<what
 * happened>` in the address and a sentence at the top of it. Whichever way in
 * produced that sentence is the one that has to be showing: a message about an
 * address, read over a password form, explains nothing and looks like a bug.
 *
 * It is told apart from every other reason a tab opens because the script has
 * to know as well — the cookie is allowed to overrule the markup, and this is
 * the one case where it is not.
 *
 * @param array<string, array<string, mixed>> $tabs
 */
function diluxone_users_way_answering( array $tabs ): string {
	$state = diluxone_users_state();

	if ( '' === $state ) {
		return '';
	}

	foreach ( $tabs as $id => $way ) {
		if ( in_array( $state, (array) $way['states'], true ) ) {
			return (string) $id;
		}
	}

	return '';
}

/**
 * Which tab opens.
 *
 * Three answers, in this order, and the order is the point:
 *
 *   1. The way that just failed. Coming back to a message about an address
 *      with the address form behind another tab is a screen that explains
 *      nothing.
 *   2. The way this person used last, from the cookie. Somebody who signs in
 *      with a password every week should not press the same tab every week.
 *   3. What the site chose for a first visit, and failing that the first one
 *      in the site's own order.
 *
 * @param array<string, array<string, mixed>> $tabs The ways that are tabs.
 */
function diluxone_users_way_open( array $tabs ): string {
	if ( array() === $tabs ) {
		return '';
	}

	$answering = diluxone_users_way_answering( $tabs );

	if ( '' !== $answering ) {
		return $answering;
	}

	$last = isset( $_COOKIE[ DILUXONE_USERS_WAY_COOKIE ] )
		? sanitize_key( wp_unslash( $_COOKIE[ DILUXONE_USERS_WAY_COOKIE ] ) )
		: '';

	if ( isset( $tabs[ $last ] ) ) {
		return $last;
	}

	$first = sanitize_key( (string) diluxone_users_option( 'diluxone_users_login_open' ) );

	return isset( $tabs[ $first ] ) ? $first : (string) array_key_first( $tabs );
}

/**
 * The script that turns the stack into tabs.
 *
 * Twice, because it is drawn in two places that are not the same kind of
 * place. On the site it goes in the queue, late and in the footer, the way
 * every other script this plugin has does — the sign-in form can come from a
 * shortcode, so there is no knowing at `wp_enqueue_scripts` whether it will
 * be needed.
 *
 * In the dashboard the same markup is drawn inside a preview, and a preview is
 * a document of its own. Enqueuing into the dashboard's queue enqueues into a
 * page that never shows it, and the preview comes out stacked — a picture of
 * the arrangement nobody chose, on the one screen whose job is to show what
 * was chosen. So there it is asked of the preview's own queue, which prints
 * it after the markup it works on (see diluxone_users_preview_document()).
 */
function diluxone_users_ways_enqueue(): void {
	if ( is_admin() ) {
		diluxone_users_preview_scripts( 'diluxone-users-ways' );

		return;
	}

	if ( wp_script_is( 'diluxone-users-ways', 'enqueued' ) ) {
		return;
	}

	wp_enqueue_script(
		'diluxone-users-ways',
		DILUXONE_USERS_URL . 'assets/diluxone-users-ways.js',
		array(),
		diluxone_users_asset_version( 'assets/diluxone-users-ways.js' ),
		true
	);
}

/**
 * The line between two ways stacked one above the other.
 *
 * @param string $says         What it says. Empty is the plain "or".
 * @param bool   $between_tabs Whether the script takes it away in tabs.
 */
function diluxone_users_way_divider( string $says = '', bool $between_tabs = false ): void {
	printf(
		'<p class="diluxone-users-divider"%1$s><span>%2$s</span></p>',
		$between_tabs ? ' data-diluxone-users-ways-or' : '',
		esc_html( '' !== $says ? $says : __( 'or', 'diluxone-users' ) )
	);
}

/**
 * The tab strip.
 *
 * It ships with the `hidden` attribute on it and the script takes it off. That
 * is the whole of the no-JavaScript story and it is the same rule the
 * dashboard follows: what a script would hide starts visible, what a script
 * would build starts inert. With the script off, this row never appears and
 * every way in is on the page, stacked — which is the arrangement that needs
 * nothing to work.
 *
 * The roles are not in here either. A `role="tab"` that opens nothing, or a
 * `role="tabpanel"` on one of four panels that are all showing, is a worse lie
 * to a screen reader than a plain button: the script adds all of them at the
 * moment they become true.
 *
 * @param array<string, array<string, mixed>> $tabs
 */
function diluxone_users_ways_strip( array $tabs ): void {
	printf(
		'<div class="diluxone-users-ways__strip" data-diluxone-users-ways-strip aria-label="%s" hidden>',
		esc_attr__( 'How to sign in', 'diluxone-users' )
	);

	foreach ( $tabs as $id => $tab ) {
		printf(
			'<button type="button" class="diluxone-users-ways__tab" id="diluxone-users-way-tab-%1$s" data-diluxone-users-way-tab="%1$s">%2$s<span>%3$s</span></button>',
			esc_attr( (string) $id ),
			diluxone_users_icon( (string) $tab['icon'], 18 ), // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- our own markup, drawn from a table of names.
			esc_html( (string) $tab['label'] )
		);
	}

	echo '</div>';
}

/**
 * One way in, in its box.
 *
 * @param string               $id
 * @param array<string, mixed> $way
 */
function diluxone_users_way_render( string $id, array $way ): void {
	if ( ! is_callable( $way['render'] ) ) {
		return;
	}

	printf(
		'<div class="diluxone-users-way%1$s" id="diluxone-users-way-%2$s" data-diluxone-users-way="%2$s">',
		empty( $way['outside'] ) ? '' : ' diluxone-users-way--outside',
		esc_attr( $id )
	);

	call_user_func( $way['render'] );

	echo '</div>';
}

/**
 * Every way into this site, drawn.
 *
 * This is what the template calls, and it is all the template knows about the
 * subject. A theme with its own copy of login.php still gets whatever is
 * registered, in whatever arrangement the site chose, including the way an
 * add-on added last week.
 */
function diluxone_users_ways_render(): void {
	$ways = diluxone_users_ways();

	if ( array() === $ways ) {
		return;
	}

	$outside = array_filter( $ways, static fn( array $way ): bool => ! empty( $way['outside'] ) );
	$tabs    = array_diff_key( $ways, $outside );
	$layout  = count( $tabs ) > 1 ? diluxone_users_way_layout( count( $tabs ) ) : 'stack';
	$open    = diluxone_users_way_open( $tabs );

	printf(
		'<div class="diluxone-users-ways" data-diluxone-users-ways data-diluxone-users-ways-mode="%1$s" data-diluxone-users-ways-open="%2$s" data-diluxone-users-ways-cookie="%3$s" data-diluxone-users-ways-path="%4$s"%5$s>',
		esc_attr( $layout ),
		esc_attr( $open ),
		esc_attr( DILUXONE_USERS_WAY_COOKIE ),
		esc_attr( defined( 'COOKIEPATH' ) && '' !== COOKIEPATH ? COOKIEPATH : '/' ),
		// Said out loud so the script knows not to let the cookie overrule it.
		'' !== diluxone_users_way_answering( $tabs ) ? ' data-diluxone-users-ways-state' : ''
	);

	$drawn = 0;

	foreach ( $outside as $id => $way ) {
		if ( $drawn > 0 ) {
			diluxone_users_way_divider( (string) $way['over'] );
		}

		diluxone_users_way_render( (string) $id, $way );
		++$drawn;
	}

	if ( array() !== $tabs ) {
		/*
		 * The one divider that stays in both arrangements: it separates what
		 * is always on top from the rest. Stacked, "the rest" is the first way
		 * in and the divider is that way's own line; in tabs it is a strip of
		 * three and nothing but "or" is true of all of them.
		 */
		if ( $drawn > 0 ) {
			$first = (array) reset( $tabs );

			diluxone_users_way_divider( 'tabs' === $layout ? '' : (string) $first['over'] );
		}

		if ( count( $tabs ) > 1 ) {
			diluxone_users_ways_strip( $tabs );
		}

		$drawn = 0;

		foreach ( $tabs as $id => $way ) {
			if ( $drawn > 0 ) {
				diluxone_users_way_divider( (string) $way['over'], true );
			}

			diluxone_users_way_render( (string) $id, $way );
			++$drawn;
		}
	}

	echo '</div>';

	// After the block and not before it: in a preview the script is written
	// into the document as it is reached, and one that runs before the strip
	// exists finds nothing to turn into tabs.
	if ( 'tabs' === $layout ) {
		diluxone_users_ways_enqueue();
	}
}

/* ── The four this plugin ships ────────────────────────────────────── */

/**
 * The plugin's own ways in.
 *
 * All four in one file because none of them is a feature of its own: each is
 * a few lines drawn out of pieces that already exist elsewhere — the social
 * buttons, WordPress's form, the passkey script. What registers from its own
 * file is everything that is not the plugin's, which is the point of the
 * registry.
 */
function diluxone_users_ways_register(): void {
	diluxone_users_register_way(
		'passkey',
		array(
			'label'     => __( 'Passkey', 'diluxone-users' ),
			'icon'      => 'key',
			'position'  => 0,
			'outside'   => true,
			'available' => 'diluxone_users_has_passkeys',
			'render'    => 'diluxone_users_way_passkey',
		)
	);

	diluxone_users_register_way(
		'social',
		array(
			'label'     => __( 'Networks', 'diluxone-users' ),
			'icon'      => 'network',
			'position'  => 10,
			'over'      => __( 'or', 'diluxone-users' ),
			'states'    => array( 'social' ),
			'available' => static fn(): bool => array() !== diluxone_users_sso_for_login(),
			'render'    => 'diluxone_users_way_social',
		)
	);

	diluxone_users_register_way(
		'email',
		array(
			'label'     => __( 'Email', 'diluxone-users' ),
			'icon'      => 'mail',
			'position'  => 20,
			'over'      => __( 'or with your email', 'diluxone-users' ),
			'states'    => array( 'email', 'error' ),
			'available' => 'diluxone_users_login_has_link',
			'render'    => 'diluxone_users_way_email',
		)
	);

	diluxone_users_register_way(
		'password',
		array(
			'label'     => __( 'Password', 'diluxone-users' ),
			'icon'      => 'user',
			'position'  => 30,
			'over'      => __( 'or with your password', 'diluxone-users' ),
			'states'    => array( 'changed' ),
			'available' => 'diluxone_users_login_has_password',
			'render'    => 'diluxone_users_way_password',
		)
	);
}
add_action( 'diluxone_users_register_ways', 'diluxone_users_ways_register' );

/** The passkey, which is a button and the line it writes when it fails. */
function diluxone_users_way_passkey(): void {
	diluxone_users_passkeys_enqueue();
	?>
	<p class="diluxone-users-notice" data-diluxone-users-passkey-notice hidden></p>
	<p><button type="button" class="diluxone-users-button diluxone-users-button--wide" data-diluxone-users-passkey="login"><?php echo diluxone_users_button_icon( 'key' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- our own markup. ?><?php esc_html_e( 'Sign in with a passkey', 'diluxone-users' ); ?></button></p>
	<?php
}

/** The networks the site offers, as the buttons they already are. */
function diluxone_users_way_social(): void {
	echo diluxone_users_sso_buttons( diluxone_users_sso_for_login() ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- our own markup, already escaped.
}

/** The address, and the link that goes to it. */
function diluxone_users_way_email(): void {
	?>
	<form class="diluxone-users-form" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
		<input type="hidden" name="action" value="diluxone_users_acceso">
		<?php wp_nonce_field( 'diluxone_users_login', 'diluxone_users_nonce' ); ?>

		<?php if ( diluxone_users_option( 'diluxone_users_handle_login' ) ) : ?>
			<?php
			// A public name is not an address, and a box of type "email" would
			// have the browser refuse it before the site is ever asked. It is
			// still an address the box expects most of the time, so the phone
			// keyboard offers the @.
			?>
			<label for="diluxone-users-email"><?php esc_html_e( 'Email address or public name', 'diluxone-users' ); ?></label>
			<input type="text" inputmode="email" autocapitalize="none" spellcheck="false" id="diluxone-users-email" name="diluxone_users_email" required autocomplete="username" placeholder="<?php echo esc_attr_x( 'you@example.com', 'placeholder for the e-mail field', 'diluxone-users' ); ?>">
		<?php else : ?>
			<label for="diluxone-users-email"><?php esc_html_e( 'Email address', 'diluxone-users' ); ?></label>
			<input type="email" id="diluxone-users-email" name="diluxone_users_email" required autocomplete="email" placeholder="<?php echo esc_attr_x( 'you@example.com', 'placeholder for the e-mail field', 'diluxone-users' ); ?>">
		<?php endif; ?>

		<button type="submit" class="diluxone-users-button"><?php echo diluxone_users_button_icon( 'mail' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- our own markup. ?><?php esc_html_e( 'Send me the sign-in link', 'diluxone-users' ); ?></button>
	</form>

	<p class="diluxone-users-note diluxone-users-note--icon">
		<?php echo diluxone_users_icon( 'info' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- our own markup. ?>
		<span><?php esc_html_e( 'You get an email with a link. Click it and you are in: no password to choose or type.', 'diluxone-users' ); ?></span>
	</p>
	<?php
}

/** Username and password: WordPress's own form, and the way out of it. */
function diluxone_users_way_password(): void {
	// WordPress's own form, not one of ours: it already brings the "remember
	// me", the redirect and the nonce, and it is the part that least needs
	// touching.
	wp_login_form(
		array(
			'redirect'       => (string) apply_filters( 'diluxone_users_login_redirect', home_url( '/' ), 0 ),
			'label_username' => __( 'Email or username', 'diluxone-users' ),
			'label_password' => __( 'Password', 'diluxone-users' ),
			'label_log_in'   => __( 'Sign in', 'diluxone-users' ),
		)
	);
	?>
	<p class="diluxone-users-note">
		<a href="<?php echo esc_url( wp_lostpassword_url() ); ?>"><?php esc_html_e( 'I forgot my password', 'diluxone-users' ); ?></a>
	</p>
	<?php
}
