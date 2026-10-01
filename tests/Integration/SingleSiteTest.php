<?php
/**
 * The single-site half of MultisiteTest and NetworkSettingsTest.
 *
 * Every rule those two prove on a network has a single-site answer, and the
 * answer is not "nothing happens": the site's own switch decides who may
 * create an account, activation sets the site up in its own table, every
 * screen and every tab is on the site's own dashboard, what its screens save
 * is its own, and there is nothing to move to a network. On a network every
 * test here is skipped, loudly — a single-site test that passes on a network
 * has proved nothing.
 */

namespace Tests\Integration;

class SingleSiteTest extends IntegrationTestCase {

	/** @var mixed WordPress's "Anyone can register", as the test found it. */
	private $anyone_can_register;

	protected function setUp(): void {
		parent::setUp();

		if ( is_multisite() ) {
			$this->markTestSkipped( 'Needs a single site: the network half is MultisiteTest and NetworkSettingsTest.' );
		}

		$this->anyone_can_register = get_option( 'users_can_register' );

		do_action( 'diluxone_users_register_panels' );
	}

	protected function tearDown(): void {
		if ( ! is_multisite() ) {
			update_option( 'users_can_register', $this->anyone_can_register );
		}

		parent::tearDown();
	}

	/** Asks for a sign-in link. */
	private function ask( string $email ): void {
		$this->postAs(
			0,
			array(
				'diluxone_users_nonce' => wp_create_nonce( 'diluxone_users_login' ),
				'diluxone_users_email' => $email,
			)
		);

		$this->expectRedirect( 'diluxone_users_login_request' );
	}

	/* ── Who gets in ───────────────────────────────────────────────── */

	/** With no network above it, the site's own switch is the whole decision. */
	public function test_the_sites_own_switch_decides_whether_accounts_can_be_created(): void {
		$this->assertTrue( diluxone_users_network_takes_accounts(), 'No network to close anything' );

		diluxone_users_update_option( 'diluxone_users_login_register', 0 );
		diluxone_users_update_option( 'diluxone_users_register_form', 0 );

		$email = 'closed-site-' . wp_generate_password( 8, false ) . '@example.test';
		$this->ask( $email );

		$this->assertFalse( email_exists( $email ) );
		$this->assertSame( 'closed', diluxone_users_register_mode() );

		diluxone_users_update_option( 'diluxone_users_login_register', 1 );

		$this->ask( 'open-site-' . wp_generate_password( 8, false ) . '@example.test' );

		$this->assertSame( 'login', diluxone_users_register_mode() );
		$this->assertCount( 1, self::$mail, 'Switched on, the link is sent' );
	}

