<?php
/**
 * A fresh install says nothing was chosen, and a save says it was.
 *
 * Activating the plugin changes nothing, and the screens have to say so: a
 * setting nobody saved reads Not configured in every summary — the overview,
 * Access, Security, the account area, the notices — with what WordPress does
 * meanwhile, and the choices under it are drawn with nothing chosen. A block
 * that depends on another answer (what becomes of wp-login.php, the second
 * step's methods, who the dashboard profile rule reaches) waits for it:
 * disabled, nothing chosen, one line saying what for, and nothing written by
 * the save while it waits. A value saved equal to its default is somebody's
 * choice, and reads as one.
 *
 * Every case starts with no setting of the plugin's saved, which is what a
 * fresh activation leaves, and runs on both topologies.
 */

namespace Tests\Integration;

use Tests\Integration\Support\CoverSettingsSupport;

class NotConfiguredTest extends IntegrationTestCase {

	use CoverSettingsSupport;

	protected bool $fresh = true;

	protected function setUp(): void {
		parent::setUp();

		do_action( 'diluxone_users_register_panels' );
	}

	protected function tearDown(): void {
		$this->cover_settings_reset();

		parent::tearDown();
	}

	/** The states of a list of rows, as drawn. */
	private function states( array $rows ): array {
		return array_column( $rows, 'state' );
	}

	/** The rows whose "change it" link lands on one tab of Security. */
	private function security_rows_for( string $tab ): array {
		$needle = 'page=' . DILUXONE_USERS_SECURITY . '&tab=' . $tab;

		return array_values(
			array_filter(
				diluxone_users_security_rows(),
				static fn( array $row ): bool => false !== strpos( $row['url'], $needle )
			)
		);
	}

	/** How many Not configured pills a piece of HTML carries. */
	private function unset_pills( string $html ): int {
		return substr_count( $html, 'diluxone-users-state--unset' );
	}

	/* ── C1: the summaries ─────────────────────────────────────────── */

	public function test_the_state_exists_and_draws_as_a_pill_of_its_own(): void {
		$this->assertArrayHasKey( 'unset', diluxone_users_states() );
		$this->assertStringContainsString( 'diluxone-users-state--unset', diluxone_users_state_pill( 'unset' ) );
		$this->assertStringContainsString( 'Not configured', diluxone_users_state_pill( 'unset' ) );
	}

	public function test_access_summary_reads_not_configured_on_a_fresh_install_and_says_what_wordpress_does(): void {
		$rows = diluxone_users_doors();

		$this->assertSame( array_fill( 0, count( $rows ), 'unset' ), $this->states( $rows ), 'no row reads Active, Off or Pending' );
		$this->assertSame( array(), array_filter( array_column( $rows, 'why' ) ), 'a Not configured pill carries no reason' );

		$details = implode( "\n", array_column( $rows, 'detail' ) );
		$this->assertStringContainsString( 'wp-login.php, as WordPress has it', $details );
		$this->assertStringContainsString( 'WordPress has no sign-in link of its own', $details );
		$this->assertStringContainsString( 'WordPress asks for none', $details );
	}

	public function test_a_setting_saved_reads_as_decided_even_at_its_default(): void {
		diluxone_users_update_option( 'diluxone_users_login_method', 'password' );
		diluxone_users_update_option( 'diluxone_users_2fa_mode', 'off' );
		diluxone_users_update_option( 'diluxone_users_sso_login', 1 );
		diluxone_users_update_option( 'diluxone_users_login_page', 0 );

		$rows = diluxone_users_doors();

		$this->assertSame( 'pending', $rows[0]['state'], 'the page: "None" chosen on purpose waits for one' );
		$this->assertSame( 'No page chosen yet.', $rows[0]['why'] );
		$this->assertSame( 'off', $rows[1]['state'], 'the link' );
		$this->assertSame( 'active', $rows[2]['state'], 'the password' );
		$this->assertSame( 'pending', $rows[3]['state'], 'the social buttons, with no provider working' );
		$this->assertSame( 'off', end( $rows )['state'], 'the second step' );
	}

