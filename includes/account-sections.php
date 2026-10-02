<?php
/**
 * The sections the plugin ships.
 *
 * Each one registers itself exactly as any other plugin would: there is no
 * privileged list. The only thing of their own is that they cannot be deleted
 * from the admin — turned off, yes — because the code that draws them is
 * still there.
 *
 * @package DiluxOneUsers
 */

defined( 'ABSPATH' ) || exit;

/** Registers the plugin's own sections. */
function diluxone_users_register_own_sections(): void {
	diluxone_users_register_section(
		'home',
		array(
			'label'    => __( 'Home', 'diluxone-users' ),
			'position' => 10,
			'render'   => 'diluxone_users_section_home',
		)
	);

	diluxone_users_register_section(
		'details',
		array(
			'label'    => __( 'Your details', 'diluxone-users' ),
			'position' => 30,
			'render'   => 'diluxone_users_section_details',
			'summary'  => 'diluxone_users_summary_details',
		)
	);

	diluxone_users_register_section(
		'accounts',
		array(
			'label'     => __( 'Linked accounts', 'diluxone-users' ),
			'position'  => 40,
			'render'    => 'diluxone_users_section_accounts',
			'summary'   => 'diluxone_users_summary_accounts',
			'available' => 'diluxone_users_sso_any',
			'why'       => __( 'There is no social network turned on, so there is nothing to link.', 'diluxone-users' ),
		)
	);

	diluxone_users_register_section(
		'security',
		array(
			'label'    => __( 'Security', 'diluxone-users' ),
			'position' => 50,
			'render'   => 'diluxone_users_section_security',
			'summary'  => 'diluxone_users_summary_security',
		)
	);

	diluxone_users_register_section(
		'notifications',
		array(
			'label'     => __( 'Notifications', 'diluxone-users' ),
			'position'  => 60,
			'render'    => 'diluxone_users_section_notifications',
			'available' => 'diluxone_users_notifications_any',
			'why'       => __( 'This site e-mails nobody about anything, so there is nothing to show.', 'diluxone-users' ),
		)
	);

	diluxone_users_register_section(
		'privacy',
		array(
			'label'     => __( 'Your data', 'diluxone-users' ),
			'position'  => 70,
			'render'    => 'diluxone_users_section_privacy',
			'available' => 'diluxone_users_privacy_any',
			'why'       => __( 'Neither downloading your data nor deleting your account is allowed, so there is nothing to do here.', 'diluxone-users' ),
		)
	);
}
add_action( 'diluxone_users_register_sections', 'diluxone_users_register_own_sections' );

/** Is any social network turned on? */
function diluxone_users_sso_any(): bool {
	return function_exists( 'diluxone_users_sso_available' ) && array() !== diluxone_users_sso_available();
}

/**
 * Is there anything to show in the notifications section?
 *
 * The question used to be "is there anything to switch", and the section went
 * away when there was not — which on a site where every rule is the site's own
 * left a person with no way of finding out what it sends them. What is asked
 * now is whether there is anything to show: a switch, or a notice that goes
 * out whatever anybody says. With only the second kind the section still
 * appears, and it opens by saying there is nothing to choose.
 */
function diluxone_users_notifications_any(): bool {
	return array() !== diluxone_users_notification_choices() || array() !== diluxone_users_notification_musts();
}

/** Is anybody allowed to do anything with their data? */
function diluxone_users_privacy_any(): bool {
	return (bool) diluxone_users_option( 'diluxone_users_privacy_export' ) || (bool) diluxone_users_option( 'diluxone_users_privacy_delete' );
}

/* ── Front page ────────────────────────────────────────────────────── */

/**
 * Every summary card there is, shown or not.
 *
 * Each section builds its own out of what it knows. The front page knows none
 * of them: if LifterLMS adds "courses in progress" tomorrow, it appears on
 * its own.
 *
 * Each card carries an id, which is what the front page remembers when
 * somebody turns one off. A card arriving from the filter without one gets an
 * id made from its label — good enough, and it costs nothing to declare a
 * real one instead.
 *
 * @return array<int, array<string, string>>
 */
