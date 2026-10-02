<?php
/**
 * The Security screen's two tabs: the second step and passkeys.
 *
 * Both are the network's on a network, saved and drawn from Network Admin;
 * on a single site they are the site's. Every case runs on both topologies.
 */

namespace Tests\Integration;

use Tests\Integration\Support\CoverSettingsSupport;

class CoverSettingsSecurityTest extends IntegrationTestCase {

	use CoverSettingsSupport;

	protected function setUp(): void {
		parent::setUp();

		do_action( 'diluxone_users_register_panels' );
	}

	protected function tearDown(): void {
		$this->cover_settings_reset();

		remove_filter( 'diluxone_users_2fa_on_wp_login', '__return_false' );

		parent::tearDown();
	}

	/* ── Both saves refuse a bad request ───────────────────────────── */

	/** @return array<string, array{0: string, 1: array<string, mixed>, 2: string}> */
	public static function saves(): array {
		return array(
			'the second step' => array(
				'diluxone_users_2fa_save',
				array(
					'diluxone_users_2fa_mode'    => 'required',
					'diluxone_users_2fa_methods' => array( 'email' ),
				),
				'diluxone_users_2fa_mode',
			),
			'passkeys'        => array( 'diluxone_users_passkeys_settings_save', array( 'diluxone_users_passkey_where' => 'device' ), 'diluxone_users_passkey_where' ),
		);
	}

	/**
	 * On a network the one refused is a site's administrator in Network
	 * Admin; on a single site, a subscriber.
	 *
	 * @dataProvider saves
	 *
	 * @param array<string, mixed> $post
	 */
	public function test_each_save_stops_on_a_bad_nonce_or_a_missing_capability( string $save, array $post, string $option ): void {
		$this->where_the_network_is_set();
		$before = diluxone_users_option( $option );

		$this->the_admin();
		$this->postAs( get_current_user_id(), $post + array( 'diluxone_users_panel_nonce' => 'not-a-nonce' ) );
		$this->assertSame( array( 'died', self::EXPIRED ), $this->ended( $save ), 'a bad nonce' );

		$this->send_panel( 'diluxone-users-login', $post );
		$this->assertSame( array( 'died', self::EXPIRED ), $this->ended( $save ), 'another screen’s nonce' );

		$this->not_allowed( true );
		$this->send_panel( DILUXONE_USERS_SECURITY, $post );
		$this->assertSame( array( 'died', 'You are not allowed to do this.' ), $this->ended( $save ) );

		$this->assertSame( $before, diluxone_users_option( $option ) );
	}

	/**
	 * Every answer of the second step's form is one the form offers. A mode
	 * of `bogus` was kept and read back as optional while the screen showed
	 * no mode chosen; a method nothing registered was kept as one the site
	 * had; a mode sent as a list was no mode at all.
	 */
	public function test_the_second_step_keeps_only_answers_it_offers(): void {
		$this->where_the_network_is_set();
		$this->the_admin();

		$this->send_panel(
			DILUXONE_USERS_SECURITY,
			array(
				'diluxone_users_2fa_mode'    => 'bogus',
				'diluxone_users_2fa_link'    => 'sometimes',
				'diluxone_users_2fa_methods' => array( 'email', 'sms_unknown', array( 'totp' ) ),
			)
		);
		$this->assertNotFalse( $this->ended( 'diluxone_users_2fa_save' ) );

		$this->assertSame( 'optional', diluxone_users_raw_get( 'diluxone_users_2fa_mode' ) );
		$this->assertSame( 'auto', diluxone_users_raw_get( 'diluxone_users_2fa_link' ) );
		$this->assertSame( array( 'email' ), diluxone_users_raw_get( 'diluxone_users_2fa_methods' ) );

		$this->send_panel(
			DILUXONE_USERS_SECURITY,
			array(
				'diluxone_users_2fa_mode'    => array( 'required' ),
				'diluxone_users_2fa_methods' => array( 'email' ),
			)
		);
		$this->ended( 'diluxone_users_2fa_save' );

		$this->assertSame( 'optional', diluxone_users_raw_get( 'diluxone_users_2fa_mode' ), 'a list is no mode' );
	}

