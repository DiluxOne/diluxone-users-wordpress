<?php
/**
 * What the plugin hands WordPress's privacy tools: the exporter and the eraser.
 *
 * Both are called the way Tools → Export/Erase Personal Data calls them, through
 * the lists WordPress builds from its filters, and what they return is read row
 * by row: what a person is entitled to see is exported, a credential is not,
 * and the eraser leaves nothing of theirs behind — except the one list it says
 * it keeps, and says why.
 */

namespace Tests\Integration;

class CoverAccountPrivacyDataTest extends IntegrationTestCase {

	/** @var array<int, array<string, mixed>> The field the tests add, through the plugin's own filter. */
	private array $extra_fields = array();

	protected function setUp(): void {
		parent::setUp();

		$this->extra_fields = array();
		add_filter( 'diluxone_users_fields', array( $this, 'add_fields' ) );
	}

	protected function tearDown(): void {
		remove_filter( 'diluxone_users_fields', array( $this, 'add_fields' ) );

		parent::tearDown();
	}

	/**
	 * The `diluxone_users_fields` filter: the test's fields on top of the site's.
	 *
	 * @param array<int, array<string, mixed>> $fields
	 * @return array<int, array<string, mixed>>
	 */
	public function add_fields( array $fields ): array {
		return array_merge( $fields, $this->extra_fields );
	}

	/** The plugin's exporter, as WordPress finds it. */
	private function exporter(): callable {
		$exporters = apply_filters( 'wp_privacy_personal_data_exporters', array() );

		$this->assertArrayHasKey( 'diluxone-users-account', $exporters );

		return $exporters['diluxone-users-account']['callback'];
	}

	/** The plugin's eraser, as WordPress finds it. */
	private function eraser(): callable {
		$erasers = apply_filters( 'wp_privacy_personal_data_erasers', array() );

		$this->assertArrayHasKey( 'diluxone-users-account', $erasers );

		return $erasers['diluxone-users-account']['callback'];
	}

	/**
	 * Every row of one group of an export, as name => value.
	 *
	 * @param array<int, array<string, mixed>> $items
	 * @return array<string, string>
	 */
	private function rows( array $items, string $item_id ): array {
		foreach ( $items as $item ) {
			if ( $item_id === $item['item_id'] ) {
				return array_column( $item['data'], 'value', 'name' );
			}
		}

		return array();
	}

	/** An attachment in this site's library, uploaded by this person, set as their picture. */
	private function give_avatar( int $user_id ): int {
		$id = (int) wp_insert_attachment(
			array(
				'post_title'     => 'avatar',
				'post_author'    => $user_id,
				'post_mime_type' => 'image/png',
				'post_status'    => 'inherit',
			),
			'avatar-' . $user_id . '.png'
		);

		update_user_meta( $user_id, 'diluxone_users_avatar', $id );
		update_user_meta( $user_id, 'diluxone_users_avatar_site', get_current_blog_id() );

		return $id;
	}

	public function test_an_address_with_no_account_exports_and_erases_nothing(): void {
		$this->assertSame(
			array(
				'data' => array(),
				'done' => true,
			),
			( $this->exporter() )( 'nobody-' . wp_generate_password( 6, false ) . '@example.test', 1 )
		);

		$this->assertSame(
			array(
				'items_removed'  => false,
				'items_retained' => false,
				'messages'       => array(),
				'done'           => true,
			),
			( $this->eraser() )( 'nobody-' . wp_generate_password( 6, false ) . '@example.test', 1 )
		);

		$erasers = apply_filters( 'wp_privacy_personal_data_erasers', array() );
		$this->assertSame(
			array(
				'items_removed'  => false,
				'items_retained' => false,
				'messages'       => array(),
				'done'           => true,
			),
			$erasers['diluxone-users-log']['callback']( 'nobody-' . wp_generate_password( 6, false ) . '@example.test', 1 ),
			'The activity log has nothing to erase for an address with no account either'
		);
	}

	public function test_an_account_with_nothing_of_the_plugins_exports_only_the_second_step_state(): void {
		$user   = $this->make_user();
		$export = ( $this->exporter() )( get_userdata( $user )->user_email, 1 );

		$this->assertTrue( $export['done'] );
		$this->assertSame( array( 'diluxone-users-access' ), array_column( $export['data'], 'item_id' ), 'No details, no passkeys: only how they sign in' );
		$this->assertSame( array( 'Two-step verification' => 'Off' ), $this->rows( $export['data'], 'diluxone-users-access' ) );
	}

