<?php
/**
 * The sign-in page of its own: the shape "A page of its own".
 *
 * The plugin answers an address of the site's choosing with a document it
 * draws whole, and every way to the sign-in screen leads there. Every case
 * runs on both topologies; on a network the test site is the hub, where the
 * page lives.
 */

namespace Tests\Integration;

class LoginDoorTest extends IntegrationTestCase {

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

	/** The main query as the door's rule leaves it. */
	private function on_door( string $view ): void {
		$GLOBALS['wp_query']     = new \WP_Query();
		$GLOBALS['wp_the_query'] = $GLOBALS['wp_query'];

		set_query_var( DILUXONE_USERS_DOOR_VAR, $view );
	}

	/** @return array<string, string> The rules the plugin added at the top. */
	private function rules(): array {
		global $wp_rewrite;

		$wp_rewrite->extra_rules_top = array();
		diluxone_users_door_rules();

		return (array) $wp_rewrite->extra_rules_top;
	}

	public function test_c1_the_shape_is_offered_and_answers_its_address(): void {
		$this->assertArrayHasKey( 'own', diluxone_users_login_templates() );
		$this->assertSame( 'sign-in', diluxone_users_login_slug(), 'the default slug' );

		$rules = $this->rules();

		$this->assertSame( 'index.php?diluxone_users_door=login', $rules['^sign\\-in/?$'] ?? '' );
		$this->assertSame( 'index.php?diluxone_users_door=register', $rules['^sign\\-in/register/?$'] ?? '' );
		$this->assertStringContainsString( 'door:sign-in', diluxone_users_rewrite_stamp(), 'a changed slug flushes the rules' );
	}

	public function test_c1_another_shape_adds_no_rule_and_no_page(): void {
		diluxone_users_update_option( 'diluxone_users_login_template', 'card' );

		$this->assertSame( array(), $this->rules() );
		$this->assertStringNotContainsString( 'door:', diluxone_users_rewrite_stamp() );

		$this->on_door( 'login' );
		$this->assertSame( '', diluxone_users_door_view() );
		$this->assertSame( '/theme/index.php', diluxone_users_door_template( '/theme/index.php' ) );
	}

	/**
	 * Defines DONOTCACHEPAGE for good: in a process of its own.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_c1_the_page_is_the_plugins_whole_document(): void {
		$this->as_front_end();
		$this->on_door( 'login' );

		$this->assertSame( DILUXONE_USERS_DIR . 'templates/login-page.php', diluxone_users_door_template( '/theme/index.php' ), 'the plugin’s document, not the theme’s template' );

		$GLOBALS['wp_query']->is_404 = true;
		$this->stays( 'diluxone_users_door_serve', 'a signed-out person stays on the page' );
		$this->assertFalse( is_404(), 'answered 200' );
		$this->assertTrue( defined( 'DONOTCACHEPAGE' ), 'kept out of every cache' );
		$this->assertStringStartsWith( 'Sign in', diluxone_users_door_title( 'Home' ) );

		ob_start();
		include DILUXONE_USERS_DIR . 'templates/login-page.php';
		$html = (string) ob_get_clean();

		$this->assertStringStartsWith( '<!doctype html>', ltrim( $html ) );
		$this->assertStringContainsString( 'diluxone-users-door', $html );
		$this->assertStringContainsString( 'diluxone-users-login-frame--own', $html, 'the form, in its frame' );
		$this->assertStringContainsString( esc_html( get_bloginfo( 'name' ) ), $html, 'the site’s name' );
		$this->assertStringNotContainsString( 'wp-block-template-part', $html, 'no header or footer of the theme' );
	}

	public function test_c1_somebody_signed_in_is_sent_to_their_account(): void {
		$this->as_front_end();
		$this->on_door( 'login' );
		wp_set_current_user( $this->make_user() );

		$this->assertSame( home_url( '/' ), $this->expectRedirect( 'diluxone_users_door_serve' ), 'with no account page, the home page' );

		$page = (int) wp_insert_post(
			array(
				'post_type'   => 'page',
				'post_status' => 'publish',
				'post_title'  => 'My account',
			)
		);
		diluxone_users_update_option( 'diluxone_users_account_page', $page );

		$this->assertSame( get_permalink( $page ), $this->expectRedirect( 'diluxone_users_door_serve' ) );
	}

	public function test_c1_a_view_nobody_offered_is_not_the_door(): void {
		$this->on_door( '../../wp-config' );

		$this->assertSame( '', diluxone_users_door_view() );
		$this->assertSame( '/theme/index.php', diluxone_users_door_template( '/theme/index.php' ) );
	}

	/** A published page, for the roles that hold one. */
	private function page( string $title ): int {
		return (int) wp_insert_post(
			array(
				'post_type'   => 'page',
				'post_status' => 'publish',
				'post_title'  => $title,
			)
		);
	}

