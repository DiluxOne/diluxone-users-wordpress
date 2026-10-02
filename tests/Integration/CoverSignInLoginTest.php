<?php
/**
 * The sign-in by e-mail link: every refusal of the request and of the link,
 * then the frame the form is drawn in, in each of its shapes.
 *
 * The request always ends on the same screen, so what each refusal is
 * checked by is what did not happen: no mail, no token, no account.
 */

namespace Tests\Integration;

class CoverSignInLoginTest extends IntegrationTestCase {

	/** Addresses are the test's own: accounts outlive nothing, but a throttle key is per address. */
	private string $run = '';

	protected function setUp(): void {
		parent::setUp();

		$this->run = strtolower( wp_generate_password( 8, false ) );
	}

	private function email( string $name ): string {
		return $name . '-' . $this->run . '@example.test';
	}

	/** Asks for a link as this text, signed out, with the form's nonce, and returns where it went. */
	private function ask( string $typed ): string {
		$this->postAs(
			0,
			array(
				'diluxone_users_nonce' => wp_create_nonce( 'diluxone_users_login' ),
				'diluxone_users_email' => $typed,
			)
		);

		return $this->expectRedirect( 'diluxone_users_login_request' );
	}

	/** An image in the media library, as the admin's picker stores it: by its ID. */
	private function image( string $name ): int {
		$id = (int) wp_insert_attachment(
			array(
				'post_title'     => $name,
				'post_mime_type' => 'image/jpeg',
				'post_status'    => 'inherit',
			),
			false,
			0,
			true
		);
		update_post_meta( $id, '_wp_attached_file', '2026/10/' . $name . '.jpg' );

		return $id;
	}

	/** @param array<string, mixed> $options */
	private function set( array $options ): void {
		foreach ( $options as $key => $value ) {
			diluxone_users_update_option( $key, $value );
		}
	}

	private function frame(): string {
		ob_start();
		diluxone_users_login_frame_open();
		echo 'FORM';
		diluxone_users_login_frame_close();

		return (string) ob_get_clean();
	}

	/* ── The request's refusals ──────────────────────────────────────── */

	public function test_a_request_without_the_form_nonce_mails_nothing(): void {
		$user = $this->make_user();
		$this->postAs( 0, array( 'diluxone_users_nonce' => 'forged', 'diluxone_users_email' => get_userdata( $user )->user_email ) );

		$url = $this->expectRedirect( 'diluxone_users_login_request' );

		$this->assertSame( 'error', $this->redirectState( $url ) );
		$this->assertSame( array(), self::$mail );
		$this->assertSame( '', get_user_meta( $user, DILUXONE_USERS_META_HASH, true ) );
	}

	public function test_a_site_that_signs_in_with_a_password_only_mails_no_link(): void {
		$user = $this->make_user();
		$this->set( array( 'diluxone_users_login_method' => 'password' ) );

		$url = $this->ask( get_userdata( $user )->user_email );

		$this->assertSame( 'error', $this->redirectState( $url ) );
		$this->assertSame( array(), self::$mail );
	}

	public function test_something_that_is_not_an_address_is_told_so(): void {
		$url = $this->ask( 'not an address' );

		$this->assertSame( 'email', $this->redirectState( $url ) );
		$this->assertSame( array(), self::$mail );
		$this->assertArrayNotHasKey( 'diluxone_users_sent', self::$cookies );
	}

	public function test_a_machine_past_its_count_is_answered_the_same_and_mailed_nothing(): void {
		$user = $this->make_user();

		for ( $i = 0; $i < diluxone_users_login_burst(); $i++ ) {
			diluxone_users_ip_burst( 'link', diluxone_users_login_burst() );
		}

		$url = $this->ask( get_userdata( $user )->user_email );

		$this->assertSame( 'sent', $this->redirectState( $url ) );
		$this->assertSame( array(), self::$mail );
		$this->assertSame( '', get_user_meta( $user, DILUXONE_USERS_META_HASH, true ) );
	}

