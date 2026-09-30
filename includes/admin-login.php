<?php
/**
 * The Access screen: how somebody gets into this site, and how they get an
 * account to get into.
 *
 * One screen for the whole door. Registration was a screen of its own for a
 * while, on the argument that opening it changes nothing about signing in —
 * true, and beside the point: they are two questions asked at the same
 * moment, they share half their settings (whether the link creates the
 * account, whether a social account does, what happens to wp-login.php), and
 * a person setting up a site asks them together. So they are tabs of one
 * screen, under one summary that shows the door whole.
 *
 * Every tab answers one question and its label says which. The first tab
 * edits nothing: it is the state of everything the others decide, read from
 * the same settings they write.
 *
 * @package DiluxOneUsers
 */

defined( 'ABSPATH' ) || exit;

/** The Access screen. */
function diluxone_users_screen_login(): void {
	diluxone_users_screen_panels( 'diluxone-users-login', diluxone_users_screens()['diluxone-users-login'] );
}

/**
 * Its tabs. Registration registers its own from its own file, onto this
 * screen; two-step verification lives on Security and is shown here only as
 * state. Passkeys are switched on here, with the other ways in, and set up on
 * Security: turning a door on is a question about doors.
 */
function diluxone_users_login_panels(): void {
	diluxone_users_register_panel(
		'diluxone-users-login',
		'summary',
		array(
			'label'    => __( 'Summary', 'diluxone-users' ),
			'position' => 0,
			'render'   => 'diluxone_users_screen_login_summary',
			'form'     => false,
		)
	);

	diluxone_users_register_panel(
		'diluxone-users-login',
		'page',
		array(
			'label'    => __( 'The sign-in page', 'diluxone-users' ),
			'position' => 10,
			'render'   => 'diluxone_users_screen_login_page',
			'save'     => 'diluxone_users_login_page_save',
		)
	);

	diluxone_users_register_panel(
		'diluxone-users-login',
		'ways',
		array(
			'label'    => __( 'Ways in', 'diluxone-users' ),
			'position' => 20,
			'render'   => 'diluxone_users_screen_login_ways',
			'save'     => 'diluxone_users_login_ways_save',
		)
	);

	/*
	 * Which ways in there are, and how they are laid out, are two questions
	 * and they were one tab until the second one grew an answer. The tab
	 * before this one decides what the site offers; this one decides what
	 * that looks like when there are four of them, which is a question about
	 * a screen and comes with the screen beside it.
	 */
	diluxone_users_register_panel(
		'diluxone-users-login',
		'arrangement',
		array(
			'label'    => __( 'How they are arranged', 'diluxone-users' ),
			'position' => 25,
			'render'   => 'diluxone_users_screen_login_arrangement',
			'save'     => 'diluxone_users_login_arrangement_save',
			'preview'  => 'diluxone_users_login_preview',
			'note'     => __( 'The sign-in page, at the width shown. Drag a way in and this follows: the order here is the order of the tabs, and stacked it is the order down the page.', 'diluxone-users' ),
			// The order travels in a form of this panel's own, which the drop
			// that ends a drag sends.
			'form'     => false,
		)
	);
}
add_action( 'diluxone_users_register_panels', 'diluxone_users_login_panels' );

/** Where people sign in, and what becomes of wp-login.php. */
function diluxone_users_login_page_save(): void {
	// phpcs:disable WordPress.Security.NonceVerification.Missing -- the panel verifies it.
	$screens = in_array( $_POST['diluxone_users_wp_screens'] ?? '', array( 'auto', 'mine', 'wp' ), true )
		? sanitize_key( wp_unslash( $_POST['diluxone_users_wp_screens'] ) )
		: 'auto';

	// The second door is not an answer on a site with no password: the screen
	// does not offer it, and a request that sends it anyway gets the answer
	// in force instead.
	if ( 'wp' === $screens && ! diluxone_users_login_has_password() ) {
		$screens = 'auto';
	}

	diluxone_users_save_options(
		array(
			'diluxone_users_login_page'    => absint( wp_unslash( $_POST['diluxone_users_login_page'] ?? 0 ) ),
			'diluxone_users_wp_screens'    => $screens,
			'diluxone_users_lost_password' => in_array( $_POST['diluxone_users_lost_password'] ?? '', array( 'wp', 'site', 'link' ), true )
				? sanitize_key( wp_unslash( $_POST['diluxone_users_lost_password'] ) )
				: 'wp',
		)
	);
	// phpcs:enable
}

/**
 * What a site with no way into it is told.
 *
 * One sentence in one place, said twice: beside the boxes while the form is
 * being filled in, and by the save if the form is sent anyway.
 */
function diluxone_users_login_ways_needs_one(): string {
	return __( 'The sign-in form has to take at least one of these. A site nobody can sign in to is not a site, and the buttons underneath the form cannot stand in for it.', 'diluxone-users' );
}

/**
 * With what somebody who already has an account gets in.
 *
 * @return bool False when neither way in was ticked, in which case nothing
 *              was written.
 */
function diluxone_users_login_ways_save(): bool {
	// phpcs:disable WordPress.Security.NonceVerification.Missing -- the panel verifies it.
	$ways = array_map( 'sanitize_key', (array) wp_unslash( $_POST['diluxone_users_login_method'] ?? array() ) );

	/*
	 * Neither box ticked is not an answer, and it is refused before anything
	 * is written: a site that saves its way to no way in cannot be opened
	 * again from the screen that closed it.
	 */
	if ( ! diluxone_users_ui_needs_one( $ways, diluxone_users_login_ways_needs_one() ) ) {
		return false;
	}

	$link     = in_array( 'link', $ways, true );
	$password = in_array( 'password', $ways, true );

	/*
	 * Two boxes, one setting. What the form takes is one question with four
	 * possible answers and one of them is refused above, so the three that
	 * are left are the three words the rest of the plugin already asks this
	 * option for.
	 */
	$saved = array(
		'diluxone_users_login_method'   => $link && $password ? 'both' : ( $password ? 'password' : 'link' ),
		'diluxone_users_login_expiry'   => absint( wp_unslash( $_POST['diluxone_users_login_expiry'] ?? 15 ) ),
		'diluxone_users_login_throttle' => absint( wp_unslash( $_POST['diluxone_users_login_throttle'] ?? 60 ) ),
		// It is about what the sign-in box accepts, so it is decided here;
		// what a public name is, is decided on the account screen.
		'diluxone_users_handle_login'   => isset( $_POST['diluxone_users_handle_login'] ) ? 1 : 0,
		'diluxone_users_sso_login'      => isset( $_POST['diluxone_users_sso_login'] ) ? 1 : 0,
	);

	/*
	 * Passkeys are switched on here, with the other ways in, and set up on
	 * Security, where the feature lives. Only when the feature is installed:
	 * an install without it never draws the box, and writing a 0 for a box
	 * nobody drew is how a setting gets turned off by somebody who came to
	 * change something else.
	 */
	if ( function_exists( 'diluxone_users_passkeys_enabled' ) ) {
		$saved['diluxone_users_passkey_enabled'] = isset( $_POST['diluxone_users_passkey_enabled'] ) ? 1 : 0;
	}

	/*
	 * Closing the password closes wp-login.php as a second door with it. Its
	 * form takes nothing else, so "keep it as a second sign-in screen" stops
	 * being an answer, and the one in force is the one written — the tab that
	 * asks the question shows what is stored, not a guess on top of it.
	 */
	if ( ! $password && 'wp' === diluxone_users_wp_screens() ) {
		$saved['diluxone_users_wp_screens'] = 'auto';
	}

	diluxone_users_save_options( $saved );
	// phpcs:enable

	return true;
}

