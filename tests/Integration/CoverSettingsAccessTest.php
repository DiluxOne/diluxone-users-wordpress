<?php
/**
 * The Access screen: the ways in, how they are arranged, registration, the
 * messages the way in shows, and the tabs that read them back.
 *
 * Access is the hub's: on a network it is saved from the main site's
 * dashboard by its administrator, and Network Admin has no Access screen.
 * Every case runs on both topologies unless it names its counterpart.
 */

namespace Tests\Integration;

use Tests\Integration\Support\CoverSettingsSupport;

class CoverSettingsAccessTest extends IntegrationTestCase {

	use CoverSettingsSupport;

	/** @var mixed WordPress's own registration switch before the test. */
	private $users_can_register;

	/** @var mixed The stored rewrite version before the test: the registration save deletes it. */
	private $rewrite_before;

	protected function setUp(): void {
		parent::setUp();

		$this->users_can_register = $this->can_register();
		$this->rewrite_before     = diluxone_users_raw_get( 'diluxone_users_rewrite_version' );

		do_action( 'diluxone_users_register_panels' );
	}

	protected function tearDown(): void {
		$this->cover_settings_reset();

		update_option( 'users_can_register', $this->users_can_register );

		if ( false === $this->rewrite_before ) {
			diluxone_users_delete_option( 'diluxone_users_rewrite_version' );
		} else {
			diluxone_users_update_option( 'diluxone_users_rewrite_version', $this->rewrite_before );
		}

		remove_filter( 'diluxone_users_login_messages', array( $this, 'messages_reshaped' ) );

		// A map of languages and not a setting with a default: the base class
		// does not know it, so it is cleaned here.
		diluxone_users_delete_option( DILUXONE_USERS_LOGIN_MESSAGES );
		remove_filter( 'diluxone_users_register_roles', array( $this, 'editor_offered' ) );
		remove_filter( 'diluxone_users_register_roles', array( $this, 'author_offered' ) );

		parent::tearDown();
	}

	/**
	 * WordPress's own registration switch as stored on this site.
	 *
	 * Read from the table and not through get_option(): on a network a filter
	 * answers it with the network's registration setting instead.
	 */
	private function can_register(): string {
		global $wpdb;

		wp_cache_delete( 'alloptions', 'options' );
		wp_cache_delete( 'users_can_register', 'options' );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		return (string) $wpdb->get_var( "SELECT option_value FROM {$wpdb->options} WHERE option_name = 'users_can_register'" );
	}

	/** A published page, deleted with the rest of the test's posts. */
	private function page( string $title ): int {
		return (int) wp_insert_post(
			array(
				'post_type'   => 'page',
				'post_status' => 'publish',
				'post_title'  => $title,
			)
		);
	}

	/** Google working and shown, with the social buttons on. */
	private function google_works(): void {
		diluxone_users_update_option(
			'diluxone_users_sso',
			array(
				'google' => array(
					'active' => 1,
					'id'     => 'g-id',
					'secret' => 'g-secret',
					'tested' => 1,
				),
			),
			false
		);
		diluxone_users_update_option( 'diluxone_users_sso_login', 1 );
	}

	/** The Access screen on one tab, as its administrator sees it. */
	private function screen( string $tab, array $get = array() ): string {
		$_GET = array(
			'page' => 'diluxone-users-login',
			'tab'  => $tab,
		) + $get;

		return $this->draw( 'diluxone_users_screen_login' );
	}

	/* ── The panels' saves refuse a bad request ────────────────────── */

	/** @return array<string, array{0: string, 1: array<string, mixed>, 2: string, 3?: string}> */
	public static function saves(): array {
		return array(
			'the page'         => array( 'diluxone_users_login_page_save', array( 'diluxone_users_lost_password' => 'link' ), 'diluxone_users_lost_password' ),
			'the ways in'      => array( 'diluxone_users_login_ways_save', array( 'diluxone_users_login_method' => array( 'link' ) ), 'diluxone_users_login_method' ),
			'the arrangement'  => array( 'diluxone_users_login_arrangement_save', array( 'diluxone_users_login_layout' => 'tabs' ), 'diluxone_users_login_layout' ),
			'registration'     => array( 'diluxone_users_screen_register_save', array( 'diluxone_users_register_open' => 'open', 'diluxone_users_register_form' => '1' ), 'diluxone_users_register_form', 'diluxone-users-register' ),
			'the messages'     => array( 'diluxone_users_login_messages_save', array( 'diluxone_users_message' => array( 'login_expired' => 'Mine' ) ), 'diluxone_users_login_messages' ),
		);
	}

	/**
	 * @dataProvider saves
	 *
	 * @param array<string, mixed> $post
	 */
	public function test_each_save_stops_on_a_bad_nonce_or_a_missing_capability( string $save, array $post, string $option, string $screen = 'diluxone-users-login' ): void {
		$before = diluxone_users_raw_get( $option );

		$this->the_admin();
		$this->postAs( get_current_user_id(), $post + array( 'diluxone_users_panel_nonce' => 'not-a-nonce' ) );
		$this->assertSame( array( 'died', self::EXPIRED ), $this->ended( $save ), 'a bad nonce' );

		$this->send_panel( 'diluxone-users-design', $post );
		$this->assertSame( array( 'died', self::EXPIRED ), $this->ended( $save ), 'another screen’s nonce' );

		$this->not_allowed();
		$this->send_panel( $screen, $post );
		$this->assertSame( array( 'died', 'You are not allowed to do this.' ), $this->ended( $save ), 'a subscriber' );

		$this->assertSame( $before, diluxone_users_raw_get( $option ) );
	}

	/* ── The ways in ───────────────────────────────────────────────── */