	public function test_the_same_inbox_is_not_written_to_twice_within_the_wait(): void {
		$user  = $this->make_user();
		$email = get_userdata( $user )->user_email;

		$this->assertSame( 'sent', $this->redirectState( $this->ask( $email ) ) );
		$first = (string) get_user_meta( $user, DILUXONE_USERS_META_HASH, true );
		$this->assertSame( 'sent', $this->redirectState( $this->ask( $email ) ) );

		$this->assertCount( 1, self::$mail );
		$this->assertSame( $first, get_user_meta( $user, DILUXONE_USERS_META_HASH, true ), 'the first link still stands' );
		$this->assertSame( $email, self::$mail[0]['to'] );
	}

	public function test_a_new_address_past_the_registration_count_creates_nothing(): void {
		$this->set( array( 'diluxone_users_login_register' => 1 ) );

		for ( $i = 0; $i < diluxone_users_register_burst(); $i++ ) {
			diluxone_users_register_allowed();
		}

		$url = $this->ask( $this->email( 'new' ) );

		$this->assertSame( 'sent', $this->redirectState( $url ) );
		$this->assertFalse( email_exists( $this->email( 'new' ) ) );
		$this->assertSame( array(), self::$mail );
	}

	public function test_the_link_mailed_is_the_one_that_signs_in_once(): void {
		$user = $this->make_user();

		$this->ask( get_userdata( $user )->user_email );

		$this->assertCount( 1, self::$mail );
		preg_match( '/diluxone_users_token=([A-Za-z0-9]+)/', (string) self::$mail[0]['message'], $m );
		$this->assertNotEmpty( $m[1] ?? '' );
		$this->assertStringNotContainsString( $m[1], (string) get_user_meta( $user, DILUXONE_USERS_META_HASH, true ), 'only the hash is stored' );

		$_GET = array(
			'diluxone_users_login' => (string) $user,
			'diluxone_users_token' => $m[1],
		);
		$url  = $this->expectRedirect( 'diluxone_users_login_consume' );

		$this->assertSame( home_url( '/' ), $url );
		$this->assertSame( $user, get_current_user_id() );

		// The same link, again: spent.
		wp_set_current_user( 0 );
		$again = $this->expectRedirect( 'diluxone_users_login_consume' );
		$this->assertSame( 'expired', $this->redirectState( $again ) );
		$this->assertSame( 0, get_current_user_id() );
	}

	/* ── The link's refusals ─────────────────────────────────────────── */

	/**
	 * Two requests with the same link both found it valid; only the one that
	 * deletes it gets in. Played here by deleting nothing — the other request
	 * got there first — and the link is "expired" for this one.
	 */
	public function test_a_link_another_request_spent_first_signs_nobody_in(): void {
		$user  = $this->make_user();
		$token = diluxone_users_token_create( $user );

		$this->hook( 'delete_user_metadata', static fn( $delete, $id, $key ) => DILUXONE_USERS_META_HASH === $key ? false : $delete, 10, 3 );

		$_GET = array(
			'diluxone_users_login' => (string) $user,
			'diluxone_users_token' => $token,
		);
		$url  = $this->expectRedirect( 'diluxone_users_login_consume' );

		$this->assertSame( 'expired', $this->redirectState( $url ) );
		$this->assertSame( 0, get_current_user_id() );
	}

	/** A link lasts what the setting says, and the setting is held between a minute and a day. */
	public function test_a_link_lasts_what_the_setting_says(): void {
		$user = $this->make_user();

		foreach ( array( 30 => 30, 0 => 1, 99999 => 1440 ) as $set => $minutes ) {
			diluxone_users_update_option( 'diluxone_users_login_expiry', $set );
			diluxone_users_token_create( $user );

			$this->assertEqualsWithDelta( time() + $minutes * MINUTE_IN_SECONDS, (int) get_user_meta( $user, DILUXONE_USERS_META_EXPIRES, true ), 2, $set . ' minutes asked' );
		}
	}

	/**
	 * A link mailed before the site went password-only does not sign in
	 * after it. What an administrator turns off disappears: the e-mail link
	 * is no longer a way in, and one already in somebody's inbox is not a
	 * way around that.
	 */
	public function test_a_link_mailed_before_the_link_was_turned_off_signs_nobody_in(): void {
		$user  = $this->make_user();
		$token = diluxone_users_token_create( $user );

		diluxone_users_update_option( 'diluxone_users_login_method', 'password' );
		$this->assertFalse( diluxone_users_login_has_link() );

		$_GET = array(
			'diluxone_users_login' => (string) $user,
			'diluxone_users_token' => $token,
		);
		$url  = $this->expectRedirect( 'diluxone_users_login_consume' );

		$this->assertSame( 'expired', $this->redirectState( $url ) );
		$this->assertSame( 0, get_current_user_id() );

		// Turned back on, the same link — still in its time, never used — works.
		diluxone_users_update_option( 'diluxone_users_login_method', 'both' );
		$this->expectRedirect( 'diluxone_users_login_consume' );
		$this->assertSame( $user, get_current_user_id() );
	}

