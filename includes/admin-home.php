<?php
/**
 * The plugin front page: what state all of this is in right now.
 *
 * It is not a welcome screen with a fixed text. It is the real numbers and
 * the real state: how many people there are, how many are in, what they are
 * asked for and how they get in. What you would want to know before touching
 * any other screen.
 *
 * The screen has two halves and they answer different questions. The cards at
 * the top are the headline numbers — four of them, glanced at, not read. What
 * is under them is the detail, and it is split into three panels behind tabs
 * because reading all three at once is reading none: nobody arrives here
 * wanting to know at the same time what their people use, how they get in and
 * what is asked of them.
 *
 * None of the panels edits anything. They tell, and they carry the button to
 * the screen where the thing is actually changed. The panels are registered
 * the way every other screen's are, so an add-on can put one here too; the
 * screen draws them itself only because the cards and the first steps sit
 * above the tabs, and the shared drawing has no room above its tabs.
 *
 * @package DiluxOneUsers
 */

defined( 'ABSPATH' ) || exit;

/** The three panels, in the order they are asked about. */
function diluxone_users_home_panels(): void {
	diluxone_users_register_panel(
		DILUXONE_USERS_MENU,
		'usage',
		array(
			'label'    => __( 'What your people use', 'diluxone-users' ),
			'position' => 10,
			'render'   => 'diluxone_users_panel_usage',
			'form'     => false,
		)
	);

	diluxone_users_register_panel(
		DILUXONE_USERS_MENU,
		'doors',
		array(
			'label'    => __( 'How your people get in', 'diluxone-users' ),
			'position' => 20,
			'render'   => 'diluxone_users_panel_doors',
			'form'     => false,
		)
	);

	diluxone_users_register_panel(
		DILUXONE_USERS_MENU,
		'asked',
		array(
			'label'    => __( 'What is asked of them', 'diluxone-users' ),
			'position' => 30,
			'render'   => 'diluxone_users_panel_asked',
			'form'     => false,
		)
	);
}
add_action( 'diluxone_users_register_panels', 'diluxone_users_home_panels' );

/**
 * The front-page numbers.
 *
 * How many accounts there are is counted with a COUNT(*) over the users table
 * and not with count_users(), which also groups by role by walking the
 * metadata table: with twenty-five thousand accounts that took five seconds
 * and the per-role number is not used here.
 *
 * It is cached all the same: they are two queries that do not change from one
 * minute to the next.
 *
 * @return array<string, int>
 */
function diluxone_users_home_numbers(): array {
	$cached = get_transient( 'diluxone_users_home_numbers' );

	if ( is_array( $cached ) ) {
		return $cached;
	}

	global $wpdb;

	// Two counts for the summary screen. `count_users()` walks every role to
	// return one of these numbers, and on a site of 25,000 people that takes a
	// while; the other has no API at all. They are kept for a quarter of an
	// hour, which is as fresh as a summary needs to be. Both count this
	// site's people — whoever has a role here — which on a single site is
	// everybody and on a network is not.
	$members = $wpdb->get_blog_prefix() . 'capabilities';

	// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- counts for the overview that WordPress has no API for, read as they are now.
	$numbers = array(
		'users'    => (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->usermeta} WHERE meta_key = %s", $members ) ),
		'sessions' => (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->usermeta} m INNER JOIN {$wpdb->usermeta} c ON c.user_id = m.user_id AND c.meta_key = %s WHERE m.meta_key = 'session_tokens'",
				$members
			)
		),
	);
	// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

	set_transient( 'diluxone_users_home_numbers', $numbers, 15 * MINUTE_IN_SECONDS );

	return $numbers;
}

/**
 * The steps a fresh install still has to go through.
 *
 * Three things, and no more: the plugin works out of the box, but until the
 * site says which page holds the sign-in form and which one holds the account
 * area, those two live nowhere — and if the mail does not go out, nobody gets
 * in at all. Everything else has a sane default.
 *
 * Each step says whether it is done, so the screen can show the list while
 * something is missing and get out of the way once it is not.
 *
 * @return array<int, array{label: string, detail: string, done: bool, url: string, cta: string}>
 */