function diluxone_users_summary_cards(): array {
	$cards = array();

	foreach ( diluxone_users_sections() as $id => $section ) {
		if ( '' === $section['summary'] || ! is_callable( $section['summary'] ) ) {
			continue;
		}

		$card = call_user_func( $section['summary'] );

		if ( ! is_array( $card ) ) {
			continue;
		}

		$cards[] = wp_parse_args(
			$card,
			array(
				'id'    => $id,
				'label' => $section['label'],
				'value' => '',
				'note'  => '',
				'link'  => diluxone_users_account_url( $id ),
				'cta'   => __( 'Open', 'diluxone-users' ),
			)
		);
	}

	/**
	 * Filters the account summary cards.
	 *
	 * @param array<int, array<string, string>> $cards
	 */
	$cards = (array) apply_filters( 'diluxone_users_summaries', $cards );

	foreach ( $cards as $i => $card ) {
		if ( '' === (string) ( $card['id'] ?? '' ) ) {
			$cards[ $i ]['id'] = sanitize_title( (string) ( $card['label'] ?? '' ) );
		}
	}

	return $cards;
}

/**
 * Is this card's value a figure, or a piece of text?
 *
 * The cards are drawn as numbers — big, heavy, read at a glance — and that is
 * right for "11 lessons" and wrong for "Hablamos de Tecnología", which at the
 * same size takes three lines and pushes every card beside it out of shape.
 *
 * It is decided here and not asked of whoever writes the card: somebody
 * adding "the next broadcast" to a summary is thinking about the broadcast,
 * not about typography, and getting it wrong should not be possible.
 *
 * Separators come out first, so "1.250", "4/6" and "80%" are still figures.
 */
function diluxone_users_card_is_figure( string $value ): bool {
	// The last one is a non-breaking space: "1 250" written with one is
	// still a figure, and it arrives that way from more than one language.
	$bare = str_replace( array( '.', ',', ' ', '/', '%', chr( 194 ) . chr( 160 ) ), '', $value );

	return '' !== $bare && is_numeric( $bare );
}

/**
 * Which cards the front page has been told not to show.
 *
 * Stored the other way round on purpose — what is hidden, not what is shown.
 * A card that appears tomorrow because a plugin was installed shows up by
 * itself, and nobody has to remember to go and tick it.
 *
 * @return array<int, string>
 */
function diluxone_users_summaries_hidden(): array {
	return array_values( array_filter( array_map( 'sanitize_key', (array) diluxone_users_option( 'diluxone_users_home_cards_off' ) ) ) );
}

/**
 * The cards the front page actually draws.
 *
 * A card with nothing to say is dropped here and not in the admin: "linked
 * accounts" on a site with no provider set up has no number to show, but it
 * should still be on the list of what can be turned off, or the list changes
 * shape depending on who is looking at it.
 *
 * @return array<int, array<string, string>>
 */
function diluxone_users_summaries(): array {
	$hidden = diluxone_users_summaries_hidden();

	return array_values(
		array_filter(
			diluxone_users_summary_cards(),
			static function ( array $card ) use ( $hidden ): bool {
				return '' !== (string) $card['value'] && ! in_array( (string) $card['id'], $hidden, true );
			}
		)
	);
}

/**
 * On the front page the heading says hello.
 *
 * It is a filter and not an <h2> of the template's own on purpose: that way
 * there is still a single place where a section heading is written.
 */
function diluxone_users_account_heading_home( string $heading, string $id, WP_User $user ): string {
	return 'home' === $id
		? sprintf(
			/* translators: %s: first name */
			__( 'Hello, %s', 'diluxone-users' ),
			diluxone_users_first_name( $user )
		)
		: $heading;
}
add_filter( 'diluxone_users_account_heading', 'diluxone_users_account_heading_home', 10, 3 );

/** Section home. */
function diluxone_users_section_home( WP_User $user ): void {
	diluxone_users_template_part(
		'account/home',
		array(
			'user'  => $user,
			'cards' => diluxone_users_summaries(),
		)
	);
}

/* ── Personal data ─────────────────────────────────────────────────── */

/** Draws the personal-details section. */
function diluxone_users_section_details( WP_User $user ): void {
	diluxone_users_template_part( 'account/details', array( 'user' => $user ) );
}

/** Summary details. */
/**
 * @return array<string, mixed>
 */
