<?php
/**
 * Passkeys, end to end: every refusal of the browser dialogue first, then a
 * real registration and a real sign-in with a P-256 key made by the test.
 *
 * The key pair is generated here and the signature made with it, over exactly
 * the bytes a browser signs, so the happy path below is the verification the
 * plugin really runs, and each refusal is one byte of that changed.
 */

namespace Tests\Integration;

use Tests\Integration\Support\RedirectException;

class CoverSignInPasskeysTest extends IntegrationTestCase {

	/** @var \OpenSSLAsymmetricKey The test's own private key. */
	private $private;

	/** The DER of its public half, as a browser hands it over. */
	private string $der = '';

	/** Credential ids are the test's own: kept to the base64url alphabet the handler allows. */
	private string $run = '';

	/** @var mixed The browser's name for itself before the test. */
	private $agent = null;

	/** @var array<int, array<int, mixed>> Every counter warning fired. */
	private array $warnings = array();

	protected function setUp(): void {
		parent::setUp();

		$this->run     = wp_generate_password( 10, false );
		$this->agent   = $_SERVER['HTTP_USER_AGENT'] ?? null;
		$this->private = openssl_pkey_new(
			array(
				'private_key_type' => OPENSSL_KEYTYPE_EC,
				'curve_name'       => 'prime256v1',
			)
		);

		$pem       = (string) openssl_pkey_get_details( $this->private )['key'];
		$this->der = (string) base64_decode( (string) preg_replace( '/-----[^-]+-----|\s+/', '', $pem ), true );

		diluxone_users_update_option( 'diluxone_users_passkey_enabled', 1 );

		add_action( 'diluxone_users_passkey_counter_warning', array( $this, 'warned' ), 10, 2 );
	}

	protected function tearDown(): void {
		remove_action( 'diluxone_users_passkey_counter_warning', array( $this, 'warned' ), 10 );

		while ( is_multisite() && ms_is_switched() ) {
			restore_current_blog();
		}

		if ( null === $this->agent ) {
			unset( $_SERVER['HTTP_USER_AGENT'] );
		} else {
			$_SERVER['HTTP_USER_AGENT'] = $this->agent;
		}

		wp_dequeue_script( 'diluxone-users-passkeys' );
		wp_deregister_script( 'diluxone-users-passkeys' );

		parent::tearDown();
	}

	/** The `diluxone_users_passkey_counter_warning` action. */
	public function warned( int $user_id, string $id ): void {
		$this->warnings[] = array( $user_id, $id );
	}

	private function id( string $name ): string {
		return 'cred-' . $name . '-' . $this->run;
	}

	/** The client data a browser returns for one operation. */
	private function client( string $type, string $challenge, ?string $origin = null ): string {
		return (string) wp_json_encode(
			array(
				'type'      => $type,
				'challenge' => $challenge,
				'origin'    => $origin ?? diluxone_users_passkey_origin(),
			)
		);
	}

	/** The authenticator data: the domain's hash, the flags, the counter. */
	private function auth( int $flags = 0x05, int $counter = 0, ?string $rp = null ): string {
		return hash( 'sha256', $rp ?? diluxone_users_passkey_rp_id(), true ) . chr( $flags ) . pack( 'N', $counter );
	}

	/** What the authenticator signs: the authenticator data and the hash of the client data. */
	private function sign( string $auth, string $client ): string {
		openssl_sign( $auth . hash( 'sha256', $client, true ), $signature, $this->private, OPENSSL_ALGO_SHA256 );

		return (string) $signature;
	}

	/** Somebody with the test's key registered. */
	private function owner( int $counter = 0 ): int {
		$user = $this->make_user();

		diluxone_users_passkeys_save(
			$user,
			array(
				array(
					'id'      => $this->id( 'a' ),
					'key'     => base64_encode( $this->der ),
					'alg'     => -7,
					'label'   => 'Phone',
					'created' => time(),
					'used'    => 0,
					'counter' => $counter,
					'rp'      => diluxone_users_passkey_rp_id(),
				),
			)
		);

		return $user;
	}

