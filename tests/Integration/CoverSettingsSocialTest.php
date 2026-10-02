<?php
/**
 * The social-login screen: the rules, the grid of networks, each network's own
 * screen with its three tabs, the credentials saved from it and the links that
 * switch a network on, off or forget it — and the guide each one shows.
 *
 * On a network every one of these is the network's: written from Network
 * Admin by whoever administers the network. A site's administrator, and the
 * hub's own dashboard, write nothing. Every case runs on both topologies
 * unless it names the counterpart that covers the other.
 */

namespace Tests\Integration;

use Tests\Integration\Support\CoverSettingsSupport;

class CoverSettingsSocialTest extends IntegrationTestCase {

	use CoverSettingsSupport;

	/** @var mixed The referer before the test. */
	private $referer;

	protected function setUp(): void {
		parent::setUp();

		$this->referer = $_SERVER['HTTP_REFERER'] ?? null;

		do_action( 'diluxone_users_register_panels' );
	}

	protected function tearDown(): void {
		$this->cover_settings_reset();

		if ( null === $this->referer ) {
			unset( $_SERVER['HTTP_REFERER'] );
		} else {
			$_SERVER['HTTP_REFERER'] = $this->referer;
		}

		remove_filter( 'diluxone_users_sso_guides', array( $this, 'no_gotcha' ) );

		parent::tearDown();
	}

	/**
	 * The four states at once: Google working and shown, GitHub working and
	 * hidden, Microsoft with its app and no test, the rest with nothing.
	 */
	private function four_states(): void {
		diluxone_users_update_option(
			'diluxone_users_sso',
			array(
				'google'    => array(
					'active' => 1,
					'id'     => 'g-id',
					'secret' => 'g-secret',
					'tested' => 1,
				),
				'github'    => array(
					'active' => 0,
					'id'     => 'gh-id',
					'secret' => 'gh-secret',
					'tested' => 1,
				),
				'microsoft' => array(
					'active' => 0,
					'id'     => 'ms-id',
					'secret' => 'ms-secret',
					'tested' => 0,
				),
			),
			false
		);
	}

	/**
	 * A guide filter: every gotcha gone.
	 *
	 * @param array<string, array<string, mixed>> $guides
	 * @return array<string, array<string, mixed>>
	 */
	public function no_gotcha( array $guides ): array {
		foreach ( $guides as $id => $guide ) {
			$guides[ $id ]['gotcha'] = '';
		}

		return $guides;
	}

	/* ── The rules ─────────────────────────────────────────────────── */

	public function test_the_rules_stop_on_a_bad_nonce_or_somebody_without_the_capability(): void {
		$this->where_the_network_is_set();
		$this->the_admin();

		$post = array( 'diluxone_users_sso_verified_only' => '1' );

		$this->postAs( get_current_user_id(), $post + array( 'diluxone_users_panel_nonce' => 'not-a-nonce' ) );
		$this->assertSame( array( 'died', self::EXPIRED ), $this->ended( 'diluxone_users_social_rules_save' ) );

		$this->send_panel( 'diluxone-users-security', $post );
		$this->assertSame( array( 'died', self::EXPIRED ), $this->ended( 'diluxone_users_social_rules_save' ), 'another screen’s nonce' );

		// On a network, a site's administrator in Network Admin; on a site, a subscriber.
		$this->not_allowed( true );
		$this->send_panel( 'diluxone-users-social', $post );
		$this->assertSame( array( 'died', 'You are not allowed to do this.' ), $this->ended( 'diluxone_users_social_rules_save' ) );

		$this->assertSame( 0, (int) diluxone_users_option( 'diluxone_users_sso_verified_only' ) );
	}