	public function test_c2_every_way_to_the_sign_in_screen_points_to_the_page_of_its_own(): void {
		$door = home_url( '/sign-in/' );

		$this->assertSame( $door, diluxone_users_door_url() );
		$this->assertSame( $door, diluxone_users_login_url(), 'diluxone_users_login_url()' );

		// A page still chosen in Sign in › The page is not the door under this shape.
		diluxone_users_update_option( 'diluxone_users_login_page', $this->page( 'Sign in' ) );
		$this->assertSame( $door, diluxone_users_login_url(), 'the page of its own first' );

		// A redirect from the account area: somebody signed out saving their security screen.
		$this->assertSame( $door, $this->expectRedirect( 'diluxone_users_security_submit' ) );

		// And the account area drawn to a stranger links there.
		$this->as_front_end();
		ob_start();
		diluxone_users_account_area();
		$this->assertStringContainsString( esc_url( $door ), (string) ob_get_clean() );

		// wp-login.php is not the only door any more.
		$this->assertNotSame( wp_login_url(), diluxone_users_login_url() );
	}

	public function test_c2_with_plain_permalinks_the_address_is_the_query(): void {
		update_option( 'permalink_structure', '' );

		$this->assertSame( add_query_arg( DILUXONE_USERS_DOOR_VAR, 'login', home_url( '/' ) ), diluxone_users_login_url() );
	}

	public function test_c2_another_shape_keeps_the_page_and_wp_login(): void {
		diluxone_users_update_option( 'diluxone_users_login_template', 'card' );

		$this->assertSame( wp_login_url(), diluxone_users_login_url(), 'no page: wp-login.php' );

		$page = $this->page( 'Sign in' );
		diluxone_users_update_option( 'diluxone_users_login_page', $page );

		$this->assertSame( get_permalink( $page ), diluxone_users_login_url() );
	}

	public function test_c2_a_sign_in_page_still_chosen_sends_people_to_the_page_of_its_own(): void {
		$page = $this->page( 'Sign in' );
		diluxone_users_update_option( 'diluxone_users_login_page', $page );

		$this->as_front_end();
		$GLOBALS['wp_query']     = new \WP_Query( array( 'page_id' => $page ) );
		$GLOBALS['wp_the_query'] = $GLOBALS['wp_query'];
		$_GET                    = array( 'diluxone-users' => 'expired' );

		$this->assertSame( add_query_arg( 'diluxone-users', 'expired', home_url( '/sign-in/' ) ), $this->expectRedirect( 'diluxone_users_door_pages' ) );

		diluxone_users_update_option( 'diluxone_users_login_template', 'plain' );
		$this->stays( 'diluxone_users_door_pages', 'another shape gives the page back' );
	}

	public function test_c2_another_site_of_the_network_points_to_the_hubs_page(): void {
		if ( ! is_multisite() ) {
			$this->markTestSkipped( 'A network has other sites; a single site is its own hub (the cases above).' );
		}

		$door = home_url( '/sign-in/' );
		$site = (int) wp_insert_site(
			array(
				'domain' => (string) get_network()->domain,
				'path'   => '/door-elsewhere/',
			)
		);

		switch_to_blog( $site );

		try {
			$url = diluxone_users_login_url();
		} finally {
			restore_current_blog();
		}

		$this->assertStringStartsWith( $door . '?redirect_to=', $url, 'the hub’s page, carrying the way back' );
	}

