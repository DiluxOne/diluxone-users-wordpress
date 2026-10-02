<?php
/**
 * The three judgements the hub rests on, without WordPress.
 *
 * Whether an address is one to send somebody back to, whether a site is on a
 * domain of its own, and which of a site's wp-login.php requests stay on it.
 * Each is a pure function on purpose: the first is where an open redirect
 * would be, and a table of tricks pins it down better than any flow could.
 */

namespace Tests\Unit\DiluxOneUsers;

use Tests\Unit\ResetsWpStubs;
use Brain\Monkey;
use PHPUnit\Framework\TestCase;

class NetworkHubTest extends TestCase {

	use ResetsWpStubs;

	private const HOSTS = array( 'example.test', 'beta.example.test' );

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		require_once DILUXONE_USERS_DIR . 'includes/options.php';
		require_once DILUXONE_USERS_DIR . 'includes/safe-mode.php';
		require_once DILUXONE_USERS_DIR . 'includes/options-scope.php';
		require_once DILUXONE_USERS_DIR . 'includes/network-hub.php';
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * @dataProvider addressesOfTheNetwork
	 */
	public function test_an_address_on_a_site_of_the_network_is_a_way_back( string $url ): void {
		$this->assertSame( $url, diluxone_users_return_url_ok( $url, self::HOSTS ) );
	}

	public static function addressesOfTheNetwork(): array {
		return array(
			'the network\'s front page'   => array( 'https://example.test/' ),
			'a site of a subdirectory'    => array( 'https://example.test/beta/some-page/?x=1#top' ),
			'a subdomain on the list'     => array( 'http://beta.example.test/' ),
			'the host in capitals'        => array( 'https://EXAMPLE.test/beta/' ),
			'with a port'                 => array( 'http://example.test:8893/beta/' ),
			'an encoded query'            => array( 'https://example.test/beta/?q=a%20b%26c' ),
		);
	}

	/**
	 * @dataProvider addressesThatAreNot
	 */
	public function test_anything_else_is_dropped( string $url ): void {
		$this->assertSame( '', diluxone_users_return_url_ok( $url, self::HOSTS ), $url );
	}

	public static function addressesThatAreNot(): array {
		return array(
			'nothing'                          => array( '' ),
			'a foreign host'                   => array( 'https://evil.test/' ),
			'a host ending like ours'          => array( 'https://notexample.test/' ),
			'ours as a subdomain of theirs'    => array( 'https://example.test.evil.test/' ),
			'a subdomain not on the list'      => array( 'https://gamma.example.test/' ),
			'protocol-relative'                => array( '//evil.test/' ),
			'protocol-relative to ours'        => array( '//example.test/beta/' ),
			'a path alone'                     => array( '/beta/' ),
			'a scheme and one slash'           => array( 'https:/evil.test/' ),
			'a scheme and no slash'            => array( 'https:evil.test' ),
			'three slashes'                    => array( 'https:///evil.test/' ),
			'backslashes for slashes'          => array( 'https:\\\\evil.test\\' ),
			'a backslash before our host'      => array( 'https://evil.test\\@example.test/' ),
			'an encoded backslash'             => array( 'https://example.test%5C@evil.test/' ),
			'a double-encoded backslash'       => array( 'https://example.test/%255C%255Cevil.test' ),
			'a user name'                      => array( 'https://example.test@evil.test/' ),
			'a user name on our host'          => array( 'https://someone:secret@example.test/' ),
			'an encoded dot in the host'       => array( 'https://example.test%2eevil.test/' ),
			'a tab'                            => array( "https://exam\tple.test/" ),
			'a line break'                     => array( "https://example.test/\r\nLocation: https://evil.test/" ),
			'an encoded line break'            => array( 'https://example.test/%0d%0aLocation:%20https://evil.test/' ),
			'a space'                          => array( 'https://example.test/be ta/' ),
			'javascript'                       => array( 'javascript:alert(1)' ),
			'data'                             => array( 'data:text/html,<script>alert(1)</script>' ),
			'another scheme'                   => array( 'ftp://example.test/' ),
			'our host only in the query'       => array( 'https://evil.test/?next=https://example.test/' ),
			'a trailing dot'                   => array( 'https://example.test./' ),
			'far too long'                     => array( 'https://example.test/' . str_repeat( 'a', 2100 ) ),
		);
	}

	public function test_no_hosts_means_no_way_back(): void {
		$this->assertSame( '', diluxone_users_return_url_ok( 'https://example.test/', array() ) );
	}

	/**
	 * @dataProvider domains
	 */
	public function test_a_domain_of_its_own_is_told_from_the_networks( string $domain, string $network, bool $mapped ): void {
		$this->assertSame( $mapped, diluxone_users_domain_is_mapped( $domain, $network ), "{$domain} on {$network}" );
	}

	public static function domains(): array {
		return array(
			'the network\'s own'           => array( 'example.test', 'example.test', false ),
			'a subdomain'                  => array( 'beta.example.test', 'example.test', false ),
			'a subdomain of a subdomain'   => array( 'a.b.example.test', 'example.test', false ),
			'in capitals'                  => array( 'BETA.Example.Test', 'example.test', false ),
			'with the network\'s port'     => array( 'beta.localhost:8893', 'localhost:8893', false ),
			'the network with its port'    => array( 'localhost:8893', 'localhost:8893', false ),
			'another domain'               => array( 'other.test', 'example.test', true ),
			'another domain, same port'    => array( 'gamma.test:8893', 'localhost:8893', true ),
			'ending like ours, no dot'     => array( 'notexample.test', 'example.test', true ),
			'ours with something after it' => array( 'example.test.evil', 'example.test', true ),
			'nothing'                      => array( '', 'example.test', false ),
		);
	}

	/**
	 * @dataProvider wpLoginRequests
	 */
	public function test_which_wp_login_requests_stay_on_the_site( string $action, array $query, string $method, bool $stays ): void {
		$this->assertSame( $stays, diluxone_users_wp_login_stays( $action, $query, $method ), "{$method} action={$action}" );
	}

	public static function wpLoginRequests(): array {
		return array(
			'signing in'                 => array( '', array(), 'GET', false ),
			'signing in, by name'        => array( 'login', array(), 'GET', false ),
			'registering'                => array( 'register', array(), 'GET', false ),
			'asking for a reset'         => array( 'lostpassword', array(), 'GET', false ),
			'the "check your e-mail"'    => array( 'checkemail', array(), 'GET', false ),
			'an unknown action'          => array( 'something-else', array(), 'GET', false ),
			'HEAD is a GET'              => array( '', array(), 'HEAD', false ),
			'logging out'                => array( 'logout', array(), 'GET', true ),
			'a protected post'           => array( 'postpass', array(), 'GET', true ),
			'a personal-data request'    => array( 'confirmaction', array(), 'GET', true ),
			'the end of a reset'         => array( 'rp', array(), 'GET', true ),
			'the new password'           => array( 'resetpass', array(), 'GET', true ),
			'recovery mode'              => array( 'enter_recovery_mode', array(), 'GET', true ),
			'the admin e-mail check'     => array( 'confirm_admin_email', array(), 'GET', true ),
			'the emergency door'         => array( '', array( 'diluxone-users-admin' => '1' ), 'GET', true ),
			'the dashboard\'s interim'   => array( '', array( 'interim-login' => '1' ), 'GET', true ),
			'a password posted'          => array( '', array(), 'POST', true ),
			'anything posted'            => array( 'register', array(), 'POST', true ),
		);
	}
}
