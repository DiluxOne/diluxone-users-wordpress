<?php
/**
 * The Registration screen: where it sits in the menu, the tabs it holds, what
 * its summary says, and where the old address of its tab lands.
 *
 * Registration is the hub's, like Access: on a network it is saved from the
 * main site's dashboard and Network Admin has neither. Every case runs on both
 * topologies unless it names its counterpart.
 */

namespace Tests\Integration;

use Tests\Integration\Support\CoverSettingsSupport;

class RegistrationScreenTest extends IntegrationTestCase {

	use CoverSettingsSupport;

	/** @var array<string, mixed> The admin menu's globals before the test. */
	private array $menu_globals = array();

	protected function setUp(): void {
		parent::setUp();

		require_once ABSPATH . 'wp-admin/includes/admin.php';

		foreach ( array( 'menu', 'submenu', '_registered_pages', '_parent_pages', 'admin_page_hooks' ) as $global ) {
			$this->menu_globals[ $global ] = $GLOBALS[ $global ] ?? null;
		}

		do_action( 'diluxone_users_register_panels' );
	}

	protected function tearDown(): void {
		foreach ( $this->menu_globals as $global => $value ) {
			if ( null === $value ) {
				unset( $GLOBALS[ $global ] );
			} else {
				$GLOBALS[ $global ] = $value;
			}
		}

		$this->cover_settings_reset();

		parent::tearDown();
	}

	/**
	 * The plugin's submenu, as the slugs it holds, in order.
	 *
	 * @return array<int, string>
	 */
	private function submenu(): array {
		$GLOBALS['submenu'] = array();

		diluxone_users_menu();

		return array_column( $GLOBALS['submenu'][ DILUXONE_USERS_MENU ] ?? array(), 2 );
	}

	/* ── The menu ──────────────────────────────────────────────────── */

	public function test_c1_registration_is_right_above_access(): void {
		$this->the_admin();

		$slugs = $this->submenu();
		$at    = array_search( 'diluxone-users-register', $slugs, true );

		$this->assertIsInt( $at, 'Registration is in the menu' );
		$this->assertSame( 'diluxone-users-login', $slugs[ $at + 1 ] ?? '', 'and Access is the next entry' );

		$titles = array_column( $GLOBALS['submenu'][ DILUXONE_USERS_MENU ], 0 );
		$this->assertSame( 'Registration', $titles[ $at ] );
		$this->assertSame( 'manage_options', $GLOBALS['submenu'][ DILUXONE_USERS_MENU ][ $at ][1] );
	}

	public function test_c1_the_screen_draws_its_tabs_under_its_own_title(): void {
		$this->the_admin();
		$_GET = array( 'page' => 'diluxone-users-register' );

		$html = $this->draw( 'diluxone_users_screen_registration' );

		$this->assertStringContainsString( 'DiluxOne Users+ | Registration', $html );
		$this->assertArrayHasKey( 'who', diluxone_users_panels( 'diluxone-users-register' ) );
	}
}