function diluxone_users_setup_steps(): array {
	$login   = (int) diluxone_users_option( 'diluxone_users_login_page' );
	$account = (int) diluxone_users_option( 'diluxone_users_account_page' );
	$mail    = diluxone_users_mail_status();

	return array(
		array(
			'label'  => __( 'Choose the sign-in page', 'diluxone-users' ),
			'detail' => __( 'The page holding the [diluxone_users_login] shortcode. Until there is one, people land on wp-login.php.', 'diluxone-users' ),
			'done'   => $login > 0 || diluxone_users_door_on(),
			'url'    => diluxone_users_admin_url( 'diluxone-users-login' ),
			'cta'    => __( 'Go to Sign in', 'diluxone-users' ),
		),
		array(
			'label'  => __( 'Choose the account page', 'diluxone-users' ),
			'detail' => __( 'The page holding the [diluxone_users_account] shortcode. It is what every link to "my account" points at.', 'diluxone-users' ),
			'done'   => $account > 0,
			'url'    => diluxone_users_admin_url( 'diluxone-users-account' ),
			'cta'    => __( 'Go to Account area', 'diluxone-users' ),
		),
		array(
			'label'  => __( 'Check that e-mail goes out', 'diluxone-users' ),
			'detail' => __( 'The sign-in link, the second-step code and the data requests are all e-mail. Send yourself a test: it is the only way to know.', 'diluxone-users' ),
			'done'   => 'ok' === $mail['state'],
			'url'    => diluxone_users_admin_url( DILUXONE_USERS_STATUS, array( 'tab' => 'tools' ) ),
			'cta'    => __( 'Go to Tools', 'diluxone-users' ),
		),
	);
}

/**
 * The first steps, while there are any.
 *
 * The one still to do carries the button; the ones already done are ticked and
 * stay visible, because crossing something off is the point of a list like
 * this. Once the three are done the whole block goes and the panels below
 * take its place: a checklist of things already done is furniture.
 *
 * @param array<int, array{label: string, detail: string, done: bool, url: string, cta: string}> $steps
 */
function diluxone_users_steps( array $steps ): void {
	$next = true;

	echo '<ol class="diluxone-users-steps">';

	foreach ( $steps as $step ) {
		$show_cta = ! $step['done'] && $next;
		$next     = $next && $step['done'];

		printf(
			'<li class="diluxone-users-step%1$s"><div class="diluxone-users-step__body"><strong>%2$s</strong><p>%3$s</p>%4$s</div></li>',
			$step['done'] ? ' is-done' : '',
			esc_html( $step['label'] ),
			esc_html( $step['detail'] ),
			$show_cta
				? sprintf(
					'<a class="button button-primary" href="%1$s">%2$s</a>',
					esc_url( $step['url'] ),
					esc_html( $step['cta'] )
				)
				: ''
		);
	}

	echo '</ol>';
}

/**
 * The buttons at the foot of a panel: where what it just showed gets changed.
 *
 * @param array<string, string> $actions Label => URL.
 */
function diluxone_users_panel_actions( array $actions ): void {
	echo '<p class="diluxone-users-panel__actions">';

	foreach ( $actions as $label => $url ) {
		printf(
			'<a class="button" href="%1$s">%2$s</a> ',
			esc_url( (string) $url ),
			esc_html( (string) $label )
		);
	}

	echo '</p>';
}

/**
 * What people actually chose, out of the people there are.
 *
 * This panel is the one that is not configuration: every row is a count of
 * real accounts, and it is written as such — "40 of 120 accounts", not a bare
 * number that could be read as a setting. The bar is the same number drawn,
 * so the rows can be compared with the eye; no chart library for a handful of
 * numbers, and on paper it prints as the table it is. The rows are
 * diluxone_users_usage_rows(), which an add-on adds to.
 */
