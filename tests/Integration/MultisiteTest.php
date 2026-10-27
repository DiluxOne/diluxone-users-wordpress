<?php
/**
 * What changes when the plugin runs on a network of sites.
 *
 * Accounts belong to the network and roles to each site, so the rules here
 * are about that seam: the network decides whether accounts may be created at
 * all and how safely people get in, the main site keeps the doors, and nobody
 * becomes a member of anything by having their address typed into a form.
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
		diluxone_users_update_option( 'diluxone_users_login_register', 1 );

		$email = 'closed-network-' . wp_generate_password( 8, false ) . '@example.test';
		$this->ask( $email );

		$this->assertFalse( email_exists( $email ), 'A site cannot open what the network closed' );
		$this->assertSame( 'closed', diluxone_users_register_mode() );

		update_site_option( 'registration', 'user' );

		$this->ask( 'open-network-' . wp_generate_password( 8, false ) . '@example.test' );

		$this->assertCount( 1, self::$mail, 'With the network open, the site’s own switch decides' );
	}

	/** @return bool Whether a site has a log table of its own. */
	private function log_table_exists( int $site ): bool {
		global $wpdb;

		return $this->table_exists( $wpdb->get_blog_prefix( $site ) . 'diluxone_users_log' );
	}

	private function table_exists( string $table ): bool {
		global $wpdb;

		return $table === $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) );
	}

	/** How many rows of one site the network's log has. */
	private function rows_of( int $site ): int {
		global $wpdb;

		return (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE site_id = %d', $wpdb->base_prefix . 'diluxone_users_log', $site ) );
	}

	/**
	 * A site born on a network where the plugin is active everywhere has
	 * nothing written for it: it reads the network's and the hub's settings,
	 * which are their defaults until an administrator saves them, and keeps
	 * no copy of its own — a copy nobody reads is a copy that goes stale.
	 */
	public function test_a_site_born_on_the_network_has_nothing_written_for_it(): void {
		$this->assertTrue( is_plugin_active_for_network( plugin_basename( DILUXONE_USERS_FILE ) ), 'The suite runs with the plugin network-activated' );

		$this->assertFalse( $this->log_table_exists( $this->site ), 'No table of its own' );

		// The suite's features-on answers, taken away: what a site reads when
		// nobody saved anything.
		diluxone_users_delete_option( 'diluxone_users_login_register' );

		switch_to_blog( $this->site );

		$this->assertSame( array( 'first_name', 'last_name' ), array_column( diluxone_users_fields(), 'key' ), 'It reads the network’s fields' );
		$this->assertSame( 0, (int) diluxone_users_option( 'diluxone_users_login_register' ), 'No door of the plugin’s is open' );
		$this->assertFalse( get_option( 'diluxone_users_fields', false ) );
		$this->assertFalse( get_option( 'diluxone_users_login_register', false ) );

		restore_current_blog();
	}

	/**
	 * A deleted site takes its rows out of the network's table, and a table
	 * under its own prefix, if one is there; the rest of the network's rows
	 * stay.
	 */
	public function test_a_deleted_site_takes_its_rows_and_its_old_table_with_it(): void {
		global $wpdb;

		$gone = (int) wp_insert_site(
			array(
				'domain' => (string) get_network()->domain,
				'path'   => '/ms-gone-' . strtolower( wp_generate_password( 6, false ) ) . '/',
				'title'  => 'Gone',
			)
		);

		diluxone_users_update_option( 'diluxone_users_log_levels', array( 'access' ) );

		foreach ( array( $gone, $this->site ) as $one ) {
			switch_to_blog( $one );
			diluxone_users_log_record( 'signed_in', 1 );
			restore_current_blog();
		}

		// A table under the site's own prefix: WordPress is asked to drop it
		// with the site's, and the network's table is never on that list.
		$old = $wpdb->get_blog_prefix( $gone ) . 'diluxone_users_log';
		$wpdb->query( "CREATE TABLE IF NOT EXISTS {$old} ( id bigint(20) unsigned NOT NULL AUTO_INCREMENT, PRIMARY KEY  (id) )" );

		$this->assertSame( 1, $this->rows_of( $gone ) );

		wp_delete_site( $gone );

		$this->assertSame( 0, $this->rows_of( $gone ), 'Its rows' );
		$this->assertFalse( $this->log_table_exists( $gone ), 'Its old table' );
		$this->assertSame( 1, $this->rows_of( $this->site ), 'The other site’s rows stay' );
		$this->assertTrue( $this->table_exists( $wpdb->base_prefix . 'diluxone_users_log' ), 'And the network’s table' );
	}

	/** Deactivating for the network leaves no purge event on any site. */
	public function test_network_deactivation_clears_the_event_on_every_site(): void {
		switch_to_blog( $this->site );
		wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', DILUXONE_USERS_LOG_PURGE );
		update_option( 'rewrite_rules', array( 'account/(.+)/?$' => 'index.php' ) );
		update_option( 'diluxone_users_rewrite_version', DILUXONE_USERS_VERSION );
		restore_current_blog();

		diluxone_users_log_unschedule( true );

		switch_to_blog( $this->site );
		$this->assertFalse( wp_next_scheduled( DILUXONE_USERS_LOG_PURGE ) );
		$this->assertEmpty( get_option( 'rewrite_rules' ), 'its stored addresses are gone, to be rebuilt without the plugin' );
		$this->assertFalse( get_option( 'diluxone_users_rewrite_version' ), 'and written back when it is switched on again' );
		restore_current_blog();

		$this->assertFalse( wp_next_scheduled( DILUXONE_USERS_LOG_PURGE ) );
	}

	/**
	 * The session a sign-in opens is valid on every site of the network, so
	 * the second step is one rule for all of them: set once, asked wherever
	 * the person signs in.
	 */
	public function test_the_second_step_is_asked_on_every_site_alike(): void {
		$user = $this->make_user( 'administrator' );
		add_user_to_blog( $this->site, $user, 'administrator' );

		switch_to_blog( $this->site );
		diluxone_users_update_option( 'diluxone_users_2fa_mode', 'required' );
		diluxone_users_update_option( 'diluxone_users_2fa_methods', array( 'email' ) );
		$there = diluxone_users_2fa_required( $user, 'password' );
		restore_current_blog();

		$this->assertTrue( $there );
		$this->assertTrue( diluxone_users_2fa_required( $user, 'password' ), 'Set on one site, it is the network’s' );
	}

	/**
	 * "Only some roles" reaches whoever holds one of them on any of their
	 * sites. Asked only of the site being signed in on, an administrator of
	 * one site would get through by signing in where they are a subscriber —
	 * and the session would open their own site as well.
	 */
	public function test_a_chosen_role_on_any_site_puts_somebody_in_scope(): void {
		$user = $this->make_user( 'subscriber' );
		add_user_to_blog( $this->site, $user, 'administrator' );

		// A rule about roles that is the network's, as an add-on's would be:
		// Users+ Pro's second step for some roles asks the same question.
		$this->hook( 'diluxone_users_option_scope', static fn( string $scope, string $key ): string => 0 === strpos( $key, 'diluxone_users_addon_rule' ) ? 'network' : $scope, 10, 2 );
		diluxone_users_update_option( 'diluxone_users_addon_rule_scope', 'some' );
		diluxone_users_update_option( 'diluxone_users_addon_rule_roles', array( 'administrator' ) );

		$this->assertTrue( diluxone_users_scope_includes( $user, 'diluxone_users_addon_rule' ), 'A subscriber here, an administrator next door' );

		$other = $this->make_user( 'subscriber' );

		$this->assertFalse( diluxone_users_scope_includes( $other, 'diluxone_users_addon_rule' ), 'A subscriber everywhere is not reached' );

		diluxone_users_delete_option( 'diluxone_users_addon_rule_scope' );
		diluxone_users_delete_option( 'diluxone_users_addon_rule_roles' );
	}

	/** A super admin is an administrator everywhere, member or not. */
	public function test_a_super_admin_is_in_scope_where_administrators_are(): void {
		$admin = $this->make_user();
		grant_super_admin( $admin );

		// A rule about roles that is the network's, as an add-on's would be:
		// Users+ Pro's second step for some roles asks the same question.
		$this->hook( 'diluxone_users_option_scope', static fn( string $scope, string $key ): string => 0 === strpos( $key, 'diluxone_users_addon_rule' ) ? 'network' : $scope, 10, 2 );
		diluxone_users_update_option( 'diluxone_users_addon_rule_scope', 'some' );
		diluxone_users_update_option( 'diluxone_users_addon_rule_roles', array( 'administrator' ) );

		$this->assertTrue( diluxone_users_scope_includes( $admin, 'diluxone_users_addon_rule' ) );

		diluxone_users_delete_option( 'diluxone_users_addon_rule_scope' );
		diluxone_users_delete_option( 'diluxone_users_addon_rule_roles' );

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

	/** An account deleted from the network leaves no rows on any site: one query. */
	public function test_deleting_an_account_from_the_network_empties_every_log(): void {
		require_once ABSPATH . 'wp-admin/includes/ms.php';

		diluxone_users_update_option( 'diluxone_users_log_levels', array( 'access' ) );

		$user = $this->make_user();

		foreach ( array( get_main_site_id(), $this->site ) as $one ) {
			switch_to_blog( $one );
			diluxone_users_log_record( 'signed_in', $user );
			restore_current_blog();
		}

		wpmu_delete_user( $user );

		$this->assertSame( 0, $this->user_rows( $user ) );
	}

	/**
	 * Taken off one site, the account lives on: its rows on that site go, and
	 * the rest of the network's stay.
	 */
	public function test_taking_somebody_off_one_site_takes_only_that_sites_rows(): void {
		require_once ABSPATH . 'wp-admin/includes/user.php';

		diluxone_users_update_option( 'diluxone_users_log_levels', array( 'access' ) );

		$user = $this->make_user();
		add_user_to_blog( $this->site, $user, 'subscriber' );

		foreach ( array( get_main_site_id(), $this->site ) as $one ) {
			switch_to_blog( $one );
			diluxone_users_log_record( 'signed_in', $user );
			restore_current_blog();
		}

		switch_to_blog( $this->site );
		wp_delete_user( $user );
		restore_current_blog();

		$this->assertNotFalse( get_userdata( $user ), 'The account is the network’s' );
		$this->assertSame( 1, $this->user_rows( $user ), 'Its row on the main site stays' );
	}

	/** Every row of one person in the network's log. */
	private function user_rows( int $user ): int {
		global $wpdb;

		return (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE user_id = %d', diluxone_users_log_table(), $user ) );
	}

	/** Add New User keeps WordPress's usernames on a network, where an address is not one. */
	public function test_add_new_user_is_left_alone_on_a_network(): void {
		$this->assertFalse( diluxone_users_admin_login_is_email() );
	}

	/** A site's session report shows its own people, not the network's. */
	public function test_the_sessions_report_of_a_site_lists_only_its_members(): void {
		// Under "every site" nobody is an outsider: somebody who is not a
		// member exists under the other two policies.
		diluxone_users_update_option( 'diluxone_users_membership', 'invite' );
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

	/** A picture uploaded on a site deleted since is no picture, and nothing asks that site for it. */
	public function test_a_picture_on_a_deleted_site_is_no_picture(): void {
		$user = $this->make_user();
		$gone = (int) wp_insert_site(
			array(
				'domain' => (string) get_network()->domain,
				'path'   => get_network()->path . 'gone-avatar-' . wp_generate_password( 6, false, false ) . '/',
			)
		);

		update_user_meta( $user, 'diluxone_users_avatar', 5 );
		update_user_meta( $user, 'diluxone_users_avatar_site', $gone );
		wp_delete_site( $gone );

		global $wpdb;
		$errors = 0;
		$count  = static function ( $query ) use ( &$errors, $wpdb, $gone ) {
			if ( false !== strpos( (string) $query, $wpdb->get_blog_prefix( $gone ) . 'posts' ) ) {
				++$errors;
			}

			return $query;
		};
		add_filter( 'query', $count );
		$url = diluxone_users_avatar_url( $user );
		remove_filter( 'query', $count );

		$this->assertSame( 0, diluxone_users_avatar_id( $user ) );
		$this->assertSame( '', $url );
		$this->assertSame( 0, $errors, 'the deleted site’s tables were not asked' );
	}
}
