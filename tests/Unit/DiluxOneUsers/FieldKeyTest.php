<?php
/**
 * Which names a field may be given.
 *
 * A field's key is the user meta key the account form writes to, with
 * whatever the person typed in the box. So the list of names that are not
 * available is not a style rule: a field called `diluxone_users_2fa_on` is a
 * box on the front end that turns somebody's second factor off, and one
 * called `wp_capabilities` is a box that edits their role.
 */

namespace Tests\Unit\DiluxOneUsers;

use Tests\Unit\ResetsWpStubs;
use Brain\Monkey;
use PHPUnit\Framework\TestCase;

class FieldKeyTest extends TestCase {

	use ResetsWpStubs;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		require_once DILUXONE_USERS_DIR . 'includes/options.php';
		require_once DILUXONE_USERS_DIR . 'includes/options-scope.php';
		require_once DILUXONE_USERS_DIR . 'includes/core-meta.php';
		require_once DILUXONE_USERS_DIR . 'includes/fields.php';

		$GLOBALS['_test_wp_options'] = array();
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	/** @dataProvider keys */
	public function test_a_key_is_allowed_or_it_is_not( string $key, bool $allowed ): void {
		$this->assertSame( $allowed, diluxone_users_field_key_allowed( $key ) );
	}

	/** @return array<string, array{string, bool}> */
	public static function keys(): array {
		return array(
			'an ordinary one'                 => array( 'diluxone_users_company', true ),
			'one of the shipped defaults'     => array( 'diluxone_users_country', true ),
			'another shipped default'         => array( 'diluxone_users_birthday', true ),
			'a name of nobody in particular'  => array( 'vat_number', true ),
			'a longer name next to a taken one' => array( 'diluxone_users_avatar_size', true ),
			'and the numbered form of one'    => array( 'diluxone_users_avatar_2', true ),
			'WordPress first name'            => array( 'first_name', true ),
			'WordPress last name'             => array( 'last_name', true ),
			'nothing at all'                  => array( '', false ),
			'the role'                        => array( 'wp_capabilities', false ),
			'any wp_ key'                     => array( 'wp_user_level', false ),
			'the session list'                => array( 'session_tokens', false ),
			'the second-factor switch'        => array( 'diluxone_users_2fa_on', false ),
			'the failure counter behind it'   => array( 'diluxone_users_2fa_fails', false ),
			'the authenticator secret'        => array( 'diluxone_users_totp', false ),
			'the backup codes'                => array( 'diluxone_users_backup_codes', false ),
			'the passkey list'                => array( 'diluxone_users_passkeys', false ),
			'a passkey index row'             => array( 'diluxone_users_pk_abc', false ),
			'a linked social account'         => array( 'diluxone_users_sso_google', false ),
			'the primary site of a network'   => array( 'primary_blog', false ),
			'the domain it signed up on'      => array( 'source_domain', false ),
			'the public name'                 => array( 'diluxone_users_handle', false ),
			'the avatar'                      => array( 'diluxone_users_avatar', false ),
			'the known devices'               => array( 'diluxone_users_devices', false ),
			'a notification switch'           => array( 'diluxone_users_notify_login', false ),
			'an edit counter'                 => array( 'diluxone_users_edits_first_name', false ),
			'the sign-in token'               => array( '_diluxone_users_link_hash', false ),
			'the closed-account flag'         => array( 'diluxone_users_closed', false ),
			'the sites a person was taken off' => array( 'diluxone_users_removed_from', false ),
		);
	}

	/**
	 * Every user meta key the plugin writes is out of a field's reach.
	 *
	 * The list above is written by hand, and a key added to the code tomorrow
	 * is not on it unless somebody remembers. So the code is read: every
	 * literal key — or constant — handed to a *_user_meta() call in the
	 * plugin is looked up, and each has to be refused as a field's name.
	 * WordPress's own first and last name are the two the plugin adopts on
	 * purpose, and the only exceptions.
	 */
	public function test_every_meta_key_the_plugin_writes_is_reserved(): void {
		$files = array_merge( (array) glob( DILUXONE_USERS_DIR . 'includes/*.php' ), array( DILUXONE_USERS_DIR . 'uninstall.php', DILUXONE_USERS_DIR . 'diluxone-users.php' ) );
		$code  = implode( "\n", array_map( static fn( $file ): string => (string) file_get_contents( (string) $file ), $files ) );

		preg_match_all( "/(?:define\\(\\s*'(DILUXONE_USERS_[A-Z0-9_]+)'\\s*,|const\\s+(DILUXONE_USERS_[A-Z0-9_]+)\\s*=)\\s*'([^']+)'/", $code, $defined, PREG_SET_ORDER );
		$constants = array();

		foreach ( $defined as $one ) {
			$constants[ '' !== $one[1] ? $one[1] : $one[2] ] = $one[3];
		}

		preg_match_all( "/(?:update|get|delete|add)_user_meta\\(\\s*[^,;]+?,\\s*(?:'([a-z0-9_]+)'|(DILUXONE_USERS_[A-Z0-9_]+))\\s*[,)]/", $code, $calls, PREG_SET_ORDER );
		$keys = array();

		foreach ( $calls as $call ) {
			$key = '' !== $call[1] ? $call[1] : ( $constants[ $call[2] ?? '' ] ?? '' );

			if ( '' !== $key && ! in_array( $key, array( 'first_name', 'last_name' ), true ) ) {
				$keys[ $key ] = true;
			}
		}

		// The scan found what it is meant to find: a scan that matches nothing
		// passes for ever.
		$this->assertArrayHasKey( 'diluxone_users_2fa_on', $keys );
		$this->assertArrayHasKey( 'diluxone_users_closed', $keys );
		$this->assertArrayHasKey( 'diluxone_users_removed_from', $keys, 'a key behind a constant' );
		$this->assertGreaterThan( 15, count( $keys ) );

		foreach ( array_keys( $keys ) as $key ) {
			$this->assertFalse( diluxone_users_field_key_allowed( (string) $key ), $key . ' is written by the plugin and could be named as a field' );
		}
	}

	public function test_a_generated_key_steps_over_a_reserved_name(): void {
		require_once DILUXONE_USERS_DIR . 'includes/admin-fields.php';

		// "Avatar" would otherwise become diluxone_users_avatar, which is
		// where the profile picture is stored.
		$key = diluxone_users_key_from( 'Avatar', array() );

		$this->assertTrue( diluxone_users_field_key_allowed( $key ) );
		$this->assertNotSame( 'diluxone_users_avatar', $key );

		// A field named "Closed" would have been the flag that keeps a closed
		// account out — a box its owner could untick.
		$this->assertSame( 'diluxone_users_closed_2', diluxone_users_key_from( 'Closed', array() ) );
	}

	public function test_a_generated_key_still_steps_over_one_already_taken(): void {
		require_once DILUXONE_USERS_DIR . 'includes/admin-fields.php';

		$this->assertSame(
			'diluxone_users_company_2',
			diluxone_users_key_from( 'Company', array( 'diluxone_users_company' ) )
		);
	}
}
