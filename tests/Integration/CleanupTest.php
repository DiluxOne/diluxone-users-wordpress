<?php
/**
 * Every test leaves the database as it found it.
 *
 * The tests database is not recreated between runs, so an account or a site
 * a test forgets stays there for good: they piled up by the thousand, and
 * the network's bulk operations and the suite slowed down with them. The
 * first test here leaks on purpose, the way a careless test would; the
 * second, run after the first test's tearDown, checks nothing was left.
 */

namespace Tests\Integration;

final class CleanupTest extends IntegrationTestCase {

	/**
	 * Makes a user through the helper, one the way the code under test would,
	 * a privacy request, and on a network a site, and deletes none of them.
	 *
	 * @return array{users: array<int, int>, request: int, site: int}
	 */
	public function test_a_test_that_forgets_to_clean_up(): array {
		$helper = $this->make_user();

		// An account nobody's helper knows about: a registration, a social
		// sign-in. Only the ID says the test made it.
		$direct = (int) wp_insert_user(
			array(
				'user_login' => 'cleanup_' . wp_generate_password( 8, false ),
				'user_email' => wp_generate_password( 8, false ) . '@example.test',
				'user_pass'  => wp_generate_password( 16 ),
			)
		);
		update_user_meta( $direct, 'diluxone_users_cleanup_probe', 'left behind' );

		// And a super admin a test forgot to take back, which WordPress
		// refuses to delete as it is.
		if ( is_multisite() ) {
			grant_super_admin( $helper );
		}

		// A post the code under test makes: what an account's export or
		// closing asks WordPress for. For an address with no account, so
		// deleting the users does not take it along.
		$request = (int) wp_create_user_request( 'nobody-' . strtolower( wp_generate_password( 8, false ) ) . '@example.test', 'export_personal_data' );
		$this->assertGreaterThan( 0, $request );

		$site = 0;

		if ( is_multisite() ) {
			$site = (int) wp_insert_site(
				array(
					'domain' => (string) get_network()->domain,
					'path'   => '/cleanup-' . strtolower( wp_generate_password( 6, false ) ) . '/',
				)
			);
			$this->assertGreaterThan( 0, $site );
		}

		$this->assertGreaterThan( 0, $helper );
		$this->assertGreaterThan( 0, $direct );

		return array(
			'users'   => array( $helper, $direct ),
			'request' => $request,
			'site'    => $site,
		);
	}

	/**
	 * @depends test_a_test_that_forgets_to_clean_up
	 *
	 * @param array{users: array<int, int>, request: int, site: int} $made
	 */
	public function test_what_it_made_is_gone_after_it( array $made ): void {
		global $wpdb;

		foreach ( $made['users'] as $user ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$this->assertSame( 0, (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->users} WHERE ID = %d", $user ) ), "User {$user} was left behind." );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$this->assertSame( 0, (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->usermeta} WHERE user_id = %d", $user ) ), "User {$user}'s meta was left behind." );
		}

		$this->assertNull( get_post( $made['request'] ), "Privacy request {$made['request']} was left behind." );

		if ( is_multisite() ) {
			$this->assertNull( get_site( $made['site'] ), "Site {$made['site']} was left behind." );
			$this->assertSame( array( get_userdata( 1 )->user_login ), array_values( get_super_admins() ), 'no super admin left behind but the administrator' );
		}
	}

	/** The administrator, below every test's mark, is never touched. */
	public function test_the_administrator_stays(): void {
		$this->assertNotFalse( get_userdata( 1 ) );
	}
}
