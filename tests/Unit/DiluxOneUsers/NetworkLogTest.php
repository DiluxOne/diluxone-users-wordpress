<?php
/**
 * Where the activity log lives, on a single site and on a network.
 *
 * On a network the log is one table, under the network's prefix, every row
 * stamped with its site; on a single site it is the site's own.
 * Which table, which site a row is stamped with, who runs the purge, which
 * tab shows every site's rows and whose bookkeeping the table's shape is: all
 * of it is answered without a database, both ways round, here. The rows
 * themselves are the integration suite's.
 */

namespace Tests\Unit\DiluxOneUsers;

use Brain\Monkey;
use PHPUnit\Framework\TestCase;

class NetworkLogTest extends TestCase {

	/** @var mixed The database object as the rest of the unit suite has it. */
	private $wpdb;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		Monkey\Functions\when( 'register_activation_hook' )->justReturn( true );
		Monkey\Functions\when( 'register_deactivation_hook' )->justReturn( true );

		require_once DILUXONE_USERS_DIR . 'includes/network-gate.php';
		require_once DILUXONE_USERS_DIR . 'includes/options.php';
		require_once DILUXONE_USERS_DIR . 'includes/options-scope.php';
		require_once DILUXONE_USERS_DIR . 'includes/log.php';
		require_once DILUXONE_USERS_DIR . 'includes/admin.php';
		require_once DILUXONE_USERS_DIR . 'includes/admin-network.php';
		require_once DILUXONE_USERS_DIR . 'includes/migrate.php';

		$GLOBALS['_test_wp_options'] = array();
		$GLOBALS['_test_multisite']  = false;

		$this->wpdb = $GLOBALS['wpdb'];

		// A site of a network that is not the first one: its own prefix is not
		// the network's, which is the case where the two tables differ.
		$GLOBALS['wpdb'] = new class() {
			public string $prefix = 'wp_3_';

			public string $base_prefix = 'wp_';

			public function get_blog_prefix( int $site ): string {
				return 1 === $site ? $this->base_prefix : $this->base_prefix . $site . '_';
			}
		};