	public function test_the_overview_and_the_status_checks_tell_nothing_chosen_from_none_chosen(): void {
		$this->the_admin();

		$page = diluxone_users_check_page( 'diluxone_users_login_page', 'diluxone_users_login', 'Sign-in page', 'diluxone-users-login', 'meanwhile' );
		$this->assertSame( 'unset', $page['state'] );
		$this->assertSame( 'meanwhile', $page['detail'] );
		$this->assertSame( '', $page['why'] );
		$this->assertStringContainsString( 'diluxone-users-login', $page['url'], 'the way to choose one stays' );

		$this->assertSame( 'unset', diluxone_users_check_ways_in()['state'] );
		$this->assertStringContainsString( 'wp-login.php', diluxone_users_check_ways_in()['detail'] );

		$html = $this->draw( 'diluxone_users_panel_doors' );
		$this->assertGreaterThanOrEqual( 3, $this->unset_pills( $html ), 'the page, the method and the doors' );

		diluxone_users_update_option( 'diluxone_users_login_page', 0 );
		$this->assertSame( 'pending', diluxone_users_check_page( 'diluxone_users_login_page', 'diluxone_users_login', 'Sign-in page', 'diluxone-users-login' )['state'] );

		diluxone_users_update_option( 'diluxone_users_login_method', 'both' );
		$this->assertSame( 'active', diluxone_users_check_ways_in()['state'] );
	}

	public function test_security_summary_reads_not_configured_for_the_second_step_and_the_sessions(): void {
		$this->where_the_network_is_set();

		$two_step = $this->security_rows_for( '2fa' );
		$sessions = $this->security_rows_for( 'sessions' );

		$this->assertSame( array( 'unset', 'unset', 'unset' ), $this->states( $two_step ) );
		$this->assertStringContainsString( 'WordPress asks for none', $two_step[0]['detail'] );
		$this->assertArrayNotHasKey( 'word', $two_step[2], 'the link row keeps the state’s own word' );

		$this->assertSame( 'unset', $sessions[0]['state'], 'how long a session lasts' );
		$this->assertStringContainsString( 'WordPress decides', $sessions[0]['detail'] );
		$this->assertStringContainsString( 'The plugin changes nothing', $sessions[0]['detail'] );
		$this->assertArrayNotHasKey( 'word', $sessions[0] );
		$this->assertSame( 'unset', $sessions[1]['state'], 'their own sessions' );
	}

	/* ── C5: the session lengths ───────────────────────────────────── */

	public function test_zero_and_zero_saved_is_off_and_says_wordpress_decides(): void {
		$this->where_the_network_is_set();

		diluxone_users_update_option( 'diluxone_users_session_long_days', 0 );
		diluxone_users_update_option( 'diluxone_users_session_short_days', 0 );

		$row = $this->security_rows_for( 'sessions' )[0];

		$this->assertSame( 'off', $row['state'] );
		$this->assertSame( 'WordPress decides', $row['word'] );
		$this->assertStringContainsString( '2 days, or 14', $row['detail'] );

		diluxone_users_update_option( 'diluxone_users_session_long_days', 21 );
		$row = $this->security_rows_for( 'sessions' )[0];
		$this->assertSame( 'active', $row['state'] );
		$this->assertArrayNotHasKey( 'word', $row );
		$this->assertStringContainsString( '21 days', $row['detail'] );
	}

