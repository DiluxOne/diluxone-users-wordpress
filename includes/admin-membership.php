<?php
/**
 * Network Admin › Membership: which sites an account is a member of.
 *
 * One screen, one decision, and the one button that applies it to the people
 * and sites that were already there. It exists only on a network: on a single
 * site every account is a member of the only site there is.
 *
 * @package DiluxOneUsers
 */

defined( 'ABSPATH' ) || exit;

/** The screen's slug. */
const DILUXONE_USERS_MEMBERSHIP_SCREEN = 'diluxone-users-membership';

/** Its one tab. */
function diluxone_users_membership_panels(): void {
	if ( ! diluxone_users_scoped_storage_active() ) {
		return;
	}

	diluxone_users_register_panel(
		DILUXONE_USERS_MEMBERSHIP_SCREEN,
		'policy',
		array(
			'label'      => __( 'Membership', 'diluxone-users' ),
			'position'   => 10,
			'render'     => 'diluxone_users_screen_membership_policy',
			'save'       => 'diluxone_users_membership_save',
			// Until the policy is confirmed, saving it is confirming it, and
			// the button says so.
			'save_label' => static fn(): string => diluxone_users_membership_confirmed()
				? __( 'Save changes', 'diluxone-users' )
				: __( 'Confirm the policy', 'diluxone-users' ),
		)
	);
}
add_action( 'diluxone_users_register_panels', 'diluxone_users_membership_panels' );

/** The screen. */
function diluxone_users_screen_membership(): void {
	diluxone_users_screen_panels( DILUXONE_USERS_MEMBERSHIP_SCREEN, __( 'Membership', 'diluxone-users' ) );
}

/**
 * Saves the policy, which is also confirming it.
 *
 * The first save is the confirmation the network has been waiting for (see
 * diluxone_users_membership_confirmed()). Under "every site" it is also the
 * moment everybody already there is added — on the spot when that is small,
 * through the queue when it is not — since until then nothing was.
 *
 * @return false|void False when what was sent is not one of the three.
 */
function diluxone_users_membership_save() {
	// phpcs:ignore WordPress.Security.NonceVerification.Missing -- the panel verifies it.
	$policy = sanitize_key( wp_unslash( $_POST[ DILUXONE_USERS_MEMBERSHIP ] ?? '' ) );

	if ( ! in_array( $policy, diluxone_users_membership_policies(), true ) ) {
		diluxone_users_notice( __( 'That is not one of the three answers. Nothing was saved.', 'diluxone-users' ), 'error' );

		return false;
	}

	$confirming = ! diluxone_users_membership_confirmed();

	diluxone_users_save_options(
		array(
			DILUXONE_USERS_MEMBERSHIP           => $policy,
			DILUXONE_USERS_MEMBERSHIP_CONFIRMED => 1,
		)
	);

	if ( ! $confirming || 'all' !== $policy ) {
		return;
	}

	if ( diluxone_users_membership_sync() ) {
		diluxone_users_notice( __( 'The policy is confirmed, and everybody is a member of every live site now.', 'diluxone-users' ) );
	} else {
		diluxone_users_notice( __( 'The policy is confirmed. Adding everybody to every site has started; it goes on in the background, a batch at a time, and its progress is below.', 'diluxone-users' ), 'info' );
	}
}

/**
 * Says, in Network Admin, that the policy is waiting to be confirmed.
 *
 * On the network's dashboard, its plugins list and the plugin's own screens
 * (see diluxone_users_notice_here()); not on the Membership screen itself,
 * which asks the question where it can be answered; and only to whoever can
 * answer it.
 */
function diluxone_users_membership_unconfirmed_notice(): void {
	if ( diluxone_users_membership_confirmed() || ! current_user_can( DILUXONE_USERS_NETWORK_CAP ) || ! diluxone_users_notice_here() ) {
		return;
	}

	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- which screen this is, to stay quiet on the one that asks.
	if ( DILUXONE_USERS_MEMBERSHIP_SCREEN === sanitize_key( wp_unslash( $_GET['page'] ?? '' ) ) ) {
		return;
	}

	printf(
		'<div class="notice notice-warning" data-diluxone-users-membership-unconfirmed><p>%1$s</p><p><a href="%2$s">%3$s</a></p></div>',
		esc_html__( 'DiluxOne Users+: the network’s membership policy has not been confirmed, so nobody is added to any site by it yet and WordPress’s own memberships stand. Choose who is a member of which site and confirm it.', 'diluxone-users' ),
		esc_url( diluxone_users_admin_url( DILUXONE_USERS_MEMBERSHIP_SCREEN ) ),
		esc_html__( 'Network Admin › Membership →', 'diluxone-users' )
	);
}
add_action( 'network_admin_notices', 'diluxone_users_membership_unconfirmed_notice' );

/**
 * The three answers, each in a sentence.
 *
 * @return array<string, array{title: string, help: string, state: string}>
 */