	public function test_no_way_in_at_all_is_refused_and_nothing_is_written(): void {
		$this->the_admin();
		diluxone_users_update_option( 'diluxone_users_login_method', 'link' );

		$this->send_panel( 'diluxone-users-login', array( 'diluxone_users_login_method' => array() ) );

		list( $how, $saved ) = $this->ended( 'diluxone_users_login_ways_save' );

		$this->assertSame( array( 'returned', false ), array( $how, $saved ) );
		$this->assertSame( 'link', diluxone_users_option( 'diluxone_users_login_method' ) );
		$this->assertStringContainsString( diluxone_users_login_ways_needs_one(), $this->draw( 'diluxone_users_ui_needs_one', array(), diluxone_users_login_ways_needs_one() ), 'and it says why' );
	}

	public function test_the_page_save_keeps_its_answers_to_their_lists(): void {
		$this->the_admin();
		diluxone_users_update_option( 'diluxone_users_login_method', 'both' );

		$this->send_panel(
			'diluxone-users-login',
			array(
				'diluxone_users_wp_screens'    => 'x',
				'diluxone_users_lost_password' => 'y',
			)
		);
		diluxone_users_login_page_save();

		$this->assertSame( 'auto', diluxone_users_raw_get( 'diluxone_users_wp_screens' ), 'an answer the screen never offers is the default' );
		$this->assertSame( 'wp', diluxone_users_raw_get( 'diluxone_users_lost_password' ) );
		$this->assertSame( 0, (int) diluxone_users_raw_get( 'diluxone_users_login_page' ), 'no page sent is no page' );

		$this->send_panel(
			'diluxone-users-login',
			array(
				'diluxone_users_wp_screens'    => 'wp',
				'diluxone_users_lost_password' => 'site',
			)
		);
		diluxone_users_login_page_save();

		$this->assertSame( 'wp', diluxone_users_raw_get( 'diluxone_users_wp_screens' ) );
		$this->assertSame( 'site', diluxone_users_raw_get( 'diluxone_users_lost_password' ) );
	}

	/* ── Where the page of its own is drawn ───────────────────────── */

	public function test_c3_under_a_page_of_its_own_the_tab_asks_for_the_address_with_no_page(): void {
		$this->the_admin();
		diluxone_users_update_option( 'diluxone_users_login_template', 'own' );
		diluxone_users_update_option( 'diluxone_users_login_page', 0 );
		diluxone_users_update_option( 'diluxone_users_login_slug', 'entrar' );

		$html = $this->screen( 'page' );

		$this->assertStringNotContainsString( 'Not used while', $html, 'this is where the address is decided' );
		$this->assertStringContainsString( '— No page —', $html );
		$this->assertMatchesRegularExpression( '#data-diluxone-users-waits="diluxone_users_login_page" data-diluxone-users-waits-is="0"#', $html, 'the field waits for “No page”' );
		$this->assertMatchesRegularExpression( '#<input type="text" id="diluxone_users_login_slug" name="diluxone_users_login_slug" class="regular-text code" value="entrar" required>#', $html, 'with no page chosen, it is let go' );
		$this->assertStringContainsString( '<code>' . esc_html( trailingslashit( home_url() ) ) . '</code>', $html, 'the site’s address before it' );

		diluxone_users_update_option( 'diluxone_users_login_page', $this->page( 'Cover own page' ) );

		$this->assertMatchesRegularExpression( '#name="diluxone_users_login_slug"[^>]* disabled data-diluxone-users-waited#', $this->screen( 'page' ), 'a page chosen: drawn at its address, the field waits' );
	}

	/**
	 * The block of the address that waits on the drop-down for these values.
	 *
	 * @param string $key 'is' or 'not'.
	 */
	private function address_block( string $html, string $key, string $values ): string {
		$this->assertMatchesRegularExpression(
			'#<div class="du-waits-on du-waits-on--hides( is-waiting)?" data-diluxone-users-waits="diluxone_users_login_page" data-diluxone-users-waits-' . $key . '="' . preg_quote( $values, '#' ) . '">#',
			$html,
			"a block hidden while it waits, for {$key} {$values}"
		);

		preg_match( '#<div class="du-waits-on du-waits-on--hides( is-waiting)?" data-diluxone-users-waits="diluxone_users_login_page" data-diluxone-users-waits-' . $key . '="' . preg_quote( $values, '#' ) . '">(.*?)</div>#s', $html, $block );

		return (string) $block[0];
	}

	/** Pretty permalinks or plain ones, as the rewrite object reads them. */
	private function permalinks( string $structure ): void {
		update_option( 'permalink_structure', $structure );
		$GLOBALS['wp_rewrite']->init();
	}

	public function test_c1_a_page_chosen_shows_its_address_as_a_link_and_no_slug(): void {
		$this->the_admin();
		$before = (string) get_option( 'permalink_structure' );
		$this->permalinks( '/%postname%/' );

		try {
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
			diluxone_users_update_option( 'diluxone_users_login_slug', 'sign-in' );

			$html = $this->screen( 'page' );
			$url  = home_url( '/entrar/' );

			$shown = $this->address_block( $html, 'is', (string) $page );
			$this->assertStringNotContainsString( 'is-waiting', $shown, 'the address in use is shown' );
			$this->assertStringContainsString( '<a href="' . esc_url( $url ) . '"><code>' . esc_html( $url ) . '</code></a>', $shown, 'as a link to the page' );
			$this->assertStringContainsString( 'It is the address of the page chosen above, and it changes with that page.', $shown );
			$this->assertStringNotContainsString( 'sign-in', $shown );

			$this->assertStringContainsString( 'is-waiting', $this->address_block( $html, 'not', '0 ' . $page ), 'the line for a page not yet saved is hidden' );

			$slug = $this->address_block( $html, 'is', '0' );
			$this->assertStringContainsString( 'du-waits-on--hides is-waiting', $slug, 'the slug field is hidden' );
			$this->assertMatchesRegularExpression( '#name="diluxone_users_login_slug"[^>]* disabled data-diluxone-users-waited#', $slug, 'and sends nothing' );
		} finally {
			$this->permalinks( $before );
		}
	}