/**
 * The social providers that are working, as one line, or nothing.
 *
 * @return array<int, string>
 */
function diluxone_users_sso_working_names(): array {
	return array_values( array_map( static fn( array $p ): string => (string) $p['name'], diluxone_users_sso_available() ) );
}

/**
 * Every way into this site and the state each one is in.
 *
 * The settings that open and close these are spread over the tabs of this
 * screen and over Security; what nobody could see was the result. This reads
 * the same settings those tabs write — there is no second source of truth —
 * and every row links to the tab that changes it.
 *
 * @return array<int, array<string, string>>
 */
function diluxone_users_doors(): array {
	$page   = (int) diluxone_users_option( 'diluxone_users_login_page' );
	$title  = $page > 0 ? (string) get_the_title( $page ) : '';
	$mode   = diluxone_users_register_mode();
	$social = diluxone_users_sso_working_names();
	$tab    = static fn( string $id ): string => diluxone_users_admin_url( 'diluxone-users-login', array( 'tab' => $id ) );

	$rows = array();

	$rows[] = array(
		'label'  => __( 'The sign-in page', 'diluxone-users' ),
		// Pending and not off: nobody switched this page off, it was never
		// chosen — which is the answer the status screen already gives.
		'state'  => $page > 0 ? 'active' : 'pending',
		'why'    => $page > 0 ? '' : __( 'No page chosen yet.', 'diluxone-users' ),
		'detail' => $page > 0
			? sprintf( '<a href="%s">%s</a>', esc_url( (string) get_permalink( $page ) ), esc_html( $title ) )
			: esc_html__( 'None: wp-login.php does the job.', 'diluxone-users' ),
		'url'    => $tab( 'page' ),
	);

	$rows[] = array(
		'label'  => __( 'The e-mail link', 'diluxone-users' ),
		'state'  => diluxone_users_login_has_link() ? 'active' : 'off',
		'detail' => diluxone_users_login_has_link()
			? sprintf(
				/* translators: %d: minutes */
				esc_html__( 'A single-use link, good for %d minutes.', 'diluxone-users' ),
				(int) diluxone_users_login_expiry()
			)
			: esc_html__( 'Nobody can ask for a link.', 'diluxone-users' ),
		'url'    => $tab( 'ways' ),
	);

	$rows[] = array(
		'label'  => __( 'Username and password', 'diluxone-users' ),
		'state'  => diluxone_users_login_has_password() ? 'active' : 'off',
		'detail' => diluxone_users_login_has_password()
			? esc_html__( 'WordPress’s own form, underneath the link.', 'diluxone-users' )
			: esc_html__( 'Closed: on this site a password opens nothing.', 'diluxone-users' ),
		'url'    => $tab( 'ways' ),
	);

	$rows[] = array(
		'label'  => __( 'A social account', 'diluxone-users' ),
		'state'  => ! diluxone_users_option( 'diluxone_users_sso_login' ) ? 'off' : ( array() !== $social ? 'active' : 'pending' ),
		'why'    => diluxone_users_option( 'diluxone_users_sso_login' ) && array() === $social ? __( 'no provider is working yet', 'diluxone-users' ) : '',
		'detail' => array() !== $social
			? esc_html( implode( ', ', $social ) )
			: esc_html__( 'The buttons are switched on but there is nothing to show.', 'diluxone-users' ),
		'url'    => diluxone_users_admin_url( 'diluxone-users-social' ),
	);

	$rows[] = array(
		'label'  => __( 'A passkey', 'diluxone-users' ),
		'state'  => diluxone_users_has_passkeys() ? 'active' : 'off',
		'detail' => diluxone_users_has_passkeys()
			? esc_html__( 'For whoever added one. It counts as both steps at once.', 'diluxone-users' )
			: esc_html__( 'Not offered.', 'diluxone-users' ),
		'url'    => $tab( 'ways' ),
	);

	// The same reading the registration tab does, and the same one it calls
	// open or closed: this row is that answer said in a line.
	$made = diluxone_users_register_doors_open();

	$rows[] = array(
		'label'  => __( 'Creating an account', 'diluxone-users' ),
		'state'  => array() !== $made ? ( 'form' === $mode && '' === diluxone_users_register_url() ? 'pending' : 'active' ) : 'off',
		'why'    => 'form' === $mode && '' === diluxone_users_register_url() ? __( 'the form has no page yet', 'diluxone-users' ) : '',
		'detail' => array() !== $made
			? esc_html(
				sprintf(
				/* translators: %s: the ways an account gets created, comma separated */
					__( 'Accounts are created by: %s.', 'diluxone-users' ),
					implode( ', ', $made )
				)
			)
			: esc_html__( 'Nobody registers themselves: accounts are made under Users.', 'diluxone-users' ),
		'url'    => $tab( 'register' ),
	);

	$rows[] = array(
		'label'  => __( 'After the door', 'diluxone-users' ),
		'state'  => 'off' !== (string) diluxone_users_option( 'diluxone_users_2fa_mode' ) ? 'active' : 'off',
		'detail' => 'off' !== (string) diluxone_users_option( 'diluxone_users_2fa_mode' )
			? esc_html__( 'A second step is asked, as set on Security.', 'diluxone-users' )
			: esc_html__( 'No second step.', 'diluxone-users' ),
		'url'    => diluxone_users_admin_url( 'diluxone-users-security' ),
		'change' => __( 'Security →', 'diluxone-users' ),
	);

	return $rows;
}