	public function test_the_sessions_tab_and_its_rail_say_wordpress_decides(): void {
		$this->where_the_network_is_set();
		$this->the_admin();

		$html = $this->draw( 'diluxone_users_screen_sessions' );

		$this->assertStringContainsString( 'and the plugin changes nothing', $html, 'the section' );
		$this->assertStringContainsString( 'du-state__now', $html, 'the rail’s “Right now”' );
		$this->assertStringContainsString( 'diluxone-users-state--unset', $html );
		$this->assertStringContainsString( 'WordPress decides how long a session lasts: 2 days, or 14', $html );

		diluxone_users_update_option( 'diluxone_users_session_long_days', 0 );
		diluxone_users_update_option( 'diluxone_users_session_short_days', 0 );

		$html = $this->draw( 'diluxone_users_screen_sessions' );
		$this->assertStringContainsString( 'diluxone-users-state--off">WordPress decides', $html );
	}

	/* ── Account and notices ───────────────────────────────────────── */

	public function test_account_summary_reads_not_configured_on_a_fresh_install(): void {
		$this->the_admin();

		$html = $this->draw( 'diluxone_users_screen_account_summary' );

		$this->assertSame( 6, $this->unset_pills( $html ), 'the page, the sections, the public name, the profile, the toolbar, their data' );
		$this->assertStringNotContainsString( 'diluxone-users-state--pending', $html );
		$this->assertStringNotContainsString( 'diluxone-users-state--active', $html );
		$this->assertStringContainsString( 'on the dashboard profile, as WordPress has it', $html );

		diluxone_users_update_option( 'diluxone_users_wp_profile', 'allow' );
		diluxone_users_update_option( 'diluxone_users_admin_bar', 'wp' );

		$html = $this->draw( 'diluxone_users_screen_account_summary' );
		$this->assertSame( 4, $this->unset_pills( $html ) );
		$this->assertStringContainsString( 'Reachable', $html );
		$this->assertStringContainsString( 'Shown, as WordPress does.', $html );
	}

	public function test_notices_summary_reads_not_configured_on_a_fresh_install(): void {
		$this->the_admin();

		$html = $this->draw( 'diluxone_users_screen_notices_summary' );

		$this->assertStringNotContainsString( 'diluxone-users-state--active', $html );
		$this->assertStringNotContainsString( 'diluxone-users-state--off', $html );
		$this->assertStringContainsString( 'As the plugin ships it:', $html );
		$this->assertStringContainsString( 'WordPress has no sign-in link of its own', $html );

		diluxone_users_update_option( 'diluxone_users_notice_rules', array() );

		$html = $this->draw( 'diluxone_users_screen_notices_summary' );
		$this->assertStringContainsString( 'diluxone-users-state--active', $html, 'the rules saved, at their defaults, are decided' );
	}

	/* ── C2: Ways in ───────────────────────────────────────────────── */

	public function test_ways_in_ticks_nothing_and_says_the_form_is_wordpresss_until_a_way_in_is_chosen(): void {
		$this->the_admin();

		$html = $this->draw( 'diluxone_users_screen_login_ways' );

		$this->assertDoesNotMatchRegularExpression( '/name="diluxone_users_login_method\[\]"[^>]*checked/', $html );
		$this->assertDoesNotMatchRegularExpression( '/name="diluxone_users_sso_login"[^>]*checked/', $html );
		$this->assertStringContainsString( 'class="du-waits"', $html );
		$this->assertStringContainsString( 'the sign-in form is WordPress’s own', $html );
		$this->assertStringNotContainsString( 'no provider is working yet', $html, 'a box nobody ticked waits on nothing' );

		diluxone_users_update_option( 'diluxone_users_login_method', 'password' );

		$html = $this->draw( 'diluxone_users_screen_login_ways' );
		$this->assertMatchesRegularExpression( '/value="password"[^>]*checked/', $html );
		$this->assertStringNotContainsString( 'class="du-waits"', $html );
	}

	/* ── C3: the sign-in page's two groups wait for the page ───────── */

