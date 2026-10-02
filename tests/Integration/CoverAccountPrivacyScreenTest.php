<?php
/**
 * The privacy tab: the confirmation link, the download, and what the tab draws.
 *
 * The refusals come first. A request that does not exist, or that Tools filed,
 * is not the account's to confirm; a key that was used or never was is
 * "expired" and says so even to somebody signed out; the download hands a
 * file to the account that asked for it and to nobody else; and a closed
 * account does not sign in. Then the tab, read from its markup in each state:
 * the last step before closing, every notice, the warning only an
 * administrator sees, an administrator who cannot close their own account, and
 * the table of requests with its Download button.
 */

namespace Tests\Integration;

class CoverAccountPrivacyScreenTest extends IntegrationTestCase {

	/** @var array<int, string> Files the test wrote in the exports folder. */
	private array $files = array();

	protected function setUp(): void {
		parent::setUp();

		require_once ABSPATH . 'wp-admin/includes/user.php';
	}

	protected function tearDown(): void {
		remove_filter( 'wp_privacy_personal_data_exporters', array( $this, 'broken_exporters' ) );
		remove_filter( 'wp_privacy_personal_data_erasers', array( $this, 'broken_erasers' ) );
		unset( $_COOKIE[ DILUXONE_USERS_CONFIRM_COOKIE ] );

		// no_writer() takes WordPress's own writer away; every export after
		// this test, in this process, needs it back.
		remove_action( 'wp_privacy_personal_data_export_file', array( $this, 'no_writer' ), 5 );

		if ( false === has_action( 'wp_privacy_personal_data_export_file', 'wp_privacy_generate_personal_data_export_file' ) ) {
			add_action( 'wp_privacy_personal_data_export_file', 'wp_privacy_generate_personal_data_export_file', 10 );
		}

		foreach ( $this->files as $file ) {
			wp_delete_file( $file );
		}

		foreach ( (array) glob( wp_privacy_exports_dir() . '*.zip' ) as $file ) {
			wp_delete_file( (string) $file );
		}

		parent::tearDown();
	}

	/**
	 * Files a request the way the account area does (or Tools does), with its key.
	 *
	 * @return array{0: int, 1: string}
	 */
	private function file( int $user_id, string $kind = 'remove_personal_data', bool $from_account = true ): array {
		$marker  = 'remove_personal_data' === $kind ? DILUXONE_USERS_CLOSE_KEY : DILUXONE_USERS_EXPORT_KEY;
		$request = wp_create_user_request( get_userdata( $user_id )->user_email, $kind, $from_account ? array( $marker => $user_id ) : array() );

		$this->assertIsInt( $request );

		return array( $request, wp_generate_user_request_key( $request ) );
	}

	/** Arriving at the account from the link. */
	private function arrive( int $request, string $key ): void {
		$_GET = array(
			'diluxone-users-request' => $request,
			'diluxone-users-key'     => $key,
		);
	}