	/**
	 * A complete sign-in answer, signed, with one part overridable.
	 *
	 * @param array<string, mixed> $change
	 * @return array<string, string>
	 */
	private function login_post( array $change = array() ): array {
		$challenge = $change['challenge'] ?? diluxone_users_passkey_challenge_new( 'log' );
		$client    = $change['client'] ?? $this->client( 'webauthn.get', $challenge );
		$auth      = $change['auth'] ?? $this->auth( 0x05, (int) ( $change['counter'] ?? 0 ) );

		return array(
			'id'                => $change['id'] ?? $this->id( 'a' ),
			'clientDataJSON'    => $client,
			'authenticatorData' => diluxone_users_b64url_encode( $auth ),
			'signature'         => diluxone_users_b64url_encode( $change['signature'] ?? $this->sign( $auth, $client ) ),
		);
	}

	/**
	 * Runs the AJAX handler and returns what it answered.
	 *
	 * @param array<string, string> $post
	 * @return array<string, mixed>
	 */
	private function ajax( array $post ): array {
		$_POST                     = $post;
		$_REQUEST                  = $post;
		$_SERVER['REQUEST_METHOD'] = 'POST';

		ob_start();

		try {
			diluxone_users_passkeys_ajax();
		} catch ( \WPAjaxDieContinueException $e ) {
			unset( $e );
		} finally {
			$out = (string) ob_get_clean();
		}

		return (array) json_decode( $out, true );
	}

	/** @param array<string, string> $post */
	private function ajax_step( string $step, array $post = array() ): array {
		return $this->ajax( $post + array( 'step' => $step, 'nonce' => wp_create_nonce( 'diluxone_users_passkeys' ) ) );
	}

	/* ── The dialogue's doors ────────────────────────────────────────── */

	public function test_a_call_without_the_page_nonce_is_refused_before_anything_is_read(): void {
		$answer = $this->ajax( array( 'step' => 'login-options', 'nonce' => 'forged' ) );

		$this->assertFalse( $answer['success'] );
		$this->assertSame( 'Session expired. Reload the page.', $answer['data']['message'] );
	}

	public function test_a_site_that_does_not_use_passkeys_answers_so(): void {
		diluxone_users_update_option( 'diluxone_users_passkey_enabled', 0 );

		$answer = $this->ajax_step( 'login-options' );

		$this->assertFalse( $answer['success'] );
		$this->assertSame( 'This site does not use passkeys.', $answer['data']['message'] );
	}

	public function test_registering_needs_a_session(): void {
		foreach ( array( 'register-options', 'register' ) as $step ) {
			$answer = $this->ajax_step( $step );

			$this->assertFalse( $answer['success'], $step );
			$this->assertSame( 'Session expired. Reload the page.', $answer['data']['message'], $step );
		}
	}

	public function test_an_unknown_step_is_refused(): void {
		$answer = $this->ajax_step( 'explode' );

		$this->assertFalse( $answer['success'] );
		$this->assertSame( 'Unknown step.', $answer['data']['message'] );
	}

	public function test_a_machine_asking_for_challenges_in_a_loop_meets_a_ceiling(): void {
		for ( $i = 0; $i < 60; $i++ ) {
			$this->assertTrue( diluxone_users_ip_burst( 'passkey', 60, 10 * MINUTE_IN_SECONDS ) );
		}

		$answer = $this->ajax_step( 'login-options' );

		$this->assertFalse( $answer['success'] );
		$this->assertSame( 'Too many attempts. Wait a moment and try again.', $answer['data']['message'] );
	}

	public function test_off_the_hub_the_call_is_sent_to_the_hub(): void {
		if ( ! is_multisite() ) {
			$this->markTestSkipped( 'A single site is its own hub; there the same call is answered, as in test_the_sign_in_options_carry_a_fresh_challenge_and_the_domain.' );
		}

		$site = (int) wp_insert_site(
			array(
				'domain' => (string) get_network()->domain,
				'path'   => '/cover-signin-pk-' . strtolower( $this->run ) . '/',
			)
		);
		switch_to_blog( $site );

		$answer = $this->ajax_step( 'login-options' );

		$this->assertFalse( $answer['success'] );
		$this->assertStringContainsString( 'Passkeys are used on', $answer['data']['message'] );
		$this->assertNotSame( '', $answer['data']['redirect'] );
	}

	public function test_the_sign_in_options_carry_a_fresh_challenge_and_the_domain(): void {
		$answer = $this->ajax_step( 'login-options' );

		$this->assertTrue( $answer['success'] );
		$this->assertSame( diluxone_users_passkey_rp_id(), $answer['data']['rpId'] );
		$this->assertSame( 'required', $answer['data']['userVerification'] );
		// The challenge was stored, and can be spent once.
		$this->assertTrue( diluxone_users_passkey_challenge_use( 'log', $answer['data']['challenge'] ) );
		$this->assertFalse( diluxone_users_passkey_challenge_use( 'log', $answer['data']['challenge'] ) );
	}

