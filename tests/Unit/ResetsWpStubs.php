<?php
/**
 * The stubs' memory, empty for every test.
 *
 * The unit suite runs in random order, and the WordPress stubs keep options,
 * transients, users and user meta in globals. A test that leaves something
 * there hands it to whichever test happens to run next, and that test passes
 * or fails for a reason that is not in it. So every test starts with nothing
 * stored, and the request it played — $_POST, $_COOKIE, $_SERVER, the screen
 * it was on — is put back after it.
 */

namespace Tests\Unit;

trait ResetsWpStubs {

	/** @var array<string, mixed> The request as the test found it. */
	private array $stub_request = array();

	/** @before */
	public function reset_wp_stubs(): void {
		$GLOBALS['_test_wp_options']              = array();
		$GLOBALS['_test_wp_transients']           = array();
		$GLOBALS['_test_wp_users']                = array();
		$GLOBALS['diluxone_users_test_user_meta'] = array();
		unset( $GLOBALS['_test_multisite'], $GLOBALS['_test_wp_url_base'] );

		$this->stub_request = array(
			'get'     => $_GET,
			'post'    => $_POST,
			'request' => $_REQUEST,
			'cookie'  => $_COOKIE,
			'server'  => $_SERVER,
			'pagenow' => $GLOBALS['pagenow'] ?? null,
		);
	}

	/** @after */
	public function restore_the_request(): void {
		$_GET     = $this->stub_request['get'];
		$_POST    = $this->stub_request['post'];
		$_REQUEST = $this->stub_request['request'];
		$_COOKIE  = $this->stub_request['cookie'];
		$_SERVER  = $this->stub_request['server'];

		if ( null === $this->stub_request['pagenow'] ) {
			unset( $GLOBALS['pagenow'] );
		} else {
			$GLOBALS['pagenow'] = $this->stub_request['pagenow'];
		}
	}
}
