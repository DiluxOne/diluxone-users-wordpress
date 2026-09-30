<?php
/**
 * The hub: on a network every door of every site leads to one site, and back.
 *
 * Every case is played on both topologies. On a network, from a second site
 * of it: each door's address, what WordPress's own wp-login.php does there,
 * the return address carried through every way in and spent once, social
 * sign-in and passkeys belonging to the hub, and a site on a domain of its
 * own. On a single site, the same calls, and the answer that nothing is sent
 * anywhere: the site is its own hub.
 */

namespace Tests\Integration;

use Tests\Integration\Support\MockProvider;

class NetworkHubTest extends IntegrationTestCase {

	/** @var array<int, int> Sites made by the test, deleted after it. */
	private array $sites = array();

	/** @var array<int, int> Pages made by the test, on the hub. */
	private array $pages = array();

	/** The second site of the network, the one people are sent from. */
	private int $beta = 0;

	/** @var array<string, string|null> The request's host and address, as the test found them. */
	private array $server = array();

	/** The script WordPress thinks is running, as the test found it. */
	private ?string $pagenow = null;

	protected function setUp(): void {
		parent::setUp();

		diluxone_users_update_option( 'diluxone_users_2fa_mode', 'off' );
		diluxone_users_update_option( 'diluxone_users_login_method', 'both' );
		diluxone_users_update_option( 'diluxone_users_login_register', 1 );
		diluxone_users_update_option( 'diluxone_users_register_form', 1 );
		diluxone_users_update_option( 'diluxone_users_login_page', $this->page( 'Hub sign-in' ) );
		diluxone_users_update_option( 'diluxone_users_register_page', $this->page( 'Hub register' ) );
		diluxone_users_update_option( 'diluxone_users_account_page', $this->page( 'Hub account' ) );

		if ( is_multisite() ) {
			$this->beta = $this->site( (string) get_network()->domain, '/hub-beta-' . strtolower( wp_generate_password( 6, false ) ) . '/' );
		}
	}

	protected function tearDown(): void {
		while ( is_multisite() && ms_is_switched() ) {
			restore_current_blog();
		}

		foreach ( $this->pages as $page ) {
			wp_delete_post( $page, true );
		}

		foreach ( $this->sites as $site ) {
			wp_delete_site( $site );
		}

		foreach ( $this->server as $key => $value ) {
			if ( null === $value ) {
				unset( $_SERVER[ $key ] );
			} else {
				$_SERVER[ $key ] = $value;
			}
		}

		if ( null !== $this->pagenow ) {
			$GLOBALS['pagenow'] = $this->pagenow;
			remove_filter( 'wp_doing_ajax', '__return_false' );
		}

		MockProvider::remove();

		parent::tearDown();
	}

	private function network_only(): void {
		if ( ! is_multisite() ) {
			$this->markTestSkipped( 'Needs a multisite network: the single-site half of this case is in this same class.' );
		}
	}

	private function single_only(): void {
		if ( is_multisite() ) {
			$this->markTestSkipped( 'Needs a single site: the network half of this case is in this same class.' );
		}
	}

	/** A published page on the current site — the hub, when the test makes it. */
	private function page( string $title ): int {
		$page = (int) wp_insert_post(
			array(
				'post_type'   => 'page',
				'post_status' => 'publish',
				'post_title'  => $title,
			)
		);

		$this->pages[] = $page;

		return $page;
	}

	private function site( string $domain, string $path ): int {
		$site = (int) wp_insert_site(
			array(
				'domain' => $domain,
				'path'   => $path,
				'title'  => 'Hub test site',
			)
		);

		$this->assertGreaterThan( 0, $site );
		$this->sites[] = $site;

		return $site;
	}

	/**
	 * Pretends the request is a page of the current site, at this path under its home.
	 *
	 * The suite's bootstrap runs every test as an AJAX request on
	 * admin-ajax.php; a page is neither, and both are put back in tearDown().
	 */
	private function standing_on( string $path ): string {
		if ( null === $this->pagenow ) {
			$this->pagenow = (string) ( $GLOBALS['pagenow'] ?? '' );
			add_filter( 'wp_doing_ajax', '__return_false' );
		}

		$GLOBALS['pagenow'] = 'index.php';

		$home = (string) wp_parse_url( home_url( '/' ), PHP_URL_PATH );
		$host = (string) wp_parse_url( home_url(), PHP_URL_HOST );
		$port = wp_parse_url( home_url(), PHP_URL_PORT );

		foreach ( array( 'HTTP_HOST', 'REQUEST_URI' ) as $key ) {
			if ( ! array_key_exists( $key, $this->server ) ) {
				$this->server[ $key ] = isset( $_SERVER[ $key ] ) ? (string) $_SERVER[ $key ] : null;
			}
		}

		$_SERVER['HTTP_HOST']   = $host . ( $port ? ':' . $port : '' );
		$_SERVER['REQUEST_URI'] = $home . ltrim( $path, '/' );

		return home_url( '/' . ltrim( $path, '/' ) );
	}

