<?php
/**
 * The registration form: every refusal of the request, in the order the
 * handler meets them, then the account it makes, then the form as the
 * shortcode draws it in each state.
 *
 * Unlike the sign-in form, this one says what happened, so each refusal is
 * checked by its own state and by no account being made.
 */

namespace Tests\Integration;

use Tests\Integration\Support\MockProvider;

class CoverSignInRegisterTest extends IntegrationTestCase {

	/** @var array<int, array<string, mixed>> */
	private const FIELDS = array(
		array(
			'key'      => 'cover_city',
			'label'    => 'City',
			'type'     => 'text',
			'required' => 1,
			'active'   => 1,
			'group'    => 'main',
			'edit'     => 'always',
			'help'     => 'Where you live',
		),
		array(
			'key'      => 'cover_terms',
			'label'    => 'I accept',
			'type'     => 'checkbox',
			'required' => 1,
			'active'   => 1,
			'group'    => 'main',
			'edit'     => 'always',
		),
	);

	private string $run = '';

	/** @var array<int, array<int, mixed>> Every `diluxone_users_registered` fired. */
	private array $registered = array();

	protected function setUp(): void {
		parent::setUp();

		$this->run = strtolower( wp_generate_password( 8, false ) );

		$page = (int) wp_insert_post(
			array(
				'post_type'    => 'page',
				'post_status'  => 'publish',
				'post_title'   => 'Register',
				'post_content' => '[diluxone_users_register]',
			)
		);

		diluxone_users_update_option( 'diluxone_users_register_page', $page );
		diluxone_users_update_option( 'diluxone_users_register_form', 1 );
		diluxone_users_update_option( 'diluxone_users_fields', self::FIELDS );

		add_action( 'diluxone_users_registered', array( $this, 'heard' ), 10, 2 );
	}

	protected function tearDown(): void {
		remove_action( 'diluxone_users_registered', array( $this, 'heard' ), 10 );
		MockProvider::remove();

		parent::tearDown();
	}

	/** The `diluxone_users_registered` action. */
	public function heard( int $user_id, string $email ): void {
		$this->registered[] = array( $user_id, $email );
	}

	private function email( string $name ): string {
		return $name . '-' . $this->run . '@example.test';
	}

	/**
	 * Sends the form, signed out, and returns the state it ended in.
	 *
	 * @param array<string, mixed> $post
	 */
	private function send( array $post, bool $nonce = true ): string {
		$this->postAs(
			0,
			$post + ( $nonce ? array( 'diluxone_users_register_nonce' => wp_create_nonce( 'diluxone_users_register' ) ) : array() )
		);

		$url = $this->expectRedirect( 'diluxone_users_register_request' );

		$this->assertStringStartsWith( diluxone_users_register_url(), $url, 'It always ends on the registration page' );

		return $this->redirectState( $url );
	}

	/** @return array<string, string> */
	private function answers( string $email ): array {
		return array(
			'diluxone_users_email' => $email,
			'cover_city'           => 'Mendoza',
			'cover_terms'          => '1',
		);
	}

	/** The form as the shortcode draws it, in this state. */
	private function form( string $state = '' ): string {
		$_GET = '' === $state ? array() : array( 'diluxone-users' => $state );

		return diluxone_users_shortcode_register();
	}

	/* ── The request's refusals ──────────────────────────────────────── */

	public function test_a_form_without_its_nonce_creates_nothing(): void {
		$this->assertSame( 'error', $this->send( $this->answers( $this->email( 'nononce' ) ), false ) );
		$this->assertFalse( email_exists( $this->email( 'nononce' ) ) );

		$this->assertSame( 'error', $this->send( $this->answers( $this->email( 'forged' ) ) + array( 'diluxone_users_register_nonce' => 'forged' ), false ) );
		$this->assertFalse( email_exists( $this->email( 'forged' ) ) );
	}

	public function test_a_form_sent_after_registration_closed_creates_nothing(): void {
		diluxone_users_update_option( 'diluxone_users_register_form', 0 );

		$this->assertSame( 'closed', $this->send( $this->answers( $this->email( 'closed' ) ) ) );
		$this->assertFalse( email_exists( $this->email( 'closed' ) ) );
	}

	public function test_something_that_is_not_an_address_is_told_so(): void {
		$this->assertSame( 'email', $this->send( $this->answers( 'not an address' ) ) );

		$post                         = $this->answers( '' );
		$post['diluxone_users_email'] = array( $this->email( 'array' ) );
		$this->assertSame( 'email', $this->send( $post ) );
		$this->assertFalse( email_exists( $this->email( 'array' ) ) );
	}

