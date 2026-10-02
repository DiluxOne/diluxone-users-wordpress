<?php
/**
 * The public name: its rules, its save, its form and the check as you type.
 *
 * Every refusal is told in words and one at a time — empty, spaces when the
 * site says no spaces, an e-mail, too short, too long, a name the site keeps
 * for itself (its own list and the administrator's), a name somebody else
 * already answers to by nicename or by login — and a name changed recently
 * waits out the cooldown. The form's handler sends somebody signed out to
 * sign in, refuses a forged nonce, and carries a refusal to the next page on
 * the server, never in the address. The check as you type answers only with
 * a nonce and a session, so it is no way of listing who is on the site.
 */

namespace Tests\Integration;

class CoverFieldsHandleTest extends IntegrationTestCase {

	protected function tearDown(): void {
		wp_dequeue_script( 'diluxone-users-handle' );
		wp_deregister_script( 'diluxone-users-handle' );

		parent::tearDown();
	}

	/** The account screen's switch for public names. */
	private function names_on(): void {
		diluxone_users_update_option( 'diluxone_users_handle_enabled', 1 );
	}

	/**
	 * Runs the AJAX check and returns the JSON it answered with.
	 *
	 * @return array<string, mixed>
	 */
	private function check(): array {
		ob_start();

		try {
			diluxone_users_handle_check();
		} catch ( \WPAjaxDieContinueException $e ) {
			unset( $e );
		}

		return (array) json_decode( (string) ob_get_clean(), true );
	}

	/** The reason a name is refused, or '' when it is fine. */
	private function refusal( string $handle, int $user_id = 0 ): string {
		$result = diluxone_users_handle_validate( $handle, $user_id );

		return is_wp_error( $result ) ? $result->get_error_code() : '';
	}

	/* ── The rules ───────────────────────────────────────────────────── */

	public function test_every_rule_refuses_in_its_own_words(): void {
		$user = $this->make_user();

		$this->assertSame( 'diluxone_users_handle_empty', $this->refusal( '  !!! ', $user ) );
		$this->assertSame( 'diluxone_users_handle_email', $this->refusal( 'ana@example.test', $user ) );
		$this->assertSame( 'diluxone_users_handle_short', $this->refusal( 'ab', $user ) );
		$this->assertSame( 'diluxone_users_handle_long', $this->refusal( str_repeat( 'a', 31 ), $user ) );
		$this->assertSame( 'diluxone_users_handle_reserved', $this->refusal( 'Admin', $user ) );
		$this->assertSame( 'diluxone_users_handle_reserved', $this->refusal( 'wp-login', $user ) );
		$this->assertSame( '', $this->refusal( 'tienda', $user ) );

		$short = diluxone_users_handle_validate( 'ab', $user );
		$this->assertSame( 'It is too short: at least 3 characters.', $short->get_error_message() );

		// The administrator's own list, however it was typed.
		diluxone_users_update_option( 'diluxone_users_handle_reserved', "Tienda, staff\nprensa" );
		$this->assertSame( 'diluxone_users_handle_reserved', $this->refusal( 'tienda', $user ) );
		$this->assertContains( 'prensa', diluxone_users_handle_reserved() );
		$this->assertContains( 'admin', diluxone_users_handle_reserved() );

		// Limits that make no sense are made to.
		diluxone_users_update_option( 'diluxone_users_handle_min', 0 );
		diluxone_users_update_option( 'diluxone_users_handle_max', 0 );
		$this->assertSame( 'diluxone_users_handle_long', $this->refusal( 'ab', $user ), 'The maximum is never under the minimum' );
		$this->assertSame( '', $this->refusal( 'a', $user ) );
	}

	public function test_a_limit_above_what_wordpress_stores_is_held_to_fifty(): void {
		$user = $this->make_user();
		diluxone_users_update_option( 'diluxone_users_handle_max', 80 );

		$this->assertSame( 'diluxone_users_handle_long', $this->refusal( str_repeat( 'a', 51 ), $user ), 'The check never calls free what the save refuses' );
		$this->assertSame( str_repeat( 'a', 50 ), diluxone_users_handle_validate( str_repeat( 'a', 50 ), $user ) );
		$this->assertTrue( diluxone_users_handle_save( $user, str_repeat( 'a', 50 ) ) );

		// A minimum above it as well: the longest WordPress stores still fits.
		diluxone_users_update_option( 'diluxone_users_handle_min', 60 );
		$this->assertSame( '', $this->refusal( str_repeat( 'b', 50 ), $user ) );
	}

