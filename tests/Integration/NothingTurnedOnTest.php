<?php
/**
 * A site that turned nothing on is the site WordPress would have been.
 *
 * Every case starts with no setting of the plugin's saved, which is what a
 * fresh activation leaves, and walks one of the places the plugin hooks into
 * WordPress: the sign-in, the registration form, the profile screens and the
 * Users list, a password reset, the e-mails, the sessions, the log, the
 * rewrite rules and, on a network, the other sites' doors. In each the answer
 * is WordPress's own. `make test-noop` asks the same of a whole site, from the
 * outside.
 */

namespace Tests\Integration;

class NothingTurnedOnTest extends IntegrationTestCase {

	protected bool $fresh = true;

	/** What an action prints. */
	private function printed( callable $run ): string {
		ob_start();
		$run();

		return (string) ob_get_clean();
	}

	public function test_signing_in_with_a_password_writes_nothing_and_sends_nothing(): void {
		$user = $this->make_user();
		$sent = count( self::$mail );

		$person = get_userdata( $user );
		do_action( 'wp_login', $person->user_login, $person );

		$this->assertSame( '', get_user_meta( $user, 'diluxone_users_devices', true ), 'no device noted' );
		$this->assertSame( $sent, count( self::$mail ), 'no notice sent' );
		$this->assertSame( array(), diluxone_users_log_levels() );
		$this->assertFalse( diluxone_users_log_records( 'signed_in' ), 'nothing recorded' );
	}

	public function test_the_session_record_is_wordpresss_own(): void {
		$this->assertSame( array( 'ip' => '1.2.3.4' ), apply_filters( 'attach_session_information', array( 'ip' => '1.2.3.4' ), 1 ) );
	}

	public function test_the_sites_mail_is_none_of_the_plugins_business(): void {
		do_action( 'wp_mail_succeeded', array() );
		do_action( 'wp_mail_failed', new \WP_Error( 'x', 'y' ) );

		$this->assertFalse( diluxone_users_raw_get( 'diluxone_users_mail_last', false ) );
	}

	public function test_a_reset_leaves_the_other_sessions_open_as_wordpress_does(): void {
		$user    = $this->make_user();
		$manager = \WP_Session_Tokens::get_instance( $user );
		$manager->create( time() + HOUR_IN_SECONDS );

		do_action( 'after_password_reset', get_userdata( $user ), 'new-pass' );

		$this->assertCount( 1, $manager->get_all() );
	}

	/** With the sessions list on, the records WordPress already signed out go (#188). */
	public function test_with_the_sessions_list_on_a_reset_forgets_the_other_sessions(): void {
		diluxone_users_update_option( 'diluxone_users_sessions_show', 1 );

		$user    = $this->make_user();
		$manager = \WP_Session_Tokens::get_instance( $user );
		$manager->create( time() + HOUR_IN_SECONDS );

		do_action( 'after_password_reset', get_userdata( $user ), 'new-pass' );

		$this->assertCount( 0, $manager->get_all() );
	}

	public function test_wordpresss_registration_form_is_wordpresss(): void {
		$this->assertSame( '', $this->printed( static fn() => do_action( 'register_form' ) ) );

		$errors = apply_filters( 'registration_errors', new \WP_Error(), 'somebody', 'somebody@example.test' );
		$this->assertFalse( $errors->has_errors() );

		diluxone_users_update_option( 'diluxone_users_wp_register_fields', 1 );
		$this->assertStringContainsString( 'diluxone_users_wp_register_nonce', $this->printed( static fn() => do_action( 'register_form' ) ), 'once an administrator asks for it' );
	}