	/** @return array<string, array{0: string}> */
	public static function bad_links(): array {
		return array(
			'no user'           => array( 'no-user' ),
			'no token'          => array( 'no-token' ),
			'a forged token'    => array( 'forged' ),
			'another account\'s token' => array( 'wrong-user' ),
			'an expired link'   => array( 'expired' ),
		);
	}

	/** @dataProvider bad_links */
	public function test_a_link_that_does_not_check_out_signs_nobody_in( string $case ): void {
		$user  = $this->make_user();
		$other = $this->make_user();
		$token = diluxone_users_token_create( $user );
		$id    = $user;

		switch ( $case ) {
			case 'no-user':
				$id = 0;
				break;
			case 'no-token':
				$token = '';
				break;
			case 'forged':
				$token = wp_generate_password( 40, false, false );
				break;
			case 'wrong-user':
				$id = $other;
				break;
			case 'expired':
				update_user_meta( $user, DILUXONE_USERS_META_EXPIRES, time() - 1 );
				break;
		}

		$_GET = array(
			'diluxone_users_login' => (string) $id,
			'diluxone_users_token' => $token,
		);

		$url = $this->expectRedirect( 'diluxone_users_login_consume' );

		$this->assertSame( 'expired', $this->redirectState( $url ) );
		$this->assertSame( 0, get_current_user_id() );
	}

	public function test_a_page_without_a_link_is_left_alone(): void {
		$_GET = array( 'diluxone_users_login' => '1' );

		diluxone_users_login_consume();

		$this->assertSame( 0, get_current_user_id() );
	}

	/* ── How accounts come to exist ──────────────────────────────────── */

	public function test_the_registration_mode_reads_both_doors(): void {
		$this->set(
			array(
				'diluxone_users_login_register' => 1,
				'diluxone_users_register_form'  => 1,
			)
		);
		$this->assertSame( 'both', diluxone_users_register_mode() );

		$this->set( array( 'diluxone_users_login_register' => 0 ) );
		$this->assertSame( 'form', diluxone_users_register_mode() );

		$this->set( array( 'diluxone_users_register_form' => 0 ) );
		$this->assertSame( 'closed', diluxone_users_register_mode() );

		$this->set( array( 'diluxone_users_login_register' => 1 ) );
		$this->assertSame( 'login', diluxone_users_register_mode() );

		// The link creates accounts only while the link is a way in.
		$this->set( array( 'diluxone_users_login_method' => 'password' ) );
		$this->assertSame( 'closed', diluxone_users_register_mode() );
	}