function diluxone_users_panel_usage(): void {
	diluxone_users_ui_aside_open();

	$stats = diluxone_users_stats();
	$total = (int) $stats['users'];

	$rows = diluxone_users_usage_rows( $stats );

	diluxone_users_intro(
		sprintf(
			/* translators: %s: number of accounts on the site */
			__( 'Out of the %s accounts on this site, how many chose each thing. These are people, not settings: nobody is counted here until they turn it on themselves.', 'diluxone-users' ),
			number_format_i18n( $total )
		)
	);

	if ( 0 === $total ) {
		// Not a second `diluxone_users_intro()`: that piece is the sentence
		// under the tab's title and it carries a rule under it, so two of
		// them in a row drew two rules with a band of white between.
		diluxone_users_ui_notice( esc_html__( 'There are no accounts yet, so there is nothing to count.', 'diluxone-users' ) );

		diluxone_users_ui_aside_close( static function (): void {} );

		return;
	}

	echo '<ul class="diluxone-users-usage">';

	foreach ( $rows as $row ) {
		$share = round( $row['value'] / $total * 100 );

		printf(
			'<li class="diluxone-users-usage__row">
				<div class="diluxone-users-usage__head">
					<strong>%1$s</strong>
					<span class="diluxone-users-usage__count">%2$s <span class="diluxone-users-usage__share">(%3$s)</span></span>
				</div>
				<span class="diluxone-users-usage__track"><span class="diluxone-users-usage__fill" style="width:%4$s%%"></span></span>
				<p class="diluxone-users-usage__detail">%5$s</p>
			</li>',
			esc_html( (string) $row['label'] ),
			esc_html(
				sprintf(
					/* translators: 1: how many people, 2: how many accounts in total */
					__( '%1$s of %2$s', 'diluxone-users' ),
					number_format_i18n( (int) $row['value'] ),
					number_format_i18n( $total )
				)
			),
			esc_html(
				sprintf(
					/* translators: %s: a percentage, already rounded */
					__( '%s%%', 'diluxone-users' ),
					number_format_i18n( $share )
				)
			),
			esc_attr( (string) $share ),
			esc_html( (string) $row['detail'] )
		);
	}

	echo '</ul>';

	// No rail: the counts are the whole of what this tab reports.
	diluxone_users_ui_aside_close( static function (): void {} );
}

/**
 * How people get in, in four lines.
 *
 * The full table of doors — every method, every provider, the second step,
 * the sessions — lives on the Access screen, and it used to be repeated here
 * with a different rule for what counts as ready, which is how the two came
 * to disagree. This is the short version: the page, the method, the mail that
 * the method depends on, one line that says whether any door is open at all,
 * and one that says whether anybody can register. The page row and the mail
 * row are the very same checks the maintenance screen runs, so they cannot
 * say something different here — the page row used
 * to be written out again, and did: it called a page nobody had chosen "off",
 * where the check calls it pending, and it could not tell that apart from a
 * page that was chosen and then left as a draft.
 */