	public function test_the_registration_options_exclude_the_keys_already_here_and_follow_the_settings(): void {
		$user = $this->owner();
		wp_set_current_user( $user );
		diluxone_users_update_option( 'diluxone_users_passkey_where', 'device' );
		diluxone_users_update_option( 'diluxone_users_passkey_verify', 0 );

		$answer = $this->ajax_step( 'register-options' );

		$this->assertTrue( $answer['success'] );
		$data = $answer['data'];
		$this->assertSame( diluxone_users_passkey_rp_id(), $data['rp']['id'] );
		$this->assertSame( get_userdata( $user )->user_email, $data['user']['name'] );
		$this->assertSame( array( array( 'id' => $this->id( 'a' ), 'type' => 'public-key' ) ), $data['excludeCredentials'] );
		$this->assertSame( 'platform', $data['authenticatorAttachment'] );
		$this->assertSame( 'preferred', $data['userVerification'] );
		// The id handed to the authenticator is opaque: not the user's number.
		$this->assertStringNotContainsString( (string) $user, (string) base64_decode( strtr( $data['user']['id'], '-_', '+/' ) ) );
		$this->assertTrue( diluxone_users_passkey_challenge_use( 'reg', $data['challenge'] ) );
	}

	public function test_registration_options_on_any_device_leave_the_attachment_open(): void {
		wp_set_current_user( $this->make_user() );

		$options = diluxone_users_passkeys_register_options();

		$this->assertNull( $options['authenticatorAttachment'] );
		$this->assertSame( 'required', $options['userVerification'] );
		$this->assertSame( array(), $options['excludeCredentials'] );
	}

	/* ── Registration ────────────────────────────────────────────────── */

	/** @return array<string, string> */
	private function register_post( array $change = array() ): array {
		$challenge = $change['challenge'] ?? diluxone_users_passkey_challenge_new( 'reg' );

		return array(
			'id'             => $change['id'] ?? $this->id( 'a' ),
			'publicKey'      => diluxone_users_b64url_encode( $change['der'] ?? $this->der ),
			'algorithm'      => (string) ( $change['alg'] ?? '-7' ),
			'clientDataJSON' => diluxone_users_b64url_encode( $change['client'] ?? $this->client( 'webauthn.create', $challenge ) ),
			'label'          => $change['label'] ?? 'Work laptop',
		);
	}

	public function test_a_registration_through_the_dialogue_stores_the_key_indexed_and_tells_the_owner(): void {
		$user = $this->make_user();
		wp_set_current_user( $user );

		$answer = $this->ajax_step( 'register', $this->register_post() );

		$this->assertTrue( $answer['success'] );
		$this->assertSame( 'Passkey saved.', $answer['data']['message'] );

		$keys = diluxone_users_passkeys( $user );
		$this->assertCount( 1, $keys );
		$this->assertSame( $this->id( 'a' ), $keys[0]['id'] );
		$this->assertSame( base64_encode( $this->der ), $keys[0]['key'] );
		$this->assertSame( -7, $keys[0]['alg'] );
		$this->assertSame( 'Work laptop', $keys[0]['label'] );
		$this->assertSame( diluxone_users_passkey_rp_id(), $keys[0]['rp'] );
		$this->assertSame( $user, diluxone_users_passkey_owner( $this->id( 'a' ) ) );

		// The security notice went to the owner, naming the key.
		$this->assertSame( get_userdata( $user )->user_email, $this->lastMail()['to'] );
		$this->assertStringContainsString( 'Work laptop', (string) $this->lastMail()['message'] );
	}

	public function test_a_registration_answering_a_challenge_never_issued_is_refused(): void {
		wp_set_current_user( $this->make_user() );

		$answer = $this->ajax_step( 'register', $this->register_post( array( 'challenge' => 'made-up' ) ) );

		$this->assertFalse( $answer['success'] );
		$this->assertSame( 'That did not check out. Try again.', $answer['data']['message'] );
		$this->assertSame( array(), diluxone_users_passkeys( get_current_user_id() ) );
	}