function diluxone_users_summary_details(): array {
	$user  = wp_get_current_user();
	$total = 0;
	$done  = 0;

	foreach ( diluxone_users_fields() as $field ) {
		++$total;

		if ( '' !== (string) diluxone_users_value( $user->ID, $field['key'] ) ) {
			++$done;
		}
	}

	if ( 0 === $total ) {
		return array();
	}

	return array(
		'value' => sprintf( '%d/%d', $done, $total ),
		'note'  => __( 'fields filled in', 'diluxone-users' ),
		'cta'   => __( 'Edit', 'diluxone-users' ),
	);
}

/* ── Linked accounts ───────────────────────────────────────────────── */

/** Draws the linked-accounts section. */
function diluxone_users_section_accounts( WP_User $user ): void {
	diluxone_users_template_part( 'account/accounts', array( 'user' => $user ) );
}

/** Summary accounts. */
/**
 * @return array<string, mixed>
 */
function diluxone_users_summary_accounts(): array {
	if ( array() === diluxone_users_sso_available() ) {
		return array();
	}

	$linked = count( diluxone_users_sso_linked( get_current_user_id() ) );

	return array(
		'value' => number_format_i18n( $linked ),
		'note'  => _n( 'network linked', 'networks linked', $linked, 'diluxone-users' ),
		'cta'   => __( 'Manage', 'diluxone-users' ),
	);
}

/* ── Security ──────────────────────────────────────────────────────── */

/** Draws the security section. */
function diluxone_users_section_security( WP_User $user ): void {
	diluxone_users_template_part( 'account/security', array( 'user' => $user ) );
}

/** Summary security. */
/**
 * @return array<string, mixed>
 */
function diluxone_users_summary_security(): array {
	$open = count( diluxone_users_sessions( get_current_user_id() ) );

	return array(
		'value' => number_format_i18n( $open ),
		'note'  => _n( 'open session', 'open sessions', $open, 'diluxone-users' ),
		'cta'   => __( 'Review', 'diluxone-users' ),
	);
}

/* ── Notifications ─────────────────────────────────────────────────── */

/**
 * The notification preferences.
 *
 * The plugin does not know what this site notifies about, so it invents none:
 * it puts the screen up and whoever has something to notify registers their
 * preference here.
 *
 * @return array<string, array<string, string>>
 */
function diluxone_users_notification_prefs(): array {
	/**
	 * Filters the notification preferences.
	 *
	 * It arrives with the plugin's own already in place — the account and
	 * security ones, which it sends itself — and this is for whatever the site
	 * wants to add on top: a broadcast, a course, a forum. None of that
	 * belongs to a users plugin.
	 *
	 * Each one: key => array( 'label' => …, 'help' => …, 'default' => '1' ).
	 * The value is stored in the user meta under that same key.
	 *
	 * @param array<string, array<string, string>> $prefs
	 */
	return (array) apply_filters( 'diluxone_users_notification_prefs', diluxone_users_default_notifications() );
}

/**
 * The notices a person gets a switch for.
 *
 * Everything registered, minus what the site decided on everybody's behalf:
 * a notice that is always sent has nothing to turn off, and one that is never
 * sent has nothing to turn on. Showing either would be a switch wired to
 * nothing. What is left carries its starting position from the policy, not
 * from what it registered, so the account area and the sending agree.
 *
 * @return array<string, array<string, string>>
 */
function diluxone_users_notification_choices(): array {
	$choices = array();

	foreach ( diluxone_users_notification_prefs() as $key => $pref ) {
		$policy = diluxone_users_notice_policy( (string) $key );

		if ( ! diluxone_users_notice_is_choice( $policy ) ) {
			continue;
		}

		$pref['default'] = 'default_on' === $policy ? '1' : '';
		$choices[ $key ] = $pref;
	}

	return $choices;
}

/** Section notifications. */
function diluxone_users_section_notifications( WP_User $user ): void {
	diluxone_users_notifications_form();
}

/**
 * The notification preferences, on their own as well.
 *
 * Shortcode: [diluxone_users_notifications]. The screen belongs to the
 * plugin; what the site notifies about is registered by whoever has something
 * to notify, with the filter above.
 */