	private function hub_login(): string {
		return (string) get_permalink( (int) diluxone_users_option( 'diluxone_users_login_page' ) );
	}

	private function hub_wp_login(): string {
		return get_site_url( get_main_site_id(), 'wp-login.php', 'login' );
	}

	/** Runs something that ends in a redirect only when a door is sent elsewhere; returns where, or ''. */
	private function maybe_redirect( callable $handler ): string {
		try {
			$handler();
		} catch ( Support\RedirectException $e ) {
			return $e->url;
		}

		return '';
	}

	/** Calls a wp-login.php request on the current site as `login_init` would, at the hub's hook. */
	private function wp_login( array $get, string $method = 'GET' ): string {
		$_GET                      = $get;
		$_SERVER['REQUEST_METHOD'] = $method;

		return $this->maybe_redirect( 'diluxone_users_wp_login_to_hub' );
	}

	/** Holds a return address on the hub, as opening its sign-in page with one would. */
	private function arrive_with( string $return ): void {
		$_GET = array( 'redirect_to' => $return );
		diluxone_users_return_capture();
		$_GET = array();
	}

	/* ── Every door, from a site that is not the hub ─────────────────── */

	public function test_every_door_on_another_site_leads_to_the_hub_with_the_way_back(): void {
		$this->network_only();

		$login    = $this->hub_login();
		$register = (string) get_permalink( (int) diluxone_users_option( 'diluxone_users_register_page' ) );
		$account  = (string) get_permalink( (int) diluxone_users_option( 'diluxone_users_account_page' ) );

		switch_to_blog( $this->beta );
		$here = $this->standing_on( 'a-page/' );

		$url = diluxone_users_login_url();
		$this->assertSame( $login, strtok( $url, '?' ), 'the plugin’s sign-in is the hub’s page' );
		$this->assertSame( $here, $this->queryArg( $url, 'redirect_to' ), 'carrying the page the person is on' );

		$url = diluxone_users_register_url();
		$this->assertSame( $register, strtok( $url, '?' ) );
		$this->assertSame( $here, $this->queryArg( $url, 'redirect_to' ) );

		$this->assertSame( $account, diluxone_users_account_url(), 'the account is the hub’s, and needs no way back' );

		// WordPress's own addresses, whatever asks for them.
		$admin = home_url( '/wp-admin/' );
		$url   = wp_login_url( $admin );
		$this->assertSame( $this->hub_wp_login(), strtok( $url, '?' ), 'wp_login_url() is the hub’s wp-login.php' );
		$this->assertSame( $admin, $this->queryArg( $url, 'redirect_to' ), 'with where WordPress meant to send them' );
		$this->assertSame( $here, $this->queryArg( wp_login_url(), 'redirect_to' ), 'or where they are' );
		$this->assertSame( '1', $this->queryArg( wp_login_url( $admin, true ), 'reauth' ), 'asking again stays asking again' );

		$url = wp_registration_url();
		$this->assertSame( $register, strtok( $url, '?' ), 'registration is the hub’s form while it is open' );
		$this->assertSame( $here, $this->queryArg( $url, 'redirect_to' ) );

		diluxone_users_update_option( 'diluxone_users_register_form', 0 );
		$url = wp_registration_url();
		$this->assertSame( $this->hub_wp_login(), strtok( $url, '?' ), 'and WordPress’s form on the hub when it is not' );
		$this->assertSame( 'register', $this->queryArg( $url, 'action' ) );
		$this->assertSame( $here, $this->queryArg( $url, 'redirect_to' ) );

		$url = wp_lostpassword_url();
		$this->assertSame( $this->hub_wp_login(), strtok( $url, '?' ), 'a lost password is asked for on the hub' );
		$this->assertSame( 'lostpassword', $this->queryArg( $url, 'action' ) );
		$this->assertSame( $here, $this->queryArg( $url, 'redirect_to' ) );

		diluxone_users_update_option( 'diluxone_users_lost_password', 'link' );
		$url = wp_lostpassword_url();
		$this->assertSame( $login, strtok( $url, '?' ), 'or on the hub’s sign-in page, when the link is the way back in' );
		$this->assertSame( $here, $this->queryArg( $url, 'redirect_to' ) );
	}