	public function test_the_rules_are_saved_and_on_a_site_the_switch_stays_on_access(): void {
		$this->single_only( 'CoverSettingsSocialTest::test_in_network_admin_the_rules_carry_the_switch_itself' );

		$this->the_admin();
		$this->send_panel(
			'diluxone-users-social',
			array(
				'diluxone_users_sso_verified_only' => '1',
				'diluxone_users_sso_scope'         => 'some',
				'diluxone_users_sso_roles'         => array( 'Subscriber' ),
			)
		);

		$this->assertSame( 'returned', $this->ended( 'diluxone_users_social_rules_save' )[0] );

		$this->assertSame( 0, (int) diluxone_users_option( 'diluxone_users_sso_link_by_email' ), 'unticked' );
		$this->assertSame( 1, (int) diluxone_users_option( 'diluxone_users_sso_verified_only' ) );
		$this->assertSame( 'some', diluxone_users_option( 'diluxone_users_sso_scope' ) );
		$this->assertSame( array( 'subscriber' ), diluxone_users_option( 'diluxone_users_sso_roles' ) );
		$this->assertSame( 1, (int) diluxone_users_option( 'diluxone_users_sso_login' ), 'not on this form: left as it was' );
	}

	public function test_in_network_admin_the_rules_carry_the_switch_itself(): void {
		$this->network_only( 'CoverSettingsSocialTest::test_the_rules_are_saved_and_on_a_site_the_switch_stays_on_access' );

		$this->in_network_admin();
		$this->the_admin();
		$this->send_panel( 'diluxone-users-social', array( 'diluxone_users_sso_link_by_email' => '1' ) );

		diluxone_users_social_rules_save();

		$this->assertSame( 0, (int) get_site_option( 'diluxone_users_sso_login' ), 'unticked in Network Admin is off, for every site' );
		$this->assertSame( 1, (int) get_site_option( 'diluxone_users_sso_link_by_email' ) );
	}

	public function test_from_the_hubs_own_dashboard_the_rules_write_nothing(): void {
		$this->network_only( 'CoverSettingsSocialTest::test_the_rules_are_saved_and_on_a_site_the_switch_stays_on_access' );

		$this->the_admin();
		$this->send_panel( 'diluxone-users-social', array( 'diluxone_users_sso_verified_only' => '1' ) );

		diluxone_users_social_rules_save();

		$this->assertSame( 0, (int) diluxone_users_option( 'diluxone_users_sso_verified_only' ) );
	}

	public function test_the_rules_tab_says_how_an_account_ends_up(): void {
		$this->where_the_network_is_set();
		$this->the_admin();

		$_GET = array(
			'page' => 'diluxone-users-social',
			'tab'  => 'general',
		);
		$html = $this->draw( 'diluxone_users_screen_social' );

		$this->assertStringContainsString( 'name="diluxone_users_sso_link_by_email"', $html );
		$this->assertStringContainsString( 'name="diluxone_users_sso_verified_only"', $html );
		$this->assertStringContainsString( 'A social account only signs in people who already have one here.', $html );
		$this->assertStringContainsString( 'Subscriber', $html, 'the role a new account gets, by its name' );

		if ( is_multisite() ) {
			$this->assertStringContainsString( 'name="diluxone_users_sso_login"', $html, 'in Network Admin the switch is here' );
		} else {
			$this->assertStringNotContainsString( 'name="diluxone_users_sso_login"', $html, 'on a site it is on Access' );
		}

		diluxone_users_update_option( 'diluxone_users_sso_register', 1 );

		$this->assertStringContainsString(
			'Signing in with a social account creates the account when there is none.',
			$this->draw( 'diluxone_users_screen_social_general' )
		);
	}

	/* ── The grid ──────────────────────────────────────────────────── */

	public function test_the_grid_offers_what_each_state_needs(): void {
		$this->where_the_network_is_set();
		$this->the_admin();
		$this->four_states();

		$_GET = array( 'page' => 'diluxone-users-social' );
		$html = $this->draw( 'diluxone_users_screen_social' );

		$this->assertStringContainsString( 'Its button is on the sign-in page.', $html );
		$this->assertStringContainsString( 'Tested and working, and its button is not on the sign-in page.', $html );
		$this->assertStringContainsString( 'It has its credentials and the live test has not been run.', $html );
		$this->assertStringContainsString( 'No app created for it yet', $html );
		$this->assertStringContainsString( 'Get started', $html );
		$this->assertStringContainsString( 'Turn it off', $html );
		$this->assertStringContainsString( 'Turn it on', $html );
		$this->assertStringContainsString( esc_attr( diluxone_users_sso_off_warning( 'Google' ) ), $html, 'taking one down is asked first' );
		$this->assertStringContainsString( 'red=github', $html );
		$this->assertStringContainsString( 'diluxone_users_action=on', $html );
		$this->assertStringContainsString( 'Not included, on purpose', $html );
		$this->assertSame( 1, substr_count( $html, 'diluxone_users_action=off' ), 'only the working one is offered off' );
	}

