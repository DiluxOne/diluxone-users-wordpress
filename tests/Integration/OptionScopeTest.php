<?php
/**
 * The settings go through the scoped helpers, and each lands where it says.
 *
 * On a single site every scope is the site's own table, as it always was. On
 * a network — where the plugin only runs activated for the whole network — a
 * network setting is one value in the network's options, a hub setting lives
 * on the hub and is read from there by every other site, and a site setting
 * stays with its site.
 */

namespace Tests\Integration;

class OptionScopeTest extends IntegrationTestCase {

	private int $site = 0;

	protected function tearDown(): void {
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

	/** A second site on the network, for what must or must not reach it. */
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

	public function test_a_saved_setting_is_read_back_from_where_it_lives(): void {
		diluxone_users_save_options(
			array(
				'diluxone_users_login_expiry' => '42',
				'diluxone_users_login_title'  => '<b>Welcome</b>',
			)
		);

		$this->assertSame( 42, diluxone_users_option( 'diluxone_users_login_expiry' ) );
		$this->assertSame( 'Welcome', diluxone_users_option( 'diluxone_users_login_title' ) );

		// The main site is the hub, so its settings are in its own table.
		$this->assertSame( 42, get_option( 'diluxone_users_login_expiry' ) );
		$this->assertFalse( diluxone_users_option_forced( 'diluxone_users_login_expiry' ) );

		$this->in_network_admin();

		diluxone_users_save_options( array( 'diluxone_users_2fa_methods' => array( 'totp', 'email', 'totp' ) ) );

		$this->assertSame( array( 'totp', 'email' ), diluxone_users_option( 'diluxone_users_2fa_methods' ) );
		$this->assertSame( array( 'totp', 'email' ), is_multisite() ? get_site_option( 'diluxone_users_2fa_methods' ) : get_option( 'diluxone_users_2fa_methods' ) );
	}

	public function test_the_helpers_round_trip(): void {
		$this->assertSame( is_multisite(), diluxone_users_scoped_storage_active(), 'Routed on a network with the plugin on for every site, and only there' );
		$this->assertNull( diluxone_users_raw_get( 'diluxone_users_sso', null ) );

		$this->assertTrue( diluxone_users_update_option( 'diluxone_users_sso', array( 'github' => array( 'enabled' => 1 ) ), false ) );
		$this->assertSame( array( 'github' => array( 'enabled' => 1 ) ), diluxone_users_raw_get( 'diluxone_users_sso' ) );
		$this->assertSame( array( 'github' => array( 'enabled' => 1 ) ), is_multisite() ? get_site_option( 'diluxone_users_sso' ) : get_option( 'diluxone_users_sso' ) );

		$this->assertTrue( diluxone_users_delete_option( 'diluxone_users_sso' ) );
		$this->assertNull( diluxone_users_raw_get( 'diluxone_users_sso', null ) );
	}

	public function test_on_a_network_each_scope_is_stored_where_it_says(): void {
		$other = $this->second_site();

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
		$this->assertNotSame( 'x', get_option( 'diluxone_users_rewrite_version' ), 'The bookkeeping is each site’s' );

		switch_to_blog( $other );
		diluxone_users_delete_option( 'diluxone_users_2fa_mode' );
		diluxone_users_delete_option( 'diluxone_users_login_title' );
		restore_current_blog();

		$this->assertFalse( get_site_option( 'diluxone_users_2fa_mode' ) );
		$this->assertFalse( get_blog_option( diluxone_users_hub_site_id(), 'diluxone_users_login_title' ) );
	}

	/**
	 * Another site reads the hub once per request, and never goes on reading
	 * what was there before somebody wrote it — even somebody going past the
	 * plugin's helpers.
	 */
	public function test_the_hubs_copy_read_from_another_site_follows_every_write(): void {
		$other = $this->second_site();

		update_option( 'diluxone_users_login_title', 'Before' );

		switch_to_blog( $other );
		$this->assertSame( 'Before', diluxone_users_raw_get( 'diluxone_users_login_title' ) );
		restore_current_blog();

		update_option( 'diluxone_users_login_title', 'After' );

		switch_to_blog( $other );
		$this->assertSame( 'After', diluxone_users_raw_get( 'diluxone_users_login_title' ) );
		restore_current_blog();

		delete_option( 'diluxone_users_login_title' );

		switch_to_blog( $other );
		$this->assertNull( diluxone_users_raw_get( 'diluxone_users_login_title', null ) );
		restore_current_blog();
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

	/** A network is always routed by scope: there is no per-site way to fall back to. */
	public function test_a_network_is_always_routed_by_scope(): void {
		if ( ! is_multisite() ) {
			$this->markTestSkipped( 'Needs a multisite network; on a single site SingleSiteTest asserts the opposite.' );
		}

		$this->assertTrue( diluxone_users_scoped_storage_active() );
		$this->assertNotSame( 'single', diluxone_users_admin_context() );
	}
}