function diluxone_users_panel_doors(): void {
	diluxone_users_ui_aside_open();

	$access = diluxone_users_admin_url( 'diluxone-users-login' );

	$methods = array(
		'link'     => __( 'The e-mail link only: no passwords on this site.', 'diluxone-users' ),
		'password' => __( 'Username and password only, the WordPress one.', 'diluxone-users' ),
		'both'     => __( 'The e-mail link, and username and password underneath.', 'diluxone-users' ),
	);

	$doors = diluxone_users_check_ways_in();

	diluxone_users_intro( __( 'The short version: the page, what the form takes, whether the e-mail this site depends on is going out, whether any door is open at all, and whether anybody can register.', 'diluxone-users' ) );

	diluxone_users_summary_table(
		array(
			diluxone_users_check_page(
				'diluxone_users_login_page',
				'diluxone_users_login',
				__( 'Sign-in page', 'diluxone-users' ),
				'diluxone-users-login',
				__( 'Everybody signs in on wp-login.php, as WordPress has it.', 'diluxone-users' )
			),
			diluxone_users_summary_row(
				'diluxone_users_login_method',
				array(
					'label'  => __( 'Sign-in method', 'diluxone-users' ),
					'state'  => 'active',
					'detail' => $methods[ diluxone_users_login_method() ],
					'url'    => diluxone_users_admin_url( 'diluxone-users-login', array( 'tab' => 'ways' ) ),
				),
				__( 'WordPress’s own form: a username and a password on wp-login.php.', 'diluxone-users' )
			),
			diluxone_users_check_mail(),
			array(
				'label'  => __( 'All the doors', 'diluxone-users' ),
				'state'  => $doors['state'],
				'detail' => $doors['detail'],
				'url'    => $access,
				'change' => __( 'Access › Summary →', 'diluxone-users' ),
			),
			// Signing in and getting an account are two questions with two
			// screens, and two rows: the same reading Registration's rail says.
			array_merge(
				diluxone_users_register_state(),
				array( 'change' => __( 'Registration › Summary →', 'diluxone-users' ) )
			),
		)
	);

	// No rail: the table is the whole of what this tab reports, and every
	// door's own tab is the row's link.
	diluxone_users_ui_aside_close( static function (): void {} );
}

/** The fields in use, grouped the way the account area groups them. */
function diluxone_users_panel_asked(): void {
	diluxone_users_ui_aside_open();

	$screens = diluxone_users_screens();
	$fields  = diluxone_users_fields( '', false );
	$active  = array_filter( $fields, static fn( array $f ): bool => (bool) $f['active'] );

	diluxone_users_intro( __( 'Besides the e-mail address, which is the identity and is never optional, this is what the account area asks people for.', 'diluxone-users' ) );

	if ( array() === $active ) {
		diluxone_users_ui_notice( esc_html__( 'Nothing beyond the e-mail address.', 'diluxone-users' ) );
	} else {
		?>
		<table class="widefat striped diluxone-users-summary">
			<tbody>
				<?php
				foreach ( diluxone_users_groups() as $group => $group_label ) :
					$in_group = array_filter( $active, static fn( array $f ): bool => $f['group'] === $group );

					if ( array() === $in_group ) {
						continue;
					}
					?>
					<tr>
						<th scope="row"><?php echo esc_html( $group_label ); ?></th>
						<td><?php echo esc_html( implode( ' · ', wp_list_pluck( $in_group, 'label' ) ) ); ?></td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		<?php
	}

	// No rail: the table is the whole of what this tab reports.
	diluxone_users_ui_aside_close( static function (): void {} );
}

/**
 * The way from a card to its screen, when whoever is looking can follow it.
 *
 * On a network the screen may be in Network Admin or on the main site, and a
 * link a site administrator cannot follow is a door drawn on a wall.
 *
 * @param string $screen Screen slug.
 * @param string $label  What the link says.
 * @return array<int, array<string, string>>
 */
function diluxone_users_home_card_link( string $screen, string $label ): array {
	$scope = diluxone_users_panel_scope( $screen );

	if ( ! diluxone_users_admin_owns( $scope ) && ! diluxone_users_admin_can_open( $scope ) ) {
		return array();
	}

	return array(
		array(
			'url'   => diluxone_users_admin_url( $screen ),
			'label' => $label,
		),
	);
}