function diluxone_users_notifications_form(): void {
	if ( ! is_user_logged_in() ) {
		return;
	}

	diluxone_users_enqueue_styles();

	diluxone_users_template_part(
		'account/notifications',
		array(
			'user'  => wp_get_current_user(),
			'prefs' => diluxone_users_notification_choices(),
			// What has no switch travels apart from what has one: the template
			// draws them differently, and nothing here should let one be
			// mistaken for the other.
			'musts' => diluxone_users_notification_musts(),
		)
	);
}

/** The same, returned for the shortcode. */
function diluxone_users_shortcode_notifications(): string {
	ob_start();
	diluxone_users_notifications_form();

	return (string) ob_get_clean();
}
add_shortcode( 'diluxone_users_notifications', 'diluxone_users_shortcode_notifications' );

/** Saves the notification preferences. */
function diluxone_users_notifications_save(): void {
	if ( ! is_user_logged_in() ) {
		wp_safe_redirect( diluxone_users_login_url() );
		exit;
	}

	check_admin_referer( 'diluxone_users_notifications' );

	$user_id = get_current_user_id();

	// Only what was on the form: a notice the site decides for everybody
	// keeps no meta, so the day the rule changes the person starts from the
	// policy and not from a box they never saw.
	foreach ( diluxone_users_notification_choices() as $key => $pref ) {
		update_user_meta( $user_id, $key, isset( $_POST[ $key ] ) ? '1' : '0' );
	}

	wp_safe_redirect( add_query_arg( 'diluxone-users', 'saved', diluxone_users_account_url( 'notifications' ) ) );
	exit;
}
add_action( 'admin_post_diluxone_users_notifications', 'diluxone_users_notifications_save' );

/* ── Your data ─────────────────────────────────────────────────────── */

/**
 * Can this account ask to be deleted?
 *
 * An account with administration rights does not delete itself: its role is
 * lowered first. Otherwise the site is left with nobody to administer it,
 * one click away.
 */
function diluxone_users_can_request_erase( int $user_id ): bool {
	$user = get_userdata( $user_id );

	return $user instanceof WP_User && ! user_can( $user, 'manage_options' );
}

/**
 * The data requests this person made, of one kind.
 *
 * @param string $kind 'export_personal_data' or 'remove_personal_data'.
 * @return array<int, WP_Post>
 */
function diluxone_users_data_requests( string $email, string $kind = '' ): array {
	$found = get_posts(
		array(
			'post_type'      => 'user_request',
			'post_name__in'  => '' !== $kind ? array( $kind ) : array( 'export_personal_data', 'remove_personal_data' ),
			'title'          => $email,
			'post_status'    => 'any',
			'posts_per_page' => 10,
			'orderby'        => 'date',
			'order'          => 'DESC',
		)
	);

	return $found;
}

/**
 * Where the account downloads the finished file of an export, when there is one.
 *
 * Not the file's own address, which works for anybody who has it: a link
 * through the plugin, which hands the file over only to the account it
 * belongs to (includes/account-export.php).
 */
function diluxone_users_data_file( WP_Post $request ): string {
	if ( '' === diluxone_users_data_file_path( $request ) ) {
		return '';
	}

	return wp_nonce_url(
		add_query_arg(
			array(
				'action'  => 'diluxone_users_data_download',
				'request' => $request->ID,
			),
			admin_url( 'admin-post.php' )
		),
		'diluxone_users_data_download'
	);
}

/**
 * Where the export's file is on disk, while it is there.
 *
 * WordPress clears old exports away; a file that is gone is not offered.
 */
function diluxone_users_data_file_path( WP_Post $request ): string {
	$name = 'export_personal_data' === $request->post_name ? (string) get_post_meta( $request->ID, '_export_file_name', true ) : '';
	$path = wp_privacy_exports_dir() . $name;

	return '' !== $name && 0 === validate_file( $name ) && file_exists( $path ) ? $path : '';
}

/**
 * What each state is called, in plain words and without jargon.
 *
 * @return array<string, array{0: string, 1: string}> state => [tone, text]
 */
function diluxone_users_data_states(): array {
	return array(
		'request-pending'   => array( 'pending', __( 'Waiting for you to confirm by email', 'diluxone-users' ) ),
		'request-confirmed' => array( 'pending', __( 'Confirmed — we are preparing it', 'diluxone-users' ) ),
		'request-completed' => array( 'ok', __( 'Ready', 'diluxone-users' ) ),
		'request-failed'    => array( 'off', __( 'Failed', 'diluxone-users' ) ),
	);
}

