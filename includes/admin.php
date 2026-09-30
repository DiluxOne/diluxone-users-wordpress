<?php
/**
 * The menu and what its screens share.
 *
 * Each menu entry is a screen with a life of its own and tabs of its own.
 * Repeating the menu as tabs on all of them — which is what it used to do —
 * adds nothing: the navigation is already on the left, and that place is for
 * the sections of the screen you are on.
 *
 * @package DiluxOneUsers
 */

defined( 'ABSPATH' ) || exit;

const DILUXONE_USERS_MENU = 'diluxone-users';

/**
 * The name the plugin introduces itself with in the dashboard.
 *
 * It is written exactly once: the menu, each screen title and the browser tab
 * all use it. Written in three places, sooner or later they say three
 * different things.
 */
function diluxone_users_plugin_name(): string {
	return (string) apply_filters( 'diluxone_users_plugin_name', __( 'DiluxOne Users+', 'diluxone-users' ) );
}

/**
 * A screen title, with the plugin name in front.
 *
 * In a dashboard with twenty plugins, "User fields" does not say whose screen
 * that is. "DiluxOne Users+ | User fields" does.
 */
function diluxone_users_screen_title( string $title ): string {
	return sprintf(
		/* translators: 1: plugin name, 2: screen name */
		_x( '%1$s | %2$s', 'title of a dashboard screen', 'diluxone-users' ),
		diluxone_users_plugin_name(),
		$title
	);
}

/**
 * The same thing, in the browser tab.
 *
 * The screen name is replaced inside the title WordPress builds, so as not to
 * be left with the rest — the site name and the "WordPress" at the end —
 * which is its and not ours.
 */
function diluxone_users_admin_title( string $admin_title, string $title ): string {
	$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;

	if ( ! $screen instanceof WP_Screen || false === strpos( (string) $screen->id, DILUXONE_USERS_MENU ) ) {
		return $admin_title;
	}

	return str_replace( $title, diluxone_users_screen_title( $title ), $admin_title );
}
add_filter( 'admin_title', 'diluxone_users_admin_title', 10, 2 );

/**
 * The menu screens, in order.
 *
 * In Network Admin the log's settings are a screen of their own, and it is
 * named after what it holds: a site's Reports have rows beside the settings,
 * the network has the settings and no rows.
 *
 * @return array<string, mixed>
 */
function diluxone_users_screens(): array {
	return array(
		'diluxone-users'          => __( 'Overview', 'diluxone-users' ),
		// The order is the way a person walks through it: how they get in,
		// how they are protected once in, what they have inside, how it
		// looks, what reaches them by e-mail, who is actually in right now,
		// keeping it all alive.
		'diluxone-users-login'    => __( 'Access', 'diluxone-users' ),
		'diluxone-users-security' => __( 'Security', 'diluxone-users' ),
		'diluxone-users-social'   => __( 'Social login', 'diluxone-users' ),
		'diluxone-users-account'  => __( 'Account area', 'diluxone-users' ),
		'diluxone-users-fields'   => __( 'User fields', 'diluxone-users' ),
		'diluxone-users-design'   => __( 'Design', 'diluxone-users' ),
		'diluxone-users-notices'  => __( 'E-mail notices', 'diluxone-users' ),
		'diluxone-users-reports'  => 'network' === diluxone_users_admin_context() ? __( 'Activity log', 'diluxone-users' ) : __( 'Reports', 'diluxone-users' ),
		'diluxone-users-status'   => __( 'Maintenance', 'diluxone-users' ),
	);
}

/**
 * What draws each screen.
 *
 * @return array<string, callable-string>
 */
function diluxone_users_screen_callbacks(): array {
	return array(
		'diluxone-users'          => 'diluxone_users_screen_home',
		'diluxone-users-login'    => 'diluxone_users_screen_login',
		'diluxone-users-security' => 'diluxone_users_screen_security',
		'diluxone-users-social'   => 'diluxone_users_screen_social',
		'diluxone-users-account'  => 'diluxone_users_screen_account',
		'diluxone-users-fields'   => 'diluxone_users_screen_fields',
		'diluxone-users-design'   => 'diluxone_users_screen_design',
		'diluxone-users-notices'  => 'diluxone_users_screen_notices',
		'diluxone-users-reports'  => 'diluxone_users_screen_reports',
		'diluxone-users-status'   => 'diluxone_users_screen_status',
	);
}

