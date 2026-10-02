<?php
/**
 * Maintenance › Tools: every button, behind its nonce and its capability.
 *
 * Each tool comes in through one handler that checks the capability and the
 * nonce before it reads the form, and each ends in a redirect back to the tab
 * with what happened kept for whoever pressed it. Here: somebody who may not
 * use them is stopped, an unknown tool does nothing, and the rebuild, the
 * fresh code, closing every session, the wipe switch, the test message and
 * the restore each do what they say and nothing more. Somebody else's
 * sign-ins on a network are ToolsPeopleTest's.
 *
 * Every case runs on both topologies unless it says otherwise.
 */

namespace Tests\Integration;

use Tests\Integration\Support\CoverAdminSupport;

class CoverAdminToolsTest extends IntegrationTestCase {

	use CoverAdminSupport;

	/** @var array<int, array{0: string, 1: callable, 2: int}> */
	private array $filters = array();

	/** @var array<int, mixed> Every session on the site before the test, by user. */
	private array $sessions = array();

	/** @var mixed */
	private $rewrite;

	protected function setUp(): void {
		parent::setUp();

		require_once ABSPATH . 'wp-admin/includes/admin.php';

		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		foreach ( (array) $wpdb->get_results( "SELECT user_id, meta_value FROM {$wpdb->usermeta} WHERE meta_key = 'session_tokens'" ) as $row ) {
			$this->sessions[ (int) $row->user_id ] = maybe_unserialize( $row->meta_value );
		}

		$this->rewrite = diluxone_users_raw_get( 'diluxone_users_rewrite_version', null );
	}

	protected function tearDown(): void {
		foreach ( $this->filters as $filter ) {
			remove_filter( $filter[0], $filter[1], $filter[2] );
		}

		$this->filters = array();

		// Closing everybody's sessions reaches the accounts the test did not make.
		foreach ( $this->sessions as $user => $tokens ) {
			update_user_meta( $user, 'session_tokens', $tokens );
		}

		if ( null === $this->rewrite ) {
			diluxone_users_delete_option( 'diluxone_users_rewrite_version' );
		} else {
			diluxone_users_update_option( 'diluxone_users_rewrite_version', $this->rewrite );
		}

		delete_transient( DILUXONE_USERS_TOOL_RESULT . '_' . get_current_user_id() );
		$this->cover_admin_reset();

		parent::tearDown();
	}

	private function filter( string $hook, callable $callback, int $priority = 10, int $args = 1 ): void {
		add_filter( $hook, $callback, $priority, $args );
		$this->filters[] = array( $hook, $callback, $priority );
	}

	/**
	 * Runs one tool as whoever is signed in, with the tools' nonce.
	 *
	 * @param array<string, mixed> $post
	 * @return array{0: string, 1: string}
	 */
	private function tool( array $post, string $nonce = '' ): array {
		$this->postAs( get_current_user_id(), $post + array( '_wpnonce' => '' !== $nonce ? $nonce : wp_create_nonce( 'diluxone_users_tools' ) ) );

		return $this->ended( 'diluxone_users_tools_action' );
	}

	/** What the tool left for the tab to say: [text, type]. */
	private function said(): array {
		$said = get_transient( DILUXONE_USERS_TOOL_RESULT . '_' . get_current_user_id() );

		return is_array( $said ) ? $said : array();
	}

	/* ── The door ──────────────────────────────────────────────────── */

	public function test_somebody_who_cannot_manage_the_site_is_stopped_before_the_nonce(): void {
		wp_set_current_user( $this->make_user( 'editor' ) );

		$this->assertSame( array( 'died', 'You are not allowed to do this.' ), $this->tool( array( 'tool' => 'flush' ) ) );
		$this->assertSame( array(), $this->said() );
	}

	public function test_a_bad_nonce_stops_every_tool(): void {
		$this->the_admin();
		diluxone_users_update_option( 'diluxone_users_rewrite_version', '0.0.1' );

		$this->assertSame( array( 'died', self::EXPIRED ), $this->tool( array( 'tool' => 'flush' ), 'nope' ) );
		$this->assertSame( '0.0.1', diluxone_users_raw_get( 'diluxone_users_rewrite_version' ) );
	}

	public function test_an_unknown_tool_does_nothing_and_says_so(): void {
		$this->the_admin();

		$ended = $this->tool( array( 'tool' => 'format-the-disk' ) );

		$this->assertSame( 'redirect', $ended[0] );
		$this->assertSame( 'tools', $this->queryArg( $ended[1], 'tab' ) );
		$this->assertSame( array( 'Nothing to do.', 'error' ), $this->said() );
	}

	/* ── The tools ─────────────────────────────────────────────────── */

