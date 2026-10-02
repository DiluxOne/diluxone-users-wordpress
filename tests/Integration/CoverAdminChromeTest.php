<?php
/**
 * What the plugin adds around its screens and inside WordPress's own: the
 * browser tab's title, the stylesheets, the notice that a setting is pinned
 * by code, the design system's pieces, the admin bar, Add New User, and the
 * branches of the Overview and the Design screen that a fresh site never
 * reaches.
 *
 * Every case runs on both topologies unless it says otherwise.
 */

namespace Tests\Integration;

use Tests\Integration\Support\CoverAdminSupport;

/**
 * A setting pinned by code, the way a site's own plugin would pin it: by a
 * named function on the `diluxone_users_option` filter.
 *
 * @param mixed $value
 * @return mixed
 */
function cover_admin_pin_radius( $value, string $key ) {
	return 'diluxone_users_style_radius' === $key ? 99 : $value;
}

class CoverAdminChromeTest extends IntegrationTestCase {

	use CoverAdminSupport;

	/** @var array<int, array{0: string, 1: callable, 2: int}> */
	private array $filters = array();

	protected function setUp(): void {
		parent::setUp();

		require_once ABSPATH . 'wp-admin/includes/admin.php';
	}

	protected function tearDown(): void {
		foreach ( $this->filters as $filter ) {
			remove_filter( $filter[0], $filter[1], $filter[2] );
		}

		$this->filters = array();

		foreach ( array( 'diluxone-users-admin', 'diluxone-users-new-user', 'diluxone-users', 'diluxone-users-social' ) as $handle ) {
			wp_dequeue_style( $handle );
			wp_dequeue_script( $handle );
		}

		$this->cover_admin_reset();

		parent::tearDown();
	}

	private function filter( string $hook, callable $callback, int $priority = 10, int $args = 1 ): void {
		add_filter( $hook, $callback, $priority, $args );
		$this->filters[] = array( $hook, $callback, $priority );
	}

	/* ── admin.php ─────────────────────────────────────────────────── */

	public function test_the_browser_tab_names_the_plugin_on_its_own_screens_only(): void {
		$this->assertSame( 'Fields ‹ Site', diluxone_users_admin_title( 'Fields ‹ Site', 'Fields' ), 'no screen yet' );

		require_once ABSPATH . 'wp-admin/includes/class-wp-screen.php';
		require_once ABSPATH . 'wp-admin/includes/screen.php';

		$GLOBALS['current_screen'] = \WP_Screen::get( 'users' );
		$this->assertSame( 'Users ‹ Site', diluxone_users_admin_title( 'Users ‹ Site', 'Users' ) );

		$GLOBALS['current_screen'] = \WP_Screen::get( 'diluxone-users_page_diluxone-users-fields' );
		$this->assertSame( diluxone_users_screen_title( 'Fields' ) . ' ‹ Site', diluxone_users_admin_title( 'Fields ‹ Site', 'Fields' ) );
	}

	public function test_one_tab_is_no_navigation(): void {
		$this->assertSame( '', $this->draw( 'diluxone_users_tabs', 'diluxone-users-fields', array( 'list' => 'Fields' ), 'list' ) );
		$tabs = $this->draw( 'diluxone_users_tabs', 'diluxone-users-fields', array( 'list' => 'Fields', 'usage' => 'Usage' ), 'list' );
		$this->assertSame( 1, substr_count( $tabs, 'nav-tab-active' ), 'one tab is the current one' );
		$this->assertMatchesRegularExpression( '/class="nav-tab nav-tab-active" href="[^"]*tab=list[^"]*"/', $tabs, 'and it is the one asked for' );
	}

	public function test_a_setting_pinned_by_code_says_so_and_names_where(): void {
		$this->assertSame( '', $this->draw( 'diluxone_users_forced_notice', 'diluxone_users_style_radius' ), 'nothing pinned, nothing said' );

		$this->filter( 'diluxone_users_option', __NAMESPACE__ . '\cover_admin_pin_radius', 10, 2 );

		$html = $this->draw( 'diluxone_users_forced_notice', 'diluxone_users_style_radius' );

		$this->assertStringContainsString( 'This site fixes this from code', $html );
		$this->assertStringContainsString( 'CoverAdminChromeTest.php', $html, 'the file that pins it' );
	}

