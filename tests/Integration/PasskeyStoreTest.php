<?php
/**
 * M-06: a passkey finds its owner by an index, and belongs to one account.
 *
 * Before, the owner was found by opening the first five hundred lists:
 * account five hundred and one could register a key and never use it, and
 * two accounts could hold the same credential id, with only one of them
 * ever found.
 */

namespace Tests\Integration;

class PasskeyStoreTest extends IntegrationTestCase {

	/** Credential ids are the test's own: users outlive the test that made them. */
	private string $run;

	protected function setUp(): void {
		parent::setUp();

		$this->run = wp_generate_password( 8, false );
	}

	private function id( string $name ): string {
		return 'cred-' . $name . '-' . $this->run;
	}

	/** @return array<string, mixed> */
	private function key( string $id ): array {
		return array(
			'id'      => $id,
			'key'     => base64_encode( 'not-a-real-key' ),
			'alg'     => -7,
			'label'   => 'Phone',
			'created' => time(),
			'used'    => 0,
			'counter' => 0,
		);
	}

	public function test_saving_a_key_indexes_it_and_the_owner_is_found_through_the_index(): void {
		$user = $this->make_user();

		diluxone_users_passkeys_save( $user, array( $this->key( $this->id( 'a' ) ) ) );

		$this->assertSame( '1', get_user_meta( $user, diluxone_users_passkey_index_key( $this->id( 'a' ) ), true ) );
		$this->assertSame( $user, diluxone_users_passkey_owner( $this->id( 'a' ) ) );
	}

	public function test_removing_a_key_removes_its_index(): void {
		$user = $this->make_user();

		diluxone_users_passkeys_save( $user, array( $this->key( $this->id( 'a' ) ), $this->key( $this->id( 'b' ) ) ) );
		diluxone_users_passkey_forget( $user, $this->id( 'a' ) );

		$this->assertSame( '', get_user_meta( $user, diluxone_users_passkey_index_key( $this->id( 'a' ) ), true ) );
		$this->assertSame( 0, diluxone_users_passkey_owner( $this->id( 'a' ) ) );
		$this->assertSame( $user, diluxone_users_passkey_owner( $this->id( 'b' ) ) );
	}

	public function test_a_stray_index_row_opens_nothing(): void {
		$user = $this->make_user();

		update_user_meta( $user, diluxone_users_passkey_index_key( $this->id( 'x' ) ), 1 );

		$this->assertSame( 0, diluxone_users_passkey_owner( $this->id( 'x' ) ) );
	}

	public function test_the_lookup_is_the_index_and_nothing_else(): void {
		// A list written straight into the meta, with no index row beside it,
		// is a state the plugin cannot produce: every key it stores goes
		// through diluxone_users_passkeys_save(), which writes the index in
		// the same call, and 1.0.0 is the first version there is — so there
		// are no keys from before it either.
		//
		// There used to be a fallback here for exactly that state, and what
		// it really was is five hundred accounts' lists opened one at a time,
		// on an unauthenticated request, for a credential id anybody can make
		// up. So the answer is no, and this is the test that keeps it no.
		$user = $this->make_user();

		update_user_meta( $user, 'diluxone_users_passkeys', array( $this->key( $this->id( 'orphan' ) ) ) );

		$this->assertSame( 0, diluxone_users_passkey_owner( $this->id( 'orphan' ) ) );

		// Stored the way the plugin stores one, the same id is found: the
		// index is written by the save, in the same call.
		$other = $this->make_user();

		diluxone_users_passkeys_save( $other, array( $this->key( $this->id( 'proper' ) ) ) );

		$this->assertSame( $other, diluxone_users_passkey_owner( $this->id( 'proper' ) ) );
	}

	public function test_a_credential_id_belongs_to_one_account(): void {
		$first  = $this->make_user();
		$second = $this->make_user();

		diluxone_users_passkeys_save( $first, array( $this->key( $this->id( 'shared' ) ) ) );

		wp_set_current_user( $second );

		$result = diluxone_users_passkeys_register( $this->registration( $this->id( 'shared' ) ) );

		$this->assertFalse( $result['success'] );
		$this->assertSame( array(), diluxone_users_passkeys( $second ) );
		$this->assertSame( $first, diluxone_users_passkey_owner( $this->id( 'shared' ) ) );
	}

