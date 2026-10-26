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

	public function test_c1_a_logo_the_site_hides_leaves_the_sites_name_on_the_page(): void {
		$this->as_front_end();
		$this->on_door( 'login' );
		diluxone_users_update_option(
			'diluxone_users_login_logo',
			(int) wp_insert_post(
				array(
					'post_type'      => 'attachment',
					'post_status'    => 'inherit',
					'post_title'     => 'Logo',
					'post_mime_type' => 'image/png',
				)
			)
		);
		diluxone_users_update_option( 'diluxone_users_login_logo_show', 0 );

		ob_start();
		include DILUXONE_USERS_DIR . 'templates/login-page.php';
		$html = (string) ob_get_clean();

		$this->assertStringContainsString( 'diluxone-users-door__site', $html, 'the site’s name, with no mark above the form' );
		$this->assertStringNotContainsString( 'diluxone-users-door__back', $html );
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

	/** The main query as WordPress leaves it for a page asked for by its ID. */
	private function on_page( int $page ): void {
		$GLOBALS['wp_query']     = new \WP_Query( array( 'page_id' => $page ) );
		$GLOBALS['wp_the_query'] = $GLOBALS['wp_query'];
		$GLOBALS['wp_query']->get_queried_object();
	}

	public function test_a_chosen_page_a_stranger_cannot_open_is_linked_by_its_path_not_its_permalink(): void {
		foreach ( array( 'draft', 'pending', 'private' ) as $status ) {
			$page = (int) wp_insert_post(
				array(
					'post_type'   => 'page',
					'post_status' => $status,
					'post_title'  => 'Entrar ' . $status,
					'post_name'   => 'entrar-' . $status,
				)
			);
			diluxone_users_update_option( 'diluxone_users_login_page', $page );

			$this->assertSame( home_url( user_trailingslashit( diluxone_users_login_slug() ) ), diluxone_users_door_url(), "a $status page: the path the door answers" );
			$this->assertStringNotContainsString( 'page_id=', diluxone_users_door_url(), "a $status page: not its ?page_id= permalink" );
		}
	}

	public function test_c1_the_chosen_sign_in_pages_own_address_draws_the_page_of_its_own_with_no_redirect(): void {
		$page = (int) wp_insert_post(
			array(
				'post_type'   => 'page',
				'post_status' => 'publish',
				'post_title'  => 'Entrar',
				'post_name'   => 'entrar',
			)
		);
		diluxone_users_update_option( 'diluxone_users_login_page', $page );

		$this->assertSame( 'entrar', diluxone_users_login_slug(), 'the page’s own path' );
		$this->assertSame( get_permalink( $page ), diluxone_users_door_url(), 'the door is the page’s address' );
		$this->assertSame( home_url( '/entrar/' ), diluxone_users_login_url() );
		$this->assertSame( home_url( '/entrar/register/' ), diluxone_users_door_url( 'register' ) );

		$rules = $this->rules();

		$this->assertSame( 'index.php?diluxone_users_door=login', $rules['^entrar/?$'] ?? '', 'its address is answered by the door' );
		$this->assertSame( 'index.php?diluxone_users_door=register', $rules['^entrar/register/?$'] ?? '' );

		// The page itself asked for, as plain permalinks and ?page_id= ask.
		$this->as_front_end();
		$this->on_page( $page );
		$_GET = array( 'diluxone-users' => 'expired' );

		$this->assertSame( 'login', diluxone_users_door_view() );
		$this->stays( 'diluxone_users_door_pages', 'nothing redirects' );
		$this->stays( 'diluxone_users_door_serve', 'a signed-out person stays on the page' );
		$this->assertSame( DILUXONE_USERS_DIR . 'templates/login-page.php', diluxone_users_door_template( '/theme/page.php' ), 'the page of its own, drawn there' );

		diluxone_users_update_option( 'diluxone_users_login_template', 'plain' );
		$this->assertSame( '', diluxone_users_door_view(), 'another shape gives the page back' );
		$this->assertSame( '/theme/page.php', diluxone_users_door_template( '/theme/page.php' ) );
	}

	public function test_c1_a_nested_sign_in_page_is_drawn_at_its_whole_path(): void {
		$parent = $this->page( 'Members' );
		$page   = (int) wp_insert_post(
			array(
				'post_type'   => 'page',
				'post_status' => 'publish',
				'post_title'  => 'Entrar',
				'post_name'   => 'entrar',
				'post_parent' => $parent,
			)
		);

		diluxone_users_update_option( 'diluxone_users_login_page', $page );

		$this->assertSame( 'members/entrar', diluxone_users_login_slug() );
		$this->assertSame( home_url( '/members/entrar/' ), diluxone_users_login_url() );

		$rules = $this->rules();

		$this->assertSame( 'index.php?diluxone_users_door=login', $rules['^members\\/entrar/?$'] ?? '' );
		$this->assertSame( 'index.php?diluxone_users_door=register', $rules['^members\\/entrar/register/?$'] ?? '' );

		foreach ( array_keys( $rules ) as $rule ) {
			$this->assertDoesNotMatchRegularExpression( '#' . $rule . '#', 'members/', 'the parent page keeps its address' );
		}

		$this->as_front_end();
		$this->on_page( $page );
		$this->stays( 'diluxone_users_door_pages', 'nothing redirects' );
		$this->assertSame( 'login', diluxone_users_door_view() );

		$this->on_page( $parent );
		$this->assertSame( '', diluxone_users_door_view(), 'the parent is not the door' );
	}

	public function test_c1_with_plain_permalinks_the_door_is_the_chosen_page_itself(): void {
		global $wp_rewrite;

		update_option( 'permalink_structure', '' );
		$wp_rewrite->init();
		$page = $this->page( 'Entrar' );
		diluxone_users_update_option( 'diluxone_users_login_page', $page );

		try {
			$this->assertSame( add_query_arg( 'page_id', $page, home_url( '/' ) ), diluxone_users_login_url() );
		} finally {
			update_option( 'permalink_structure', '/%postname%/' );
			$wp_rewrite->init();
		}
	}

	public function test_c1_a_page_in_the_bin_leaves_the_address_with_no_page(): void {
		$page = $this->page( 'Entrar' );
		diluxone_users_update_option( 'diluxone_users_login_page', $page );
		wp_trash_post( $page );

		$this->assertSame( 'sign-in', diluxone_users_login_slug() );
	}

	public function test_c1_the_chosen_page_renamed_keeps_its_old_path_sending_people_to_the_new(): void {
		$parent = $this->page( 'Members' );
		$page   = (int) wp_insert_post(
			array(
				'post_type'   => 'page',
				'post_status' => 'publish',
				'post_title'  => 'Entrar',
				'post_name'   => 'entrar',
			)
		);
		diluxone_users_update_option( 'diluxone_users_login_page', $page );
		diluxone_users_update_option( 'diluxone_users_login_slug_old', array() );

		wp_update_post(
			array(
				'ID'        => $page,
				'post_name' => 'acceder',
			)
		);

		$this->assertSame( 'acceder', diluxone_users_login_slug() );
		$this->assertSame( array( 'entrar' ), diluxone_users_login_slug_old() );

		wp_update_post(
			array(
				'ID'          => $page,
				'post_parent' => $parent,
			)
		);
		wp_update_post(
			array(
				'ID'        => $parent,
				'post_name' => 'socios',
			)
		);

		$this->assertSame( 'socios/acceder', diluxone_users_login_slug() );
		$this->assertSame( array( 'members/acceder', 'acceder', 'entrar' ), diluxone_users_login_slug_old(), 'a parent renamed moves it too' );
	}

	public function test_c1_another_page_chosen_keeps_the_address_it_leaves(): void {
		diluxone_users_update_option( 'diluxone_users_login_slug_old', array() );

		$first = $this->page( 'Entrar' );
		diluxone_users_update_option( 'diluxone_users_login_page', $first );

		$this->assertSame( array( 'sign-in' ), diluxone_users_login_slug_old(), '/sign-in/ sends people to the page chosen' );

		$second = $this->page( 'Acceso' );
		diluxone_users_update_option( 'diluxone_users_login_page', $second );

		$this->assertSame( 'acceso', diluxone_users_login_slug() );
		$this->assertSame( array( 'sign-in' ), diluxone_users_login_slug_old(), 'the first page is still a page, answered as one' );

		diluxone_users_update_option( 'diluxone_users_login_template', 'card' );
		diluxone_users_update_option( 'diluxone_users_login_page', $first );
		$this->assertSame( array( 'sign-in' ), diluxone_users_login_slug_old(), 'nothing kept under another shape' );
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

	/** Access › The sign-in page saved with "No page" and this address. */
	private function save_slug( string $slug ): bool {
		$this->postPanel(
			'diluxone-users-login',
			array(
				'diluxone_users_login_page' => '0',
				'diluxone_users_login_slug' => $slug,
			)
		);

		ob_start();
		$saved = diluxone_users_login_page_save();
		ob_end_clean();

		return $saved;
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

		$this->assertSame( add_query_arg( 'diluxone-users', 'expired', home_url( '/entrar/' ) ), $this->expectRedirect( 'diluxone_users_door_serve' ) );
		$this->assertSame( 302, $status, 'not a 301 a browser keeps, which a slug set back would loop' );
		$this->assertTrue( defined( 'DONOTCACHEPAGE' ), 'kept by no cache' );

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

	public function test_c3_the_design_tab_saved_writes_no_address(): void {
		diluxone_users_update_option( 'diluxone_users_login_slug', 'entrar' );

		$this->postPanel(
			'diluxone-users-design',
			array(
				'diluxone_users_login_template' => 'own',
				'diluxone_users_login_slug'     => 'acceso',
			)
		);
		diluxone_users_design_login_save();

		$this->assertSame( 'entrar', diluxone_users_login_slug(), 'Design only says where it is drawn' );
		$this->assertSame( array(), diluxone_users_login_slug_old() );
	}

	/** What the save said when it refused the address. */
	private function refused( string $slug ): string {
		$this->postPanel(
			'diluxone-users-login',
			array(
				'diluxone_users_login_page' => '0',
				'diluxone_users_login_slug' => $slug,
			)
		);

		ob_start();
		$saved = diluxone_users_login_page_save();
		$said  = (string) ob_get_clean();

		$this->assertFalse( $saved, $slug . ' was saved' );
		$this->assertStringContainsString( 'notice-error', $said );

		return $said;
	}

	public function test_c4_an_address_a_page_already_has_is_refused_naming_the_page(): void {
		wp_insert_post(
			array(
				'post_type'   => 'page',
				'post_status' => 'publish',
				'post_title'  => 'About us',
				'post_name'   => 'about',
			)
		);

		$said = $this->refused( 'about' );

		$this->assertStringContainsString( 'The address /about/ is already the page “About us”.', $said );
		$this->assertSame( 'sign-in', diluxone_users_login_slug() );
		$this->assertSame( array(), diluxone_users_login_slug_old() );
	}

	public function test_c4_a_draft_or_a_post_counts_and_the_bin_does_not(): void {
		$draft = (int) wp_insert_post(
			array(
				'post_type'   => 'post',
				'post_status' => 'draft',
				'post_title'  => 'Coming soon',
				'post_name'   => 'soon',
			)
		);

		$this->assertStringContainsString( 'is already the post “Coming soon”', $this->refused( 'soon' ) );

		wp_trash_post( $draft );

		$this->assertTrue( $this->save_slug( 'soon' ), 'a post in the bin has no address' );
		$this->assertSame( 'soon', diluxone_users_login_slug() );
	}

	public function test_c4_what_wordpress_or_the_site_answers_is_refused(): void {
		$account = (int) wp_insert_post(
			array(
				'post_type'   => 'page',
				'post_status' => 'publish',
				'post_title'  => 'My account',
				'post_name'   => 'my-account',
			)
		);
		diluxone_users_update_option( 'diluxone_users_account_page', $account );

		foreach ( array( 'wp-admin', 'wp-json', 'feed', 'category', 'tag', 'search', 'my-account' ) as $slug ) {
			$this->assertStringContainsString( 'The address', $this->refused( $slug ), $slug );
		}

		$this->assertStringContainsString( 'one word, with no slashes', $this->refused( 'a/b' ) );
		$this->assertStringContainsString( 'needs an address', $this->refused( '///' ) );
		$this->assertSame( 'sign-in', diluxone_users_login_slug(), 'nothing written' );
	}

	public function test_c4_a_site_path_of_a_subdirectory_network_is_refused(): void {
		if ( ! is_multisite() || is_subdomain_install() ) {
			$this->markTestSkipped( 'Only a subdirectory network has site paths; on a single site the cases above are its reserved addresses.' );
		}

		wp_insert_site(
			array(
				'domain' => (string) get_network()->domain,
				'path'   => '/entrada/',
			)
		);

		$this->assertStringContainsString( 'already answers', $this->refused( 'entrada' ) );
		$this->assertStringContainsString( 'already answers', $this->refused( 'blog' ) );
	}

	public function test_c4_the_chosen_sign_in_pages_own_address_is_not_taken_on_the_status_screen(): void {
		$page = (int) wp_insert_post(
			array(
				'post_type'   => 'page',
				'post_status' => 'publish',
				'post_title'  => 'Entrar',
				'post_name'   => 'entrar',
			)
		);

		diluxone_users_update_option( 'diluxone_users_login_template', 'own' );
		diluxone_users_update_option( 'diluxone_users_login_page', $page );

		$this->assertSame( '', diluxone_users_login_slug_taken( 'entrar' ), 'the page the page of its own takes the place of' );
		$this->assertSame( 'active', diluxone_users_check_door()['state'] );

		diluxone_users_update_option( 'diluxone_users_login_page', 0 );

		$this->assertSame( 'the page “Entrar”', diluxone_users_login_slug_taken( 'entrar' ), 'any other page still has its address' );
	}

	public function test_c4_a_good_address_is_saved_clean(): void {
		$this->assertTrue( $this->save_slug( '/Entrar/' ) );

		$this->assertSame( 'entrar', diluxone_users_login_slug() );
	}

	public function test_c5_the_second_step_is_drawn_on_the_page_of_its_own(): void {
		$user  = $this->make_user();
		$nonce = diluxone_users_2fa_pending_start( $user, 'password', false, '' );

		$this->assertSame( 'page', diluxone_users_2fa_surface(), 'with no page chosen, the page of its own draws it' );

		$url = diluxone_users_2fa_url( $user, $nonce, 'email' );

		$this->assertStringStartsWith( home_url( '/sign-in/?' ), $url );
		$this->assertStringStartsWith( home_url( '/sign-in/?' ), diluxone_users_2fa_restart_url() );

		$this->as_front_end();
		$this->on_door( 'login' );
		parse_str( (string) wp_parse_url( $url, PHP_URL_QUERY ), $_GET );

		$html = diluxone_users_door_form();

		$this->assertStringContainsString( 'diluxone-users-login-frame--own', $html );
		$this->assertStringContainsString( 'One more step', $html, 'the second step, not the sign-in form' );
	}

	public function test_c5_a_reset_lands_and_is_drawn_on_the_page_of_its_own(): void {
		$user  = $this->make_user();
		$login = get_userdata( $user )->user_login;
		$key   = (string) get_password_reset_key( get_userdata( $user ) );

		diluxone_users_update_option( 'diluxone_users_lost_password', 'site' );

		// The link in WordPress's e-mail, opened on wp-login.php, is handed on.
		$_GET = array(
			'action' => 'rp',
			'key'    => $key,
			'login'  => $login,
		);

		$to = $this->expectRedirect( 'diluxone_users_reset_to_site' );

		$this->assertStringStartsWith( home_url( '/sign-in/?' ), $to );

		// There the key goes into a cookie, and the page draws the new password.
		$_GET                    = array();
		$_COOKIE                 = array( diluxone_users_reset_cookie() => $login . ':' . $key );
		$this->as_front_end();
		$this->on_door( 'login' );

		$html = diluxone_users_door_form();

		$this->assertStringContainsString( 'diluxone-users-login-frame--own', $html );
		$this->assertStringContainsString( 'Choose a new password', $html );
		$this->assertStringStartsWith( home_url( '/sign-in/' ), diluxone_users_login_url(), 'and the save ends there' );
	}

	public function test_c5_registration_is_at_register_on_the_same_document(): void {
		diluxone_users_update_option( 'diluxone_users_register_form', 1 );

		$this->assertSame( home_url( '/sign-in/register/' ), diluxone_users_register_url() );
		$this->assertTrue( diluxone_users_register_form_open(), 'a form with a door, and no page' );

		$this->as_front_end();
		$this->on_door( 'register' );

		$this->assertSame( DILUXONE_USERS_DIR . 'templates/login-page.php', diluxone_users_door_template( '/theme/index.php' ) );
		$this->assertStringStartsWith( 'Create your account', diluxone_users_door_title( 'Home' ) );

		$html = diluxone_users_door_form();

		$this->assertStringContainsString( 'diluxone-users-login-frame--own', $html );
		$this->assertStringContainsString( 'diluxone-users-register', $html );
	}

	public function test_c5_a_registration_page_still_chosen_sends_people_to_the_page_of_its_own(): void {
		$page = $this->page( 'Create your account' );
		diluxone_users_update_option( 'diluxone_users_register_page', $page );

		$this->as_front_end();
		$GLOBALS['wp_query']     = new \WP_Query( array( 'page_id' => $page ) );
		$GLOBALS['wp_the_query'] = $GLOBALS['wp_query'];

		$this->assertSame( home_url( '/sign-in/register/' ), $this->expectRedirect( 'diluxone_users_door_pages' ) );
	}

	public function test_safe_mode_shuts_the_page_of_its_own(): void {
		$this->hook( 'diluxone_users_safe_mode', '__return_true' );

		$this->assertSame( wp_login_url(), diluxone_users_login_url(), 'wp-login.php is the way in' );
		$this->assertSame( '', diluxone_users_register_url() );

		$this->as_front_end();
		$this->on_door( 'login' );
		wp_set_current_user( $this->make_user() );

		$this->stays( 'diluxone_users_door_serve', 'nobody signed in is sent anywhere' );
		$this->assertSame( '/theme/index.php', diluxone_users_door_template( '/theme/index.php' ), 'the plugin draws no document' );

		$this->on_door( 'moved' );
		$this->stays( 'diluxone_users_door_serve', 'an old address redirects nowhere' );

		$page = $this->page( 'Sign in' );
		diluxone_users_update_option( 'diluxone_users_login_page', $page );
		$GLOBALS['wp_query']     = new \WP_Query( array( 'page_id' => $page ) );
		$GLOBALS['wp_the_query'] = $GLOBALS['wp_query'];

		$this->stays( 'diluxone_users_door_pages', 'a sign-in page still chosen draws itself' );
		$this->assertSame( '/theme/page.php', diluxone_users_door_template( '/theme/page.php' ), 'and is drawn as the page it is' );
	}
}