	public function test_the_sites_menu_leads_to_the_hub(): void {
		$this->network_only();

		$login   = $this->hub_login();
		$account = (string) get_permalink( (int) diluxone_users_option( 'diluxone_users_account_page' ) );

		switch_to_blog( $this->beta );
		$here = $this->standing_on( 'news/' );

		$menu = wp_create_nav_menu( 'Hub test menu ' . wp_generate_password( 4, false ) );
		$this->assertIsInt( $menu );
		set_theme_mod( 'nav_menu_locations', array( 'hub-test' => $menu ) );
		diluxone_users_update_option( 'diluxone_users_menu_location', 'hub-test' );

		$args  = (object) array( 'menu' => get_term( $menu, 'nav_menu' ) );
		$items = diluxone_users_menu_items( array(), $args );

		$this->assertCount( 1, $items );
		$this->assertSame( $login, strtok( $items[0]->url, '?' ), '“Sign in” is the hub’s' );
		$this->assertSame( $here, $this->queryArg( $items[0]->url, 'redirect_to' ), 'and comes back to the page it was clicked on' );

		wp_set_current_user( $this->make_user() );
		$items = diluxone_users_menu_items( array(), $args );

		$this->assertSame( $account, $items[0]->url, 'the person’s item opens their account on the hub' );

		wp_delete_nav_menu( $menu );
	}

	public function test_on_a_single_site_every_door_is_the_sites_own(): void {
		$this->single_only();

		$login = $this->hub_login();
		$this->standing_on( 'a-page/' );

		$this->assertSame( $login, diluxone_users_login_url(), 'no way back to carry: the site is its own hub' );
		$this->assertStringNotContainsString( 'redirect_to', diluxone_users_register_url() );
		$this->assertSame( site_url( 'wp-login.php', 'login' ), wp_login_url() );
		$this->assertSame( site_url( 'wp-login.php', 'login' ) . '?redirect_to=' . rawurlencode( home_url( '/x/' ) ), wp_login_url( home_url( '/x/' ) ) );
		$this->assertStringNotContainsString( 'redirect_to', wp_registration_url() );
		$this->assertStringStartsWith( site_url( 'wp-login.php', 'login' ), wp_lostpassword_url() );
		$this->assertStringNotContainsString( 'redirect_to', wp_lostpassword_url() );
		$this->assertFalse( diluxone_users_off_hub() );
		$this->assertFalse( diluxone_users_sends_to_hub() );

		// And the menu's “Sign in” is the site's page, as it was.
		$menu = wp_create_nav_menu( 'Hub test menu ' . wp_generate_password( 4, false ) );
		$this->assertIsInt( $menu );
		set_theme_mod( 'nav_menu_locations', array( 'hub-test' => $menu ) );
		diluxone_users_update_option( 'diluxone_users_menu_location', 'hub-test' );

		$items = diluxone_users_menu_items( array(), (object) array( 'menu' => get_term( $menu, 'nav_menu' ) ) );
		$this->assertSame( $login, $items[0]->url );

		wp_delete_nav_menu( $menu );
	}

	/* ── Return addresses that are not one ──────────────────────────── */

	public function test_a_foreign_or_malformed_return_address_is_dropped(): void {
		$this->network_only();

		switch_to_blog( $this->beta );
		$here = $this->standing_on( 'a-page/' );

		foreach ( array( 'https://evil.test/', '//evil.test/', 'https:\\\\evil.test', 'http://localhost%5C@evil.test/', 'javascript:alert(1)' ) as $bad ) {
			$this->assertSame( '', diluxone_users_safe_return( $bad ), $bad );
			$this->assertSame( $here, $this->queryArg( wp_login_url( $bad ), 'redirect_to' ), "{$bad} in wp_login_url()" );
			$this->assertSame( home_url( '/' ), $this->queryArg( $this->wp_login( array( 'redirect_to' => $bad ) ), 'redirect_to' ), "{$bad} in wp-login.php" );
		}

		restore_current_blog();

		// On the hub it is not held at all.
		$this->arrive_with( 'https://evil.test/' );
		$this->assertArrayNotHasKey( DILUXONE_USERS_RETURN_COOKIE, self::$cookies );
		$this->assertSame( home_url( '/' ), apply_filters( 'diluxone_users_login_redirect', home_url( '/' ), $this->make_user() ) );

		// Nor is one somebody wrote into the cookie by hand.
		$_COOKIE[ DILUXONE_USERS_RETURN_COOKIE ] = 'https://evil.test/';
		$this->assertSame( home_url( '/' ), apply_filters( 'diluxone_users_login_redirect', home_url( '/' ), $this->make_user() ) );
	}

	public function test_on_a_single_site_an_address_elsewhere_is_not_one_either(): void {
		$this->single_only();

		$this->assertSame( home_url( '/x/' ), diluxone_users_safe_return( home_url( '/x/' ) ) );

		foreach ( array( 'https://evil.test/', '//evil.test/', 'https:\\\\evil.test' ) as $bad ) {
			$this->assertSame( '', diluxone_users_safe_return( $bad ), $bad );
		}

		$this->assertSame( 'fallback', wp_validate_redirect( 'https://evil.test/', 'fallback' ), 'no host added to WordPress’s list' );
	}

	/* ── wp-login.php on a site that is not the hub ─────────────────── */

