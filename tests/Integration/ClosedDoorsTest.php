<?php
/**
 * A switch that hides a form also closes the address the form posted to.
 *
 * Each of these was a setting that removed a button while the handler behind
 * it kept answering: a request made by hand still mailed sign-in links on a
 * password-only site, still created accounts through a registration form the
 * site had turned off, and still filed privacy requests the site had stopped
 * offering. And one capability was asked about users in general where the
 * question was about one person.
 */

namespace Tests\Integration;

class ClosedDoorsTest extends IntegrationTestCase {

	public function test_a_password_only_site_mails_no_link(): void {
		diluxone_users_update_option( 'diluxone_users_login_method', 'password' );

		$user = get_userdata( $this->make_user() );

		$this->postAs(
			0,
			array(
				'diluxone_users_nonce' => wp_create_nonce( 'diluxone_users_login' ),
				'diluxone_users_email' => $user->user_email,
			)
		);

		$this->expectRedirect( 'diluxone_users_login_request' );

		$this->assertCount( 0, self::$mail );
	}

	public function test_a_registration_form_that_is_off_creates_nobody(): void {
		diluxone_users_update_option( 'diluxone_users_login_register', 1 );
		diluxone_users_update_option( 'diluxone_users_register_form', 0 );

		$email = 'form-off-' . wp_generate_password( 8, false ) . '@example.test';

		$this->postAs(
			0,
			array(
				'diluxone_users_register_nonce' => wp_create_nonce( 'diluxone_users_register' ),
				'diluxone_users_email'          => $email,
			)
		);

		$url = $this->expectRedirect( 'diluxone_users_register_request' );

		$this->assertSame( 'closed', $this->redirectState( $url ) );
		$this->assertFalse( email_exists( $email ) );
	}

	/** @dataProvider privacy_switches */
	public function test_a_privacy_request_that_is_off_is_not_filed( string $kind, string $option ): void {
		diluxone_users_update_option( $option, 0 );

		$user = get_userdata( $this->make_user() );

		wp_set_current_user( $user->ID );
		$this->postAs(
			$user->ID,
			array(
				'_wpnonce'               => wp_create_nonce( 'diluxone_users_data_request' ),
				'diluxone_users_request' => $kind,
			)
		);

		$this->expectRedirect( 'diluxone_users_data_request' );

		$this->assertCount(
			0,
			get_posts(
				array(
					'post_type'   => 'user_request',
					'post_status' => 'any',
					'title'       => $user->user_email,
				)
			)
		);
	}

	/** @return array<string, array{0: string, 1: string}> */
	public function privacy_switches(): array {
		return array(
			'export' => array( 'export', 'diluxone_users_privacy_export' ),
			'erase'  => array( 'erase', 'diluxone_users_privacy_delete' ),
		);
	}

	/**
	 * Closing somebody's sessions is asked about that somebody: an editor of
	 * users is not thereby allowed to act on an administrator.
	 */
	public function test_closing_an_administrators_sessions_needs_rights_over_that_administrator(): void {
		$admin  = $this->make_user( 'administrator' );
		$editor = $this->make_user( 'editor' );
		get_userdata( $editor )->add_cap( 'edit_users' );

		wp_set_current_user( $admin );
		$token = \WP_Session_Tokens::get_instance( $admin )->create( time() + HOUR_IN_SECONDS );

		wp_set_current_user( $editor );
		$this->postAs(
			$editor,
			array(
				'_wpnonce'            => wp_create_nonce( 'diluxone_users_sessions_admin' ),
				'diluxone_users_user' => (string) $admin,
			)
		);

		try {
			diluxone_users_sessions_admin_close();
			$this->fail( 'It should have stopped.' );
		} catch ( \WPAjaxDieContinueException $e ) {
			$this->assertNotNull( \WP_Session_Tokens::get_instance( $admin )->get( $token ) );
		}
	}