	public function test_a_method_turned_off_can_be_turned_back_on(): void {
		$this->where_the_network_is_set();
		$this->the_admin();

		diluxone_users_update_option( 'diluxone_users_2fa_methods', array( 'email' ) );

		$this->send_panel(
			DILUXONE_USERS_SECURITY,
			array(
				'diluxone_users_2fa_mode'    => 'optional',
				'diluxone_users_2fa_methods' => array( 'email', 'totp' ),
			)
		);
		$this->assertNotFalse( $this->ended( 'diluxone_users_2fa_save' ) );

		$this->assertSame( array( 'email', 'totp' ), diluxone_users_raw_get( 'diluxone_users_2fa_methods' ), 'the app, off until now, is on' );
	}

	public function test_passkeys_are_saved_and_switched_on_only_where_there_is_no_access_screen(): void {
		$this->where_the_network_is_set();
		$this->the_admin();

		$this->send_panel(
			DILUXONE_USERS_SECURITY,
			array(
				'diluxone_users_passkey_where'   => 'Device',
				'diluxone_users_passkey_verify'  => '1',
				'diluxone_users_passkey_enabled' => '1',
			)
		);
		diluxone_users_passkeys_settings_save();

		$this->assertSame( 'device', diluxone_users_option( 'diluxone_users_passkey_where' ) );
		$this->assertSame( 1, (int) diluxone_users_option( 'diluxone_users_passkey_verify' ) );

		// On a network this form is where they are offered; on a site that is
		// Access › Ways in, and this form leaves it alone.
		$this->assertSame( is_multisite() ? 1 : 0, (int) diluxone_users_option( 'diluxone_users_passkey_enabled' ) );
	}

	public function test_from_the_hubs_own_dashboard_neither_save_writes_anything(): void {
		$this->network_only( 'CoverSettingsSecurityTest::test_off_needs_no_method_and_is_saved' );

		$this->the_admin();

		$this->send_panel(
			DILUXONE_USERS_SECURITY,
			array(
				'diluxone_users_2fa_mode'      => 'off',
				'diluxone_users_passkey_where' => 'device',
			)
		);
		diluxone_users_2fa_save();
		diluxone_users_passkeys_settings_save();

		$this->assertSame( 'optional', diluxone_users_option( 'diluxone_users_2fa_mode' ) );
		$this->assertSame( 'any', diluxone_users_option( 'diluxone_users_passkey_where' ) );
	}

	/* ── The second step, saved ────────────────────────────────────── */

	public function test_a_second_step_with_no_way_to_send_the_code_is_refused(): void {
		$this->where_the_network_is_set();
		$this->the_admin();

		foreach ( array( 'optional', 'required' ) as $mode ) {
			$this->send_panel(
				DILUXONE_USERS_SECURITY,
				array(
					'diluxone_users_2fa_mode'    => $mode,
					'diluxone_users_2fa_methods' => array(),
				)
			);

			ob_start();
			$saved = diluxone_users_2fa_save();
			$said  = (string) ob_get_clean();

			$this->assertFalse( $saved, $mode );
			$this->assertStringContainsString( esc_html( diluxone_users_2fa_needs_a_method() ), $said, $mode );
		}

		$this->assertSame( 'optional', diluxone_users_option( 'diluxone_users_2fa_mode' ), 'nothing written' );
		$this->assertSame( array( 'totp', 'email' ), diluxone_users_option( 'diluxone_users_2fa_methods' ) );
	}

	public function test_off_needs_no_method_and_is_saved(): void {
		$this->where_the_network_is_set();
		$this->the_admin();

		$this->send_panel(
			DILUXONE_USERS_SECURITY,
			array(
				'diluxone_users_2fa_mode'          => 'off',
				'diluxone_users_2fa_link'          => 'never',
				'diluxone_users_2fa_remember_days' => '-7',
			)
		);

		$this->assertTrue( diluxone_users_2fa_save() );
		$this->assertSame( 'off', diluxone_users_option( 'diluxone_users_2fa_mode' ) );
		$this->assertSame( array(), diluxone_users_option( 'diluxone_users_2fa_methods' ) );
		$this->assertSame( 'never', diluxone_users_option( 'diluxone_users_2fa_link' ) );
		$this->assertSame( 7, (int) diluxone_users_option( 'diluxone_users_2fa_remember_days' ), 'a number of days, never a negative one' );
	}