	public function test_an_account_wordpress_refuses_is_no_account(): void {
		$user = $this->make_user();

		// The address is free as an e-mail and taken as a login.
		$login = get_userdata( $user )->user_login . '@example.test';
		wp_update_user( array( 'ID' => $user, 'user_email' => $this->email( 'moved' ) ) );
		global $wpdb;
		$wpdb->update( $wpdb->users, array( 'user_login' => $login ), array( 'ID' => $user ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		clean_user_cache( $user );

		$this->assertSame( 0, diluxone_users_create_account( $login ) );
	}

	public function test_an_account_made_from_an_address_shows_no_address(): void {
		$id = diluxone_users_create_account( 'juan.perez_88-' . $this->run . '@example.test' );

		$this->assertGreaterThan( 0, $id );
		$this->assertSame( 'juan perez 88 ' . $this->run, get_userdata( $id )->display_name );
		$this->assertStringNotContainsString( 'example', get_userdata( $id )->user_nicename );
	}

	/* ── The frame around the form ───────────────────────────────────── */

	public function test_the_plain_shape_draws_no_frame(): void {
		$this->assertSame( 'FORM', $this->frame() );

		// And a shape nobody knows is read as plain.
		$this->set( array( 'diluxone_users_login_template' => 'mystery' ) );
		$this->assertSame( 'plain', diluxone_users_login_template() );
		$this->assertSame( 'FORM', $this->frame() );
	}

	public function test_a_card_is_a_box_with_no_picture_whatever_is_chosen(): void {
		$this->set(
			array(
				'diluxone_users_login_template' => 'card',
				'diluxone_users_login_image'    => $this->image( 'card' ),
			)
		);

		$html = $this->frame();

		$this->assertStringStartsWith( '<div class="diluxone-users-login-page"><div class="diluxone-users-login-frame diluxone-users-login-frame--card diluxone-users-login-frame--left">', $html );
		$this->assertStringNotContainsString( 'style=', $html );
		$this->assertStringEndsWith( '<div class="diluxone-users-login-frame__box">FORM</div></div></div>', $html );
	}

	public function test_a_backdrop_carries_its_picture_as_a_property_on_the_side_chosen(): void {
		$this->set(
			array(
				'diluxone_users_login_template' => 'backdrop',
				'diluxone_users_login_side'     => 'right',
				'diluxone_users_login_image'    => $this->image( 'backdrop' ),
			)
		);

		$html = $this->frame();

		$this->assertStringContainsString( 'diluxone-users-login-frame--right', $html );
		$this->assertMatchesRegularExpression( '#style="--diluxone-users-login-image: url\([^)]*backdrop\.jpg\)"#', $html );
		$this->assertStringNotContainsString( '__picture', $html );
	}

	public function test_a_split_with_nothing_written_is_a_picture_hidden_from_screen_readers(): void {
		$this->set( array( 'diluxone_users_login_template' => 'split' ) );

		$html = $this->frame();

		$this->assertStringContainsString( '<div class="diluxone-users-login-frame__picture" aria-hidden="true"></div>', $html );
		$this->assertStringNotContainsString( 'style=', $html );
	}

	public function test_a_split_with_words_draws_every_piece_escaped_over_the_picture(): void {
		$this->set(
			array(
				'diluxone_users_login_template'     => 'split',
				'diluxone_users_login_image'        => $this->image( 'split' ),
				'diluxone_users_login_panel_logo'   => $this->image( 'mark' ),
				'diluxone_users_login_panel_title'  => "First line\n\n  Second <em>line</em>  \r\n",
				'diluxone_users_login_panel_text'   => '  Some text & more ',
				'diluxone_users_login_panel_points' => "One\nTwo",
				'diluxone_users_login_panel_foot'   => 'The foot',
			)
		);

		$html = $this->frame();

		$this->assertStringContainsString( 'diluxone-users-login-frame__picture diluxone-users-login-frame__picture--words diluxone-users-login-frame__picture--over">', $html );
		$this->assertMatchesRegularExpression( '#<p class="diluxone-users-login-frame__mark"><img src="[^"]*mark\.jpg" alt="[^"]*"></p>#', $html );
		$this->assertStringContainsString( '<h2 class="diluxone-users-login-frame__title"><span>First line</span><span>Second &lt;em&gt;line&lt;/em&gt;</span></h2>', $html );
		$this->assertStringContainsString( '<p class="diluxone-users-login-frame__text">Some text &amp; more</p>', $html );
		$this->assertStringContainsString( '<ul class="diluxone-users-login-frame__points"><li>One</li><li>Two</li></ul>', $html );
		$this->assertStringContainsString( '<p class="diluxone-users-login-frame__foot">The foot</p>', $html );
		$this->assertStringNotContainsString( 'aria-hidden', $html );
	}

	public function test_a_split_with_words_and_no_picture_keeps_the_empty_rows(): void {
		$this->set(
			array(
				'diluxone_users_login_template'    => 'split',
				'diluxone_users_login_panel_text'  => 'Only text',
			)
		);

		$html = $this->frame();

		$this->assertStringContainsString( 'diluxone-users-login-frame__picture diluxone-users-login-frame__picture--words">', $html );
		$this->assertStringNotContainsString( '__picture--over', $html );
		// The mark's row and the foot's row are held by empty spans.
		$this->assertSame( 2, substr_count( $html, '<span aria-hidden="true"></span>' ) );
		$this->assertStringNotContainsString( '__title', $html );
		$this->assertStringNotContainsString( '__points', $html );
	}

	public function test_the_site_s_mark_is_drawn_above_the_form_only_when_chosen(): void {
		ob_start();
		diluxone_users_login_logo();
		$this->assertSame( '', ob_get_clean() );

		$this->set( array( 'diluxone_users_login_logo' => $this->image( 'logo' ) ) );

		ob_start();
		diluxone_users_login_logo();
		$html = (string) ob_get_clean();

		$this->assertMatchesRegularExpression( '#^<p class="diluxone-users-login__logo"><img src="[^"]*logo\.jpg" alt="' . preg_quote( esc_attr( get_bloginfo( 'name' ) ), '#' ) . '"></p>$#', $html );
	}

	public function test_an_icon_nobody_drew_is_nothing(): void {
		$this->assertSame( '', diluxone_users_icon( 'unicorn' ) );
		$this->assertStringContainsString( 'width="40"', diluxone_users_icon( 'mail' ) );
		$this->assertStringContainsString( 'width="20"', diluxone_users_icon( 'key' ) );
	}

	/* ── What no other test asked ─────────────────────────────────── */

	/** The wait between two links to one inbox is the setting's, and never nothing. */
	public function test_the_wait_between_links_is_the_settings(): void {
		$user  = $this->make_user();
		$email = get_userdata( $user )->user_email;
		$key   = 'diluxone_users_throttle_' . md5( strtolower( $email ) );

		diluxone_users_update_option( 'diluxone_users_login_throttle', 300 );
		$this->ask( $email );

		$timeout = is_multisite() ? (int) get_site_option( '_site_transient_timeout_' . $key ) : (int) get_option( '_site_transient_timeout_' . $key );
		$this->assertEqualsWithDelta( time() + 300, $timeout, 3 );

		delete_site_transient( $key );
		diluxone_users_update_option( 'diluxone_users_login_throttle', 0 );
		self::forget_transients();
		$this->ask( $email );

		$timeout = is_multisite() ? (int) get_site_option( '_site_transient_timeout_' . $key ) : (int) get_option( '_site_transient_timeout_' . $key );
		$this->assertEqualsWithDelta( time() + 1, $timeout, 3, 'nought is one second, never no wait at all' );
	}

	/** Where the link lands follows the site's redirect filter, which is told whose sign-in it is. */
	public function test_the_link_lands_where_the_site_says(): void {
		$user = $this->make_user();
		$this->hook( 'diluxone_users_login_redirect', static fn( $to, $id ) => home_url( '/welcome/?u=' . $id ), 10, 2 );

		$_GET = array(
			'diluxone_users_login' => (string) $user,
			'diluxone_users_token' => diluxone_users_token_create( $user ),
		);

		$this->assertSame( home_url( '/welcome/?u=' . $user ), $this->expectRedirect( 'diluxone_users_login_consume' ) );
	}

	/** A way in nobody chose is both ways. */
	public function test_a_way_in_nobody_chose_is_both(): void {
		diluxone_users_update_option( 'diluxone_users_login_method', 'nonsense' );

		$this->assertTrue( diluxone_users_login_has_link() );
		$this->assertTrue( diluxone_users_login_has_password() );
	}

	/** The account a link makes has the role the site gives, and is a member of the site it was asked on. */
	public function test_the_account_a_link_makes_has_the_sites_role(): void {
		diluxone_users_update_option( 'diluxone_users_login_role', 'contributor' );
		diluxone_users_update_option( 'diluxone_users_login_register', 1 );
		$email = $this->email( 'newbylink' );

		$this->ask( $email );

		$made = get_user_by( 'email', $email );
		$this->assertInstanceOf( \WP_User::class, $made );
		$this->assertSame( array( 'contributor' ), array_values( $made->roles ) );
		$this->assertTrue( is_user_member_of_blog( $made->ID, get_current_blog_id() ) );
	}

	/** The password form carries where the site sends people afterwards. */
	public function test_the_password_form_carries_the_way_on(): void {
		$this->hook( 'diluxone_users_login_redirect', static fn(): string => home_url( '/after/' ) );

		ob_start();
		diluxone_users_way_password();
		$html = (string) ob_get_clean();

		$this->assertStringContainsString( 'name="redirect_to" value="' . esc_attr( home_url( '/after/' ) ) . '"', $html );
	}
}