	public function test_a_name_wordpress_refuses_on_save_is_not_recorded(): void {
		$user  = $this->make_user();
		$nice  = get_userdata( $user )->user_nicename;
		$force = static fn(): string => str_repeat( 'x', 60 );
		add_filter( 'pre_user_nicename', $force );

		try {
			$refused = diluxone_users_handle_save( $user, 'ana-lopez' );
		} finally {
			remove_filter( 'pre_user_nicename', $force );
		}

		$this->assertSame( 'user_nicename_too_long', $refused->get_error_code() );
		$this->assertSame( '', diluxone_users_handle( $user ) );
		$this->assertSame( 0, diluxone_users_handle_changed( $user ), 'No cooldown for a change that did not happen' );
		$this->assertSame( $nice, get_userdata( $user )->user_nicename );
	}

	public function test_spaces_become_dashes_unless_the_site_says_no(): void {
		$user = $this->make_user();

		$this->assertSame( 'ana-perez', diluxone_users_handle_validate( 'Ana Pérez', $user ) );

		diluxone_users_update_option( 'diluxone_users_handle_spaces', 'reject' );
		$this->assertSame( 'diluxone_users_handle_spaces', $this->refusal( 'Ana Pérez', $user ) );
		$this->assertSame( 'ana-perez', diluxone_users_handle_validate( 'ana-perez', $user ) );
	}

	/**
	 * Accents are dropped, always. There was a setting that promised to keep
	 * them, and WordPress cannot: sanitize_title() strips them when it saves,
	 * and the address is user_nicename, which wp_insert_user() keeps to ASCII.
	 * The preview showed "josé" and "jose" was saved, so the setting went.
	 */
	public function test_accents_are_dropped_and_what_is_shown_is_what_is_saved(): void {
		$this->assertSame( 'jose', diluxone_users_handle_clean( 'José' ) );
		$this->assertSame( 'jose-nandu', diluxone_users_handle_clean( 'José Ñandú' ) );
		$this->assertArrayNotHasKey( 'diluxone_users_handle_charset', diluxone_users_option_defaults() );
	}

	public function test_a_name_somebody_else_answers_to_is_taken_and_ones_own_is_not(): void {
		$user  = $this->make_user();
		$other = $this->make_user();
		$login = get_userdata( $other )->user_login;
		$nice  = get_userdata( $other )->user_nicename;

		$this->assertTrue( diluxone_users_handle_taken( $login, $user ), 'By login' );
		$this->assertTrue( diluxone_users_handle_taken( $nice, $user ), 'By nicename' );
		$this->assertFalse( diluxone_users_handle_taken( $nice, $other ), 'Their own' );
		$this->assertSame( 'diluxone_users_handle_taken', $this->refusal( $nice, $user ) );

		$this->assertSame( $other, diluxone_users_handle_user( $nice ) );
		$this->assertSame( 0, diluxone_users_handle_user( '!!!' ) );
		$this->assertSame( 0, diluxone_users_handle_user( 'nobody-has-this-one' ) );
	}

	/* ── The save and the cooldown ───────────────────────────────────── */