/** The state of the whole door. Nothing is edited here. */
function diluxone_users_screen_login_summary(): void {
	diluxone_users_ui_aside_open();

	diluxone_users_intro( __( 'Every way into this site and the state each one is in, read from the settings the other tabs write. Nothing is edited here.', 'diluxone-users' ) );

	diluxone_users_summary_table( diluxone_users_doors() );

	/*
	 * The way back in is not one of the doors: it is there whatever the table
	 * says, and it cannot be switched off. Under the table it read as an
	 * eighth row somebody had forgotten to draw a pill for, so it goes beside
	 * it, where the rest of what this screen does not decide already is.
	 */
	diluxone_users_ui_aside_close(
		static function (): void {
			diluxone_users_ui_note(
				__( 'If you get locked out', 'diluxone-users' ),
				array(
					sprintf(
						/* translators: %s: the emergency URL */
						esc_html__( 'Whoever can administer the site always has a way in through %s, whatever is chosen on the other tabs.', 'diluxone-users' ),
						'<code>' . esc_html( wp_login_url() ) . '?diluxone-users-admin=1</code>'
					),
					sprintf(
						/* translators: %s: the WP-CLI command */
						esc_html__( 'With a shell, %s prints a single-use link and needs no e-mail to work.', 'diluxone-users' ),
						'<code>wp diluxone-users login ' . esc_html( wp_get_current_user()->user_email ) . '</code>'
					),
				)
			);

			diluxone_users_ui_links(
				__( 'Decided somewhere else', 'diluxone-users' ),
				array(
					array(
						'url'   => diluxone_users_admin_url( 'diluxone-users-social' ),
						'label' => __( 'Social login', 'diluxone-users' ),
						'help'  => __( 'Which providers the buttons offer, and the keys each one needs before it works.', 'diluxone-users' ),
					),
					array(
						'url'   => diluxone_users_admin_url( 'diluxone-users-security' ),
						'label' => __( 'Security', 'diluxone-users' ),
						'help'  => __( 'The second step asked for after the door, and what a passkey has to prove.', 'diluxone-users' ),
					),
					array(
						'url'   => diluxone_users_admin_url( DILUXONE_USERS_NOTICES, array( 'tab' => 'templates' ) ),
						'label' => __( 'The e-mail that carries the link', 'diluxone-users' ),
						'help'  => __( 'Its subject and its words live with the rest of what this site sends.', 'diluxone-users' ),
					),
				)
			);
		}
	);
}

/**
 * Is wp-login.php still a screen somebody lands on?
 *
 * The tab asks it twice — whether it is worth saying how that screen looks,
 * and whether an answer that sends people there means anything — so it is
 * answered once. Kept, it is a screen; taken over, it is not; and 'auto'
 * takes it over only while the e-mail link is the only way in, so a site with
 * a password still has it. With no sign-in page chosen nothing has been
 * decided yet, and a result nobody has asked for is not worth drawing.
 */
function diluxone_users_wp_login_seen(): bool {
	// With no page of its own, wp-login.php is not one of the ways in: it is
	// the only one. That is the moment how it looks matters most, so it is
	// answered first and the rest of the rules do not get a say.
	if ( (int) diluxone_users_option( 'diluxone_users_login_page' ) <= 0 ) {
		return true;
	}

	return ! diluxone_users_wp_screens_taken();
}

