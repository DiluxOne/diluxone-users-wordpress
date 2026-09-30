<?php
/**
 * Every stored setting has a scope, and only one of the three.
 *
 * On a network a setting is the network's, the hub's or the site's, and which
 * one it is decides whether a person's second factor or the social
 * credentials are shared or not. The map is written out key by key, so the
 * failure mode is a new setting added without a line in it: silently 'site',
 * which on a network is the one answer that splits what should be shared.
 * This is the test that catches it, from the defaults and from the source.
 */

namespace Tests\Unit\DiluxOneUsers;

use Brain\Monkey;
use PHPUnit\Framework\TestCase;

class OptionScopeTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		require_once DILUXONE_USERS_DIR . 'includes/options.php';
		require_once DILUXONE_USERS_DIR . 'includes/options-scope.php';
		$GLOBALS['_test_wp_options'] = array();
		$GLOBALS['_test_multisite']  = false;
	}

	protected function tearDown(): void {
		unset( $GLOBALS['_test_multisite'] );
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * Every key the plugin stores through its helpers, read from includes/.
	 *
	 * A literal key or one of the constants that name the stored lists. A key
	 * built at run time (the page settings, the doors seeded on a new site)
	 * comes from the defaults, which the other test covers.
	 *
	 * @return array<int, string>
	 */
	private function stored_in_source(): array {
		$source = '';

		foreach ( (array) glob( DILUXONE_USERS_DIR . 'includes/*.php' ) as $file ) {
			$source .= (string) file_get_contents( (string) $file );
		}

		preg_match_all( "/const (DILUXONE_USERS_[A-Z_]+) = '(diluxone_users_[a-z0-9_]+)';/", $source, $consts );
		$names = array_combine( $consts[1], $consts[2] );

		preg_match_all( "/diluxone_users_(?:raw_get|update_option|delete_option)\(\s*('diluxone_users_[a-z0-9_]+'|DILUXONE_USERS_[A-Z_]+)/", $source, $calls );

		$keys = array();

		foreach ( $calls[1] as $arg ) {
			$keys[] = "'" === $arg[0] ? trim( $arg, "'" ) : ( $names[ $arg ] ?? $arg );
		}

		return array_values( array_unique( $keys ) );
	}

	public function test_every_default_has_a_scope_written_out(): void {
		$missing = array_diff( array_keys( diluxone_users_option_defaults() ), array_keys( diluxone_users_option_scopes() ) );

		$this->assertSame( array(), array_values( $missing ), 'A setting without a line in includes/options-scope.php.' );
	}

	public function test_every_setting_stored_without_a_default_has_a_scope_too(): void {
		$stored = $this->stored_in_source();

		// The source scan found the lists stored without a default, or it is
		// not reading the source any more and the test above is all there is.
		$this->assertContains( 'diluxone_users_sso', $stored );
		$this->assertContains( 'diluxone_users_mail_templates', $stored );

		$missing = array_diff( $stored, array_keys( diluxone_users_option_scopes() ) );

		$this->assertSame( array(), array_values( $missing ), 'A stored setting without a line in includes/options-scope.php.' );
	}

	public function test_the_map_names_nothing_the_plugin_does_not_store(): void {
		$known = array_merge( array_keys( diluxone_users_option_defaults() ), $this->stored_in_source() );
		$stale = array_diff( array_keys( diluxone_users_option_scopes() ), $known );

		$this->assertSame( array(), array_values( $stale ), 'A scope for a setting that no longer exists.' );
	}

	public function test_there_are_only_three_scopes(): void {
		$this->assertSame(
			array(),
			array_values( array_diff( array_unique( diluxone_users_option_scopes() ), array( 'network', 'hub', 'site' ) ) )
		);
	}

	public function test_who_gets_in_and_how_safely_is_the_networks(): void {
		foreach ( array(
			'diluxone_users_2fa_mode',
			'diluxone_users_passkey_enabled',
			'diluxone_users_session_long_days',
			'diluxone_users_trusted_proxies',
			'diluxone_users_sso',
			'diluxone_users_sso_link_by_email',
			'diluxone_users_fields',
			'diluxone_users_uninstall_wipe',
		) as $key ) {
			$this->assertSame( 'network', diluxone_users_option_scope( $key ), $key );
		}
	}

	public function test_the_screens_people_sign_in_and_live_on_are_the_hubs(): void {
		foreach ( array(
			'diluxone_users_login_method',
			'diluxone_users_register_form',
			'diluxone_users_sso_register',
			'diluxone_users_sso_button_skin',
			'diluxone_users_account_sections',
			'diluxone_users_styles',
			'diluxone_users_mail_templates',
			'diluxone_users_login_messages',
			'diluxone_users_privacy_export',
		) as $key ) {
			$this->assertSame( 'hub', diluxone_users_option_scope( $key ), $key );
		}
	}

	public function test_the_bookkeeping_and_anything_unknown_stay_on_the_site(): void {
		$this->assertSame( 'site', diluxone_users_option_scope( 'diluxone_users_rewrite_version' ) );
		$this->assertSame( 'site', diluxone_users_option_scope( 'diluxone_users_log_schema' ) );
		$this->assertSame( 'site', diluxone_users_option_scope( 'diluxone_users_not_a_setting' ) );
	}

	public function test_nothing_is_routed_on_a_single_site(): void {
		$this->assertFalse( diluxone_users_scoped_storage_active() );

		$this->assertTrue( diluxone_users_update_option( 'diluxone_users_2fa_mode', 'all' ) );
		$this->assertSame( 'all', $GLOBALS['_test_wp_options']['diluxone_users_2fa_mode'] );
		$this->assertSame( 'all', diluxone_users_raw_get( 'diluxone_users_2fa_mode' ) );

		diluxone_users_delete_option( 'diluxone_users_2fa_mode' );
		$this->assertFalse( diluxone_users_raw_get( 'diluxone_users_2fa_mode' ) );
	}

	public function test_nor_on_a_network_until_it_is_turned_on(): void {
		$GLOBALS['_test_multisite'] = true;

		$this->assertFalse( diluxone_users_scoped_storage_active() );

		diluxone_users_update_option( 'diluxone_users_sso', array( 'google' => array() ) );
		$this->assertSame( array( 'google' => array() ), $GLOBALS['_test_wp_options']['diluxone_users_sso'] );
	}
}