	public function test_what_becomes_of_wp_login_and_the_reset_wait_for_a_page(): void {
		$this->the_admin();

		$html = $this->draw( 'diluxone_users_screen_login_page' );

		$this->assertSame( 2, substr_count( $html, 'du-waits-on is-waiting' ), 'both groups wait' );
		$this->assertSame( 2, substr_count( $html, '<p class="du-waits">' ), 'each under its own line, shown' );
		$this->assertStringContainsString( 'data-diluxone-users-waits="diluxone_users_login_page"', $html );
		$this->assertDoesNotMatchRegularExpression( '/name="diluxone_users_(wp_screens|lost_password)"[^>]*checked/', $html, 'nothing chosen' );
		$this->assertMatchesRegularExpression( '/name="diluxone_users_wp_screens"[^>]*disabled/', $html );
		$this->assertStringNotContainsString( 'diluxone-users-not-now', $html, 'no pending line and no pending pill on an option' );
		$this->assertStringNotContainsString( 'there is no sign-in page to land on yet', $html );

		$page = (int) wp_insert_post(
			array(
				'post_type'   => 'page',
				'post_status' => 'publish',
				'post_title'  => 'Fresh sign in',
			)
		);
		diluxone_users_update_option( 'diluxone_users_login_page', $page );
		diluxone_users_update_option( 'diluxone_users_login_method', 'both' );

		$html = $this->draw( 'diluxone_users_screen_login_page' );

		$this->assertStringNotContainsString( 'is-waiting', $html );
		$this->assertSame( 2, substr_count( $html, '<p class="du-waits" hidden>' ), 'the lines stay for the script, hidden' );
		$this->assertMatchesRegularExpression( '/value="auto"[^>]*checked/', $html, 'the default, read through, once a page is there' );
		$this->assertDoesNotMatchRegularExpression( '/name="diluxone_users_wp_screens"[^>]*disabled/', $html );
	}

	public function test_the_page_save_leaves_a_waiting_group_alone(): void {
		$this->the_admin();

		// What the browser sends while both groups wait: the page alone.
		$this->send_panel( 'diluxone-users-login', array( 'diluxone_users_login_page' => '0' ) );
		diluxone_users_login_page_save();

		$this->assertTrue( diluxone_users_option_is_set( 'diluxone_users_login_page' ) );
		$this->assertFalse( diluxone_users_option_is_set( 'diluxone_users_wp_screens' ) );
		$this->assertFalse( diluxone_users_option_is_set( 'diluxone_users_lost_password' ) );

		$this->send_panel(
			'diluxone-users-login',
			array(
				'diluxone_users_login_page'    => '0',
				'diluxone_users_wp_screens'    => 'mine',
				'diluxone_users_lost_password' => 'site',
			)
		);
		diluxone_users_login_page_save();

		$this->assertSame( 'mine', diluxone_users_option( 'diluxone_users_wp_screens' ) );
		$this->assertSame( 'site', diluxone_users_option( 'diluxone_users_lost_password' ) );
	}

	/* ── C4: the second step's groups wait for the mode ────────────── */

	public function test_the_second_steps_groups_wait_while_it_is_off_or_unset(): void {
		$this->where_the_network_is_set();
		$this->the_admin();

		$html = $this->draw( 'diluxone_users_screen_login_2fa' );

		$this->assertDoesNotMatchRegularExpression( '/name="diluxone_users_2fa_mode"[^>]*checked/', $html, 'no mode chosen' );
		$this->assertSame( 2, substr_count( $html, 'du-waits-on is-waiting' ), 'with what, and the link rule' );
		$this->assertStringContainsString( 'No second step is chosen yet', $html );
		$this->assertDoesNotMatchRegularExpression( '/name="diluxone_users_2fa_(methods\[\]|link)"[^>]*checked/', $html );
		$this->assertMatchesRegularExpression( '/name="diluxone_users_panel_drawn\[\]" value="diluxone_users_2fa_methods"[^>]*disabled/', $html, 'the line the save reads is disabled with the boxes' );
		$this->assertStringNotContainsString( 'diluxone-users-not-now', $html );

		diluxone_users_update_option( 'diluxone_users_2fa_mode', 'off' );

		$html = $this->draw( 'diluxone_users_screen_login_2fa' );
		$this->assertMatchesRegularExpression( '/value="off"[^>]*checked/', $html );
		$this->assertSame( 2, substr_count( $html, 'du-waits-on is-waiting' ) );
		$this->assertStringContainsString( 'The second step is off, so nothing below is asked of anybody.', $html );

		diluxone_users_update_option( 'diluxone_users_2fa_mode', 'optional' );

		$html = $this->draw( 'diluxone_users_screen_login_2fa' );
		$this->assertStringNotContainsString( 'is-waiting', $html );
		$this->assertMatchesRegularExpression( '/value="email"[^>]*checked/', $html, 'the default method, once the step is on' );
	}