/** Where people sign in, and what becomes of wp-login.php. */
function diluxone_users_screen_login_page(): void {
	diluxone_users_ui_aside_open();

	$page   = (int) diluxone_users_option( 'diluxone_users_login_page' );
	$lost   = (string) diluxone_users_option( 'diluxone_users_lost_password' );
	$chosen = diluxone_users_wp_screens();
	$seen   = diluxone_users_wp_login_seen();
	$link   = diluxone_users_login_has_link();
	$brand  = (bool) diluxone_users_option( 'diluxone_users_wp_login_brand' );

	diluxone_users_intro( __( 'The page holding the sign-in form, and what becomes of wp-login.php. The form appears on the page you pick, below whatever the page already says.', 'diluxone-users' ) );

	diluxone_users_ui_field_open( __( 'The sign-in page', 'diluxone-users' ), 'diluxone_users_login_page' );

	/** @var array<string, mixed> $diluxone_users_dropdown */
	$diluxone_users_dropdown = array(
		'name'              => 'diluxone_users_login_page',
		'id'                => 'diluxone_users_login_page',
		'selected'          => $page,
		'show_option_none'  => __( '— None: wp-login.php does the job —', 'diluxone-users' ),
		'option_none_value' => 0,
	);

	// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- wp_dropdown_pages() escapes its own and prints it.
	wp_dropdown_pages( $diluxone_users_dropdown );
	diluxone_users_create_page_link( 'diluxone_users_login_page' );

	diluxone_users_ui_field_close( __( 'With none chosen, everybody signs in on wp-login.php and nothing below applies.', 'diluxone-users' ) );

	diluxone_users_ui_section(
		__( 'What happens to wp-login.php', 'diluxone-users' ),
		__( 'wp-login.php is the sign-in screen WordPress brings with it, at /wp-login.php: the grey box with the logo on it that everybody has seen. It keeps working whatever is chosen here — what changes is whether anybody still lands on it.', 'diluxone-users' )
	);

	/*
	 * Without a password the question has one answer. wp-login.php's form
	 * takes a password and nothing else, so leaving it open as a second door
	 * would be leaving open the one door this site closed — and the runtime
	 * does not (see diluxone_users_wp_screens_taken()). So the screen says
	 * what is true: the third answer is the one in force, the second cannot
	 * be picked. Turning the password off moves a stored second door to the
	 * third answer; one left from before that rule is shown moved, and the
	 * next save writes it.
	 */
	$password = diluxone_users_login_has_password();

	if ( ! $password && 'wp' === $chosen ) {
		$chosen = 'auto';
	}

	if ( $page <= 0 ) {
		diluxone_users_not_now( __( 'There is no sign-in page to send anybody to. What is chosen here waits for one.', 'diluxone-users' ) );
	} elseif ( ! $password ) {
		diluxone_users_not_now(
			__( 'Nobody signs in with a password on this site, so wp-login.php has nothing left to offer and everybody is sent to the sign-in page. What is chosen here applies the day a password is a way in again.', 'diluxone-users' ),
			diluxone_users_admin_url( 'diluxone-users-login', array( 'tab' => 'ways' ) ),
			__( 'Ways in →', 'diluxone-users' )
		);
	}

	diluxone_users_ui_choices(
		array(
			array(
				'name'    => 'diluxone_users_wp_screens',
				'value'   => 'mine',
				'checked' => 'mine' === $chosen,
				'title'   => __( 'Send everybody to the sign-in page above', 'diluxone-users' ),
				'help'    => __( 'wp-login.php stops being a screen: an old bookmark, a plugin’s link or its “register” link all land on this site’s own pages. Whoever administers the site keeps the emergency way in on the Summary tab.', 'diluxone-users' ),
			),
			array(
				'name'     => 'diluxone_users_wp_screens',
				'value'    => 'wp',
				'checked'  => 'wp' === $chosen,
				'title'    => __( 'Keep it as a second sign-in screen', 'diluxone-users' ),
				'help'     => __( 'The site has two screens that sign people in: yours, and the one WordPress brings. Anybody arriving on wp-login.php from a bookmark or a plugin sees the second.', 'diluxone-users' ),
				'disabled' => ! $password,
				'state'    => $password ? '' : 'off',
				'note'     => $password ? '' : __( 'its form only takes a password, and this site takes none', 'diluxone-users' ),
			),
			array(
				'name'    => 'diluxone_users_wp_screens',
				'value'   => 'auto',
				'checked' => 'auto' === $chosen,
				'title'   => __( 'Send people to the page only while the e-mail link is the only way in', 'diluxone-users' ),
				'help'    => __( 'While a password still opens something here, wp-login.php is left alone. The day the link is the only way in, its form could not work anyway.', 'diluxone-users' ),
			),
		)
	);

	diluxone_users_ui_section(
		__( 'If somebody forgets their password', 'diluxone-users' ),
		__( 'Forgetting a password is about changing it, not about getting in. This is where the new one is typed — or whether one is typed at all.', 'diluxone-users' )
	);

	if ( ! diluxone_users_login_has_password() ) {
		diluxone_users_not_now( __( 'There is no password on this site, so there is nothing to forget. What is chosen here waits for one.', 'diluxone-users' ) );
	}

	diluxone_users_ui_choices(
		array(
			array(
				'name'    => 'diluxone_users_lost_password',
				'value'   => 'wp',
				'checked' => 'wp' === $lost,
				'title'   => __( 'WordPress’s screen', 'diluxone-users' ),
				'help'    => __( 'They ask for a reset e-mail on wp-login.php, and the link in it lands on wp-login.php to type the new password. Two screens that look like WordPress, at the moment somebody is worried about their account.', 'diluxone-users' ),
			),
			array(
				'name'    => 'diluxone_users_lost_password',
				'value'   => 'site',
				'checked' => 'site' === $lost,
				'title'   => __( 'This site’s screen', 'diluxone-users' ),
				'help'    => __( 'They still ask on WordPress’s form — it is the only place that sends the e-mail — but the link lands on your sign-in page, and the new password is typed there, in your design.', 'diluxone-users' ),
				'state'   => $page > 0 ? '' : 'pending',
				'note'    => $page > 0 ? '' : __( 'there is no sign-in page to land on yet', 'diluxone-users' ),
			),
			array(
				'name'    => 'diluxone_users_lost_password',
				'value'   => 'link',
				'checked' => 'link' === $lost,
				'title'   => __( 'Nowhere: “I forgot my password” leads to the sign-in page', 'diluxone-users' ),
				'help'    => __( 'Nothing is reset and the password is left as it was. On a site where a link already opens the door, a reset asks for the same address to send a second e-mail doing what the first one does.', 'diluxone-users' ),
				'state'   => $link ? '' : 'pending',
				'note'    => $link ? '' : __( 'the e-mail link is not one of the ways in, so this sends them nowhere they can use', 'diluxone-users' ),
			),
		)
	);

	/*
	 * Both pills above are pending and not off, which is the same answer the
	 * ways-in tab already gives to the same shape: a choice waiting on
	 * something missing somewhere else — a page nobody has made, a way in
	 * nobody switched on — and saying so in the note beside it. Off is for a
	 * thing somebody turned off, and neither of these was ever on.
	 *
	 * How wp-login.php looks is worth saying only while somebody still lands
	 * on it, and it is never a decision taken here — it is taken on Design,
	 * beside a preview of the screen it is about. As a section in the middle
	 * of these options it was a heading, a sentence and a link interrupting
	 * the one question this tab asks; beside them it is the answer to “and
	 * what does that screen look like, then”.
	 */
	diluxone_users_ui_aside_close(
		static function () use ( $seen, $brand ): void {
			if ( $seen ) {
				diluxone_users_ui_note(
					__( 'How wp-login.php looks', 'diluxone-users' ),
					__( 'People still land on that screen, so it is worth it looking like the rest of the site.', 'diluxone-users' ),
					$brand ? 'active' : 'off',
					$brand
						? __( 'with the site’s logo and colour on it', 'diluxone-users' )
						: __( 'as WordPress ships it', 'diluxone-users' ),
					// The title names a look, and a look is not a switch: "Off"
					// beside it reads as though that screen had been turned
					// off, which is the one thing it can never be.
					$brand ? __( 'Dressed', 'diluxone-users' ) : __( 'Plain', 'diluxone-users' )
				);
			}

			diluxone_users_ui_links(
				__( 'What these pages look like', 'diluxone-users' ),
				array(
					array(
						'url'   => diluxone_users_admin_url( 'diluxone-users-design', array( 'tab' => 'login' ) ),
						'label' => __( 'The sign-in page', 'diluxone-users' ),
						'help'  => __( 'Its shape, its picture and every sentence on it, with a preview beside them.', 'diluxone-users' ),
					),
					array(
						'url'   => diluxone_users_admin_url( 'diluxone-users-design', array( 'tab' => 'wp' ) ),
						'label' => 'wp-login.php',
						'help'  => __( 'The site’s name, mark and colour on the screen WordPress brings with it.', 'diluxone-users' ),
					),
					array(
						'url'   => diluxone_users_admin_url( 'diluxone-users-login', array( 'tab' => 'ways' ) ),
						'label' => __( 'What the form takes', 'diluxone-users' ),
						'help'  => __( 'Whether a password opens anything here, which is what decides if a reset has anywhere to go.', 'diluxone-users' ),
					),
				)
			);
		}
	);
}

