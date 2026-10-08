<?php
/**
 * An add-on's settings are settings like the plugin's own.
 *
 * Added through `diluxone_users_option_defaults`, a key is read with its
 * default, saved by diluxone_users_save_options(), carried by the settings
 * file and stored where the scope it was given says. What the filter cannot
 * do is just as much the point: add a key outside the plugin's namespace — a
 * screen or a file would then write an option that is not the plugin's — or
 * change a default of the plugin's own.
 *
 * The add-on's key is a hub setting, written from the main site: the hub on a
 * network, the only site on a single one.
 */

namespace Tests\Integration;

class AddOnOptionsTest extends IntegrationTestCase {

	private const KEY = 'diluxone_users_addon_shade';

	protected function setUp(): void {
		parent::setUp();

		$this->hook(
			'diluxone_users_option_defaults',
			static function ( array $defaults ): array {
				$defaults[ self::KEY ]                    = 'blue';
				$defaults['blogdescription']              = 'taken over';
				$defaults['diluxone_users_login_template'] = 'bogus';
				unset( $defaults['diluxone_users_login_title'] );

				return $defaults;
			}
		);
		$this->hook( 'diluxone_users_option_scope', static fn( string $scope, string $key ): string => self::KEY === $key ? 'hub' : $scope, 10, 2 );
	}

	protected function tearDown(): void {
		diluxone_users_delete_option( self::KEY );
		parent::tearDown();
	}

	public function test_a_key_added_is_read_with_its_default(): void {
		$this->assertSame( 'blue', diluxone_users_option( self::KEY ) );
		$this->assertArrayHasKey( self::KEY, diluxone_users_option_defaults() );
	}

	public function test_the_plugins_own_defaults_stay_as_they_are(): void {
		$defaults = diluxone_users_option_defaults();

		$this->assertSame( 'plain', $defaults['diluxone_users_login_template'], 'a default of the plugin’s own is not changed from outside' );
		$this->assertArrayHasKey( 'diluxone_users_login_title', $defaults, 'nor taken away' );
		$this->assertArrayNotHasKey( 'blogdescription', $defaults, 'a key outside the namespace is not the plugin’s' );
	}

	/** A callback of the defaults filter that reads a setting gets the plugin's own defaults, not an endless loop. */
	public function test_a_callback_that_reads_a_setting_does_not_loop(): void {
		$this->hook(
			'diluxone_users_option_defaults',
			static function ( array $defaults ): array {
				$defaults['diluxone_users_addon_loop'] = diluxone_users_option( 'diluxone_users_login_method' );

				return $defaults;
			}
		);

		$this->assertContains( diluxone_users_option_defaults()['diluxone_users_addon_loop'], array( 'link', 'password', 'both' ), 'the setting, read once, and back' );
	}

	public function test_a_key_added_is_saved_and_one_outside_the_namespace_is_not(): void {
		$before = get_option( 'blogdescription' );

		diluxone_users_save_options(
			array(
				self::KEY         => 'green',
				'blogdescription' => 'taken over',
			)
		);

		$this->assertSame( 'green', diluxone_users_raw_get( self::KEY, null ) );
		$this->assertSame( $before, get_option( 'blogdescription' ), 'the site’s own option is not the plugin’s to write' );
	}

	public function test_the_settings_file_carries_it_both_ways(): void {
		diluxone_users_update_option( self::KEY, 'green' );

		$this->assertSame( 'green', diluxone_users_tool_settings()[ self::KEY ] ?? null, 'it goes into the file' );

		diluxone_users_delete_option( self::KEY );
		diluxone_users_tool_restore( array( self::KEY => 'teal' ) );

		$this->assertSame( 'teal', diluxone_users_raw_get( self::KEY, null ), 'and comes back out of it' );
	}

	public function test_it_is_stored_where_its_scope_says(): void {
		diluxone_users_save_options( array( self::KEY => 'green' ) );

		if ( ! is_multisite() ) {
			$this->assertSame( 'green', get_option( self::KEY ), 'on a single site everything is the site’s' );

			return;
		}

		$this->assertSame( 'green', get_blog_option( diluxone_users_hub_site_id(), self::KEY ), 'a hub setting is in the hub’s table' );
		$this->assertFalse( get_site_option( self::KEY ), 'and not in the network’s' );
	}
}
