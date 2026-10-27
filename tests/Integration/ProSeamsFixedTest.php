<?php
/**
 * The six behaviours the plugin no longer offers as settings (#188), each
 * with an add-on hooked to its filter: what the add-on answers is what the
 * site does. Users+ Pro brings its settings back this way, and the plugin
 * names none of them.
 *
 * Each is walked twice: with nothing hooked, the plugin's own answer, and
 * with an add-on that answers the other way. Every case runs on a single
 * site and on a network.
 */

namespace Tests\Integration;

class ProSeamsFixedTest extends IntegrationTestCase {

	private int $user;

	protected function setUp(): void {
		parent::setUp();

		$this->user = $this->make_user();
	}

	protected function tearDown(): void {
		$_GET = array();

		parent::tearDown();
	}

	/** A published page with a shortcode, the one a setting points at. */
	private function page( string $setting, string $shortcode ): int {
		$page = (int) wp_insert_post(
			array(
				'post_type'    => 'page',
				'post_status'  => 'publish',
				'post_title'   => $shortcode,
				'post_content' => '[' . $shortcode . ']',
			)
		);
		diluxone_users_update_option( $setting, $page );

		return $page;
	}

	/* ── 1. The second step after the e-mail link ──────────────────── */

	public function test_diluxone_users_2fa_on_link_decides_whether_the_link_asks_the_second_step(): void {
		diluxone_users_update_option( 'diluxone_users_2fa_mode', 'required' );
		diluxone_users_update_option( 'diluxone_users_2fa_methods', array( 'email' ) );
		$this->page( 'diluxone_users_login_page', 'diluxone_users_login' );
		$going = home_url( '/after/' );

		wp_set_current_user( 0 );
		$url = $this->expectRedirect( fn() => diluxone_users_complete_login( $this->user, 'link', true, $going ) );
		$this->assertSame( (string) $this->user, $this->queryArg( $url, 'diluxone_users_2fa' ), 'the plugin alone: the second step' );
		$this->assertSame( 0, get_current_user_id() );

		$this->hook( 'diluxone_users_2fa_on_link', '__return_false' );

		wp_set_current_user( 0 );
		$this->assertSame( $going, $this->expectRedirect( fn() => diluxone_users_complete_login( $this->user, 'link', true, $going ) ), 'the add-on: straight in' );
		$this->assertSame( $this->user, get_current_user_id() );
		$this->assertTrue( diluxone_users_2fa_required( $this->user, 'password' ), 'a password still asks it' );
	}

	/* ── 2. A new password and the other sessions ──────────────────── */

	public function test_diluxone_users_reset_ends_sessions_decides_whether_a_new_password_closes_them(): void {
		$sessions = \WP_Session_Tokens::get_instance( $this->user );
		$sessions->create( time() + HOUR_IN_SECONDS );
		$sessions->create( time() + HOUR_IN_SECONDS );

		reset_password( get_userdata( $this->user ), 'a-new-password-1' );
		$this->assertCount( 2, \WP_Session_Tokens::get_instance( $this->user )->get_all(), 'the plugin alone: they stay open' );

		$heard = null;
		$this->hook(
			'diluxone_users_reset_ends_sessions',
			static function ( bool $end, \WP_User $user ) use ( &$heard ): bool {
				$heard = $user->ID;

				return true;
			},
			10,
			2
		);

		reset_password( get_userdata( $this->user ), 'a-new-password-2' );
		$this->assertSame( array(), \WP_Session_Tokens::get_instance( $this->user )->get_all(), 'the add-on: every one closed' );
		$this->assertSame( $this->user, $heard, 'asked with the account' );
	}

	/* ── 3. Which roles sign in with a network ─────────────────────── */