	public function test_a_required_answer_missing_creates_nothing(): void {
		$post = $this->answers( $this->email( 'missing' ) );
		unset( $post['cover_city'] );

		$this->assertSame( 'missing', $this->send( $post ) );
		$this->assertFalse( email_exists( $this->email( 'missing' ) ) );
	}

	public function test_a_machine_past_its_count_is_told_to_slow_down(): void {
		for ( $i = 0; $i < diluxone_users_register_burst(); $i++ ) {
			diluxone_users_register_allowed();
		}

		$this->assertSame( 'slow', $this->send( $this->answers( $this->email( 'slow' ) ) ) );
		$this->assertFalse( email_exists( $this->email( 'slow' ) ) );
	}

	public function test_an_address_with_an_account_is_sent_to_sign_in(): void {
		$user = $this->make_user();

		$this->assertSame( 'taken', $this->send( $this->answers( get_userdata( $user )->user_email ) ) );
		$this->assertSame( array(), self::$mail );
		$this->assertSame( array(), $this->registered );
	}

	public function test_an_account_wordpress_refuses_is_an_error(): void {
		// Free as an e-mail, taken as a login: wp_insert_user() says no.
		$user  = $this->make_user();
		$login = $this->email( 'login' );
		global $wpdb;
		$wpdb->update( $wpdb->users, array( 'user_login' => $login ), array( 'ID' => $user ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		clean_user_cache( $user );

		$this->assertSame( 'error', $this->send( $this->answers( $login ) ) );
		$this->assertSame( array(), $this->registered );
	}

	public function test_a_registration_makes_the_account_with_its_answers_and_mails_the_link(): void {
		$email = $this->email( 'new' );

		$this->assertSame( 'registered', $this->send( $this->answers( $email ) ) );

		$user = (int) email_exists( $email );
		$this->assertGreaterThan( 0, $user );
		$this->assertSame( 'Mendoza', get_user_meta( $user, 'cover_city', true ) );
		$this->assertSame( array( array( $user, $email ) ), $this->registered );
		$this->assertSame( $email, $this->lastMail()['to'] );
		$this->assertStringContainsString( 'diluxone_users_token=', (string) $this->lastMail()['message'] );
		$this->assertNotSame( '', get_user_meta( $user, DILUXONE_USERS_META_HASH, true ) );
		$this->assertSame( 0, get_current_user_id(), 'the link signs in, not the form' );
	}

	/* ── The form ────────────────────────────────────────────────────── */

	public function test_somebody_signed_in_sees_no_form(): void {
		wp_set_current_user( $this->make_user() );

		$this->assertSame( '', diluxone_users_shortcode_register() );
	}

	public function test_the_open_form_asks_for_the_address_and_the_required_answers(): void {
		$html = $this->form();

		$this->assertStringContainsString( '<h2 class="diluxone-users-login__title">Create your account</h2>', $html );
		$this->assertStringContainsString( 'name="action" value="diluxone_users_signup"', $html );
		$this->assertMatchesRegularExpression( '/name="diluxone_users_register_nonce" value="([a-f0-9]+)"/', $html );
		preg_match( '/name="diluxone_users_register_nonce" value="([a-f0-9]+)"/', $html, $m );
		$this->assertSame( 1, wp_verify_nonce( $m[1], 'diluxone_users_register' ) );
		$this->assertStringContainsString( 'name="diluxone_users_email" required', $html );
		$this->assertStringContainsString( 'diluxone-users-field diluxone-users-field--text', $html );
		$this->assertStringContainsString( '<label for="diluxone-users-cover_city">', $html );
		$this->assertStringContainsString( '<p class="diluxone-users-field__help">Where you live</p>', $html );
		// A checkbox carries its own label: no label above it.
		$this->assertStringContainsString( 'diluxone-users-field diluxone-users-field--checkbox', $html );
		$this->assertStringNotContainsString( '<label for="diluxone-users-cover_terms">', $html );
		$this->assertStringContainsString( 'Already have an account?', $html );
		$this->assertStringNotContainsString( 'diluxone-users-login__intro', $html );
		$this->assertStringNotContainsString( 'diluxone-users-login__legal', $html );
		$this->assertStringNotContainsString( 'data-diluxone-users-message', $html );
	}

	public function test_the_site_s_own_words_and_the_legal_line_are_drawn_escaped(): void {
		diluxone_users_update_option( 'diluxone_users_register_title', 'Join <us>' );
		diluxone_users_update_option( 'diluxone_users_register_intro', 'It takes a minute & no password' );
		diluxone_users_update_option( 'diluxone_users_login_legal', 'By joining you accept the <a href="https://example.test/terms">terms</a><script>x</script>' );

		$html = $this->form();

		$this->assertStringContainsString( '<h2 class="diluxone-users-login__title">Join &lt;us&gt;</h2>', $html );
		$this->assertStringContainsString( '<p class="diluxone-users-login__intro">It takes a minute &amp; no password</p>', $html );
		$this->assertStringContainsString( '<a href="https://example.test/terms">terms</a>', $html );
		$this->assertStringNotContainsString( '<script>', $html );
	}

	/** @return array<string, array{0: string, 1: string}> */
	public static function states(): array {
		return array(
			'taken'   => array( 'taken', 'register_taken' ),
			'email'   => array( 'email', 'register_email' ),
			'missing' => array( 'missing', 'register_missing' ),
			'slow'    => array( 'slow', 'register_slow' ),
			'closed'  => array( 'closed', 'register_closed' ),
			'error'   => array( 'error', 'register_error' ),
		);
	}

	/** @dataProvider states */
	public function test_each_state_says_its_own_message( string $state, string $message ): void {
		$html = $this->form( $state );

		$this->assertSame( 1, substr_count( $html, 'data-diluxone-users-message=' ) );
		$this->assertStringContainsString( 'data-diluxone-users-message="' . $message . '"', $html );

		if ( 'taken' === $state ) {
			$this->assertStringContainsString( '<a href="' . esc_url( diluxone_users_login_url() ) . '">Sign in instead</a>', $html );
		} else {
			$this->assertStringNotContainsString( 'Sign in instead', $html );
		}
	}

	public function test_after_registering_the_form_says_the_link_is_on_its_way(): void {
		diluxone_users_update_option( 'diluxone_users_register_done', 'All set' );

		$html = $this->form( 'registered' );

		$this->assertStringContainsString( 'All set', $html );
		$this->assertStringContainsString( 'There is no password to choose.', $html );
		$this->assertStringNotContainsString( '<form', $html );
	}

	public function test_where_the_link_creates_accounts_the_closed_form_points_to_sign_in(): void {
		diluxone_users_update_option( 'diluxone_users_register_form', 0 );
		diluxone_users_update_option( 'diluxone_users_login_register', 1 );

		$html = $this->form();

		$this->assertStringContainsString( 'Sign in to create your account', $html );
		$this->assertStringContainsString( 'href="' . esc_url( diluxone_users_login_url() ) . '"', $html );
		$this->assertStringNotContainsString( '<form', $html );
	}

	public function test_a_closed_form_says_so_and_why_when_a_form_arrived_late(): void {
		diluxone_users_update_option( 'diluxone_users_register_form', 0 );

		$plain = $this->form();
		$this->assertStringContainsString( 'Registration is closed', $plain );
		$this->assertStringNotContainsString( 'data-diluxone-users-message', $plain );
		$this->assertStringNotContainsString( '<form', $plain );

		$late = $this->form( 'closed' );
		$this->assertStringContainsString( 'data-diluxone-users-message="register_closed"', $late );
	}

	public function test_the_networks_are_offered_above_the_form_when_they_are_on(): void {
		MockProvider::install();
		diluxone_users_update_option( 'diluxone_users_sso_login', 1 );

		$html = $this->form();

		$this->assertStringContainsString( 'or with your email', $html );
		$this->assertStringContainsString( esc_url( diluxone_users_sso_login_url( MockProvider::ID ) ), $html );
	}

	/* ── What the form takes ─────────────────────────────────────────── */

	/**
	 * The account keeps the answers the form asked for, and nothing else.
	 *
	 * The form draws the required fields a person can fill in. Anything else
	 * posted beside them — an optional field it never showed, one the person
	 * may never edit, one switched off, WordPress's own role or the photo —
	 * is not an answer to this form, and is not kept.
	 */
	public function test_only_the_fields_the_form_drew_are_kept(): void {
		diluxone_users_update_option(
			'diluxone_users_fields',
			array_merge(
				self::FIELDS,
				array(
					array( 'key' => 'cover_bio', 'label' => 'Bio', 'type' => 'text', 'required' => 0, 'active' => 1, 'group' => 'main', 'edit' => 'always' ),
					array( 'key' => 'cover_vip', 'label' => 'VIP', 'type' => 'text', 'required' => 1, 'active' => 1, 'group' => 'main', 'edit' => 'never' ),
					array( 'key' => 'cover_off', 'label' => 'Off', 'type' => 'text', 'required' => 1, 'active' => 0, 'group' => 'main', 'edit' => 'always' ),
				)
			)
		);

		$form = $this->form();
		$this->assertStringContainsString( 'name="cover_city"', $form );
		$this->assertStringNotContainsString( 'name="cover_bio"', $form, 'the form does not ask for it' );

		$email = $this->email( 'only-drawn' );

		$this->assertSame(
			'registered',
			$this->send(
				$this->answers( $email ) + array(
					'cover_bio'             => 'Hand-made',
					'cover_vip'             => 'yes',
					'cover_off'             => 'on',
					'wp_capabilities'       => array( 'administrator' => true ),
					'diluxone_users_avatar' => '1',
				)
			)
		);

		$user = (int) email_exists( $email );

		$this->assertSame( 'Mendoza', get_user_meta( $user, 'cover_city', true ) );
		$this->assertSame( '', get_user_meta( $user, 'cover_bio', true ), 'never shown, never kept' );
		$this->assertSame( '', get_user_meta( $user, 'cover_vip', true ) );
		$this->assertSame( '', get_user_meta( $user, 'cover_off', true ) );
		$this->assertSame( '', get_user_meta( $user, 'diluxone_users_avatar', true ) );
		$this->assertSame( array( diluxone_users_register_role() ), array_values( get_userdata( $user )->roles ) );
	}

	/* ── Probing for addresses ───────────────────────────────────────── */

	/**
	 * "Taken" tells whoever asked that an address has an account here, so
	 * every "taken" spends one of this machine's tries — on its own count,
	 * not because of where the general count happens to sit in the handler.
	 * The general count is forgotten between the probes here, the way it
	 * would be if the checks were ever put in another order: the probing is
	 * still cut off, and past the limit the answer is "slow", which says
	 * nothing about the address.
	 */
	public function test_probing_for_addresses_is_cut_off_whatever_the_order_of_the_checks(): void {
		add_filter( 'diluxone_users_register_burst', array( $this, 'two' ) );

		try {
			$known = get_userdata( $this->make_user() )->user_email;

			for ( $try = 1; $try <= 2; $try++ ) {
				$this->assertSame( 'taken', $this->send( $this->answers( $known ) ), 'try ' . $try );
				delete_site_transient( 'diluxone_users_burst_register_' . md5( diluxone_users_client_ip() ) );
			}

			$this->assertSame( 'slow', $this->send( $this->answers( $known ) ), 'a third probe learns nothing' );
		} finally {
			remove_filter( 'diluxone_users_register_burst', array( $this, 'two' ) );
		}
	}

	/** The `diluxone_users_register_burst` filter: two a window. */
	public function two(): int {
		return 2;
	}

	/**
	 * The account the form makes has the role the site gives new accounts —
	 * on a network, on the hub, where the form is.
	 */
	public function test_the_account_the_form_makes_has_the_sites_role(): void {
		diluxone_users_update_option( 'diluxone_users_login_role', 'contributor' );
		$email = $this->email( 'role' );

		$this->assertSame( 'registered', $this->send( $this->answers( $email ) ) );

		$user = (int) email_exists( $email );

		$this->assertTrue( is_user_member_of_blog( $user, get_current_blog_id() ) );
		$this->assertSame( array( 'contributor' ), array_values( get_userdata( $user )->roles ) );
	}

	/** A network that takes no accounts, and a form with no page to be on, are both closed. */
	public function test_the_form_is_closed_with_no_page_or_on_a_network_that_takes_no_accounts(): void {
		diluxone_users_update_option( 'diluxone_users_register_page', 0 );
		$this->assertFalse( diluxone_users_register_form_open(), 'no page' );

		$this->postAs( 0, $this->answers( $this->email( 'nopage' ) ) + array( 'diluxone_users_register_nonce' => wp_create_nonce( 'diluxone_users_register' ) ) );
		$url = $this->expectRedirect( 'diluxone_users_register_request' );
		$this->assertSame( 'closed', $this->redirectState( $url ) );
		$this->assertStringStartsWith( home_url( '/' ), $url );

		if ( is_multisite() ) {
			$this->setUp_page_again();
			update_site_option( 'registration', 'none' );
			$this->assertSame( 'closed', $this->send( $this->answers( $this->email( 'closednet' ) ) ) );
			$this->assertFalse( email_exists( $this->email( 'closednet' ) ) );
		}
	}

	/** The registration page, again. */
	private function setUp_page_again(): void {
		diluxone_users_update_option(
			'diluxone_users_register_page',
			(int) wp_insert_post(
				array(
					'post_type'    => 'page',
					'post_status'  => 'publish',
					'post_title'   => 'Register again',
					'post_content' => '[diluxone_users_register]',
				)
			)
		);
	}
}
