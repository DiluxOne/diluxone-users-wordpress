<?php
/**
 * Base for the tests that need a real WordPress loaded.
 *
 * The plugin has no tables of its own — its data is options, transients and
 * user meta — so those are what is isolated between tests. And its handlers
 * all end the same way, in a redirect and an exit, or in a mail, or in a
 * cookie: each of those is caught here so a test can look at it.
 */

namespace Tests\Integration;

use PHPUnit\Framework\TestCase;
use Tests\Integration\Support\RedirectException;

class IntegrationTestCase extends TestCase {

	/**
	 * Options the plugin writes that have no entry among its defaults.
	 *
	 * Everything in diluxone_users_option_defaults() is cleaned up as well,
	 * read from the function so an option added tomorrow is covered the day
	 * it is added.
	 *
	 * @var array<int, string>
	 */
	protected static array $options = array(
		'diluxone_users_fields',
		'diluxone_users_account_sections',
		'diluxone_users_sso',
		'diluxone_users_mail_last',
	);

	/** @var array<int, array<string, mixed>> Every e-mail the plugin tried to send, oldest first. */
	protected static array $mail = array();

	/** @var array<string, array{value: string, options: array<string, mixed>}> Every cookie the plugin tried to set, by name. */
	protected static array $cookies = array();

	/** @var string|null The network's registration setting before the test, on a network. */
	private ?string $network_registration = null;

	protected function setUp(): void {
		parent::setUp();

		foreach ( array_merge( self::$options, array_keys( diluxone_users_option_defaults() ) ) as $option ) {
			delete_option( $option );
		}

		self::forget_transients();

		self::$mail    = array();
		self::$cookies = array();

		$_GET     = array();
		$_POST    = array();
		$_REQUEST = array();
		$_COOKIE  = array();

		$_SERVER['REQUEST_METHOD'] = 'GET';
		$_SERVER['REMOTE_ADDR']    = '203.0.113.1';

		wp_set_current_user( 0 );

		// On a network, whether accounts may be created is the network's
		// decision, and a freshly converted one says no. The tests about
		// creating accounts are about the site's own switches, so the network
		// is opened for them; MultisiteTest closes it where that is the point.
		if ( is_multisite() ) {
			$this->network_registration = (string) get_site_option( 'registration', 'none' );
			update_site_option( 'registration', 'user' );
		}

		add_filter( 'wp_redirect', array( $this, 'throw_redirect' ), 1 );
		add_filter( 'pre_wp_mail', array( $this, 'catch_mail' ), 10, 2 );
		add_filter( 'diluxone_users_cookie', array( $this, 'catch_cookie' ), 10, 3 );
		// The CLI has no headers to send: WordPress's own auth cookie is not
		// written, and the session lives in wp_set_current_user() alone.
		add_filter( 'send_auth_cookies', '__return_false' );
	}

	protected function tearDown(): void {
		remove_filter( 'wp_redirect', array( $this, 'throw_redirect' ), 1 );
		remove_filter( 'pre_wp_mail', array( $this, 'catch_mail' ), 10 );
		remove_filter( 'diluxone_users_cookie', array( $this, 'catch_cookie' ), 10 );
		remove_filter( 'send_auth_cookies', '__return_false' );

		wp_set_current_user( 0 );

		if ( null !== $this->network_registration ) {
			update_site_option( 'registration', $this->network_registration );
			$this->network_registration = null;
		}

		parent::tearDown();
	}

	/** The plugin's transients — throttles, states, challenges — gone. */
	protected static function forget_transients(): void {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '\_transient\_diluxone\_users\_%' OR option_name LIKE '\_transient\_timeout\_diluxone\_users\_%'" );
		wp_cache_flush();
	}

	/** The `wp_redirect` filter: the address is thrown instead of sent. */
	public function throw_redirect( string $location ): string {
		throw new RedirectException( $location );
	}

	/**
	 * The `pre_wp_mail` filter: the mail is kept instead of sent.
	 *
	 * @param mixed                $pre
	 * @param array<string, mixed> $atts
	 */
	public function catch_mail( $pre, array $atts ): bool {
		self::$mail[] = $atts;

		return true;
	}

	/**
	 * The `diluxone_users_cookie` filter: the cookie is kept instead of set.
	 *
	 * @param array<string, mixed> $options
	 * @return false
	 */
	public function catch_cookie( array $options, string $name, string $value ): bool {
		self::$cookies[ $name ] = array(
			'value'   => $value,
			'options' => $options,
		);

		return false;
	}

	/** Runs a handler that has to end in a redirect, and returns where to. */
	protected function expectRedirect( callable $handler ): string {
		try {
			$handler();
		} catch ( RedirectException $e ) {
			return $e->url;
		}

		$this->fail( 'A redirect was expected and none happened.' );
	}

	/** One argument out of a URL's query string, or '' when it is not there. */
	protected function queryArg( string $url, string $key ): string {
		// wp_nonce_url() escapes the ampersands for HTML; they are undone here.
		parse_str( (string) wp_parse_url( wp_specialchars_decode( $url ), PHP_URL_QUERY ), $query );

		return isset( $query[ $key ] ) && is_scalar( $query[ $key ] ) ? (string) $query[ $key ] : '';
	}

	/** The `diluxone-users` state a redirect carries: 'sent', 'expired', 'social'... */
	protected function redirectState( string $url ): string {
		return $this->queryArg( $url, 'diluxone-users' );
	}

	/**
	 * Sets up a POST request as somebody (or nobody).
	 *
	 * @param array<string, mixed> $post
	 * @param array<string, mixed> $get
	 */
	protected function postAs( ?int $user_id, array $post, array $get = array() ): void {
		wp_set_current_user( (int) $user_id );

		$_GET     = $get;
		$_POST    = $post;
		$_REQUEST = array_merge( $get, $post );

		$_SERVER['REQUEST_METHOD'] = 'POST';
	}

	/**
	 * The last e-mail the plugin tried to send.
	 *
	 * @return array<string, mixed>
	 */
	protected function lastMail(): array {
		return array() === self::$mail ? array() : self::$mail[ count( self::$mail ) - 1 ];
	}

	/** A fresh person, with whatever role is passed. */
	protected function make_user( string $role = 'subscriber' ): int {
		return (int) wp_insert_user(
			array(
				'user_login' => 'diluxone_users_' . wp_generate_password( 8, false ),
				'user_email' => wp_generate_password( 8, false ) . '@example.test',
				'user_pass'  => wp_generate_password( 16 ),
				'role'       => $role,
			)
		);
	}
}
