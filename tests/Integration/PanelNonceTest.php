<?php
/**
 * Every handler that reads a request checks its nonce and its capability
 * first, in the same function, before it reads anything else.
 *
 * A panel's save used to lean on the screen that called it: the screen
 * checked the nonce and the capability, and the save read $_POST trusting
 * that it had. True, and invisible to anybody reading the save — and a save
 * is a function anybody can call. Each one now checks both itself, first.
 *
 * The four handlers that built their nonce's action out of the request — the
 * id of the thing being acted on — read that id before the nonce had said
 * the request was genuine. Their nonces have one fixed action now, checked
 * before anything is read; whose thing it is stays the check after it.
 *
 * Every case runs on both topologies. On a network a setting that is the
 * network's is saved from Network Admin, by whoever administers the network;
 * a site's administrator there is somebody without the capability.
 */

namespace Tests\Integration;

class PanelNonceTest extends IntegrationTestCase {

	protected function tearDown(): void {
		$_GET     = array();
		$_POST    = array();
		$_REQUEST = array();

		parent::tearDown();
	}

	/**
	 * A representative panel of each screen: where it is, what it is sent,
	 * which setting that writes and what it should then say.
	 *
	 * @return array<string, array{0: string, 1: string, 2: array<string, mixed>, 3: string, 4: int}>
	 */
	public static function panels(): array {
		return array(
			'design › your brand (hub)'       => array( 'diluxone-users-design', 'diluxone_users_design_brand_save', array( 'diluxone_users_login_logo' => '42' ), 'diluxone_users_login_logo', 42 ),
			'design › profile photo (hub)'    => array( 'diluxone-users-design', 'diluxone_users_design_photo_save', array( 'diluxone_users_avatar_max_kb' => '512' ), 'diluxone_users_avatar_max_kb', 512 ),
			'security › sessions (network)'   => array( 'diluxone-users-security', 'diluxone_users_sessions_save', array( 'diluxone_users_session_long_days' => '21' ), 'diluxone_users_session_long_days', 21 ),
			'reports › activity (network)'    => array( 'diluxone-users-reports', 'diluxone_users_log_settings_save', array( 'diluxone_users_log_days' => '33' ), 'diluxone_users_log_days', 33 ),
			'social › rules (network)'        => array( 'diluxone-users-social', 'diluxone_users_social_rules_save', array( 'diluxone_users_sso_link_by_email' => '1' ), 'diluxone_users_sso_link_by_email', 1 ),
			'account › your handle (hub)'     => array( 'diluxone-users-account', 'diluxone_users_account_handle_save', array( 'diluxone_users_handle_min' => '5' ), 'diluxone_users_handle_min', 5 ),
			'design › buttons, by its helper' => array( 'diluxone-users-design', 'diluxone_users_design_social_save', array( 'diluxone_users_sso_button_columns' => '1' ), 'diluxone_users_sso_button_columns', 1 ),
		);
	}

	/** Whether the setting is the network's, and so saved from Network Admin. */
	private function networks( string $option ): bool {
		return is_multisite() && 'network' === ( diluxone_users_option_scopes()[ $option ] ?? '' );
	}

	/** Runs a handler and says whether it stopped instead of finishing. */
	private function died( callable $handler ): bool {
		try {
			$handler();
		} catch ( \WPAjaxDieContinueException $e ) {
			return true;
		}

		return false;
	}

	/**
	 * Sends a panel's form as somebody, with a nonce made for them.
	 *
	 * @param array<string, mixed> $post
	 */
	private function sendAs( int $user, string $nonce_action, array $post ): void {
		wp_set_current_user( $user );
		$this->postAs( $user, $post + array( 'diluxone_users_panel_nonce' => wp_create_nonce( $nonce_action ) ) );
	}

	/* ── The panels' saves ─────────────────────────────────────────── */