	public function test_wp_login_on_another_site_goes_to_the_hub_with_its_action_and_return(): void {
		$this->network_only();

		switch_to_blog( $this->beta );
		$admin = home_url( '/wp-admin/' );

		$url = $this->wp_login( array( 'redirect_to' => $admin ) );
		$this->assertSame( $this->hub_wp_login(), strtok( $url, '?' ) );
		$this->assertSame( $admin, $this->queryArg( $url, 'redirect_to' ) );
		$this->assertSame( '', $this->queryArg( $url, 'action' ) );

		$url = $this->wp_login( array() );
		$this->assertSame( home_url( '/' ), $this->queryArg( $url, 'redirect_to' ), 'with none asked for, back to the site’s front page' );

		foreach ( array( 'register', 'lostpassword', 'checkemail' ) as $action ) {
			$url = $this->wp_login( array( 'action' => $action ) );
			$this->assertSame( $this->hub_wp_login(), strtok( $url, '?' ), $action );
			$this->assertSame( $action, $this->queryArg( $url, 'action' ), $action );
		}

		$this->assertSame( '1', $this->queryArg( $this->wp_login( array( 'reauth' => '1' ) ), 'reauth' ) );
	}

	public function test_what_a_site_keeps_for_itself_stays_there(): void {
		$this->network_only();

		switch_to_blog( $this->beta );

		foreach ( DILUXONE_USERS_WP_LOGIN_LOCAL as $action ) {
			$this->assertSame( '', $this->wp_login( array( 'action' => $action ) ), $action );
		}

		$this->assertSame( '', $this->wp_login( array( 'diluxone-users-admin' => '1' ) ), 'the emergency door' );
		$this->assertSame( '', $this->wp_login( array( 'interim-login' => '1' ) ), 'the dashboard’s interim sign-in' );
		$this->assertSame( '', $this->wp_login( array(), 'POST' ), 'a password posted to it' );

		restore_current_blog();

		$this->assertSame( '', $this->wp_login( array() ), 'the hub’s own wp-login.php is the hub’s' );
	}

	public function test_on_a_single_site_wp_login_is_left_alone(): void {
		$this->single_only();

		$this->assertSame( '', $this->wp_login( array() ) );
		$this->assertSame( '', $this->wp_login( array( 'action' => 'register' ) ) );
	}

	/* ── The return address: held once, spent once ──────────────────── */

	public function test_the_return_address_is_held_in_a_short_lived_httponly_cookie_and_spent_once(): void {
		$this->network_only();

		switch_to_blog( $this->beta );
		$back = home_url( '/a-page/' );
		restore_current_blog();

		$user = $this->make_user();

		$this->arrive_with( $back );

		$cookie = self::$cookies[ DILUXONE_USERS_RETURN_COOKIE ] ?? array();
		$this->assertSame( $back, $cookie['value'] ?? '' );
		$this->assertTrue( $cookie['options']['httponly'] );
		$this->assertSame( 'Lax', $cookie['options']['samesite'] );
		$this->assertEqualsWithDelta( time() + DILUXONE_USERS_RETURN_TTL, $cookie['options']['expires'], 5, 'twenty minutes, no more' );
		$this->assertLessThanOrEqual( 30 * MINUTE_IN_SECONDS, DILUXONE_USERS_RETURN_TTL );

		// The password form, drawn on the same page, reads it without spending it.
		$this->assertSame( $back, apply_filters( 'diluxone_users_login_redirect', home_url( '/' ), 0 ) );
		$this->assertSame( $back, apply_filters( 'diluxone_users_login_redirect', home_url( '/' ), 0 ) );

		$this->assertFalse( is_user_member_of_blog( $user, $this->beta ) );

		// Somebody signs in: it is spent, and they are a member of the site
		// they are going back to.
		$this->assertSame( $back, apply_filters( 'diluxone_users_login_redirect', home_url( '/' ), $user ) );
		$this->assertArrayNotHasKey( DILUXONE_USERS_RETURN_COOKIE, $_COOKIE );
		$this->assertSame( '', self::$cookies[ DILUXONE_USERS_RETURN_COOKIE ]['value'], 'the cookie is cleared' );
		$this->assertLessThan( time(), self::$cookies[ DILUXONE_USERS_RETURN_COOKIE ]['options']['expires'] );
		$this->assertTrue( is_user_member_of_blog( $user, $this->beta ), 'a member of the site they came from' );

		$this->assertSame( home_url( '/' ), apply_filters( 'diluxone_users_login_redirect', home_url( '/' ), $user ), 'single use' );
	}

	public function test_a_site_that_takes_nobody_is_not_joined_on_the_way_back(): void {
		$this->network_only();

		diluxone_users_update_option( 'diluxone_users_login_register', 0 );
		diluxone_users_update_option( 'diluxone_users_register_form', 0 );
		diluxone_users_update_option( 'diluxone_users_sso_register', 0 );

		switch_to_blog( $this->beta );
		$back = home_url( '/' );
		restore_current_blog();

		$user = $this->make_user();
		$this->arrive_with( $back );

		$this->assertSame( $back, apply_filters( 'diluxone_users_login_redirect', home_url( '/' ), $user ), 'back all the same' );
		$this->assertFalse( is_user_member_of_blog( $user, $this->beta ), 'but not a member of a site that takes nobody' );
	}

