<?php
/**
 * The pages the plugin draws on and the person in the site's own menu.
 *
 * A chosen page draws its part even with no shortcode typed in it, and only
 * as the main query's own page in the loop. "Create the page" refuses what is
 * not one of the plugin's pages and says when WordPress would not make it, and
 * the notice after it is an administrator's. The menu adds the person only to
 * the menu the site chose, with the dashboard for whoever can write and, on a
 * network, the way to join a site or the word that it is by invitation.
 */

namespace Tests\Integration;

class CoverAccountMenuPagesTest extends IntegrationTestCase {

	/** @var array<int, int> Sites the test made. */
	private array $sites = array();

	/** @var array<int, int> Menus the test made, on this site. */
	private array $menus = array();

	/** @var mixed The menu locations before the test. */
	private $locations;

	/** Whether the test registered the account shortcode itself. */
	private bool $added_shortcode = false;

	/** @var array<string, mixed> The query globals before the test. */
	private array $query = array();

	protected function setUp(): void {
		parent::setUp();

		$this->locations = get_theme_mod( 'nav_menu_locations' );
		$this->query     = array(
			'wp_query'     => $GLOBALS['wp_query'] ?? null,
			'wp_the_query' => $GLOBALS['wp_the_query'] ?? null,
			'post'         => $GLOBALS['post'] ?? null,
		);

		if ( is_multisite() ) {
			diluxone_users_membership_unlock();
			diluxone_users_join_drawn( false );
		}
	}

	protected function tearDown(): void {
		while ( is_multisite() && ms_is_switched() ) {
			restore_current_blog();
		}

		foreach ( $this->menus as $menu ) {
			wp_delete_nav_menu( $menu );
		}

		if ( false === $this->locations ) {
			remove_theme_mod( 'nav_menu_locations' );
		} else {
			set_theme_mod( 'nav_menu_locations', $this->locations );
		}

		foreach ( $this->query as $global => $value ) {
			$GLOBALS[ $global ] = $value;
		}

		foreach ( $this->sites as $site ) {
			wp_delete_site( $site );
		}

		if ( $this->added_shortcode ) {
			remove_shortcode( 'diluxone_users_account' );
			$this->added_shortcode = false;
		}

		remove_filter( 'wp_insert_post_empty_content', '__return_true' );
		wp_dequeue_style( 'diluxone-users' );
		wp_dequeue_style( 'diluxone-users-menu' );
		wp_deregister_style( 'diluxone-users-menu' );
		unset( $_SERVER['HTTP_REFERER'] );

		if ( is_multisite() ) {
			diluxone_users_membership_queue_save( array() );
			diluxone_users_join_drawn( false );
		}

		parent::tearDown();
	}

	/** A menu in a location of the current site, chosen for the person. */
	private function chosen_menu(): \WP_Term {
		$menu = wp_create_nav_menu( 'Cover account menu ' . wp_generate_password( 6, false ) );
		$this->assertIsInt( $menu );
		$this->menus[] = $menu;

		register_nav_menu( 'cover-account-menu', 'Cover account menu' );
		set_theme_mod( 'nav_menu_locations', array( 'cover-account-menu' => $menu ) );
		diluxone_users_update_option( 'diluxone_users_menu_location', 'cover-account-menu' );

		return get_term( $menu, 'nav_menu' );
	}

	/**
	 * What the menu says, as title => classes.
	 *
	 * @return array<string, string>
	 */
	private function menu_said( \WP_Term $menu ): array {
		$said = array();

		foreach ( diluxone_users_menu_items( array(), (object) array( 'menu' => $menu ) ) as $item ) {
			$said[ wp_strip_all_tags( (string) $item->title ) ] = implode( ' ', (array) $item->classes );
		}

		return $said;
	}

	/** The main query, standing on this page, inside its loop. */
	private function on_page( int $page ): void {
		$query = new \WP_Query( array( 'page_id' => $page ) );

		$GLOBALS['wp_query']     = $query;
		$GLOBALS['wp_the_query'] = $query;
		$query->the_post();
	}

