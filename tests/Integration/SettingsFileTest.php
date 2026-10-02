<?php
/**
 * An export file, put back in.
 *
 * The round trip used to lose the account area: every value inside a
 * section's configuration went through the path meant for lists of keys and
 * came back as a word. It also carried the wipe-on-uninstall decision from
 * one site to another, and accepted a field keyed like some other plugin's
 * data.
 */

namespace Tests\Integration;

class SettingsFileTest extends IntegrationTestCase {

	protected function tearDown(): void {
		diluxone_users_delete_option( 'diluxone_users_uninstall_wipe' );

		parent::tearDown();
	}

	public function test_the_account_sections_survive_the_round_trip(): void {
		$sections = array(
			'home'   => array(
				'position'   => 10,
				'label'      => 'Start',
				'slug'       => 'home',
				'intro'      => 'Everything of yours.',
				'content'    => '<p>Hello <a href="https://example.test/">there</a></p>',
				'placement'  => 'after',
				'roles'      => array( 'subscriber' ),
				'visibility' => 'all',
			),
			'extra' => array(
				'position' => 70,
				'label'    => 'Courses',
				'custom'   => true,
				'content'  => '<p>Yours</p>',
			),
		);
		diluxone_users_update_option( 'diluxone_users_account_sections', $sections );

		$file = diluxone_users_tool_settings();
		diluxone_users_delete_option( 'diluxone_users_account_sections' );

		diluxone_users_tool_restore( $file );

		$this->assertSame( $sections, diluxone_users_raw_get( 'diluxone_users_account_sections' ) );
	}

	public function test_the_wipe_on_uninstall_does_not_travel(): void {
		diluxone_users_update_option( 'diluxone_users_uninstall_wipe', 1 );
		$file = diluxone_users_tool_settings();

		$this->assertArrayNotHasKey( 'diluxone_users_uninstall_wipe', $file );

		diluxone_users_delete_option( 'diluxone_users_uninstall_wipe' );
		diluxone_users_tool_restore( array( 'diluxone_users_uninstall_wipe' => 1 ) );

		$this->assertFalse( diluxone_users_raw_get( 'diluxone_users_uninstall_wipe' ) );
	}

	/**
	 * A file edited by hand can hold a list where a field's word belongs. A
	 * type sent as a list was a TypeError — a fatal error on import — and a
	 * key sent as a list became a field keyed `array`. Each is no word: the
	 * type is a text field's, the key is none and that row is not imported.
	 */
	public function test_a_field_with_a_list_for_a_word_does_not_break_the_import(): void {
		$this->in_network_admin();

		$warnings = array();
		set_error_handler(
			static function ( int $level, string $message ) use ( &$warnings ): bool {
				$warnings[] = $message;

				return true;
			}
		);

		try {
			$written = diluxone_users_tool_restore(
				array(
					'diluxone_users_fields' => array(
						array(
							'key'     => 'diluxone_users_x',
							'label'   => 'X',
							'type'    => array( 'a' ),
							'options' => array( 'one', array( 'two' ) ),
						),
						array(
							'key'   => array( 'k' ),
							'label' => 'Y',
						),
						'not-a-field',
					),
				)
			);
		} finally {
			restore_error_handler();
		}

		$this->assertSame( array(), $warnings );
		$this->assertSame( 1, $written );

		$fields = diluxone_users_fields( '', false );

		$this->assertSame( array( 'diluxone_users_x' ), wp_list_pluck( $fields, 'key' ) );
		$this->assertSame( 'text', $fields[0]['type'] );
		$this->assertSame( array( 'one' ), $fields[0]['options'] );
	}

	public function test_a_field_keyed_like_another_plugins_data_is_not_imported(): void {
		// On a network the fields are the network's, written from Network Admin.
		$this->in_network_admin();

		diluxone_users_tool_restore(
			array(
				'diluxone_users_fields' => array(
					array(
						'key'   => 'billing_phone',
						'label' => 'Phone',
						'type'  => 'text',
					),
					array(
						'key'   => 'diluxone_users_phone',
						'label' => 'Phone',
						'type'  => 'text',
					),
				),
			)
		);

		$keys = array_column( (array) diluxone_users_raw_get( 'diluxone_users_fields' ), 'key' );

		$this->assertNotContains( 'billing_phone', $keys );
		$this->assertContains( 'diluxone_users_phone', $keys );
	}

	/**
	 * On a single site everything is the site's, the second step included: the
	 * file hands it over and restores it.
	 */
	public function test_a_file_restored_on_a_single_site_restores_everything(): void {
		if ( is_multisite() ) {
			$this->markTestSkipped( 'Needs a single site: on a network the file leaves the network’s settings alone.' );
		}

		diluxone_users_update_option( 'diluxone_users_2fa_mode', 'required' );

		$this->assertArrayHasKey( 'diluxone_users_2fa_mode', diluxone_users_tool_settings() );

		diluxone_users_tool_restore(
			array(
				'diluxone_users_2fa_mode'    => 'off',
				'diluxone_users_login_title' => 'From the file',
			)
		);

		$this->assertSame( 'off', diluxone_users_raw_get( 'diluxone_users_2fa_mode' ) );
		$this->assertSame( 'From the file', diluxone_users_raw_get( 'diluxone_users_login_title' ) );
	}