	public function test_on_a_single_site_no_return_is_held_or_used(): void {
		$this->single_only();

		$this->arrive_with( home_url( '/x/' ) );
		$this->assertArrayNotHasKey( DILUXONE_USERS_RETURN_COOKIE, self::$cookies );

		$_COOKIE[ DILUXONE_USERS_RETURN_COOKIE ] = home_url( '/x/' );
		$this->assertSame( home_url( '/' ), apply_filters( 'diluxone_users_login_redirect', home_url( '/' ), $this->make_user() ) );
	}

	/* ── Every way in comes back ────────────────────────────────────── */

	/** Consumes a sign-in link for somebody and returns where it sent them. */
	private function open_link( int $user ): string {
		$_GET = array(
			'diluxone_users_login' => (string) $user,
			'diluxone_users_token' => diluxone_users_token_create( $user ),
		);

		return $this->expectRedirect( 'diluxone_users_login_consume' );
	}

	public function test_the_email_link_comes_back_and_on_another_browser_lands_on_the_hub(): void {
		$this->network_only();

		switch_to_blog( $this->beta );
		$back = home_url( '/a-page/' );
		restore_current_blog();

		$user = $this->make_user();

		$this->arrive_with( $back );
		$this->assertSame( $back, $this->open_link( $user ) );
		$this->assertSame( $user, get_current_user_id() );
		$this->assertTrue( is_user_member_of_blog( $user, $this->beta ) );

		// Another browser: no cookie, the hub's front page.
		$_COOKIE = array();
		wp_set_current_user( 0 );
		$this->assertSame( home_url( '/' ), $this->open_link( $this->make_user() ) );
	}

	public function test_a_password_comes_back_through_the_form(): void {
		$this->network_only();

		switch_to_blog( $this->beta );
		$back = home_url( '/a-page/' );
		restore_current_blog();

		$user     = $this->make_user();
		$password = 'Hub-Password-9';
		wp_set_password( $password, $user );

		$this->arrive_with( $back );

		// What the form carries is the return address...
		$this->assertSame( $back, apply_filters( 'diluxone_users_login_redirect', home_url( '/' ), 0 ) );

		// ...and WordPress sends them there; signing in spends the cookie and
		// makes them a member of the site.
		$_POST = array( 'redirect_to' => $back );
		$this->assertInstanceOf(
			\WP_User::class,
			wp_signon(
				array(
					'user_login'    => get_userdata( $user )->user_login,
					'user_password' => $password,
				)
			)
		);

		$this->assertArrayNotHasKey( DILUXONE_USERS_RETURN_COOKIE, $_COOKIE );
		$this->assertTrue( is_user_member_of_blog( $user, $this->beta ) );
		$this->assertSame( $back, wp_validate_redirect( $back, 'fallback' ) );
	}

	/** Plays a social round trip on the hub and returns where it ended. */
	private function social( bool $forge = false ): string {
		$_GET = array(
			'diluxone_users_sso' => MockProvider::ID,
			'diluxone_users_go'  => '1',
		);
		diluxone_users_sso_query_snapshot();

		$out    = $this->expectRedirect( 'diluxone_users_sso_handle' );
		$state  = $this->queryArg( $out, 'state' );
		$secret = self::$cookies[ diluxone_users_sso_cookie() ]['value'] ?? '';

		$_COOKIE[ diluxone_users_sso_cookie() ] = $secret;
		$_GET                                   = array(
			'diluxone_users_sso' => MockProvider::ID,
			'code'               => 'the-code',
			'state'              => $forge ? 'forged-' . $state : $state,
		);
		diluxone_users_sso_query_snapshot();

		return $this->expectRedirect( 'diluxone_users_sso_handle' );
	}

	public function test_a_social_sign_in_comes_back_and_keeps_its_state_check(): void {
		$this->network_only();

		MockProvider::install();
		diluxone_users_update_option( 'diluxone_users_sso_register', 1 );
		$sub = 'hub-' . wp_generate_password( 8, false );

		MockProvider::$profile = array(
			'sub'            => $sub,
			'email'          => $sub . '@example.test',
			'email_verified' => true,
		);

		switch_to_blog( $this->beta );
		$back = home_url( '/a-page/' );
		restore_current_blog();

		// The provider is told the hub's address, from every site.
		$this->arrive_with( $back );
		$this->assertSame( 'social', $this->redirectState( $this->social( true ) ), 'a forged state is refused, return address or not' );
		$this->assertSame( 0, get_current_user_id() );

		$this->arrive_with( $back );
		$this->assertSame( $back, $this->social() );
		$this->assertTrue( is_user_member_of_blog( get_current_user_id(), $this->beta ) );
	}