	/** A site of the network that is not the hub. */
	private function other_site(): int {
		$site = (int) wp_insert_site(
			array(
				'domain' => (string) get_network()->domain,
				'path'   => '/cover-account-' . strtolower( wp_generate_password( 6, false ) ) . '/',
				'title'  => 'Cover account site',
			)
		);

		$this->assertGreaterThan( 0, $site );
		$this->sites[] = $site;

		return $site;
	}

	/* ── The pages ─────────────────────────────────────────────────── */

	public function test_a_chosen_page_draws_its_part_without_the_shortcode(): void {
		$page = (int) wp_insert_post(
			array(
				'post_type'   => 'page',
				'post_status' => 'publish',
				'post_title'  => 'Account',
			)
		);
		diluxone_users_update_option( 'diluxone_users_account_page', $page );

		$this->assertSame( 'Welcome', diluxone_users_page_draws_itself( 'Welcome' ), 'Outside the loop, left alone' );

		$this->on_page( $page );

		// The suite's WordPress has no shortcodes registered: has_shortcode()
		// only sees one that is.
		if ( ! shortcode_exists( 'diluxone_users_account' ) ) {
			add_shortcode( 'diluxone_users_account', 'diluxone_users_shortcode_account' );
			$this->added_shortcode = true;
		}

		$this->assertSame( "Welcome\n\n[diluxone_users_account]", diluxone_users_page_draws_itself( "Welcome \n" ) );
		$this->assertSame( 'Before [diluxone_users_account] after', diluxone_users_page_draws_itself( 'Before [diluxone_users_account] after' ), 'Where it was typed, it stays' );
	}

	public function test_a_page_that_is_not_chosen_draws_nothing_extra(): void {
		$page = (int) wp_insert_post(
			array(
				'post_type'   => 'page',
				'post_status' => 'publish',
				'post_title'  => 'About',
			)
		);

		$this->on_page( $page );

		$this->assertSame( 'About us', diluxone_users_page_draws_itself( 'About us' ) );
	}

	public function test_a_chosen_page_is_never_shortcode_not_found(): void {
		$this->assertTrue( apply_filters( 'diluxone_users_page_draws', false, 'diluxone_users_login_page' ) );
		$this->assertFalse( diluxone_users_page_draws_check( false, 'some_other_option' ) );
		$this->assertTrue( diluxone_users_page_draws_check( true, 'some_other_option' ) );
	}

	public function test_create_the_page_is_offered_only_while_none_is_chosen(): void {
		ob_start();
		diluxone_users_create_page_link( 'diluxone_users_login_page' );
		$link = (string) ob_get_clean();

		$this->assertStringContainsString( 'Create the page', $link );
		$this->assertSame( 1, preg_match( '/href="([^"]+)"/', $link, $href ) );
		$this->assertSame( 'diluxone_users_login_page', $this->queryArg( $href[1], 'page' ) );
		$this->assertSame( wp_create_nonce( 'diluxone_users_create_page' ), $this->queryArg( $href[1], '_wpnonce' ) );

		diluxone_users_update_option( 'diluxone_users_login_page', 123 );
		ob_start();
		diluxone_users_create_page_link( 'diluxone_users_login_page' );
		diluxone_users_create_page_link( 'not_a_page_of_the_plugin' );
		$this->assertSame( '', (string) ob_get_clean() );
	}

