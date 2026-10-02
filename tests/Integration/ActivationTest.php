<?php
/**
 * Activation, again and again: what it writes the first time and never after.
 *
 * Every case runs on both topologies unless it names its counterpart. On a
 * network, activating from a site is activating for the network: every site
 * is set up, the network's fields and the hub's doors are written once, and
 * no site keeps a copy of its own. Activating again leaves an
 * administrator's answers alone, puts back WordPress's own fields without
 * losing the site's, and a site born while the plugin is not on for the
 * network is left to itself.
 */

namespace Tests\Integration;

class ActivationTest extends IntegrationTestCase {

	/** @var array<string, mixed> The settings the test rewrites, as it found them (null: there were none). */
	private array $kept = array();

	/** @var array<int, int> Sites made by the test. */
	private array $sites = array();

	/** WordPress's own registration answer, as the test found it. */
	private string $registration = '';

	private const KEYS = array( 'diluxone_users_fields', 'diluxone_users_login_register', 'diluxone_users_sso_register' );

	protected function setUp(): void {
		parent::setUp();

		foreach ( self::KEYS as $key ) {
			$this->kept[ $key ] = diluxone_users_raw_get( $key, null );
		}

		$this->registration = is_multisite() ? (string) get_site_option( 'registration', 'none' ) : (string) get_option( 'users_can_register' );
	}

	protected function tearDown(): void {
		while ( is_multisite() && ms_is_switched() ) {
			restore_current_blog();
		}

		foreach ( $this->sites as $site ) {
			wp_delete_site( $site );
		}

		foreach ( $this->kept as $key => $value ) {
			if ( null === $value ) {
				diluxone_users_delete_option( $key );
			} else {
				diluxone_users_update_option( $key, $value );
			}
		}

		if ( is_multisite() ) {
			update_site_option( 'registration', $this->registration );
		} else {
			update_option( 'users_can_register', $this->registration );
		}

		parent::tearDown();
	}

	private function network_only(): void {
		if ( ! is_multisite() ) {
			$this->markTestSkipped( 'Needs a multisite network: the single-site half of this case is in this same class.' );
		}
	}

	private function site(): int {
		$site = (int) wp_insert_site(
			array(
				'domain' => (string) get_network()->domain,
				'path'   => '/act-' . strtolower( wp_generate_password( 6, false ) ) . '/',
				'title'  => 'Activation test site',
			)
		);

		$this->assertGreaterThan( 0, $site );
		$this->sites[] = $site;

		return $site;
	}

	/** Opens or closes accounts the way the topology does it. */
	private function accounts( bool $open ): void {
		if ( is_multisite() ) {
			update_site_option( 'registration', $open ? 'user' : 'none' );
		} else {
			update_option( 'users_can_register', $open ? 1 : 0 );
		}
	}

	/** @return array<int, string> The keys of WordPress's own fields among the defaults. */
	private function natives(): array {
		return array_values( array_filter( array_column( diluxone_users_default_fields(), 'key' ), 'diluxone_users_field_is_native' ) );
	}