	public function test_c2_no_page_shows_the_slug_field_enabled(): void {
		$this->the_admin();
		diluxone_users_update_option( 'diluxone_users_login_template', 'own' );
		diluxone_users_update_option( 'diluxone_users_login_page', 0 );
		diluxone_users_update_option( 'diluxone_users_login_slug', 'entrar' );

		$html = $this->screen( 'page' );
		$slug = $this->address_block( $html, 'is', '0' );

		$this->assertStringNotContainsString( 'is-waiting', $slug, 'the slug field is not waiting' );
		$this->assertStringContainsString( '<input type="text" id="diluxone_users_login_slug" name="diluxone_users_login_slug" class="regular-text code" value="entrar" required>', $slug, 'and it is editable' );
		$this->assertStringContainsString( 'is-waiting', $this->address_block( $html, 'not', '0' ), 'the line for a page picked is hidden' );
		$this->assertStringNotContainsString( 'It is the address of the page chosen above', $html, 'no page, no page address' );
	}

	public function test_c3_no_page_and_an_address_saved_is_where_the_page_of_its_own_is_drawn(): void {
		$this->the_admin();
		diluxone_users_update_option( 'diluxone_users_login_template', 'own' );
		diluxone_users_update_option( 'diluxone_users_login_page', $this->page( 'Cover own page' ) );
		diluxone_users_update_option( 'diluxone_users_login_slug', 'sign-in' );
		diluxone_users_update_option( 'diluxone_users_login_slug_old', array() );

		$was = diluxone_users_login_slug();

		$this->send_panel(
			'diluxone-users-login',
			array(
				'diluxone_users_login_page' => '0',
				'diluxone_users_login_slug' => '/Entrar/',
			)
		);

		$this->assertTrue( diluxone_users_login_page_save() );
		$this->assertSame( 0, (int) diluxone_users_raw_get( 'diluxone_users_login_page' ) );
		$this->assertSame( 'entrar', diluxone_users_raw_get( 'diluxone_users_login_slug' ) );
		$this->assertSame( 'entrar', diluxone_users_login_slug() );
		$this->assertSame( home_url( user_trailingslashit( 'entrar' ) ), diluxone_users_door_url(), 'drawn at that address' );
		$this->assertSame( array(), diluxone_users_login_slug_old(), 'the page left keeps its own address: it is still a page' );
		$this->assertNotSame( 'entrar', $was );
	}

	public function test_c3_a_refused_address_writes_nothing_and_says_why(): void {
		$this->the_admin();
		diluxone_users_update_option( 'diluxone_users_login_template', 'own' );
		diluxone_users_update_option( 'diluxone_users_login_page', 0 );
		diluxone_users_update_option( 'diluxone_users_login_slug', 'sign-in' );
		diluxone_users_update_option( 'diluxone_users_lost_password', 'wp' );

		wp_insert_post(
			array(
				'post_type'   => 'page',
				'post_status' => 'publish',
				'post_title'  => 'About us',
				'post_name'   => 'cover-about',
			)
		);

		$this->send_panel(
			'diluxone-users-login',
			array(
				'diluxone_users_login_page'    => '0',
				'diluxone_users_login_slug'    => 'cover-about',
				'diluxone_users_lost_password' => 'site',
			)
		);

		ob_start();
		$saved = diluxone_users_login_page_save();
		$said  = (string) ob_get_clean();

		$this->assertFalse( $saved );
		$this->assertStringContainsString( 'notice-error', $said );
		$this->assertStringContainsString( 'The address /cover-about/ is already the page “About us”.', $said );
		$this->assertSame( 'sign-in', diluxone_users_raw_get( 'diluxone_users_login_slug' ), 'nothing written' );
		$this->assertSame( 'wp', diluxone_users_raw_get( 'diluxone_users_lost_password' ), 'nothing of the tab written' );
	}

	public function test_c3_the_page_left_for_no_page_is_not_an_address_to_take(): void {
		$this->the_admin();
		diluxone_users_update_option( 'diluxone_users_login_template', 'own' );

		$page = (int) wp_insert_post(
			array(
				'post_type'   => 'page',
				'post_status' => 'publish',
				'post_title'  => 'Entrar',
				'post_name'   => 'cover-entrar',
			)
		);
		diluxone_users_update_option( 'diluxone_users_login_page', $page );

		$this->send_panel(
			'diluxone-users-login',
			array(
				'diluxone_users_login_page' => '0',
				'diluxone_users_login_slug' => 'cover-entrar',
			)
		);

		ob_start();
		$this->assertFalse( diluxone_users_login_page_save(), 'with no page chosen, that page is a page like any other' );
		ob_end_clean();
		$this->assertSame( $page, (int) diluxone_users_raw_get( 'diluxone_users_login_page' ) );
	}

	/** @return array<string, array{0: string}> */
	public static function other_shapes(): array {
		return array(
			'In the page'    => array( 'plain' ),
			'A centred card' => array( 'card' ),
		);
	}