	/** An export file on disk for this request, as WordPress's writer leaves it. */
	private function give_file( int $request ): void {
		wp_mkdir_p( wp_privacy_exports_dir() );

		$name = 'cover-account-' . $request . '-' . wp_generate_password( 8, false ) . '.zip';
		file_put_contents( wp_privacy_exports_dir() . $name, 'zip' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		update_post_meta( $request, '_export_file_name', $name );

		$this->files[] = wp_privacy_exports_dir() . $name;
	}

	/** The privacy tab, as the account area draws it for somebody. */
	private function tab( int $user_id ): string {
		wp_set_current_user( $user_id );

		ob_start();
		diluxone_users_section_privacy( get_userdata( $user_id ) );

		return (string) ob_get_clean();
	}

	/**
	 * The `wp_privacy_personal_data_exporters` filter: two that are no exporter.
	 *
	 * @param array<string, mixed> $exporters
	 * @return array<string, mixed>
	 */
	public function broken_exporters( array $exporters ): array {
		$exporters['cover-not-an-array'] = 'nonsense';
		$exporters['cover-says-nothing'] = array( 'callback' => static fn(): string => 'not an answer' );

		return $exporters;
	}

	/**
	 * The `wp_privacy_personal_data_erasers` filter: one that is no eraser.
	 *
	 * @param array<string, mixed> $erasers
	 * @return array<string, mixed>
	 */
	public function broken_erasers( array $erasers ): array {
		$erasers['cover-not-callable'] = array( 'callback' => 'no_such_function_anywhere' );

		return $erasers;
	}

	/* ── The link ──────────────────────────────────────────────────── */

	public function test_a_request_that_does_not_exist_is_nobodys_to_confirm(): void {
		$this->assertNull( diluxone_users_confirm_request( 999999999 ) );

		$this->arrive( 999999999, 'any-key' );
		$this->assertSame( '', diluxone_users_confirm_arrived()['state'] );

		diluxone_users_confirm_arrive(); // Nothing to do: no redirect.
		$this->addToAssertionCount( 1 );
	}

	public function test_a_request_filed_from_tools_is_left_to_wordpress_on_arrival(): void {
		[ $request, $key ] = $this->file( $this->make_user(), 'export_personal_data', false );

		$this->arrive( $request, $key );

		$this->assertSame(
			array(
				'request' => null,
				'key'     => '',
				'state'   => '',
			),
			diluxone_users_confirm_arrived()
		);
	}

	public function test_a_used_key_is_expired_and_says_so_even_signed_out(): void {
		$user              = $this->make_user();
		[ $request, $key ] = $this->file( $user );

		// A new key replaces the old one: the first link is spent.
		wp_generate_user_request_key( $request );
		$this->arrive( $request, $key );

		$arrived = diluxone_users_confirm_arrived();
		$this->assertSame( 'expired', $arrived['state'] );
		$this->assertSame( '', $arrived['key'], 'A spent key is not handed on' );

		$url = $this->expectRedirect( 'diluxone_users_confirm_arrive' );

		$this->assertSame( 'expired', $this->redirectState( $url ), 'Not sent to sign in for a link that no longer works' );
		$this->assertArrayNotHasKey( DILUXONE_USERS_CONFIRM_COOKIE, self::$cookies );
	}

	public function test_signed_out_a_good_link_on_the_account_asks_to_sign_in_first(): void {
		[ $request, $key ] = $this->file( $this->make_user() );
		$this->arrive( $request, $key );

		$url = $this->expectRedirect( 'diluxone_users_confirm_arrive' );

		$this->assertSame( 'confirm', $this->redirectState( $url ) );
		$this->assertSame( $request . ':' . $key, self::$cookies[ DILUXONE_USERS_CONFIRM_COOKIE ]['value'] ?? '' );
		$this->assertSame( 'request-pending', get_post_status( $request ) );
	}

	public function test_confirming_drops_the_cookie_that_brought_them_back(): void {
		[ $request ] = $this->file( $this->make_user(), 'export_personal_data' );

		$_COOKIE[ DILUXONE_USERS_CONFIRM_COOKIE ] = $request . ':whatever';

		diluxone_users_confirm_now( $request );

		$this->assertSame( '', self::$cookies[ DILUXONE_USERS_CONFIRM_COOKIE ]['value'] ?? null );
		$this->assertLessThan( time(), self::$cookies[ DILUXONE_USERS_CONFIRM_COOKIE ]['options']['expires'] ?? PHP_INT_MAX );
	}

	public function test_the_button_refuses_a_forged_form(): void {
		$user              = $this->make_user();
		[ $request, $key ] = $this->file( $user );

		$this->postAs(
			$user,
			array(
				'_wpnonce'                  => 'forged',
				'diluxone_users_request_id' => $request,
				'diluxone_users_key'        => $key,
			)
		);

		$this->expectDie( 'diluxone_users_confirm_close', self::EXPIRED, 403 );
		$this->assertSame( 'request-pending', get_post_status( $request ) );
		$this->assertInstanceOf( \WP_User::class, get_userdata( $user ) );
	}

	public function test_the_button_on_a_copy_request_is_expired(): void {
		$user              = $this->make_user();
		[ $request, $key ] = $this->file( $user, 'export_personal_data' );

		wp_set_current_user( $user );
		$this->postAs(
			$user,
			array(
				'_wpnonce'                  => wp_create_nonce( 'diluxone_users_confirm_close' ),
				'diluxone_users_request_id' => $request,
				'diluxone_users_key'        => $key,
			)
		);

		$this->assertSame( 'expired', $this->redirectState( $this->expectRedirect( 'diluxone_users_confirm_close' ) ), 'Only a deletion is closed from this button' );
		$this->assertSame( 'request-pending', get_post_status( $request ) );
	}

	public function test_where_the_site_carries_it_out_itself_the_button_only_confirms(): void {
		diluxone_users_update_option( 'diluxone_users_privacy_delete_when', 'admin' );

		$user              = $this->make_user();
		[ $request, $key ] = $this->file( $user );

		wp_set_current_user( $user );
		$this->postAs(
			$user,
			array(
				'_wpnonce'                  => wp_create_nonce( 'diluxone_users_confirm_close' ),
				'diluxone_users_request_id' => $request,
				'diluxone_users_key'        => $key,
			)
		);

		$url = $this->expectRedirect( 'diluxone_users_confirm_close' );

		$this->assertSame( 'confirmed', $this->redirectState( $url ) );
		$this->assertStringStartsWith( diluxone_users_account_url( 'privacy' ), $url, 'Still signed in, on the account' );
		$this->assertSame( 'request-confirmed', get_post_status( $request ) );
		$this->assertInstanceOf( \WP_User::class, get_userdata( $user ) );
	}

	/* ── Carrying it out ───────────────────────────────────────────── */

	public function test_a_request_with_no_address_is_not_carried_out(): void {
		$user        = $this->make_user();
		[ $export ]  = $this->file( $user, 'export_personal_data' );
		[ $closing ] = $this->file( $user );

		foreach ( array( $export, $closing ) as $request ) {
			wp_update_post(
				array(
					'ID'         => $request,
					'post_title' => '',
				)
			);
		}

		diluxone_users_export_on_confirm( $export );
		diluxone_users_erase_on_confirm( $closing );

		$this->assertSame( '', (string) get_post_meta( $export, '_export_file_name', true ) );
		$this->assertSame( 'request-pending', get_post_status( $export ) );
		$this->assertSame( 'request-pending', get_post_status( $closing ) );
		$this->assertInstanceOf( \WP_User::class, get_userdata( $user ) );
	}

	public function test_an_exporter_that_is_no_exporter_does_not_stop_the_copy(): void {
		$this->needs_zip();

		add_filter( 'wp_privacy_personal_data_exporters', array( $this, 'broken_exporters' ) );
		[ $request ] = $this->file( $this->make_user(), 'export_personal_data' );

		diluxone_users_export_on_confirm( $request );

		$this->assertSame( 'request-completed', get_post_status( $request ) );
		$this->assertNotSame( '', (string) get_post_meta( $request, '_export_file_name', true ) );
	}

	/** An action ahead of WordPress's writer that takes the writer away: no file is written. */
	public function no_writer(): void {
		remove_action( 'wp_privacy_personal_data_export_file', 'wp_privacy_generate_personal_data_export_file', 10 );
	}

	public function test_a_copy_whose_file_was_not_written_is_neither_mailed_nor_completed(): void {
		$this->needs_zip();

		$user        = $this->make_user();
		[ $request ] = $this->file( $user, 'export_personal_data' );
		add_action( 'wp_privacy_personal_data_export_file', array( $this, 'no_writer' ), 5 );

		try {
			diluxone_users_export_on_confirm( $request );
		} finally {
			remove_action( 'wp_privacy_personal_data_export_file', array( $this, 'no_writer' ), 5 );
		}

		$this->assertSame( '', (string) get_post_meta( $request, '_export_file_name', true ) );
		$this->assertSame( '', (string) get_post_meta( $request, '_export_data_grouped', true ), 'Nothing left behind' );
		$this->assertNotSame( 'request-completed', get_post_status( $request ), 'Not ready: there is nothing to download' );
		$this->assertSame( array(), self::$mail, 'No “your file is ready” for a file that is not there' );
	}

	public function test_an_email_about_no_request_keeps_wordpresss_words(): void {
		$this->assertSame( '', diluxone_users_data_mail_key( null, 'confirm' ) );
		$this->assertSame( '', diluxone_users_data_mail_key( get_post( 1 ), 'confirm' ) );
	}

	public function test_an_eraser_that_is_no_eraser_does_not_stop_the_closing(): void {
		add_filter( 'wp_privacy_personal_data_erasers', array( $this, 'broken_erasers' ) );
		diluxone_users_update_option( 'diluxone_users_membership', 'invite' );

		$user        = $this->make_user();
		[ $request ] = $this->file( $user );
		update_user_meta( $user, 'diluxone_users_handle', 'gone-soon' );

		diluxone_users_erase_on_confirm( $request );

		$this->assertSame( 'request-completed', get_post_status( $request ) );
	}

	public function test_a_closed_account_does_not_sign_in(): void {
		$closed = get_userdata( $this->make_user() );
		update_user_meta( $closed->ID, 'diluxone_users_closed', 1 );
		$open = get_userdata( $this->make_user() );

		$refused = apply_filters( 'authenticate', $closed, $closed->user_login, 'whatever' );

		$this->assertInstanceOf( \WP_Error::class, $refused );
		$this->assertSame( 'diluxone_users_closed', $refused->get_error_code() );
		$this->assertSame( $open, diluxone_users_closed_refuses( $open ) );
		$this->assertNull( diluxone_users_closed_refuses( null ) );
	}

	/* ── The download ──────────────────────────────────────────────── */

	public function test_the_download_refuses_a_forged_link(): void {
		$user        = $this->make_user();
		[ $request ] = $this->file( $user, 'export_personal_data' );
		$this->give_file( $request );

		wp_set_current_user( $user );
		$_GET = array(
			'request'  => $request,
			'_wpnonce' => 'forged',
		);
		$_REQUEST = $_GET;

		$this->expectDie( 'diluxone_users_data_download', self::EXPIRED, 403 );
	}

	public function test_the_download_refuses_somebody_elses_file(): void {
		$owner       = $this->make_user();
		$other       = $this->make_user();
		[ $request ] = $this->file( $owner, 'export_personal_data' );
		$this->give_file( $request );

		wp_set_current_user( $other );
		$_GET     = array(
			'request'  => $request,
			'_wpnonce' => wp_create_nonce( 'diluxone_users_data_download' ),
		);
		$_REQUEST = $_GET;

		$this->expectDie( 'diluxone_users_data_download', 'This file is not yours to download.', 403 );
	}

	public function test_the_download_refuses_a_request_that_is_not_one(): void {
		$user = $this->make_user();

		wp_set_current_user( $user );
		$_GET     = array(
			'request'  => 1,
			'_wpnonce' => wp_create_nonce( 'diluxone_users_data_download' ),
		);
		$_REQUEST = $_GET;

		$this->expectDie( 'diluxone_users_data_download', 'This file is not yours to download.', 403 );
	}

	public function test_the_download_says_when_the_file_is_gone(): void {
		$user        = $this->make_user();
		[ $request ] = $this->file( $user, 'export_personal_data' );
		update_post_meta( $request, '_export_file_name', 'cleared-away-' . $request . '.zip' );

		wp_set_current_user( $user );
		$_GET     = array(
			'request'  => $request,
			'_wpnonce' => wp_create_nonce( 'diluxone_users_data_download' ),
		);
		$_REQUEST = $_GET;

		$this->expectDie( 'diluxone_users_data_download', 'This file is no longer there. Ask for your data again.', 404 );
	}

	/* ── The tab ───────────────────────────────────────────────────── */

	public function test_the_tab_from_the_email_asks_the_last_question_and_nothing_else(): void {
		$user              = $this->make_user();
		[ $request, $key ] = $this->file( $user );
		$this->arrive( $request, $key );

		$html = $this->tab( $user );

		$this->assertStringContainsString( 'Delete your account for good?', $html );
		$this->assertStringContainsString( 'name="action" value="diluxone_users_confirm_close"', $html );
		$this->assertStringContainsString( 'name="diluxone_users_request_id" value="' . $request . '"', $html );
		$this->assertStringContainsString( 'name="diluxone_users_key" value="' . esc_attr( $key ) . '"', $html );
		$this->assertStringContainsString( 'name="_wpnonce"', $html );
		$this->assertStringContainsString( 'No, keep it', $html );
		$this->assertStringNotContainsString( 'Download your data', $html, 'The last step is the only thing on the page' );
		$this->assertSame( 'request-pending', get_post_status( $request ), 'Drawing it deletes nothing' );
	}

	public function test_the_tab_from_somebody_elses_email_does_not_ask(): void {
		[ $request, $key ] = $this->file( $this->make_user() );
		$this->arrive( $request, $key );

		$html = $this->tab( $this->make_user() );

		$this->assertStringNotContainsString( 'Delete your account for good?', $html );
		$this->assertStringNotContainsString( esc_attr( $key ), $html, 'Another person’s key is not printed' );
	}

	/** @return array<string, array{0: string, 1: string, 2: string}> */
	public function notices(): array {
		return array(
			'requested' => array( 'requested', 'ok', 'We sent you an email to confirm it.' ),
			'admin'     => array( 'admin', 'error', 'An account with admin permissions cannot ask for its own deletion.' ),
			'ready'     => array( 'ready', 'ok', 'Confirmed, and your file is ready' ),
			'confirmed' => array( 'confirmed', 'ok', 'Confirmed. The site takes it from here' ),
			'other'     => array( 'other', 'error', 'That link belongs to another account.' ),
			'expired'   => array( 'expired', 'error', 'That link no longer works' ),
			'error'     => array( 'error', 'error', 'We could not create the request.' ),
		);
	}

	/** @dataProvider notices */
	public function test_the_tab_says_how_the_last_step_went( string $state, string $tone, string $text ): void {
		$_GET = array( 'diluxone-users' => $state );

		$html = $this->tab( $this->make_user() );

		$this->assertStringContainsString( 'diluxone-users-notice--' . $tone . '">' . esc_html( $text ), $html );
	}

	public function test_the_tab_lists_the_requests_and_offers_the_file(): void {
		$user        = $this->make_user();
		[ $ready ]   = $this->file( $user, 'export_personal_data' );
		$this->give_file( $ready );
		wp_update_post(
			array(
				'ID'          => $ready,
				'post_status' => 'request-completed',
			)
		);

		// WordPress files a second one only once the first is no longer waiting.
		$this->file( $user, 'export_personal_data' );
		[ $closing ] = $this->file( $user );
		wp_update_post(
			array(
				'ID'          => $closing,
				'post_status' => 'request-failed',
			)
		);

		$html = $this->tab( $user );

		$this->assertSame( 2, substr_count( $html, '<table class="diluxone-users-table diluxone-users-requests">' ) );
		$this->assertStringContainsString( 'diluxone-users-pill--ok">Ready', $html );
		$this->assertStringContainsString( 'diluxone-users-pill--pending">Waiting for you to confirm by email', $html );
		$this->assertStringContainsString( 'diluxone-users-pill--off">Failed', $html );
		$this->assertSame( 1, substr_count( $html, '>Download</a>' ), 'Only the request with a file has the button' );
		$this->assertStringContainsString( 'action=diluxone_users_data_download', $html );
		$this->assertStringContainsString( 'request=' . $ready, $html );
		$this->assertStringNotContainsString( wp_privacy_exports_url(), $html, 'The file’s own address is never shown' );
		$this->assertStringContainsString( 'Ask for it again, up to date', $html );
		$this->assertStringContainsString( 'Ask to delete my account', $html );
		$this->assertStringNotContainsString( 'this only shows to administrators', $html );
	}

	public function test_an_administrator_cannot_close_their_own_account_and_is_warned_about_mail(): void {
		update_option( 'diluxone_users_mail_last', array( 'ok' => 0, 'time' => time(), 'error' => 'no route' ), false );

		$html = $this->tab( 1 );

		$this->assertStringContainsString( 'this only shows to administrators', $html );
		$this->assertStringContainsString( 'This account administers the site, so it cannot delete itself', $html );
		$this->assertStringNotContainsString( 'Ask to delete my account', $html );
		$this->assertStringContainsString( 'Ask for my data', $html );
	}

	public function test_the_mail_warning_is_never_shown_to_a_member(): void {
		update_option( 'diluxone_users_mail_last', array( 'ok' => 0, 'time' => time(), 'error' => 'no route' ), false );

		$this->assertStringNotContainsString( 'this only shows to administrators', $this->tab( $this->make_user() ) );
	}

	public function test_what_the_administrator_turned_off_is_not_drawn(): void {
		diluxone_users_update_option( 'diluxone_users_privacy_export', 0 );

		$html = $this->tab( $this->make_user() );

		$this->assertStringNotContainsString( 'Download your data', $html );
		$this->assertStringContainsString( 'Delete your account', $html );

		diluxone_users_update_option( 'diluxone_users_privacy_export', 1 );
		diluxone_users_update_option( 'diluxone_users_privacy_delete', 0 );

		$html = $this->tab( $this->make_user() );

		$this->assertStringContainsString( 'Download your data', $html );
		$this->assertStringNotContainsString( 'Ask to delete my account', $html );
	}

	/* ── What no other test asked ─────────────────────────────────── */

	/**
	 * The cookie that brings somebody back to a confirmation after signing
	 * in is believed only when it names a waiting request and its own key:
	 * a made-up one, a wrong key or a request that is not there sends them
	 * where they were going anyway.
	 */
	public function test_a_return_cookie_that_does_not_check_out_changes_nothing(): void {
		[ $request, $key ] = $this->file( $this->make_user() );

		foreach ( array( 'garbage', $request . ':wrongkey', '999999999:' . $key ) as $held ) {
			$_COOKIE[ DILUXONE_USERS_CONFIRM_COOKIE ] = $held;

			$this->assertSame( '/x/', apply_filters( 'diluxone_users_login_redirect', '/x/', 0 ), $held );
		}

		$_COOKIE[ DILUXONE_USERS_CONFIRM_COOKIE ] = $request . ':' . $key;
		$this->assertNotSame( '/x/', apply_filters( 'diluxone_users_login_redirect', '/x/', 0 ), 'the real one does' );
	}

	/** A file name that climbs out of the exports folder is no file to hand over. */
	public function test_a_file_name_that_climbs_out_of_the_folder_is_no_file(): void {
		[ $request ] = $this->file( $this->make_user(), 'export_personal_data' );

		foreach ( array( '../../wp-config.php', '/etc/passwd' ) as $name ) {
			update_post_meta( $request, '_export_file_name', $name );

			$this->assertSame( '', diluxone_users_data_file_path( get_post( $request ) ), $name );
		}
	}

	/** "Direct" leaves the confirmation link to WordPress, for a copy as for a closing. */
	public function test_direct_links_are_left_to_wordpress(): void {
		[ $export ]  = $this->file( $this->make_user(), 'export_personal_data' );
		[ $closing ] = $this->file( $this->make_user() );

		$this->assertNotNull( diluxone_users_confirm_request( $export ), 'the control: the account’s by default' );

		diluxone_users_update_option( 'diluxone_users_privacy_export_link', 'direct' );
		diluxone_users_update_option( 'diluxone_users_privacy_delete_link', 'direct' );

		$this->assertNull( diluxone_users_confirm_request( $export ) );
		$this->assertNull( diluxone_users_confirm_request( $closing ) );
	}

	/** The confirmation page's words change only for what was carried out here, and only once it was. */
	public function test_the_confirmation_words_change_only_for_what_was_done_here(): void {
		[ $export ]  = $this->file( $this->make_user(), 'export_personal_data' );
		[ $tools ]   = $this->file( $this->make_user(), 'export_personal_data', false );
		[ $closing ] = $this->file( $this->make_user() );

		$this->assertSame( 'WP', diluxone_users_exported_message( 'WP', $export ), 'not ready yet' );
		$this->assertSame( 'WP', diluxone_users_exported_message( 'WP', $tools ), 'filed from Tools' );

		diluxone_users_update_option( 'diluxone_users_privacy_delete_when', 'admin' );
		$this->assertSame( 'WP', diluxone_users_closed_message( 'WP', $closing ), 'the administrator carries it out' );
	}

	/** Every page of an eraser is asked for, until it says it is done. */
	public function test_every_page_of_an_eraser_is_asked_for(): void {
		$pages  = array();
		$eraser = static function ( string $email, int $page ) use ( &$pages ): array {
			$pages[] = $page;

			return array( 'items_removed' => true, 'items_retained' => false, 'messages' => array(), 'done' => 2 === $page );
		};
		$this->hook(
			'wp_privacy_personal_data_erasers',
			static fn(): array => array(
				'acme' => array(
					'eraser_friendly_name' => 'Acme',
					'callback'             => $eraser,
				),
			),
			99
		);

		[ $request ] = $this->file( $this->make_user() );
		diluxone_users_erase_on_confirm( $request );

		$this->assertSame( array( 1, 2 ), $pages );
		$this->assertSame( 'request-completed', get_post_status( $request ) );
	}

	/** Items an exporter hands over across pages are merged into one, and a datum with no group is dropped. */
	public function test_an_exporters_pages_are_merged_into_one_item(): void {
		$groups = diluxone_users_export_groups(
			array(
				array( 'group_id' => 'g', 'group_label' => 'G', 'item_id' => 'i1', 'data' => array( array( 'name' => 'A', 'value' => '1' ) ) ),
				array( 'group_id' => 'g', 'item_id' => 'i1', 'data' => array( array( 'name' => 'B', 'value' => '2' ) ) ),
				array( 'item_id' => 'lost', 'data' => array() ),
			)
		);

		$this->assertSame( array( 'g' ), array_keys( $groups ) );
		// Merged the way WordPress merges an item's pages: what came later first.
		$this->assertSame( array( 'B', 'A' ), array_column( $groups['g']['items']['i1'], 'name' ) );
	}

	/** The account-closed mail is the plugin's for a request from the account, and WordPress's for one from Tools. */
	public function test_the_account_closed_mail_is_the_plugins_for_the_accounts_request(): void {
		[ $ours ]  = $this->file( $this->make_user() );
		[ $tools ] = $this->file( $this->make_user(), 'remove_personal_data', false );

		$this->assertNotSame( 'WP', apply_filters( 'user_erasure_fulfillment_email_subject', 'WP', 'Site', array( 'request' => wp_get_user_request( $ours ) ) ) );
		$this->assertSame( 'WP', apply_filters( 'user_erasure_fulfillment_email_subject', 'WP', 'Site', array( 'request' => wp_get_user_request( $tools ) ) ) );
	}
}
