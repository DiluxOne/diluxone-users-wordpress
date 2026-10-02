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
		'diluxone_users_membership_queue',
	);

	/** @var array<int, array<string, mixed>> Every e-mail the plugin tried to send, oldest first. */
	protected static array $mail = array();

	/** @var array<string, array{value: string, options: array<string, mixed>}> Every cookie the plugin tried to set, by name. */
	protected static array $cookies = array();

	/** @var string|null The network's registration setting before the test, on a network. */
	private ?string $network_registration = null;

	/** @var int The highest user ID before the test: every account above it was made by the test. */
	private int $users_before = 0;

	/** @var int The highest site ID before the test, on a network: every site above it was made by the test. */
	private int $sites_before = 0;

	/** @var array<int, int> The highest post ID before the test, by site: every post above it was made by the test. */
	private array $posts_before = array();

	/** @var int The highest row of the activity log before the test: every row above it was written by the test. */
	private int $log_before = 0;

	/**
	 * Where the database stood before the test, taken before any setUp().
	 *
	 * An `@before` method runs ahead of setUp(), the subclasses' included, so
	 * a user or a site a test case makes in its own setUp() is counted as the
	 * test's too.
	 *
	 * @before
	 */
	public function remember_the_database(): void {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$this->users_before = (int) $wpdb->get_var( "SELECT MAX(ID) FROM {$wpdb->users}" );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$this->sites_before = is_multisite() ? (int) $wpdb->get_var( "SELECT MAX(blog_id) FROM {$wpdb->blogs}" ) : 0;

		$this->log_before = diluxone_users_log_table_exists()
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			? (int) $wpdb->get_var( 'SELECT MAX(id) FROM ' . diluxone_users_log_table() )
			: 0;

		// Posts are per site: privacy requests, pages, menu items. Read from
		// each site's own table, without switching to it.
		$this->posts_before = array();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$sites = is_multisite() ? array_map( 'intval', (array) $wpdb->get_col( "SELECT blog_id FROM {$wpdb->blogs}" ) ) : array( get_current_blog_id() );

		foreach ( $sites as $site ) {
			$posts = $wpdb->get_blog_prefix( $site ) . 'posts';

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$this->posts_before[ $site ] = (int) $wpdb->get_var( "SELECT MAX(ID) FROM {$posts}" );
		}
	}

	/**
	 * Every user and site the test made, gone: the database as it was found.
	 *
	 * By ID, not by a list the helpers keep, so the accounts the code under
	 * test creates (a registration, a social sign-in) go too. Nothing at or
	 * below the mark is touched: the administrator and whatever the
	 * environment seeded stay. A test that already deleted what it made is
	 * fine: only what is still there is deleted. An `@after` method runs after
	 * every tearDown(), so the subclasses have closed their own sites first.
	 * Posts go per site, for the sites there were before; a site the test
	 * made goes whole, with its posts.
	 *
	 * @after
	 */
	public function forget_what_the_test_made(): void {
		global $wpdb;

		self::delete_posts_above( $this->posts_before );
		self::delete_users_above( $this->users_before );
		self::delete_sites_above( $this->sites_before );

		// The rows the test wrote in the activity log, and what it left
		// queued: the suite shares its database with the network's screenshot
		// run, where a job left behind for a site that no longer exists shows
		// up as a sync "in progress".
		if ( diluxone_users_log_table_exists() ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$wpdb->query( $wpdb->prepare( 'DELETE FROM ' . diluxone_users_log_table() . ' WHERE id > %d', $this->log_before ) );
		}

		foreach ( self::$options as $option ) {
			delete_option( $option );

			if ( is_multisite() ) {
				delete_site_option( $option );
			}
		}
	}

	/**
	 * Every post above its site's mark deleted, for good, with its meta.
	 *
	 * @param array<int, int> $marks The highest post ID, by site.
	 */
	protected static function delete_posts_above( array $marks ): void {
		global $wpdb;

		foreach ( $marks as $site => $mark ) {
			// A site the test deleted took its posts with it.
			if ( is_multisite() && null === get_site( $site ) ) {
				continue;
			}

			$posts = $wpdb->get_blog_prefix( $site ) . 'posts';

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$ids = array_map( 'intval', (array) $wpdb->get_col( $wpdb->prepare( "SELECT ID FROM {$posts} WHERE ID > %d", $mark ) ) );

			if ( array() === $ids ) {
				continue;
			}

			$switched = is_multisite() && get_current_blog_id() !== $site;

			if ( $switched ) {
				switch_to_blog( $site );
			}

			foreach ( $ids as $id ) {
				wp_delete_post( $id, true );
			}

			if ( $switched ) {
				restore_current_blog();
			}
		}

		wp_cache_flush();
	}

	/** Every user with an ID above the mark, and their meta, deleted. */
	protected static function delete_users_above( int $mark ): void {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$ids = array_map( 'intval', (array) $wpdb->get_col( $wpdb->prepare( "SELECT ID FROM {$wpdb->users} WHERE ID > %d", $mark ) ) );

		if ( array() === $ids ) {
			return;
		}

		require_once ABSPATH . 'wp-admin/includes/user.php';

		if ( is_multisite() ) {
			require_once ABSPATH . 'wp-admin/includes/ms.php';
		}

		foreach ( $ids as $id ) {
			// On a network wp_delete_user() only takes the person off this
			// site; wpmu_delete_user() deletes the account and its meta.
			if ( is_multisite() ) {
				// WordPress will not delete a super admin: a test that granted
				// it and failed before taking it back would leave the account.
				if ( is_super_admin( $id ) ) {
					revoke_super_admin( $id );
				}

				wpmu_delete_user( $id );
			} else {
				wp_delete_user( $id );
			}
		}

		wp_cache_flush();
	}

	/** Every site with an ID above the mark deleted, on a network. */
	protected static function delete_sites_above( int $mark ): void {
		global $wpdb;

		if ( ! is_multisite() || $mark < 1 ) {
			return;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$ids = array_map( 'intval', (array) $wpdb->get_col( $wpdb->prepare( "SELECT blog_id FROM {$wpdb->blogs} WHERE blog_id > %d", $mark ) ) );

		if ( array() === $ids ) {
			return;
		}

		// A test that ends inside a switch_to_blog() would delete from the
		// wrong tables.
		while ( ms_is_switched() ) {
			restore_current_blog();
		}

		foreach ( $ids as $id ) {
			wp_delete_site( $id );
		}

		wp_cache_flush();
	}

	/** @var array<int, array{0: string, 1: callable, 2: int}> Filters and actions the test added through hook(). */
	private array $hooks = array();

	/** @var array<string, mixed> $_SERVER as the test found it. */
	private array $server = array();

	protected function setUp(): void {
		parent::setUp();

		$this->server = $_SERVER;

		// Both places a setting can be: this site's table, and on a network
		// the network's own options, where the network's settings live.
		foreach ( array_merge( self::$options, array_keys( diluxone_users_option_defaults() ) ) as $option ) {
			delete_option( $option );

			if ( is_multisite() ) {
				delete_site_option( $option );
			}
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

		// Social sign-in reads the query from a copy taken early in the
		// request; a copy a test before this one took would answer for it.
		diluxone_users_sso_query( true );

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

		unset( $GLOBALS['current_screen'] );

		foreach ( $this->hooks as $hook ) {
			remove_filter( $hook[0], $hook[1], $hook[2] );
		}

		$this->hooks = array();

		// SCRIPT_NAME, REQUEST_URI, HTTP_HOST, a referer: whatever the test
		// made the request look like stays with the test.
		$_SERVER = $this->server;

		if ( null !== $this->network_registration ) {
			update_site_option( 'registration', $this->network_registration );
			$this->network_registration = null;
		}

		parent::tearDown();
	}

	/**
	 * The rest of the test runs as if in Network Admin.
	 *
	 * On a network the network's settings are written from there and from
	 * nowhere else, so a test that saves them through a screen has to be there.
	 * On a single site it changes nothing: there is no Network Admin.
	 */
	protected function in_network_admin(): void {
		require_once ABSPATH . 'wp-admin/includes/class-wp-screen.php';
		require_once ABSPATH . 'wp-admin/includes/screen.php';

		$GLOBALS['current_screen'] = \WP_Screen::get( 'dashboard-network' );
	}

	/**
	 * The rest of the test runs as the front of the site.
	 *
	 * The suite loads WordPress as the dashboard — WP_ADMIN and DOING_AJAX
	 * are defined in the bootstrap, so the handlers register and wp_die() can
	 * be caught — and so is_admin() is true everywhere unless a screen says
	 * otherwise. is_admin() asks the current screen first: this one says it
	 * is not in the admin, and the base class forgets it after the test.
	 */
	protected function as_front_end(): void {
		$GLOBALS['current_screen'] = new class() {
			public function in_admin( ?string $admin = null ): bool {
				return false;
			}
		};
	}

	/** The plugin's transients — throttles, states, challenges — gone. */
	protected static function forget_transients(): void {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '\_transient\_diluxone\_users\_%' OR option_name LIKE '\_transient\_timeout\_diluxone\_users\_%' OR option_name LIKE '\_site\_transient\_diluxone\_users\_%' OR option_name LIKE '\_site\_transient\_timeout\_diluxone\_users\_%'" );

		// On a network, the counts per machine are the network's.
		if ( is_multisite() ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->query( "DELETE FROM {$wpdb->sitemeta} WHERE meta_key LIKE '\_site\_transient\_diluxone\_users\_%' OR meta_key LIKE '\_site\_transient\_timeout\_diluxone\_users\_%'" );
		}

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

	/**
	 * A filter or an action for this test only, taken off after it.
	 *
	 * Taken off in tearDown(), so an assertion that fails halfway through
	 * does not leave it hooked for every test after.
	 */
	protected function hook( string $hook, callable $callback, int $priority = 10, int $args = 1 ): void {
		add_filter( $hook, $callback, $priority, $args );
		$this->hooks[] = array( $hook, $callback, $priority );
	}

	/**
	 * Runs a handler that has to end in wp_die(), and returns the message.
	 *
	 * With a message, it has to be that message; with a status, that status.
	 * A refusal is two things — what it says and that it is a refusal — and
	 * a wp_die() that answers 200, or dies for some other reason entirely,
	 * is not the refusal the test is about.
	 */
	protected function expectDie( callable $handler, ?string $message = null, ?int $status = null ): string {
		ob_start();

		try {
			$handler();
		} catch ( \WPAjaxDieContinueException $e ) {
			ob_end_clean();

			if ( null !== $message ) {
				$this->assertSame( $message, $e->getMessage(), 'wp_die() said something else' );
			}

			if ( null !== $status ) {
				$this->assertSame( $status, $e->status, 'wp_die() answered another status' );
			}

			return $e->getMessage();
		} catch ( \Throwable $e ) {
			ob_end_clean();

			throw $e;
		}

		ob_end_clean();
		$this->fail( 'wp_die() was expected and the handler went on.' );
	}

	/**
	 * WordPress writes a copy of somebody's data as a zip, with ZipArchive.
	 *
	 * Without it the test would have nothing to look at, and a skip there is
	 * silent in CI: the copy of a person's data would stop being tested and
	 * nobody would notice. Every environment this suite runs in has it, so
	 * its absence is a broken environment, and says so.
	 */
	protected function needs_zip(): void {
		$this->assertTrue( class_exists( 'ZipArchive' ), 'This environment has no ZipArchive: the tests image is broken, and the copy of a person\'s data cannot be tested.' );
	}

	/**
	 * Runs a handler that must neither redirect nor stop: it leaves the
	 * request to whatever comes after it.
	 */
	protected function stays( callable $handler, string $why = '' ): void {
		try {
			$handler();
		} catch ( RedirectException $e ) {
			$this->fail( 'Sent to ' . $e->url . ( '' !== $why ? ' — ' . $why : '' ) );
		} catch ( \WPAjaxDieContinueException $e ) {
			$this->fail( 'Stopped: ' . $e->getMessage() . ( '' !== $why ? ' — ' . $why : '' ) );
		}

		$this->addToAssertionCount( 1 );
	}

	/** What wp_die() says when a nonce fails and there is no page to go back to. */
	protected const EXPIRED = 'The link you followed has expired.';

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
	 * Sets up a panel's form as the screen sends it: with the screen's nonce,
	 * from somebody allowed to save it.
	 *
	 * Every save checks both before it reads anything. Whoever is signed in
	 * stays when they may save here; otherwise the first account, which is an
	 * administrator on a single site and the super admin on a network, sends it.
	 *
	 * @param array<string, mixed> $post
	 */
	protected function postPanel( string $screen, array $post ): void {
		if ( ! current_user_can( diluxone_users_admin_cap() ) ) {
			wp_set_current_user( 1 );
		}

		$this->postAs( get_current_user_id(), $post + array( 'diluxone_users_panel_nonce' => wp_create_nonce( 'diluxone_users_panel_' . $screen ) ) );
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