	/**
	 * @dataProvider other_shapes
	 *
	 * @param string $shape The shape chosen on Design › The sign-in page.
	 */
	public function test_c6_another_shape_draws_no_address_and_the_save_stores_what_it_did( string $shape ): void {
		$this->the_admin();
		diluxone_users_update_option( 'diluxone_users_login_template', $shape );
		diluxone_users_update_option( 'diluxone_users_login_page', 0 );
		diluxone_users_update_option( 'diluxone_users_login_slug', 'sign-in' );
		diluxone_users_update_option( 'diluxone_users_login_slug_old', array() );
		diluxone_users_update_option( 'diluxone_users_wp_screens', 'auto' );
		diluxone_users_update_option( 'diluxone_users_lost_password', 'wp' );

		$html = $this->screen( 'page' );

		$this->assertStringNotContainsString( 'name="diluxone_users_login_slug"', $html, 'no address field' );
		$this->assertStringNotContainsString( '— No page —', $html );
		$this->assertStringContainsString( '— None: wp-login.php does the job —', $html );
		$this->assertStringContainsString( 'With none chosen, everybody signs in on wp-login.php and nothing below applies.', $html );

		$written = array();
		$keep    = static function ( string $option ) use ( &$written ): void {
			if ( 0 === strpos( $option, 'diluxone_users_' ) ) {
				$written[] = $option;
			}
		};
		$this->hook( 'updated_option', $keep );
		$this->hook( 'added_option', $keep );
		$this->hook( 'update_site_option', $keep );
		$this->hook( 'add_site_option', $keep );

		$page = $this->page( 'Cover other shape' );

		$this->send_panel(
			'diluxone-users-login',
			array(
				'diluxone_users_login_page'    => (string) $page,
				'diluxone_users_login_slug'    => 'entrar',
				'diluxone_users_wp_screens'    => 'mine',
				'diluxone_users_lost_password' => 'site',
			)
		);

		$this->assertTrue( diluxone_users_login_page_save() );

		sort( $written );
		$this->assertSame( array( 'diluxone_users_login_page', 'diluxone_users_lost_password', 'diluxone_users_wp_screens' ), array_values( array_unique( $written ) ), 'the keys it stores today, and no address' );
		$this->assertSame( $page, (int) diluxone_users_raw_get( 'diluxone_users_login_page' ) );
		$this->assertSame( 'sign-in', diluxone_users_raw_get( 'diluxone_users_login_slug' ), 'an address sent anyway is not stored' );
		$this->assertSame( array(), diluxone_users_raw_get( 'diluxone_users_login_slug_old' ), 'no old address kept' );
		$this->assertSame( get_permalink( $page ), diluxone_users_login_url(), 'the page holds the form, as today' );
	}

	public function test_the_ways_save_writes_what_the_form_sent_and_nothing_it_did_not(): void {
		$this->the_admin();
		diluxone_users_update_option( 'diluxone_users_handle_login', 1 );
		diluxone_users_update_option( 'diluxone_users_sso_login', 1 );
		// What Users+ Pro stores when passkeys are a way in, with Pro off.
		update_option( 'diluxone_users_passkey_enabled', 1 );

		$this->send_panel(
			'diluxone-users-login',
			array(
				'diluxone_users_login_method'   => array( 'password' ),
				'diluxone_users_login_expiry'   => '30',
				'diluxone_users_login_throttle' => '120',
			)
		);
		diluxone_users_login_ways_save();

		$this->assertSame( 'password', diluxone_users_option( 'diluxone_users_login_method' ) );
		$this->assertSame( 30, (int) diluxone_users_raw_get( 'diluxone_users_login_expiry' ) );
		$this->assertSame( 120, (int) diluxone_users_raw_get( 'diluxone_users_login_throttle' ) );
		$this->assertSame( 0, (int) diluxone_users_raw_get( 'diluxone_users_handle_login' ), 'a box left unticked is off' );

		// On a network these two are the network's, drawn here and switched
		// in Network Admin: the hub's form leaves them as they stand.
		$network = is_multisite() ? 1 : 0;
		$this->assertSame( $network, (int) diluxone_users_raw_get( 'diluxone_users_sso_login' ) );
		$this->assertSame( 1, (int) get_option( 'diluxone_users_passkey_enabled' ), 'an add-on’s box nobody drew is not turned off' );
		delete_option( 'diluxone_users_passkey_enabled' );

		$this->send_panel(
			'diluxone-users-login',
			array(
				'diluxone_users_login_method' => array( 'link', 'password' ),
				'diluxone_users_login_expiry' => 'abc',
			)
		);
		diluxone_users_login_ways_save();

		$this->assertSame( 'both', diluxone_users_option( 'diluxone_users_login_method' ) );
		$this->assertSame( 0, (int) diluxone_users_raw_get( 'diluxone_users_login_expiry' ), 'a word is no number' );
		$this->assertSame( 1, diluxone_users_login_expiry(), 'and a link still lasts a minute' );
		$this->assertSame( 60, (int) diluxone_users_raw_get( 'diluxone_users_login_throttle' ), 'a wait nobody sent is the default' );
	}

	public function test_closing_the_password_closes_wp_login_as_a_second_door(): void {
		$this->the_admin();
		diluxone_users_update_option( 'diluxone_users_login_method', 'both' );
		diluxone_users_update_option( 'diluxone_users_wp_screens', 'wp' );

		$this->send_panel( 'diluxone-users-login', array( 'diluxone_users_login_method' => array( 'link' ) ) );
		diluxone_users_login_ways_save();

		$this->assertSame( 'link', diluxone_users_option( 'diluxone_users_login_method' ) );
		$this->assertSame( 'auto', diluxone_users_raw_get( 'diluxone_users_wp_screens' ) );

		diluxone_users_update_option( 'diluxone_users_wp_screens', 'mine' );
		$this->send_panel( 'diluxone-users-login', array( 'diluxone_users_login_method' => array( 'link' ) ) );
		diluxone_users_login_ways_save();

		$this->assertSame( 'mine', diluxone_users_raw_get( 'diluxone_users_wp_screens' ), 'any other answer is left alone' );
	}

	/* ── How they are arranged ─────────────────────────────────────── */

