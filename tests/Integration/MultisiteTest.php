<?php
/**
 * What changes when the plugin runs on a network of sites.
 *
 * Accounts belong to the network and roles to each site, so the rules here
 * are about that seam: the network decides whether accounts may be created at
 * all, a site decides who becomes its member, and nobody becomes a member of
 * anything by having their address typed into a form.
 *
 * The shared CI runs this suite on a network. On a single site every test
 * here is skipped, loudly — a network test that passes on a single site has
 * proved nothing.
 */

namespace Tests\Integration;

class MultisiteTest extends IntegrationTestCase {

	private int $site = 0;

	private string $registration = '';

	protected function setUp(): void {
		parent::setUp();

		if ( ! is_multisite() ) {
			$this->markTestSkipped( 'Needs a multisite network: run `make env-multisite` first.' );
		}

		$this->registration = (string) get_site_option( 'registration', 'none' );

		$this->site = (int) wp_insert_site(
			array(
				'domain' => (string) get_network()->domain,
				'path'   => '/ms-' . strtolower( wp_generate_password( 6, false ) ) . '/',
				'title'  => 'Second site',
			)
		);

		$this->assertGreaterThan( 0, $this->site );
	}

	protected function tearDown(): void {
		if ( is_multisite() ) {
			while ( ms_is_switched() ) {
				restore_current_blog();
			}

			update_site_option( 'registration', $this->registration );

			if ( $this->site > 0 ) {
				wp_delete_site( $this->site );
			}
		}

		parent::tearDown();
	}

	/** Asks for a sign-in link on the current site. */
	private function ask( string $email ): void {
		$this->postAs(
			0,
			array(
				'diluxone_users_nonce' => wp_create_nonce( 'diluxone_users_login' ),
				'diluxone_users_email' => $email,
			)
		);

		$this->expectRedirect( 'diluxone_users_login_request' );
	}

	public function test_the_network_decides_whether_accounts_can_be_created(): void {
		update_site_option( 'registration', 'none' );
		switch_to_blog( $this->site );
		update_option( 'diluxone_users_login_register', 1 );

		$email = 'closed-network-' . wp_generate_password( 8, false ) . '@example.test';
		$this->ask( $email );

		$this->assertFalse( email_exists( $email ), 'A site cannot open what the network closed' );
		$this->assertSame( 'closed', diluxone_users_register_mode() );

		update_site_option( 'registration', 'user' );

		$this->ask( 'open-network-' . wp_generate_password( 8, false ) . '@example.test' );

		$this->assertCount( 1, self::$mail, 'With the network open, the site’s own switch decides' );
	}