	public function test_a_provider_that_does_not_exist_shows_the_grid(): void {
		$this->where_the_network_is_set();
		$this->the_admin();

		$_GET = array(
			'page'     => 'diluxone-users-social',
			'provider' => 'myspace',
		);
		$html = $this->draw( 'diluxone_users_screen_social' );

		$this->assertStringContainsString( 'The networks', $html );
		$this->assertStringNotContainsString( 'Back to all providers', $html );
	}

	/* ── One network's own screen ──────────────────────────────────── */

	/** The screen of one provider, on one tab. */
	private function provider( string $id, string $tab ): string {
		$_GET = array(
			'page'     => 'diluxone-users-social',
			'provider' => $id,
			'tab'      => $tab,
		);

		return $this->draw( 'diluxone_users_screen_social' );
	}

	/**
	 * Every provider has its own guide, drawn step by step with its warning
	 * and what it asks for.
	 *
	 * @return array<string, array{0: string}>
	 */
	public static function providers(): array {
		$ids = array( 'google', 'microsoft', 'linkedin', 'twitter', 'facebook', 'github', 'gitlab', 'discord', 'twitch', 'amazon', 'yahoo', 'wordpress' );

		return array_combine( $ids, array_map( static fn( string $id ): array => array( $id ), $ids ) );
	}

	/** @dataProvider providers */
	public function test_getting_started_walks_through_that_providers_console( string $id ): void {
		$this->where_the_network_is_set();
		$this->the_admin();

		$provider = diluxone_users_sso_providers()[ $id ];
		$guide    = diluxone_users_sso_guide( $id );
		$html     = $this->provider( $id, 'start' );

		$this->assertNotEmpty( $guide['steps'], 'a guide with steps' );
		$this->assertNotSame( '', $guide['gotcha'], 'and the step everybody misses' );

		foreach ( $guide['steps'] as $step ) {
			$this->assertStringContainsString( '<li>' . esc_html( $step ) . '</li>', $html );
		}

		$this->assertStringContainsString( esc_html( $guide['gotcha'] ), $html );
		$this->assertStringContainsString( 'Step by step in ' . esc_html( $provider['name'] ), $html );
		$this->assertStringContainsString( esc_attr( diluxone_users_sso_redirect_uri( $id ) ), $html, 'the address to paste' );
		$this->assertStringContainsString( esc_url( $provider['console'] ), $html );
		$this->assertStringContainsString( esc_url( $provider['guide'] ), $html, 'their own documentation' );
		$this->assertStringContainsString( '<code>' . esc_html( $provider['scope'] ) . '</code>', $html );
		$this->assertStringContainsString( 'Create the app, load the redirect URL', $html, 'nothing created yet' );
		$this->assertStringNotContainsString( 'Delete its settings', $html, 'nothing to delete' );

		if ( ! empty( $provider['pkce'] ) ) {
			$this->assertStringContainsString( 'It requires PKCE.', $html );
		} else {
			$this->assertStringNotContainsString( 'It requires PKCE.', $html );
		}
	}

	public function test_a_guide_without_a_warning_shows_none_and_an_unknown_one_is_empty(): void {
		add_filter( 'diluxone_users_sso_guides', array( $this, 'no_gotcha' ) );

		$this->where_the_network_is_set();
		$this->the_admin();

		$this->assertStringNotContainsString( 'Watch out:', $this->provider( 'github', 'start' ) );
		$this->assertSame(
			array(
				'steps'  => array(),
				'gotcha' => '',
			),
			diluxone_users_sso_guide( 'myspace' )
		);
	}