	public function test_the_stylesheets_go_where_they_are_used(): void {
		diluxone_users_admin_styles( 'index.php' );
		$this->assertFalse( wp_style_is( 'diluxone-users-admin', 'enqueued' ), 'not on WordPress’s other screens' );

		diluxone_users_admin_styles( 'users.php' );
		$this->assertTrue( wp_style_is( 'diluxone-users-admin', 'enqueued' ), 'the Users list wears the pills' );
		$this->assertFalse( wp_style_is( 'diluxone-users', 'enqueued' ), 'and no preview stylesheet' );
	}

	/* ── The design system's pieces ───────────────────────────────── */

	public function test_the_pieces_draw_nothing_when_they_have_nothing(): void {
		$this->assertSame( '', $this->draw( 'diluxone_users_ui_aside_state', '  ', 'active' ) );
		$this->assertSame( '', $this->draw( 'diluxone_users_ui_links', 'Title', array() ) );

		$this->filter( 'get_pages', '__return_empty_array' );
		$this->assertSame( '', $this->draw( 'diluxone_users_ui_page_dropdown', array( 'name' => 'p', 'id' => 'p' ) ) );
	}

	public function test_a_link_elsewhere_says_it_opens_a_new_tab(): void {
		$html = $this->draw(
			'diluxone_users_ui_links',
			'',
			array(
				array(
					'url'      => 'https://example.org/',
					'label'    => 'Docs',
					'external' => true,
				),
			)
		);

		$this->assertStringContainsString( 'target="_blank" rel="noopener noreferrer"', $html );
		$this->assertStringContainsString( '(opens in a new tab)', $html );
		$this->assertStringNotContainsString( 'du-links__title', $html );
	}

	public function test_a_rewritten_text_says_whose_words_it_is_and_offers_the_way_back(): void {
		$html = $this->draw(
			'diluxone_users_ui_rewritable',
			array(
				'label'    => 'Sign-in e-mail',
				'help'     => 'What it is for.',
				'language' => 'Spanish',
				'revert'   => 'diluxone_users_revert_x',
				'name'     => 'login_link',
			),
			static function (): void {
				echo '<textarea name="x"></textarea>';
			}
		);

		$this->assertStringContainsString( 'Written by this site, in Spanish.', $html );
		$this->assertStringContainsString( 'class="du-fold__help"', $html );
		$this->assertStringContainsString( 'name="diluxone_users_revert_x"', $html );
		$this->assertStringContainsString( 'data-diluxone-users-rewritable="login_link"', $html );

		$html = $this->draw( 'diluxone_users_ui_fold', array( 'label' => 'Folded' ), '__return_null' );
		$this->assertStringNotContainsString( 'du-fold__help', $html, 'no help, no line for it' );

		$html = $this->draw( 'diluxone_users_ui_rewritable', array( 'label' => 'Plain' ), '__return_null' );
		$this->assertStringContainsString( 'As the plugin says it.', $html );
		$this->assertStringNotContainsString( 'Put the plugin’s own words back', $html );
	}

	public function test_a_group_that_needs_one_answer_counts_lists_and_refuses_noughts(): void {
		$this->assertTrue( diluxone_users_ui_needs_one( array( array(), array( 'a' ) ), 'x' ) );

		$html = $this->draw(
			function (): void {
				$this->assertFalse( diluxone_users_ui_needs_one( array( array(), '', '0', null ), 'Pick one.' ) );
			}
		);

		$this->assertStringContainsString( 'Pick one.', $html );
		$this->assertStringContainsString( 'notice-error', $html );
	}

	/* ── The admin bar ─────────────────────────────────────────────── */