	/** @return bool Whether the log table of this site exists. */
	private function log_table_exists( int $site ): bool {
		global $wpdb;

		$table = $wpdb->get_blog_prefix( $site ) . 'diluxone_users_log';

		return $table === $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) );
	}

	/**
	 * A site born on a network where the plugin is active everywhere is set
	 * up when it is born, not the first time somebody opens its dashboard: its
	 * visitors may sign in before that.
	 */
	public function test_a_site_born_on_the_network_is_set_up_at_birth(): void {
		$this->assertTrue( is_plugin_active_for_network( plugin_basename( DILUXONE_USERS_FILE ) ), 'The suite runs with the plugin network-activated' );
		$this->assertTrue( $this->log_table_exists( $this->site ) );

		switch_to_blog( $this->site );

		$this->assertNotFalse( get_option( 'diluxone_users_fields', false ) );
		$this->assertSame( 1, (int) get_option( 'diluxone_users_login_register' ), 'The network was open when it was born' );
	}

	/** Its registration switch starts where the network is, closed or open. */
	public function test_a_site_born_on_a_closed_network_starts_closed(): void {
		update_site_option( 'registration', 'none' );

		$closed = (int) wp_insert_site(
			array(
				'domain' => (string) get_network()->domain,
				'path'   => '/ms-closed-' . strtolower( wp_generate_password( 6, false ) ) . '/',
				'title'  => 'Closed',
			)
		);

		switch_to_blog( $closed );
		$this->assertSame( 0, (int) get_option( 'diluxone_users_login_register' ) );
		restore_current_blog();

		wp_delete_site( $closed );
	}

	public function test_a_deleted_site_takes_its_table_with_it(): void {
		$gone = (int) wp_insert_site(
			array(
				'domain' => (string) get_network()->domain,
				'path'   => '/ms-gone-' . strtolower( wp_generate_password( 6, false ) ) . '/',
				'title'  => 'Gone',
			)
		);

		$this->assertTrue( $this->log_table_exists( $gone ) );

		wp_delete_site( $gone );

		$this->assertFalse( $this->log_table_exists( $gone ) );
	}

	/** Deactivating for the network leaves no purge event on any site. */
	public function test_network_deactivation_clears_the_event_on_every_site(): void {
		switch_to_blog( $this->site );
		wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', DILUXONE_USERS_LOG_PURGE );
		restore_current_blog();

		diluxone_users_log_unschedule( true );

		switch_to_blog( $this->site );
		$this->assertFalse( wp_next_scheduled( DILUXONE_USERS_LOG_PURGE ) );
		restore_current_blog();

		$this->assertFalse( wp_next_scheduled( DILUXONE_USERS_LOG_PURGE ) );
	}

	/**
	 * Anybody can type anybody's address. Asking for a link on a site must
	 * not make the owner of that address a member of it; clicking the link,
	 * which proves the address is theirs, does.
	 */
	public function test_membership_comes_with_the_click_not_with_the_request(): void {
		update_site_option( 'registration', 'user' );
		$user = get_userdata( $this->make_user() );

		switch_to_blog( $this->site );
		update_option( 'diluxone_users_login_register', 1 );

		$this->ask( $user->user_email );

		$this->assertFalse( is_user_member_of_blog( $user->ID, $this->site ), 'Asking proves nothing' );

		$token = diluxone_users_token_create( $user->ID );
		$_GET  = array(
			'diluxone_users_login' => (string) $user->ID,
			'diluxone_users_token' => $token,
		);
		$this->expectRedirect( 'diluxone_users_login_consume' );

		$this->assertTrue( is_user_member_of_blog( $user->ID, $this->site ), 'The click does' );
	}

	public function test_a_site_that_takes_nobody_adds_nobody(): void {
		$user = get_userdata( $this->make_user() );

		switch_to_blog( $this->site );
		update_option( 'diluxone_users_login_register', 0 );
		update_option( 'diluxone_users_sso_register', 0 );
		update_option( 'diluxone_users_register_form', 0 );

		diluxone_users_join_site( $user->ID );

		$this->assertFalse( is_user_member_of_blog( $user->ID, $this->site ) );
	}

	public function test_a_super_admin_is_not_made_a_subscriber(): void {
		$admin = $this->make_user();
		grant_super_admin( $admin );

		switch_to_blog( $this->site );
		update_option( 'diluxone_users_login_register', 1 );

		diluxone_users_join_site( $admin );

		$this->assertFalse( is_user_member_of_blog( $admin, $this->site ) );

		revoke_super_admin( $admin );
	}

	/** A passkey belongs to a person, whichever site they are a member of. */
	public function test_a_passkey_is_found_for_somebody_who_is_not_a_member_here(): void {
		$user = $this->make_user();
		$id   = 'ms-key-' . wp_generate_password( 12, false );

		diluxone_users_passkeys_save(
			$user,
			array(
				array(
					'id'      => $id,
					'public'  => 'x',
					'counter' => 0,
					'name'    => 'Test',
					'created' => time(),
					'used'    => 0,
				),
			)
		);

		switch_to_blog( $this->site );

		$this->assertSame( $user, diluxone_users_passkey_owner( $id ) );
	}

	/**
	 * The session a sign-in opens is valid on every site of the network, so a
	 * site that asks for the second step is asked about wherever the person
	 * signs in: here it asks nothing, the other site asks, and the answer is
	 * yes.
	 */
	public function test_a_site_that_asks_for_the_second_step_is_not_bypassed_from_another(): void {
		$user = $this->make_user( 'administrator' );
		add_user_to_blog( $this->site, $user, 'administrator' );

		update_option( 'diluxone_users_2fa_mode', 'off' );

		switch_to_blog( $this->site );
		update_option( 'diluxone_users_2fa_mode', 'required' );
		update_option( 'diluxone_users_2fa_methods', array( 'email' ) );
		restore_current_blog();

		$this->assertFalse( diluxone_users_2fa_required_here( $user, 'password' ) );
		$this->assertTrue( diluxone_users_2fa_required( $user, 'password' ) );
	}

	/** A super admin is an administrator everywhere, member or not. */
	public function test_a_super_admin_is_in_scope_where_administrators_are(): void {
		$admin = $this->make_user();
		grant_super_admin( $admin );

		update_option( 'diluxone_users_2fa_mode', 'off' );

		switch_to_blog( $this->site );
		update_option( 'diluxone_users_2fa_mode', 'required' );
		update_option( 'diluxone_users_2fa_methods', array( 'email' ) );
		update_option( 'diluxone_users_2fa_scope', 'some' );
		update_option( 'diluxone_users_2fa_roles', array( 'administrator' ) );
		restore_current_blog();

		$this->assertTrue( diluxone_users_2fa_required( $admin, 'password' ) );

		revoke_super_admin( $admin );
	}

	/**
	 * The count per machine is the network's: the accounts and the mail it
	 * limits are the network's, and a count per site would be one more full
	 * allowance for every site of it.
	 */
	public function test_one_machine_has_one_allowance_on_the_whole_network(): void {
		for ( $i = 0; $i < diluxone_users_register_burst(); $i++ ) {
			$this->assertTrue( diluxone_users_register_allowed() );
		}

		switch_to_blog( $this->site );

		$this->assertFalse( diluxone_users_register_allowed() );
	}

	/** An account deleted from the network leaves no rows in any site's log. */
	public function test_deleting_an_account_from_the_network_empties_every_log(): void {
		require_once ABSPATH . 'wp-admin/includes/ms.php';
		global $wpdb;

		$user = $this->make_user();

		switch_to_blog( $this->site );
		diluxone_users_log_record( 'signed_in', $user );
		$table = diluxone_users_log_table();
		restore_current_blog();

		wpmu_delete_user( $user );

		$this->assertSame( 0, (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE user_id = %d", $user ) ) );
	}

	/** Add New User keeps WordPress's usernames on a network, where an address is not one. */
	public function test_add_new_user_is_left_alone_on_a_network(): void {
		$this->assertFalse( diluxone_users_admin_login_is_email() );
	}

	/** A site's session report shows its own people, not the network's. */
	public function test_the_sessions_report_of_a_site_lists_only_its_members(): void {
		$outsider = $this->make_user();
		$member   = $this->make_user();
		add_user_to_blog( $this->site, $member, 'subscriber' );

		foreach ( array( $outsider, $member ) as $user ) {
			\WP_Session_Tokens::get_instance( $user )->create( time() + HOUR_IN_SECONDS );
		}

		switch_to_blog( $this->site );
		$ids = array_map( 'intval', array_column( diluxone_users_sessions_search( '', 1, 200 )['rows'], 'user_id' ) );
		restore_current_blog();

		$this->assertContains( $member, $ids );
		$this->assertNotContains( $outsider, $ids );
	}

	/**
	 * A picture is an attachment of one site and the person is the network's:
	 * it is read, and deleted, on the site that holds it.
	 */
	public function test_a_picture_is_read_and_deleted_on_the_site_that_holds_it(): void {
		$user = $this->make_user();

		$picture = wp_insert_attachment(
			array(
				'post_mime_type' => 'image/png',
				'post_title'     => 'Avatar',
				'post_status'    => 'inherit',
				'post_author'    => $user,
			),
			'avatar-' . $user . '.png'
		);
		update_user_meta( $user, 'diluxone_users_avatar', $picture );
		update_user_meta( $user, 'diluxone_users_avatar_site', get_current_blog_id() );
		$url = diluxone_users_avatar_url( $user );

		$this->assertNotSame( '', $url );

		switch_to_blog( $this->site );
		$this->assertSame( $url, diluxone_users_avatar_url( $user ), 'The same picture from the site next door' );
		diluxone_users_avatar_delete( $user );
		restore_current_blog();

		$this->assertNull( get_post( $picture ), 'Deleted where it lives' );
	}
}
