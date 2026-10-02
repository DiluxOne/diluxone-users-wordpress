<?php
/**
 * The ways in, drawn: which tab opens and why, the ways that stay outside the
 * tabs with the line between them, the passkey's button, the e-mail box that
 * also takes a public name, and the tab script queued on the site rather than
 * in a preview.
 */

namespace Tests\Integration;

class CoverSignInWaysTest extends IntegrationTestCase {

	/** @var array<string, array<string, mixed>> Ways the test adds, through the `diluxone_users_ways` filter. */
	private array $extra = array();

	protected function setUp(): void {
		parent::setUp();

		add_filter( 'diluxone_users_ways', array( $this, 'with_extra' ) );
	}

	protected function tearDown(): void {
		remove_filter( 'diluxone_users_ways', array( $this, 'with_extra' ) );
		remove_filter( 'diluxone_users_ways', '__return_empty_array' );

		wp_dequeue_script( 'diluxone-users-ways' );
		wp_deregister_script( 'diluxone-users-ways' );
		wp_dequeue_script( 'diluxone-users-passkeys' );
		wp_deregister_script( 'diluxone-users-passkeys' );

		// What a drawing in the dashboard asked the preview for.
		diluxone_users_preview_scripts( null, true );

		parent::tearDown();
	}

	/**
	 * The `diluxone_users_ways` filter.
	 *
	 * @param array<string, array<string, mixed>> $ways
	 * @return array<string, array<string, mixed>>
	 */
	public function with_extra( array $ways ): array {
		return $ways + $this->extra;
	}

	/** @param array<string, mixed> $way */
	private function extra( string $id, array $way ): void {
		$this->extra[ $id ] = $way + array(
			'label'     => $id,
			'icon'      => '',
			'over'      => '',
			'available' => '__return_true',
			'states'    => array(),
			'outside'   => false,
			'position'  => 50,
		);
	}

	private function drawn(): string {
		ob_start();
		diluxone_users_ways_render();

		return (string) ob_get_clean();
	}

	/** What the block says opens. */
	private function opens( string $html ): string {
		preg_match( '/data-diluxone-users-ways-open="([^"]*)"/', $html, $m );

		return $m[1] ?? '';
	}

	/* ── Which tab opens ─────────────────────────────────────────────── */

	public function test_with_no_ways_in_nothing_is_drawn(): void {
		add_filter( 'diluxone_users_ways', '__return_empty_array' );

		$this->assertSame( '', $this->drawn() );
		$this->assertSame( '', diluxone_users_way_open( array() ) );
	}

	public function test_the_tab_used_last_opens_when_nothing_failed(): void {
		$_COOKIE[ DILUXONE_USERS_WAY_COOKIE ] = 'password';

		$html = $this->drawn();

		$this->assertSame( 'password', $this->opens( $html ) );
		$this->assertStringNotContainsString( 'data-diluxone-users-ways-state', $html );
	}

	public function test_the_tab_that_failed_wins_over_the_one_used_last_and_says_so(): void {
		$_COOKIE[ DILUXONE_USERS_WAY_COOKIE ] = 'password';
		$_GET['diluxone-users']               = 'email';

		$html = $this->drawn();

		$this->assertSame( 'email', $this->opens( $html ) );
		$this->assertStringContainsString( ' data-diluxone-users-ways-state>', $html );
	}

	public function test_a_cookie_naming_no_tab_falls_to_the_site_s_first_choice_then_to_the_first(): void {
		$_COOKIE[ DILUXONE_USERS_WAY_COOKIE ] = 'nothing-here';
		diluxone_users_update_option( 'diluxone_users_login_open', 'password' );

		$this->assertSame( 'password', $this->opens( $this->drawn() ) );

		diluxone_users_update_option( 'diluxone_users_login_open', 'gone' );

		$this->assertSame( (string) array_key_first( array_diff_key( diluxone_users_ways(), array( 'passkey' => 1 ) ) ), $this->opens( $this->drawn() ) );
	}

	/* ── What stays outside the tabs ─────────────────────────────────── */

	public function test_the_passkey_stays_above_the_tabs_with_only_or_between(): void {
		diluxone_users_update_option( 'diluxone_users_passkey_enabled', 1 );
		diluxone_users_update_option( 'diluxone_users_login_layout', 'tabs' );

		$html = $this->drawn();

		$passkey = strpos( $html, 'id="diluxone-users-way-passkey"' );
		$strip   = strpos( $html, 'data-diluxone-users-ways-strip' );
		$this->assertNotFalse( $passkey );
		$this->assertLessThan( $strip, $passkey );
		$this->assertStringContainsString( 'data-diluxone-users-passkey="login"', $html );
		$this->assertStringContainsString( 'Sign in with a passkey', $html );
		// In tabs the line under the passkey is the plain "or".
		$this->assertStringContainsString( '</div><p class="diluxone-users-divider"><span>or</span></p><div class="diluxone-users-ways__strip"', $html );
		$this->assertTrue( wp_script_is( 'diluxone-users-passkeys', 'enqueued' ) );
	}

