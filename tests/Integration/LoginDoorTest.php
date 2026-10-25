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

	/** @var string The site's language before the test. */
	private string $language = '';

	/** @var bool Whether the request is answered in Spanish (in_spanish()). */
	private bool $spanish = false;

	protected function setUp(): void {
		parent::setUp();

		$this->permalinks = (string) get_option( 'permalink_structure' );
		update_option( 'permalink_structure', '/%postname%/' );

		$this->language = (string) get_option( 'WPLANG' );

		// The translations the plugin ships, read the way a site with its
		// language packs reads them.
		$this->hook(
			'lang_dir_for_domain',
			static fn( $path, string $domain ) => 'diluxone-users' === $domain ? DILUXONE_USERS_DIR . 'languages/' : $path,
			10,
			2
		);

		diluxone_users_update_option( 'diluxone_users_login_template', 'own' );
	}

	protected function tearDown(): void {
		update_option( 'permalink_structure', $this->permalinks );
		update_option( 'WPLANG', $this->language );
		unload_textdomain( 'diluxone-users', true );

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

	/** What WordPress matches a path against first, of every rule it has. */
	private function first_match( string $path ): string {
		global $wp_rewrite;

		$this->rules();

		foreach ( (array) $wp_rewrite->rewrite_rules() as $match => $query ) {
			if ( preg_match( '#^' . $match . '#', $path ) ) {
				return (string) $query;
			}
		}

		return '';
	}

	public function test_c1_with_no_address_typed_the_chosen_sign_in_page_gives_its_own(): void {
		$page = (int) wp_insert_post(
			array(
				'post_type'   => 'page',
				'post_status' => 'publish',
				'post_title'  => 'Entrar',
				'post_name'   => 'entrar',
			)
		);
		diluxone_users_update_option( 'diluxone_users_login_page', $page );

		$this->assertSame( '', (string) diluxone_users_option( 'diluxone_users_login_slug' ), '“Its address” is empty' );
		$this->assertSame( 'entrar', diluxone_users_login_slug(), 'the address of the page chosen' );
		$this->assertSame( home_url( '/entrar/' ), diluxone_users_login_url() );
		$this->assertSame( 'index.php?diluxone_users_door=login', $this->first_match( 'entrar/' ), '/entrar/ is the page of its own before it is the page' );
		$this->assertSame( array(), diluxone_users_login_slug_old(), 'no old address sending anybody anywhere' );
		$this->assertSame( '', diluxone_users_login_slug_taken( 'entrar' ), 'the page chosen does not count as taking it' );
		$this->assertSame( 'active', diluxone_users_check_door()['state'], 'the Status screen does not call it taken' );

		$this->as_front_end();
		$this->on_door( 'login' );
		$this->stays( 'diluxone_users_door_serve', 'answered there, not redirected' );
		$this->assertSame( DILUXONE_USERS_DIR . 'templates/login-page.php', diluxone_users_door_template( '/theme/index.php' ) );

		// A page saved later leaves the address where it is.
		diluxone_users_login_slug_old_forget();
		$this->assertSame( 'entrar', diluxone_users_login_slug() );
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

	/**
	 * The site set to Spanish, and the request answered in it, as the front
	 * end is: without needing WordPress's own Spanish installed to switch to.
	 */
	private function in_spanish( bool $spanish = true ): void {
		update_option( 'WPLANG', $spanish ? 'es_ES' : '' );

		if ( ! $this->spanish && $spanish ) {
			$this->hook( 'determine_locale', fn( string $locale ): string => $this->spanish ? 'es_ES' : $locale );
		}

		$this->spanish = $spanish;

		// Read again in the language now: what was read before stays otherwise.
		unload_textdomain( 'diluxone-users', true );
	}

	public function test_c2_with_nothing_chosen_a_site_in_spanish_gets_the_address_in_spanish(): void {
		$this->in_spanish();

		$this->assertSame( 'acceder', diluxone_users_login_slug() );
		$this->assertSame( home_url( '/acceder/' ), diluxone_users_login_url() );
		$this->assertSame( 'index.php?diluxone_users_door=login', $this->rules()['^acceder/?$'] ?? '' );
		$this->assertArrayNotHasKey( '^sign\\-in/?$', $this->rules(), 'not /sign-in/' );
	}

	public function test_c2_the_sites_language_and_not_that_of_whoever_is_looking(): void {
		if ( ! in_array( 'es_ES', get_available_languages(), true ) ) {
			$this->markTestSkipped( 'WordPress switches only to a language it has installed: `make env` installs es_ES.' );
		}

		update_option( 'WPLANG', 'es_ES' );

		$this->assertNotSame( 'es_ES', determine_locale(), 'whoever is looking is not in Spanish' );
		$this->assertSame( 'acceder', diluxone_users_login_slug(), 'the site’s language, not theirs' );
		$this->assertFalse( is_locale_switched(), 'the language put back' );
		$this->assertSame( 'Its address', __( 'Its address', 'diluxone-users' ), 'and the plugin’s words with it' );

		// While a notice is written in its recipient's language, the address
		// is still the site's.
		update_option( 'WPLANG', '' );
		switch_to_locale( 'es_ES' );

		try {
			$this->assertSame( 'sign-in', diluxone_users_login_slug() );
		} finally {
			restore_previous_locale();
		}
	}

	public function test_c2_the_screen_says_the_address_left_empty_gives(): void {
		$this->in_spanish();

		ob_start();
		diluxone_users_screen_login_door( 'own' );
		$html = (string) ob_get_clean();

		$this->assertStringContainsString( 'value=""', $html, 'empty, as stored' );
		$this->assertStringContainsString( 'placeholder="acceder"', $html );
		$this->assertStringContainsString( '<code>/acceder/</code>', $html, 'the line under the field' );
		$this->assertStringNotContainsString( ' required', $html, 'empty is an answer' );
	}

	public function test_c2_a_field_saved_empty_is_the_default(): void {
		ob_start();
		$this->assertTrue( $this->save_slug( 'entrar' ) );
		$this->assertTrue( $this->save_slug( '' ), 'empty is not refused' );
		ob_end_clean();

		$this->assertSame( '', (string) diluxone_users_option( 'diluxone_users_login_slug' ) );
		$this->assertSame( 'sign-in', diluxone_users_login_slug() );
		$this->assertSame( array( 'entrar' ), diluxone_users_login_slug_old(), '/entrar/ sends people to the default' );
	}

	public function test_c3_an_english_site_with_nothing_chosen_is_at_sign_in_as_before(): void {
		update_option( 'WPLANG', '' );

		$this->assertSame( 0, diluxone_users_page_here( 'diluxone_users_login_page' ), 'no page chosen' );
		$this->assertSame( '', (string) diluxone_users_option( 'diluxone_users_login_slug' ), 'nothing typed' );
		$this->assertSame( 'sign-in', diluxone_users_login_slug() );
		$this->assertSame( home_url( '/sign-in/' ), diluxone_users_login_url() );
		$this->assertSame( 'index.php?diluxone_users_door=login', $this->rules()['^sign\\-in/?$'] ?? '' );

		ob_start();
		diluxone_users_screen_login_door( 'own' );
		$this->assertStringContainsString( '<code>/sign-in/</code>', (string) ob_get_clean() );
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

	public function test_c3_a_save_with_no_address_keeps_the_one_stored(): void {
		diluxone_users_update_option( 'diluxone_users_login_slug', 'entrar' );

		$this->postPanel( 'diluxone-users-design', array( 'diluxone_users_login_template' => 'card' ) );
		diluxone_users_design_login_save();

		$this->assertSame( 'entrar', diluxone_users_login_slug(), 'the field waits under another shape and sends nothing' );
		$this->assertSame( array(), diluxone_users_login_slug_old() );
	}

	/** The save, with what it printed. */
	private function refused( string $slug ): string {
		ob_start();
		$saved = $this->save_slug( $slug );
		$said  = (string) ob_get_clean();

		$this->assertFalse( $saved, $slug . ' was saved' );

		return $said;
	}

	public function test_c4_an_address_typed_wins_over_both_defaults(): void {
		$this->in_spanish();

		$page = (int) wp_insert_post(
			array(
				'post_type'   => 'page',
				'post_status' => 'publish',
				'post_title'  => 'Entrar',
				'post_name'   => 'entrar',
			)
		);
		diluxone_users_update_option( 'diluxone_users_login_page', $page );
		diluxone_users_update_option( 'diluxone_users_login_slug', 'puerta' );

		$this->assertSame( 'puerta', diluxone_users_login_slug() );
		$this->assertSame( home_url( '/puerta/' ), diluxone_users_login_url() );

		$rules = $this->rules();

		$this->assertSame( 'index.php?diluxone_users_door=login', $rules['^puerta/?$'] ?? '' );
		$this->assertArrayNotHasKey( '^entrar/?$', $rules, 'not the page’s address' );
		$this->assertArrayNotHasKey( '^acceder/?$', $rules, 'nor the translated one' );

		// The page chosen is served at its own address, and sends people to it.
		$this->as_front_end();
		$GLOBALS['wp_query']     = new \WP_Query( array( 'page_id' => $page ) );
		$GLOBALS['wp_the_query'] = $GLOBALS['wp_query'];

		$this->assertSame( home_url( '/puerta/' ), $this->expectRedirect( 'diluxone_users_door_pages' ) );

		ob_start();
		diluxone_users_screen_login_door( 'own' );
		$html = (string) ob_get_clean();

		$this->assertStringContainsString( 'value="puerta"', $html, 'what was typed, as typed' );
		$this->assertStringContainsString( '<code>/entrar/</code>', $html, 'and the address emptying it would give' );
	}

	public function test_c4_an_address_a_page_already_has_is_refused_naming_the_page(): void {
		diluxone_users_update_option( 'diluxone_users_login_template', 'card' );

		wp_insert_post(
			array(
				'post_type'   => 'page',
				'post_status' => 'publish',
				'post_title'  => 'About us',
				'post_name'   => 'about',
			)
		);

		$said = $this->refused( 'about' );

		$this->assertStringContainsString( 'notice-error', $said );
		$this->assertStringContainsString( 'The address /about/ is already the page “About us”.', $said );
		$this->assertSame( 'card', diluxone_users_option( 'diluxone_users_login_template' ), 'nothing of the tab written' );
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

		ob_start();
		$this->assertTrue( $this->save_slug( 'soon' ), 'a post in the bin has no address' );
		ob_end_clean();
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
			$this->assertStringContainsString( 'notice-error', $this->refused( $slug ), $slug );
		}

		$this->assertStringContainsString( 'one word, with no slashes', $this->refused( 'a/b' ) );
		$this->assertStringContainsString( 'needs an address', $this->refused( '!!!' ) );
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

	public function test_c4_a_good_address_is_saved_clean(): void {
		ob_start();
		$this->assertTrue( $this->save_slug( '/Entrar/' ) );
		ob_end_clean();

		$this->assertSame( 'entrar', diluxone_users_login_slug() );
	}

	public function test_c5_a_language_changed_leaves_the_old_default_sending_people_to_the_new(): void {
		update_option( 'WPLANG', '' );
		diluxone_users_login_slug_follow();

		$this->assertSame( 'sign-in', diluxone_users_option( 'diluxone_users_login_slug_was' ), 'the address the rules are built for' );
		$this->assertSame( array(), diluxone_users_login_slug_old(), 'the first one moves nothing' );

		$this->in_spanish();
		diluxone_users_login_slug_follow();

		$this->assertSame( 'acceder', diluxone_users_login_slug() );
		$this->assertSame( array( 'sign-in' ), diluxone_users_login_slug_old() );
		$this->assertSame( 'acceder', diluxone_users_option( 'diluxone_users_login_slug_was' ) );
		$this->assertSame( 'index.php?diluxone_users_door=moved', $this->rules()['^sign\\-in/?$'] ?? '' );
		$this->assertStringContainsString( 'door:acceder+sign-in', diluxone_users_rewrite_stamp(), 'the rules flushed once' );

		$this->as_front_end();
		$this->on_door( 'moved' );
		$_GET = array( 'diluxone-users' => 'expired' );

		$this->assertSame( add_query_arg( 'diluxone-users', 'expired', home_url( '/acceder/' ) ), $this->expectRedirect( 'diluxone_users_door_serve' ) );

		// Back to English: /sign-in/ is the page again, and /acceder/ sends people there.
		$this->in_spanish( false );
		diluxone_users_login_slug_follow();

		$this->assertSame( array( 'acceder' ), diluxone_users_login_slug_old() );
	}

	public function test_c5_nothing_follows_while_another_shape_is_chosen(): void {
		diluxone_users_update_option( 'diluxone_users_login_template', 'card' );
		diluxone_users_login_slug_follow();

		$this->assertNull( diluxone_users_raw_get( 'diluxone_users_login_slug_was', null ), 'nothing written' );
	}

	public function test_c5_a_chosen_page_renamed_or_replaced(): void {
		$page = $this->page( 'Entrar' );
		wp_update_post(
			array(
				'ID'        => $page,
				'post_name' => 'entrar',
			)
		);
		diluxone_users_update_option( 'diluxone_users_login_page', $page );
		diluxone_users_login_slug_follow();

		wp_update_post(
			array(
				'ID'        => $page,
				'post_name' => 'acceso',
			)
		);
		diluxone_users_login_slug_follow();

		$this->assertSame( 'acceso', diluxone_users_login_slug() );
		$this->assertSame( array( 'entrar' ), diluxone_users_login_slug_old(), 'renamed: the old address sends people on' );

		// Another page chosen: the one before is still there, at its address.
		diluxone_users_update_option( 'diluxone_users_login_page', $this->page( 'Ingresar' ) );
		diluxone_users_login_slug_follow();

		$this->assertSame( 'ingresar', diluxone_users_login_slug() );
		$this->assertSame( array( 'entrar' ), diluxone_users_login_slug_old(), '/acceso/ is the page’s own' );
	}

	public function test_c5_a_typed_change_is_not_counted_twice(): void {
		diluxone_users_login_slug_follow();

		ob_start();
		$this->save_slug( 'puerta' );
		ob_end_clean();

		diluxone_users_login_slug_follow();

		$this->assertSame( 'puerta', diluxone_users_option( 'diluxone_users_login_slug_was' ) );
		$this->assertSame( array( 'sign-in' ), diluxone_users_login_slug_old() );
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
	}
}