	public function test_the_second_step_save_writes_only_what_was_asked(): void {
		$this->where_the_network_is_set();
		$this->the_admin();

		// Saved with nothing chosen: nothing is written, and nothing refused.
		$this->send_panel( DILUXONE_USERS_SECURITY, array() );
		$this->assertTrue( diluxone_users_2fa_save() );
		$this->assertFalse( diluxone_users_option_is_set( 'diluxone_users_2fa_mode' ) );
		$this->assertFalse( diluxone_users_option_is_set( 'diluxone_users_2fa_methods' ) );

		// Off, with the groups under it disabled: the mode alone.
		$this->send_panel( DILUXONE_USERS_SECURITY, array( 'diluxone_users_2fa_mode' => 'off' ) );
		$this->assertTrue( diluxone_users_2fa_save() );
		$this->assertSame( 'off', diluxone_users_option( 'diluxone_users_2fa_mode' ) );
		$this->assertFalse( diluxone_users_option_is_set( 'diluxone_users_2fa_methods' ) );

		// On, with the boxes drawn and one ticked: all three.
		$this->send_panel(
			DILUXONE_USERS_SECURITY,
			array(
				'diluxone_users_2fa_mode'    => 'optional',
				'diluxone_users_panel_drawn' => array( 'diluxone_users_2fa_methods' ),
				'diluxone_users_2fa_methods' => array( 'email' ),
			)
		);
		$this->assertTrue( diluxone_users_2fa_save() );
		$this->assertSame( 'optional', diluxone_users_option( 'diluxone_users_2fa_mode' ) );
		$this->assertSame( array( 'email' ), diluxone_users_option( 'diluxone_users_2fa_methods' ) );

		// On, with the boxes drawn and none ticked: refused, as before.
		$this->send_panel(
			DILUXONE_USERS_SECURITY,
			array(
				'diluxone_users_2fa_mode'    => 'optional',
				'diluxone_users_panel_drawn' => array( 'diluxone_users_2fa_methods' ),
			)
		);
		$this->assertFalse( diluxone_users_2fa_save() );
		$this->assertSame( array( 'email' ), diluxone_users_option( 'diluxone_users_2fa_methods' ), 'nothing written' );
	}

	/* ── C6: who the dashboard profile rule reaches waits for the rule ── */

	public function test_for_whom_waits_until_the_profile_is_redirected_or_closed(): void {
		$this->the_admin();

		$html = $this->draw( 'diluxone_users_screen_account_dashboard' );

		$this->assertDoesNotMatchRegularExpression( '/name="diluxone_users_wp_profile"[^>]*checked/', $html, 'no answer chosen' );
		$this->assertStringContainsString( 'du-waits-on is-waiting', $html );
		$this->assertStringContainsString( 'data-diluxone-users-waits="diluxone_users_wp_profile"', $html );
		$this->assertStringContainsString( 'data-diluxone-users-waits-is="redirect block"', $html );
		$this->assertDoesNotMatchRegularExpression( '/name="diluxone_users_wp_profile_scope"[^>]*checked/', $html );
		$this->assertMatchesRegularExpression( '/name="diluxone_users_wp_profile_roles\[\]"[^>]*disabled/', $html );
		$this->assertStringNotContainsString( 'diluxone-users-not-now', $html, 'no pending pill' );

		diluxone_users_update_option( 'diluxone_users_wp_profile', 'allow' );
		$this->assertStringContainsString( 'du-waits-on is-waiting', $this->draw( 'diluxone_users_screen_account_dashboard' ), 'left as WordPress ships it: still waiting' );

		diluxone_users_update_option( 'diluxone_users_wp_profile', 'redirect' );
		$html = $this->draw( 'diluxone_users_screen_account_dashboard' );
		$this->assertStringNotContainsString( 'is-waiting', $html );
		$this->assertMatchesRegularExpression( '/name="diluxone_users_wp_profile_scope"\s+value="all"[^>]*checked/', $html );
	}