/** Screen home. */
function diluxone_users_screen_home(): void {
	$numbers   = diluxone_users_home_numbers();
	$fields    = diluxone_users_fields( '', false );
	$active    = array_filter( $fields, static fn( array $f ): bool => (bool) $f['active'] );
	$providers = diluxone_users_sso_providers();
	$ready     = diluxone_users_sso_available();

	$panels  = diluxone_users_panels( DILUXONE_USERS_MENU );
	$labels  = wp_list_pluck( $panels, 'label' );
	$current = diluxone_users_tab( $labels );

	diluxone_users_screen_open( diluxone_users_screens()[ DILUXONE_USERS_MENU ] );

	$steps   = diluxone_users_setup_steps();
	$pending = array_filter( $steps, static fn( array $step ): bool => ! $step['done'] );
	?>

	<div class="diluxone-users-welcome">
		<h2>
			<?php
			/* translators: %s: plugin name */
			printf( esc_html__( 'Welcome to %s', 'diluxone-users' ), esc_html( diluxone_users_plugin_name() ) );
			?>
		</h2>
		<p><?php esc_html_e( 'Who is in this site, what is asked of them and how they get in.', 'diluxone-users' ); ?></p>
	</div>

	<?php
	diluxone_users_ui_wide_open();
	diluxone_users_ui_cards_open();

	diluxone_users_ui_card(
		array(
			'icon'   => 'dashicons-groups',
			'title'  => __( 'Accounts', 'diluxone-users' ),
			'value'  => number_format_i18n( $numbers['users'] ),
			'detail' => __( 'People with an account on this site.', 'diluxone-users' ),
			'links'  => array(
				array(
					'url'   => admin_url( 'users.php' ),
					'label' => __( 'See them', 'diluxone-users' ),
				),
			),
		)
	);

	diluxone_users_ui_card(
		array(
			'icon'   => 'dashicons-clock',
			'title'  => __( 'Open sessions', 'diluxone-users' ),
			'value'  => number_format_i18n( $numbers['sessions'] ),
			'detail' => __( 'Signed in right now, on at least one device.', 'diluxone-users' ),
			'links'  => diluxone_users_home_card_link( DILUXONE_USERS_SECURITY, __( 'See who', 'diluxone-users' ) ),
		)
	);

	diluxone_users_ui_card(
		array(
			'icon'   => 'dashicons-forms',
			'title'  => __( 'Fields in use', 'diluxone-users' ),
			'value'  => number_format_i18n( count( $active ) ) . ' / ' . number_format_i18n( count( $fields ) ),
			'detail' => __( 'Of the ones defined, how many people are actually asked for.', 'diluxone-users' ),
			'links'  => diluxone_users_home_card_link( 'diluxone-users-fields', __( 'Manage them', 'diluxone-users' ) ),
		)
	);

	diluxone_users_ui_card(
		array(
			'icon'   => 'dashicons-share',
			'title'  => __( 'Social providers', 'diluxone-users' ),
			'value'  => number_format_i18n( count( $ready ) ) . ' / ' . number_format_i18n( count( $providers ) ),
			'detail' => __( 'Verified and working, of the ones the plugin brings.', 'diluxone-users' ),
			'links'  => diluxone_users_home_card_link( 'diluxone-users-social', __( 'Set them up', 'diluxone-users' ) ),
		)
	);

	diluxone_users_ui_cards_close();
	diluxone_users_ui_wide_close();
	?>

	<?php if ( array() !== $pending ) : ?>
		<?php
		diluxone_users_ui_section(
			__( 'First steps', 'diluxone-users' ),
			__( 'Three things the plugin cannot guess. Each one disappears from this list once it is done.', 'diluxone-users' )
		);

		diluxone_users_steps( $steps );
		?>
	<?php endif; ?>

	<?php diluxone_users_managed_elsewhere(); ?>

	<?php if ( array() !== $panels ) : ?>
		<?php diluxone_users_tabs( DILUXONE_USERS_MENU, $labels, $current ); ?>

		<div class="diluxone-users-panel">
			<h2><?php echo esc_html( (string) $labels[ $current ] ); ?></h2>
			<?php
			if ( is_callable( $panels[ $current ]['render'] ) ) {
				call_user_func( $panels[ $current ]['render'] );
			}
			?>
		</div>
	<?php endif; ?>
	<?php

	diluxone_users_screen_close();
}