	public function test_activating_writes_the_fields_and_the_doors_where_they_live(): void {
		global $wpdb;

		$other = is_multisite() ? $this->site() : 0;

		foreach ( self::KEYS as $key ) {
			diluxone_users_delete_option( $key );
		}
		$this->accounts( true );

		$asked = array();
		$this->hook(
			'query',
			static function ( string $sql ) use ( &$asked ): string {
				$asked[] = $sql;

				return $sql;
			}
		);

		// From a site, and not for the network: on a network that is the same thing.
		diluxone_users_activate_now( false );

		$looked = array_filter( $asked, static fn( string $sql ): bool => str_contains( $sql, diluxone_users_log_table() ) );
		$this->assertNotSame( array(), $looked, 'the log\'s table is made, or checked, on the way' );

		$this->assertSame( array_column( diluxone_users_default_fields(), 'key' ), array_column( (array) diluxone_users_raw_get( 'diluxone_users_fields' ), 'key' ) );
		$this->assertSame( 1, (int) diluxone_users_raw_get( 'diluxone_users_login_register' ), 'accounts are open, so the doors start open' );
		$this->assertSame( 1, (int) diluxone_users_raw_get( 'diluxone_users_sso_register' ) );

		$table = diluxone_users_log_table();
		$this->assertSame( $table, $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) ) );

		if ( is_multisite() ) {
			$this->assertFalse( ms_is_switched(), 'back on the site it started on' );
			$this->assertSame( $wpdb->base_prefix . 'diluxone_users_log', $table, 'the network\'s table' );

			switch_to_blog( $other );
			$this->assertFalse( get_option( 'diluxone_users_fields', false ), 'no copy on another site' );
			$this->assertFalse( get_option( 'diluxone_users_login_register', false ) );
			restore_current_blog();
		}
	}

	public function test_activating_again_keeps_what_an_administrator_chose(): void {
		$this->accounts( true );
		diluxone_users_update_option( 'diluxone_users_login_register', 0 );
		diluxone_users_update_option( 'diluxone_users_sso_register', 0 );

		diluxone_users_activate_now( false );

		$this->assertSame( 0, (int) diluxone_users_raw_get( 'diluxone_users_login_register' ), 'closed on purpose stays closed' );
		$this->assertSame( 0, (int) diluxone_users_raw_get( 'diluxone_users_sso_register' ) );
	}

	public function test_on_a_closed_network_or_site_both_doors_start_closed(): void {
		$this->accounts( false );
		diluxone_users_delete_option( 'diluxone_users_login_register' );
		diluxone_users_delete_option( 'diluxone_users_sso_register' );

		diluxone_users_activate_now( false );

		$this->assertSame( 0, (int) diluxone_users_raw_get( 'diluxone_users_login_register' ) );
		$this->assertSame( 0, (int) diluxone_users_raw_get( 'diluxone_users_sso_register' ) );
	}

	public function test_wordpress_own_fields_are_put_back_in_front_of_the_sites_own(): void {
		$team = array(
			'key'   => 'diluxone_users_team',
			'label' => 'Team',
		);
		diluxone_users_update_option( 'diluxone_users_fields', array( $team ) );

		diluxone_users_seed_fields();

		$fields = (array) diluxone_users_raw_get( 'diluxone_users_fields' );
		$this->assertSame( array_merge( $this->natives(), array( 'diluxone_users_team' ) ), array_column( $fields, 'key' ) );
		$this->assertSame( $team, end( $fields ), 'the site\'s own field, as it was' );

		diluxone_users_seed_fields();
		$this->assertSame( $fields, diluxone_users_raw_get( 'diluxone_users_fields' ), 'and only once' );

		diluxone_users_update_option( 'diluxone_users_fields', array() );
		diluxone_users_seed_fields();
		$this->assertSame( $this->natives(), array_column( (array) diluxone_users_raw_get( 'diluxone_users_fields' ), 'key' ), 'an empty list still gets WordPress\'s own' );
	}

	public function test_a_site_born_while_the_plugin_is_not_on_for_the_network_is_left_alone(): void {
		$this->network_only();

		diluxone_users_update_option( DILUXONE_USERS_MEMBERSHIP_CONFIRMED, 1 );
		diluxone_users_membership_queue_save( array() );
		diluxone_users_delete_option( 'diluxone_users_login_register' );
		$this->hook( 'pre_site_option_active_sitewide_plugins', static fn(): array => array() );
		add_filter( 'diluxone_users_membership_inline', '__return_zero', 20 );

		try {
			$site = $this->site();

			$this->assertNull( diluxone_users_raw_get( 'diluxone_users_login_register', null ), 'nothing set up' );
			$this->assertSame( array(), diluxone_users_membership_queue(), 'and nobody queued for it' );
			$this->assertSame( array(), get_users( array( 'blog_id' => $site, 'fields' => 'ID', 'exclude' => array( get_current_user_id() ) ) ), 'nor added' );
		} finally {
			remove_filter( 'diluxone_users_membership_inline', '__return_zero', 20 );
			diluxone_users_membership_queue_save( array() );
		}
	}

	/** The notice about the plugin speaks where the plugin is looked at, and nowhere else. */
	public function test_the_plugins_notice_speaks_only_where_the_plugin_is_looked_at(): void {
		$was = $GLOBALS['pagenow'] ?? '';

		$cases = array(
			array( 'index.php', null, true ),
			array( 'plugins.php', null, true ),
			array( 'admin.php', 'diluxone-users-design', true ),
			array( 'admin.php', 'other', false ),
			array( 'edit.php', null, false ),
			array( 'admin.php', array( 'diluxone-users' ), false ),
		);

		try {
			foreach ( $cases as list( $now, $page, $speaks ) ) {
				$GLOBALS['pagenow'] = $now;
				$_GET               = null === $page ? array() : array( 'page' => $page );

				$this->assertSame( $speaks, diluxone_users_notice_here(), $now . ' ' . wp_json_encode( $page ) );
			}
		} finally {
			$GLOBALS['pagenow'] = $was;
		}
	}
}
