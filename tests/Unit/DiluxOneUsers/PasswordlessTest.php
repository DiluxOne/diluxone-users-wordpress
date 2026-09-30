<?php
/**
 * Passwordless sign-in is a product decision, not a visual detail: if
 * wp-login.php is left open, there is a second way in that the sign-in screen
 * does not show. These tests pin down what is closed and what is not.
 */

namespace Tests\Unit\DiluxOneUsers;

use Brain\Monkey;
use PHPUnit\Framework\TestCase;

class PasswordlessTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		// The module registers hooks as it loads, so it is loaded with Brain
		// Monkey active. The hooks are not stubbed in wordpress-stubs.php
		// precisely so as not to cover its own.
		Monkey\setUp();
		require_once DILUXONE_USERS_DIR . 'includes/options.php';
		require_once DILUXONE_USERS_DIR . 'includes/options-scope.php';
		require_once DILUXONE_USERS_DIR . 'includes/passwordless.php';
	}

	protected function tearDown(): void {
		unset($GLOBALS['_test_wp_options']['diluxone_users_lost_password']);
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * @dataProvider actionsThatAreClosed
	 */
	public function test_password_actions_are_redirected(string $action): void {
		$this->assertTrue(
			diluxone_users_should_redirect($action),
			"The action '{$action}' should go to the sign-in page"
		);
	}

	public static function actionsThatAreClosed(): array {
		return [
			'default login'         => [''],
			'explicit login'        => ['login'],
			'native registration'   => ['register'],
			'unknown action'        => ['something-that-does-not-exist'],
		];
	}

	/**
	 * Asking for a password reset is not the same screen as choosing the new
	 * password, and only one of the three answers is about closing the ask.
	 *
	 * It used to be closed always, which meant a site that sent people to its
	 * own screen to type a new password had taken away the only form that
	 * sends them the e-mail to get there.
	 *
	 * @dataProvider lostPasswordAnswers
	 */
	public function test_asking_for_a_reset_is_only_closed_when_nobody_resets_anything(string $answer, bool $closed): void {
		// Through the options store the stubs keep, not by redefining
		// get_option(): the stub file is loaded before Patchwork and cannot be
		// rerouted afterwards.
		$GLOBALS['_test_wp_options']['diluxone_users_lost_password'] = $answer;

		$this->assertSame(
			$closed,
			diluxone_users_should_redirect('lostpassword'),
			"With '{$answer}', asking for a reset should " . ($closed ? 'go to the sign-in page' : 'stay on WordPress\'s form')
		);
	}

	public static function lostPasswordAnswers(): array {
		return [
			"WordPress's screen"  => ['wp', false],
			"the site's screen"   => ['site', false],
			'nobody resets'       => ['link', true],
		];
	}

	/**
	 * @dataProvider actionsThatStayOpen
	 */
	public function test_flows_that_are_not_login_keep_working(string $action): void {
		$this->assertFalse(
			diluxone_users_should_redirect($action),
			"The action '{$action}' cannot be redirected: it breaks a flow WordPress needs"
		);
	}

	public static function actionsThatStayOpen(): array {
		return [
			'signing out'               => ['logout'],
			'post password'             => ['postpass'],
			'reset started by an admin' => ['rp'],
			'reset, second step'        => ['resetpass'],
			'GDPR confirmation'         => ['confirmaction'],
		];
	}

	public function test_the_escape_hatch_shows_the_native_form(): void {
		$this->assertFalse(
			diluxone_users_should_redirect('login', ['diluxone-users-admin' => '1']),
			'Without this way out, a mail or social-provider failure locks everybody out of the site'
		);
	}

	public function test_the_dashboard_interstitial_login_is_not_redirected(): void {
		// It is the modal appearing inside wp-admin when the session expires:
		// redirecting it would break the screen that opened it.
		$this->assertFalse(
			diluxone_users_should_redirect('login', ['interim-login' => '1'])
		);
	}

	public function test_the_escape_hatch_does_not_reopen_native_registration(): void {
		// diluxone-users-admin exists so an administrator can get in with their
		// password, not to re-enable sign-ups through wp-login.php.
		// This test fails if somebody moves the `register` check ahead of the
		// `diluxone-users-admin` one.
		$this->assertFalse(
			diluxone_users_should_redirect('register', ['diluxone-users-admin' => '1']),
			'The escape hatch shows the native form; native registration stays off through option_users_can_register'
		);
	}

	/**
	 * H-05: taking the screens over is not taking the endpoint away.
	 *
	 * @dataProvider postsToWpLogin
	 */
	public function test_which_posts_to_wp_login_get_through(string $action, bool $has_password, bool $allowed): void {
		$this->assertSame($allowed, diluxone_users_wp_login_post_allowed($action, $has_password));
	}

	public static function postsToWpLogin(): array {
		return [
			'the password form, password on'   => ['', true, true],
			'the same with the action spelled' => ['login', true, true],
			'the password form, link only'     => ['', false, false],
			'lost password, password on'       => ['lostpassword', true, false],
			'native registration, password on' => ['register', true, false],
			'anything unknown'                 => ['something-else', true, false],
		];
	}
}
