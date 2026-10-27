<?php
/**
 * Issue #170: the account area's sign-in button loses the way back.
 *
 * The expectation: signed out on /perfil/datos/, the "Sign in" button of the
 * door points to the sign-in with a `redirect_to` that is the address asked
 * for, on the same site, so that signing in lands on /perfil/datos/ and not on
 * the home page.
 */

namespace Tests\Unit\Repro;

use Tests\Unit\ResetsWpStubs;
use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;

class Issue170Test extends TestCase {

	use ResetsWpStubs;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		require_once DILUXONE_USERS_DIR . 'includes/options.php';
		require_once DILUXONE_USERS_DIR . 'includes/options-scope.php';
		require_once DILUXONE_USERS_DIR . 'includes/safe-mode.php';
		require_once DILUXONE_USERS_DIR . 'includes/multisite.php';
		require_once DILUXONE_USERS_DIR . 'includes/network-hub.php';
		require_once DILUXONE_USERS_DIR . 'includes/login.php';
		require_once DILUXONE_USERS_DIR . 'includes/login-door.php';
		require_once DILUXONE_USERS_DIR . 'includes/templates.php';
		require_once DILUXONE_USERS_DIR . 'includes/account.php';

		// WordPress, which the unit bootstrap does not stub.
		Functions\when( 'wp_login_url' )->justReturn( 'http://localhost/wp-login.php' );
		Functions\when( 'wp_doing_ajax' )->justReturn( false );
		Functions\when( 'set_url_scheme' )->returnArg( 1 );
		Functions\when( 'wp_validate_redirect' )->returnArg( 1 );
		Functions\when( 'get_stylesheet_directory' )->justReturn( sys_get_temp_dir() . '/diluxone-users-no-theme' );
		Functions\when( 'get_template_directory' )->justReturn( sys_get_temp_dir() . '/diluxone-users-no-theme' );
		Functions\when( 'esc_html_e' )->alias(
			static function ( string $text ): void {
				echo esc_html( $text );
			}
		);

		// A signed-out visitor asking for /perfil/datos/ on a single site.
		$_SERVER['REQUEST_METHOD'] = 'GET';
		$_SERVER['HTTP_HOST']      = 'localhost';
		$_SERVER['REQUEST_URI']    = '/perfil/datos/';
		unset( $GLOBALS['pagenow'] );
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_the_door_of_the_account_area_sends_the_visitor_back_to_the_page_they_asked_for(): void {
		ob_start();
		diluxone_users_account_area();
		$html = (string) ob_get_clean();

		$this->assertSame( 1, preg_match( '/<a class="diluxone-users-button" href="([^"]*)">/', $html, $found ), 'the door draws a Sign in button' );

		$href = html_entity_decode( $found[1] );
		$this->assertStringStartsWith( 'http://localhost/wp-login.php', $href, 'it leads to the sign-in' );

		parse_str( (string) wp_parse_url( $href, PHP_URL_QUERY ), $query );

		$this->assertArrayHasKey( 'redirect_to', $query, 'the button carries the address that was asked for' );

		$back = (string) $query['redirect_to'];

		// The address may have been encoded once more on the way.
		if ( false === strpos( $back, '://' ) ) {
			$back = rawurldecode( $back );
		}

		$this->assertSame( 'localhost', wp_parse_url( $back, PHP_URL_HOST ), 'on the same site' );
		$this->assertSame( '/perfil/datos/', wp_parse_url( $back, PHP_URL_PATH ), 'on the page and tab they asked for' );
	}
}
