<?php
/**
 * Activation, again and again: it settles which defaults the install is on,
 * and writes nothing else.
 *
 * Every case runs on both topologies unless it names its counterpart. A new
 * install writes one option, the defaults' version, and no setting, table or
 * event; one that ran 1.0.0 gets that version's answers written down for the
 * settings it never saved, and a network waits for its settings and its log
 * to move first. Activating again changes nothing, the list of fields gets
 * WordPress's own put back only once it is a saved list, and a site born
 * while the plugin is not on for the network is left to itself.
 */

namespace Tests\Integration;

class ActivationTest extends IntegrationTestCase {

	protected bool $fresh = true;

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

	/**
	 * Runs a callback with these settings gone from where they live — and, on
	 * a network, from the main site's own table — and puts them back after.
	 *
	 * @param array<int, string> $keys
	 * @return mixed What the callback returned.
	 */
	private function without( array $keys, callable $run ) {
		$kept = array();
		$main = is_multisite() ? (int) get_main_site_id() : 0;

		foreach ( $keys as $key ) {
			$kept[ $key ] = array(
				'here' => diluxone_users_raw_get( $key, null ),
				'main' => is_multisite() ? get_blog_option( $main, $key, null ) : null,
			);
			diluxone_users_delete_option( $key );

			if ( is_multisite() ) {
				delete_blog_option( $main, $key );
			}
		}

		try {
			return $run();
		} finally {
			foreach ( $kept as $key => $was ) {
				diluxone_users_delete_option( $key );

				if ( null !== $was['here'] ) {
					diluxone_users_update_option( $key, $was['here'] );
				}

				if ( is_multisite() && null !== $was['main'] ) {
					update_blog_option( $main, $key, $was['main'] );
				}
			}
		}
	}

	/** Every setting the plugin can store, and the marks an older version left. */
	private function every_setting(): array {
		return array_values(
			array_unique(
				array_merge(
					array_keys( diluxone_users_option_defaults() ),
					array_keys( diluxone_users_defaults_before() ),
					array( 'diluxone_users_fields', 'diluxone_users_log_schema', DILUXONE_USERS_DEFAULTS_VERSION_OPTION )
				)
			)
		);
	}

	public function test_activating_a_new_install_writes_one_option_and_nothing_else(): void {
		global $wpdb;

		$this->accounts( true );

		$this->without(
			$this->every_setting(),
			function () use ( $wpdb ): void {
				$written = array();
				$note    = static function ( $option ) use ( &$written ): void {
					if ( 0 === strpos( (string) $option, 'diluxone_users_' ) ) {
						$written[] = (string) $option;
					}
				};

				foreach ( array( 'added_option', 'updated_option', 'add_site_option', 'update_site_option' ) as $hook ) {
					$this->hook( $hook, $note );
				}

				$asked = array();
				$this->hook(
					'query',
					static function ( string $sql ) use ( &$asked ): string {
						$asked[] = $sql;

						return $sql;
					}
				);

				$events = _get_cron_array();

				// From a site, and not for the network: on a network that is the same thing.
				diluxone_users_activate_now( false );

				$this->assertSame( array( DILUXONE_USERS_DEFAULTS_VERSION_OPTION ), array_values( array_unique( $written ) ), 'the version of the defaults, and no setting' );
				$this->assertSame( array(), array_filter( $asked, static fn( string $sql ): bool => (bool) preg_match( '/^\s*(CREATE|ALTER)\s+TABLE/i', $sql ) ), 'no table made' );
				$this->assertSame( $events, _get_cron_array(), 'nothing scheduled' );

				$this->assertNull( diluxone_users_raw_get( 'diluxone_users_fields', null ), 'the starter fields are a default, not a stored list' );
				$this->assertSame( array_column( diluxone_users_default_fields(), 'key' ), array_column( diluxone_users_fields(), 'key' ) );
				$this->assertNull( diluxone_users_raw_get( 'diluxone_users_login_register', null ), 'accounts are open on the site, and the plugin opens no door of its own' );
				$this->assertSame( 0, (int) diluxone_users_option( 'diluxone_users_login_register' ) );
				$this->assertSame( 0, (int) diluxone_users_option( 'diluxone_users_sso_register' ) );
				$this->assertSame( array(), diluxone_users_log_levels(), 'nothing recorded' );
				$this->assertTrue( diluxone_users_defaults_settled() );

				// Settled once: a second activation, or any request, writes nothing.
				$written = array();
				diluxone_users_activate_now( false );
				diluxone_users_defaults_settle();
				$this->assertSame( array(), $written );
			}
		);
	}