	public function test_the_rewrite_rules_are_rebuilt_and_the_version_recorded(): void {
		$this->the_admin();
		diluxone_users_update_option( 'diluxone_users_rewrite_version', '0.0.1' );

		$this->assertSame( 'redirect', $this->tool( array( 'tool' => 'flush' ) )[0] );
		$this->assertSame( DILUXONE_USERS_VERSION, diluxone_users_raw_get( 'diluxone_users_rewrite_version' ) );
		$this->assertSame( array( 'Rewrite rules rebuilt.', 'success' ), $this->said() );
	}

	public function test_a_fresh_code_goes_to_the_person_hashed_and_is_never_shown(): void {
		$this->the_admin();
		$person = $this->make_user();
		$email  = get_userdata( $person )->user_email;

		$this->assertSame( 'redirect', $this->tool( array( 'tool' => 'code', 'email' => $email ) )[0] );

		$mail   = $this->lastMail();
		$stored = get_user_meta( $person, 'diluxone_users_2fa_email', true );

		$this->assertSame( $email, $mail['to'] );
		$this->assertMatchesRegularExpression( '/\b(\d{6})\b/', (string) $mail['message'] );
		preg_match( '/\b(\d{6})\b/', (string) $mail['message'], $code );
		$this->assertSame( wp_hash( $code[1] ), $stored['hash'], 'stored hashed' );
		$this->assertSame( 'success', $this->said()[1] );
		$this->assertStringNotContainsString( $code[1], $this->said()[0], 'the administrator never sees it' );
	}

	public function test_a_fresh_code_that_cannot_be_sent_says_so_and_an_unknown_address_is_no_account(): void {
		$this->the_admin();
		$person = $this->make_user();

		$this->filter( 'pre_wp_mail', '__return_false', 20 );

		$this->tool( array( 'tool' => 'code', 'email' => get_userdata( $person )->user_email ) );
		$this->assertSame( 'error', $this->said()[1] );
		$this->assertStringContainsString( 'could not be sent', $this->said()[0] );

		$this->tool( array( 'tool' => 'code', 'email' => 'nobody-' . wp_generate_password( 6, false ) . '@example.test' ) );
		$this->assertSame( array( 'No account with that e-mail address.', 'error' ), $this->said() );
	}

	public function test_closing_one_unknown_persons_sessions_is_no_account(): void {
		$this->the_admin();

		$this->tool( array( 'tool' => 'close', 'scope' => 'one', 'close_email' => 'nobody@example.test' ) );

		$this->assertSame( array( 'No account with that e-mail address.', 'error' ), $this->said() );
	}

	public function test_closing_every_session_signs_everybody_out(): void {
		$admin = $this->the_admin();
		$one   = $this->make_user();
		$two   = $this->make_user();

		foreach ( array( $admin, $one, $two ) as $user ) {
			\WP_Session_Tokens::get_instance( $user )->create( time() + HOUR_IN_SECONDS );
		}

		$this->assertSame( 'redirect', $this->tool( array( 'tool' => 'close', 'scope' => 'all' ) )[0] );

		foreach ( array( $admin, $one, $two ) as $user ) {
			$this->assertSame( array(), \WP_Session_Tokens::get_instance( $user )->get_all(), 'whoever pressed it included' );
		}

		$this->assertStringContainsString( 'Every session on the site is closed.', $this->said()[0] );
	}

	public function test_the_wipe_switch_is_saved_both_ways(): void {
		$this->where_network_screens_are();
		$this->the_admin();

		$this->tool( array( 'tool' => 'wipe', 'wipe' => '1' ) );
		$this->assertSame( 1, (int) diluxone_users_raw_get( 'diluxone_users_uninstall_wipe' ) );
		$this->assertSame( 'Deleting the plugin will now remove everything it wrote.', $this->said()[0] );

		$this->tool( array( 'tool' => 'wipe' ) );
		$this->assertSame( 0, (int) diluxone_users_raw_get( 'diluxone_users_uninstall_wipe' ) );
		$this->assertSame( 'Deleting the plugin will leave the data where it is.', $this->said()[0] );
	}

	public function test_a_restore_without_a_file_is_no_file(): void {
		$this->the_admin();

		$this->tool( array( 'tool' => 'import' ) );
		$this->assertSame( array( 'No file uploaded.', 'error' ), $this->said() );

		// A path that was not uploaded through this request is not read.
		$_FILES = array(
			'file' => array(
				'tmp_name' => __FILE__,
				'error'    => UPLOAD_ERR_OK,
			),
		);

		try {
			$this->tool( array( 'tool' => 'import' ) );
		} finally {
			$_FILES = array();
		}

		$this->assertSame( array( 'No file uploaded.', 'error' ), $this->said() );
	}