	public function test_the_second_step_keeps_the_way_back(): void {
		$this->network_only();

		diluxone_users_update_option( 'diluxone_users_2fa_mode', 'required' );
		diluxone_users_update_option( 'diluxone_users_2fa_methods', array( 'email' ) );
		diluxone_users_update_option( 'diluxone_users_2fa_remember_days', 0 );

		switch_to_blog( $this->beta );
		$back = home_url( '/a-page/' );
		restore_current_blog();

		$user = $this->make_user();
		$this->arrive_with( $back );

		// A social account asks for the second step; the challenge is the hub's.
		$challenge = $this->expectRedirect( fn() => diluxone_users_complete_login( $user, 'sso', false, apply_filters( 'diluxone_users_login_redirect', home_url( '/' ), $user ) ) );
		$this->assertSame( $this->hub_login(), strtok( $challenge, '?' ) );
		$this->assertSame( 0, get_current_user_id(), 'nobody is in before the code' );

		preg_match( '/\b(\d{6})\b/', (string) ( $this->lastMail()['message'] ?? '' ), $code );

		$this->postAs(
			0,
			array(
				'diluxone_users_2fa_user'   => (string) $user,
				'diluxone_users_2fa_key'    => $this->queryArg( $challenge, 'diluxone_users_key' ),
				'diluxone_users_2fa_method' => 'email',
				'diluxone_users_2fa_code'   => $code[1] ?? '',
			)
		);

		$this->assertSame( $back, $this->expectRedirect( 'diluxone_users_2fa_handle' ) );
		$this->assertSame( $user, get_current_user_id() );
	}

	public function test_on_a_single_site_every_way_in_lands_where_it_always_did(): void {
		$this->single_only();

		$this->arrive_with( home_url( '/x/' ) );
		$this->assertSame( home_url( '/' ), $this->open_link( $this->make_user() ) );
	}

	/* ── Social sign-in and passkeys are the hub's ──────────────────── */

	public function test_social_sign_in_and_its_route_are_the_hubs(): void {
		$this->network_only();

		global $wp_rewrite;

		$hub  = diluxone_users_sso_redirect_uri( 'google' );
		$rule = '^' . preg_quote( diluxone_users_sso_base(), '/' ) . '/([a-z0-9_-]+)/?$';

		$this->assertStringStartsWith( home_url( '/' ), $hub );

		switch_to_blog( $this->beta );

		$this->assertSame( $hub, diluxone_users_sso_redirect_uri( 'google' ), 'one address for the providers, from every site' );

		unset( $wp_rewrite->extra_rules_top[ $rule ] );
		diluxone_users_sso_rule();
		$this->assertArrayNotHasKey( $rule, $wp_rewrite->extra_rules_top, 'no /sso/ route on a site that is not the hub' );

		// An old address on this site starts nothing here.
		MockProvider::install();
		$_GET = array(
			'diluxone_users_sso' => MockProvider::ID,
			'diluxone_users_go'  => '1',
		);
		diluxone_users_sso_query_snapshot();

		$url = $this->expectRedirect( 'diluxone_users_sso_handle' );
		$this->assertStringStartsWith( $this->hub_login_from_here(), $url, 'it goes to the hub’s sign-in' );
		$this->assertArrayNotHasKey( diluxone_users_sso_cookie(), self::$cookies, 'and no trip was started' );

		restore_current_blog();

		diluxone_users_sso_rule();
		$this->assertArrayHasKey( $rule, $wp_rewrite->extra_rules_top, 'the hub has it' );
	}

	/** The hub's sign-in page, as it is written from the current site. */
	private function hub_login_from_here(): string {
		return (string) strtok( diluxone_users_login_url(), '?' );
	}

	public function test_the_account_route_is_the_hubs(): void {
		$this->network_only();

		global $wp_rewrite;

		$before = count( $wp_rewrite->extra_rules_top );

		switch_to_blog( $this->beta );
		diluxone_users_account_rule();
		$this->assertCount( $before, $wp_rewrite->extra_rules_top, 'no account route on a site that is not the hub' );
	}

	public function test_passkeys_belong_to_the_hubs_domain(): void {
		$this->network_only();

		$hub  = (string) wp_parse_url( home_url(), PHP_URL_HOST );
		$port = wp_parse_url( home_url(), PHP_URL_PORT );

		$this->assertSame( $hub, diluxone_users_passkey_rp_id() );

		// A site on a subdomain of the network: another host, the same keys.
		$sub = $this->site( 'hub-sub-' . strtolower( wp_generate_password( 6, false ) ) . '.' . get_network()->domain, '/' );
		switch_to_blog( $sub );

		$this->assertNotSame( $hub, wp_parse_url( home_url(), PHP_URL_HOST ) );
		$this->assertSame( $hub, diluxone_users_passkey_rp_id(), 'the hub’s domain, from every site' );
		$this->assertSame( 'http://' . $hub . ( $port ? ':' . $port : '' ), diluxone_users_passkey_origin() );

		// And its endpoint says where to go instead of answering.
		$answer = $this->ajax( 'diluxone_users_passkeys_ajax', array( 'step' => 'login-options' ) );
		$this->assertFalse( $answer['success'] ?? true );
		$this->assertStringStartsWith( $this->hub_login_from_here(), (string) ( $answer['data']['redirect'] ?? '' ) );
	}