	public function test_a_saved_name_is_the_address_and_waits_out_the_cooldown(): void {
		$user = $this->make_user();

		$this->assertSame( get_userdata( $user )->user_nicename, diluxone_users_public_handle( $user ), 'Before choosing, WordPress’s' );
		$this->assertSame( '', diluxone_users_public_handle( 999999 ) );
		$this->assertTrue( diluxone_users_handle_can_change( $user ) );
		$this->assertSame( 0, diluxone_users_handle_next_change( $user ) );

		$this->assertTrue( diluxone_users_handle_save( $user, 'Ana Lopez' ) );

		$account = get_userdata( $user );
		$this->assertSame( 'ana-lopez', $account->user_nicename );
		$this->assertSame( 'ana-lopez', get_user_meta( $user, 'nickname', true ) );
		$this->assertSame( 'ana-lopez', diluxone_users_handle( $user ) );
		$this->assertSame( 'ana-lopez', diluxone_users_public_handle( $user ) );
		$this->assertEqualsWithDelta( time(), diluxone_users_handle_changed( $user ), 5 );

		$this->assertTrue( diluxone_users_handle_save( $user, 'ana-lopez' ), 'The same one again changes nothing' );

		$this->assertFalse( diluxone_users_handle_can_change( $user ) );
		$this->assertSame( diluxone_users_handle_changed( $user ) + 30 * DAY_IN_SECONDS, diluxone_users_handle_next_change( $user ) );

		$wait = diluxone_users_handle_save( $user, 'another-one' );
		$this->assertSame( 'diluxone_users_handle_cooldown', $wait->get_error_code() );
		$this->assertStringContainsString( wp_date( 'j M Y', diluxone_users_handle_next_change( $user ) ), $wait->get_error_message() );

		// Once the wait is over, the rules decide.
		update_user_meta( $user, 'diluxone_users_handle_changed', time() - 31 * DAY_IN_SECONDS );
		$this->assertSame( 'diluxone_users_handle_short', diluxone_users_handle_save( $user, 'ab' )->get_error_code() );
		$this->assertSame( 'ana-lopez', diluxone_users_handle( $user ) );

		// No cooldown at all.
		update_user_meta( $user, 'diluxone_users_handle_changed', time() );
		diluxone_users_update_option( 'diluxone_users_handle_cooldown', 0 );
		$this->assertTrue( diluxone_users_handle_can_change( $user ) );
	}

	/* ── Signing in with it ──────────────────────────────────────────── */

	public function test_the_sign_in_box_resolves_a_name_only_when_both_switches_are_on(): void {
		$user  = $this->make_user();
		$email = get_userdata( $user )->user_email;
		diluxone_users_handle_save( $user, 'ana-lopez' );

		$this->assertSame( 'ana-lopez', diluxone_users_handle_login_email( 'ana-lopez' ), 'Off' );

		diluxone_users_update_option( 'diluxone_users_handle_login', 1 );
		$this->assertFalse( diluxone_users_handle_login_on(), 'Names themselves are off' );
		$this->assertSame( 'ana-lopez', diluxone_users_handle_login_email( 'ana-lopez' ) );

		$this->names_on();
		$this->assertTrue( diluxone_users_handle_login_on() );
		$this->assertSame( $email, diluxone_users_handle_login_email( 'ana-lopez' ) );
		$this->assertSame( 'typed@example.test', diluxone_users_handle_login_email( 'typed@example.test' ) );
		$this->assertSame( 'nobody-here', diluxone_users_handle_login_email( 'nobody-here' ) );
	}

	/* ── The field and the form ──────────────────────────────────────── */

	public function test_the_field_is_drawn_only_for_a_person_on_a_site_with_names(): void {
		$user = $this->make_user();

		$this->assertSame( '', diluxone_users_handle_field( $user ), 'Names are off' );

		$this->names_on();
		$this->assertSame( '', diluxone_users_handle_field( 0 ), 'Nobody' );
		$this->assertSame( '', diluxone_users_handle_field(), 'Nobody signed in' );

		$html = diluxone_users_handle_field( $user );

		$this->assertStringContainsString( 'name="diluxone_users_handle" value="' . esc_attr( get_userdata( $user )->user_nicename ) . '"', $html );
		$this->assertStringContainsString( 'minlength="3"', $html );
		$this->assertStringContainsString( 'maxlength="30"', $html );
		$this->assertStringContainsString( 'data-diluxone-users-handle-check', $html );
		$this->assertStringContainsString( 'Spaces turn into dashes', $html );
		$this->assertTrue( wp_script_is( 'diluxone-users-handle', 'enqueued' ) );

		diluxone_users_handle_enqueue();
		$this->assertTrue( wp_script_is( 'diluxone-users-handle', 'enqueued' ), 'Twice is still once' );

		diluxone_users_update_option( 'diluxone_users_handle_spaces', 'reject' );
		$this->assertStringContainsString( 'No spaces: this goes in a web address.', diluxone_users_handle_field( $user ) );

		// Changed recently: locked, and it says until when.
		update_user_meta( $user, 'diluxone_users_handle_changed', time() );
		$locked = diluxone_users_handle_field( $user );
		$this->assertStringContainsString( "disabled='disabled'", $locked );
		$this->assertStringNotContainsString( 'data-diluxone-users-handle-check', $locked );
		$this->assertStringContainsString( wp_date( 'j M Y', diluxone_users_handle_next_change( $user ) ), $locked );
	}