	public function test_the_profile_screens_and_the_users_list_are_wordpresss(): void {
		$user = get_userdata( $this->make_user() );

		$this->assertSame( '', $this->printed( static fn() => do_action( 'show_user_profile', $user ) ), 'no section of first and last name over WordPress’s own' );

		wp_set_current_user( 1 );
		$this->assertSame( '', $this->printed( static fn() => do_action( 'edit_user_profile', $user ) ), 'no Access block' );
		$this->assertSame( array( 'username' => 'Username' ), apply_filters( 'manage_users_columns', array( 'username' => 'Username' ) ), 'no Access column' );

		$this->assertFalse( diluxone_users_admin_login_is_email(), 'Add New User keeps its own username field' );

		diluxone_users_update_option( 'diluxone_users_2fa_mode', 'optional' );
		$this->assertArrayHasKey( 'diluxone_users', apply_filters( 'manage_users_columns', array() ), 'once a way in it describes is on' );
	}

	public function test_the_rewrite_rules_are_wordpresss(): void {
		$this->assertSame( '', diluxone_users_rewrite_stamp() );

		global $wp_rewrite;
		$wp_rewrite->extra_rules_top = array();
		diluxone_users_sso_rule();
		diluxone_users_account_rule();
		$this->assertSame( array(), $wp_rewrite->extra_rules_top, 'no /sso/ and no account route' );

		diluxone_users_sso_save_credentials( 'google', array( 'id' => 'client', 'secret' => 'secret' ) );
		$this->assertStringContainsString( 'sso', diluxone_users_rewrite_stamp(), 'a provider set up needs its route' );
	}

	public function test_the_log_has_no_table_and_no_purge_until_something_is_recorded(): void {
		$schema = diluxone_users_raw_get( DILUXONE_USERS_LOG_SCHEMA_OPTION, null );
		diluxone_users_delete_option( DILUXONE_USERS_LOG_SCHEMA_OPTION );
		wp_clear_scheduled_hook( DILUXONE_USERS_LOG_PURGE );

		try {
			diluxone_users_log_ready();

			$this->assertNull( diluxone_users_raw_get( DILUXONE_USERS_LOG_SCHEMA_OPTION, null ) );
			$this->assertFalse( wp_next_scheduled( DILUXONE_USERS_LOG_PURGE ) );
			$this->assertFalse( diluxone_users_log_record( 'signed_in', 1 ) );

			diluxone_users_update_option( 'diluxone_users_log_levels', array( 'access' ) );
			diluxone_users_log_ready();

			$this->assertSame( DILUXONE_USERS_LOG_SCHEMA, (int) diluxone_users_raw_get( DILUXONE_USERS_LOG_SCHEMA_OPTION ), 'the table comes with the first group ticked' );
			$this->assertSame( diluxone_users_log_purges_here(), (bool) wp_next_scheduled( DILUXONE_USERS_LOG_PURGE ) );
		} finally {
			wp_clear_scheduled_hook( DILUXONE_USERS_LOG_PURGE );

			if ( null !== $schema ) {
				diluxone_users_update_option( DILUXONE_USERS_LOG_SCHEMA_OPTION, $schema );
			}
		}
	}

	public function test_on_a_network_every_site_keeps_its_own_wp_login(): void {
		if ( ! is_multisite() ) {
			$this->assertFalse( diluxone_users_hub_on(), 'a single site has no hub to send anybody to' );

			return;
		}

		$site = (int) wp_insert_site(
			array(
				'domain' => (string) get_network()->domain,
				'path'   => '/noop-' . strtolower( wp_generate_password( 6, false ) ) . '/',
				'title'  => 'Nothing turned on',
			)
		);

		switch_to_blog( $site );

		try {
			$this->assertFalse( diluxone_users_sends_to_hub() );
			$this->assertSame( wp_login_url(), site_url( 'wp-login.php', 'login' ) );
			$this->assertSame( array( 'example.test' ), diluxone_users_allowed_hosts( array( 'example.test' ) ), 'no other host made safe' );

			diluxone_users_update_option( 'diluxone_users_network_hub', 1 );
			$this->assertTrue( diluxone_users_sends_to_hub(), 'once the network asks for it' );
		} finally {
			restore_current_blog();
		}
	}
}