	public function test_the_dashboard_save_leaves_the_profile_and_its_scope_alone_while_they_wait(): void {
		$this->the_admin();

		$this->send_panel( 'diluxone-users-account', array( 'diluxone_users_admin_bar' => 'wp' ) );
		diluxone_users_account_dashboard_save();

		$this->assertTrue( diluxone_users_option_is_set( 'diluxone_users_admin_bar' ) );
		$this->assertFalse( diluxone_users_option_is_set( 'diluxone_users_wp_profile' ) );
		$this->assertFalse( diluxone_users_option_is_set( 'diluxone_users_wp_profile_scope' ) );

		$this->send_panel(
			'diluxone-users-account',
			array(
				'diluxone_users_admin_bar'        => 'wp',
				'diluxone_users_wp_profile'       => 'block',
				'diluxone_users_wp_profile_scope' => 'some',
				'diluxone_users_wp_profile_roles' => array( 'subscriber' ),
			)
		);
		diluxone_users_account_dashboard_save();

		$this->assertSame( 'block', diluxone_users_option( 'diluxone_users_wp_profile' ) );
		$this->assertSame( 'some', diluxone_users_option( 'diluxone_users_wp_profile_scope' ) );
		$this->assertSame( array( 'subscriber' ), diluxone_users_option( 'diluxone_users_wp_profile_roles' ) );
	}

	/* ── C7: a save is a decision ──────────────────────────────────── */

	public function test_saving_ways_in_at_its_defaults_makes_the_doors_read_as_decided(): void {
		$this->the_admin();

		$this->send_panel(
			'diluxone-users-login',
			array(
				'diluxone_users_login_method'   => array( 'password' ),
				'diluxone_users_login_expiry'   => '15',
				'diluxone_users_login_throttle' => '60',
			)
		);
		$this->assertTrue( diluxone_users_login_ways_save() );

		$rows = diluxone_users_doors();

		$this->assertSame( 'off', $rows[1]['state'], 'the link, decided off' );
		$this->assertSame( 'active', $rows[2]['state'], 'the password, decided on' );
		// On a network the social buttons are the network's, switched in
		// Network Admin: the hub's save leaves them alone, so they stay undecided.
		$this->assertSame( is_multisite() ? 'unset' : 'off', $rows[3]['state'], 'the social buttons: the box was drawn and not ticked, or not the hub’s to tick' );
		$this->assertSame( 'unset', $rows[0]['state'], 'the page, which that tab does not ask' );
	}

	public function test_a_value_pinned_from_code_is_decided(): void {
		$this->assertFalse( diluxone_users_option_is_set( 'diluxone_users_2fa_mode' ) );

		$this->hook( 'diluxone_users_option', static fn( $value, string $key ) => 'diluxone_users_2fa_mode' === $key ? 'required' : $value, 10, 2 );

		$this->assertTrue( diluxone_users_option_forced( 'diluxone_users_2fa_mode' ) );
		$this->assertTrue( diluxone_users_option_is_set( 'diluxone_users_2fa_mode' ) );

		$rows = diluxone_users_doors();
		$this->assertSame( 'active', end( $rows )['state'], 'the second step, pinned on, reads as decided' );
	}

	/* ── The pieces themselves ─────────────────────────────────────── */