	public function test_create_the_page_makes_it_with_its_shortcode_once_and_rebuilds_the_addresses(): void {
		$rewrite = diluxone_users_raw_get( 'diluxone_users_rewrite_version', null );
		diluxone_users_update_option( 'diluxone_users_rewrite_version', DILUXONE_USERS_VERSION );
		diluxone_users_update_option( 'diluxone_users_account_page', 0 );
		$_SERVER['HTTP_REFERER'] = admin_url( 'admin.php?page=diluxone-users-account' );
		wp_set_current_user( 1 );

		$create = function (): string {
			$this->postAs(
				1,
				array(),
				array(
					'page'     => 'diluxone_users_account_page',
					'_wpnonce' => wp_create_nonce( 'diluxone_users_create_page' ),
				)
			);

			return $this->expectRedirect( 'diluxone_users_create_page' );
		};

		try {
			$url  = $create();
			$page = (int) diluxone_users_option( 'diluxone_users_account_page' );

			$this->assertGreaterThan( 0, $page );
			$this->assertSame( (string) $page, $this->queryArg( $url, 'diluxone_users_created' ) );
			$this->assertStringContainsString( '[diluxone_users_account]', (string) get_post_field( 'post_content', $page ) );
			$this->assertSame( 'publish', get_post_status( $page ) );
			$this->assertNull( diluxone_users_raw_get( 'diluxone_users_rewrite_version', null ), 'the addresses are rebuilt' );

			$again = $create();
			$this->assertSame( $page, (int) diluxone_users_option( 'diluxone_users_account_page' ), 'a second click makes no second page' );
			$this->assertSame( '', $this->queryArg( $again, 'diluxone_users_created' ) );

			wp_delete_post( $page, true );
		} finally {
			if ( null === $rewrite ) {
				diluxone_users_delete_option( 'diluxone_users_rewrite_version' );
			} else {
				diluxone_users_update_option( 'diluxone_users_rewrite_version', $rewrite );
			}
		}
	}

	public function test_create_the_page_refuses_a_page_that_is_not_the_plugins(): void {
		wp_set_current_user( 1 );
		$this->postAs(
			1,
			array(),
			array(
				'page'     => 'blogname',
				'_wpnonce' => wp_create_nonce( 'diluxone_users_create_page' ),
			)
		);

		$this->expectDie( 'diluxone_users_create_page', 'You are not allowed to do this.', 403 );
		$this->assertSame( '', (string) get_option( 'diluxone_users_blogname', '' ) );
	}

	public function test_create_the_page_says_why_wordpress_would_not_make_it(): void {
		add_filter( 'wp_insert_post_empty_content', '__return_true' );
		wp_set_current_user( 1 );
		$this->postAs(
			1,
			array(),
			array(
				'page'     => 'diluxone_users_register_page',
				'_wpnonce' => wp_create_nonce( 'diluxone_users_create_page' ),
			)
		);

		$this->expectDie( 'diluxone_users_create_page', 'Content, title, and excerpt are empty.' );
		$this->assertSame( 0, (int) diluxone_users_option( 'diluxone_users_register_page' ), 'Nothing chosen' );
	}

	public function test_create_the_page_keeps_one_chosen_in_the_meantime(): void {
		diluxone_users_update_option( 'diluxone_users_account_page', 77 );
		$_SERVER['HTTP_REFERER'] = admin_url( 'admin.php?page=diluxone-users-account' );
		wp_set_current_user( 1 );
		$this->postAs(
			1,
			array(),
			array(
				'page'     => 'diluxone_users_account_page',
				'_wpnonce' => wp_create_nonce( 'diluxone_users_create_page' ),
			)
		);

		$url = $this->expectRedirect( 'diluxone_users_create_page' );

		$this->assertSame( admin_url( 'admin.php?page=diluxone-users-account' ), $url, 'Back where the click came from, with nothing created' );
		$this->assertSame( 77, (int) diluxone_users_option( 'diluxone_users_account_page' ) );
	}

	public function test_the_created_notice_is_an_administrators(): void {
		$page = (int) wp_insert_post(
			array(
				'post_type'   => 'page',
				'post_status' => 'publish',
				'post_title'  => 'Cover sign in',
			)
		);
		$_GET = array( 'diluxone_users_created' => $page );

		wp_set_current_user( $this->make_user() );
		ob_start();
		diluxone_users_created_page_notice();
		$this->assertSame( '', (string) ob_get_clean() );

		wp_set_current_user( 1 );
		ob_start();
		diluxone_users_created_page_notice();
		$notice = (string) ob_get_clean();

		$this->assertStringContainsString( 'notice-success', $notice );
		$this->assertStringContainsString( 'was created and chosen', $notice );
		$this->assertStringContainsString( '>Cover sign in</a>', $notice );
		$this->assertStringContainsString( esc_url( (string) get_permalink( $page ) ), $notice );

		$_GET = array();
		ob_start();
		diluxone_users_created_page_notice();
		$this->assertSame( '', (string) ob_get_clean() );
	}