	/** @return array<string, array{0: array<string, mixed>}> */
	public static function broken_registrations(): array {
		return array(
			'no id'                => array( array( 'id' => '' ) ),
			'no key'               => array( array( 'der' => '' ) ),
			'a sign-in, not a new key' => array( array( 'type' => 'webauthn.get' ) ),
			'another origin'       => array( array( 'origin' => 'https://cloned.example' ) ),
			'client data not JSON' => array( array( 'raw' => 'not json' ) ),
		);
	}

	/**
	 * @dataProvider broken_registrations
	 * @param array<string, mixed> $change
	 */
	public function test_a_registration_that_does_not_check_out_stores_nothing( array $change ): void {
		$user = $this->make_user();
		wp_set_current_user( $user );

		$challenge = diluxone_users_passkey_challenge_new( 'reg' );
		$client    = $change['raw'] ?? $this->client( $change['type'] ?? 'webauthn.create', $challenge, $change['origin'] ?? null );
		$post      = $this->register_post( $change + array( 'challenge' => $challenge ) );

		$post['clientDataJSON'] = $client;

		$result = diluxone_users_passkeys_register( $post );

		$this->assertFalse( $result['success'] );
		$this->assertSame( 'That did not check out. Try again.', $result['data']['message'] );
		$this->assertSame( array(), diluxone_users_passkeys( $user ) );
	}

	public function test_a_key_of_an_algorithm_the_site_cannot_verify_is_refused(): void {
		$user = $this->make_user();
		wp_set_current_user( $user );

		foreach ( array( array( 'alg' => '-8' ), array( 'der' => 'garbage-that-is-no-key' ) ) as $change ) {
			$post                   = $this->register_post( $change );
			$post['clientDataJSON'] = diluxone_users_b64url_decode( $post['clientDataJSON'] );

			$result = diluxone_users_passkeys_register( $post );

			$this->assertFalse( $result['success'] );
			$this->assertSame( 'That key is of a kind this site cannot verify.', $result['data']['message'] );
		}

		$this->assertSame( array(), diluxone_users_passkeys( $user ) );
	}

	public function test_the_same_key_twice_is_kept_once(): void {
		$user = $this->owner();
		wp_set_current_user( $user );

		$post                   = $this->register_post();
		$post['clientDataJSON'] = diluxone_users_b64url_decode( $post['clientDataJSON'] );
		$result                 = diluxone_users_passkeys_register( $post );

		$this->assertTrue( $result['success'] );
		$this->assertSame( 'That one was already here.', $result['data']['message'] );
		$this->assertCount( 1, diluxone_users_passkeys( $user ) );
	}

	public function test_a_key_another_account_holds_is_not_claimed(): void {
		$this->owner();
		$other = $this->make_user();
		wp_set_current_user( $other );

		$post                   = $this->register_post();
		$post['clientDataJSON'] = diluxone_users_b64url_decode( $post['clientDataJSON'] );
		$result                 = diluxone_users_passkeys_register( $post );

		$this->assertFalse( $result['success'] );
		$this->assertSame( 'That passkey is already registered to another account.', $result['data']['message'] );
		$this->assertSame( array(), diluxone_users_passkeys( $other ) );
	}

	public function test_a_key_with_no_name_is_named_after_the_device(): void {
		$cases = array(
			'Mozilla/5.0 (iPhone; CPU iPhone OS 17_0)' => 'iPhone',
			'Mozilla/5.0 (Macintosh; Intel Mac OS X)'  => 'Mac',
			'Mozilla/5.0 (Windows NT 10.0; Win64)'     => 'Windows',
			'curl/8.0'                                 => 'Passkey',
		);

		foreach ( $cases as $agent => $name ) {
			$_SERVER['HTTP_USER_AGENT'] = $agent;
			$this->assertSame( $name, diluxone_users_passkey_clean_label( '   ' ), $agent );
		}

		unset( $_SERVER['HTTP_USER_AGENT'] );
		$this->assertSame( 'Passkey', diluxone_users_passkey_label() );
		$this->assertSame( 60, mb_strlen( diluxone_users_passkey_clean_label( str_repeat( 'ñ', 80 ) ) ) );
	}

	/* ── Sign-in ─────────────────────────────────────────────────────── */