/** With what somebody who already has an account gets in. */
function diluxone_users_screen_login_ways(): void {
	diluxone_users_ui_aside_open();

	$social   = diluxone_users_sso_working_names();
	$handles  = (bool) diluxone_users_option( 'diluxone_users_handle_enabled' );
	$passkeys = function_exists( 'diluxone_users_passkeys_enabled' );

	diluxone_users_intro( __( 'With what somebody who already has an account gets in. Every way in is a box: tick the ones this site offers. What each one does once it is on is set where that feature lives, and who gets an account at all is the Registration tab.', 'diluxone-users' ) );

	/*
	 * The two the form draws itself are one question — what the box accepts —
	 * and they are the question that cannot be answered with nothing. The
	 * ones under them are switches that ride on top of it, which is why they
	 * are a group of their own and no sentence forces a tick there.
	 */
	diluxone_users_ui_section( __( 'What the sign-in form takes', 'diluxone-users' ), __( 'The form draws these two itself. Whatever else is switched on below sits under them, on the same screen.', 'diluxone-users' ) );

	diluxone_users_forced_notice( 'diluxone_users_login_method' );

	diluxone_users_ui_choices(
		array(
			array(
				'type'     => 'checkbox',
				'name'     => 'diluxone_users_login_method[]',
				'value'    => 'link',
				'checked'  => diluxone_users_login_has_link(),
				'title'    => __( 'A link e-mailed to the address on the account', 'diluxone-users' ),
				'help'     => __( 'The form takes an address and sends a single-use link. Nothing to remember, and nothing on the site worth stealing.', 'diluxone-users' ),
				// What the link does belongs to the link, and appears with it.
				'children' => static function () use ( $handles ): void {
					diluxone_users_ui_fields_open();

					diluxone_users_ui_number(
						array(
							'label'  => __( 'The link expires after', 'diluxone-users' ),
							'name'   => 'diluxone_users_login_expiry',
							'value'  => (string) diluxone_users_option( 'diluxone_users_login_expiry' ),
							'suffix' => __( 'minutes', 'diluxone-users' ),
							'min'    => 1,
							'max'    => 1440,
						)
					);

					diluxone_users_ui_number(
						array(
							'label'  => __( 'Wait between two requests', 'diluxone-users' ),
							'name'   => 'diluxone_users_login_throttle',
							'value'  => (string) diluxone_users_option( 'diluxone_users_login_throttle' ),
							'suffix' => __( 'seconds, for the same address', 'diluxone-users' ),
							'min'    => 0,
							'help'   => __( 'Stops the form being used as a machine for e-mailing third parties.', 'diluxone-users' ),
						)
					);

					diluxone_users_ui_fields_close();

					if ( ! $handles ) {
						diluxone_users_not_now(
							__( 'Public names are off, so the box only takes the e-mail address.', 'diluxone-users' ),
							diluxone_users_admin_url( 'diluxone-users-account', array( 'tab' => 'handle' ) ),
							__( 'Turn them on →', 'diluxone-users' )
						);
					}

					diluxone_users_ui_inline_choices(
						__( 'What the box takes', 'diluxone-users' ),
						array(
							array(
								'type'    => 'checkbox',
								'name'    => 'diluxone_users_handle_login',
								'value'   => '1',
								'checked' => (bool) diluxone_users_option( 'diluxone_users_handle_login' ),
								'title'   => __( 'The public name as well as the e-mail address', 'diluxone-users' ),
							),
						),
						__( 'The link still goes to the address on the account, never to what was typed. It only saves people from remembering which address they used.', 'diluxone-users' )
					);
				},
			),
			array(
				'type'    => 'checkbox',
				'name'    => 'diluxone_users_login_method[]',
				'value'   => 'password',
				'checked' => diluxone_users_login_has_password(),
				'title'   => __( 'A username and a password', 'diluxone-users' ),
				'help'    => __( 'WordPress’s own form, under the link. Off, a password opens nothing here: wp-login.php stops showing its form and WordPress’s own registration is locked shut, because the account it hands out a password for could not use one.', 'diluxone-users' ),
			),
		),
		diluxone_users_login_ways_needs_one()
	);

	/*
	 * Switching a way in on belongs with the other ways in; what it does once
	 * it is on belongs where the feature lives. So both boxes are here and
	 * both cards carry the way to the rest of their settings — the passkeys
	 * box used to be on Security, which is why nobody found it from the one
	 * screen that lists the doors.
	 */
	diluxone_users_ui_section( __( 'Other ways in', 'diluxone-users' ), __( 'Switched on here, one by one, and none of them is needed: they are buttons under the form above, not a replacement for it.', 'diluxone-users' ) );

	$diluxone_users_social_help = __( 'The buttons under the form. Off, they go, and whoever already linked a social account keeps it and can unlink it from their account.', 'diluxone-users' );

	if ( array() !== $social ) {
		$diluxone_users_social_help = sprintf(
			/* translators: %s: providers working, comma separated */
			__( 'Working right now: %s. Off, the buttons go, and whoever already linked a social account keeps it.', 'diluxone-users' ),
			implode( ', ', $social )
		);
	}

	$diluxone_users_ways = array(
		array(
			'type'    => 'checkbox',
			'name'    => 'diluxone_users_sso_login',
			'value'   => '1',
			'checked' => (bool) diluxone_users_option( 'diluxone_users_sso_login' ),
			'title'   => __( 'A social account', 'diluxone-users' ),
			'help'    => $diluxone_users_social_help,
			'state'   => array() === $social ? 'pending' : '',
			'note'    => array() === $social ? __( 'no provider is working yet', 'diluxone-users' ) : '',
		),
	);

	if ( $passkeys ) {
		$diluxone_users_insecure = ! is_ssl() && 'local' !== wp_get_environment_type();

		$diluxone_users_ways[] = array(
			'type'    => 'checkbox',
			'name'    => 'diluxone_users_passkey_enabled',
			'value'   => '1',
			'checked' => diluxone_users_passkeys_enabled(),
			'title'   => __( 'A passkey', 'diluxone-users' ),
			'help'    => __( 'A key that stays on the person’s device or keychain: nothing to type, nothing to phish, and it counts as both steps at once.', 'diluxone-users' ),
			'state'   => $diluxone_users_insecure ? 'pending' : '',
			'note'    => $diluxone_users_insecure ? __( 'this site is not on HTTPS, and browsers refuse passkeys until it is', 'diluxone-users' ) : '',
		);
	}

	diluxone_users_ui_choices( $diluxone_users_ways );

	/*
	 * The way to the rest of each feature used to hang off the end of that
	 * card’s own sentence, which made every card read as an invitation to
	 * leave it. Out here the cards say what the box does and nothing else,
	 * and the ways out are one list, in the place somebody looks once they
	 * have finished ticking.
	 */
	diluxone_users_ui_aside_close(
		static function () use ( $passkeys ): void {
			diluxone_users_ui_note(
				__( 'What this tab does not decide', 'diluxone-users' ),
				__( 'A way in is switched on here, with the other ways in. What it does once it is on — which providers the buttons offer, what a passkey has to prove — is set where that feature lives.', 'diluxone-users' )
			);

			$links = array(
				array(
					'url'   => diluxone_users_admin_url( 'diluxone-users-social' ),
					'label' => __( 'Social login', 'diluxone-users' ),
					'help'  => __( 'Which providers the buttons offer, and the keys each one needs before it works.', 'diluxone-users' ),
				),
			);

			if ( $passkeys ) {
				$links[] = array(
					'url'   => diluxone_users_admin_url( 'diluxone-users-security', array( 'tab' => 'passkeys' ) ),
					'label' => __( 'Passkeys', 'diluxone-users' ),
					'help'  => __( 'Which passkeys are accepted, and whether a fingerprint, a face or a PIN is required.', 'diluxone-users' ),
				);
			}

			$links[] = array(
				'url'   => diluxone_users_admin_url( 'diluxone-users-login', array( 'tab' => 'register' ) ),
				'label' => __( 'Registration', 'diluxone-users' ),
				'help'  => __( 'Who gets an account at all. These boxes only say how somebody who has one gets in.', 'diluxone-users' ),
			);

			diluxone_users_ui_links( __( 'Where the rest of each one is', 'diluxone-users' ), $links );
		}
	);
}

/**
 * How the ways in are arranged, saved.
 *
 * Every id that comes back is checked against the registry rather than
 * cleaned and trusted: the order arrives as a list of names typed by a form,
 * and a name that is not a way in is not an order, it is noise that would sit
 * in the option for ever.
 */