	/* ── The menu ──────────────────────────────────────────────────── */

	public function test_another_menu_is_left_as_it_is(): void {
		$this->chosen_menu();
		$other = wp_create_nav_menu( 'Footer ' . wp_generate_password( 6, false ) );
		$this->menus[] = $other;

		$items = array( 'kept' );

		$this->assertSame( $items, diluxone_users_menu_items( $items, (object) array( 'menu' => get_term( $other, 'nav_menu' ) ) ) );
		$this->assertSame( $items, diluxone_users_menu_items( $items, (object) array() ) );
	}

	public function test_whoever_can_write_gets_the_dashboard_and_a_member_does_not(): void {
		$menu = $this->chosen_menu();

		wp_set_current_user( $this->make_user( 'editor' ) );
		$editor = $this->menu_said( $menu );
		$this->assertArrayHasKey( 'Go to the WordPress dashboard', $editor );
		$this->assertArrayHasKey( 'Sign out', $editor );

		wp_set_current_user( $this->make_user() );
		$this->assertArrayNotHasKey( 'Go to the WordPress dashboard', $this->menu_said( $menu ) );
	}

	public function test_signed_out_the_menu_offers_only_sign_in(): void {
		$menu = $this->chosen_menu();
		wp_set_current_user( 0 );

		$items = diluxone_users_menu_items( array(), (object) array( 'menu' => $menu ) );

		$this->assertCount( 1, $items );
		$this->assertSame( 'Sign in', $items[0]->title );
		$this->assertSame( diluxone_users_login_url(), $items[0]->url );
	}

	/** The person's item in each style, with the name escaped in all of them. */
	public function test_the_persons_item_in_each_style_escapes_the_name(): void {
		$menu = $this->chosen_menu();
		$user = $this->make_user();
		update_user_meta( $user, 'first_name', '<b>x</b>' );
		wp_set_current_user( $user );

		$person = function () use ( $menu ): string {
			foreach ( diluxone_users_menu_items( array(), (object) array( 'menu' => $menu ) ) as $item ) {
				if ( in_array( 'diluxone-users-menu--person', (array) $item->classes, true ) ) {
					return (string) $item->title;
				}
			}

			return '';
		};

		diluxone_users_update_option( 'diluxone_users_menu_style', 'name' );
		$this->assertSame( '&lt;b&gt;x&lt;/b&gt;', $person() );

		diluxone_users_update_option( 'diluxone_users_menu_style', 'avatar' );
		$title = $person();
		$this->assertStringContainsString( '<img class="diluxone-users-menu__avatar"', $title );
		$this->assertStringContainsString( '<span class="screen-reader-text">&lt;b&gt;x&lt;/b&gt;</span>', $title, 'the name is still there for a screen reader' );

		diluxone_users_update_option( 'diluxone_users_menu_style', 'both' );
		$title = $person();
		$this->assertStringContainsString( '<img', $title );
		$this->assertStringContainsString( '<span class="diluxone-users-menu__name">&lt;b&gt;x&lt;/b&gt;</span>', $title );
		$this->assertStringNotContainsString( '<b>', $title );
	}

	public function test_without_an_account_page_the_person_goes_to_the_profile_and_no_section_is_listed(): void {
		$menu = $this->chosen_menu();
		diluxone_users_update_option( 'diluxone_users_account_page', 0 );
		wp_set_current_user( $this->make_user() );

		$items = diluxone_users_menu_items( array(), (object) array( 'menu' => $menu ) );

		$this->assertCount( 2, $items, 'the person and signing out, and no section of an account there is not' );
		$this->assertSame( admin_url( 'profile.php' ), $items[0]->url );
		$this->assertSame( 'Sign out', $items[1]->title );
	}