	public function test_the_export_carries_the_facts_and_never_the_credentials(): void {
		$this->extra_fields = array(
			array(
				'key'   => 'diluxone_users_cover_document',
				'label' => 'Document',
				'type'  => 'text',
			),
			array(
				'key'   => 'diluxone_users_cover_empty',
				'label' => 'Left blank',
				'type'  => 'text',
			),
		);

		$user  = $this->make_user();
		$email = get_userdata( $user )->user_email;

		update_user_meta( $user, 'first_name', 'Ana' );
		update_user_meta( $user, 'diluxone_users_cover_document', '30.111.222' );
		update_user_meta( $user, 'diluxone_users_handle', 'ana_p' );
		update_user_meta( $user, 'diluxone_users_notify_login', '1' );
		update_user_meta( $user, 'diluxone_users_notify_security', '0' );
		update_user_meta( $user, 'diluxone_users_sso_google', 'google-sub-123' );
		update_user_meta( $user, 'diluxone_users_2fa_on', 1 );
		update_user_meta( $user, 'diluxone_users_totp', 'SECRETSECRETSECRET' );
		update_user_meta( $user, 'diluxone_users_devices', array( 'hash-a', 'hash-b', '' ) );
		update_user_meta( $user, DILUXONE_USERS_MEMBERSHIP_REMOVED, array( 987654 ) );
		$avatar = $this->give_avatar( $user );

		diluxone_users_passkeys_save(
			$user,
			array(
				array(
					'id'      => 'cred-one',
					'label'   => 'Laptop',
					'key'     => 'PUBLIC-KEY-BYTES',
					'created' => 1700000000,
					'used'    => 0,
				),
			)
		);

		$export = ( $this->exporter() )( $email, 1 );
		$this->assertTrue( $export['done'] );

		$details = $this->rows( $export['data'], 'diluxone-users-details' );
		$this->assertSame( '30.111.222', $details['Document'] ?? null, 'A field the site asks for' );
		$this->assertArrayNotHasKey( 'Left blank', $details, 'A blank answer is not a row' );
		$this->assertArrayNotHasKey( 'First name', $details, 'WordPress’s own exporter carries the name' );
		$this->assertSame( 'ana_p', $details['Public name'] ?? null );
		$this->assertSame( (string) wp_get_attachment_url( $avatar ), $details['Profile picture'] ?? null );
		$this->assertSame( 'Yes', $details['When somebody signs in to my account from a new device'] ?? null );
		$this->assertSame( 'No', $details['When something in my security changes'] ?? null );

		$access = $this->rows( $export['data'], 'diluxone-users-access' );
		$this->assertSame( 'Google', $access['Linked account'] ?? null, 'The network, not the id it gave' );
		$this->assertSame( 'On', $access['Two-step verification'] ?? null );
		$this->assertSame( 'Set up', $access['Authenticator app'] ?? null );
		$this->assertSame( '#987654', $access['Sites an administrator removed you from'] ?? null, 'A site that does not exist is named by its number' );
		$this->assertSame( '2', $access['Devices recognised'] ?? null, 'The count, not the hashes' );

		$key = $this->rows( $export['data'], 'diluxone-users-passkey-0' );
		$this->assertSame( 'Laptop', $key['Name'] ?? null );
		$this->assertSame( (string) wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), 1700000000 ), $key['Added'] ?? null );
		$this->assertSame( '—', $key['Last used'] ?? null, 'Never used reads as a dash, not a 1970 date' );

		$flat = (string) wp_json_encode( $export );
		$this->assertStringNotContainsString( 'SECRETSECRETSECRET', $flat, 'The TOTP secret is a key to the account, not a fact about the person' );
		$this->assertStringNotContainsString( 'PUBLIC-KEY-BYTES', $flat );
		$this->assertStringNotContainsString( 'google-sub-123', $flat );
		$this->assertStringNotContainsString( 'hash-a', $flat );
	}

	public function test_on_a_network_a_removed_site_is_named(): void {
		if ( ! is_multisite() ) {
			$this->markTestSkipped( 'A single site has no other sites; the single-site case is test_the_export_carries_the_facts_and_never_the_credentials.' );
		}

		$user = $this->make_user();
		update_user_meta( $user, DILUXONE_USERS_MEMBERSHIP_REMOVED, array( get_main_site_id() ) );

		$export = ( $this->exporter() )( get_userdata( $user )->user_email, 1 );
		$access = $this->rows( $export['data'], 'diluxone-users-access' );

		$this->assertSame( (string) get_site( get_main_site_id() )->blogname, $access['Sites an administrator removed you from'] ?? null );
	}

	public function test_the_eraser_removes_every_key_the_picture_and_the_passkeys(): void {
		$this->extra_fields = array(
			array(
				'key'   => 'diluxone_users_cover_phone',
				'label' => 'Phone',
				'type'  => 'text',
			),
		);

		$user      = $this->make_user();
		$bystander = $this->make_user();
		$email     = get_userdata( $user )->user_email;
		$meta      = array(
			'diluxone_users_cover_phone'              => '555-1234',
			'diluxone_users_edits_diluxone_users_cover_phone' => '1',
			'diluxone_users_handle'                   => 'someone',
			'diluxone_users_devices'                  => array( 'h' ),
			'diluxone_users_2fa_on'                   => '1',
			'diluxone_users_totp'                     => 'SECRET',
			'diluxone_users_backup_codes'             => array( 'x' ),
			'diluxone_users_sso_github'               => '42',
			'diluxone_users_notify_login'             => '1',
		);

		foreach ( $meta as $key => $value ) {
			update_user_meta( $user, $key, $value );
		}

		update_user_meta( $bystander, 'diluxone_users_handle', 'bystander' );

		$avatar = $this->give_avatar( $user );
		diluxone_users_passkeys_save( $user, array( array( 'id' => 'cred-erase', 'label' => 'Phone' ) ) );

		// A list among the meta (the devices, the backup codes) is read as it
		// is: no warning on the way.
		$warnings = array();
		set_error_handler(
			static function ( int $level, string $message ) use ( &$warnings ): bool {
				$warnings[] = $message;

				return true;
			},
			E_WARNING | E_NOTICE
		);

		try {
			$result = ( $this->eraser() )( $email, 1 );
		} finally {
			restore_error_handler();
		}

		$this->assertSame( array(), $warnings );
		$this->assertSame(
			array(
				'items_removed'  => true,
				'items_retained' => false,
				'messages'       => array(),
				'done'           => true,
			),
			$result
		);

		foreach ( array_keys( $meta ) as $key ) {
			$this->assertSame( '', get_user_meta( $user, $key, true ), $key . ' is erased' );
		}

		$this->assertNull( get_post( $avatar ), 'The photograph itself is deleted, not just unlinked' );
		$this->assertSame( '', get_user_meta( $user, 'diluxone_users_avatar', true ) );
		$this->assertSame( array(), diluxone_users_passkeys( $user ) );
		$this->assertSame( '', get_user_meta( $user, diluxone_users_passkey_index_key( 'cred-erase' ), true ), 'No index row still says whose credential it was' );
		$this->assertSame( 'bystander', get_user_meta( $bystander, 'diluxone_users_handle', true ), 'Only that person’s data' );
	}

	public function test_the_eraser_keeps_the_removed_sites_and_says_why(): void {
		$user = $this->make_user();
		update_user_meta( $user, DILUXONE_USERS_MEMBERSHIP_REMOVED, array( 7 ) );

		$result = ( $this->eraser() )( get_userdata( $user )->user_email, 1 );

		$this->assertFalse( $result['items_removed'], 'There was nothing else to erase' );
		$this->assertTrue( $result['items_retained'] );
		$this->assertCount( 1, $result['messages'] );
		$this->assertStringContainsString( 'keeps them from being added back', $result['messages'][0] );
		$this->assertSame( array( 7 ), diluxone_users_membership_removed( $user ) );
	}

	public function test_the_list_of_keys_is_built_from_the_fields_and_the_providers(): void {
		$this->extra_fields = array(
			array(
				'key'   => 'diluxone_users_cover_city',
				'label' => 'City',
				'type'  => 'text',
			),
		);

		$keys = diluxone_users_privacy_keys( 1 );

		$this->assertContains( 'diluxone_users_cover_city', $keys );
		$this->assertContains( 'diluxone_users_edits_diluxone_users_cover_city', $keys );
		$this->assertContains( 'diluxone_users_sso_google', $keys );
		$this->assertContains( 'diluxone_users_notify_security', $keys );
		$this->assertNotContains( 'first_name', $keys, 'WordPress’s own name is WordPress’s to erase' );
		$this->assertSame( $keys, array_values( array_unique( $keys ) ) );
	}

	public function test_the_suggested_policy_text_reaches_the_privacy_screen(): void {
		require_once ABSPATH . 'wp-admin/includes/class-wp-screen.php';
		require_once ABSPATH . 'wp-admin/includes/screen.php';
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
		require_once ABSPATH . 'wp-admin/includes/class-wp-privacy-policy-content.php';

		$property = new \ReflectionProperty( \WP_Privacy_Policy_Content::class, 'policy_content' );
		$property->setAccessible( true );
		$before = $property->getValue();

		$GLOBALS['current_screen']      = \WP_Screen::get( 'options-privacy' );
		$GLOBALS['wp_current_filter'][] = 'admin_init';

		try {
			diluxone_users_privacy_policy();
		} finally {
			array_pop( $GLOBALS['wp_current_filter'] );
		}

		$added = array_values( array_filter( (array) $property->getValue(), static fn( array $c ): bool => diluxone_users_plugin_name() === $c['plugin_name'] ) );
		$property->setValue( null, $before );

		$this->assertCount( 1, $added );
		$this->assertStringContainsString( 'What is stored in the activity log', $added[0]['policy_text'] );
		$this->assertStringContainsString( 'gravatar.com', $added[0]['policy_text'] );
		$this->assertStringNotContainsString( '<script', $added[0]['policy_text'] );
	}
}