	public function test_settings_never_print_the_secret_and_say_one_is_kept(): void {
		$this->where_the_network_is_set();
		$this->the_admin();
		$this->four_states();

		$html = $this->provider( 'microsoft', 'settings' );

		$this->assertStringContainsString( 'name="diluxone_users_provider_nonce"', $html );
		$this->assertStringContainsString( 'value="ms-id"', $html );
		$this->assertStringNotContainsString( 'ms-secret', $html, 'the secret is never printed back' );
		$this->assertStringContainsString( 'A secret is saved. Leave this empty to keep it', $html );
		$this->assertStringContainsString( 'This provider has not passed the live test', $html );
		$this->assertStringContainsString( 'Run the live test', $html );
		$this->assertStringContainsString( 'A window opens, Microsoft asks you to authorise', $html );
		$this->assertStringContainsString( 'Delete its settings', $html );

		// A provider with nothing saved asks for both, with no promise.
		$html = $this->provider( 'gitlab', 'settings' );

		$this->assertStringNotContainsString( 'A secret is saved.', $html );
		$this->assertStringNotContainsString( 'Run the live test', $html, 'nothing to test yet' );
	}

	public function test_a_working_provider_offers_its_test_again_and_its_switch(): void {
		$this->where_the_network_is_set();
		$this->the_admin();
		$this->four_states();

		$html = $this->provider( 'google', 'settings' );

		$this->assertStringContainsString( 'Test it again', $html );
		$this->assertStringContainsString( 'Turn it off', $html );
		$this->assertStringNotContainsString( 'This provider has not passed the live test', $html );

		$html = $this->provider( 'github', 'usage' );

		$this->assertStringContainsString( 'Turn it on', $html );
		$this->assertStringContainsString( '[diluxone_users_login]', $html );
		$this->assertStringContainsString( '[diluxone_users_accounts]', $html );
		$this->assertStringContainsString( esc_html( diluxone_users_sso_login_url( 'github' ) ), $html );
		$this->assertStringNotContainsString( 'documentation', $html, 'their documentation is on the first tab' );
	}

	/* ── Its credentials, saved ────────────────────────────────────── */

	/**
	 * The provider's own form, sent to its screen.
	 *
	 * @param array<string, mixed> $post
	 */
	private function send_provider( string $id, array $post, string $nonce = '' ): string {
		$this->postAs(
			get_current_user_id(),
			$post + array( 'diluxone_users_provider_nonce' => '' === $nonce ? wp_create_nonce( 'diluxone_users_provider' ) : $nonce ),
			array(
				'page'     => 'diluxone-users-social',
				'provider' => $id,
				'tab'      => 'settings',
			)
		);

		return $this->draw( 'diluxone_users_screen_social' );
	}

	public function test_new_credentials_are_saved_cleaned_and_wait_for_the_test(): void {
		$this->where_the_network_is_set();
		$this->the_admin();

		$html = $this->send_provider(
			'gitlab',
			array(
				'diluxone_users_client_id'     => ' gl-<b>id</b> ',
				'diluxone_users_client_secret' => 'gl-secret',
				'diluxone_users_active'        => '1',
			)
		);

		$this->assertStringContainsString( 'Provider saved.', $html );
		$this->assertSame(
			array(
				'active' => false,
				'id'     => 'gl-id',
				'secret' => 'gl-secret',
			),
			diluxone_users_sso_credentials( 'gitlab' ),
			'untested, it cannot be shown however it is ticked'
		);
		$this->assertSame( 'not-tested', diluxone_users_sso_state( 'gitlab' ) );
	}

