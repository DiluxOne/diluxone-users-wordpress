<?php
/**
 * Issue #191: the sign-in page forgets the `redirect_to` it was opened with.
 *
 * The expectation: the account area's "Sign in" button opens the sign-in page
 * with `redirect_to` set to the page the person asked for, on the same site.
 * The e-mail link form keeps it (a hidden `redirect_to`, so the request that
 * mails the link can keep it with the token), and the password form lands
 * there too, instead of on the home page.
 */

namespace Tests\Unit\Repro;

use Tests\Unit\ResetsWpStubs;
use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;

class Issue191Test extends TestCase {

	use ResetsWpStubs;

	private const WANTED = 'http://localhost/perfil/datos/';

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		require_once DILUXONE_USERS_DIR . 'includes/options.php';
		require_once DILUXONE_USERS_DIR . 'includes/options-scope.php';
		require_once DILUXONE_USERS_DIR . 'includes/handle.php';
		require_once DILUXONE_USERS_DIR . 'includes/login.php';
		require_once DILUXONE_USERS_DIR . 'includes/login-ways.php';
		require_once DILUXONE_USERS_DIR . 'includes/templates.php';

		// WordPress, which the unit bootstrap does not stub.
		Functions\when( 'wp_validate_redirect' )->returnArg( 1 );
		Functions\when( 'admin_url' )->alias(
			static function ( string $path = '' ): string {
				return 'http://localhost/wp-admin/' . $path;
			}
		);
		Functions\when( 'wp_nonce_field' )->justReturn( '' );
		Functions\when( 'wp_kses' )->returnArg( 1 );
		Functions\when( 'wp_kses_allowed_html' )->justReturn( array( 'a' => array(), 'img' => array() ) );
		Functions\when( 'wp_lostpassword_url' )->justReturn( 'http://localhost/wp-login.php?action=lostpassword' );
		Functions\when( 'esc_attr_x' )->returnArg( 1 );
		Functions\when( 'esc_html_e' )->alias(
			static function ( string $text ): void {
				echo esc_html( $text );
			}
		);

		// The sign-in page opened by the account area's "Sign in" button.
		$_GET['redirect_to']     = self::WANTED;
		$_REQUEST['redirect_to'] = self::WANTED;
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_the_e_mail_link_form_carries_the_address_the_sign_in_was_opened_with(): void {
		ob_start();
		diluxone_users_way_email();
		$html = (string) ob_get_clean();

		$this->assertSame( 1, preg_match( '/<input[^>]*name="redirect_to"[^>]*>/', $html, $found ), 'the form has a hidden redirect_to' );
		$this->assertSame( 1, preg_match( '/value="([^"]*)"/', $found[0], $value ), 'and it has a value' );
		$this->assertSame( self::WANTED, html_entity_decode( $value[1] ), 'which is the address asked for' );
	}

	public function test_the_password_form_lands_on_the_address_the_sign_in_was_opened_with(): void {
		$given = null;

		Functions\when( 'wp_login_form' )->alias(
			static function ( array $args ) use ( &$given ) {
				$given = $args;
				return '';
			}
		);

		ob_start();
		diluxone_users_way_password();
		ob_end_clean();

		$this->assertIsArray( $given, 'the password form is drawn' );
		$this->assertSame( self::WANTED, $given['redirect'] ?? null, 'it sends the person to the address asked for, not the home page' );
	}
}