	public function test_the_form_shows_the_refusal_left_for_it_once_and_its_button_only_when_it_can_save(): void {
		$this->assertSame( '', diluxone_users_shortcode_handle(), 'Signed out' );

		$user = $this->make_user();
		wp_set_current_user( $user );
		$this->assertSame( '', diluxone_users_shortcode_handle(), 'Names are off' );

		$this->names_on();
		diluxone_users_flash_set( $user, 'handle', 'Somebody already has that one.' );

		$html = diluxone_users_shortcode_handle();

		$this->assertStringContainsString( 'diluxone-users-notice--error">Somebody already has that one.', $html );
		$this->assertStringContainsString( 'name="action" value="diluxone_users_handle"', $html );
		$this->assertStringContainsString( 'value="' . wp_create_nonce( 'diluxone_users_handle' ) . '"', $html );
		$this->assertStringContainsString( 'type="submit"', $html );

		$again = diluxone_users_shortcode_handle();
		$this->assertStringNotContainsString( 'diluxone-users-notice--error', $again, 'Read once' );

		diluxone_users_handle_save( $user, 'ana-lopez' );
		$locked = diluxone_users_shortcode_handle();
		$this->assertStringContainsString( 'value="ana-lopez"', $locked );
		$this->assertStringNotContainsString( 'type="submit"', $locked );
	}

	/* ── The form's handler ──────────────────────────────────────────── */

	public function test_the_handler_sends_somebody_signed_out_to_sign_in(): void {
		$this->postAs( 0, array( 'diluxone_users_handle' => 'x' ) );

		$this->assertSame( diluxone_users_login_url(), $this->expectRedirect( 'diluxone_users_handle_submit' ) );
	}

	public function test_the_handler_refuses_a_forged_nonce(): void {
		$user = $this->make_user();
		$this->postAs(
			$user,
			array(
				'_wpnonce'              => 'forged',
				'diluxone_users_handle' => 'ana-lopez',
			)
		);

		$this->expectDie( 'diluxone_users_handle_submit', self::EXPIRED, 403 );
		$this->assertSame( '', diluxone_users_handle( $user ) );
	}

	public function test_the_handler_saves_or_carries_the_refusal_on_the_server(): void {
		$this->names_on();
		$user = $this->make_user();
		wp_set_current_user( $user );
		$this->postAs(
			$user,
			array(
				'_wpnonce'              => wp_create_nonce( 'diluxone_users_handle' ),
				'diluxone_users_handle' => 'ab',
			)
		);

		$url = $this->expectRedirect( 'diluxone_users_handle_submit' );

		$this->assertSame( diluxone_users_account_url( 'details' ), $url );
		$this->assertStringNotContainsString( 'short', $url, 'The message never travels in the address' );
		$this->assertStringContainsString( 'too short', diluxone_users_flash_take( $user, 'handle' ) );

		$_POST['diluxone_users_handle'] = 'Ana Lopez';

		$url = $this->expectRedirect( 'diluxone_users_handle_submit' );

		$this->assertSame( 'saved', $this->redirectState( $url ) );
		$this->assertSame( 'ana-lopez', diluxone_users_handle( $user ) );
	}

	/* ── The check as you type ───────────────────────────────────────── */

	public function test_the_check_refuses_without_a_nonce(): void {
		$user = $this->make_user();
		$this->postAs(
			$user,
			array(
				'nonce'  => 'forged',
				'handle' => 'ana',
			)
		);

		$this->expectDie( 'diluxone_users_handle_check', '-1', 403 );
	}

	public function test_the_check_answers_nothing_without_a_session(): void {
		$this->postAs(
			0,
			array(
				'nonce'  => wp_create_nonce( 'diluxone_users_handle_check' ),
				'handle' => 'admin',
			)
		);

		$this->assertSame( array( 'success' => false ), $this->check() );
	}