	/**
	 * On a site of a network, a file restores what that site sets and nothing
	 * of the network's: a site administrator with a file cannot switch the
	 * second step off for everybody.
	 */
	public function test_a_file_restored_on_a_site_of_a_network_leaves_the_networks_settings_alone(): void {
		if ( ! is_multisite() ) {
			$this->markTestSkipped( 'Needs a multisite network: run `make env-multisite` first.' );
		}

		diluxone_users_update_option( 'diluxone_users_2fa_mode', 'required' );

		diluxone_users_tool_restore(
			array(
				'diluxone_users_2fa_mode'    => 'off',
				'diluxone_users_login_title' => 'From the file',
			)
		);

		$this->assertSame( 'required', diluxone_users_raw_get( 'diluxone_users_2fa_mode' ) );
		$this->assertSame( 'From the file', diluxone_users_raw_get( 'diluxone_users_login_title' ) );
		$this->assertArrayNotHasKey( 'diluxone_users_2fa_mode', diluxone_users_tool_settings(), 'Nor does it hand them over' );
	}

	/** A setting the site never set travels as nothing, and stays a default. */
	public function test_a_default_is_not_written_back_as_a_setting(): void {
		diluxone_users_delete_option( 'diluxone_users_color_map' );

		$file = diluxone_users_tool_settings();
		$this->assertArrayNotHasKey( 'diluxone_users_color_map', $file );

		diluxone_users_tool_restore( $file );
		$this->assertNull( diluxone_users_raw_get( 'diluxone_users_color_map', null ) );
	}

	/* ── What no other test asked ─────────────────────────────────── */

	/**
	 * The file names the plugin, its version, the site and when, and holds
	 * the settings; fed back in unchanged it changes nothing.
	 */
	public function test_the_file_says_what_it_is_and_round_trips(): void {
		$this->in_network_admin();
		diluxone_users_update_option( 'diluxone_users_login_title', 'Round trip' );

		$payload = diluxone_users_tool_export_payload();

		$this->assertSame( 'diluxone-users', $payload['plugin'] );
		$this->assertSame( DILUXONE_USERS_VERSION, $payload['version'] );
		$this->assertSame( home_url(), $payload['site'] );
		$this->assertNotFalse( \DateTimeImmutable::createFromFormat( \DATE_ATOM, $payload['exported'] ) );
		$this->assertSame( diluxone_users_tool_settings(), $payload['settings'] );

		$before = diluxone_users_tool_settings();
		diluxone_users_tool_restore( (array) json_decode( (string) wp_json_encode( $payload ), true )['settings'] );
		$this->assertSame( $before, diluxone_users_tool_settings() );
	}

	/** The social sign-in credentials never travel in a file, out or in. */
	public function test_the_social_credentials_never_travel(): void {
		$this->in_network_admin();
		diluxone_users_update_option( 'diluxone_users_sso', array( 'google' => array( 'id' => 'id', 'secret' => 's3cr3t' ) ) );

		$this->assertArrayNotHasKey( 'diluxone_users_sso', diluxone_users_tool_settings() );
		$this->assertStringNotContainsString( 's3cr3t', (string) wp_json_encode( diluxone_users_tool_export_payload() ) );

		diluxone_users_delete_option( 'diluxone_users_sso' );
		$this->assertSame( 0, diluxone_users_tool_restore( array( 'diluxone_users_sso' => array( 'google' => array( 'secret' => 'evil' ) ) ) ) );
		$this->assertFalse( diluxone_users_raw_get( 'diluxone_users_sso' ) );
	}

	/** A file cannot make the client's address come from a header nobody listed. */
	public function test_a_file_cannot_point_the_clients_address_at_any_header(): void {
		$this->in_network_admin();
		diluxone_users_tool_restore(
			array(
				'diluxone_users_ip_header'      => 'HTTP_X_EVIL',
				'diluxone_users_trusted_proxies' => '0.0.0.0/0',
			)
		);

		$this->assertSame( '', diluxone_users_ip_header() );

		$_SERVER['REMOTE_ADDR'] = '203.0.113.9';
		$_SERVER['HTTP_X_EVIL'] = '1.2.3.4';
		$this->assertSame( '203.0.113.9', diluxone_users_client_ip() );
	}