	public function test_stacked_the_line_under_the_passkey_is_the_first_way_s_own(): void {
		diluxone_users_update_option( 'diluxone_users_passkey_enabled', 1 );
		diluxone_users_update_option( 'diluxone_users_login_layout', 'stack' );
		diluxone_users_update_option( 'diluxone_users_button_icons', 1 );

		$html = $this->drawn();

		$tabs  = array_filter( diluxone_users_ways(), static fn( array $way ): bool => empty( $way['outside'] ) );
		$first = (array) reset( $tabs );
		$this->assertStringContainsString( '<p class="diluxone-users-divider"><span>' . esc_html( (string) $first['over'] ) . '</span></p>', $html );
		$this->assertStringContainsString( 'data-diluxone-users-ways-mode="stack"', $html );
		// With icons on, the passkey's button carries its key.
		$this->assertMatchesRegularExpression( '#data-diluxone-users-passkey="login"><svg class="diluxone-users-icon diluxone-users-icon--key"#', $html );
	}

	public function test_two_ways_outside_the_tabs_have_their_own_line_between_them(): void {
		diluxone_users_update_option( 'diluxone_users_passkey_enabled', 1 );
		$this->extra(
			'cover-outside',
			array(
				'outside' => true,
				'over'    => 'or a <b>hardware</b> key',
				'render'  => static function (): void {
					echo '<span data-cover-outside></span>';
				},
			)
		);

		$html = $this->drawn();

		$this->assertStringContainsString( '<p class="diluxone-users-divider"><span>or a &lt;b&gt;hardware&lt;/b&gt; key</span></p><div class="diluxone-users-way diluxone-users-way--outside" id="diluxone-users-way-cover-outside"', $html );
		$this->assertStringContainsString( '<span data-cover-outside></span>', $html );
	}

	public function test_a_way_with_nothing_to_draw_it_takes_no_room(): void {
		$this->extra( 'cover-broken', array( 'render' => 'no_such_function_anywhere' ) );

		$html = $this->drawn();

		$this->assertStringNotContainsString( 'id="diluxone-users-way-cover-broken"', $html );

		ob_start();
		diluxone_users_way_render( 'cover-broken', $this->extra['cover-broken'] );
		$this->assertSame( '', ob_get_clean() );
	}

	/* ── The e-mail box ──────────────────────────────────────────────── */

	public function test_the_email_box_takes_a_public_name_when_the_site_allows_it(): void {
		ob_start();
		diluxone_users_way_email();
		$plain = (string) ob_get_clean();

		$this->assertStringContainsString( '<input type="email" id="diluxone-users-email"', $plain );

		diluxone_users_update_option( 'diluxone_users_handle_enabled', 1 );
		diluxone_users_update_option( 'diluxone_users_handle_login', 1 );

		ob_start();
		diluxone_users_way_email();
		$named = (string) ob_get_clean();

		$this->assertStringContainsString( 'Email address or public name', $named );
		$this->assertStringContainsString( '<input type="text" inputmode="email"', $named );
		$this->assertStringNotContainsString( 'type="email"', $named );
	}

	/* ── The tab script ──────────────────────────────────────────────── */

	public function test_in_the_dashboard_the_tab_script_is_asked_of_the_preview(): void {
		diluxone_users_update_option( 'diluxone_users_login_layout', 'tabs' );
		diluxone_users_preview_scripts( null, true );

		$this->drawn();

		$this->assertContains( 'diluxone-users-ways', diluxone_users_preview_scripts() );
		$this->assertFalse( wp_script_is( 'diluxone-users-ways', 'enqueued' ) );
	}

	public function test_on_the_site_the_tab_script_is_queued_once_in_the_footer(): void {
		$this->as_front_end();

		$this->assertFalse( is_admin() );
		diluxone_users_update_option( 'diluxone_users_login_layout', 'tabs' );

		$this->drawn();
		$this->drawn();

		$this->assertTrue( wp_script_is( 'diluxone-users-ways', 'enqueued' ) );
		$this->assertSame( 1, wp_scripts()->get_data( 'diluxone-users-ways', 'group' ) );
		$this->assertSame( 1, count( array_keys( wp_scripts()->queue, 'diluxone-users-ways', true ) ) );
	}

	public function test_on_the_site_stacked_ways_queue_no_tab_script(): void {
		$this->as_front_end();
		diluxone_users_update_option( 'diluxone_users_login_layout', 'stack' );

		$this->drawn();

		$this->assertFalse( wp_script_is( 'diluxone-users-ways', 'enqueued' ) );
	}

	public function test_stacked_ways_queue_no_tab_script(): void {
		diluxone_users_update_option( 'diluxone_users_login_layout', 'stack' );
		diluxone_users_preview_scripts( null, true );

		$this->drawn();

		$this->assertNotContains( 'diluxone-users-ways', diluxone_users_preview_scripts() );
	}
}