	public function test_registering_a_fresh_credential_still_works(): void {
		$user = $this->make_user();
		wp_set_current_user( $user );

		$result = diluxone_users_passkeys_register( $this->registration( $this->id( 'fresh' ) ) );

		$this->assertTrue( $result['success'], (string) ( $result['data']['message'] ?? '' ) );
		$this->assertSame( $user, diluxone_users_passkey_owner( $this->id( 'fresh' ) ) );
	}

	/**
	 * What the browser would POST to register a key: a fresh challenge, the
	 * right origin, and a public key that is really a public key.
	 *
	 * @return array<string, string>
	 */
	private function registration( string $id ): array {
		$pair = openssl_pkey_new(
			array(
				'private_key_type' => OPENSSL_KEYTYPE_EC,
				'curve_name'       => 'prime256v1',
			)
		);
		$pem  = (string) openssl_pkey_get_details( $pair )['key'];
		$der  = (string) base64_decode( (string) preg_replace( '/-----[^-]+-----|\s/', '', $pem ), true );

		$client = wp_json_encode(
			array(
				'type'      => 'webauthn.create',
				'challenge' => diluxone_users_passkey_challenge_new( 'reg' ),
				'origin'    => diluxone_users_passkey_origin(),
			)
		);

		return array(
			'id'             => $id,
			'publicKey'      => diluxone_users_b64url_encode( $der ),
			'algorithm'      => '-7',
			'clientDataJSON' => (string) $client,
			'label'          => 'Test key',
		);
	}

	/**
	 * A key works only on the domain it was made for. On a network of
	 * subdomains the list is the person's everywhere, so each site lists and
	 * counts only the keys that work on it; a key saved before the domain was
	 * recorded is listed everywhere, as it was.
	 */
	public function test_a_key_is_listed_where_it_works(): void {
		$user = $this->make_user();

		diluxone_users_passkeys_save(
			$user,
			array(
				array(
					'id'  => 'here-' . $user,
					'rp'  => diluxone_users_passkey_rp_id(),
				),
				array(
					'id'  => 'elsewhere-' . $user,
					'rp'  => 'other.example.test',
				),
				array(
					'id'  => 'unknown-' . $user,
				),
			)
		);

		$ids = array_column( diluxone_users_passkeys_here( $user ), 'id' );

		$this->assertSame( array( 'here-' . $user, 'unknown-' . $user ), $ids );
	}

	/**
	 * What the browser sends is sanitised where it is read: base64url kept to
	 * its alphabet, the client data decoded from it byte for byte, the
	 * algorithm a number, the label a line of text, and a list read as nothing.
	 */
	public function test_what_the_browser_sends_is_sanitised_where_it_is_read(): void {
		$client = '{"type":"webauthn.get","challenge":"abc","origin":"https://example.test"}';

		$_POST = array(
			'id'             => 'AbC-_9+/= x',
			'clientDataJSON' => diluxone_users_b64url_encode( $client ),
			'signature'      => array( 'x' ),
			'algorithm'      => '-7; drop',
			'label'          => '<b>My key</b>',
		);

		$this->assertSame( array(), diluxone_users_passkeys_posted(), 'Without the dialogue\'s nonce nothing was sent' );

		$_REQUEST['nonce'] = wp_create_nonce( 'diluxone_users_passkeys' );

		$sent = diluxone_users_passkeys_posted();
		$_POST = array();

		$this->assertSame( 'AbC-_9x', $sent['id'] );
		$this->assertSame( $client, $sent['clientDataJSON'], 'byte for byte' );
		$this->assertArrayNotHasKey( 'signature', $sent );
		$this->assertSame( '-7', $sent['algorithm'] );
		$this->assertSame( 'My key', $sent['label'] );
	}
}