	public function test_a_signed_answer_from_a_registered_key_opens_a_session_and_moves_the_counter(): void {
		$user = $this->owner( 3 );

		// Through the dialogue the client data travels base64url-encoded.
		$post                   = $this->login_post( array( 'counter' => 4 ) );
		$post['clientDataJSON'] = diluxone_users_b64url_encode( $post['clientDataJSON'] );
		$answer                 = $this->ajax_step( 'login', $post );

		$this->assertTrue( $answer['success'] );
		$this->assertSame( home_url( '/' ), $answer['data']['redirect'] );
		$this->assertSame( $user, get_current_user_id() );

		$key = diluxone_users_passkeys( $user )[0];
		$this->assertSame( 4, $key['counter'] );
		$this->assertGreaterThan( 0, $key['used'] );
		$this->assertSame( array(), $this->warnings );
	}

	public function test_a_counter_that_goes_back_is_logged_and_the_sign_in_goes_on(): void {
		$user = $this->owner( 9 );

		$result = diluxone_users_passkeys_login( $this->login_post( array( 'counter' => 5 ) ) );

		$this->assertTrue( $result['success'] );
		$this->assertSame( array( array( $user, $this->id( 'a' ) ) ), $this->warnings );
		$this->assertSame( 5, diluxone_users_passkeys( $user )[0]['counter'] );
	}

	public function test_a_redirect_filter_cannot_send_the_browser_off_the_site(): void {
		$this->owner();
		$away = static fn(): string => 'https://evil.example/steal';
		add_filter( 'diluxone_users_login_redirect', $away );

		try {
			$result = diluxone_users_passkeys_login( $this->login_post() );
		} finally {
			remove_filter( 'diluxone_users_login_redirect', $away );
		}

		$this->assertTrue( $result['success'] );
		$this->assertSame( home_url( '/' ), $result['data']['redirect'] );
	}

	/** @return array<string, array{0: string}> */
	public static function broken_sign_ins(): array {
		return array(
			'no credential id'          => array( 'no-id' ),
			'a challenge never issued'  => array( 'challenge' ),
			'a challenge spent already' => array( 'replay' ),
			'a registration, not a sign-in' => array( 'type' ),
			'another origin'            => array( 'origin' ),
			'authenticator data too short' => array( 'short' ),
			'another domain\'s hash'    => array( 'rp' ),
			'nobody present'            => array( 'absent' ),
			'presence without the verification asked for' => array( 'unverified' ),
			'a key nobody holds'        => array( 'unknown' ),
			'a stray index row'         => array( 'stray' ),
			'a signature over other bytes' => array( 'forged' ),
			'a stored key of an algorithm not allowed' => array( 'alg' ),
			'a stored key that is not a key' => array( 'garbage' ),
		);
	}

	/** @dataProvider broken_sign_ins */
	public function test_a_sign_in_that_does_not_check_out_opens_nothing( string $case ): void {
		$user      = $this->owner();
		$challenge = diluxone_users_passkey_challenge_new( 'log' );
		$change    = array( 'challenge' => $challenge );

		switch ( $case ) {
			case 'no-id':
				$change['id'] = '';
				break;
			case 'challenge':
				$change['challenge'] = 'never-issued';
				break;
			case 'replay':
				diluxone_users_passkey_challenge_use( 'log', $challenge );
				break;
			case 'type':
				$change['client'] = $this->client( 'webauthn.create', $challenge );
				break;
			case 'origin':
				$change['client'] = $this->client( 'webauthn.get', $challenge, 'https://cloned.example' );
				break;
			case 'short':
				$change['auth'] = str_repeat( 'x', 36 );
				break;
			case 'rp':
				$change['auth'] = $this->auth( 0x05, 0, 'cloned.example' );
				break;
			case 'absent':
				$change['auth'] = $this->auth( 0x04 );
				break;
			case 'unverified':
				$change['auth'] = $this->auth( 0x01 );
				break;
			case 'unknown':
				$change['id'] = $this->id( 'nobody' );
				break;
			case 'stray':
				$change['id'] = $this->id( 'stray' );
				update_user_meta( $user, diluxone_users_passkey_index_key( $this->id( 'stray' ) ), 1 );
				break;
			case 'forged':
				$change['signature'] = $this->sign( $this->auth( 0x05, 99 ), 'something else' );
				break;
			case 'alg':
			case 'garbage':
				$keys                                    = diluxone_users_passkeys( $user );
				$keys[0][ 'alg' === $case ? 'alg' : 'key' ] = 'alg' === $case ? -8 : base64_encode( 'no key' );
				update_user_meta( $user, 'diluxone_users_passkeys', $keys );
				break;
		}

		$result = diluxone_users_passkeys_login( $this->login_post( $change ) );

		$this->assertFalse( $result['success'] );
		$this->assertSame( 'That passkey did not check out.', $result['data']['message'] );
		$this->assertSame( 0, get_current_user_id() );
		$this->assertSame( 0, diluxone_users_passkeys( $user )[0]['used'] );
	}

