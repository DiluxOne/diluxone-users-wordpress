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

	/* ── The summaries ─────────────────────────────────────────────── */

	/** Registration open through the e-mail link, and the link the way in. */
	private function open_by_the_link(): void {
		diluxone_users_update_option( 'diluxone_users_login_method', 'link' );
		diluxone_users_update_option( 'diluxone_users_login_register', 1 );
		diluxone_users_update_option( 'diluxone_users_sso_register', 0 );
		diluxone_users_update_option( 'diluxone_users_register_form', 0 );
	}

	/** One screen on one tab, drawn. */
	private function screen( string $screen, string $tab ): string {
		$_GET = array(
			'page' => $screen,
			'tab'  => $tab,
		);

		return $this->draw( 'diluxone-users-register' === $screen ? 'diluxone_users_screen_registration' : ( 'diluxone-users-login' === $screen ? 'diluxone_users_screen_login' : 'diluxone_users_screen_home' ) );
	}

	/**
	 * The labels of a summary table's rows.
	 *
	 * @return array<int, string>
	 */
	private function rows( string $html ): array {
		preg_match_all( '#<th scope="row" class="diluxone-users-summary__what">([^<]+)</th>#', $html, $found );

		return array_map( 'html_entity_decode', $found[1] );
	}

	public function test_c4_registrations_summary_and_rail_speak_only_of_registration(): void {
		$this->the_admin();
		$this->open_by_the_link();

		$html = $this->screen( 'diluxone-users-register', 'summary' );

		$this->assertSame( array( 'The e-mail link', 'A social account', 'The site’s own form', 'WordPress’s own form', 'The role given' ), $this->rows( $html ) );
		$this->assertStringContainsString( 'An address the site has never seen gets a link', $html );
		$this->assertStringContainsString( 'Subscriber, whichever door they came through.', $html );
		$this->assertStringNotContainsString( 'Username and password', $html, 'signing in is Access’s' );
		$this->assertStringNotContainsString( 'The sign-in page', $html );
		$this->assertStringNotContainsString( 'After the door', $html );

		$this->assertSame( 1, preg_match( '#<p class="du-state__line">([^<]*)</p>#', $html, $rail ), 'a rail with one line' );
		$this->assertStringStartsWith( 'Open: accounts are created by the e-mail link', html_entity_decode( $rail[1] ) );
		$this->assertStringContainsString( 'diluxone-users-state--active', $html );
	}

	public function test_c4_the_rail_says_nobody_registers_when_every_door_is_shut(): void {
		$this->the_admin();
		$this->open_by_the_link();
		diluxone_users_update_option( 'diluxone_users_login_register', 0 );

		if ( is_multisite() ) {
			update_site_option( 'registration', 'none' );
		}

		$html = $this->screen( 'diluxone-users-register', 'summary' );

		$this->assertStringContainsString( '<p class="du-state__line">Nobody registers themselves: accounts are made under Users.</p>', $html );
	}

	public function test_c4_access_summary_speaks_only_of_signing_in(): void {
		$this->the_admin();
		$this->open_by_the_link();

		$html = $this->screen( 'diluxone-users-login', 'summary' );
		$rows = $this->rows( $html );

		$this->assertContains( 'The e-mail link', $rows );
		$this->assertContains( 'After the door', $rows );
		$this->assertNotContains( 'Creating an account', $rows );
		$this->assertNotContains( 'Registration', $rows );
		$this->assertStringNotContainsString( 'Accounts are created by', $html );
		$this->assertStringNotContainsString( 'diluxone-users-register', $html, 'no row links to registration' );
		$this->assertStringNotContainsString( 'tab=register', $html );
	}

	public function test_c4_overview_shows_signing_in_and_registration_as_two_rows(): void {
		$this->the_admin();
		$this->open_by_the_link();

		$html = $this->draw( 'diluxone_users_panel_doors' );
		$rows = $this->rows( $html );

		$this->assertContains( 'All the doors', $rows );
		$this->assertContains( 'Registration', $rows );
		$this->assertMatchesRegularExpression( '#Open: accounts are created by the e-mail link[^<]*</td>\s*<td class="diluxone-users-summary__change">\s*<a href="[^"]*page=diluxone-users-register">Registration › Summary →</a>#u', $html );
		$this->assertMatchesRegularExpression( '#<a href="[^"]*page=diluxone-users-login">Access › Summary →</a>#u', $html );
	}
}