	public function test_on_a_single_site_social_and_passkeys_are_the_sites_own(): void {
		$this->single_only();

		global $wp_rewrite;

		$this->assertStringStartsWith( home_url( '/' ), diluxone_users_sso_redirect_uri( 'google' ) );

		$rule = '^' . preg_quote( diluxone_users_sso_base(), '/' ) . '/([a-z0-9_-]+)/?$';
		unset( $wp_rewrite->extra_rules_top[ $rule ] );
		diluxone_users_sso_rule();
		$this->assertArrayHasKey( $rule, $wp_rewrite->extra_rules_top );

		$this->assertSame( wp_parse_url( home_url(), PHP_URL_HOST ), diluxone_users_passkey_rp_id() );
	}

	/**
	 * Runs an AJAX handler and returns the JSON it answered.
	 *
	 * @param array<string, string> $post
	 * @return array<string, mixed>
	 */
	private function ajax( callable $handler, array $post ): array {
		$_POST = $post;

		$die = static function (): callable {
			return static function (): void {
				throw new \RuntimeException( 'wp_die' );
			};
		};

		add_filter( 'wp_doing_ajax', '__return_true' );
		add_filter( 'wp_die_ajax_handler', $die );

		ob_start();

		try {
			$handler();
		} catch ( \Exception $e ) {
			// wp_die() after the answer: the bootstrap's handler, or ours.
			unset( $e );
		} finally {
			$out = (string) ob_get_clean();
			remove_filter( 'wp_doing_ajax', '__return_true' );
			remove_filter( 'wp_die_ajax_handler', $die );
		}

		return (array) json_decode( $out, true );
	}

	/* ── Shortcodes and forms on a site that is not the hub ─────────── */

	/**
	 * A shortcode, drawn the way do_shortcode_tag() draws it.
	 *
	 * By hand because the suite's bootstrap loads WordPress inside a
	 * function, and the registry of shortcodes it fills is not the global
	 * do_shortcode() reads; the filter and the callback are the same ones.
	 */
	private function shortcode( string $tag ): string {
		$out = apply_filters( 'pre_do_shortcode_tag', false, $tag, array(), array() );

		if ( false !== $out ) {
			return (string) $out;
		}

		return (string) call_user_func( str_replace( 'diluxone_users_', 'diluxone_users_shortcode_', $tag ) );
	}

	public function test_the_shortcodes_on_another_site_are_doors_to_the_hub(): void {
		$this->network_only();

		$account = (string) get_permalink( (int) diluxone_users_option( 'diluxone_users_account_page' ) );

		switch_to_blog( $this->beta );
		$here = $this->standing_on( 'a-page/' );

		$html = $this->shortcode( 'diluxone_users_login' );
		$this->assertStringContainsString( 'data-diluxone-users-door="login"', $html );
		$this->assertStringContainsString( esc_url( diluxone_users_login_url() ), $html );
		$this->assertStringContainsString( rawurlencode( $here ), $html, 'with the way back' );
		$this->assertStringNotContainsString( 'diluxone_users_link_request', $html, 'no form posting to this site' );

		$this->assertStringContainsString( 'data-diluxone-users-door="register"', $this->shortcode( 'diluxone_users_register' ) );
		$this->assertStringContainsString( 'data-diluxone-users-door="login"', $this->shortcode( 'diluxone_users_account' ), 'the account asks a stranger to sign in' );

		foreach ( array( 'fields', 'avatar', 'sessions', 'accounts', 'notifications', 'handle', 'account_nav' ) as $piece ) {
			$this->assertSame( '', $this->shortcode( "diluxone_users_{$piece}" ), "{$piece} draws nothing to a stranger, as before" );
		}

		wp_set_current_user( $this->make_user() );

		$this->assertSame( '', $this->shortcode( 'diluxone_users_login' ), 'nothing to sign in to' );

		foreach ( array( 'account', 'fields', 'avatar', 'accounts' ) as $piece ) {
			$html = $this->shortcode( "diluxone_users_{$piece}" );
			$this->assertStringContainsString( 'data-diluxone-users-door="account"', $html, $piece );
			$this->assertStringContainsString( esc_url( $account ), $html, $piece );
		}

		restore_current_blog();

		wp_set_current_user( 0 );
		$this->assertStringNotContainsString( 'data-diluxone-users-door', $this->shortcode( 'diluxone_users_login' ), 'the hub draws the form itself' );
	}

