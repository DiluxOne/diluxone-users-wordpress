<?php
/**
 * The two tools on Maintenance › Tools that act on somebody else's sign-ins.
 *
 * A session is the network's, not one site's: on a network a site's
 * administrator reached "close everyone's sessions" from their own Tools tab,
 * and it signed out every account of the network, super admins included; and
 * any address typed into "close this person's" or "send a fresh code" was
 * acted on. On a network both are now for whoever administers its users, and
 * a site's administrator does not see them. On a single site nothing changes.
 */

namespace Tests\Integration;

class ToolsPeopleTest extends IntegrationTestCase {

	/** @var array<int, int> People made super admin by the test, taken back after it. */
	private array $supers = array();

	protected function setUp(): void {
		parent::setUp();

		add_filter( 'wp_redirect', array( $this, 'throw_redirect' ) );
	}

	protected function tearDown(): void {
		remove_filter( 'wp_redirect', array( $this, 'throw_redirect' ) );

		while ( is_multisite() && ms_is_switched() ) {
			restore_current_blog();
		}

		foreach ( $this->supers as $user ) {
			revoke_super_admin( $user );
		}

		wp_set_current_user( 0 );
		$_POST    = array();
		$_REQUEST = array();

		parent::tearDown();
	}

	private function network_only(): void {
		if ( ! is_multisite() ) {
			$this->markTestSkipped( 'A network case.' );
		}
	}

	private function single_only(): void {
		if ( is_multisite() ) {
			$this->markTestSkipped( 'A single-site case.' );
		}
	}

	/** Somebody with one open session. */
	private function signed_in( string $role = 'subscriber' ): int {
		$user = $this->make_user( $role );
		\WP_Session_Tokens::get_instance( $user )->create( time() + HOUR_IN_SECONDS );

		return $user;
	}

	private function sessions( int $user ): int {
		return count( \WP_Session_Tokens::get_instance( $user )->get_all() );
	}

	/** A super admin with an open session. */
	private function super_admin(): int {
		$user = $this->signed_in();
		grant_super_admin( $user );
		$this->supers[] = $user;

		return $user;
	}

	/** A site of the network, and its administrator, standing on it. */
	private function site_admin(): int {
		$site  = (int) wp_insert_site(
			array(
				'domain' => (string) get_network()->domain,
				'path'   => get_network()->path . 'tools-' . wp_generate_password( 6, false, false ) . '/',
			)
		);
		$admin = $this->make_user();

		add_user_to_blog( $site, $admin, 'administrator' );
		switch_to_blog( $site );

		return $admin;
	}

	/**
	 * Runs a tool as somebody, and says how it ended.
	 *
	 * @param array<string, mixed> $post
	 * @return string 'redirect' or 'died'
	 */
	private function run_tool( int $as, array $post ): string {
		wp_set_current_user( $as );
		$this->postAs( $as, $post + array( '_wpnonce' => wp_create_nonce( 'diluxone_users_tools' ) ) );

		try {
			diluxone_users_tools_action();
		} catch ( Support\RedirectException $e ) {
			return 'redirect';
		} catch ( \WPAjaxDieContinueException $e ) {
			return 'died';
		}

		$this->fail( 'The tool neither redirected nor stopped.' );
	}

	/** What the Tools tab draws for whoever is signed in. */
	private function tools_tab( int $as ): string {
		wp_set_current_user( $as );
		ob_start();
		diluxone_users_screen_tools_boxes();

		return (string) ob_get_clean();
	}

	/* ── On a network ───────────────────────────────────────────────── */

	public function test_a_site_administrator_cannot_sign_the_whole_network_out(): void {
		$this->network_only();

		$super = $this->super_admin();
		$other = $this->signed_in();
		$admin = $this->site_admin();

		$this->assertSame( 'died', $this->run_tool( $admin, array( 'tool' => 'close', 'scope' => 'all' ) ) );
		$this->assertSame( 1, $this->sessions( $super ), 'the super admin is still signed in' );
		$this->assertSame( 1, $this->sessions( $other ), 'and so is everybody else' );
	}

	public function test_a_site_administrator_cannot_sign_a_super_admin_out_by_address(): void {
		$this->network_only();

		$super = $this->super_admin();
		$admin = $this->site_admin();

		$this->assertSame(
			'died',
			$this->run_tool(
				$admin,
				array(
					'tool'        => 'close',
					'scope'       => 'one',
					'close_email' => get_userdata( $super )->user_email,
				)
			)
		);
		$this->assertSame( 1, $this->sessions( $super ) );
	}

	public function test_a_site_administrator_cannot_send_somebody_a_code(): void {
		$this->network_only();

		$super      = $this->super_admin();
		$admin      = $this->site_admin();
		self::$mail = array();

		$this->assertSame( 'died', $this->run_tool( $admin, array( 'tool' => 'code', 'email' => get_userdata( $super )->user_email ) ) );
		$this->assertSame( array(), self::$mail, 'no code went out' );
	}

	public function test_a_site_administrator_does_not_see_the_two_tools(): void {
		$this->network_only();

		$html = $this->tools_tab( $this->site_admin() );

		$this->assertStringNotContainsString( 'value="close"', $html );
		$this->assertStringNotContainsString( 'value="code"', $html );
		$this->assertStringContainsString( 'value="flush"', $html, 'the site’s own tools are still there' );
	}

	public function test_a_super_admin_closes_one_persons_sessions_and_nobody_elses(): void {
		$this->network_only();

		$super = $this->super_admin();
		$one   = $this->signed_in();
		$other = $this->signed_in();

		$this->assertStringContainsString( 'value="close"', $this->tools_tab( $super ) );
		$this->assertSame(
			'redirect',
			$this->run_tool(
				$super,
				array(
					'tool'        => 'close',
					'scope'       => 'one',
					'close_email' => get_userdata( $one )->user_email,
				)
			)
		);
		$this->assertSame( 0, $this->sessions( $one ) );
		$this->assertSame( 1, $this->sessions( $other ) );
	}

	/* ── On a single site ───────────────────────────────────────────── */

	public function test_on_a_single_site_the_administrator_keeps_both_tools(): void {
		$this->single_only();

		$admin = $this->make_user( 'administrator' );
		$one   = $this->signed_in();
		$other = $this->signed_in();

		$html = $this->tools_tab( $admin );
		$this->assertStringContainsString( 'value="close"', $html );
		$this->assertStringContainsString( 'value="code"', $html );

		$this->assertSame(
			'redirect',
			$this->run_tool(
				$admin,
				array(
					'tool'        => 'close',
					'scope'       => 'one',
					'close_email' => get_userdata( $one )->user_email,
				)
			)
		);
		$this->assertSame( 0, $this->sessions( $one ) );
		$this->assertSame( 1, $this->sessions( $other ) );
	}

	public function test_an_address_sent_as_a_list_is_no_address_and_no_error(): void {
		$admin = is_multisite() ? $this->super_admin() : $this->make_user( 'administrator' );

		$this->assertSame(
			'redirect',
			$this->run_tool(
				$admin,
				array(
					'tool'        => 'close',
					'scope'       => 'one',
					'close_email' => array( 'x@example.test' ),
				)
			)
		);
		$this->assertSame( 'redirect', $this->run_tool( $admin, array( 'tool' => 'code', 'email' => array( 'x@example.test' ) ) ) );
	}
}