	public function test_the_arrangement_keeps_only_ways_that_exist(): void {
		$this->the_admin();

		$this->send_panel(
			'diluxone-users-login',
			array(
				'diluxone_users_login_layout' => 'tabs',
				'diluxone_users_login_open'   => 'Password',
				'diluxone_users_login_order'  => array( 'password', 'carrier-pigeon', 'email', 'password', 'passkey' ),
			)
		);
		diluxone_users_login_arrangement_save();

		$this->assertSame( 'tabs', diluxone_users_option( 'diluxone_users_login_layout' ) );
		$this->assertSame( 'password', diluxone_users_option( 'diluxone_users_login_open' ) );
		$this->assertSame( array( 'password', 'email' ), diluxone_users_option( 'diluxone_users_login_order' ), 'no stranger, no repeat, nothing outside the tabs' );

		$this->send_panel(
			'diluxone-users-login',
			array(
				'diluxone_users_login_layout' => 'carousel',
				'diluxone_users_login_open'   => 'passkey',
			)
		);
		diluxone_users_login_arrangement_save();

		$this->assertSame( 'auto', diluxone_users_option( 'diluxone_users_login_layout' ) );
		$this->assertSame( '', diluxone_users_option( 'diluxone_users_login_open' ), 'a way nothing registers is not opened' );
		$this->assertSame( array(), diluxone_users_option( 'diluxone_users_login_order' ) );
	}

	/* ── Registration ──────────────────────────────────────────────── */

	public function test_registration_open_with_no_door_is_refused(): void {
		$this->the_admin();
		update_option( 'users_can_register', 1 );

		// On a network WordPress's own form is the network's door: shut it.
		if ( is_multisite() ) {
			update_site_option( 'registration', 'none' );
		}
		diluxone_users_update_option( 'diluxone_users_register_form', 1 );

		$this->send_panel( 'diluxone-users-register', array( 'diluxone_users_register_open' => 'open' ) );

		$this->assertSame( array( 'returned', false ), $this->ended( 'diluxone_users_screen_register_save' ) );
		$this->assertSame( 1, (int) diluxone_users_option( 'diluxone_users_register_form' ), 'nothing written' );
		$this->assertSame( '1', $this->can_register() );
	}

	public function test_registration_open_saves_the_doors_the_page_and_a_safe_role(): void {
		$this->the_admin();
		diluxone_users_update_option( 'diluxone_users_rewrite_version', DILUXONE_USERS_VERSION );
		$page = $this->page( 'Cover register' );

		$this->send_panel(
			'diluxone-users-register',
			array(
				'diluxone_users_register_open'  => 'open',
				'diluxone_users_login_register' => '1',
				'diluxone_users_wp_register'    => '1',
				'diluxone_users_register_page'  => (string) $page,
				'diluxone_users_login_role'     => 'administrator',
			)
		);

		$this->assertSame( array( 'returned', true ), $this->ended( 'diluxone_users_screen_register_save' ) );

		$this->assertSame( 1, (int) diluxone_users_option( 'diluxone_users_login_register' ) );
		$this->assertSame( 0, (int) diluxone_users_option( 'diluxone_users_sso_register' ) );
		$this->assertSame( 0, (int) diluxone_users_option( 'diluxone_users_register_form' ) );
		$this->assertSame( $page, (int) diluxone_users_option( 'diluxone_users_register_page' ) );
		$this->assertSame( 'subscriber', diluxone_users_option( 'diluxone_users_login_role' ), 'a role that edits the site is not handed to a stranger' );
		$this->assertSame( is_multisite() ? $this->users_can_register : '1', $this->can_register(), 'WordPress’s own switch, written there — on a network, the network’s, untouched' );
		$this->assertFalse( diluxone_users_raw_get( 'diluxone_users_rewrite_version' ), 'the /register/ rules are made again' );
		$this->assertSame( array( 'the e-mail link', 'WordPress’s own form' ), diluxone_users_register_doors_open() );
	}

	/**
	 * A filter for the roles offered: an editor too.
	 *
	 * @param array<string, string> $roles
	 * @return array<string, string>
	 */
	public function editor_offered( array $roles ): array {
		return $roles + array( 'editor' => 'Editor' );
	}

	/**
	 * A filter for the roles offered: an author too.
	 *
	 * @param array<string, string> $roles
	 * @return array<string, string>
	 */
	public function author_offered( array $roles ): array {
		return $roles + array( 'author' => 'Author' );
	}

	public function test_a_role_the_site_offers_on_purpose_is_taken_and_one_never_offered_keeps_the_saved_one(): void {
		$this->the_admin();
		add_filter( 'diluxone_users_register_roles', array( $this, 'author_offered' ) );

		$this->send_panel(
			'diluxone-users-register',
			array(
				'diluxone_users_register_open' => 'open',
				'diluxone_users_register_form' => '1',
				'diluxone_users_login_role'    => 'author',
			)
		);
		diluxone_users_screen_register_save();
		$this->assertSame( 'author', diluxone_users_option( 'diluxone_users_login_role' ) );
		$this->assertSame( 'author', diluxone_users_register_role(), 'and an account is given it' );
		$this->assertContains( 'the site’s own form', diluxone_users_register_doors_open() );

		// Still offered, as the one saved, once the filter is gone.
		remove_filter( 'diluxone_users_register_roles', array( $this, 'author_offered' ) );
		$this->assertArrayHasKey( 'author', diluxone_users_register_roles(), 'the saved one stays on the list' );

		$this->send_panel(
			'diluxone-users-register',
			array(
				'diluxone_users_register_open' => 'open',
				'diluxone_users_register_form' => '1',
				'diluxone_users_login_role'    => 'administrator',
			)
		);
		diluxone_users_screen_register_save();
		$this->assertSame( 'author', diluxone_users_option( 'diluxone_users_login_role' ), 'a role never offered keeps what was saved' );
	}

