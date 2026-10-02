<?php
/**
 * The security tab of the account area: its buttons and what it draws.
 *
 * The refusals first — somebody signed out, a forged form, turning on a step
 * the site does not offer or one nobody could answer, a wrong code from the
 * app — then turning it on and setting up the app, and then the screen itself
 * in each state it can be in, read from the markup it prints.
 */

namespace Tests\Integration;

class CoverAccountSecurityTest extends IntegrationTestCase {

	private int $user;

	protected function setUp(): void {
		parent::setUp();

		diluxone_users_update_option( 'diluxone_users_2fa_mode', 'optional' );
		diluxone_users_update_option( 'diluxone_users_2fa_methods', array( 'totp', 'email' ) );

		$this->user = $this->make_user();
	}

	protected function tearDown(): void {
		unset( $_SERVER['HTTP_REFERER'] );

		parent::tearDown();
	}

	/** Sends the security form as the person and returns where it went. */
	private function submit( string $action, string $code = '' ): string {
		wp_set_current_user( $this->user );

		$this->postAs(
			$this->user,
			array(
				'_wpnonce'                => wp_create_nonce( 'diluxone_users_security' ),
				'diluxone_users_security' => $action,
				'diluxone_users_code'     => $code,
			)
		);

		return $this->expectRedirect( 'diluxone_users_security_submit' );
	}

	/** The security tab, as the account area draws it for the person. */
	private function screen( array $get = array() ): string {
		wp_set_current_user( $this->user );
		$_GET = $get;

		ob_start();
		diluxone_users_section_security( get_userdata( $this->user ) );

		return (string) ob_get_clean();
	}

	/* ── Who is offered it ─────────────────────────────────────────── */

	public function test_with_the_step_off_it_is_offered_to_nobody_and_on_for_nobody(): void {
		update_user_meta( $this->user, 'diluxone_users_2fa_on', 1 );
		diluxone_users_update_option( 'diluxone_users_2fa_mode', 'off' );

		$this->assertFalse( diluxone_users_2fa_offered( $this->user ) );
		$this->assertFalse( diluxone_users_2fa_on( $this->user ), 'What they once turned on does not count while the site has it off' );
	}

	/* ── The refusals ──────────────────────────────────────────────── */

	public function test_somebody_signed_out_is_sent_to_sign_in(): void {
		$this->postAs( 0, array( 'diluxone_users_security' => 'on' ) );

		$this->assertSame( diluxone_users_login_url(), $this->expectRedirect( 'diluxone_users_security_submit' ) );
	}

	public function test_a_forged_form_changes_nothing(): void {
		$this->postAs(
			$this->user,
			array(
				'_wpnonce'                => 'forged',
				'diluxone_users_security' => 'on',
			)
		);

		try {
			diluxone_users_security_submit();
			$this->fail( 'A form without the person’s nonce went through.' );
		} catch ( \WPAjaxDieContinueException $e ) {
			$this->assertSame( '', get_user_meta( $this->user, 'diluxone_users_2fa_on', true ) );
		}
	}

	public function test_turning_on_a_step_the_site_does_not_offer_is_refused(): void {
		diluxone_users_update_option( 'diluxone_users_2fa_mode', 'off' );

		$this->assertSame( 'notoffered', $this->redirectState( $this->submit( 'on' ) ) );
		$this->assertSame( 'notoffered', $this->redirectState( $this->submit( 'totp', '123456' ) ) );
		$this->assertSame( '', get_user_meta( $this->user, 'diluxone_users_2fa_on', true ) );
		$this->assertSame( '', get_user_meta( $this->user, 'diluxone_users_totp', true ) );
	}

	public function test_turning_it_on_with_nothing_to_answer_it_with_is_refused(): void {
		// The app is the only method and it is not set up yet.
		diluxone_users_update_option( 'diluxone_users_2fa_methods', array( 'totp' ) );

		$this->assertSame( 'nomethod', $this->redirectState( $this->submit( 'on' ) ) );
		$this->assertSame( '', get_user_meta( $this->user, 'diluxone_users_2fa_on', true ) );
	}

	public function test_a_wrong_code_from_the_app_sets_nothing_up(): void {
		diluxone_users_totp_pending( $this->user );

		$this->assertSame( 'badcode', $this->redirectState( $this->submit( 'totp', '000000' ) ) );
		$this->assertSame( '', get_user_meta( $this->user, 'diluxone_users_totp', true ) );
		$this->assertSame( '', get_user_meta( $this->user, 'diluxone_users_2fa_on', true ) );
	}