/**
 * Menu.
 *
 * On a network, a site's menu carries only the screens that belong to it:
 * the network's settings are in Network Admin (see admin-network.php), and
 * on a site that is not the hub, the hub's screens are on the hub.
 */
function diluxone_users_menu(): void {
	add_menu_page(
		diluxone_users_plugin_name(),
		diluxone_users_plugin_name(),
		'manage_options',
		DILUXONE_USERS_MENU,
		'diluxone_users_screen_home',
		'dashicons-groups',
		71
	);

	$callbacks = diluxone_users_screen_callbacks();

	foreach ( diluxone_users_screens() as $slug => $title ) {
		if ( diluxone_users_screen_here( $slug ) ) {
			add_submenu_page( DILUXONE_USERS_MENU, $title, $title, 'manage_options', $slug, $callbacks[ $slug ] );
		}
	}
}
add_action( 'admin_menu', 'diluxone_users_menu' );

/**
 * The URL of a plugin screen, with whatever arguments are needed.
 *
 * From wherever it is asked, it is the screen where it lives: on a network a
 * network screen is in Network Admin and the hub's are on the hub.
 *
 * @param array<string, mixed> $args
 */
function diluxone_users_admin_url( string $screen, array $args = array() ): string {
	return add_query_arg(
		array_merge( array( 'page' => $screen ), $args ),
		diluxone_users_admin_base( $screen, (string) ( $args['tab'] ?? '' ) )
	);
}

/**
 * The active tab within a screen.
 *
 * @param array<string, string> $tabs
 */
function diluxone_users_tab( array $tabs ): string {
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended
	$tab = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : '';

	return isset( $tabs[ $tab ] ) ? $tab : (string) array_key_first( $tabs );
}

/**
 * A screen's tabs.
 *
 * @param array<string, string> $tabs
 * @param array<string, mixed>  $extra
 */
function diluxone_users_tabs( string $screen, array $tabs, string $current, array $extra = array() ): void {
	if ( count( $tabs ) < 2 ) {
		return;
	}

	echo '<nav class="nav-tab-wrapper wp-clearfix">';

	foreach ( $tabs as $slug => $title ) {
		printf(
			'<a class="nav-tab%1$s" href="%2$s">%3$s</a>',
			$slug === $current ? ' nav-tab-active' : '',
			esc_url( diluxone_users_admin_url( $screen, array_merge( $extra, array( 'tab' => $slug ) ) ) ),
			esc_html( $title )
		);
	}

	echo '</nav>';
}

/**
 * The common header: title and, when there are any, tabs.
 *
 * @param array<string, mixed> $tabs
 * @param array<string, mixed> $extra
 */
function diluxone_users_screen_open( string $title, string $screen = '', array $tabs = array(), string $current = '', array $extra = array() ): void {
	echo '<div class="wrap diluxone-users-admin">';
	printf( '<h1>%s</h1>', esc_html( diluxone_users_screen_title( $title ) ) );

	if ( array() !== $tabs ) {
		diluxone_users_tabs( $screen, $tabs, $current, $extra );
	}
}

/** Screen close. */
function diluxone_users_screen_close(): void {
	echo '</div>';
}

/**
 * The roles that can edit other people.
 *
 * Some settings are never applied to them, whatever is chosen — locking the
 * dashboard profile of the person who has to unlock everybody else's is how a
 * site ends with nobody able to fix it. Those settings do not offer these
 * roles as a choice either: a tick box that is quietly ignored is worse than
 * no tick box, and this screen used to have four of them.
 *
 * It is asked of the role and not of the person, because the list being drawn
 * is a list of roles.
 *
 * @return array<int, string>
 */
function diluxone_users_roles_that_edit_users(): array {
	$spared = array();

	foreach ( wp_roles()->role_objects as $slug => $role ) {
		if ( ! empty( $role->capabilities['edit_users'] ) ) {
			$spared[] = (string) $slug;
		}
	}

	return $spared;
}