	public function test_diluxone_users_sso_role_allowed_decides_who_signs_in_with_a_network(): void {
		$admin = $this->make_user( 'administrator' );

		$this->assertFalse( diluxone_users_sso_role_blocked( $admin ), 'the plugin alone: every role' );
		$this->assertFalse( diluxone_users_sso_role_blocked( $this->user ) );
		$this->assertTrue( diluxone_users_sso_role_allowed( 0, 'register' ) );

		// An add-on keeping administrators out, and new accounts too.
		$this->hook(
			'diluxone_users_sso_role_allowed',
			static fn( bool $allowed, int $user_id, string $context ): bool => 'account' === $context && ! user_can( $user_id, 'manage_options' ),
			10,
			3
		);

		$this->assertTrue( diluxone_users_sso_role_blocked( $admin ), 'the add-on: the administrator is kept out' );
		$this->assertFalse( diluxone_users_sso_role_blocked( $this->user ), 'the subscriber is not' );
		$this->assertFalse( diluxone_users_sso_role_allowed( 0, 'register' ), 'nor is a new account made' );
	}

	/* ── 4. How often a public name changes ────────────────────────── */

	public function test_diluxone_users_handle_cooldown_decides_how_often_a_name_changes(): void {
		diluxone_users_update_option( 'diluxone_users_handle_enabled', 1 );

		$this->assertTrue( diluxone_users_handle_save( $this->user, 'first-' . strtolower( wp_generate_password( 6, false ) ) ) );
		$this->assertTrue( diluxone_users_handle_save( $this->user, 'second-' . strtolower( wp_generate_password( 6, false ) ) ), 'the plugin alone: again straight away' );

		$this->hook( 'diluxone_users_handle_cooldown', static fn(): int => 30 );

		$this->assertInstanceOf( \WP_Error::class, diluxone_users_handle_save( $this->user, 'third-' . strtolower( wp_generate_password( 6, false ) ) ), 'the add-on: 30 days to wait' );
		$this->assertGreaterThan( time() + 29 * DAY_IN_SECONDS, diluxone_users_handle_next_change( $this->user ) );
	}

	/* ── 5. The required fields missing after a sign-in ────────────── */

	public function test_diluxone_users_ask_missing_decides_whether_the_missing_fields_are_asked(): void {
		$account = $this->page( 'diluxone_users_account_page', 'diluxone_users_account' );
		diluxone_users_update_option(
			'diluxone_users_fields',
			array(
				array(
					'key'      => 'first_name',
					'label'    => 'First name',
					'type'     => 'text',
					'required' => 1,
				),
			)
		);
		$going = home_url( '/course/' );

		$this->assertSame( $going, $this->expectRedirect( fn() => diluxone_users_complete_login( $this->user, 'link', true, $going ) ), 'the plugin alone: straight on' );

		$this->hook( 'diluxone_users_ask_missing', '__return_true' );

		$step = $this->expectRedirect( fn() => diluxone_users_complete_login( $this->user, 'link', true, $going ) );
		$this->assertStringStartsWith( (string) get_permalink( $account ), $step, 'the add-on: the step on the account page' );
		$this->assertSame( '1', $this->queryArg( $step, DILUXONE_USERS_COMPLETE_VAR ) );
		$this->assertSame( $going, rawurldecode( $this->queryArg( $step, 'redirect_to' ) ) );
	}

	/* ── 6. Whose colours ──────────────────────────────────────────── */

	public function test_diluxone_users_colors_from_theme_decides_whose_colours_the_plugin_paints_with(): void {
		$palette = static fn(): array => array(
			'primary' => array(
				'name'  => 'Primary',
				'color' => '#ff0000',
			),
		);
		$this->hook( 'diluxone_users_theme_palette', $palette );
		diluxone_users_update_option( 'diluxone_users_style_accent', '#15803d' );

		$this->assertSame( '', diluxone_users_theme_colors_css(), 'the plugin alone: nothing of the theme’s' );
		$this->assertStringContainsString( '--diluxone-users-accent:#15803d;', diluxone_users_style_tokens(), 'its own accent' );

		$this->hook( 'diluxone_users_colors_from_theme', '__return_true' );

		$this->assertStringContainsString( '--diluxone-users-accent:#ff0000;', diluxone_users_theme_colors_css(), 'the add-on: the theme’s palette' );
		$this->assertStringNotContainsString( '--diluxone-users-accent:#15803d;', diluxone_users_style_tokens(), 'and the accent chosen here no longer beats it' );
	}
}