	public function test_an_unknown_button_only_goes_back(): void {
		$url = $this->submit( 'something-else' );

		$this->assertSame( diluxone_users_account_url( 'security' ), $url );
		$this->assertSame( '', get_user_meta( $this->user, 'diluxone_users_2fa_on', true ) );
	}

	/* ── Turning it on ─────────────────────────────────────────────── */

	public function test_turning_it_on_hands_out_backup_codes_once(): void {
		$url = $this->submit( 'on' );

		$this->assertSame( 'on', $this->redirectState( $url ) );
		$this->assertStringStartsWith( diluxone_users_account_url( 'security' ), $url );
		$this->assertSame( '1', get_user_meta( $this->user, 'diluxone_users_2fa_on', true ) );
		$this->assertGreaterThan( 0, diluxone_users_backup_left( $this->user ) );
		$this->assertStringContainsString( 'Two-step verification was turned on.', (string) $this->lastMail()['message'], 'The person is told' );

		$fresh = diluxone_users_backup_fresh( $this->user );
		$this->assertCount( diluxone_users_backup_left( $this->user ), $fresh, 'Shown once, right after' );
		$this->assertSame( array(), diluxone_users_backup_fresh( $this->user ), 'And gone on reading' );
	}

	public function test_turning_it_on_again_keeps_the_codes_they_wrote_down(): void {
		$codes = diluxone_users_backup_generate( $this->user, 3 );

		$this->assertSame( 'on', $this->redirectState( $this->submit( 'on' ) ) );
		$this->assertSame( 3, diluxone_users_backup_left( $this->user ) );
		$this->assertSame( array(), diluxone_users_backup_fresh( $this->user ), 'No new set to show' );
		$this->assertTrue( diluxone_users_backup_use( $this->user, $codes[0] ), 'The codes written down still work' );
	}

	public function test_the_app_is_set_up_with_the_code_it_shows(): void {
		$secret = diluxone_users_totp_pending( $this->user );

		$this->assertSame( 'totp', $this->redirectState( $this->submit( 'totp', diluxone_users_totp_code( $secret, time() ) ) ) );
		$this->assertSame( $secret, diluxone_users_totp_secret( $this->user ) );
		$this->assertSame( '1', get_user_meta( $this->user, 'diluxone_users_2fa_on', true ) );
		$this->assertGreaterThan( 0, diluxone_users_backup_left( $this->user ) );
		$this->assertStringContainsString( 'An authenticator app was set up.', (string) $this->lastMail()['message'] );
	}

	public function test_setting_up_the_app_with_codes_left_does_not_replace_them(): void {
		diluxone_users_backup_generate( $this->user, 2 );
		$secret = diluxone_users_totp_pending( $this->user );

		$this->assertSame( 'totp', $this->redirectState( $this->submit( 'totp', diluxone_users_totp_code( $secret, time() ) ) ) );
		$this->assertSame( 2, diluxone_users_backup_left( $this->user ) );
	}

	/* ── The screen ────────────────────────────────────────────────── */

	public function test_the_screen_with_the_step_off_offers_to_turn_it_on_and_the_app(): void {
		$html = $this->screen( array( 'diluxone-users' => 'badcode' ) );

		$this->assertStringContainsString( 'diluxone-users-notice--error', $html );
		$this->assertStringContainsString( 'That code is not right.', $html );
		$this->assertStringContainsString( '<span class="diluxone-users-chip">', $html );
		$this->assertMatchesRegularExpression( '#diluxone-users-chip">\s*Off\s*</span>#', $html );
		$this->assertStringContainsString( 'value="on"', $html );
		$this->assertStringNotContainsString( 'value="off"', $html );
		$this->assertStringNotContainsString( 'New backup codes', $html );
		$this->assertStringContainsString( 'name="diluxone_users_security" value="totp"', $html, 'The app’s set-up form' );
		$this->assertStringContainsString( 'diluxone-users-totp__key', $html );
		$this->assertStringNotContainsString( 'This site requires it.', $html );
	}