function diluxone_users_membership_choices(): array {
	$hub = diluxone_users_hub_name();

	return array(
		'all'    => array(
			'title' => __( 'Every site', 'diluxone-users' ),
			'help'  => sprintf(
				/* translators: %s: the name of the site where the network's accounts live */
				__( 'Every account is a member of every live site of the network. A new account joins every site, a new site gets every account, and signing in adds whatever is missing. The role is each site’s own New User Default Role; on %s, the role new accounts get.', 'diluxone-users' ),
				$hub
			),
			'state' => __( 'Every account is a member of every live site of the network.', 'diluxone-users' ),
		),
		'click'  => array(
			'title' => __( 'Whoever asks', 'diluxone-users' ),
			'help'  => sprintf(
				/* translators: %s: the name of the site where the network's accounts live */
				__( 'An account is a member of %s, where it lives. On any other site, somebody signed in who is not a member is offered “Join this site”, and joins with that site’s New User Default Role.', 'diluxone-users' ),
				$hub
			),
			'state' => sprintf(
				/* translators: %s: the name of the site where the network's accounts live */
				__( 'Accounts are members of %s, and join any other site with a click.', 'diluxone-users' ),
				$hub
			),
		),
		'invite' => array(
			'title' => __( 'By invitation', 'diluxone-users' ),
			'help'  => sprintf(
				/* translators: %s: the name of the site where the network's accounts live */
				__( 'An account is a member of %s, where it lives. Only a site’s administrators add people to it; somebody signed in who is not a member is told the site is by invitation.', 'diluxone-users' ),
				$hub
			),
			'state' => sprintf(
				/* translators: %s: the name of the site where the network's accounts live */
				__( 'Accounts are members of %s; administrators add them to any other site.', 'diluxone-users' ),
				$hub
			),
		),
	);
}

/**
 * One job of the queue, in words.
 *
 * @param array{kind: string, id: int, after: int, done: int, total: int} $job
 */
function diluxone_users_membership_job_says( array $job ): string {
	switch ( $job['kind'] ) {
		case 'user':
			$user = get_userdata( $job['id'] );

			return sprintf(
				/* translators: %s: the e-mail address of a new account */
				__( 'A new account, %s, on every site', 'diluxone-users' ),
				$user instanceof WP_User ? $user->user_email : '#' . $job['id']
			);
		case 'site':
			return sprintf(
				/* translators: %s: the name of a new site */
				__( 'Every account on a new site, %s', 'diluxone-users' ),
				diluxone_users_log_site_name( $job['id'] )
			);
		default:
			return __( 'Everybody on every site', 'diluxone-users' );
	}
}

/** The tab. */
function diluxone_users_screen_membership_policy(): void {
	$policy  = diluxone_users_membership();
	$choices = diluxone_users_membership_choices();
	$queue   = diluxone_users_membership_queue();

	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- only which notice to show after the redirect.
	$synced = isset( $_GET['diluxone-users-synced'] ) ? sanitize_key( wp_unslash( $_GET['diluxone-users-synced'] ) ) : '';

	if ( 'done' === $synced ) {
		diluxone_users_notice( __( 'Everybody is a member of every live site now.', 'diluxone-users' ) );
	} elseif ( 'queued' === $synced ) {
		diluxone_users_notice( __( 'Adding everybody to every site has started. It goes on in the background, a batch at a time; its progress is below.', 'diluxone-users' ), 'info' );
	}

	diluxone_users_ui_aside_open();

	diluxone_users_intro( __( 'An account belongs to the whole network; being a member of a site — having a role on it — is each site’s. This decides which sites an account is a member of, for every site of the network.', 'diluxone-users' ) );

	diluxone_users_ui_section( __( 'Who is a member of which site', 'diluxone-users' ) );

	$list = array();

	foreach ( $choices as $value => $choice ) {
		$list[] = array(
			'type'    => 'radio',
			'name'    => DILUXONE_USERS_MEMBERSHIP,
			'value'   => $value,
			'checked' => $policy === $value,
			'title'   => $choice['title'],
			'help'    => $choice['help'],
		);
	}

	diluxone_users_ui_choices( $list );

	$confirmed = diluxone_users_membership_confirmed();

	if ( ! $confirmed ) {
		diluxone_users_not_now( __( 'Not confirmed yet: until it is, none of this happens and WordPress’s own memberships stand. The button beside confirms the answer that is ticked; under “Every site”, confirming it adds every account to every live site.', 'diluxone-users' ) );
	} elseif ( 'all' === $policy ) {
		diluxone_users_membership_sync_box( $queue );
	}

	diluxone_users_ui_aside_close(
		static function () use ( $policy, $choices, $queue, $confirmed ): void {
			if ( ! $confirmed ) {
				diluxone_users_ui_aside_state(
					esc_html__( 'The policy is waiting to be confirmed: nobody is added to any site by it yet.', 'diluxone-users' ),
					'pending',
					__( 'not confirmed', 'diluxone-users' )
				);
			}

			$line = esc_html( $choices[ $policy ]['state'] );

			if ( 'all' === $policy ) {
				$line .= ' ' . esc_html(
					sprintf(
						/* translators: %s: number of sites */
						_n( '%s live site.', '%s live sites.', count( diluxone_users_membership_sites() ), 'diluxone-users' ),
						number_format_i18n( count( diluxone_users_membership_sites() ) )
					)
				);
			}

			if ( array() !== $queue ) {
				$line .= ' ' . esc_html(
					sprintf(
						/* translators: %s: number of jobs */
						_n( '%s job is adding people in the background.', '%s jobs are adding people in the background.', count( $queue ), 'diluxone-users' ),
						number_format_i18n( count( $queue ) )
					)
				);
			}

			if ( $confirmed ) {
				diluxone_users_ui_aside_state( $line, array() !== $queue ? 'pending' : 'active' );
			}

			diluxone_users_ui_note(
				__( 'A removal is a decision', 'diluxone-users' ),
				array(
					__( 'Somebody an administrator removes from a site is not added back to it by any of this — not by the policy, not by signing in, not by “Join this site” — until an administrator adds them again.', 'diluxone-users' ),
					__( 'Super admins reach every site already and are never added. Archived, spam and deleted sites get nobody.', 'diluxone-users' ),
				)
			);

			diluxone_users_ui_links(
				__( 'Where the members are', 'diluxone-users' ),
				array(
					array(
						'url'   => network_admin_url( 'users.php' ),
						'label' => __( 'Network Admin › Users', 'diluxone-users' ),
						'help'  => __( 'Every account, and the sites each one is a member of.', 'diluxone-users' ),
					),
					array(
						'url'   => network_admin_url( 'sites.php' ),
						'label' => __( 'Network Admin › Sites', 'diluxone-users' ),
						'help'  => __( 'Each site’s own users, and its New User Default Role under Settings.', 'diluxone-users' ),
					),
				)
			);
		}
	);
}