	/** Activation sets the site up, and every setting it writes is the site's own. */
	public function test_activation_sets_the_site_up_in_its_own_table(): void {
		global $wpdb;

		diluxone_users_activate( false );

		$table = diluxone_users_log_table();

		$this->assertSame( $table, $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) ) );
		$this->assertNotFalse( get_option( 'diluxone_users_fields', false ), 'The fields, in the site’s table' );
		$this->assertNotFalse( get_option( 'diluxone_users_login_register', false ), 'The doors, in the site’s table' );
	}

	/**
	 * The activity log is the site's own table, under the site's prefix, with
	 * every row stamped as the site's: there is no network to share it with,
	 * no network report, no network button and nothing to move.
	 */
	public function test_the_log_is_the_sites_own_and_there_is_nothing_to_move(): void {
		global $wpdb;

		$this->assertFalse( diluxone_users_log_network() );
		$this->assertSame( $wpdb->prefix . 'diluxone_users_log', diluxone_users_log_table() );
		$this->assertTrue( diluxone_users_log_purges_here() );

		$this->assertTrue( diluxone_users_log_move(), 'Nothing to move' );
		$this->assertFalse( get_option( 'diluxone_users_log_moved', false ), 'And nothing written about it' );

		$this->assertArrayHasKey( 'activity', diluxone_users_panels( DILUXONE_USERS_REPORTS ) );
		$this->assertArrayNotHasKey( 'network-activity', diluxone_users_panels( DILUXONE_USERS_REPORTS ), 'No network report' );

		wp_set_current_user( $this->make_user( 'administrator' ) );
		$_REQUEST['_wpnonce'] = wp_create_nonce( 'diluxone_users_log_empty_network' );

		$this->expectException( \WPAjaxDieContinueException::class );
		diluxone_users_log_empty_network();
	}

	/** The doors start where WordPress's "Anyone can register" is. */
	public function test_the_doors_start_where_anyone_can_register_is(): void {
		foreach ( array( 0, 1 ) as $anyone ) {
			update_option( 'users_can_register', $anyone );
			diluxone_users_delete_option( 'diluxone_users_login_register' );
			diluxone_users_delete_option( 'diluxone_users_sso_register' );

			diluxone_users_site_setup();

			$this->assertSame( $anyone, (int) get_option( 'diluxone_users_login_register' ), "Anyone can register: {$anyone}" );
			$this->assertSame( $anyone, (int) get_option( 'diluxone_users_sso_register' ), "Anyone can register: {$anyone}" );
		}
	}

	/** Deactivating leaves no purge event behind, however the hook is called. */
	public function test_deactivation_clears_the_event(): void {
		foreach ( array( false, true ) as $network_wide ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', DILUXONE_USERS_LOG_PURGE );
			update_option( 'rewrite_rules', array( 'account/(.+)/?$' => 'index.php' ) );

			diluxone_users_log_unschedule( $network_wide );

			$this->assertFalse( wp_next_scheduled( DILUXONE_USERS_LOG_PURGE ) );
			$this->assertEmpty( get_option( 'rewrite_rules' ), 'the stored addresses are gone, to be rebuilt without the plugin' );
		}
	}

	/** On a single site everybody already belongs: joining changes nobody's role. */
	public function test_joining_the_site_changes_nobody(): void {
		$user = $this->make_user( 'editor' );
		diluxone_users_update_option( 'diluxone_users_login_register', 1 );

		diluxone_users_join_site( $user );

		$this->assertSame( array( 'editor' ), array_values( get_userdata( $user )->roles ) );
	}

	/** "Only some roles" reaches whoever holds one of them here. */
	public function test_a_chosen_role_puts_somebody_in_scope(): void {
		diluxone_users_update_option( 'diluxone_users_2fa_mode', 'required' );
		diluxone_users_update_option( 'diluxone_users_2fa_methods', array( 'email' ) );
		diluxone_users_update_option( 'diluxone_users_2fa_scope', 'some' );
		diluxone_users_update_option( 'diluxone_users_2fa_roles', array( 'administrator' ) );

		$this->assertTrue( diluxone_users_2fa_required( $this->make_user( 'administrator' ), 'password' ) );
		$this->assertFalse( diluxone_users_2fa_required( $this->make_user( 'subscriber' ), 'password' ) );
	}

	/** Add New User takes the e-mail as the username, as every other door does. */
	public function test_add_new_user_uses_the_email_as_username(): void {
		$this->assertTrue( diluxone_users_admin_login_is_email() );
	}

	/** The site's session report lists everybody with a session: they are all its people. */
	public function test_the_sessions_report_lists_everybody_signed_in(): void {
		$one = $this->make_user();
		$two = $this->make_user( 'editor' );

		foreach ( array( $one, $two ) as $user ) {
			\WP_Session_Tokens::get_instance( $user )->create( time() + HOUR_IN_SECONDS );
		}

		$ids = array_map( 'intval', array_column( diluxone_users_sessions_search( '', 1, 200 )['rows'], 'user_id' ) );

		$this->assertContains( $one, $ids );
		$this->assertContains( $two, $ids );
	}

	/** A picture is read and deleted from the site's own media library. */
	public function test_a_picture_is_read_and_deleted_here(): void {
		$user = $this->make_user();

		$picture = wp_insert_attachment(
			array(
				'post_mime_type' => 'image/png',
				'post_title'     => 'Avatar',
				'post_status'    => 'inherit',
				'post_author'    => $user,
			),
			'avatar-' . $user . '.png'
		);
		update_user_meta( $user, 'diluxone_users_avatar', $picture );
		update_user_meta( $user, 'diluxone_users_avatar_site', get_current_blog_id() );

		$this->assertNotSame( '', diluxone_users_avatar_url( $user ) );

		diluxone_users_avatar_delete( $user );

		$this->assertNull( get_post( $picture ) );
		$this->assertSame( '', get_user_meta( $user, 'diluxone_users_avatar', true ) );
	}

	/* ── Where each setting is set ─────────────────────────────────── */

	/** The site's own screen writes the second step, and it is read back from the site. */
	public function test_the_sites_screen_sets_the_second_step(): void {
		diluxone_users_update_option( 'diluxone_users_2fa_mode', 'optional' );

		$_POST = array(
			'diluxone_users_2fa_mode'    => 'required',
			'diluxone_users_2fa_methods' => array( 'email' ),
		);
		diluxone_users_2fa_save();
		$_POST = array();

		$this->assertSame( 'required', diluxone_users_option( 'diluxone_users_2fa_mode' ) );
		$this->assertSame( 'required', get_option( 'diluxone_users_2fa_mode' ) );
	}

	/** Every scope — the network's, the hub's, the site's — is the site's own table. */
	public function test_every_scope_is_stored_in_the_sites_own_table(): void {
		$this->assertFalse( diluxone_users_scoped_storage_active() );
		$this->assertSame( 'single', diluxone_users_admin_context() );

		diluxone_users_update_option( 'diluxone_users_2fa_mode', 'all' );
		diluxone_users_update_option( 'diluxone_users_login_title', 'Title' );
		diluxone_users_update_option( 'diluxone_users_rewrite_version', 'x' );

		$this->assertSame( 'all', get_option( 'diluxone_users_2fa_mode' ) );
		$this->assertSame( 'Title', get_option( 'diluxone_users_login_title' ) );
		$this->assertSame( 'x', get_option( 'diluxone_users_rewrite_version' ) );
		$this->assertTrue( diluxone_users_on_the_hub(), 'A single site is its own hub' );

		diluxone_users_delete_option( 'diluxone_users_rewrite_version' );
	}

	/** Every screen is on the site's dashboard, and so is every tab of it but the network's. */
	public function test_every_screen_and_tab_is_on_the_sites_dashboard(): void {
		foreach ( array_keys( diluxone_users_screen_scopes() ) as $screen ) {
			$this->assertTrue( diluxone_users_screen_here( $screen ), $screen );
			$this->assertStringStartsWith( admin_url(), diluxone_users_admin_url( $screen ), $screen );
		}

		$this->assertArrayHasKey( 'logging', diluxone_users_panels( DILUXONE_USERS_REPORTS ), 'The rules beside the rows' );
		$this->assertArrayHasKey( 'activity', diluxone_users_panels( DILUXONE_USERS_REPORTS ) );
		$this->assertArrayHasKey( '2fa', diluxone_users_panels( DILUXONE_USERS_SECURITY ) );
		$this->assertArrayNotHasKey( 'network', diluxone_users_panels( DILUXONE_USERS_MENU ), 'No network to describe' );
		$this->assertArrayNotHasKey( 'uninstall', diluxone_users_panels( DILUXONE_USERS_MENU ), 'Its box is on Maintenance › Tools' );
	}

	/** The Overview says nothing is managed elsewhere, and links every area here. */
	public function test_nothing_is_managed_elsewhere(): void {
		wp_set_current_user( $this->make_user( 'administrator' ) );

		ob_start();
		diluxone_users_managed_elsewhere();
		$this->assertSame( '', (string) ob_get_clean() );

		$link = diluxone_users_home_card_link( 'diluxone-users-social', 'Set them up' );

		$this->assertCount( 1, $link );
		$this->assertStringStartsWith( admin_url(), $link[0]['url'] );
	}

	/** The pages are the site's own, drawn and routed here. */
	public function test_the_pages_are_the_sites(): void {
		$page = (int) wp_insert_post(
			array(
				'post_type'   => 'page',
				'post_status' => 'publish',
				'post_title'  => 'Sign-in',
			)
		);
		diluxone_users_update_option( 'diluxone_users_login_page', $page );

		$this->assertSame( $page, diluxone_users_page_here( 'diluxone_users_login_page' ) );
		$this->assertSame( (string) get_permalink( $page ), diluxone_users_page_url( 'diluxone_users_login_page' ) );

		wp_delete_post( $page, true );
	}

	/**
	 * Passkeys and social sign-in are switched on Access › Ways in: their own
	 * tabs do not carry the switch, so saving them does not flip it.
	 */
	public function test_the_doors_are_switched_on_access_and_not_on_their_own_tabs(): void {
		diluxone_users_update_option( 'diluxone_users_passkey_enabled', 0 );
		diluxone_users_update_option( 'diluxone_users_sso_login', 1 );

		$_POST = array( 'diluxone_users_passkey_enabled' => '1' );
		diluxone_users_passkeys_settings_save();
		diluxone_users_social_rules_save();
		$_POST = array();

		$this->assertSame( 0, (int) diluxone_users_raw_get( 'diluxone_users_passkey_enabled' ) );
		$this->assertSame( 1, (int) diluxone_users_raw_get( 'diluxone_users_sso_login' ) );
	}

	/** "Show me what I chose" writes nothing on a single site either. */
	public function test_a_trial_run_writes_nothing(): void {
		diluxone_users_update_option( 'diluxone_users_2fa_mode', 'optional' );

		$_POST  = array(
			'diluxone_users_2fa_mode'    => 'required',
			'diluxone_users_2fa_methods' => array( 'email' ),
		);
		$caught = diluxone_users_preview_would_save( diluxone_users_panels( DILUXONE_USERS_SECURITY )['2fa'] );
		$_POST  = array();

		$this->assertSame( 'required', $caught['diluxone_users_2fa_mode'] ?? null );
		$this->assertSame( 'optional', get_option( 'diluxone_users_2fa_mode' ) );
	}

	/** There is no network to move the settings to: the move is done before it starts, and writes nothing. */
	public function test_there_is_nothing_to_move(): void {
		$admin = $this->make_user( 'administrator' );
		wp_set_current_user( $admin );

		$this->assertTrue( diluxone_users_network_migrate() );
		$this->assertFalse( get_option( DILUXONE_USERS_NETWORK_VERSION_OPTION ) );
		$this->assertFalse( get_option( DILUXONE_USERS_NETWORK_MIGRATING ) );

		ob_start();
		diluxone_users_network_conflicts_notice();
		$this->assertSame( '', (string) ob_get_clean() );
	}
}
