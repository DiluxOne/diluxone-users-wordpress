<?php
/**
 * Where each screen and tab belongs on a network, and what the move compares.
 *
 * The admin half of the network change is a map — which screen, which tab,
 * belongs to the network, the hub or the site — and a rule reading it from
 * four places. Both are pure, and a mistake in either is a network screen in a
 * site's menu, or a site's screen nobody can reach; so they are held here,
 * place by place. So are the two small judgements the move makes about stored
 * values: whether two are the same setting, and how one is written down.
 */

namespace Tests\Unit\DiluxOneUsers;

use Tests\Unit\ResetsWpStubs;
use Brain\Monkey;
use PHPUnit\Framework\TestCase;

class NetworkPlacesTest extends TestCase {

	use ResetsWpStubs;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		require_once DILUXONE_USERS_DIR . 'includes/options.php';
		require_once DILUXONE_USERS_DIR . 'includes/options-scope.php';
		require_once DILUXONE_USERS_DIR . 'includes/admin.php';
		require_once DILUXONE_USERS_DIR . 'includes/admin-network.php';
		require_once DILUXONE_USERS_DIR . 'includes/migrate.php';
		$GLOBALS['_test_multisite'] = false;
	}

	protected function tearDown(): void {
		unset( $GLOBALS['_test_multisite'] );
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_the_networks_screens_are_the_networks_alone(): void {
		foreach ( array( 'diluxone-users-security', 'diluxone-users-social', 'diluxone-users-fields' ) as $screen ) {
			$this->assertSame( 'network', diluxone_users_panel_scope( $screen ), $screen );
			$this->assertTrue( diluxone_users_screen_here( $screen, 'network' ), $screen );
			$this->assertFalse( diluxone_users_screen_here( $screen, 'hub' ), $screen );
			$this->assertFalse( diluxone_users_screen_here( $screen, 'site' ), $screen );
			$this->assertTrue( diluxone_users_screen_here( $screen, 'single' ), $screen );
		}
	}

	public function test_the_screens_people_sign_in_on_are_the_hubs(): void {
		foreach ( array( 'diluxone-users-login', 'diluxone-users-design', 'diluxone-users-notices' ) as $screen ) {
			$this->assertSame( 'hub', diluxone_users_panel_scope( $screen ), $screen );
			$this->assertTrue( diluxone_users_screen_here( $screen, 'hub' ), $screen );
			$this->assertFalse( diluxone_users_screen_here( $screen, 'site' ), $screen );
			$this->assertFalse( diluxone_users_screen_here( $screen, 'network' ), $screen );
		}
	}

	/** The tabs that do not go where their screen goes. */
	public function test_a_tab_can_belong_somewhere_else_than_its_screen(): void {
		$this->assertSame( 'site', diluxone_users_panel_scope( 'diluxone-users-account', 'menu' ) );
		$this->assertSame( 'site', diluxone_users_panel_scope( 'diluxone-users-account', 'dashboard' ) );
		$this->assertSame( 'hub', diluxone_users_panel_scope( 'diluxone-users-account', 'sections' ) );
		$this->assertSame( 'network', diluxone_users_panel_scope( 'diluxone-users-reports', 'logging' ) );
		$this->assertSame( 'site', diluxone_users_panel_scope( 'diluxone-users-reports', 'activity' ) );
		$this->assertSame( 'network', diluxone_users_panel_scope( 'diluxone-users', 'uninstall' ) );

		// So Account area is on every site, for its own two tabs, and Reports
		// is on every site and in Network Admin.
		$this->assertTrue( diluxone_users_screen_here( 'diluxone-users-account', 'site' ) );
		$this->assertFalse( diluxone_users_screen_here( 'diluxone-users-account', 'network' ) );
		$this->assertTrue( diluxone_users_screen_here( 'diluxone-users-reports', 'site' ) );
		$this->assertTrue( diluxone_users_screen_here( 'diluxone-users-reports', 'network' ) );
		$this->assertTrue( diluxone_users_screen_here( 'diluxone-users', 'network' ) );
		$this->assertTrue( diluxone_users_screen_here( 'diluxone-users-status', 'site' ) );
		$this->assertFalse( diluxone_users_screen_here( 'diluxone-users-status', 'network' ) );
	}

	/** An add-on's screen nobody placed stays on the site, where it always was. */
	public function test_an_unknown_screen_is_the_sites(): void {
		$this->assertSame( 'site', diluxone_users_panel_scope( 'addon-screen', 'tab' ) );
	}

	/** On a single site every tab is drawn: there is one place. */
	public function test_on_a_single_site_every_tab_is_here(): void {
		$panels = array(
			'usage'     => array(),
			'network'   => array(),
			'uninstall' => array(),
		);

		$this->assertSame( array_keys( $panels ), array_keys( diluxone_users_panels_here( $panels, 'diluxone-users' ) ) );
		$this->assertSame( 'manage_options', diluxone_users_admin_cap() );
	}

	public function test_every_network_setting_moves_but_the_wipe_and_the_bookkeeping(): void {
		$moved = diluxone_users_network_moved_keys();

		$this->assertContains( 'diluxone_users_2fa_mode', $moved );
		$this->assertContains( 'diluxone_users_fields', $moved );
		$this->assertContains( 'diluxone_users_sso', $moved );
		$this->assertNotContains( 'diluxone_users_uninstall_wipe', $moved );
		$this->assertNotContains( DILUXONE_USERS_NETWORK_VERSION_OPTION, $moved );
		$this->assertNotContains( DILUXONE_USERS_NETWORK_CONFLICTS, $moved );
		$this->assertNotContains( 'diluxone_users_login_title', $moved, 'A hub setting stays on the hub' );
	}

	/** What is read back from the database is a string where a number was written. */
	public function test_one_and_one_as_text_are_the_same_setting(): void {
		Monkey\Functions\when( 'maybe_serialize' )->alias( 'serialize' );

		$this->assertTrue( diluxone_users_network_same( 1, '1' ) );
		$this->assertTrue( diluxone_users_network_same( array( 'a' => 1 ), array( 'a' => 1 ) ) );
		$this->assertFalse( diluxone_users_network_same( 'off', 'required' ) );
		$this->assertFalse( diluxone_users_network_same( array( 'totp' ), array( 'email' ) ) );
	}

	public function test_a_value_is_written_down_short_and_flat(): void {
		$this->assertSame( 'off', diluxone_users_network_said( 'off' ) );
		$this->assertSame( 'totp, email', diluxone_users_network_said( array( 'totp', 'email' ) ) );
		$this->assertSame( 80, mb_strlen( diluxone_users_network_said( str_repeat( 'x', 200 ) ) ) );
	}
}