		Monkey\Functions\when( 'get_current_blog_id' )->justReturn( 3 );
	}

	protected function tearDown(): void {
		$GLOBALS['wpdb'] = $this->wpdb;
		unset( $GLOBALS['_test_multisite'] );
		Monkey\tearDown();
		parent::tearDown();
	}

	/** The plugin on for every site of the network, or not. */
	private function network( bool $on ): void {
		$GLOBALS['_test_multisite'] = true;

		Monkey\Functions\when( 'plugin_basename' )->justReturn( 'diluxone-users/diluxone-users.php' );
		Monkey\Functions\when( 'get_site_option' )->alias(
			static function ( string $key, $fallback = false ) use ( $on ) {
				return 'active_sitewide_plugins' === $key
					? ( $on ? array( 'diluxone-users/diluxone-users.php' => 1 ) : array() )
					: $fallback;
			}
		);
	}

	/** A single site: no network, and the prefix is the site's. */
	private function single(): void {
		$GLOBALS['_test_multisite'] = false;
		$GLOBALS['wpdb']->prefix    = 'wp_';

		Monkey\Functions\when( 'get_current_blog_id' )->justReturn( 1 );
	}

	/* ── Which table ───────────────────────────────────────────────── */

	public function test_on_a_single_site_the_table_is_the_sites_own(): void {
		$this->single();

		$this->assertFalse( diluxone_users_log_network() );
		$this->assertSame( 'wp_diluxone_users_log', diluxone_users_log_table() );
	}

	public function test_on_a_network_whose_settings_are_the_networks_every_site_writes_to_one_table(): void {
		$this->network( true );

		$this->assertTrue( diluxone_users_log_network() );
		$this->assertSame( 'wp_diluxone_users_log', diluxone_users_log_table(), 'The network’s prefix, not the site’s' );
	}

	/** A site of a network never has a table of its own: there is no second way. */
	public function test_on_a_network_no_site_keeps_a_table_of_its_own(): void {
		$this->network( false );

		$this->assertTrue( diluxone_users_log_network() );
		$this->assertSame( 'wp_diluxone_users_log', diluxone_users_log_table() );
	}

	/* ── Which site a row is stamped with ──────────────────────────── */

	public function test_a_row_is_stamped_with_the_site_it_happened_on_both_ways(): void {
		$this->network( true );
		$this->assertSame( 3, diluxone_users_log_site() );

		$this->single();
		$this->assertSame( 1, diluxone_users_log_site() );
	}

	/* ── Who runs the purge ────────────────────────────────────────── */

	public function test_the_purge_runs_once_for_the_network_on_its_main_site(): void {
		$this->network( true );

		Monkey\Functions\when( 'is_main_site' )->justReturn( false );
		$this->assertFalse( diluxone_users_log_purges_here(), 'Another site of the network does not purge the network’s table' );

		Monkey\Functions\when( 'is_main_site' )->justReturn( true );
		$this->assertTrue( diluxone_users_log_purges_here() );
	}

	public function test_a_single_site_purges_its_own_table(): void {
		Monkey\Functions\when( 'is_main_site' )->justReturn( false );

		$this->single();
		$this->assertTrue( diluxone_users_log_purges_here() );
	}

	/* ── A site deleted from the network ───────────────────────────── */

	public function test_a_deleted_site_drops_its_own_table_and_never_the_networks(): void {
		$this->network( true );

		$this->assertSame( array( 'wp_3_posts', 'wp_3_diluxone_users_log' ), diluxone_users_log_drop_with_site( array( 'wp_3_posts' ), 3 ) );
		$this->assertSame( array( 'wp_posts' ), diluxone_users_log_drop_with_site( array( 'wp_posts' ), 1 ), 'The first site’s table is the network’s' );
	}

	/* ── One table, several networks ───────────────────────────────── */

	/** The network's sites are asked of the current network, and of no other. */
	public function test_the_networks_sites_are_the_current_networks(): void {
		$this->network( true );

		$asked = array();

		Monkey\Functions\when( 'get_current_network_id' )->justReturn( 7 );
		Monkey\Functions\when( 'get_sites' )->alias(
			static function ( array $args ) use ( &$asked ): array {
				$asked[] = $args;

				return array( '12', '15' );
			}
		);

		$this->assertSame( array( 12, 15 ), diluxone_users_log_network_sites() );
		$this->assertSame( 7, $asked[0]['network_id'] );
		$this->assertSame( 'ids', $asked[0]['fields'] );
	}

	/** A network of thousands of sites is deleted from in groups, never all at once and never with none. */
	public function test_a_networks_sites_come_in_groups_small_enough_for_one_in(): void {
		$groups = diluxone_users_log_site_groups( range( 1, 1203 ) );

		$this->assertCount( 3, $groups );
		$this->assertCount( 500, $groups[0] );
		$this->assertSame( 1203, $groups[2][ count( $groups[2] ) - 1 ] );

		$this->assertSame( array(), diluxone_users_log_site_groups( array() ), 'No site is no group: nothing, never every row' );
		$this->assertSame( array( array( 3 ) ), diluxone_users_log_site_groups( array( 3, '3', 0, -1 ) ) );
		$this->assertSame( '%d, %d, %d', diluxone_users_log_placeholders( array( 1, 2, 3 ) ) );
	}

	/* ── Whose bookkeeping ─────────────────────────────────────────── */

	public function test_the_shape_of_the_table_and_its_move_are_the_networks_to_keep(): void {
		foreach ( array( 'diluxone_users_log_schema', 'diluxone_users_log_moved', 'diluxone_users_log_moving', 'diluxone_users_log_kept' ) as $key ) {
			$this->assertSame( 'network', diluxone_users_option_scope( $key ), $key );
			$this->assertNotContains( $key, diluxone_users_network_moved_keys(), "{$key} is not a site’s setting taken by the network" );
		}
	}

	/* ── Which screen shows which rows ─────────────────────────────── */

	public function test_every_sites_rows_are_a_tab_of_the_networks_and_a_sites_own_are_its(): void {
		$this->assertSame( 'network', diluxone_users_panel_scope( 'diluxone-users-reports', 'network-activity' ) );
		$this->assertSame( 'site', diluxone_users_panel_scope( 'diluxone-users-reports', 'activity' ) );

		$this->assertTrue( diluxone_users_admin_owns( 'network', 'network' ) );
		$this->assertFalse( diluxone_users_admin_owns( diluxone_users_panel_scope( 'diluxone-users-reports', 'network-activity' ), 'hub' ), 'Not on the main site' );
		$this->assertFalse( diluxone_users_admin_owns( diluxone_users_panel_scope( 'diluxone-users-reports', 'network-activity' ), 'site' ), 'Not on another site' );
		$this->assertFalse( diluxone_users_admin_owns( diluxone_users_panel_scope( 'diluxone-users-reports', 'activity' ), 'network' ), 'A site’s own rows are not a network tab' );
		$this->assertTrue( diluxone_users_admin_owns( diluxone_users_panel_scope( 'diluxone-users-reports', 'activity' ), 'single' ), 'On a single site the report is the site’s' );
	}
}