	/**
	 * A role no account would ever be given is not offered, even through
	 * the filter: the screen saved `editor` while every new account got
	 * subscriber, the screen saying one thing and the site doing another.
	 */
	public function test_a_role_no_account_would_be_given_is_not_offered_even_on_purpose(): void {
		$this->the_admin();
		add_filter( 'diluxone_users_register_roles', array( $this, 'editor_offered' ) );

		try {
			$this->assertArrayNotHasKey( 'editor', diluxone_users_register_roles() );

			$this->send_panel(
				'diluxone-users-register',
				array(
					'diluxone_users_register_open' => 'open',
					'diluxone_users_register_form' => '1',
					'diluxone_users_login_role'    => 'editor',
				)
			);
			diluxone_users_screen_register_save();
		} finally {
			remove_filter( 'diluxone_users_register_roles', array( $this, 'editor_offered' ) );
		}

		$this->assertSame( 'subscriber', diluxone_users_option( 'diluxone_users_login_role' ) );
		$this->assertSame( diluxone_users_register_role(), diluxone_users_option( 'diluxone_users_login_role' ), 'what is saved is what an account gets' );
	}

	/** "Nobody" on a network that still takes accounts says so, with the way to the network's switch. */
	public function test_registration_closed_says_where_the_network_keeps_it_open(): void {
		$this->the_admin();

		$this->send_panel( 'diluxone-users-register', array( 'diluxone_users_register_open' => 'closed' ) );

		ob_start();
		diluxone_users_screen_register_save();
		$said = (string) ob_get_clean();

		if ( is_multisite() && diluxone_users_network_takes_accounts() ) {
			$this->assertStringContainsString( 'data-diluxone-users-network-registration', $said );
			$this->assertStringContainsString( esc_url( network_admin_url( 'settings.php' ) ), $said );
		} else {
			$this->assertStringNotContainsString( 'data-diluxone-users-network-registration', $said, 'Nothing to say where the site’s answer is the whole answer' );
		}
	}

	public function test_registration_closed_shuts_every_door_and_wordpress_own_form(): void {
		$this->the_admin();
		update_option( 'users_can_register', 1 );
		diluxone_users_update_option( 'diluxone_users_register_form', 1 );

		$this->send_panel(
			'diluxone-users-register',
			array(
				'diluxone_users_register_open' => 'closed',
				'diluxone_users_register_form' => '1',
				'diluxone_users_wp_register'   => '1',
			)
		);

		$this->assertSame( array( 'returned', true ), $this->ended( 'diluxone_users_screen_register_save' ) );
		$this->assertSame( 0, (int) diluxone_users_option( 'diluxone_users_register_form' ), 'a ticked door under "nobody" is shut' );
		$this->assertSame( is_multisite() ? '1' : '0', $this->can_register(), 'on a network the network’s switch is left alone' );

		// On a network WordPress's own form is the network's to open: what it
		// says is read through, and every door of the plugin's is shut.
		$this->assertSame( array(), array_values( array_diff( diluxone_users_register_doors_open(), is_multisite() ? array( 'WordPress’s own form' ) : array() ) ) );
	}

	/**
	 * On a network WordPress's own form is the network's: Network Admin's
	 * "Allow new registrations" opens it, and WordPress reads this site's
	 * switch through it. The box saved that switch and changed nothing, so
	 * it is shown as the network has it, not offered, and the save writes
	 * nothing there.
	 */
	public function test_on_a_network_wordpress_own_form_is_the_networks_and_not_offered(): void {
		if ( ! is_multisite() ) {
			$this->markTestSkipped( 'A network case; on a single site the counterpart is test_registration_open_saves_the_doors_the_page_and_a_safe_role.' );
		}

		$this->the_admin();
		update_site_option( 'registration', 'none' );

		$card = array_values( wp_list_filter( diluxone_users_register_doors( array() ), array( 'name' => 'diluxone_users_wp_register' ) ) )[0];
		$this->assertTrue( $card['disabled'], 'not offered' );
		$this->assertFalse( $card['checked'], 'shown as the network has it' );

		$this->send_panel(
			'diluxone-users-register',
			array(
				'diluxone_users_register_open'  => 'open',
				'diluxone_users_login_register' => '1',
				'diluxone_users_wp_register'    => '1',
			)
		);
		$this->assertSame( array( 'returned', true ), $this->ended( 'diluxone_users_screen_register_save' ) );

		$this->assertSame( 'none', get_site_option( 'registration' ), 'the network decides' );
		$this->assertNotContains( 'WordPress’s own form', diluxone_users_register_doors_open() );

		update_site_option( 'registration', 'user' );
		$this->assertContains( 'WordPress’s own form', diluxone_users_register_doors_open(), 'open on the network, open here' );
	}

	public function test_while_the_link_is_the_only_way_in_wordpress_own_form_stays_locked(): void {
		$this->the_admin();
		update_option( 'users_can_register', 0 );
		diluxone_users_update_option( 'diluxone_users_login_method', 'link' );

		$this->send_panel(
			'diluxone-users-register',
			array(
				'diluxone_users_register_open' => 'open',
				'diluxone_users_sso_register'  => '1',
				'diluxone_users_wp_register'   => '1',
			)
		);
		diluxone_users_screen_register_save();

		$this->assertSame( '0', $this->can_register(), 'the lock is not overwritten' );
		$this->assertSame( 1, (int) diluxone_users_option( 'diluxone_users_sso_register' ) );
		$this->assertSame( array(), diluxone_users_register_doors_open(), 'no provider works, so the social door is not open' );

		$this->google_works();
		$this->assertSame( array( 'a social account' ), diluxone_users_register_doors_open() );
	}

	/* ── The messages ──────────────────────────────────────────────── */