	public function test_an_install_that_ran_1_0_0_keeps_what_it_did(): void {
		$this->accounts( true );

		$this->without(
			array_merge( $this->every_setting(), array( 'diluxone_users_network_version', 'diluxone_users_log_moved' ) ),
			function (): void {
				// What 1.0.0 left behind the moment it was activated, and one
				// answer an administrator saved on top.
				diluxone_users_update_option( 'diluxone_users_fields', diluxone_users_default_fields() );
				diluxone_users_update_option( 'diluxone_users_log_schema', DILUXONE_USERS_LOG_SCHEMA );
				diluxone_users_update_option( 'diluxone_users_login_method', 'password' );
				diluxone_users_update_option( 'diluxone_users_login_register', 0 );

				if ( is_multisite() ) {
					// Its settings and its log already moved to the network.
					diluxone_users_update_option( 'diluxone_users_network_version', DILUXONE_USERS_NETWORK_VERSION );
					diluxone_users_update_option( 'diluxone_users_log_moved', 1 );
				}

				diluxone_users_activate_now( false );

				$this->assertTrue( diluxone_users_defaults_settled() );
				$this->assertSame( 'password', diluxone_users_raw_get( 'diluxone_users_login_method' ), 'what an administrator saved is left as it was' );
				$this->assertSame( 0, (int) diluxone_users_raw_get( 'diluxone_users_login_register' ) );

				foreach ( diluxone_users_defaults_before() as $key => $old ) {
					if ( in_array( $key, array( 'diluxone_users_login_method', 'diluxone_users_login_register' ), true ) ) {
						continue;
					}

					$this->assertEquals( $old, diluxone_users_raw_get( $key, null ), $key . ': written down as it was' );
				}

				$this->assertSame( array( 'access' ), diluxone_users_log_levels(), 'the log goes on recording the way in' );
				$this->assertSame( 'optional', diluxone_users_option( 'diluxone_users_2fa_mode' ) );
				$this->assertSame( 1, (int) diluxone_users_option( 'diluxone_users_sso_register' ), 'the door seeded from WordPress\'s open registration' );
			}
		);
	}

	public function test_a_network_that_has_not_moved_its_settings_waits_for_the_move(): void {
		$this->network_only();

		$this->without(
			array_merge( $this->every_setting(), array( 'diluxone_users_network_version', 'diluxone_users_log_moved' ) ),
			function (): void {
				diluxone_users_update_option( 'diluxone_users_log_schema', DILUXONE_USERS_LOG_SCHEMA );

				diluxone_users_defaults_settle();

				$this->assertFalse( diluxone_users_defaults_settled(), 'not before the main site\'s answers are on the network' );
				$this->assertNull( diluxone_users_raw_get( 'diluxone_users_login_method', null ) );

				diluxone_users_update_option( 'diluxone_users_network_version', DILUXONE_USERS_NETWORK_VERSION );
				diluxone_users_update_option( 'diluxone_users_log_moved', 1 );
				diluxone_users_defaults_settle();

				$this->assertTrue( diluxone_users_defaults_settled() );
				$this->assertSame( 'both', diluxone_users_raw_get( 'diluxone_users_login_method' ) );
				$this->assertSame( 1, (int) diluxone_users_raw_get( 'diluxone_users_network_hub' ), 'the other sites go on sending people to the hub' );
			}
		);
	}

	public function test_wordpress_own_fields_are_put_back_in_front_of_the_sites_own(): void {
		$team = array(
			'key'   => 'diluxone_users_team',
			'label' => 'Team',
		);
		diluxone_users_update_option( 'diluxone_users_fields', array( $team ) );

		diluxone_users_seed_native_fields();

		$fields = (array) diluxone_users_raw_get( 'diluxone_users_fields' );
		$this->assertSame( array_merge( $this->natives(), array( 'diluxone_users_team' ) ), array_column( $fields, 'key' ) );
		$this->assertSame( $team, end( $fields ), 'the site\'s own field, as it was' );

		diluxone_users_seed_native_fields();
		$this->assertSame( $fields, diluxone_users_raw_get( 'diluxone_users_fields' ), 'and only once' );

		diluxone_users_update_option( 'diluxone_users_fields', array() );
		diluxone_users_seed_native_fields();
		$this->assertSame( $this->natives(), array_column( (array) diluxone_users_raw_get( 'diluxone_users_fields' ), 'key' ), 'an empty list still gets WordPress\'s own' );

		diluxone_users_delete_option( 'diluxone_users_fields' );
		diluxone_users_seed_native_fields();
		$this->assertNull( diluxone_users_raw_get( 'diluxone_users_fields', null ), 'a list never saved stays unsaved' );
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
