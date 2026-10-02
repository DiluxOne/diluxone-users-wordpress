<?php
/**
 * Maintenance › Status: every check, with each verdict it can give.
 *
 * The tab touches nothing; what it has to get right is the verdict. A page
 * that is missing, a draft or without its shortcode; mail that failed, went
 * out, or was never tried; HTTPS with and without passkeys on; plain
 * permalinks; rewrite rules from another version; the e-mail link as the
 * only door while mail fails; providers filled in and left off. Each is drawn
 * here and its state read back.
 *
 * Every case runs on both topologies: the screen is a site's, and on a
 * network it is drawn on the hub, where its pages are.
 */

namespace Tests\Integration;

use Tests\Integration\Support\CoverAdminSupport;

class CoverAdminStatusTest extends IntegrationTestCase {

	use CoverAdminSupport;

	/** @var array<int, array{0: string, 1: callable, 2: int}> */
	private array $filters = array();

	/** @var array<int, string> */
	private array $shortcodes = array();

	/** @var mixed */
	private $rewrite;

	protected function setUp(): void {
		parent::setUp();

		$this->rewrite = diluxone_users_raw_get( 'diluxone_users_rewrite_version', null );
	}

	protected function tearDown(): void {
		foreach ( $this->filters as $filter ) {
			remove_filter( $filter[0], $filter[1], $filter[2] );
		}

		foreach ( $this->shortcodes as $tag ) {
			remove_shortcode( $tag );
		}

		if ( null === $this->rewrite ) {
			diluxone_users_delete_option( 'diluxone_users_rewrite_version' );
		} else {
			diluxone_users_update_option( 'diluxone_users_rewrite_version', $this->rewrite );
		}

		$this->filters    = array();
		$this->shortcodes = array();
		$this->cover_admin_reset();

		parent::tearDown();
	}

	private function filter( string $hook, callable $callback, int $priority = 10, int $args = 1 ): void {
		add_filter( $hook, $callback, $priority, $args );
		$this->filters[] = array( $hook, $callback, $priority );
	}

	/** The shortcode, where has_shortcode() looks for it inside the suite. */
	private function shortcode( string $tag ): void {
		if ( ! shortcode_exists( $tag ) ) {
			add_shortcode( $tag, '__return_empty_string' );
			$this->shortcodes[] = $tag;
		}
	}

	private function page( string $status, string $content ): int {
		return (int) wp_insert_post(
			array(
				'post_type'    => 'page',
				'post_title'   => 'My account',
				'post_status'  => $status,
				'post_content' => $content,
			)
		);
	}

	private function account_check(): array {
		return diluxone_users_check_page( 'diluxone_users_account_page', 'diluxone_users_account', 'Account page', 'diluxone-users-account' );
	}

	/** The row of one check, by its label. */
	private function row( string $label ): array {
		foreach ( diluxone_users_checks() as $check ) {
			if ( $label === $check['label'] ) {
				return $check;
			}
		}

		$this->fail( 'No check called ' . $label );
	}

	/* ── One check, as a row ───────────────────────────────────────── */

	public function test_a_row_keeps_its_own_word_and_link_text_only_when_it_has_them(): void {
		$row = diluxone_users_check( 'Thing', 'off', 'detail', '', 'https://example.test', 'Fix →', 'Broken' );

		$this->assertSame( 'Broken', $row['word'] );
		$this->assertSame( 'Fix →', $row['change'] );

		$row = diluxone_users_check( 'Thing', 'active' );
		$this->assertArrayNotHasKey( 'word', $row );
		$this->assertArrayNotHasKey( 'change', $row );
	}

	/* ── The pages ─────────────────────────────────────────────────── */

	public function test_the_account_page_none_gone_a_draft_without_its_shortcode_and_working(): void {
		$this->the_admin();
		$this->shortcode( 'diluxone_users_account' );

		$this->assertSame( 'pending', $this->account_check()['state'], 'none chosen' );
		$this->assertSame( 'No page chosen yet.', $this->account_check()['why'] );

		diluxone_users_update_option( 'diluxone_users_account_page', 999999 );
		$this->assertSame( 'off', $this->account_check()['state'] );
		$this->assertSame( 'The chosen page no longer exists.', $this->account_check()['detail'] );

		$post = (int) wp_insert_post( array( 'post_title' => 'A post', 'post_status' => 'publish' ) );
		diluxone_users_update_option( 'diluxone_users_account_page', $post );
		$this->assertSame( 'off', $this->account_check()['state'], 'a post is not a page' );

		$draft = $this->page( 'draft', '[diluxone_users_account]' );
		diluxone_users_update_option( 'diluxone_users_account_page', $draft );
		$check = $this->account_check();
		$this->assertSame( 'off', $check['state'] );
		$this->assertStringContainsString( 'is not published', $check['detail'] );
		$this->assertStringContainsString( 'post=' . $draft, $check['url'], 'the way to it is its edit screen' );

		// The plugin draws its own pages whether the shortcode is typed in
		// them or not, and says so through the filter.
		$bare = $this->page( 'publish', 'Nothing here' );
		diluxone_users_update_option( 'diluxone_users_account_page', $bare );
		$this->assertSame( 'active', $this->account_check()['state'] );

		// Without that answer, the content alone is what is read.
		remove_filter( 'diluxone_users_page_draws', 'diluxone_users_page_draws_check', 10 );

		try {
			$check = $this->account_check();
		} finally {
			add_filter( 'diluxone_users_page_draws', 'diluxone_users_page_draws_check', 10, 2 );
		}

		$this->assertSame( 'pending', $check['state'] );
		$this->assertStringContainsString( '[diluxone_users_account]', $check['detail'] );
		$this->assertSame( 'Shortcode not found.', $check['why'] );

		$good = $this->page( 'publish', '[diluxone_users_account]' );
		diluxone_users_update_option( 'diluxone_users_account_page', $good );
		$check = $this->account_check();
		$this->assertSame( 'active', $check['state'] );
		$this->assertSame( 'My account', $check['detail'] );
	}