	/**
	 * @dataProvider panels
	 *
	 * @param array<string, mixed> $post
	 */
	public function test_a_save_without_the_panels_nonce_stops_and_writes_nothing( string $screen, string $save, array $post, string $option, int $value ): void {
		if ( $this->networks( $option ) ) {
			$this->in_network_admin();
		}

		$before = diluxone_users_raw_get( $option );

		wp_set_current_user( 1 );
		$this->postAs( 1, $post );
		$this->assertTrue( $this->died( $save ), 'no nonce at all' );

		$this->postAs( 1, $post + array( 'diluxone_users_panel_nonce' => 'not-a-nonce' ) );
		$this->assertTrue( $this->died( $save ), 'a nonce that is not one' );

		// Another screen's nonce is not this one's.
		$this->sendAs( 1, 'diluxone_users_panel_diluxone-users-status', $post );
		$this->assertTrue( $this->died( $save ), 'another screen’s nonce' );

		$this->assertSame( $before, diluxone_users_raw_get( $option ), 'nothing was written' );
	}

	/**
	 * @dataProvider panels
	 *
	 * @param array<string, mixed> $post
	 */
	public function test_a_save_by_somebody_without_the_capability_stops_and_writes_nothing( string $screen, string $save, array $post, string $option, int $value ): void {
		$network = $this->networks( $option );

		if ( $network ) {
			$this->in_network_admin();
		}

		// On a network's own screen, a site's administrator is somebody
		// without the network's capability; anywhere else, a subscriber.
		$who    = $this->make_user( $network ? 'administrator' : 'subscriber' );
		$before = diluxone_users_raw_get( $option );

		$this->sendAs( $who, 'diluxone_users_panel_' . $screen, $post );

		$this->assertFalse( current_user_can( diluxone_users_admin_cap() ) );
		$this->assertTrue( $this->died( $save ) );
		$this->assertSame( $before, diluxone_users_raw_get( $option ), 'nothing was written' );
	}

	/**
	 * @dataProvider panels
	 *
	 * @param array<string, mixed> $post
	 */
	public function test_a_save_with_the_nonce_and_the_capability_saves( string $screen, string $save, array $post, string $option, int $value ): void {
		if ( $this->networks( $option ) ) {
			$this->in_network_admin();
		}

		$this->postPanel( $screen, $post );

		$this->assertFalse( $this->died( $save ) );
		$this->assertSame( $value, (int) diluxone_users_raw_get( $option ) );
	}

	/** The network's own policy: only from Network Admin, only by its administrator. */
	public function test_the_membership_save_checks_the_network_capability(): void {
		if ( ! is_multisite() ) {
			$this->markTestSkipped( 'Membership is a network’s.' );
		}

		$this->in_network_admin();
		$before = diluxone_users_membership();

		$this->sendAs( $this->make_user( 'administrator' ), 'diluxone_users_panel_' . DILUXONE_USERS_MEMBERSHIP_SCREEN, array( DILUXONE_USERS_MEMBERSHIP => 'invite' ) );
		$this->assertTrue( $this->died( 'diluxone_users_membership_save' ), 'a site’s administrator' );

		$this->postAs( 1, array( DILUXONE_USERS_MEMBERSHIP => 'invite' ) );
		wp_set_current_user( 1 );
		$this->assertTrue( $this->died( 'diluxone_users_membership_save' ), 'no nonce' );

		$this->assertSame( $before, diluxone_users_membership() );
	}

	/** The "Your data" switches have their own form and their own nonce. */
	public function test_the_privacy_switches_check_their_own_nonce(): void {
		diluxone_users_update_option( 'diluxone_users_privacy_export', 1 );

		$this->postAs( 1, array( 'diluxone_users_privacy_nonce' => 'not-a-nonce' ) );
		$this->assertTrue( $this->died( 'diluxone_users_account_privacy_save' ) );
		$this->assertSame( 1, (int) diluxone_users_raw_get( 'diluxone_users_privacy_export' ), 'nothing was written' );

		$who = $this->make_user();
		wp_set_current_user( $who );
		$this->postAs( $who, array( 'diluxone_users_privacy_nonce' => wp_create_nonce( 'diluxone_users_privacy' ) ) );
		$this->assertTrue( $this->died( 'diluxone_users_account_privacy_save' ), 'a subscriber' );
		$this->assertSame( 1, (int) diluxone_users_raw_get( 'diluxone_users_privacy_export' ) );

		wp_set_current_user( 1 );
		$this->postAs( 1, array( 'diluxone_users_privacy_nonce' => wp_create_nonce( 'diluxone_users_privacy' ) ) );
		$this->assertFalse( $this->died( 'diluxone_users_account_privacy_save' ) );
		$this->assertSame( 0, (int) diluxone_users_raw_get( 'diluxone_users_privacy_export' ), 'unticked, it is off' );
	}