	/** Design › The sign-in page saved with this shape and this address. */
	private function save_slug( string $slug ): bool {
		$this->postPanel(
			'diluxone-users-design',
			array(
				'diluxone_users_login_template' => 'own',
				'diluxone_users_login_slug'     => $slug,
			)
		);

		return diluxone_users_design_login_save();
	}

	public function test_c3_a_changed_address_keeps_the_old_one_sending_people_to_the_new(): void {
		$this->save_slug( 'entrar' );

		$this->assertSame( 'entrar', diluxone_users_login_slug() );
		$this->assertSame( array( 'sign-in' ), diluxone_users_login_slug_old() );
		$this->assertSame( home_url( '/entrar/' ), diluxone_users_login_url() );

		$rules = $this->rules();

		$this->assertSame( 'index.php?diluxone_users_door=login', $rules['^entrar/?$'] ?? '' );
		$this->assertSame( 'index.php?diluxone_users_door=moved', $rules['^sign\\-in/?$'] ?? '', '/sign-in/ still answered' );
		$this->assertSame( 'index.php?diluxone_users_door=moved-register', $rules['^sign\\-in/register/?$'] ?? '' );
		$this->assertStringContainsString( 'door:entrar+sign-in', diluxone_users_rewrite_stamp(), 'the rules flushed once more' );

		// Somebody opens /sign-in/, with what a link in an e-mail carried.
		$this->as_front_end();
		$this->on_door( 'moved' );
		$_GET = array( 'diluxone-users' => 'expired' );

		$this->assertSame( add_query_arg( 'diluxone-users', 'expired', home_url( '/entrar/' ) ), $this->expectRedirect( 'diluxone_users_door_serve' ) );

		$this->on_door( 'moved-register' );
		$_GET = array();

		$this->assertSame( home_url( '/entrar/register/' ), $this->expectRedirect( 'diluxone_users_door_serve' ) );
	}

	public function test_c3_the_old_addresses_are_the_newest_ten_and_never_the_current_one(): void {
		for ( $i = 1; $i <= 12; $i++ ) {
			$this->save_slug( 'door-' . $i );
		}

		$old = diluxone_users_login_slug_old();

		$this->assertCount( 10, $old );
		$this->assertSame( 'door-11', $old[0], 'newest first' );
		$this->assertNotContains( 'door-12', $old );

		$this->save_slug( 'door-5' );

		$this->assertSame( 'door-5', diluxone_users_login_slug() );
		$this->assertNotContains( 'door-5', diluxone_users_login_slug_old(), 'going back takes it off the list' );
		$this->assertSame( 'door-12', diluxone_users_login_slug_old()[0] );
	}

	public function test_c3_an_old_address_a_page_takes_later_is_the_pages(): void {
		$this->save_slug( 'entrar' );

		wp_insert_post(
			array(
				'post_type'   => 'page',
				'post_status' => 'publish',
				'post_title'  => 'Sign in',
				'post_name'   => 'sign-in',
			)
		);

		$this->assertSame( array(), diluxone_users_login_slug_old() );
		$this->assertArrayNotHasKey( '^sign\\-in/?$', $this->rules() );
	}

	public function test_c3_a_save_with_no_address_keeps_the_one_stored(): void {
		diluxone_users_update_option( 'diluxone_users_login_slug', 'entrar' );

		$this->postPanel( 'diluxone-users-design', array( 'diluxone_users_login_template' => 'card' ) );
		diluxone_users_design_login_save();

		$this->assertSame( 'entrar', diluxone_users_login_slug(), 'the field waits under another shape and sends nothing' );
		$this->assertSame( array(), diluxone_users_login_slug_old() );
	}
}