/**
 * The "everybody / only some roles" control.
 *
 * One control written once and used by everything that has to answer that
 * question: the second factor, the dashboard profile, who sees a section of
 * the account area. The list of roles hangs under the second answer and is
 * hidden while the first one is chosen (see diluxone-users-admin.js) — with
 * JavaScript off it is simply always visible, and the radio is what the
 * server reads either way.
 *
 * The names are passed in rather than built from a prefix because not every
 * caller has one: a section of the account area is one row of an array, not
 * two options of its own.
 *
 * @param string            $scope_name Name of the radio input.
 * @param string            $roles_name Name of the checkboxes, brackets included.
 * @param string            $scope      'all' or 'some'.
 * @param array<int,string> $chosen     Roles already ticked.
 * @param string            $help       A line under the control, or '' for none.
 * @param string            $spared     Who this can never reach, or '' when it can reach anybody.
 * @param array<int,string> $exclude    Roles not offered at all.
 * @param array<int,string> $fixed      Roles shown but not choosable: they are never reached, and it says so on the row.
 */
function diluxone_users_roles_picker( string $scope_name, string $roles_name, string $scope, array $chosen, string $help = '', string $spared = '', array $exclude = array(), array $fixed = array() ): void {
	?>
	<fieldset data-diluxone-users-scope>
		<?php
		/*
		 * The two answers are cards, like every other question on these
		 * screens.
		 *
		 * They were two bare radios on one line, which is what they had
		 * always been — the design system arrived and this control was not
		 * carried over with the rest. On the second-step tab that is three
		 * card questions with a pair of naked radios sitting in the middle of
		 * them, and it reads as a piece of another plugin.
		 *
		 * The help line moves onto the second card for the same reason: it
		 * explains the choice, and a card question explains itself on the
		 * card rather than in a grey paragraph underneath the group.
		 */
		diluxone_users_ui_choices(
			array(
				array(
					'name'    => $scope_name,
					'value'   => 'all',
					'checked' => 'some' !== $scope,
					'title'   => __( 'Everybody', 'diluxone-users' ),
				),
				array(
					'name'    => $scope_name,
					'value'   => 'some',
					'checked' => 'some' === $scope,
					'title'   => __( 'Only some roles', 'diluxone-users' ),
					'help'    => $help,
				),
			)
		);
		?>

		<div class="diluxone-users-scope__roles" data-diluxone-users-scope-roles>
			<?php foreach ( wp_roles()->get_names() as $role => $label ) : ?>
				<?php if ( in_array( (string) $role, $fixed, true ) ) : ?>
					<?php
					/*
					 * On the list and not choosable. It used to be left off the
					 * list, and the rule it stood for — this role is never
					 * reached — showed up as an absence, which reads as a bug:
					 * "the administrator is not there and I cannot tick it".
					 */
					?>
					<label class="diluxone-users-roles__item diluxone-users-roles__item--fixed">
						<input type="checkbox" disabled>
						<?php echo esc_html( translate_user_role( $label ) ); ?>
						<span class="description"><?php esc_html_e( '— always kept, whatever is chosen', 'diluxone-users' ); ?></span>
					</label>
				<?php elseif ( ! in_array( (string) $role, $exclude, true ) ) : ?>
					<label class="diluxone-users-roles__item">
						<input type="checkbox" name="<?php echo esc_attr( $roles_name ); ?>" value="<?php echo esc_attr( $role ); ?>" <?php checked( in_array( $role, $chosen, true ) ); ?>>
						<?php echo esc_html( translate_user_role( $label ) ); ?>
					</label>
				<?php endif; ?>
			<?php endforeach; ?>
		</div>

		<?php if ( '' !== $spared ) : ?>
			<p class="du-field__help description"><?php echo esc_html( $spared ); ?></p>
		<?php endif; ?>
	</fieldset>
	<?php
}

/**
 * The same control, for a setting that lives in two options of its own.
 *
 * @param string $prefix Option prefix, e.g. 'diluxone_users_2fa'.
 * @param string $help   A line under the control, or '' for none.
 * @param string $spared Who this can never reach, or '' when it can reach anybody.
 */