	public function test_presence_alone_is_enough_when_the_site_does_not_ask_for_verification(): void {
		diluxone_users_update_option( 'diluxone_users_passkey_verify', 0 );

		$this->assertSame( array( 'flags' => 0x01, 'counter' => 7 ), diluxone_users_passkey_auth_data( $this->auth( 0x01, 7 ) ) );
	}

	public function test_an_rsa_key_is_verified_too(): void {
		$rsa = openssl_pkey_new(
			array(
				'private_key_type' => OPENSSL_KEYTYPE_RSA,
				'private_key_bits' => 2048,
			)
		);
		$der = (string) base64_decode( (string) preg_replace( '/-----[^-]+-----|\s+/', '', (string) openssl_pkey_get_details( $rsa )['key'] ), true );

		openssl_sign( 'auth' . hash( 'sha256', 'client', true ), $signature, $rsa, OPENSSL_ALGO_SHA256 );

		$this->assertTrue( diluxone_users_passkey_signature_ok( $der, -257, 'auth', 'client', (string) $signature ) );
		$this->assertFalse( diluxone_users_passkey_signature_ok( $der, -257, 'auth', 'other', (string) $signature ) );
		$this->assertFalse( diluxone_users_passkey_signature_ok( $der, -35, 'auth', 'client', (string) $signature ) );
	}

	public function test_the_owner_of_an_empty_id_is_nobody(): void {
		$this->assertSame( 0, diluxone_users_passkey_owner( '' ) );
	}

	/* ── The script and the profile's form ───────────────────────────── */

	public function test_the_script_is_queued_once_with_the_dialogue_nonce_and_only_when_passkeys_are_on(): void {
		diluxone_users_update_option( 'diluxone_users_passkey_enabled', 0 );
		diluxone_users_passkeys_enqueue();
		$this->assertFalse( wp_script_is( 'diluxone-users-passkeys', 'enqueued' ) );

		diluxone_users_update_option( 'diluxone_users_passkey_enabled', 1 );
		diluxone_users_passkeys_enqueue();
		diluxone_users_passkeys_enqueue();

		$this->assertTrue( wp_script_is( 'diluxone-users-passkeys', 'enqueued' ) );
		$data = (string) wp_scripts()->get_data( 'diluxone-users-passkeys', 'data' );
		$this->assertSame( 1, substr_count( $data, 'diluxOneUsersPasskeys' ) );
		$this->assertStringContainsString( wp_create_nonce( 'diluxone_users_passkeys' ), $data );
		$this->assertStringContainsString( 'admin-ajax.php', $data );
	}

	public function test_managing_keys_signed_out_goes_to_the_sign_in_screen(): void {
		$url = $this->expectRedirect( 'diluxone_users_passkeys_manage' );

		$this->assertSame( diluxone_users_login_url(), $url );
	}

	public function test_managing_keys_without_the_form_nonce_changes_nothing(): void {
		$user = $this->owner();
		$this->postAs(
			$user,
			array(
				'_wpnonce'                  => 'forged',
				'diluxone_users_passkey'    => $this->id( 'a' ),
				'diluxone_users_passkey_do' => 'delete',
			)
		);

		try {
			diluxone_users_passkeys_manage();
			$this->fail( 'A forged nonce has to stop the request.' );
		} catch ( \WPAjaxDieContinueException $e ) {
			unset( $e );
		}

		$this->assertCount( 1, diluxone_users_passkeys( $user ) );
	}

	public function test_a_key_is_renamed_from_the_profile(): void {
		$user = $this->owner();
		wp_set_current_user( $user );
		$this->postAs(
			$user,
			array(
				'_wpnonce'                     => wp_create_nonce( 'diluxone_users_passkey' ),
				'diluxone_users_passkey'       => $this->id( 'a' ),
				'diluxone_users_passkey_do'    => 'rename',
				'diluxone_users_passkey_label' => 'Old phone',
			)
		);

		$url = $this->expectRedirect( 'diluxone_users_passkeys_manage' );

		$this->assertSame( 'passkeyname', $this->redirectState( $url ) );
		$this->assertSame( 'Old phone', diluxone_users_passkeys( $user )[0]['label'] );
	}