	/* ── Mail ──────────────────────────────────────────────────────── */

	public function test_mail_failing_going_out_and_never_tried(): void {
		$this->the_admin();

		$this->assertSame( 'unknown', diluxone_users_check_mail()['state'] );

		diluxone_users_update_option( 'diluxone_users_mail_last', array( 'ok' => 0, 'time' => time(), 'error' => '<b>SMTP</b> refused' ) );
		$check = diluxone_users_check_mail();
		$this->assertSame( 'off', $check['state'] );
		$this->assertSame( 'SMTP refused', $check['detail'], 'the error as text' );
		$this->assertSame( 'Failing', $check['word'] );

		diluxone_users_update_option( 'diluxone_users_mail_last', array( 'ok' => 0, 'time' => time() ) );
		$this->assertSame( 'The last message could not be sent.', diluxone_users_check_mail()['detail'] );

		diluxone_users_update_option( 'diluxone_users_mail_last', array( 'ok' => 1, 'time' => time() - HOUR_IN_SECONDS ) );
		$check = diluxone_users_check_mail();
		$this->assertSame( 'active', $check['state'] );
		$this->assertSame( 'Going out', $check['word'] );
		$this->assertStringContainsString( '1 hour', $check['detail'] );
	}

	/* ── HTTPS, permalinks, rewrite rules ──────────────────────────── */

	public function test_https_with_and_without_passkeys(): void {
		$this->the_admin();

		$http = static fn( string $url ): string => set_url_scheme( $url, 'http' );
		$this->filter( 'home_url', $http, 99 );
		$this->filter( 'site_url', $http, 99 );

		diluxone_users_update_option( 'diluxone_users_passkey_enabled', 0 );
		$this->assertSame( 'pending', $this->row( 'HTTPS' )['state'] );

		diluxone_users_update_option( 'diluxone_users_passkey_enabled', 1 );
		$this->assertSame( 'off', $this->row( 'HTTPS' )['state'], 'a door nobody can open' );

		remove_filter( 'home_url', $http, 99 );
		remove_filter( 'site_url', $http, 99 );

		$https = static fn( string $url ): string => set_url_scheme( $url, 'https' );
		$this->filter( 'home_url', $https, 99 );
		$this->filter( 'site_url', $https, 99 );
		$this->assertSame( 'active', $this->row( 'HTTPS' )['state'] );
	}

	public function test_plain_permalinks_are_off_and_pretty_ones_are_fine(): void {
		$this->the_admin();

		$plain = static fn() => '';
		$this->filter( 'pre_option_permalink_structure', $plain );
		$check = $this->row( 'Permalinks' );
		$this->assertSame( 'off', $check['state'] );
		$this->assertStringContainsString( 'options-permalink.php', $check['url'] );

		remove_filter( 'pre_option_permalink_structure', $plain );

		$this->filter( 'pre_option_permalink_structure', static fn() => '/%postname%/' );
		$check = $this->row( 'Permalinks' );
		$this->assertSame( 'active', $check['state'] );
		$this->assertSame( '/%postname%/', $check['detail'] );
	}

	public function test_rewrite_rules_of_another_version_point_at_the_tools(): void {
		$this->the_admin();

		diluxone_users_update_option( 'diluxone_users_rewrite_version', '0.0.1' );
		$check = $this->row( 'Rewrite rules' );
		$this->assertSame( 'pending', $check['state'] );
		$this->assertStringContainsString( 'tab=tools', $check['url'] );

		diluxone_users_update_option( 'diluxone_users_rewrite_version', DILUXONE_USERS_VERSION );
		$this->assertSame( 'active', $this->row( 'Rewrite rules' )['state'] );
	}

	/* ── Ways in and the social networks ───────────────────────────── */