/**
 * Adding the people and sites that were already there, and how far it has got.
 *
 * A link and not a field of the form around it, like emptying the log: a
 * form inside a form is thrown away by the browser, and saving the policy
 * should never be what adds a thousand people to a thousand sites.
 *
 * @param array<int, array{kind: string, id: int, after: int, done: int, total: int}> $queue
 */
function diluxone_users_membership_sync_box( array $queue ): void {
	$sites  = count( diluxone_users_membership_sites() );
	$people = diluxone_users_membership_people_count();

	// How many there are is said when the button is pressed, in the question
	// it asks: it changes with every account made, and this sentence does not.
	diluxone_users_ui_section(
		__( 'The accounts and sites already there', 'diluxone-users' ),
		__( 'New accounts and new sites follow the policy on their own. What existed before it is added with this. Removals made by an administrator stay.', 'diluxone-users' )
	);

	if ( array() !== $queue ) {
		echo '<ul class="diluxone-users-membership-queue" data-diluxone-users-membership-queue>';

		foreach ( $queue as $job ) {
			printf(
				'<li data-diluxone-users-job="%1$s">%2$s — %3$s</li>',
				esc_attr( $job['kind'] ),
				esc_html( diluxone_users_membership_job_says( $job ) ),
				esc_html(
					sprintf(
						/* translators: 1: percentage done, 2: additions looked at, 3: additions in all */
						__( '%1$s%% (%2$s of %3$s)', 'diluxone-users' ),
						number_format_i18n( diluxone_users_membership_percent( $job['done'], $job['total'] ) ),
						number_format_i18n( $job['done'] ),
						number_format_i18n( $job['total'] )
					)
				)
			);
		}

		echo '</ul>';
	}

	printf(
		'<p><a class="button" href="%1$s" data-diluxone-users-confirm="%2$s" data-diluxone-users-sync>%3$s</a></p>',
		esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=diluxone_users_membership_sync' ), 'diluxone_users_membership_sync' ) ),
		esc_attr(
			sprintf(
				/* translators: 1: number of accounts, 2: number of sites */
				__( 'Every one of the %1$s accounts becomes a member of every one of the %2$s live sites it is not a member of yet, except where an administrator removed it. Go ahead?', 'diluxone-users' ),
				number_format_i18n( $people ),
				number_format_i18n( $sites )
			)
		),
		esc_html__( 'Sync everyone now', 'diluxone-users' )
	);
}

/**
 * "Sync everyone now".
 *
 * @return never
 */
function diluxone_users_membership_sync_request(): void {
	// On a single site nobody has the network's capability.
	if ( ! current_user_can( DILUXONE_USERS_NETWORK_CAP ) ) {
		wp_die( esc_html__( 'You are not allowed to do this.', 'diluxone-users' ) );
	}

	check_admin_referer( 'diluxone_users_membership_sync' );

	$done = diluxone_users_membership_sync();

	wp_safe_redirect(
		add_query_arg(
			array(
				'page'                  => DILUXONE_USERS_MEMBERSHIP_SCREEN,
				'diluxone-users-synced' => $done ? 'done' : 'queued',
			),
			network_admin_url( 'admin.php' )
		)
	);
	exit;
}
add_action( 'admin_post_diluxone_users_membership_sync', 'diluxone_users_membership_sync_request' );