	public function test_renaming_a_key_that_is_not_yours_touches_nothing(): void {
		$owner = $this->owner();
		$other = $this->make_user();

		diluxone_users_passkey_rename( $other, $this->id( 'a' ), 'Mine now' );

		$this->assertSame( 'Phone', diluxone_users_passkeys( $owner )[0]['label'] );
		$this->assertSame( array(), diluxone_users_passkeys( $other ) );
	}

	public function test_a_key_is_removed_from_the_profile_with_its_index_and_a_notice(): void {
		$user = $this->owner();
		wp_set_current_user( $user );
		$this->postAs(
			$user,
			array(
				'_wpnonce'                  => wp_create_nonce( 'diluxone_users_passkey' ),
				'diluxone_users_passkey'    => $this->id( 'a' ),
				'diluxone_users_passkey_do' => 'delete',
			)
		);

		$url = $this->expectRedirect( 'diluxone_users_passkeys_manage' );

		$this->assertSame( 'passkeyoff', $this->redirectState( $url ) );
		$this->assertSame( array(), diluxone_users_passkeys( $user ) );
		$this->assertSame( '', get_user_meta( $user, 'diluxone_users_passkeys', true ) );
		$this->assertSame( 0, diluxone_users_passkey_owner( $this->id( 'a' ) ) );
		$this->assertStringContainsString( 'A passkey was removed.', (string) $this->lastMail()['message'] );
	}

	/**
	 * Removing a key that is somebody else's removes nothing, and says
	 * nothing was removed: no "A passkey was removed." in anybody's inbox,
	 * and no notice saying it went.
	 */
	public function test_removing_a_key_that_is_not_yours_removes_and_says_nothing(): void {
		$owner = $this->owner();
		$other = $this->make_user();

		wp_set_current_user( $other );
		$this->postAs(
			$other,
			array(
				'_wpnonce'                  => wp_create_nonce( 'diluxone_users_passkey' ),
				'diluxone_users_passkey'    => $this->id( 'a' ),
				'diluxone_users_passkey_do' => 'delete',
			)
		);

		$url = $this->expectRedirect( 'diluxone_users_passkeys_manage' );

		$this->assertSame( '', $this->redirectState( $url ) );
		$this->assertCount( 1, diluxone_users_passkeys( $owner ), 'the owner keeps it' );
		$this->assertSame( $owner, diluxone_users_passkey_owner( $this->id( 'a' ) ), 'and its index' );
		$this->assertSame( array(), self::$mail, 'nobody is told of a removal that did not happen' );
	}

	/**
	 * Signing in with a passkey on a network makes the person a member of
	 * the hub, as every door does; on a single site everybody already is.
	 */
	public function test_a_passkey_sign_in_makes_the_person_a_member_of_the_hub(): void {
		$user = $this->owner();

		if ( is_multisite() ) {
			diluxone_users_update_option( 'diluxone_users_membership', 'click' );
			diluxone_users_update_option( DILUXONE_USERS_MEMBERSHIP_CONFIRMED, 1 );
			remove_user_from_blog( $user, get_main_site_id() );
			delete_user_meta( $user, DILUXONE_USERS_MEMBERSHIP_REMOVED );
			$this->assertFalse( is_user_member_of_blog( $user, get_main_site_id() ) );
		}

		$this->assertTrue( diluxone_users_passkeys_login( $this->login_post() )['success'] );
		$this->assertTrue( is_user_member_of_blog( $user, get_main_site_id() ) );
	}

	/** A counter of nought on both sides is a key that keeps none, and is no warning. */
	public function test_a_key_that_keeps_no_counter_is_no_warning(): void {
		$this->owner( 0 );
		$this->assertTrue( diluxone_users_passkeys_login( $this->login_post( array( 'counter' => 0 ) ) )['success'] );
		$this->assertSame( array(), $this->warnings );
	}

	/** The same counter again is a warning: a copied key, perhaps. */
	public function test_a_counter_that_stands_still_is_a_warning(): void {
		$user = $this->owner( 5 );
		$this->assertTrue( diluxone_users_passkeys_login( $this->login_post( array( 'counter' => 5 ) ) )['success'] );
		$this->assertSame( array( array( $user, $this->id( 'a' ) ) ), $this->warnings, 'the same number again: a copied key, perhaps' );
	}
}
