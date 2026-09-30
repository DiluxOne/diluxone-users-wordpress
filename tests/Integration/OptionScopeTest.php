<?php
/**
 * The settings go through the scoped helpers and nothing moves yet.
 *
 * Every read and write of a setting now passes through the helpers that know
 * its scope. Until scoped storage is turned on they must behave exactly as the
 * calls they replaced: a value saved from a screen is read back, it is in this
 * site's options table, and on a network the next site does not see it. The
 * last test turns the routing on for itself, so the second half of the change
 * is a switch already known to work.
 */

namespace Tests\Integration;

class OptionScopeTest extends IntegrationTestCase {

	private int $site = 0;

	protected function tearDown(): void {
		remove_all_filters( 'diluxone_users_scoped_storage' );
		remove_all_filters( 'diluxone_users_option_scope' );

		if ( is_multisite() ) {
			while ( ms_is_switched() ) {
				restore_current_blog();
			}

			foreach ( array( 'diluxone_users_2fa_mode', 'diluxone_users_login_title', 'diluxone_users_rewrite_version' ) as $key ) {
				delete_site_option( $key );
			}

			if ( $this->site > 0 ) {
				wp_delete_site( $this->site );
			}
		}

		parent::tearDown();
	}

	/** A second site on the network, for what must not leak into it. */
	private function second_site(): int {
		if ( ! is_multisite() ) {
			$this->markTestSkipped( 'Needs a multisite network: run `make env-multisite` first.' );
		}

		$this->site = (int) wp_insert_site(
			array(
				'domain' => (string) get_network()->domain,
				'path'   => '/scope-' . strtolower( wp_generate_password( 6, false ) ) . '/',
				'title'  => 'Second site',
			)
		);

		$this->assertGreaterThan( 0, $this->site );

		return $this->site;
	}

	public function test_a_saved_setting_is_read_back_from_this_sites_options(): void {
		diluxone_users_save_options(
			array(
				'diluxone_users_login_expiry' => '42',
				'diluxone_users_2fa_methods'  => array( 'totp', 'email', 'totp' ),
				'diluxone_users_login_title'  => '<b>Welcome</b>',
			)
		);

		$this->assertSame( 42, diluxone_users_option( 'diluxone_users_login_expiry' ) );
		$this->assertSame( array( 'totp', 'email' ), diluxone_users_option( 'diluxone_users_2fa_methods' ) );
		$this->assertSame( 'Welcome', diluxone_users_option( 'diluxone_users_login_title' ) );

		$this->assertSame( 42, get_option( 'diluxone_users_login_expiry' ) );
		$this->assertFalse( diluxone_users_option_forced( 'diluxone_users_login_expiry' ) );
	}

	public function test_the_helpers_round_trip_on_this_site(): void {
		$this->assertFalse( diluxone_users_scoped_storage_active() );
		$this->assertNull( diluxone_users_raw_get( 'diluxone_users_sso', null ) );

		$this->assertTrue( diluxone_users_update_option( 'diluxone_users_sso', array( 'github' => array( 'enabled' => 1 ) ), false ) );
		$this->assertSame( array( 'github' => array( 'enabled' => 1 ) ), diluxone_users_raw_get( 'diluxone_users_sso' ) );
		$this->assertSame( array( 'github' => array( 'enabled' => 1 ) ), get_option( 'diluxone_users_sso' ) );

		$this->assertTrue( diluxone_users_delete_option( 'diluxone_users_sso' ) );
		$this->assertFalse( get_option( 'diluxone_users_sso' ) );
	}

	public function test_on_a_network_a_setting_is_still_the_sites_own(): void {
		$other = $this->second_site();

		diluxone_users_update_option( 'diluxone_users_2fa_mode', 'all' );

		switch_to_blog( $other );
		$there = diluxone_users_raw_get( 'diluxone_users_2fa_mode', null );
		restore_current_blog();

		$this->assertNull( $there );
		$this->assertFalse( get_site_option( 'diluxone_users_2fa_mode' ) );
	}

	public function test_the_scope_filter_is_heard_and_nonsense_is_the_site(): void {
		add_filter(
			'diluxone_users_option_scope',
			static fn( string $scope, string $key ): string => 'diluxone_users_addon_thing' === $key ? 'network' : ( 'diluxone_users_login_title' === $key ? 'everywhere' : $scope ),
			10,
			2
		);

		$this->assertSame( 'network', diluxone_users_option_scope( 'diluxone_users_addon_thing' ) );
		$this->assertSame( 'site', diluxone_users_option_scope( 'diluxone_users_login_title' ) );
	}

	public function test_turned_on_each_scope_is_stored_where_it_says(): void {
		$other = $this->second_site();

		add_filter( 'diluxone_users_scoped_storage', '__return_true' );
		$this->assertTrue( diluxone_users_scoped_storage_active() );

		switch_to_blog( $other );
		diluxone_users_update_option( 'diluxone_users_2fa_mode', 'all' );
		diluxone_users_update_option( 'diluxone_users_login_title', 'Hub title' );
		diluxone_users_update_option( 'diluxone_users_rewrite_version', 'x' );

		$this->assertSame( 'all', diluxone_users_raw_get( 'diluxone_users_2fa_mode' ) );
		$this->assertSame( 'Hub title', diluxone_users_raw_get( 'diluxone_users_login_title' ) );
		$this->assertFalse( get_option( 'diluxone_users_2fa_mode' ) );
		$this->assertFalse( get_option( 'diluxone_users_login_title' ) );
		$this->assertSame( 'x', get_option( 'diluxone_users_rewrite_version' ) );
		restore_current_blog();

		$this->assertSame( 'all', get_site_option( 'diluxone_users_2fa_mode' ) );
		$this->assertSame( 'Hub title', get_blog_option( diluxone_users_hub_site_id(), 'diluxone_users_login_title' ) );

		switch_to_blog( $other );
		diluxone_users_delete_option( 'diluxone_users_2fa_mode' );
		diluxone_users_delete_option( 'diluxone_users_login_title' );
		restore_current_blog();

		$this->assertFalse( get_site_option( 'diluxone_users_2fa_mode' ) );
		$this->assertFalse( get_blog_option( diluxone_users_hub_site_id(), 'diluxone_users_login_title' ) );
	}
}