	public function test_a_rewrite_is_kept_and_the_plugins_own_words_are_not(): void {
		$this->the_admin();

		$shipped = diluxone_users_login_message_shipped( 'login_social', 'en_US' );

		$this->send_panel(
			'diluxone-users-login',
			array(
				'diluxone_users_message_locale' => 'en_US',
				'diluxone_users_message'        => array(
					'login_expired' => "  Try <b>again</b>,\r\nplease.  ",
					'login_social'  => str_replace( "\n", "\r\n", $shipped ),
					'login_changed' => array( 'not', 'a', 'sentence' ),
				),
			)
		);
		diluxone_users_login_messages_save();

		$store = diluxone_users_login_messages_store();

		$this->assertSame( "Try again,\nplease.", $store['en_US']['login_expired'] ?? null, 'cleaned, trimmed, with plain line feeds' );
		$this->assertArrayNotHasKey( 'login_social', $store['en_US'], 'the plugin’s own sentence, sent back unchanged, is not a rewrite' );
		$this->assertArrayNotHasKey( 'login_changed', $store['en_US'] );
		$this->assertSame( 1, diluxone_users_login_messages_own( 'en_US' ) );

		// Put back.
		$this->send_panel(
			'diluxone-users-login',
			array(
				'diluxone_users_message'         => array( 'login_expired' => 'Ignored' ),
				'diluxone_users_message_shipped' => array( 'login_expired' => '1' ),
			)
		);
		diluxone_users_login_messages_save();

		$this->assertSame( 0, diluxone_users_login_messages_own( 'en_US' ) );
	}

	public function test_the_language_written_is_one_the_site_can_run_in(): void {
		$this->postAs( 0, array( 'diluxone_users_message_locale' => 'en_US' ) );
		$this->assertSame( 'en_US', diluxone_users_login_messages_locale() );

		$this->postAs( 0, array( 'diluxone_users_message_locale' => 'xx_YY' ) );
		$this->assertSame( diluxone_users_mail_locale_key( get_locale() ), diluxone_users_login_messages_locale(), 'a language the site cannot run in is the site’s' );

		$this->postAs( 0, array( 'diluxone_users_message_locale' => 'xx_YY' ), array( 'lang' => 'en_US' ) );
		$this->assertSame( 'en_US', diluxone_users_login_messages_locale(), 'the address wins' );
	}

	/**
	 * A filter on the messages: the "login" group emptied, one message of a
	 * group nobody declared added.
	 *
	 * @param array<string, array<string, mixed>> $messages
	 * @return array<string, array<string, mixed>>
	 */
	public function messages_reshaped( array $messages ): array {
		foreach ( $messages as $key => $message ) {
			if ( 'login' === ( $message['group'] ?? '' ) ) {
				unset( $messages[ $key ] );
			}
		}

		$messages['cover_loose'] = array(
			'group'   => 'an-add-ons-own',
			'tone'    => 'ok',
			'label'   => 'Cover loose message',
			'when'    => 'When the add-on says so.',
			'shipped' => static fn(): string => 'The add-on’s sentence.',
		);

		return $messages;
	}

	/* ── What Access no longer holds ───────────────────────────────── */

	public function test_c3_access_has_no_registration_tab_and_its_old_address_draws_the_summary(): void {
		$this->the_admin();

		$tabs = diluxone_users_panels( 'diluxone-users-login' );

		$this->assertArrayNotHasKey( 'register', $tabs );
		$this->assertSame( array( 'summary', 'page', 'ways', 'arrangement', 'messages' ), array_keys( $tabs ) );

		$this->assertStringNotContainsString( 'name="diluxone_users_register_open"', $this->screen( 'register' ), 'an old tab address draws Access’s first tab, not the registration form' );
	}

	public function test_c3_access_messages_hold_no_registration_message_and_saving_them_leaves_those_alone(): void {
		$this->the_admin();

		$html = $this->screen( 'messages' );

		$this->assertStringContainsString( 'name="diluxone_users_message[login_expired]"', $html );
		$this->assertStringContainsString( 'name="diluxone_users_message[reset_mismatch]"', $html );
		$this->assertStringNotContainsString( 'name="diluxone_users_message[register_', $html );
		$this->assertStringNotContainsString( 'Creating an account', $html, 'no section of registration messages either' );

		diluxone_users_login_message_rewrite( 'register_taken', 'en_US', 'Written on Registration.' );
		diluxone_users_login_message_rewrite( 'register_closed', 'en_US', 'Also written there.' );

		// A form sent by hand with Registration's sentences in it: they are not
		// Access's to write, nor to put back.
		$this->send_panel(
			'diluxone-users-login',
			array(
				'diluxone_users_message_locale'  => 'en_US',
				'diluxone_users_message'         => array(
					'login_expired'  => 'Ours',
					'register_taken' => 'Overwritten from Access',
				),
				'diluxone_users_message_shipped' => array( 'register_closed' => '1' ),
			)
		);
		diluxone_users_login_messages_save();

		$store = diluxone_users_login_messages_store()['en_US'] ?? array();

		$this->assertSame( 'Ours', $store['login_expired'] ?? null );
		$this->assertSame( 'Written on Registration.', $store['register_taken'] ?? null );
		$this->assertSame( 'Also written there.', $store['register_closed'] ?? null );
		$this->assertMatchesRegularExpression( '/This site writes 1 of the \d+ messages itself/', $this->screen( 'messages' ), 'the rail counts Access’s own' );
	}