	public function test_the_menus_photo_style_is_only_for_somebody_signed_in(): void {
		$this->chosen_menu();

		diluxone_users_menu_style();
		$this->assertFalse( wp_style_is( 'diluxone-users-menu', 'enqueued' ), 'Nobody signed in has no photo' );

		wp_set_current_user( $this->make_user() );
		diluxone_users_menu_style();

		$this->assertTrue( wp_style_is( 'diluxone-users-menu', 'enqueued' ) );
		$this->assertStringContainsString( 'diluxone-users-menu__avatar', implode( '', (array) wp_styles()->get_data( 'diluxone-users-menu', 'after' ) ) );
	}

	public function test_on_a_network_a_site_to_join_is_offered_in_the_menu(): void {
		if ( ! is_multisite() ) {
			$this->markTestSkipped( 'A single site has nobody who is not a member; the single-site menu is test_whoever_can_write_gets_the_dashboard_and_a_member_does_not.' );
		}

		diluxone_users_update_option( DILUXONE_USERS_MEMBERSHIP_CONFIRMED, 1 );
		diluxone_users_update_option( 'diluxone_users_membership', 'click' );
		$user = $this->make_user();
		$site = $this->other_site();

		switch_to_blog( $site );
		$menu = $this->chosen_menu();
		wp_set_current_user( $user );

		$said = $this->menu_said( $menu );

		$this->assertArrayHasKey( 'Join this site', $said );
		$this->assertStringContainsString( 'diluxone-users-menu--join', $said['Join this site'] );
		$this->assertArrayNotHasKey( 'This site is by invitation', $said );

		$join = '';
		foreach ( diluxone_users_menu_items( array(), (object) array( 'menu' => $menu ) ) as $item ) {
			if ( 'Join this site' === $item->title ) {
				$join = (string) $item->url;
			}
		}
		$this->assertSame( 'diluxone_users_join', $this->queryArg( $join, 'action' ) );
		$this->assertSame( wp_create_nonce( 'diluxone_users_join' ), $this->queryArg( $join, '_wpnonce' ) );

		diluxone_users_update_option( 'diluxone_users_membership', 'invite' );
		$said = $this->menu_said( $menu );

		$this->assertArrayHasKey( 'This site is by invitation', $said );
		$this->assertArrayNotHasKey( 'Join this site', $said );
	}

	/* ── Joining, around the edges ─────────────────────────────────── */

	public function test_a_refused_password_is_not_marked_and_a_single_site_marks_nothing(): void {
		$back = home_url( '/somewhere/' );

		$this->assertSame( $back, diluxone_users_join_mark_password( $back, '', new \WP_Error( 'nope' ) ) );

		if ( ! is_multisite() ) {
			$this->assertSame( $back, diluxone_users_join_mark_password( $back, '', get_userdata( $this->make_user() ) ) );
		}
	}

	public function test_on_a_network_a_password_back_to_a_site_to_join_says_so(): void {
		if ( ! is_multisite() ) {
			$this->markTestSkipped( 'A single site has no other site to join; its half is test_a_refused_password_is_not_marked_and_a_single_site_marks_nothing.' );
		}

		$site = $this->other_site();
		$back = get_home_url( $site, '/' );
		diluxone_users_update_option( 'diluxone_users_membership', 'invite' );
		$user = $this->make_user();

		$this->assertSame( 'join', $this->redirectState( diluxone_users_join_mark_password( $back, '', get_userdata( $user ) ) ) );
	}

	public function test_on_a_network_arriving_back_loads_the_styles_and_says_nothing_to_a_member(): void {
		if ( ! is_multisite() ) {
			$this->markTestSkipped( 'A single site has nothing to join; its half is MembershipTest::test_on_a_single_site_there_is_nothing_to_join_or_to_be_invited_to.' );
		}

		wp_set_current_user( 1 );
		$_GET = array( 'diluxone-users' => 'join-refused' );

		diluxone_users_join_styles();
		$this->assertSame( wp_style_is( 'diluxone-users', 'registered' ), wp_style_is( 'diluxone-users', 'enqueued' ) );

		ob_start();
		diluxone_users_join_notice();
		$this->assertSame( '', (string) ob_get_clean(), 'A member of the site has nothing to be told' );
		$this->assertFalse( diluxone_users_join_drawn() );
	}
}