	public function test_a_form_posted_to_another_site_goes_to_the_hub(): void {
		$this->network_only();

		switch_to_blog( $this->beta );

		$this->postAs(
			0,
			array(
				'diluxone_users_nonce' => wp_create_nonce( 'diluxone_users_login' ),
				'diluxone_users_email' => 'hub-post@example.test',
			)
		);

		$url = $this->expectRedirect( fn() => do_action( 'admin_post_nopriv_diluxone_users_link_request' ) );
		$this->assertSame( $this->hub_login_from_here(), strtok( $url, '?' ) );
		$this->assertSame( array(), self::$mail, 'nothing is sent from here' );

		$this->postAs( $this->make_user(), array() );
		$url = $this->expectRedirect( fn() => do_action( 'admin_post_diluxone_users_avatar' ) );
		$this->assertSame( diluxone_users_account_url(), $url );
	}

	public function test_on_a_single_site_the_shortcodes_and_forms_are_the_sites(): void {
		$this->single_only();

		$this->assertStringNotContainsString( 'data-diluxone-users-door', $this->shortcode( 'diluxone_users_login' ) );
		$this->assertStringNotContainsString( 'data-diluxone-users-door', $this->shortcode( 'diluxone_users_account' ) );
		$this->assertSame( '', $this->maybe_redirect( 'diluxone_users_post_to_hub' ) );
	}

	/* ── A site on a domain of its own ──────────────────────────────── */

	public function test_a_site_on_a_domain_of_its_own_is_found_and_told(): void {
		$this->network_only();

		$this->assertSame( array(), wp_list_pluck( diluxone_users_mapped_sites(), 'id' ), 'none to begin with' );

		$domain = 'hub-mapped-' . strtolower( wp_generate_password( 6, false ) ) . '.test';
		$mapped = $this->site( $domain, '/' );

		$this->assertSame( array( $mapped ), wp_list_pluck( diluxone_users_mapped_sites(), 'id' ), 'found once it is added' );
		$this->assertContains( $domain, diluxone_users_network_hosts() );
		$this->assertTrue( diluxone_users_site_mapped( $mapped ) );
		$this->assertFalse( diluxone_users_site_mapped( $this->beta ) );

		switch_to_blog( $mapped );

		$this->assertTrue( diluxone_users_off_hub() );
		$this->assertFalse( diluxone_users_sends_to_hub(), 'a session on the hub would not arrive here' );
		$this->assertSame( '', $this->wp_login( array() ), 'its own wp-login.php, to sign in again' );
		$this->assertSame( site_url( 'wp-login.php', 'login' ), wp_login_url() );

		wp_set_current_user( $this->make_user( 'administrator' ) );
		ob_start();
		diluxone_users_mapped_site_notice();
		$notice = (string) ob_get_clean();
		$this->assertStringContainsString( 'data-diluxone-users-mapped', $notice );
		$this->assertStringContainsString( $domain, $notice );

		restore_current_blog();

		switch_to_blog( $this->beta );
		ob_start();
		diluxone_users_mapped_site_notice();
		$this->assertSame( '', (string) ob_get_clean(), 'not on a site of the network’s own domain' );
		restore_current_blog();

		// Moved under the network's domain, it is one of the family again.
		wp_update_site( $mapped, array( 'domain' => 'hub-moved.' . get_network()->domain ) );
		$this->assertSame( array(), wp_list_pluck( diluxone_users_mapped_sites(), 'id' ), 'the list follows a change of domain' );

		wp_delete_site( $mapped );
		$this->sites = array_diff( $this->sites, array( $mapped ) );
		$this->assertNotContains( 'hub-moved.' . get_network()->domain, diluxone_users_network_domains(), 'and a deletion' );
	}

	public function test_the_networks_hosts_and_only_those_are_safe_redirects(): void {
		$this->network_only();

		$host = 'hub-safe-' . strtolower( wp_generate_password( 6, false ) ) . '.' . (string) wp_parse_url( 'http://' . get_network()->domain, PHP_URL_HOST );
		$this->site( $host . ( wp_parse_url( 'http://' . get_network()->domain, PHP_URL_PORT ) ? ':' . wp_parse_url( 'http://' . get_network()->domain, PHP_URL_PORT ) : '' ), '/' );

		$this->assertSame( 'http://' . $host . '/', wp_validate_redirect( 'http://' . $host . '/', 'fallback' ), 'a subdomain site of this network' );
		$this->assertSame( 'fallback', wp_validate_redirect( 'https://evil.test/', 'fallback' ) );
		$this->assertNotContains( 'evil.test', apply_filters( 'allowed_redirect_hosts', array() ) );
	}

	public function test_on_a_single_site_there_is_no_domain_of_its_own(): void {
		$this->single_only();

		$this->assertSame( array(), diluxone_users_mapped_sites() );
		$this->assertFalse( diluxone_users_site_mapped() );
		$this->assertSame( array(), diluxone_users_network_domains() );

		ob_start();
		diluxone_users_mapped_site_notice();
		$this->assertSame( '', (string) ob_get_clean() );
	}
}