	public function test_restored_sections_are_typed_one_field_at_a_time(): void {
		$clean = diluxone_users_tool_sections(
			array(
				7          => array( 'label' => 'numeric id' ),
				'!!'       => array( 'label' => 'no id left' ),
				'notarray' => 'x',
				'Profile'  => array(
					'position'   => '3',
					'enabled'    => '1',
					'custom'     => 'yes',
					'label'      => '<b>Me</b>',
					'slug'       => 'My Page',
					'intro'      => '<i>hi</i>',
					'content'    => '<script>x</script><p>ok</p>',
					'placement'  => 'Side Bar',
					'visibility' => 'ROLES',
					'roles'      => array( 'Editor', 3 ),
					'evil'       => 'dropped',
				),
			)
		);

		$this->assertSame(
			array(
				'profile' => array(
					'position'   => 3,
					'enabled'    => 1,
					'custom'     => true,
					'label'      => 'Me',
					'slug'       => 'my-page',
					'intro'      => 'hi',
					'content'    => 'x<p>ok</p>',
					'placement'  => 'sidebar',
					'visibility' => 'roles',
					'roles'      => array( 'editor', '3' ),
				),
			),
			$clean
		);
	}

	/* ── The test message ─────────────────────────────────────────── */

	private function mail_test(): array {
		$this->postAs( get_current_user_id(), array( '_wpnonce' => wp_create_nonce( 'diluxone_users_mail_test' ) ) );

		return $this->ended( 'diluxone_users_mail_test' );
	}

	public function test_the_test_message_goes_to_whoever_asked(): void {
		$admin = $this->the_admin();

		$this->assertSame( 'redirect', $this->mail_test()[0] );
		$this->assertSame( get_userdata( $admin )->user_email, $this->lastMail()['to'] );
		$this->assertSame( 'success', $this->said()[1] );
	}

	public function test_a_test_message_that_fails_is_recorded_and_said(): void {
		$this->the_admin();
		$this->filter( 'pre_wp_mail', '__return_false', 20 );

		$this->mail_test();

		$this->assertSame( 'fail', diluxone_users_mail_status()['state'] );
		$this->assertSame( 'error', $this->said()[1] );
		$this->assertStringContainsString( 'wp_mail() returned false', $this->said()[0] );
	}

	public function test_the_test_message_stops_without_its_nonce_or_the_capability(): void {
		$this->the_admin();
		$this->postAs( get_current_user_id(), array( '_wpnonce' => wp_create_nonce( 'diluxone_users_tools' ) ) );
		$this->assertSame( array( 'died', self::EXPIRED ), $this->ended( 'diluxone_users_mail_test' ) );

		wp_set_current_user( $this->make_user( 'editor' ) );
		$this->assertSame( array( 'died', 'You are not allowed to do this.' ), $this->mail_test() );
		$this->assertSame( array(), self::$mail );
	}

	/* ── The tab ───────────────────────────────────────────────────── */

	public function test_the_tab_says_what_the_last_tool_did_once(): void {
		$this->the_admin();
		$this->tool( array( 'tool' => 'flush' ) );

		$html = $this->draw( 'diluxone_users_screen_tools_boxes' );
		$this->assertStringContainsString( 'notice-success', $html );
		$this->assertStringContainsString( 'Rewrite rules rebuilt.', $html );
		$this->assertStringContainsString( 'enctype="multipart/form-data"', $html, 'the restore uploads' );
		$this->assertStringContainsString( 'value="diluxone_users_mail_test"', $html );

		$this->assertStringNotContainsString( 'Rewrite rules rebuilt.', $this->draw( 'diluxone_users_screen_tools_boxes' ), 'said once' );
	}

	public function test_on_a_network_the_wipe_is_the_networks_and_a_site_only_reads_it(): void {
		$this->network_only( 'test_the_wipe_switch_is_saved_both_ways' );

		$this->the_admin();
		update_site_option( 'diluxone_users_uninstall_wipe', 1 );

		$html = $this->draw( 'diluxone_users_screen_tools_boxes' );

		$this->assertStringContainsString( 'deleting the plugin removes everything it wrote, on every site', $html );
		$this->assertStringNotContainsString( 'name="wipe"', $html );
	}

	public function test_on_a_single_site_the_wipe_is_a_box_on_the_tab(): void {
		$this->single_only( 'test_on_a_network_the_wipe_is_the_networks_and_a_site_only_reads_it' );

		$this->the_admin();
		diluxone_users_update_option( 'diluxone_users_uninstall_wipe', 1 );

		$html = $this->draw( 'diluxone_users_screen_tools_boxes' );

		$this->assertMatchesRegularExpression( '/name="wipe" value="1"\s+checked/', $html );
	}
}