	/**
	 * With public names switched off, a form sent by hand saves none: what an
	 * administrator turns off disappears, the door the form posted to too.
	 * The check as you type answers nothing either.
	 */
	public function test_with_public_names_off_nothing_saves_one(): void {
		$user = $this->make_user();
		wp_set_current_user( $user );
		$nicename = get_userdata( $user )->user_nicename;
		$this->postAs(
			$user,
			array(
				'_wpnonce'              => wp_create_nonce( 'diluxone_users_handle' ),
				'diluxone_users_handle' => 'ana-lopez',
			)
		);

		$url = $this->expectRedirect( 'diluxone_users_handle_submit' );

		$this->assertNotSame( 'saved', $this->redirectState( $url ) );
		$this->assertSame( '', diluxone_users_handle( $user ) );
		clean_user_cache( $user );
		$this->assertSame( $nicename, get_userdata( $user )->user_nicename );

		$this->postAs( $user, array( 'nonce' => wp_create_nonce( 'diluxone_users_handle_check' ), 'handle' => 'ana-lopez' ) );
		$this->assertSame( array( 'success' => false ), $this->check() );
	}

	public function test_the_check_says_what_saving_would_say(): void {
		$this->names_on();
		$user = $this->make_user();
		wp_set_current_user( $user );
		$nonce = wp_create_nonce( 'diluxone_users_handle_check' );

		$this->postAs( $user, array( 'nonce' => $nonce, 'handle' => 'admin' ) );
		$refused = $this->check();
		$this->assertTrue( $refused['success'] );
		$this->assertFalse( $refused['data']['free'] );
		$this->assertSame( 'That one is taken by the site itself. Pick another.', $refused['data']['reason'] );

		$this->postAs( $user, array( 'nonce' => $nonce, 'handle' => 'Ana Lopez' ) );
		$free = $this->check();
		$this->assertTrue( $free['data']['free'] );
		$this->assertStringEndsWith( '/ana-lopez/', $free['data']['url'] );
		$this->assertStringContainsString( 'Nobody is using it', $free['data']['reason'] );

		diluxone_users_handle_save( $user, 'ana-lopez' );
		$this->postAs( $user, array( 'nonce' => $nonce, 'handle' => 'ana-lopez' ) );
		$this->assertSame( 'This is the one you have now.', $this->check()['data']['reason'] );
	}

	public function test_the_profile_address_is_the_author_page_without_its_last_segment(): void {
		$user = $this->make_user();
		wp_set_current_user( $user );

		$author = untrailingslashit( get_author_posts_url( $user ) );

		$this->assertSame( trailingslashit( substr( $author, 0, (int) strrpos( $author, '/' ) ) ), diluxone_users_handle_base_url() );
	}

	/**
	 * A name two accounts answer to — one's login, the other's public name —
	 * always goes to the one whose public name it is.
	 */
	public function test_a_name_two_accounts_answer_to_goes_to_its_public_owner(): void {
		global $wpdb;

		$this->names_on();
		diluxone_users_update_option( 'diluxone_users_handle_login', 1 );
		$name = 'ana' . strtolower( wp_generate_password( 6, false ) );

		// One account logs in as the name and chose another public name; the
		// other was given the name as its public name outside the plugin.
		$by_login = (int) wp_insert_user( array( 'user_login' => $name, 'user_email' => $name . '-login@example.test', 'user_pass' => wp_generate_password() ) );
		$by_name  = $this->make_user();
		$wpdb->update( $wpdb->users, array( 'user_nicename' => 'someone-else-' . $name ), array( 'ID' => $by_login ) );
		$wpdb->update( $wpdb->users, array( 'user_nicename' => $name ), array( 'ID' => $by_name ) );
		clean_user_cache( $by_login );
		clean_user_cache( $by_name );

		$this->assertGreaterThan( $by_login, $by_name, 'the one made second, so the first found is not the answer by luck' );
		$this->assertSame( $by_name, diluxone_users_handle_user( $name ) );
		$this->assertSame( get_userdata( $by_name )->user_email, diluxone_users_handle_login_email( $name ) );
	}
}