	/* ── The second step, drawn ────────────────────────────────────── */

	public function test_the_tab_says_off_makes_every_question_moot(): void {
		$this->where_the_network_is_set();
		$this->the_admin();
		diluxone_users_update_option( 'diluxone_users_2fa_mode', 'off' );

		$html = $this->draw( 'diluxone_users_screen_login_2fa' );

		$this->assertStringContainsString( 'The second step is off, so nothing below is asked of anybody.', $html );
		$this->assertStringContainsString( 'Nothing is asked: the second step is off for the whole site', $html );
	}

	public function test_a_link_always_or_never_asked_is_said_as_such(): void {
		$this->where_the_network_is_set();
		$this->the_admin();

		diluxone_users_update_option( 'diluxone_users_2fa_link', 'always' );
		$this->assertSame( 'active', diluxone_users_2fa_link_today()['state'] );
		$this->assertStringContainsString( 'The second step is asked of somebody who came in by link, whichever one they have.', $this->draw( 'diluxone_users_screen_login_2fa' ) );

		diluxone_users_update_option( 'diluxone_users_2fa_link', 'never' );
		$this->assertSame( 'off', diluxone_users_2fa_link_today()['state'] );
		$this->assertStringContainsString( 'Nothing is asked of somebody who came in by link: following it is the whole of it.', $this->draw( 'diluxone_users_screen_login_2fa' ) );
	}

	public function test_with_nowhere_to_answer_it_required_cannot_be_picked(): void {
		$this->where_the_network_is_set();
		$this->the_admin();
		add_filter( 'diluxone_users_2fa_on_wp_login', '__return_false' );

		$html = $this->draw( 'diluxone_users_screen_login_2fa' );

		$this->assertStringContainsString( esc_html( diluxone_users_2fa_needs_a_surface() ), $html );
		$this->assertStringContainsString( 'The sign-in page →', $html );
		$this->assertStringContainsString( 'needs a sign-in page', $html );
		$this->assertMatchesRegularExpression( '/value="required"[^>]*disabled/', $html );
	}

	public function test_without_the_email_link_the_link_question_waits(): void {
		$this->where_the_network_is_set();
		$this->the_admin();
		diluxone_users_update_option( 'diluxone_users_login_method', 'password' );

		$this->assertStringContainsString( 'The e-mail link is not one of the ways in on this site, so nobody follows one.', $this->draw( 'diluxone_users_screen_login_2fa' ) );
	}

	/* ── Passkeys ──────────────────────────────────────────────────── */

	public function test_passkeys_offered_say_which_domain_they_are_tied_to(): void {
		$this->where_the_network_is_set();
		$this->the_admin();
		diluxone_users_update_option( 'diluxone_users_passkey_enabled', 1 );

		$html = $this->draw( 'diluxone_users_screen_login_passkeys' );

		$this->assertStringContainsString( 'People can add passkeys and sign in with them. They are tied to <code>' . esc_html( diluxone_users_passkey_rp_id() ) . '</code>', $html );
		$this->assertStringContainsString( is_multisite() ? 'Offered on every site' : 'Offered on this site', $html );

		diluxone_users_update_option( 'diluxone_users_passkey_enabled', 0 );

		$this->assertStringContainsString( 'Nobody can add one or sign in with one, whatever is chosen beside this.', $this->draw( 'diluxone_users_screen_login_passkeys' ) );
	}

	public function test_the_summary_says_which_passkeys_are_accepted(): void {
		diluxone_users_update_option( 'diluxone_users_passkey_where', 'device' );

		$rows = array_column( diluxone_users_passkeys_summary_rows( array() ), 'detail', 'label' );

		$this->assertSame( 'Only the device in front of them: no phone, no hardware key.', $rows['Which passkeys are accepted'] ?? null );

		diluxone_users_update_option( 'diluxone_users_passkey_where', 'any' );
		$rows = array_column( diluxone_users_passkeys_summary_rows( array() ), 'detail', 'label' );

		$this->assertSame( 'Any: the device being used, a USB key, or another phone.', $rows['Which passkeys are accepted'] ?? null );
	}
}