function diluxone_users_scope_control( string $prefix, string $help = '', string $spared = '' ): void {
	// With somebody spared, the roles that edit users are shown as fixed
	// rows rather than left off: the rule is seen, not inferred.
	$fixed = '' === $spared ? array() : diluxone_users_roles_that_edit_users();

	diluxone_users_roles_picker(
		$prefix . '_scope',
		$prefix . '_roles[]',
		diluxone_users_scope( $prefix ),
		(array) diluxone_users_option( $prefix . '_roles' ),
		$help,
		$spared,
		array(),
		$fixed
	);
}

/**
 * Reads the control back out of a submitted form.
 *
 * @param string $prefix Option prefix.
 * @return array<string, mixed>
 */
function diluxone_users_scope_posted( string $prefix ): array {
	// phpcs:disable WordPress.Security.NonceVerification.Missing -- the caller verifies it.
	$scope = sanitize_key( wp_unslash( $_POST[ $prefix . '_scope' ] ?? 'all' ) );
	$roles = array_map( 'sanitize_key', (array) wp_unslash( $_POST[ $prefix . '_roles' ] ?? array() ) );
	// phpcs:enable

	$scope = 'some' === $scope ? 'some' : 'all';

	return array(
		$prefix . '_scope' => $scope,
		// Roles kept even when it applies to everybody: switching back and
		// forth should not throw away what was chosen.
		$prefix . '_roles' => $roles,
	);
}

/**
 * The way back to what the plugin would have done.
 *
 * Every one of these numbers reads "empty means the plugin's own", and empty
 * is a state a field can be put into but not easily got back to: once a
 * number is typed, the person has to remember that the box used to be blank
 * and that blank meant something. The button remembers instead.
 *
 * @param string $fields CSS selectors of the fields it empties, comma separated.
 */
function diluxone_users_default_button( string $fields ): void {
	printf(
		'<p><button type="button" class="button-link diluxone-users-default" data-diluxone-users-default="%s">%s</button></p>',
		esc_attr( $fields ),
		esc_html__( 'Back to the default', 'diluxone-users' )
	);
}

/**
 * A picture chosen from the media library.
 *
 * The library and not a URL box: whoever is setting this up already has the
 * picture in WordPress, and a URL typed by hand breaks the day the site moves
 * domain. It is the media modal WordPress already ships — nothing is drawn
 * here but the button, the preview and the hidden field holding the id.
 */
function diluxone_users_image_field( string $key, string $help = '' ): void {
	$id  = (int) diluxone_users_option( $key );
	$url = $id > 0 ? (string) wp_get_attachment_image_url( $id, 'medium' ) : '';
	?>
	<div class="diluxone-users-image" data-diluxone-users-image>
		<input type="hidden" name="<?php echo esc_attr( $key ); ?>" value="<?php echo esc_attr( (string) $id ); ?>" data-diluxone-users-image-id>

		<div class="diluxone-users-image__preview" data-diluxone-users-image-preview <?php echo '' === $url ? 'hidden' : ''; ?>>
			<img src="<?php echo esc_url( $url ); ?>" alt="">
		</div>

		<p>
			<button type="button" class="button" data-diluxone-users-image-pick><?php esc_html_e( 'Choose a picture', 'diluxone-users' ); ?></button>
			<button type="button" class="button-link diluxone-users-danger" data-diluxone-users-image-clear <?php echo '' === $url ? 'hidden' : ''; ?>><?php esc_html_e( 'Remove', 'diluxone-users' ); ?></button>
		</p>

		<?php if ( '' !== $help ) : ?>
			<p class="description"><?php echo esc_html( $help ); ?></p>
		<?php endif; ?>
	</div>
	<?php
}

/** A short notice at the top of the screen. */
function diluxone_users_notice( string $text, string $type = 'success' ): void {
	printf(
		'<div class="notice notice-%1$s is-dismissible"><p>%2$s</p></div>',
		esc_attr( $type ),
		esc_html( $text )
	);
}