	/** Whoever administers the site does close them, on a single site and on a network alike. */
	public function test_an_administrator_closes_another_persons_sessions(): void {
		$admin  = $this->make_user( 'administrator' );
		$member = $this->make_user();

		if ( is_multisite() ) {
			// Acting on another account is the network's to allow; its super
			// admins are who the network lets do it.
			grant_super_admin( $admin );
		}

		wp_set_current_user( $member );
		$token = \WP_Session_Tokens::get_instance( $member )->create( time() + HOUR_IN_SECONDS );

		wp_set_current_user( $admin );
		$this->postAs(
			$admin,
			array(
				'_wpnonce'            => wp_create_nonce( 'diluxone_users_sessions_admin' ),
				'diluxone_users_user' => (string) $member,
			)
		);

		try {
			$url = $this->expectRedirect( 'diluxone_users_sessions_admin_close' );
		} finally {
			if ( is_multisite() ) {
				revoke_super_admin( $admin );
			}
		}

		$this->assertSame( 'closed', $this->queryArg( $url, 'diluxone_users_done' ) );
		$this->assertNull( \WP_Session_Tokens::get_instance( $member )->get( $token ) );
	}

	/** Closing a person's sessions also forgets the browsers that skip their second step. */
	public function test_closing_a_persons_sessions_forgets_their_trusted_browsers(): void {
		$admin  = $this->make_user( 'administrator' );
		$member = $this->make_user();
		update_user_meta( $member, 'diluxone_users_2fa_epoch', 'before' );

		if ( is_multisite() ) {
			grant_super_admin( $admin );
		}

		wp_set_current_user( $admin );
		$this->postAs( $admin, array( '_wpnonce' => wp_create_nonce( 'diluxone_users_sessions_admin' ), 'diluxone_users_user' => (string) $member ) );

		try {
			$this->expectRedirect( 'diluxone_users_sessions_admin_close' );
		} finally {
			if ( is_multisite() ) {
				revoke_super_admin( $admin );
			}
		}

		$this->assertNotSame( 'before', get_user_meta( $member, 'diluxone_users_2fa_epoch', true ) );
	}

	/** The administrator's button refuses a forged nonce, a nobody and an id that is no account. */
	public function test_the_administrators_button_refuses_a_forged_nonce_and_no_one(): void {
		$admin  = $this->make_user( 'administrator' );
		$member = $this->make_user();

		if ( is_multisite() ) {
			grant_super_admin( $admin );
		}

		wp_set_current_user( $member );
		$token = \WP_Session_Tokens::get_instance( $member )->create( time() + HOUR_IN_SECONDS );
		wp_set_current_user( $admin );

		try {
			$this->postAs( $admin, array( '_wpnonce' => 'forged', 'diluxone_users_user' => (string) $member ) );
			$this->expectDie( 'diluxone_users_sessions_admin_close', self::EXPIRED, 403 );

			foreach ( array( '0', '999999999' ) as $nobody ) {
				$this->postAs( $admin, array( '_wpnonce' => wp_create_nonce( 'diluxone_users_sessions_admin' ), 'diluxone_users_user' => $nobody ) );
				$this->expectDie( 'diluxone_users_sessions_admin_close', 'You are not allowed to do this.', 403 );
			}
		} finally {
			if ( is_multisite() ) {
				revoke_super_admin( $admin );
			}
		}

		$this->assertIsArray( \WP_Session_Tokens::get_instance( $member )->get( $token ) );
	}

	/**
	 * On a network a site's administrator cannot sign a super admin out. On a
	 * single site an administrator signs out another administrator.
	 */
	public function test_who_an_administrator_may_sign_out(): void {
		$admin  = $this->make_user( 'administrator' );
		$target = $this->make_user( 'administrator' );

		if ( is_multisite() ) {
			grant_super_admin( $target );
		}

		wp_set_current_user( $target );
		$token = \WP_Session_Tokens::get_instance( $target )->create( time() + HOUR_IN_SECONDS );
		wp_set_current_user( $admin );
		$this->postAs( $admin, array( '_wpnonce' => wp_create_nonce( 'diluxone_users_sessions_admin' ), 'diluxone_users_user' => (string) $target ) );

		try {
			if ( is_multisite() ) {
				$this->expectDie( 'diluxone_users_sessions_admin_close', 'You are not allowed to do this.', 403 );
				$this->assertIsArray( \WP_Session_Tokens::get_instance( $target )->get( $token ), 'the super admin keeps their session' );
			} else {
				$this->expectRedirect( 'diluxone_users_sessions_admin_close' );
				$this->assertNull( \WP_Session_Tokens::get_instance( $target )->get( $token ) );
			}
		} finally {
			if ( is_multisite() ) {
				revoke_super_admin( $target );
			}
		}
	}