function diluxone_users_login_arrangement_save(): void {
	// phpcs:disable WordPress.Security.NonceVerification.Missing -- the panel verifies it.
	$known  = array_keys( diluxone_users_login_arrangeable() );
	$layout = sanitize_key( wp_unslash( $_POST['diluxone_users_login_layout'] ?? 'auto' ) );
	$open   = sanitize_key( wp_unslash( $_POST['diluxone_users_login_open'] ?? '' ) );
	$order  = array_map( 'sanitize_key', (array) wp_unslash( $_POST['diluxone_users_login_order'] ?? array() ) );

	diluxone_users_save_options(
		array(
			'diluxone_users_login_layout' => in_array( $layout, array( 'auto', 'stack', 'tabs' ), true ) ? $layout : 'auto',
			// Empty is an answer — "whichever is first" — and it is the one a
			// site keeps until it has a reason not to.
			'diluxone_users_login_open'   => in_array( $open, $known, true ) ? $open : '',
			'diluxone_users_login_order'  => array_values( array_intersect( array_unique( $order ), $known ) ),
		)
	);
	// phpcs:enable
}

/**
 * The ways in that can be arranged: everything except what stays on top.
 *
 * Whether a site offers one today does not come into it. A way in that is
 * switched off keeps its place in the order, so turning the networks off for
 * a fortnight and on again does not silently move them to the end of the
 * strip — and the list stays the same length while somebody is reading it.
 *
 * @return array<string, array<string, mixed>>
 */
function diluxone_users_login_arrangeable(): array {
	return array_filter( diluxone_users_ways_known(), static fn( array $way ): bool => empty( $way['outside'] ) );
}

/**
 * The ways in, in order, and what the screen does with them.
 *
 * The order is dragged and not numbered because it is one thing seen at a
 * glance, which is the same reason the sections of the account area are
 * dragged — and it is the same list, with the same rows and the same grip, so
 * that a person who has ordered one has already learnt this one.
 *
 * It is a form of this panel's own, and a drop that ends a drag sends it. Its
 * button is on the preview, where every tab keeps the one that saves it; it
 * reaches the form by its id.
 */
function diluxone_users_screen_login_arrangement(): void {
	$ways    = diluxone_users_login_arrangeable();
	$offered = diluxone_users_ways();
	$outside = array_filter( diluxone_users_ways_known(), static fn( array $way ): bool => ! empty( $way['outside'] ) );
	$layout  = (string) diluxone_users_option( 'diluxone_users_login_layout' );
	$open    = (string) diluxone_users_option( 'diluxone_users_login_open' );

	diluxone_users_intro( __( 'What the sign-in screen does with the ways in the tab before this one switched on. Which ones there are is decided there; this is how they are laid out once there are several, and in what order.', 'diluxone-users' ) );

	echo '<form method="post" id="diluxone-users-arrangement">';
	wp_nonce_field( 'diluxone_users_panel_diluxone-users-login', 'diluxone_users_panel_nonce' );
	diluxone_users_ui_save( 'diluxone-users-arrangement', __( 'Save the arrangement', 'diluxone-users' ) );

	diluxone_users_ui_section(
		__( 'Stacked or in tabs', 'diluxone-users' ),
		__( 'Stacked is every way in on the screen at once, one under the other. In tabs they share one place and the screen stops growing downwards, which is what a laptop notices.', 'diluxone-users' )
	);

	diluxone_users_forced_notice( 'diluxone_users_login_layout' );

	diluxone_users_ui_choices(
		array(
			array(
				'name'    => 'diluxone_users_login_layout',
				'value'   => 'auto',
				'checked' => ! in_array( $layout, array( 'stack', 'tabs' ), true ),
				'title'   => __( 'Whichever suits how many there are', 'diluxone-users' ),
				'help'    => sprintf(
					/* translators: %d: how many ways in this site would put behind tabs */
					__( 'Stacked with two ways in or fewer, tabs from three. Two doors read better one under the other than behind a strip that asks you to choose before you can see either; four are what makes the screen too tall. Right now this site has %d.', 'diluxone-users' ),
					count( array_diff_key( $offered, $outside ) )
				),
			),
			array(
				'name'    => 'diluxone_users_login_layout',
				'value'   => 'stack',
				'checked' => 'stack' === $layout,
				'title'   => __( 'Always stacked', 'diluxone-users' ),
				'help'    => __( 'Everything on the screen, nothing behind a tab. The arrangement that needs no JavaScript, and the one somebody without it always gets.', 'diluxone-users' ),
			),
			array(
				'name'    => 'diluxone_users_login_layout',
				'value'   => 'tabs',
				'checked' => 'tabs' === $layout,
				'title'   => __( 'Always in tabs', 'diluxone-users' ),
				'help'    => __( 'Even with two. Worth it on a site where one way in is for almost everybody and the other is the exception.', 'diluxone-users' ),
			),
		)
	);

	diluxone_users_ui_section(
		__( 'In what order', 'diluxone-users' ),
		__( 'Drag them. It is the order of the tabs and, stacked, the order down the page — so it is what somebody sees first either way. A way in that is switched off keeps its place here, so turning it off for a week does not move it to the end.', 'diluxone-users' )
	);

	if ( array() !== $outside ) {
		diluxone_users_ui_note(
			__( 'Above all of them, always', 'diluxone-users' ),
			sprintf(
				/* translators: %s: the ways in that are never behind a tab, comma separated */
				esc_html__( '%s is not in this list and never goes behind a tab: it is the fastest way in there is, a tab would cost it a click, and inside one the browser never gets to offer it as the address field is focused.', 'diluxone-users' ),
				'<strong>' . esc_html( implode( ', ', array_map( static fn( array $way ): string => (string) $way['label'], $outside ) ) ) . '</strong>'
			)
		);
	}

	?>
	<ul class="diluxone-users-endpoints__list" data-diluxone-users-sortable>
		<?php foreach ( $ways as $diluxone_users_id => $diluxone_users_way ) : ?>
			<li class="diluxone-users-endpoint <?php echo isset( $offered[ $diluxone_users_id ] ) ? '' : 'is-off'; ?>">
				<input type="hidden" name="diluxone_users_login_order[]" value="<?php echo esc_attr( (string) $diluxone_users_id ); ?>">
				<span class="diluxone-users-endpoint__name"><?php echo esc_html( (string) $diluxone_users_way['label'] ); ?></span>
				<span class="diluxone-users-endpoint__grip" aria-hidden="true"></span>
			</li>
		<?php endforeach; ?>
	</ul>
	<?php

	diluxone_users_ui_section(
		__( 'Which tab opens', 'diluxone-users' ),
		__( 'Only the first time. After that it is whichever one that person used last, which their browser remembers — one id in a cookie, nothing about who they are.', 'diluxone-users' )
	);

	/*
	 * Stacked there are no tabs, so none opens first; and a way in the site
	 * does not offer is not a tab. The first is said above the box, while it
	 * is the saved answer. The second is not offered in it: the screen would
	 * quietly open the first one instead.
	 */
	$in_tabs = array_diff_key( $offered, $outside );

	if ( 'stack' === diluxone_users_way_layout( count( $in_tabs ) ) ) {
		diluxone_users_not_now( __( 'The ways in are stacked on this site, so there are no tabs and none opens first. What is chosen here applies the day they are in tabs.', 'diluxone-users' ) );
	}

	diluxone_users_ui_select(
		array(
			'label'   => __( 'For somebody this site has never seen', 'diluxone-users' ),
			'name'    => 'diluxone_users_login_open',
			'value'   => isset( $in_tabs[ $open ] ) ? $open : '',
			'options' => array( '' => __( 'The first one in the order above', 'diluxone-users' ) ) + array_map(
				static fn( array $way ): string => (string) $way['label'],
				array_intersect_key( $ways, $in_tabs )
			),
			'help'    => __( 'A screen that comes back from a refused attempt ignores this and shows the way in that was refused, whatever it says here: a message about an address, read over a password form, explains nothing.', 'diluxone-users' ),
		)
	);

	echo '</form>';
}