	public function test_an_empty_secret_keeps_the_saved_one_and_a_new_id_asks_for_the_test_again(): void {
		$this->where_the_network_is_set();
		$this->the_admin();
		$this->four_states();

		// Same ID, secret left empty: kept, tested, and switched off.
		$this->send_provider( 'google', array( 'diluxone_users_client_id' => 'g-id' ) );

		$this->assertSame( 'g-secret', diluxone_users_sso_credentials( 'google' )['secret'] );
		$this->assertSame( 'disabled', diluxone_users_sso_state( 'google' ) );

		// A new ID is a new app: the test goes.
		$this->send_provider(
			'google',
			array(
				'diluxone_users_client_id' => 'another-id',
				'diluxone_users_active'    => '1',
			)
		);

		$this->assertSame( 'not-tested', diluxone_users_sso_state( 'google' ) );
		$this->assertSame( 'g-secret', diluxone_users_sso_credentials( 'google' )['secret'] );
	}

	public function test_credentials_are_not_saved_without_the_nonce_or_the_capability(): void {
		$this->where_the_network_is_set();
		$this->the_admin();

		$post = array(
			'diluxone_users_client_id'     => 'evil-id',
			'diluxone_users_client_secret' => 'evil-secret',
		);

		$html = $this->send_provider( 'gitlab', $post, 'not-a-nonce' );
		$this->assertStringNotContainsString( 'Provider saved.', $html );

		$this->not_allowed( true );
		$html = $this->send_provider( 'gitlab', $post );
		$this->assertStringNotContainsString( 'Provider saved.', $html );

		$this->assertSame( 'not-configured', diluxone_users_sso_state( 'gitlab' ) );
	}

	public function test_credentials_are_not_saved_from_the_hubs_own_dashboard(): void {
		$this->network_only( 'CoverSettingsSocialTest::test_credentials_are_not_saved_without_the_nonce_or_the_capability' );

		$this->the_admin();

		$html = $this->send_provider(
			'gitlab',
			array(
				'diluxone_users_client_id'     => 'gl-id',
				'diluxone_users_client_secret' => 'gl-secret',
			)
		);

		$this->assertStringNotContainsString( 'Provider saved.', $html );
		$this->assertSame( 'not-configured', diluxone_users_sso_state( 'gitlab' ) );
	}

	/* ── On, off, forget ───────────────────────────────────────────── */

	/** @return array{0: string, 1: mixed} */
	private function press( string $id, string $action, string $nonce = '', string $page = 'diluxone-users-social' ): array {
		$_GET     = array(
			'page'                  => $page,
			'red'                   => $id,
			'diluxone_users_action' => $action,
		);
		$_REQUEST = $_GET + array( '_wpnonce' => '' === $nonce ? wp_create_nonce( 'diluxone_users_social_toggle' ) : $nonce );

		return $this->ended( 'diluxone_users_social_toggle' );
	}

	public function test_a_provider_that_never_passed_the_test_cannot_be_switched_on(): void {
		$this->where_the_network_is_set();
		$this->the_admin();
		$this->four_states();

		list( $how ) = $this->press( 'microsoft', 'on' );

		$this->assertSame( 'redirect', $how );
		$this->assertSame( 'not-tested', diluxone_users_sso_state( 'microsoft' ) );

		$this->press( 'myspace', 'on' );
		$this->press( 'myspace', 'forget' );
		$this->assertArrayNotHasKey( 'myspace', (array) diluxone_users_raw_get( 'diluxone_users_sso', array() ), 'a network that is not one is not written' );
	}

	public function test_switching_goes_back_where_it_was_pressed_and_forgetting_goes_to_the_grid(): void {
		$this->where_the_network_is_set();
		$this->the_admin();
		$this->four_states();

		$from                    = diluxone_users_admin_url( 'diluxone-users-social', array( 'provider' => 'github' ) );
		$_SERVER['HTTP_REFERER'] = $from;

		list( $how, $to ) = $this->press( 'github', 'on' );

		$this->assertSame( 'redirect', $how );
		$this->assertSame( $from, $to, 'back to the provider’s own screen' );
		$this->assertSame( 'enabled', diluxone_users_sso_state( 'github' ) );

		list( , $to ) = $this->press( 'github', 'forget' );

		$this->assertSame( diluxone_users_admin_url( 'diluxone-users-social' ), $to, 'the settings are gone: the grid' );
		$this->assertSame( 'not-configured', diluxone_users_sso_state( 'github' ) );
		$this->assertSame( 'enabled', diluxone_users_sso_state( 'google' ), 'the others are untouched' );
	}