	/** Both buttons go back where they were pressed, and never off the site. */
	public function test_the_buttons_never_send_anybody_off_the_site(): void {
		$admin  = $this->make_user( 'administrator' );
		$member = $this->make_user();

		if ( is_multisite() ) {
			grant_super_admin( $admin );
		}

		$_SERVER['HTTP_REFERER'] = 'https://evil.test/x';
		wp_set_current_user( $admin );
		$this->postAs( $admin, array( '_wpnonce' => wp_create_nonce( 'diluxone_users_sessions_admin' ), 'diluxone_users_user' => (string) $member ) );

		try {
			$url = $this->expectRedirect( 'diluxone_users_sessions_admin_close' );
		} finally {
			if ( is_multisite() ) {
				revoke_super_admin( $admin );
			}
		}

		$this->assertSame( wp_parse_url( home_url(), PHP_URL_HOST ), wp_parse_url( $url, PHP_URL_HOST ) );

		wp_set_current_user( $member );
		\WP_Session_Tokens::get_instance( $member )->create( time() + HOUR_IN_SECONDS );
		$this->postAs( $member, array( '_wpnonce' => wp_create_nonce( 'diluxone_users_sessions' ), 'diluxone_users_session' => '' ) );

		$this->assertSame( wp_parse_url( home_url(), PHP_URL_HOST ), wp_parse_url( $this->expectRedirect( 'diluxone_users_sessions_action' ), PHP_URL_HOST ) );
	}

	/**
	 * `diluxone_users_user[]=42` is no person. absint() reads a list as 1, and
	 * the first account — the administrator who installed the site — was the
	 * one signed out, while the person meant kept every session.
	 */
	public function test_an_administrator_closing_a_person_sent_as_a_list_closes_nobody(): void {
		$admin  = $this->make_user( 'administrator' );
		$member = $this->make_user();

		if ( is_multisite() ) {
			grant_super_admin( $admin );
		}

		$first  = \WP_Session_Tokens::get_instance( 1 );
		$theirs = $first->create( time() + HOUR_IN_SECONDS );
		$token  = \WP_Session_Tokens::get_instance( $member )->create( time() + HOUR_IN_SECONDS );

		wp_set_current_user( $admin );
		$this->postAs(
			$admin,
			array(
				'_wpnonce'            => wp_create_nonce( 'diluxone_users_sessions_admin' ),
				'diluxone_users_user' => array( (string) $member ),
			)
		);

		try {
			$this->expectDie( 'diluxone_users_sessions_admin_close', 'You are not allowed to do this.', 403 );

			$this->assertIsArray( $first->get( $theirs ), 'the first account keeps its session' );
			$this->assertIsArray( \WP_Session_Tokens::get_instance( $member )->get( $token ), 'and so does the person' );
		} finally {
			$first->destroy( $theirs );

			if ( is_multisite() ) {
				revoke_super_admin( $admin );
			}
		}
	}

	/**
	 * What went wrong in the public-name form reaches the page it lands on
	 * from the server, not from the address: a link whose query string makes
	 * the site say anything in its own voice is not a link the site offers.
	 */
	public function test_an_error_is_not_carried_in_the_address(): void {
		diluxone_users_update_option( 'diluxone_users_handle_enabled', 1 );
		$user = $this->make_user();
		wp_set_current_user( $user );

		$this->postAs(
			$user,
			array(
				'_wpnonce'              => wp_create_nonce( 'diluxone_users_handle' ),
				'diluxone_users_handle' => 'somebody@example.test',
			)
		);

		$url = $this->expectRedirect( 'diluxone_users_handle_submit' );

		$this->assertSame( '', $this->queryArg( $url, 'diluxone_users_handle' ) );
		$this->assertNotSame( '', diluxone_users_flash_take( $user, 'handle' ) );
		$this->assertSame( '', diluxone_users_flash_take( $user, 'handle' ), 'Read once' );

		$_GET['diluxone_users_handle'] = 'Your account was compromised, call us';
		$this->assertStringNotContainsString( 'compromised', diluxone_users_shortcode_handle() );
	}
}