	public function test_the_messages_tab_draws_every_message_and_whatever_an_add_on_added(): void {
		$this->the_admin();

		$html = $this->screen( 'messages' );

		$this->assertStringContainsString( 'name="diluxone_users_message_locale"', $html );
		$this->assertStringContainsString( 'name="diluxone_users_message[login_expired]"', $html );
		$this->assertStringContainsString( 'Every message is the plugin’s own', $html );
		$this->assertStringNotContainsString( 'Added by something else on this site', $html );

		diluxone_users_login_message_rewrite( 'login_expired', diluxone_users_login_messages_locale(), 'Ours' );
		add_filter( 'diluxone_users_login_messages', array( $this, 'messages_reshaped' ) );

		$html = $this->screen( 'messages' );

		$this->assertStringContainsString( 'Added by something else on this site', $html );
		$this->assertStringContainsString( 'name="diluxone_users_message[cover_loose]"', $html );
		$this->assertStringContainsString( 'The add-on’s sentence.', $html );
		$this->assertStringNotContainsString( 'name="diluxone_users_message[login_expired]"', $html, 'an emptied group is left out' );
	}

	public function test_the_rail_counts_what_the_site_wrote(): void {
		$this->the_admin();

		$locale = diluxone_users_login_messages_locale();
		diluxone_users_login_message_rewrite( 'login_expired', $locale, 'Ours' );

		$html = $this->screen( 'messages' );

		$this->assertMatchesRegularExpression( '/This site writes 1 of the \d+ messages itself/', $html );
		$this->assertStringContainsString( 'Ours', $html, 'the box opens with the rewrite' );

		diluxone_users_login_message_rewrite( 'login_social', $locale, 'Ours too' );

		$this->assertMatchesRegularExpression( '/This site writes 2 of the \d+ messages themselves/', $this->screen( 'messages' ) );
	}

	/* ── The tabs that read it back ────────────────────────────────── */

	public function test_the_summary_with_a_page_and_a_network(): void {
		$this->the_admin();

		$page = $this->page( 'Cover sign in' );
		diluxone_users_update_option( 'diluxone_users_login_page', $page );
		$this->google_works();

		$html = $this->screen( 'summary' );

		$this->assertStringContainsString( 'Cover sign in</a>', $html );
		$this->assertStringContainsString( 'Google', $html );
		$this->assertStringNotContainsString( 'None: wp-login.php does the job.', $html );
	}

	public function test_the_summary_with_nothing_set_up(): void {
		$this->the_admin();

		$html = $this->screen( 'summary' );

		$this->assertStringContainsString( 'Everybody signs in on wp-login.php, as WordPress has it.', $html, 'no page decided' );
		$this->assertStringContainsString( 'No buttons: WordPress has none of its own.', $html, 'the buttons not decided' );
		$this->assertStringNotContainsStringIgnoringCase( 'passkey', $html, 'a door the plugin does not have is not a row' );

		// "None" chosen, and the buttons on with nothing to show.
		diluxone_users_update_option( 'diluxone_users_login_page', 0 );
		diluxone_users_update_option( 'diluxone_users_sso_login', 1 );

		$html = $this->screen( 'summary' );

		$this->assertStringContainsString( 'None: wp-login.php does the job.', $html );
		$this->assertStringContainsString( 'no provider is working yet', $html, 'the buttons are on and have nothing to show' );
	}

	public function test_wp_login_is_seen_unless_a_page_takes_its_place(): void {
		diluxone_users_update_option( 'diluxone_users_login_page', 0 );
		$this->assertTrue( diluxone_users_wp_login_seen(), 'no page: it is the only way in' );

		diluxone_users_update_option( 'diluxone_users_login_page', 123 );
		diluxone_users_update_option( 'diluxone_users_wp_screens', 'wp' );
		$this->assertTrue( diluxone_users_wp_login_seen(), 'left open beside the page' );

		diluxone_users_update_option( 'diluxone_users_wp_screens', 'mine' );
		$this->assertFalse( diluxone_users_wp_login_seen(), 'sent to the page' );
	}

	public function test_the_page_tab_without_a_password_says_wp_login_has_nothing_to_offer(): void {
		$this->the_admin();

		$page = $this->page( 'Cover passwordless page' );
		diluxone_users_update_option( 'diluxone_users_login_page', $page );
		diluxone_users_update_option( 'diluxone_users_login_method', 'link' );
		diluxone_users_update_option( 'diluxone_users_wp_screens', 'wp' );

		$html = $this->screen( 'page' );

		$this->assertStringContainsString( 'Nobody signs in with a password on this site, so wp-login.php has nothing left to offer', $html );
		$this->assertStringContainsString( 'There is no password on this site, so there is nothing to forget.', $html );
		$this->assertMatchesRegularExpression( '/name="diluxone_users_wp_screens" value="auto"[^>]*checked/', $html, 'the second door is shown moved to the answer in force' );
	}

	public function test_the_page_tab_with_no_page_and_wp_login_branded(): void {
		$this->the_admin();
		diluxone_users_update_option( 'diluxone_users_wp_login_brand', 1 );

		$html = $this->screen( 'page' );

		$this->assertStringContainsString( 'There is no sign-in page to send anybody to: wp-login.php is the sign-in screen, as WordPress has it.', $html );
		$this->assertStringContainsString( 'it has the site’s logo and colour on it', $html, 'the rail’s “Right now”' );
	}

	public function test_the_ways_tab_names_the_networks_that_work(): void {
		$this->the_admin();
		$this->google_works();

		$this->assertStringContainsString( 'Working right now: Google.', $this->screen( 'ways' ) );
	}

	public function test_the_shape_of_the_page_names_the_logo_when_there_is_one(): void {
		$this->the_admin();

		$_GET = array(
			'page' => DILUXONE_USERS_DESIGN,
			'tab'  => 'login',
		);
		$this->assertStringContainsString( 'None yet.', $this->draw( 'diluxone_users_screen_design' ) );

		diluxone_users_update_option( 'diluxone_users_login_logo', 99 );
		$this->assertStringContainsString( 'The site’s logo.', $this->draw( 'diluxone_users_screen_design' ) );
	}
}