	public function test_the_admin_bar_goes_for_who_is_chosen_and_stays_for_who_edits_people(): void {
		$person = $this->make_user();

		// Somebody who edits people: on a network, only its super admins do.
		$admin = $this->the_admin();

		wp_set_current_user( $person );
		$this->assertTrue( diluxone_users_admin_bar_show( true ), 'WordPress’s own answer by default' );

		diluxone_users_update_option( 'diluxone_users_admin_bar', 'hide' );
		$this->assertFalse( diluxone_users_admin_bar_show( true ) );

		wp_set_current_user( $admin );
		$this->assertTrue( diluxone_users_admin_bar_show( true ), 'whoever edits people keeps it' );

		diluxone_users_update_option( 'diluxone_users_admin_bar_keep_admins', 0 );
		$this->assertFalse( diluxone_users_admin_bar_show( true ) );

		diluxone_users_update_option( 'diluxone_users_admin_bar_scope', 'some' );
		diluxone_users_update_option( 'diluxone_users_admin_bar_roles', array( 'subscriber' ) );
		$this->assertTrue( diluxone_users_admin_bar_show( true ), 'only the roles chosen' );

		wp_set_current_user( $person );
		$this->assertFalse( diluxone_users_admin_bar_show( true ) );
	}

	public function test_edit_profile_points_at_the_account_area_on_the_front_only(): void {
		diluxone_users_update_option( 'diluxone_users_bar_account', 1 );

		$this->assertSame( 'https://example.test/wp-admin/profile.php', diluxone_users_edit_profile_url( 'https://example.test/wp-admin/profile.php' ), 'inside the dashboard it is the dashboard’s' );

		$this->as_front_end();

		// No account page: nowhere to point at but WordPress's own profile,
		// and never the home page an account address falls back to.
		diluxone_users_update_option( 'diluxone_users_account_page', 0 );
		$this->assertSame( 'https://example.test/wp-admin/profile.php', diluxone_users_edit_profile_url( 'https://example.test/wp-admin/profile.php' ) );

		$page = (int) wp_insert_post(
			array(
				'post_type'   => 'page',
				'post_status' => 'publish',
				'post_title'  => 'Account',
			)
		);
		diluxone_users_update_option( 'diluxone_users_account_page', $page );

		$this->assertSame( get_permalink( $page ), diluxone_users_edit_profile_url( 'https://example.test/wp-admin/profile.php' ) );

		diluxone_users_update_option( 'diluxone_users_bar_account', 0 );
		$this->assertSame( 'https://example.test/wp-admin/profile.php', diluxone_users_edit_profile_url( 'https://example.test/wp-admin/profile.php' ) );

		wp_delete_post( $page, true );
	}

	/* ── The overview ──────────────────────────────────────────────── */

	public function test_the_overview_counts_this_sites_people_and_their_sessions_for_a_quarter_hour(): void {
		delete_transient( 'diluxone_users_home_numbers' );
		$before = diluxone_users_home_numbers();
		delete_transient( 'diluxone_users_home_numbers' );

		$one = $this->make_user();
		$this->make_user();
		\WP_Session_Tokens::get_instance( $one )->create( time() + HOUR_IN_SECONDS );

		$now = diluxone_users_home_numbers();
		$this->assertSame( $before['users'] + 2, $now['users'] );
		$this->assertSame( $before['sessions'] + 1, $now['sessions'] );

		$this->make_user();
		$this->assertSame( $now, diluxone_users_home_numbers(), 'kept for a quarter of an hour' );

		$timeout = (int) get_option( '_transient_timeout_diluxone_users_home_numbers' );
		$this->assertEqualsWithDelta( time() + 15 * MINUTE_IN_SECONDS, $timeout, 5 );

		delete_transient( 'diluxone_users_home_numbers' );
		$this->assertSame( $before['users'] + 3, diluxone_users_home_numbers()['users'] );

		if ( is_multisite() ) {
			// Somebody with no role on this site is not one of its people.
			diluxone_users_update_option( 'diluxone_users_membership', 'invite' );
			wpmu_create_user( 'nohere' . strtolower( wp_generate_password( 8, false ) ), wp_generate_password( 16 ), wp_generate_password( 8, false ) . '@example.test' );
			delete_transient( 'diluxone_users_home_numbers' );
			$this->assertSame( $before['users'] + 3, diluxone_users_home_numbers()['users'] );

			// Another site counts its own people, not the hub's.
			$site = (int) wp_insert_site(
				array(
					'domain' => (string) get_network()->domain,
					'path'   => '/home-numbers-' . strtolower( wp_generate_password( 6, false ) ) . '/',
					'title'  => 'Counted',
				)
			);
			switch_to_blog( $site );
			delete_transient( 'diluxone_users_home_numbers' );
			$this->make_user();
			$this->assertSame( count( get_users( array( 'blog_id' => $site, 'fields' => 'ID' ) ) ), diluxone_users_home_numbers()['users'] );
			delete_transient( 'diluxone_users_home_numbers' );
			restore_current_blog();
			wp_delete_site( $site );
		}

		delete_transient( 'diluxone_users_home_numbers' );
	}