	public function test_the_link_as_the_only_door_with_failing_mail_locks_everybody_out(): void {
		$this->the_admin();

		diluxone_users_update_option( 'diluxone_users_login_method', 'link' );
		$this->assertSame( 'active', diluxone_users_check_ways_in()['state'], 'mail not known to fail' );
		$this->assertSame( 'e-mail link', diluxone_users_check_ways_in()['detail'] );

		diluxone_users_update_option( 'diluxone_users_mail_last', array( 'ok' => 0, 'time' => time() ) );
		$check = diluxone_users_check_ways_in();
		$this->assertSame( 'off', $check['state'] );
		$this->assertStringContainsString( 'Nobody can sign in.', $check['detail'] );

		diluxone_users_update_option( 'diluxone_users_login_method', 'password' );
		$this->assertSame( 'active', diluxone_users_check_ways_in()['state'], 'the password does not need the mail' );
	}

	public function test_social_providers_ready_and_dormant(): void {
		$this->the_admin();

		$this->assertNull( diluxone_users_check_social(), 'none filled in, no row' );

		diluxone_users_update_option(
			'diluxone_users_sso',
			array(
				'google' => array( 'active' => 1, 'id' => 'gid', 'secret' => 'gsecret', 'tested' => 1 ),
			)
		);
		$check = diluxone_users_check_social();
		$this->assertSame( 'active', $check['state'] );
		$this->assertSame( '1 provider configured and enabled.', $check['detail'] );
		$this->assertStringContainsString( 'social login', diluxone_users_check_ways_in()['detail'] );

		diluxone_users_update_option(
			'diluxone_users_sso',
			array(
				'google' => array( 'active' => 1, 'id' => 'gid', 'secret' => 'gsecret', 'tested' => 1 ),
				'github' => array( 'active' => 0, 'id' => 'hid', 'secret' => 'hsecret', 'tested' => 1 ),
			)
		);
		$check = diluxone_users_check_social();
		$this->assertSame( 'pending', $check['state'] );
		$this->assertStringContainsString( 'GitHub', $check['detail'] );
		$this->assertStringContainsString( 'page=diluxone-users-social', $check['url'] );
		$this->assertSame( $check, $this->row( 'Social login' ), 'and it is on the tab' );
	}

	/* ── The tab ───────────────────────────────────────────────────── */

	public function test_the_tab_counts_what_is_off_and_shows_the_numbers(): void {
		$this->the_admin();

		$person = $this->make_user();
		update_user_meta( $person, 'diluxone_users_totp', 'JBSWY3DPEHPK3PXP' );
		update_user_meta( $person, 'diluxone_users_sso_google', 'g-1' );

		diluxone_users_update_option( 'diluxone_users_mail_last', array( 'ok' => 0, 'time' => time() ) );
		$this->filter( 'pre_option_permalink_structure', static fn() => '' );

		$html = $this->draw( 'diluxone_users_screen_status_checks' );

		$this->assertMatchesRegularExpression( '/notice-error[^>]*><p>\d+ things need attention\./', $html );
		$this->assertStringContainsString( 'With an authenticator app', $html );
		$this->assertStringContainsString( 'wp-content/themes/' . get_stylesheet() . '/diluxone-users/', $html );
		$this->assertStringContainsString( '<code>' . DILUXONE_USERS_VERSION . '</code>', $html );

		$stats = diluxone_users_stats();
		$this->assertGreaterThanOrEqual( 1, $stats['totp'] );
		$this->assertGreaterThanOrEqual( 1, $stats['social'] );
	}

	public function test_a_site_with_nothing_wrong_says_everything_checks_out(): void {
		$this->the_admin();
		$this->shortcode( 'diluxone_users_account' );
		$this->shortcode( 'diluxone_users_login' );

		diluxone_users_update_option( 'diluxone_users_account_page', $this->page( 'publish', '[diluxone_users_account]' ) );
		diluxone_users_update_option( 'diluxone_users_login_page', $this->page( 'publish', '[diluxone_users_login]' ) );
		diluxone_users_update_option( 'diluxone_users_mail_last', array( 'ok' => 1, 'time' => time() ) );
		diluxone_users_update_option( 'diluxone_users_rewrite_version', DILUXONE_USERS_VERSION );
		$this->filter( 'pre_option_permalink_structure', static fn() => '/%postname%/' );

		$html = $this->draw( 'diluxone_users_screen_status_checks' );

		$this->assertStringContainsString( 'Everything checks out.', $html );
		$this->assertStringNotContainsString( 'need attention', $html );
	}

	public function test_the_whole_screen_draws_its_tabs(): void {
		$this->the_admin();
		do_action( 'diluxone_users_register_panels' );

		$_GET = array(
			'page' => DILUXONE_USERS_STATUS,
			'tab'  => 'lockout',
		);
		$html = $this->draw( 'diluxone_users_screen_status' );

		$this->assertSame( 1, substr_count( $html, 'nav-tab-active' ) );
		$this->assertMatchesRegularExpression( '/class="nav-tab nav-tab-active" href="[^"]*tab=lockout[^"]*"/', $html );
		$this->assertStringContainsString( 'wp diluxone-users login ' . wp_get_current_user()->user_email, $html );
		$this->assertStringContainsString( 'wp-login.php?diluxone-users-admin=1', $html );
	}
}
