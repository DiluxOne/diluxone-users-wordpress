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

use Brain\Monkey;
use PHPUnit\Framework\TestCase;

class FieldKeyTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		require_once DILUXONE_USERS_DIR . 'includes/options.php';
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
			'the sign-in token'               => array( '_diluxone_users_acceso_hash', false ),
		);
	}

	public function test_a_generated_key_steps_over_a_reserved_name(): void {
		require_once DILUXONE_USERS_DIR . 'includes/admin-fields.php';

		// "Avatar" would otherwise become diluxone_users_avatar, which is
		// where the profile picture is stored.
		$key = diluxone_users_key_from( 'Avatar', array() );

		$this->assertTrue( diluxone_users_field_key_allowed( $key ) );
		$this->assertNotSame( 'diluxone_users_avatar', $key );
	}

	public function test_a_generated_key_still_steps_over_one_already_taken(): void {
		require_once DILUXONE_USERS_DIR . 'includes/admin-fields.php';

		$this->assertSame(
			'diluxone_users_company_2',
			diluxone_users_key_from( 'Company', array( 'diluxone_users_company' ) )
		);
	}
}