	public function test_the_first_steps_are_done_as_the_settings_are(): void {
		diluxone_users_update_option( 'diluxone_users_login_page', 0 );
		diluxone_users_update_option( 'diluxone_users_account_page', 0 );
		diluxone_users_delete_option( 'diluxone_users_mail_last' );

		$this->assertSame( array( false, false, false ), array_column( diluxone_users_setup_steps(), 'done' ), 'mail never tried is not checked' );

		diluxone_users_update_option( 'diluxone_users_login_page', 5 );
		$this->assertSame( array( true, false, false ), array_column( diluxone_users_setup_steps(), 'done' ) );

		diluxone_users_update_option( 'diluxone_users_account_page', 6 );
		diluxone_users_update_option( 'diluxone_users_mail_last', array( 'ok' => 0, 'time' => time() ) );
		$this->assertSame( array( true, true, false ), array_column( diluxone_users_setup_steps(), 'done' ), 'mail that failed is not done' );

		diluxone_users_update_option( 'diluxone_users_mail_last', array( 'ok' => 1, 'time' => time() ) );
		$this->assertSame( array( true, true, true ), array_column( diluxone_users_setup_steps(), 'done' ) );

		diluxone_users_delete_option( 'diluxone_users_mail_last' );
	}

	/* ── Add New User ──────────────────────────────────────────────── */

	public function test_add_new_user_takes_the_email_as_the_username_behind_its_nonce(): void {
		$this->single_only( 'test_on_a_network_add_new_user_is_left_as_wordpress_has_it' );

		$admin = $this->the_admin();

		$_POST = array( 'email' => 'x@example.test' );
		diluxone_users_admin_new_user_login();
		$this->assertArrayNotHasKey( 'user_login', $_POST, 'not the Add New User form' );

		$this->postAs( $admin, array( '_wpnonce_create-user' => wp_create_nonce( 'create-user' ), 'action' => 'adduser', 'email' => 'x@example.test' ) );
		diluxone_users_admin_new_user_login();
		$this->assertArrayNotHasKey( 'user_login', $_POST, 'adding an existing person is not creating one' );

		$this->postAs( $admin, array( '_wpnonce_create-user' => 'nope', 'action' => 'createuser', 'email' => 'x@example.test' ) );
		$this->expectDie( 'diluxone_users_admin_new_user_login', self::EXPIRED, 403 );

		$this->postAs( $admin, array( '_wpnonce_create-user' => wp_create_nonce( 'create-user' ), 'action' => 'createuser', 'email' => 'new.one@example.test' ) );
		diluxone_users_admin_new_user_login();
		$this->assertSame( 'new.one@example.test', $_POST['user_login'] );

		diluxone_users_admin_new_user_script( 'user-new.php' );
		$this->assertTrue( wp_script_is( 'diluxone-users-new-user', 'enqueued' ) );
	}

	public function test_on_a_network_add_new_user_is_left_as_wordpress_has_it(): void {
		$this->network_only( 'test_add_new_user_takes_the_email_as_the_username_behind_its_nonce' );

		$admin = $this->the_admin();

		$this->postAs( $admin, array( '_wpnonce_create-user' => wp_create_nonce( 'create-user' ), 'action' => 'createuser', 'email' => 'new.one@example.test' ) );
		diluxone_users_admin_new_user_login();
		$this->assertArrayNotHasKey( 'user_login', $_POST );

		diluxone_users_admin_new_user_script( 'user-new.php' );
		$this->assertFalse( wp_script_is( 'diluxone-users-new-user', 'enqueued' ) );
	}