/**
 * The sign-in form as the site serves it.
 *
 * The shortcode answers with nothing to somebody who is already signed in,
 * and in the dashboard everybody is, so the template is rendered the way the
 * shortcode renders it. It is still the template — the theme's copy of it if
 * there is one — and not a drawing of the template, which is the only way a
 * preview is worth looking at.
 */
function diluxone_users_login_preview(): void {
	// The same frame and the same template the front end uses, in the same
	// order: a preview that skips the frame previews a page that does not
	// exist.
	diluxone_users_login_frame_open();

	echo diluxone_users_render( // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- the template escapes its own output.
		'login.php',
		array(
			'state'     => diluxone_users_state(),
			'email'     => '',
			'providers' => diluxone_users_sso_for_login(),
			'minutes'   => diluxone_users_login_expiry(),
			'title'     => false,
		)
	);

	diluxone_users_login_frame_close();
}

/** What the sign-in screen says, in the site's own words. */
function diluxone_users_screen_login_words(): void {
	diluxone_users_ui_section(
		__( 'What the screen says', 'diluxone-users' ),
		__( 'The sentences on the sign-in screen. Leave a box empty and the plugin says its own, which is what the grey text in each box shows — so a site that changes nothing still reads correctly, and changing one is never a step somebody has to remember.', 'diluxone-users' )
	);

	diluxone_users_ui_inline_choices(
		__( 'The envelope on “check your email”', 'diluxone-users' ),
		array(
			array(
				'name'    => 'diluxone_users_sent_icon',
				'value'   => 'plain',
				'checked' => 'circle' !== (string) diluxone_users_option( 'diluxone_users_sent_icon' ),
				'title'   => __( 'On its own', 'diluxone-users' ),
			),
			array(
				'name'    => 'diluxone_users_sent_icon',
				'value'   => 'circle',
				'checked' => 'circle' === (string) diluxone_users_option( 'diluxone_users_sent_icon' ),
				'title'   => __( 'In a circle of washed colour', 'diluxone-users' ),
			),
		)
	);

	diluxone_users_ui_words(
		'diluxone_users_login_title',
		__( 'The heading', 'diluxone-users' ),
		__( 'Sign in', 'diluxone-users' ),
		__( 'Writing one shows it even when the shortcode was not asked for a heading: naming this screen is meant to be read.', 'diluxone-users' )
	);

	diluxone_users_ui_words(
		'diluxone_users_login_intro',
		__( 'The line under it', 'diluxone-users' ),
		'',
		__( 'Nothing by default. This is where a site says something like “if it is your first time, the account is created in the same step”.', 'diluxone-users' )
	);

	diluxone_users_ui_words_area(
		'diluxone_users_login_legal',
		__( 'The terms line', 'diluxone-users' ),
		'',
		esc_html__( 'Goes under the form, small. On a site where signing in also creates the account, this is the only place the rules can be stated — there is no separate registration form to put them on.', 'diluxone-users' )
		. ' ' . sprintf(
			/* translators: %s: an example of a link, literal HTML */
			esc_html__( 'Links are allowed, and they are the point: %s', 'diluxone-users' ),
			'<code>&lt;a href="/terms/"&gt;' . esc_html__( 'terms', 'diluxone-users' ) . '&lt;/a&gt;</code>'
		),
		3,
		true
	);

	diluxone_users_ui_words(
		'diluxone_users_sent_title',
		__( 'After the link is sent', 'diluxone-users' ),
		__( 'Check your email', 'diluxone-users' ),
		__( 'The screen somebody lands on once the e-mail is on its way.', 'diluxone-users' )
	);

	diluxone_users_ui_words(
		'diluxone_users_sent_note',
		__( 'And the note under it', 'diluxone-users' ),
		__( 'Did not arrive? Check your spam or promotions folder.', 'diluxone-users' )
	);
}

/**
 * The shapes the sign-in page comes in.
 *
 * Only the first leaves the page alone, and it is the default: a site that
 * designed its own page does not want a plugin taking it over. The other
 * three are for the sites that would otherwise install a second plugin to get
 * a sign-in screen that does not look like a form dropped into a blog post.
 *
 * @return array<string, array{label: string, help: string, picture: bool}>
 */
function diluxone_users_login_templates(): array {
	$templates = array(
		'plain'    => array(
			'label'   => __( 'In the page', 'diluxone-users' ),
			'help'    => __( 'The form where the theme put it, with nothing around it. For a site that already designed this page.', 'diluxone-users' ),
			'picture' => false,
		),
		'card'     => array(
			'label'   => __( 'A centred card', 'diluxone-users' ),
			'help'    => __( 'The form in a box in the middle of the page. The safe answer when there is no design for this screen.', 'diluxone-users' ),
			'picture' => false,
		),
		'split'    => array(
			'label'   => __( 'Split with a picture', 'diluxone-users' ),
			'help'    => __( 'Half the window is a picture and the other half is the form. On a phone the picture goes and the form stays.', 'diluxone-users' ),
			'picture' => true,
		),
		'backdrop' => array(
			'label'   => __( 'On a full background', 'diluxone-users' ),
			'help'    => __( 'The picture behind everything and the form on top of it, darkened enough to stay readable.', 'diluxone-users' ),
			'picture' => true,
		),
	);

	/**
	 * Filters the shapes the sign-in page comes in.
	 *
	 * A shape added here needs its own CSS: the plugin prints the id as a
	 * class on the frame — `diluxone-users-login-frame--<id>` — and stops
	 * there. Set `picture` to true and it gets the picture field.
	 *
	 * @since 1.0.0
	 *
	 * @param array<string, array{label: string, help: string, picture: bool}> $templates
	 */
	return (array) apply_filters( 'diluxone_users_login_templates', $templates );
}

