<?php
/**
 * Activation, again and again: it writes nothing at all.
 *
 * Every case runs on both topologies unless it names its counterpart. An
 * activation writes no option, makes no table and schedules no event, the
 * first time and every time after; the list of fields gets WordPress's own
 * put back only once it is a saved list, and a site born while the plugin is
 * not on for the network is left to itself.
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

	/** Every setting the plugin can store. */
	private function every_setting(): array {
		return array_values(
			array_unique(
				array_merge(
					array_keys( diluxone_users_option_defaults() ),
					array( 'diluxone_users_fields', 'diluxone_users_log_schema' )
				)
			)
		);
	}

	public function test_activating_writes_nothing_at_all(): void {
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

				$this->assertSame( array(), $written, 'no option at all' );
				$this->assertSame( array(), array_filter( $asked, static fn( string $sql ): bool => (bool) preg_match( '/^\s*(CREATE|ALTER)\s+TABLE/i', $sql ) ), 'no table made' );
				$this->assertSame( $events, _get_cron_array(), 'nothing scheduled' );

				$this->assertNull( diluxone_users_raw_get( 'diluxone_users_fields', null ), 'the starter fields are a default, not a stored list' );
				$this->assertSame( array_column( diluxone_users_default_fields(), 'key' ), array_column( diluxone_users_fields(), 'key' ) );
				$this->assertNull( diluxone_users_raw_get( 'diluxone_users_login_register', null ), 'accounts are open on the site, and the plugin opens no door of its own' );
				$this->assertSame( 0, (int) diluxone_users_option( 'diluxone_users_login_register' ) );
				$this->assertSame( 0, (int) diluxone_users_option( 'diluxone_users_sso_register' ) );
				$this->assertSame( array(), diluxone_users_log_levels(), 'nothing recorded' );

				// And a second activation writes nothing either.
				diluxone_users_activate_now( false );
				$this->assertSame( array(), $written );
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