	public function test_a_waiting_group_is_drawn_disabled_unchecked_and_without_pills_and_the_line_hides_when_it_is_not(): void {
		$choices = array(
			array(
				'type'    => 'checkbox',
				'name'    => 'diluxone_users_test[]',
				'value'   => 'a',
				'checked' => true,
				'title'   => 'A',
				'state'   => 'pending',
				'note'    => 'waiting on something else',
			),
		);

		$waits = array(
			'why'     => 'What it waits for.',
			'name'    => 'diluxone_users_other',
			'is'      => array( 'yes', 'also' ),
			'waiting' => true,
		);

		$html = $this->draw( 'diluxone_users_ui_choices', $choices, '', array(), $waits );

		$this->assertStringContainsString( '<div class="du-waits-on is-waiting" data-diluxone-users-waits="diluxone_users_other" data-diluxone-users-waits-is="yes also">', $html );
		$this->assertStringContainsString( '<p class="du-waits">What it waits for.</p>', $html );
		$this->assertMatchesRegularExpression( '/name="diluxone_users_test\[\]"[^>]*data-diluxone-users-waited[^>]*disabled/', $html );
		$this->assertDoesNotMatchRegularExpression( '/name="diluxone_users_test\[\]"[^>]*checked/', $html );
		$this->assertMatchesRegularExpression( '/name="diluxone_users_panel_drawn\[\]"[^>]*disabled/', $html );
		$this->assertStringNotContainsString( 'diluxone-users-state--pending', $html );
		$this->assertStringNotContainsString( 'waiting on something else', $html );

		$waits['waiting'] = false;
		$html             = $this->draw( 'diluxone_users_ui_choices', $choices, '', array(), $waits );

		$this->assertStringContainsString( '<div class="du-waits-on" data-diluxone-users-waits="diluxone_users_other"', $html );
		$this->assertStringContainsString( '<p class="du-waits" hidden>What it waits for.</p>', $html );
		$this->assertMatchesRegularExpression( '/name="diluxone_users_test\[\]"[^>]*checked/', $html );
		$this->assertDoesNotMatchRegularExpression( '/name="diluxone_users_test\[\]"[^>]*disabled/', $html );
		$this->assertStringContainsString( 'diluxone-users-state--pending', $html );
	}

	public function test_a_summary_row_is_the_callers_once_decided_and_not_configured_before(): void {
		$row = array(
			'label'  => 'Thing',
			'state'  => 'active',
			'why'    => 'because',
			'word'   => 'Shown',
			'detail' => 'what it does',
			'url'    => 'https://example.test',
		);

		$drawn = diluxone_users_summary_row( 'diluxone_users_sessions_show', $row, 'what WordPress does' );

		$this->assertSame( 'unset', $drawn['state'] );
		$this->assertSame( '', $drawn['why'] );
		$this->assertSame( 'what WordPress does', $drawn['detail'] );
		$this->assertArrayNotHasKey( 'word', $drawn );
		$this->assertSame( 'https://example.test', $drawn['url'], 'the way to decide it stays' );
		$this->assertSame( 'Thing', $drawn['label'] );

		diluxone_users_update_option( 'diluxone_users_sessions_show', 0 );

		$this->assertSame( $row, diluxone_users_summary_row( 'diluxone_users_sessions_show', $row, 'what WordPress does' ) );
		$this->assertSame( $row, diluxone_users_summary_row( array( 'diluxone_users_privacy_export', 'diluxone_users_sessions_show' ), $row, 'x' ), 'decided once any of several is' );
	}

	public function test_the_rails_right_now_takes_the_state_and_a_word(): void {
		$html = $this->draw( 'diluxone_users_ui_aside_state', 'Nothing decided.', 'unset' );
		$this->assertStringContainsString( 'diluxone-users-state--unset">Not configured', $html );

		$html = $this->draw( 'diluxone_users_ui_aside_state', 'WordPress decides.', 'off', '', 'WordPress decides' );
		$this->assertStringContainsString( 'diluxone-users-state--off">WordPress decides', $html );
	}
}