	/* ── A nonce with one action, checked before the id is read ────── */

	public function test_closing_an_account_refuses_a_bad_nonce_before_anything(): void {
		$user = $this->make_user();

		foreach ( array( 'not-a-nonce', wp_create_nonce( 'diluxone_users_confirm_close_7' ) ) as $nonce ) {
			$this->postAs(
				$user,
				array(
					'diluxone_users_request_id' => 7,
					'diluxone_users_key'        => 'whatever',
					'_wpnonce'                  => $nonce,
				)
			);

			$this->assertTrue( $this->died( 'diluxone_users_confirm_close' ), 'stopped, not sent anywhere' );
		}

		$this->assertTrue( get_userdata( $user ) instanceof \WP_User, 'and the account is there' );
	}

	public function test_a_download_refuses_a_bad_nonce_before_anything(): void {
		$user = $this->make_user();
		wp_set_current_user( $user );

		foreach ( array( 'not-a-nonce', wp_create_nonce( 'diluxone_users_data_download_7' ) ) as $nonce ) {
			$this->postAs( $user, array(), array( 'request' => '7', '_wpnonce' => $nonce ) );

			try {
				diluxone_users_data_download();
				$this->fail( 'It went on.' );
			} catch ( \WPAjaxDieContinueException $e ) {
				$this->assertStringNotContainsString( 'not yours', $e->getMessage(), 'the nonce stopped it, not the ownership check after it' );
			}
		}
	}

	public function test_a_download_with_the_nonce_still_asks_whose_file_it_is(): void {
		$user = $this->make_user();
		wp_set_current_user( $user );
		$this->postAs( $user, array(), array( 'request' => '7', '_wpnonce' => wp_create_nonce( 'diluxone_users_data_download' ) ) );

		try {
			diluxone_users_data_download();
			$this->fail( 'It went on.' );
		} catch ( \WPAjaxDieContinueException $e ) {
			$this->assertStringContainsString( 'not yours', $e->getMessage() );
		}
	}

	public function test_creating_a_page_refuses_a_bad_nonce_and_creates_nothing(): void {
		diluxone_users_update_option( 'diluxone_users_account_page', 0 );

		foreach ( array( 'not-a-nonce', wp_create_nonce( 'diluxone_users_create_page_diluxone_users_account_page' ) ) as $nonce ) {
			wp_set_current_user( 1 );
			$this->postAs( 1, array(), array( 'page' => 'diluxone_users_account_page', '_wpnonce' => $nonce ) );

			$this->assertTrue( $this->died( 'diluxone_users_create_page' ) );
		}

		$this->assertSame( 0, (int) diluxone_users_raw_get( 'diluxone_users_account_page' ), 'no page chosen' );

		// A subscriber, with a nonce of their own, gets no further.
		$who = $this->make_user();
		wp_set_current_user( $who );
		$this->postAs( $who, array(), array( 'page' => 'diluxone_users_account_page', '_wpnonce' => wp_create_nonce( 'diluxone_users_create_page' ) ) );
		$this->assertTrue( $this->died( 'diluxone_users_create_page' ) );
		$this->assertSame( 0, (int) diluxone_users_raw_get( 'diluxone_users_account_page' ) );
	}