/** The shape of the sign-in page, and what it is made of. */
function diluxone_users_screen_login_shape(): void {
	$template = diluxone_users_login_template();
	$shapes   = diluxone_users_login_templates();

	diluxone_users_intro( __( 'What surrounds the form. Three of the four take over the page, edge to edge; the first leaves it exactly where your theme put it.', 'diluxone-users' ) );

	diluxone_users_ui_section( __( 'The shape of the page', 'diluxone-users' ) );

	diluxone_users_ui_field_open( '' );
	?>
	<div class="diluxone-users-templates">
		<?php foreach ( $shapes as $id => $shape ) : ?>
			<label class="diluxone-users-templates__one <?php echo $id === $template ? 'is-chosen' : ''; ?>">
				<input type="radio" name="diluxone_users_login_template" value="<?php echo esc_attr( $id ); ?>" <?php checked( $id, $template ); ?>>
				<span class="diluxone-users-templates__art diluxone-users-templates__art--login-<?php echo esc_attr( $id ); ?>" aria-hidden="true"></span>
				<span class="diluxone-users-templates__name"><?php echo esc_html( $shape['label'] ); ?></span>
				<span class="diluxone-users-templates__help"><?php echo esc_html( $shape['help'] ); ?></span>
			</label>
		<?php endforeach; ?>
	</div>
	<?php
	diluxone_users_ui_field_close();

	diluxone_users_ui_field_open( __( 'The picture', 'diluxone-users' ) );
	diluxone_users_image_field( 'diluxone_users_login_image' );
	diluxone_users_ui_field_close( __( 'Used by the two templates that have one. It is decoration, so it carries no alternative text: nothing that has to be read should live in it.', 'diluxone-users' ) );

	diluxone_users_ui_inline_choices(
		__( 'Which side', 'diluxone-users' ),
		array(
			array(
				'name'    => 'diluxone_users_login_side',
				'value'   => 'left',
				'checked' => 'right' !== (string) diluxone_users_option( 'diluxone_users_login_side' ),
				'title'   => __( 'Picture on the left, form on the right', 'diluxone-users' ),
			),
			array(
				'name'    => 'diluxone_users_login_side',
				'value'   => 'right',
				'checked' => 'right' === (string) diluxone_users_option( 'diluxone_users_login_side' ),
				'title'   => __( 'Picture on the right, form on the left', 'diluxone-users' ),
			),
		),
		__( 'Only the split template uses this.', 'diluxone-users' )
	);

	diluxone_users_ui_section(
		__( 'What the panel says', 'diluxone-users' ),
		__( 'Half the window, with something written on it. Leave it all empty and the panel is the picture on its own, which is what it was. With a picture behind them the words get a shade over it so they stay readable, and with no picture they sit on the colour.', 'diluxone-users' )
	);

	if ( 'split' !== $template ) {
		diluxone_users_not_now( __( 'Only the split template has a panel. What is written here waits for the day that is the shape chosen above.', 'diluxone-users' ) );
	}

	diluxone_users_ui_field_open( __( 'The mark on it', 'diluxone-users' ) );
	diluxone_users_image_field( 'diluxone_users_login_panel_logo' );
	diluxone_users_ui_field_close( __( 'It sits at the top of the panel. Rarely the same file as the one above the form: this one lands on a block of colour and usually has to be the light version.', 'diluxone-users' ) );

	diluxone_users_ui_words_area(
		'diluxone_users_login_panel_title',
		__( 'The heading', 'diluxone-users' ),
		__( "One account.\nNo passwords.", 'diluxone-users' ),
		__( 'One line per line. A heading on a panel is written in deliberate lines, not broken wherever the column happens to end.', 'diluxone-users' ),
		2
	);

	diluxone_users_ui_words_area(
		'diluxone_users_login_panel_text',
		__( 'The paragraph under it', 'diluxone-users' ),
		__( 'Sign in with the account you already have, or with a link we email you.', 'diluxone-users' ),
		'',
		3
	);

	diluxone_users_ui_words_area(
		'diluxone_users_login_panel_points',
		__( 'What they get', 'diluxone-users' ),
		__( "Everything on the site\nYour certificates\nWord when we go live", 'diluxone-users' ),
		__( 'One per line, each with a tick beside it. Three or four: a panel is not a features page.', 'diluxone-users' ),
		4
	);

	diluxone_users_ui_words(
		'diluxone_users_login_panel_foot',
		__( 'The line at the foot', 'diluxone-users' ),
		__( 'Already 25,000 of us.', 'diluxone-users' ),
		__( 'It holds the bottom of the panel. Somewhere for the one fact that makes signing up feel less like a form.', 'diluxone-users' )
	);

	diluxone_users_ui_section( __( 'Your mark', 'diluxone-users' ) );

	diluxone_users_ui_note(
		'',
		sprintf(
			'%1$s <a href="%2$s">%3$s</a>',
			(int) diluxone_users_option( 'diluxone_users_login_logo' ) > 0
				? esc_html__( 'The site’s logo, above the form in every template.', 'diluxone-users' )
				: esc_html__( 'None yet.', 'diluxone-users' ),
			esc_url( diluxone_users_admin_url( 'diluxone-users-design', array( 'tab' => 'brand' ) ) ),
			esc_html__( 'It is set on Your brand →', 'diluxone-users' )
		)
	);
}

/** WordPress's own sign-in screen, which somebody still sees. */
function diluxone_users_screen_login_wp(): void {
	diluxone_users_intro( __( 'Even with everybody sent to the page above, this one is still shown to somebody: an administrator coming in through the emergency door, and anybody finishing a password reset — WordPress builds that link and it goes here and nowhere else. Left alone it is a grey box with the WordPress logo on it.', 'diluxone-users' ) );

	diluxone_users_ui_choices(
		array(
			array(
				'type'     => 'checkbox',
				'name'     => 'diluxone_users_wp_login_brand',
				'value'    => '1',
				'checked'  => (bool) diluxone_users_option( 'diluxone_users_wp_login_brand' ),
				'title'    => __( 'Put the site’s name, mark and colour on wp-login.php', 'diluxone-users' ),
				'help'     => __( 'Off by default: a plugin that repaints a screen nobody asked it about is a plugin that gets blamed for the repaint.', 'diluxone-users' ),
				// What is painted there is only a question once something is
				// painted there at all, so it hangs off the box that decides
				// it.
				'children' => static function (): void {
					diluxone_users_ui_field_open( __( 'The mark there', 'diluxone-users' ) );
					diluxone_users_image_field( 'diluxone_users_wp_login_logo' );
					diluxone_users_ui_field_close( __( 'Replaces the WordPress logo above the box. Without one, the site’s name is written there instead — which is still better than somebody else’s logo.', 'diluxone-users' ) );

					diluxone_users_ui_field_open( __( 'The colour there', 'diluxone-users' ), 'diluxone_users_wp_login_bg' );
					?>
					<input type="color" id="diluxone_users_wp_login_bg" name="diluxone_users_wp_login_bg" value="<?php echo esc_attr( diluxone_users_wp_login_bg() ); ?>">
					<?php
					diluxone_users_ui_field_close( __( 'Left as the accent colour it follows the accent, instead of becoming a second colour that drifts from the first.', 'diluxone-users' ) );
				},
			),
		)
	);
}