	/** What counts as written: settings there are, under names that are names. */
	public function test_only_settings_there_are_are_written_and_counted(): void {
		// The hub's settings, written where the hub writes them: its own dashboard.

		$written = diluxone_users_tool_restore(
			array(
				'diluxone_users_style_radius' => '8',
				'not_ours'                    => 1,
				0                             => 'x',
				'diluxone_users_login_title'  => 'Counted',
			)
		);

		$this->assertSame( 2, $written );
		$this->assertFalse( get_option( 'not_ours' ) );
		$this->assertSame( 'Counted', diluxone_users_raw_get( 'diluxone_users_login_title' ) );
	}

	/** Sections that are not a map are no sections. */
	public function test_sections_that_are_not_a_map_are_none(): void {
		// The hub's settings, written where the hub writes them: its own dashboard.
		diluxone_users_tool_restore( array( 'diluxone_users_account_sections' => 'x' ) );

		$this->assertSame( array(), diluxone_users_raw_get( 'diluxone_users_account_sections' ) );
	}

	/** @return array<string, array{0: string, 1: callable, 2: mixed}> */
	public static function enums(): array {
		return array(
			'the sign-in shape'   => array( 'diluxone_users_login_template', 'diluxone_users_login_template', 'plain' ),
			'the account shape'   => array( 'diluxone_users_account_template', 'diluxone_users_account_template', 'plain' ),
			'the menu style'      => array( 'diluxone_users_account_nav_style', 'diluxone_users_account_nav_style', 'pills' ),
			'the menu alignment'  => array( 'diluxone_users_account_nav_align', 'diluxone_users_account_nav_align', 'start' ),
			'the cover'           => array( 'diluxone_users_account_cover_kind', 'diluxone_users_account_cover_kind', 'color' ),
		);
	}

	/**
	 * A file skips the screens' own lists, so an answer that is none of
	 * theirs can be stored: it is read back as the default.
	 *
	 * @dataProvider enums
	 */
	public function test_an_answer_restored_with_nonsense_reads_its_default( string $key, string $reader, string $default ): void {
		// The hub's settings, written where the hub writes them: its own dashboard.
		diluxone_users_tool_restore( array( $key => '<b>bogus' ) );

		$this->assertSame( $default, call_user_func( $reader ) );
	}

	/** The import from a file: too big, not JSON, no settings, and one that restores. */
	public function test_a_file_is_refused_or_restored_and_says_which(): void {
		// The hub's settings, written where the hub writes them: its own dashboard.
		$path = (string) tempnam( sys_get_temp_dir(), 'diluxone-users-import' );

		try {
			file_put_contents( $path, str_repeat( ' ', MB_IN_BYTES + 1 ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions
			$this->assertSame( 'error', diluxone_users_tool_import_file( $path )[1] );
			$this->assertStringContainsString( 'too big', diluxone_users_tool_import_file( $path )[0] );

			foreach ( array( 'not json', '{"settings":"x"}' ) as $text ) {
				file_put_contents( $path, $text ); // phpcs:ignore WordPress.WP.AlternativeFunctions
				$this->assertSame( array( 'That file is not a DiluxOne Users+ export.', 'error' ), diluxone_users_tool_import_file( $path ), $text );
			}

			file_put_contents( $path, '{"settings":{"diluxone_users_style_radius":"9"}}' ); // phpcs:ignore WordPress.WP.AlternativeFunctions
			$this->assertSame( array( '1 setting restored.', 'success' ), diluxone_users_tool_import_file( $path ) );
			$this->assertSame( '9', (string) diluxone_users_raw_get( 'diluxone_users_style_radius' ) );
		} finally {
			wp_delete_file( $path );
		}
	}

	/**
	 * On a site of a network that is not the hub, a file writes nothing of
	 * the network's and nothing of the hub's, whichever key it names.
	 */
	public function test_a_file_restored_on_another_site_writes_nothing_of_the_networks_or_the_hubs(): void {
		if ( ! is_multisite() ) {
			$this->markTestSkipped( 'A network case; on a single site the counterpart is test_a_file_restored_on_a_single_site_restores_everything.' );
		}

		$site = (int) wp_insert_site(
			array(
				'domain' => (string) get_network()->domain,
				'path'   => '/file-' . strtolower( wp_generate_password( 6, false ) ) . '/',
			)
		);

		$file   = array();
		$before = array();

		foreach ( diluxone_users_option_scopes() as $key => $scope ) {
			if ( 'site' !== $scope ) {
				$file[ $key ]   = is_array( diluxone_users_option_defaults()[ $key ] ?? '' ) ? array( 'x' ) : 'x';
				$before[ $key ] = diluxone_users_raw_get( $key, 'unset' );
			}
		}

		$this->assertGreaterThan( 50, count( $file ), 'every key of the network and the hub' );

		switch_to_blog( $site );

		try {
			$written = diluxone_users_tool_restore( $file );
		} finally {
			restore_current_blog();
		}

		$this->assertSame( 0, $written );

		foreach ( $before as $key => $was ) {
			$this->assertSame( $was, diluxone_users_raw_get( $key, 'unset' ), $key );
		}
	}

}
