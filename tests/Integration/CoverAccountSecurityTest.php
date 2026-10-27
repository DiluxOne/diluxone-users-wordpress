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
		diluxone_users_update_option( 'diluxone_users_2fa_methods', array( 'email' ) );

		$this->user = $this->make_user();
	}

	/**
	 * A method of an add-on's that does not arrive by e-mail, which nobody
	 * has set up: what Users+ Pro's app is to somebody who has not scanned it.
	 */
	private function a_device_method(): void {
		$this->hook(
			'diluxone_users_2fa_methods',
			static function ( array $methods ): array {
				$methods['device'] = array(
					'channel'  => 'device',
					'label'    => 'A device',
					'help'     => '',
					'ready'    => '__return_false',
					'verify'   => '__return_false',
					'position' => 10,
				);

				return $methods;
			}
		);
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
		$this->assertSame( '', get_user_meta( $this->user, 'diluxone_users_2fa_on', true ) );
	}

	public function test_turning_it_on_with_nothing_to_answer_it_with_is_refused(): void {
		// An add-on's method is the only one, and it is not set up yet.
		$this->a_device_method();
		diluxone_users_update_option( 'diluxone_users_2fa_methods', array( 'device' ) );

		$this->assertSame( 'nomethod', $this->redirectState( $this->submit( 'on' ) ) );
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

	/* ── The screen ────────────────────────────────────────────────── */

	public function test_the_screen_with_the_step_off_offers_to_turn_it_on_and_an_add_ons_panel(): void {
		$this->hook(
			'diluxone_users_account_security_2fa',
			static function ( \WP_User $user ): void {
				echo '<p id="addon-2fa">' . (int) $user->ID . '</p>';
			}
		);

		$html = $this->screen( array( 'diluxone-users' => 'nomethod' ) );

		$this->assertStringContainsString( 'diluxone-users-notice--error', $html );
		$this->assertStringContainsString( 'First set up a way to receive the second step.', $html );
		$this->assertStringContainsString( '<span class="diluxone-users-chip">', $html );
		$this->assertMatchesRegularExpression( '#diluxone-users-chip">\s*Off\s*</span>#', $html );
		$this->assertStringContainsString( 'value="on"', $html );
		$this->assertStringNotContainsString( 'value="off"', $html );
		$this->assertStringNotContainsString( 'New backup codes', $html );
		$this->assertStringContainsString( '<p id="addon-2fa">' . $this->user . '</p>', $html, 'an add-on draws after the second step' );
		$this->assertStringNotContainsString( 'diluxone-users-totp', $html, 'no app without Users+ Pro' );
		$this->assertStringNotContainsString( 'This site requires it.', $html );
		$this->assertLessThan( strpos( $html, 'addon-2fa' ), strpos( $html, 'value="on"' ), 'after the second step’s panel' );
	}

	public function test_the_screen_with_the_step_on_through_the_link_alone(): void {
		diluxone_users_update_option( 'diluxone_users_login_method', 'link' );
		// An add-on that lets the link alone in, as Users+ Pro's "Let it decide" does.
		$this->hook( 'diluxone_users_2fa_on_link', '__return_false' );
		update_user_meta( $this->user, 'diluxone_users_2fa_on', 1 );
		diluxone_users_backup_generate( $this->user, 4 );

		$html = $this->screen( array( 'diluxone-users' => 'on' ) );

		$this->assertStringContainsString( 'diluxone-users-notice--ok', $html );
		$this->assertStringContainsString( 'You do not have one.', $html, 'No password on a site of links' );
		$this->assertStringContainsString( 'Right now it is not being asked anywhere', $html );
		$this->assertStringNotContainsString( 'Set up a way of getting the code', $html, 'nothing else to set up' );
		$this->assertMatchesRegularExpression( '#diluxone-users-chip">\s*On\s*</span>#', $html );
		$this->assertStringContainsString( 'Ready to use: A code by email.', $html );
		$this->assertStringContainsString( 'id="diluxone-users-reauth-code"', $html, 'Turning it off asks for a code' );
		$this->assertStringContainsString( 'value="code"', $html, 'And one can be emailed' );
		$this->assertStringContainsString( 'value="off"', $html );
		$this->assertStringContainsString( 'New backup codes (4 left)', $html );
		$this->assertStringNotContainsString( 'value="on"', $html );

		// With an add-on's method that does not arrive by e-mail, not set up:
		// the way to be asked on the link too.
		$this->a_device_method();
		diluxone_users_update_option( 'diluxone_users_2fa_methods', array( 'email', 'device' ) );
		$this->assertStringContainsString( 'Set up a way of getting the code that is not an email, below', $this->screen() );
	}

	public function test_the_screen_where_the_site_requires_it(): void {
		diluxone_users_update_option( 'diluxone_users_2fa_mode', 'required' );
		$this->hook( 'diluxone_users_2fa_on_link', '__return_false' );

		$html = $this->screen();

		$this->assertStringContainsString( 'The one on your WordPress account.', $html );
		$this->assertStringContainsString( 'It is asked when you get in with your password', $html );
		$this->assertStringContainsString( 'This site requires it.', $html );
		$this->assertStringNotContainsString( 'value="off"', $html, 'Required cannot be turned off' );
	}

	public function test_the_screen_shows_the_fresh_codes_once(): void {
		diluxone_users_backup_stash( $this->user, array( 'AAAA-1111', 'BBBB-2222' ) );

		$html = $this->screen();

		$this->assertStringContainsString( 'Write these down now', $html );
		$this->assertStringContainsString( '<code>AAAA-1111</code>', $html );
		$this->assertStringContainsString( '<code>BBBB-2222</code>', $html );

		$this->assertStringNotContainsString( 'AAAA-1111', $this->screen(), 'A reload shows them no more' );
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

	public function test_keys_an_addon_kept_draw_nothing_while_it_is_off(): void {
		// What Users+ Pro stores, with Pro off: the switch and one person's keys.
		update_option( 'diluxone_users_passkey_enabled', 1 );
		update_user_meta( $this->user, 'diluxone_users_passkeys', array( array( 'id' => 'kept', 'label' => 'Laptop' ) ) );

		$html = $this->screen( array( 'diluxone-users' => 'passkeyname' ) );

		$this->assertStringNotContainsStringIgnoringCase( 'passkey', $html, 'without the add-on the section says nothing about them' );
		$this->assertStringNotContainsString( 'Laptop', $html );
		$this->assertSame( array( array( 'id' => 'kept', 'label' => 'Laptop' ) ), get_user_meta( $this->user, 'diluxone_users_passkeys', true ), 'and loses nothing' );

		delete_option( 'diluxone_users_passkey_enabled' );
	}
}