/**
 * Can this site send the confirmation e-mail?
 *
 * Without it, the request is created and waits forever. What is looked at is
 * what happened the last time something was sent, not whether a mail plugin
 * is installed: that last one is what made this screen say all was well while
 * nothing was going out.
 */
function diluxone_users_data_mail_ready(): bool {
	return diluxone_users_mail_works();
}

/** Section privacy. */
function diluxone_users_section_privacy( WP_User $user ): void {
	// The erase request asks before it is sent.
	wp_enqueue_script( 'diluxone-users-confirm', DILUXONE_USERS_URL . 'assets/diluxone-users-confirm.js', array(), diluxone_users_asset_version( 'assets/diluxone-users-confirm.js' ), true );

	// Arrived from the e-mail to confirm the account's deletion (includes/account-confirm.php).
	$arrived = diluxone_users_confirm_arrived();
	$closing = 'ok' === $arrived['state'] && $arrived['request'] instanceof WP_User_Request && 'remove_personal_data' === $arrived['request']->action_name
		? (int) $arrived['request']->ID
		: 0;

	diluxone_users_template_part(
		'account/privacy',
		array(
			'user'      => $user,
			'exports'   => diluxone_users_data_requests( $user->user_email, 'export_personal_data' ),
			'erasures'  => diluxone_users_data_requests( $user->user_email, 'remove_personal_data' ),
			'can_erase' => diluxone_users_can_request_erase( $user->ID ),
			'closing'   => $closing,
			'key'       => $closing > 0 ? $arrived['key'] : '',
		)
	);
}

/**
 * Creates the export or erasure request.
 *
 * WordPress's own requests are used and not an exporter of our own: those
 * already send the confirmation e-mail, assemble the ZIP, and — the important
 * part — call the exporters and erasers other plugins register. An exporter
 * written here would return half of the person's data.
 */
function diluxone_users_data_request(): void {
	if ( ! is_user_logged_in() ) {
		wp_safe_redirect( diluxone_users_login_url() );
		exit;
	}

	check_admin_referer( 'diluxone_users_data_request' );

	$user = wp_get_current_user();
	$kind = 'erase' === sanitize_key( wp_unslash( $_POST['diluxone_users_request'] ?? '' ) ) ? 'remove_personal_data' : 'export_personal_data';

	// Switching a request off hides its button, and it also closes the door
	// the button posted to: a hand-made request is refused the same way.
	$allowed = 'remove_personal_data' === $kind ? 'diluxone_users_privacy_delete' : 'diluxone_users_privacy_export';

	// So does "Your data" turned off in Sections, or kept from this person's
	// role: a section they do not have has no form to send.
	if ( ! diluxone_users_option( $allowed ) || ! isset( diluxone_users_sections()['privacy'] ) ) {
		wp_safe_redirect( add_query_arg( 'diluxone-users', 'error', diluxone_users_account_url( 'privacy' ) ) );
		exit;
	}

	if ( 'remove_personal_data' === $kind && ! diluxone_users_can_request_erase( $user->ID ) ) {
		wp_safe_redirect( add_query_arg( 'diluxone-users', 'admin', diluxone_users_account_url( 'privacy' ) ) );
		exit;
	}

	// A request asked for here says so, so it is carried out when it is
	// confirmed and an erasure closes the account too (see
	// includes/account-closing.php and includes/account-export.php). One filed
	// from Tools does neither.
	$request_id = wp_create_user_request(
		$user->user_email,
		$kind,
		array( 'remove_personal_data' === $kind ? DILUXONE_USERS_CLOSE_KEY : DILUXONE_USERS_EXPORT_KEY => (int) $user->ID )
	);

	if ( is_wp_error( $request_id ) ) {
		wp_safe_redirect( add_query_arg( 'diluxone-users', 'error', diluxone_users_account_url( 'privacy' ) ) );
		exit;
	}

	wp_send_user_request( $request_id );

	wp_safe_redirect( add_query_arg( 'diluxone-users', 'requested', diluxone_users_account_url( 'privacy' ) ) );
	exit;
}
add_action( 'admin_post_diluxone_users_data_request', 'diluxone_users_data_request' );