	/* ── The Overview ──────────────────────────────────────────────── */

	public function test_the_overview_with_no_accounts_has_nothing_to_count(): void {
		$this->the_admin();
		$this->filter(
			'pre_count_users',
			static fn() => array(
				'total_users' => 0,
				'avail_roles' => array(),
			)
		);

		$html = $this->draw( 'diluxone_users_panel_usage' );

		$this->assertStringContainsString( 'There are no accounts yet, so there is nothing to count.', $html );
		$this->assertStringNotContainsString( 'diluxone-users-usage', $html );
	}

	public function test_the_overview_lists_what_people_are_asked_for_by_group(): void {
		$this->the_admin();
		diluxone_users_update_option(
			'diluxone_users_fields',
			array(
				array( 'key' => 'first_name', 'label' => 'First name', 'group' => 'main' ),
				array( 'key' => 'diluxone_users_shoe', 'label' => 'Shoe', 'group' => 'extra' ),
				array( 'key' => 'diluxone_users_off', 'label' => 'Hidden one', 'group' => 'extra', 'active' => 0 ),
			)
		);

		$html = $this->draw( 'diluxone_users_panel_asked' );

		$this->assertStringContainsString( 'First name', $html );
		$this->assertStringContainsString( 'Shoe', $html );
		$this->assertStringNotContainsString( 'Hidden one', $html, 'a hidden field is asked of nobody' );

		// A group with nothing active in it gets no row.
		diluxone_users_update_option( 'diluxone_users_fields', array( array( 'key' => 'first_name', 'label' => 'First name', 'group' => 'main' ) ) );
		$html = $this->draw( 'diluxone_users_panel_asked' );
		$this->assertSame( 1, substr_count( $html, '<th scope="row">' ) );
	}

	/* ── Design › Your brand ───────────────────────────────────────── */

	public function test_the_look_is_the_sites_when_the_stylesheet_is_off(): void {
		diluxone_users_update_option( 'diluxone_users_styles', 0 );
		$this->assertSame( 'site', diluxone_users_brand_look() );

		diluxone_users_update_option( 'diluxone_users_styles', 1 );
		diluxone_users_update_option( 'diluxone_users_colors', 'theme' );
		$this->assertSame( 'theme', diluxone_users_brand_look() );
	}

	public function test_colours_from_a_theme_with_no_palette_keep_the_accent_and_say_what_is_measured(): void {
		$this->the_admin();
		diluxone_users_update_option( 'diluxone_users_colors', 'theme' );
		diluxone_users_update_option( 'diluxone_users_style_accent', '#123456' );
		$this->filter( 'diluxone_users_theme_palette', '__return_empty_array', 99 );

		$own = $this->draw( 'diluxone_users_brand_own_colour' );
		$this->assertStringContainsString( '<input type="hidden" name="diluxone_users_style_accent" value="#123456">', $own, 'the colour picked is kept while the picker is off' );

		$html = $this->draw( 'diluxone_users_screen_design_brand' );
		$this->assertStringContainsString( 'Your theme publishes no palette', $html );
	}

	/* ── Security › Summary ────────────────────────────────────────── */

	/**
	 * In a process of its own: the panel registry is a static that lives as
	 * long as the process, and here nothing has registered into it yet.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_security_gets_a_summary_only_when_it_has_tabs_to_sum_up(): void {
		$this->assertSame( array(), diluxone_users_panel_registry( DILUXONE_USERS_SECURITY ) );

		diluxone_users_security_summary_panel();
		$this->assertSame( array(), diluxone_users_panel_registry( DILUXONE_USERS_SECURITY ), 'a Summary of nothing is not added' );

		do_action( 'diluxone_users_register_panels' );
		$this->assertArrayHasKey( 'summary', diluxone_users_panel_registry( DILUXONE_USERS_SECURITY ) );
	}
}