/**
 * The notice that a setting is pinned by code, with who pins it.
 *
 * It lives in admin.php and not in each screen because it is always the same
 * notice, and because the day a third pinnable setting arrives nobody has to
 * remember to copy the text correctly.
 */
function diluxone_users_forced_notice( string $key ): void {
	if ( ! diluxone_users_option_forced( $key ) ) {
		return;
	}

	$filters = diluxone_users_option_forced_by();
	?>
	<div class="diluxone-users-forced">
		<p><?php esc_html_e( 'This site fixes this from code: whatever is chosen here, it stays as it is.', 'diluxone-users' ); ?></p>

		<?php if ( array() !== $filters ) : ?>
			<p><?php esc_html_e( 'It is filtered here — open the file to change it or take it out:', 'diluxone-users' ); ?></p>
			<ul>
				<?php foreach ( $filters as $filter ) : ?>
					<li><code><?php echo esc_html( $filter ); ?></code></li>
				<?php endforeach; ?>
			</ul>
		<?php endif; ?>
	</div>
	<?php
}

/** A paragraph of explanation, at reading width. */
function diluxone_users_intro( string $text ): void {
	printf( '<p class="diluxone-users-admin__intro">%s</p>', esc_html( $text ) );
}

/**
 * The plugin admin styles.
 *
 * Besides the plugin's own screens, WordPress's Users list and the profile
 * screen get them too: the Access column and the block on the profile are
 * drawn with the same pills, and without the stylesheet they come out as a
 * run-on line of words.
 */
function diluxone_users_admin_styles( string $hook ): void {
	$people = in_array( $hook, array( 'users.php', 'user-edit.php', 'profile.php' ), true );

	if ( ! $people && false === strpos( $hook, 'diluxone-users' ) ) {
		return;
	}

	wp_enqueue_style( 'diluxone-users-admin', DILUXONE_USERS_URL . 'assets/diluxone-users-admin.css', array(), diluxone_users_asset_version( 'assets/diluxone-users-admin.css' ) );

	// The colours come from the admin colour scheme this person picked, so
	// they ride with the request and not with the file: two administrators of
	// the same site can have picked different ones.
	wp_add_inline_style( 'diluxone-users-admin', diluxone_users_admin_tokens() );

	// The media modal, for the screens that let a picture be chosen. It is
	// WordPress's own and it is not small, so it is loaded where it is used.
	if ( false !== strpos( $hook, 'diluxone-users-design' ) ) {
		wp_enqueue_media();
	}

	wp_enqueue_script( 'diluxone-users-admin', DILUXONE_USERS_URL . 'assets/diluxone-users-admin.js', array(), diluxone_users_asset_version( 'assets/diluxone-users-admin.js' ), true );

	if ( $people ) {
		return;
	}

	// The button preview uses the real stylesheet, the same one the site uses:
	// previewing with another would be previewing something else.
	diluxone_users_sso_enqueue_button_styles();

	// And so do the previews — of the account area, of the sign-in form, of
	// the form somebody signing up meets. They render the real templates, and
	// a real template without its stylesheet is not what the site serves.
	//
	// It is loaded whatever the setting says: the previews are drawn by the
	// server with what is on the form (see diluxone_users_preview_request()),
	// and a template has to be able to show either answer.
	//
	// The registration screen is not on this list because it is not a screen:
	// it became a tab of the sign-in one, which is here. A slug no `$hook` can
	// ever contain is a line that reads like it does something.
	$previews = array( 'diluxone-users-account', 'diluxone-users-login', 'diluxone-users-design' );

	foreach ( $previews as $diluxone_users_screen ) {
		if ( false === strpos( $hook, $diluxone_users_screen ) ) {
			continue;
		}

		if ( ! wp_style_is( 'diluxone-users', 'registered' ) ) {
			wp_register_style( 'diluxone-users', DILUXONE_USERS_URL . 'assets/diluxone-users.css', array(), diluxone_users_asset_version( 'assets/diluxone-users.css' ) );
		}

		wp_enqueue_style( 'diluxone-users' );

		break;
	}
}
add_action( 'admin_enqueue_scripts', 'diluxone_users_admin_styles' );