	public function test_a_switch_with_a_bad_nonce_stops_and_one_elsewhere_does_nothing(): void {
		$this->where_the_network_is_set();
		$this->the_admin();
		$this->four_states();

		$this->assertSame( array( 'died', self::EXPIRED ), $this->press( 'google', 'off', 'not-a-nonce' ) );
		$this->assertSame( array( 'died', self::EXPIRED ), $this->press( 'google', 'off', wp_create_nonce( 'diluxone_users_section_action' ) ), 'another switch’s nonce' );

		$this->not_allowed( true );
		$this->assertSame( array( 'died', 'You are not allowed to do this.' ), $this->press( 'google', 'off' ), 'somebody without the capability' );

		$this->assertSame( 'enabled', diluxone_users_sso_state( 'google' ) );
	}

	/* ── The buttons ───────────────────────────────────────────────── */

	public function test_the_buttons_panel_and_its_preview(): void {
		$this->the_admin();
		diluxone_users_update_option( 'diluxone_users_sso_button_shape', 'pill' );

		$html = $this->draw( 'diluxone_users_screen_social_buttons' );

		foreach ( array( 'diluxone_users_sso_button_skin', 'diluxone_users_sso_button_shape', 'diluxone_users_sso_button_show', 'diluxone_users_sso_button_columns', 'diluxone_users_sso_button_text' ) as $name ) {
			$this->assertStringContainsString( 'name="' . $name . '"', $html, $name );
		}

		$preview = $this->draw( 'diluxone_users_social_buttons_preview' );

		$this->assertStringContainsString( 'Google', $preview );
		$this->assertStringNotContainsString( 'diluxone_users_go', $preview, 'the preview signs nobody in' );
		$this->assertSame( '', diluxone_users_sso_buttons( array() ), 'no provider, no buttons' );
	}

	/**
	 * A provider's colour comes from a filter anybody can hook, and it is
	 * printed inside a style attribute: only a colour gets there.
	 */
	public function test_a_providers_colour_is_a_colour_or_nothing(): void {
		$button = diluxone_users_sso_button( 'acme', array( 'name' => 'Acme', 'color' => 'red;background:url(x)' ) );

		$this->assertStringContainsString( 'style="--diluxone-users-brand: "', $button );
		$this->assertStringNotContainsString( 'url(x)', $button );

		$this->assertStringContainsString( '--diluxone-users-brand: #123456', diluxone_users_sso_button( 'acme', array( 'name' => 'Acme', 'color' => '#123456' ) ) );
	}

	/** On a network, a site's administrator posting the credentials form changes nothing. */
	public function test_on_a_network_a_sites_administrator_cannot_post_credentials(): void {
		if ( ! is_multisite() ) {
			$this->markTestSkipped( 'A network case; on a single site the counterpart is test_each_save_stops_on_a_bad_nonce_or_a_missing_capability.' );
		}

		$before = diluxone_users_raw_get( 'diluxone_users_sso', array() );
		$this->in_network_admin();
		$admin = $this->make_user( 'administrator' );
		wp_set_current_user( $admin );

		$this->postAs(
			$admin,
			array(
				'diluxone_users_client_id'      => 'stolen',
				'diluxone_users_client_secret'  => 'stolen',
				'diluxone_users_active'         => '1',
				'diluxone_users_provider_nonce' => wp_create_nonce( 'diluxone_users_provider' ),
			)
		);

		ob_start();

		try {
			diluxone_users_screen_provider( 'google', diluxone_users_sso_providers()['google'] );
		} finally {
			$html = (string) ob_get_clean();
		}

		$this->assertStringNotContainsString( 'Provider saved.', $html );

		$this->assertSame( $before, diluxone_users_raw_get( 'diluxone_users_sso', array() ) );
	}
}
