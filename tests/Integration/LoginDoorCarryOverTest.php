<?php
/**
 * A site set up before Access › The sign-in page decided the address alone.
 *
 * Then the address typed on Design won, and its save stored `sign-in` by
 * default, so a site with a page chosen had the page of its own at
 * `/sign-in/` and the page sending people there. Nothing is written when it
 * is updated: what is stored is read the new way. Every case runs on both
 * topologies; on a network the test site is the hub.
 */

namespace Tests\Integration;

class LoginDoorCarryOverTest extends IntegrationTestCase {

	/** @var string The permalink structure before the test. */
	private string $permalinks = '';

	protected function setUp(): void {
		parent::setUp();

		$this->permalinks = (string) get_option( 'permalink_structure' );
		update_option( 'permalink_structure', '/%postname%/' );

		diluxone_users_update_option( 'diluxone_users_login_template', 'own' );
	}

	protected function tearDown(): void {
		update_option( 'permalink_structure', $this->permalinks );

		$GLOBALS['wp_query']     = new \WP_Query();
		$GLOBALS['wp_the_query'] = $GLOBALS['wp_query'];

		parent::tearDown();
	}

	/**
	 * What a site from before this change has stored: no old address, since
	 * the address in force had never changed.
	 *
	 * @param int    $page The sign-in page chosen, 0 for none.
	 * @param string $slug The address stored beside it.
	 */
	private function stored_before( int $page, string $slug ): void {
		diluxone_users_update_option( 'diluxone_users_login_page', $page );
		diluxone_users_update_option( 'diluxone_users_login_slug', $slug );
		diluxone_users_update_option( 'diluxone_users_login_slug_old', array() );
	}

	/** @return array<string, string> The rules the plugin added at the top. */
	private function rules(): array {
		global $wp_rewrite;

		$wp_rewrite->extra_rules_top = array();
		diluxone_users_door_rules();

		return (array) $wp_rewrite->extra_rules_top;
	}

	private function page( string $title, string $name ): int {
		return (int) wp_insert_post(
			array(
				'post_type'   => 'page',
				'post_status' => 'publish',
				'post_title'  => $title,
				'post_name'   => $name,
			)
		);
	}

	public function test_c4_a_page_chosen_and_sign_in_stored_draws_the_page_at_its_address_and_sign_in_sends_people_there(): void {
		$page = $this->page( 'Entrar', 'entrar' );
		$this->stored_before( $page, 'sign-in' );

		$this->assertSame( 'entrar', diluxone_users_login_slug(), 'the page’s own address' );
		$this->assertSame( home_url( '/entrar/' ), diluxone_users_login_url() );
		$this->assertSame( array( 'sign-in' ), diluxone_users_login_slug_old(), '/sign-in/ is an old address' );
		$this->assertSame( array(), diluxone_users_raw_get( 'diluxone_users_login_slug_old' ), 'nothing written' );
		$this->assertSame( 'sign-in', diluxone_users_raw_get( 'diluxone_users_login_slug' ), 'kept for "No page"' );

		$rules = $this->rules();

		$this->assertSame( 'index.php?diluxone_users_door=login', $rules['^entrar/?$'] ?? '' );
		$this->assertSame( 'index.php?diluxone_users_door=moved', $rules['^sign\\-in/?$'] ?? '' );
		$this->assertSame( 'index.php?diluxone_users_door=moved-register', $rules['^sign\\-in/register/?$'] ?? '' );

		// Somebody opens /sign-in/ from a link sent before the update.
		$this->as_front_end();
		$GLOBALS['wp_query']     = new \WP_Query();
		$GLOBALS['wp_the_query'] = $GLOBALS['wp_query'];
		set_query_var( DILUXONE_USERS_DOOR_VAR, 'moved' );
		$_GET   = array( 'diluxone-users' => 'expired' );
		$status = 0;
		$this->hook(
			'wp_redirect',
			static function ( $location, $code ) use ( &$status ) {
				$status = (int) $code;
				return $location;
			},
			0,
			2
		);

		$this->assertSame( add_query_arg( 'diluxone-users', 'expired', get_permalink( $page ) ), $this->expectRedirect( 'diluxone_users_door_serve' ) );
		$this->assertSame( 302, $status );
	}

	public function test_c4_choosing_no_page_later_gives_the_stored_address_back(): void {
		$page = $this->page( 'Entrar', 'entrar' );
		$this->stored_before( $page, 'sign-in' );

		diluxone_users_update_option( 'diluxone_users_login_page', 0 );

		$this->assertSame( 'sign-in', diluxone_users_login_slug() );
		$this->assertNotContains( 'sign-in', diluxone_users_login_slug_old(), 'never the current address' );
	}

	public function test_c4_a_post_at_the_stored_address_keeps_it(): void {
		$page = $this->page( 'Entrar', 'entrar' );
		$this->page( 'Sign in', 'sign-in' );
		$this->stored_before( $page, 'sign-in' );

		$this->assertSame( array(), diluxone_users_login_slug_old() );
		$this->assertArrayNotHasKey( '^sign\\-in/?$', $this->rules(), 'the page at /sign-in/ is answered as a page' );
	}

	public function test_c4_another_site_of_the_network_reads_the_hubs_old_addresses(): void {
		if ( ! is_multisite() ) {
			$this->markTestSkipped( 'A network has other sites; a single site is its own hub (the cases above).' );
		}

		$page = $this->page( 'Entrar', 'entrar' );
		$this->stored_before( $page, 'sign-in' );

		$site = (int) wp_insert_site(
			array(
				'domain' => (string) get_network()->domain,
				'path'   => '/carry-over-elsewhere/',
			)
		);

		switch_to_blog( $site );

		try {
			$slug = diluxone_users_login_slug();
			$old  = diluxone_users_login_slug_old();
		} finally {
			restore_current_blog();
		}

		$this->assertSame( 'entrar', $slug );
		$this->assertSame( array( 'sign-in' ), $old );
	}

	public function test_c5_no_page_and_an_address_typed_keeps_the_address(): void {
		$this->stored_before( 0, 'entrar' );

		$this->assertSame( 'entrar', diluxone_users_login_slug() );
		$this->assertSame( home_url( '/entrar/' ), diluxone_users_login_url() );
		$this->assertSame( array(), diluxone_users_login_slug_old(), 'nothing added to the old addresses' );
		$this->assertSame( array(), diluxone_users_raw_get( 'diluxone_users_login_slug_old' ) );

		$rules = $this->rules();

		$this->assertSame( 'index.php?diluxone_users_door=login', $rules['^entrar/?$'] ?? '' );
		$this->assertArrayNotHasKey( '^sign\\-in/?$', $rules );
	}
}