	public function test_the_screen_with_the_step_on_through_the_link_alone(): void {
		diluxone_users_update_option( 'diluxone_users_login_method', 'link' );
		diluxone_users_update_option( 'diluxone_users_2fa_link', 'never' );
		update_user_meta( $this->user, 'diluxone_users_2fa_on', 1 );
		diluxone_users_backup_generate( $this->user, 4 );

		$html = $this->screen( array( 'diluxone-users' => 'on' ) );

		$this->assertStringContainsString( 'diluxone-users-notice--ok', $html );
		$this->assertStringContainsString( 'You do not have one.', $html, 'No password on a site of links' );
		$this->assertStringContainsString( 'Right now it is not being asked anywhere', $html );
		$this->assertStringContainsString( 'Set up the authenticator app below', $html );
		$this->assertMatchesRegularExpression( '#diluxone-users-chip">\s*On\s*</span>#', $html );
		$this->assertStringContainsString( 'Ready to use: A code by email.', $html );
		$this->assertStringContainsString( 'id="diluxone-users-reauth-code"', $html, 'Turning it off asks for a code' );
		$this->assertStringContainsString( 'value="code"', $html, 'And one can be emailed' );
		$this->assertStringContainsString( 'value="off"', $html );
		$this->assertStringContainsString( 'New backup codes (4 left)', $html );
		$this->assertStringNotContainsString( 'value="on"', $html );
	}

	public function test_the_screen_where_the_site_requires_it_and_the_app_is_set_up(): void {
		diluxone_users_update_option( 'diluxone_users_2fa_mode', 'required' );
		diluxone_users_update_option( 'diluxone_users_2fa_link', 'never' );
		update_user_meta( $this->user, 'diluxone_users_totp', diluxone_users_totp_secret_new() );

		$html = $this->screen();

		$this->assertStringContainsString( 'The one on your WordPress account.', $html );
		$this->assertStringContainsString( 'It is asked when you get in with your password', $html );
		$this->assertStringNotContainsString( 'Set up the authenticator app below', $html, 'It is set up' );
		$this->assertStringContainsString( 'This site requires it.', $html );
		$this->assertStringNotContainsString( 'value="off"', $html, 'Required cannot be turned off' );
		$this->assertStringContainsString( 'value="totp_off"', $html, 'The app can be removed, with a code' );
		$this->assertStringContainsString( 'Set up. When you sign in, we ask for the six-digit code from the app.', $html );
		$this->assertStringNotContainsString( 'diluxone-users-totp__key', $html, 'No secret is printed once it is set up' );
	}

	public function test_the_screen_shows_the_fresh_codes_once(): void {
		diluxone_users_backup_stash( $this->user, array( 'AAAA-1111', 'BBBB-2222' ) );

		$html = $this->screen();

		$this->assertStringContainsString( 'Write these down now', $html );
		$this->assertStringContainsString( '<code>AAAA-1111</code>', $html );
		$this->assertStringContainsString( '<code>BBBB-2222</code>', $html );

		$this->assertStringNotContainsString( 'AAAA-1111', $this->screen(), 'A reload shows them no more' );
	}

	public function test_the_screen_lists_the_passkeys_with_their_forms(): void {
		diluxone_users_update_option( 'diluxone_users_passkey_enabled', 1 );
		diluxone_users_passkeys_save(
			$this->user,
			array(
				array(
					'id'      => 'cred-<one>',
					'label'   => 'Work <laptop>',
					'created' => time() - WEEK_IN_SECONDS,
					'used'    => time() - HOUR_IN_SECONDS,
				),
				array(
					'id'      => 'cred-two',
					'label'   => 'Phone',
					'created' => time() - DAY_IN_SECONDS,
					'used'    => 0,
				),
			)
		);

		$html = $this->screen( array( 'diluxone-users' => 'passkeyname' ) );

		$this->assertStringContainsString( 'The passkey has a new name.', $html );
		$this->assertSame( 2, substr_count( $html, '<details class="diluxone-users-key">' ) );
		$this->assertStringContainsString( '<strong>Work &lt;laptop&gt;</strong>', $html, 'The name is escaped' );
		$this->assertStringContainsString( 'value="cred-&lt;one&gt;"', $html );
		$this->assertStringContainsString( 'used 1 hour ago', $html );
		$this->assertSame( 1, preg_match_all( '/used [^<]+ ago/', $html ), 'A key never used says nothing about it' );
		$this->assertSame( 2, substr_count( $html, 'value="delete"' ) );
		$this->assertSame( 2, substr_count( $html, 'value="rename"' ) );
		$this->assertStringContainsString( 'data-diluxone-users-passkey="register"', $html );
	}

	public function test_the_screen_draws_no_second_step_where_the_site_has_none(): void {
		diluxone_users_update_option( 'diluxone_users_2fa_mode', 'off' );
		diluxone_users_update_option( 'diluxone_users_sessions_show', 0 );

		$html = $this->screen();

		$this->assertStringNotContainsString( 'Two-step verification', $html );
		$this->assertStringNotContainsString( 'Authenticator app', $html );
		$this->assertStringNotContainsString( 'Where you are signed in', $html, 'What the administrator turns off disappears' );
		$this->assertStringContainsString( 'How you get in', $html );
	}
}