	public function test_creating_a_page_with_the_nonce_creates_it(): void {
		diluxone_users_update_option( 'diluxone_users_account_page', 0 );

		wp_set_current_user( 1 );
		$this->postAs( 1, array(), array( 'page' => 'diluxone_users_account_page', '_wpnonce' => wp_create_nonce( 'diluxone_users_create_page' ) ) );

		$this->expectRedirect( 'diluxone_users_create_page' );

		$page = (int) diluxone_users_raw_get( 'diluxone_users_account_page' );
		$this->assertGreaterThan( 0, $page );
		$this->assertSame( 'page', get_post_type( $page ) );
	}

	/** The trial runs kept in transients right now. */
	private function trials(): int {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->options} WHERE option_name LIKE %s", $wpdb->esc_like( '_transient_' . DILUXONE_USERS_PREVIEW_TRY ) . '%' ) );
	}

	public function test_a_trial_run_refuses_a_bad_nonce_and_keeps_nothing(): void {
		$before = $this->trials();

		foreach ( array( 'not-a-nonce', '' ) as $nonce ) {
			// The panel's own nonce is right; the trial's is not.
			wp_set_current_user( 1 );
			$this->postAs(
				1,
				array(
					'diluxone_users_wp_login_bg' => '#123456',
					'diluxone_users_panel_nonce' => wp_create_nonce( 'diluxone_users_panel_' . DILUXONE_USERS_DESIGN ),
				),
				array(
					'screen'                   => DILUXONE_USERS_DESIGN,
					'panel'                    => 'wp',
					'diluxone_users_try_nonce' => $nonce,
				)
			);

			$this->assertTrue( $this->died( 'diluxone_users_preview_try' ) );
		}

		$this->assertSame( $before, $this->trials(), 'nothing was kept for a frame to pick up' );
	}

	public function test_a_trial_run_with_both_nonces_points_the_frame_at_the_page(): void {
		wp_set_current_user( 1 );

		// The panels are registered on admin_init: a test run on its own, or
		// first, finds none unless it asks.
		do_action( 'diluxone_users_register_panels' );

		$url = diluxone_users_preview_try_url( DILUXONE_USERS_DESIGN, 'wp' );

		$this->postAs(
			1,
			array(
				'diluxone_users_wp_login_bg' => '#123456',
				'diluxone_users_panel_nonce' => wp_create_nonce( 'diluxone_users_panel_' . DILUXONE_USERS_DESIGN ),
			),
			array(
				'screen'                   => DILUXONE_USERS_DESIGN,
				'panel'                    => 'wp',
				'diluxone_users_try_nonce' => $this->queryArg( $url, 'diluxone_users_try_nonce' ),
			)
		);

		$to    = $this->expectRedirect( 'diluxone_users_preview_try' );
		$token = $this->queryArg( $to, 'diluxone-users-try' );
		$kept  = get_transient( DILUXONE_USERS_PREVIEW_TRY . $token );

		$this->assertNotSame( '', $token );
		$this->assertSame( '#123456', $kept['values']['diluxone_users_wp_login_bg'] ?? null );
		$this->assertSame( '', (string) diluxone_users_raw_get( 'diluxone_users_wp_login_bg', '' ), 'and nothing was saved' );
	}

	/* ── Passkeys ──────────────────────────────────────────────────── */

	/**
	 * Runs the passkeys endpoint and returns what it answered.
	 *
	 * @return array<string, mixed>
	 */
	private function passkeys( string $step, string $nonce ): array {
		$this->postAs(
			get_current_user_id(),
			array(
				'step'  => $step,
				'nonce' => $nonce,
			)
		);

		ob_start();
		$this->died( 'diluxone_users_passkeys_ajax' );

		return (array) json_decode( (string) ob_get_clean(), true );
	}

	/** Every step, the sign-in ones included, checks the page's nonce before anything else. */
	public function test_every_passkey_step_checks_the_nonce_first(): void {
		diluxone_users_update_option( 'diluxone_users_passkey_enabled', 1 );

		foreach ( array( 'register-options', 'register', 'login-options', 'login', 'no-such-step' ) as $step ) {
			$answer = $this->passkeys( $step, 'not-a-nonce' );

			$this->assertFalse( $answer['success'] ?? true, $step );
			$this->assertSame( __( 'Session expired. Reload the page.', 'diluxone-users' ), $answer['data']['message'] ?? null, $step );
		}

		// The page hands the same nonce to somebody not signed in, and with it
		// the sign-in step answers with a challenge.
		$answer = $this->passkeys( 'login-options', wp_create_nonce( 'diluxone_users_passkeys' ) );

		$this->assertTrue( $answer['success'] ?? false );
		$this->assertNotSame( '', (string) ( $answer['data']['challenge'] ?? '' ) );

		self::forget_transients();
	}

	/* ── The reset link ────────────────────────────────────────────── */

	public function test_a_reset_key_nobody_issued_keeps_no_cookie(): void {
		$user = get_userdata( $this->make_user() );

		$_GET = array(
			'diluxone_users_key'   => 'made-up-key',
			'diluxone_users_login' => $user->user_login,
		);

		$to = $this->expectRedirect( 'diluxone_users_reset_catch' );

		$this->assertSame( 'expired', $this->redirectState( $to ) );
		$this->assertArrayNotHasKey( diluxone_users_reset_cookie(), self::$cookies );
		$this->assertSame( '', $this->queryArg( $to, 'diluxone_users_key' ), 'and the key is out of the address' );
	}

	public function test_a_real_reset_key_is_kept_in_the_cookie(): void {
		$user = get_userdata( $this->make_user() );
		$key  = get_password_reset_key( $user );

		$this->assertIsString( $key );

		$_GET = array(
			'diluxone_users_key'   => $key,
			'diluxone_users_login' => $user->user_login,
		);

		$to = $this->expectRedirect( 'diluxone_users_reset_catch' );

		$this->assertSame( 'reset', $this->redirectState( $to ) );
		$this->assertSame( $user->user_login . ':' . $key, self::$cookies[ diluxone_users_reset_cookie() ]['value'] ?? null );
	}

	/* ── WordPress's own forms, checked here again ─────────────────── */

	public function test_the_profile_saves_check_the_profile_nonce(): void {
		$user = $this->make_user();

		wp_set_current_user( 1 );
		$this->postAs( 1, array( '_wpnonce' => 'not-a-nonce' ) );

		$this->assertTrue( $this->died( static fn() => diluxone_users_profile_save( $user ) ) );
		$this->assertTrue( $this->died( static fn() => diluxone_users_profile_block_save( $user ) ) );

		// With the profile's own nonce, both go through.
		$this->postAs( 1, array( '_wpnonce' => wp_create_nonce( 'update-user_' . $user ) ) );

		$this->assertFalse( $this->died( static fn() => diluxone_users_profile_save( $user ) ) );
		$this->assertFalse( $this->died( static fn() => diluxone_users_profile_block_save( $user ) ) );
	}

	public function test_add_new_user_only_rewrites_a_real_create_user_post(): void {
		if ( is_multisite() ) {
			$this->markTestSkipped( 'On a network the username stays the one typed.' );
		}

		$post = array(
			'action' => 'createuser',
			'email'  => 'somebody@example.test',
		);

		// Somebody who cannot create accounts, with a nonce of their own.
		$who = $this->make_user();
		wp_set_current_user( $who );
		$this->postAs( $who, $post + array( '_wpnonce_create-user' => wp_create_nonce( 'create-user' ) ) );
		diluxone_users_admin_new_user_login();
		$this->assertArrayNotHasKey( 'user_login', $_POST );

		// The form's nonce wrong: stopped.
		wp_set_current_user( 1 );
		$this->postAs( 1, $post + array( '_wpnonce_create-user' => 'not-a-nonce' ) );
		$this->assertTrue( $this->died( 'diluxone_users_admin_new_user_login' ) );
		$this->assertArrayNotHasKey( 'user_login', $_POST );

		// Right: the username is the address.
		$this->postAs( 1, $post + array( '_wpnonce_create-user' => wp_create_nonce( 'create-user' ) ) );
		diluxone_users_admin_new_user_login();
		$this->assertSame( 'somebody@example.test', wp_unslash( $_POST['user_login'] ?? '' ) );
	}
}
