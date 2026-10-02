<?php
/**
 * The hub and the membership, around their edges.
 *
 * The refusals first: a site that is not this network's takes nobody, nobody
 * joins as nobody, an addition WordPress refuses is not announced, an address
 * that is not a site of the network joins nothing, a malformed job in the
 * queue is dropped. Then the way back from the hub's sign-in page for somebody
 * already signed in, where "here" is when there is no page to come back to,
 * the people of a network on an installation with several, the run of the
 * queue asked of cron and taken back, and the removals an administrator
 * undoes one site at a time. Each network case names its single-site half.
 */

namespace Tests\Integration;

class CoverAccountNetworkTest extends IntegrationTestCase {

	/** @var array<int, int> Sites the test made. */
	private array $sites = array();

	/** @var array<string, mixed> The query globals, the script and the request before the test. */
	private array $saved = array();

	/** @var array<int, int> Pages the test made. */
	private array $pages = array();

	protected function setUp(): void {
		parent::setUp();

		$this->saved = array(
			'wp_query'     => $GLOBALS['wp_query'] ?? null,
			'wp_the_query' => $GLOBALS['wp_the_query'] ?? null,
			'post'         => $GLOBALS['post'] ?? null,
			'pagenow'      => $GLOBALS['pagenow'] ?? null,
			'host'         => $_SERVER['HTTP_HOST'] ?? null,
			'uri'          => $_SERVER['REQUEST_URI'] ?? null,
		);

		if ( is_multisite() ) {
			diluxone_users_membership_unlock();
			diluxone_users_update_option( DILUXONE_USERS_MEMBERSHIP_CONFIRMED, 1 );
		}
	}

	protected function tearDown(): void {
		while ( is_multisite() && ms_is_switched() ) {
			restore_current_blog();
		}

		foreach ( array( 'wp_query', 'wp_the_query', 'post', 'pagenow' ) as $global ) {
			$GLOBALS[ $global ] = $this->saved[ $global ];
		}

		foreach ( array( 'host' => 'HTTP_HOST', 'uri' => 'REQUEST_URI' ) as $saved => $key ) {
			if ( null === $this->saved[ $saved ] ) {
				unset( $_SERVER[ $key ] );
			} else {
				$_SERVER[ $key ] = $this->saved[ $saved ];
			}
		}

		remove_filter( 'networks_pre_query', array( $this, 'two_networks' ) );
		remove_filter( 'wp_doing_ajax', '__return_false' );
		remove_filter( 'can_add_user_to_blog', array( $this, 'refuse_addition' ) );
		remove_action( 'admin_notices', 'diluxone_users_mapped_site_notice' );

		if ( is_multisite() ) {
			diluxone_users_on_hub( static fn() => wp_clear_scheduled_hook( DILUXONE_USERS_MEMBERSHIP_EVENT ) );
			diluxone_users_membership_queue_save( array() );
			diluxone_users_membership_unlock();
		}

		foreach ( $this->pages as $page ) {
			wp_delete_post( $page, true );
		}

		foreach ( $this->sites as $site ) {
			wp_delete_site( $site );
		}

		parent::tearDown();
	}

	/** The `networks_pre_query` filter: an installation with two networks, for a count. */
	public function two_networks( $found, \WP_Network_Query $query ) {
		return ! empty( $query->query_vars['count'] ) ? 2 : $found;
	}

	/** The `can_add_user_to_blog` filter: WordPress says no. */
	public function refuse_addition(): \WP_Error {
		return new \WP_Error( 'cover_refused', 'Not today.' );
	}

	private function network_only( string $counterpart ): void {
		if ( ! is_multisite() ) {
			$this->markTestSkipped( 'A network case; on a single site the counterpart is ' . $counterpart . '.' );
		}
	}

	private function site(): int {
		$site = (int) wp_insert_site(
			array(
				'domain' => (string) get_network()->domain,
				'path'   => '/cover-net-' . strtolower( wp_generate_password( 6, false ) ) . '/',
				'title'  => 'Cover network site',
			)
		);

		$this->assertGreaterThan( 0, $site );
		$this->sites[] = $site;

		return $site;
	}

	/** Somebody who is a member of no site. */
	private function nobody(): int {
		$user = $this->make_user();

		foreach ( array_keys( get_blogs_of_user( $user ) ) as $site ) {
			diluxone_users_membership_quietly( static fn() => remove_user_from_blog( $user, (int) $site ) );
		}

		return $user;
	}

	/* ── Where somebody stands, and the hub's addresses ─────────────── */

	public function test_from_wp_login_or_a_form_here_is_the_front_page(): void {
		$_SERVER['HTTP_HOST']   = (string) wp_parse_url( home_url(), PHP_URL_HOST ) . ( wp_parse_url( home_url(), PHP_URL_PORT ) ? ':' . wp_parse_url( home_url(), PHP_URL_PORT ) : '' );
		$_SERVER['REQUEST_URI'] = '/somewhere/';

		// The suite runs as an AJAX request; a page load is what is asked about.
		add_filter( 'wp_doing_ajax', '__return_false' );

		$GLOBALS['pagenow'] = 'index.php';
		$this->assertSame( home_url( '/somewhere/' ), diluxone_users_here(), 'A page of the site is where they are' );

		$GLOBALS['pagenow'] = 'wp-login.php';
		$this->assertSame( home_url( '/' ), diluxone_users_here(), 'Coming back to wp-login.php would only send them round again' );

		$GLOBALS['pagenow']        = 'index.php';
		$_SERVER['REQUEST_METHOD'] = 'POST';
		$this->assertSame( home_url( '/' ), diluxone_users_here(), 'A form’s address is not a page to come back to' );

		$_SERVER['REQUEST_METHOD'] = 'GET';
		$_SERVER['HTTP_HOST']      = '';
		$this->assertSame( home_url( '/' ), diluxone_users_here(), 'With no host there is no address to rebuild' );
	}

	public function test_the_hubs_wp_login_carries_its_arguments_encoded(): void {
		$url = diluxone_users_hub_wp_login(
			array(
				'action'      => 'lostpassword',
				'redirect_to' => 'https://example.test/a b/',
			)
		);

		$this->assertStringStartsWith( get_site_url( diluxone_users_hub_site_id(), 'wp-login.php', 'login' ), $url );
		$this->assertSame( 'lostpassword', $this->queryArg( $url, 'action' ) );
		$this->assertSame( 'https://example.test/a b/', $this->queryArg( $url, 'redirect_to' ) );
	}

	public function test_a_site_on_another_port_of_the_host_is_looked_up_without_it(): void {
		$parts = wp_parse_url( home_url( '/' ) );
		$url   = $parts['scheme'] . '://' . $parts['host'] . ':1/';

		if ( ! is_multisite() ) {
			$this->assertSame( 0, diluxone_users_site_for_url( $url ), 'A single site has no site of a network' );

			return;
		}

		// Found only if the network's domain carries no port: the port a
		// request came on is not a site of its own.
		$expected = false === strpos( (string) get_network()->domain, ':' ) ? get_main_site_id() : 0;

		$this->assertSame( $expected, diluxone_users_site_for_url( $url ) );
	}

	public function test_an_address_that_is_no_site_joins_nothing(): void {
		$user = $this->make_user();
		$was  = get_blogs_of_user( $user );

		diluxone_users_return_join( $user, 'https://elsewhere.example/page/' );

		$this->assertSame( array_keys( $was ), array_keys( get_blogs_of_user( $user ) ) );
	}

	public function test_a_shortcode_on_the_hub_or_a_single_site_is_drawn_as_it_is(): void {
		$this->assertFalse( diluxone_users_shortcode_off_hub( false, 'diluxone_users_login' ) );
		$this->assertSame( 'already', diluxone_users_shortcode_off_hub( 'already', 'diluxone_users_login' ) );
		$this->assertFalse( diluxone_users_shortcode_off_hub( false, 'some_other_shortcode' ) );
	}

	public function test_the_domain_notice_waits_for_the_dashboards_front_page(): void {
		$this->assertSame( 10, has_action( 'load-index.php', 'diluxone_users_mapped_site_notice_hook' ), 'the dashboard’s front page' );
		$this->assertFalse( has_action( 'admin_init', 'diluxone_users_mapped_site_notice_hook' ), 'and not every screen' );

		diluxone_users_mapped_site_notice_hook();

		$this->assertSame( 10, has_action( 'admin_notices', 'diluxone_users_mapped_site_notice' ) );
	}

	public function test_a_site_born_with_nothing_to_set_up_is_left_alone(): void {
		$current = get_current_blog_id();

		diluxone_users_site_born( 'not a site' );

		$this->assertSame( $current, get_current_blog_id(), 'Nothing to switch to, nothing switched' );
		$this->assertFalse( is_multisite() && ms_is_switched() );
		$this->assertFalse( is_multisite() && ms_is_switched() );
	}

	/* ── The way back from the hub's sign-in page ──────────────────── */

	/** The main query standing on a page of the hub, during template_redirect. */
	private function on_page_during_template_redirect( int $page, callable $work ): void {
		$query = new \WP_Query( array( 'page_id' => $page ) );

		$GLOBALS['wp_query']            = $query;
		$GLOBALS['wp_the_query']        = $query;
		$GLOBALS['wp_current_filter'][] = 'template_redirect';

		try {
			$work();
		} finally {
			array_pop( $GLOBALS['wp_current_filter'] );
		}
	}

	private function hub_page( string $title ): int {
		$page = (int) wp_insert_post(
			array(
				'post_type'   => 'page',
				'post_status' => 'publish',
				'post_title'  => $title,
			)
		);

		$this->pages[] = $page;

		return $page;
	}

	public function test_signed_in_on_the_hubs_sign_in_page_goes_straight_back(): void {
		$this->network_only( 'NetworkHubTest::test_on_a_single_site_no_return_is_held_or_used' );

		$login = $this->hub_page( 'Cover hub sign-in' );
		diluxone_users_update_option( 'diluxone_users_login_page', $login );
		$site = $this->site();
		$back = get_home_url( $site, '/a-page/' );

		wp_set_current_user( $this->make_user() );
		$_GET = array( 'redirect_to' => $back );

		$url = '';
		$this->on_page_during_template_redirect(
			$login,
			function () use ( &$url ): void {
				$url = $this->expectRedirect( 'diluxone_users_return_capture' );
			}
		);

		$this->assertSame( $back, $url, 'Nothing to sign in to: back where they came from' );
		$this->assertArrayNotHasKey( DILUXONE_USERS_RETURN_COOKIE, self::$cookies, 'Nothing held for later' );
	}

	public function test_a_page_of_the_hub_that_is_no_door_holds_nothing(): void {
		$this->network_only( 'NetworkHubTest::test_on_a_single_site_no_return_is_held_or_used' );

		diluxone_users_update_option( 'diluxone_users_login_page', $this->hub_page( 'Cover hub sign-in' ) );
		$about = $this->hub_page( 'Cover about' );
		$_GET  = array( 'redirect_to' => get_home_url( $this->site(), '/' ) );

		$this->on_page_during_template_redirect( $about, 'diluxone_users_return_capture' );

		$this->assertArrayNotHasKey( DILUXONE_USERS_RETURN_COOKIE, self::$cookies );
	}

	public function test_with_no_door_chosen_the_hub_holds_nothing_from_a_page(): void {
		$this->network_only( 'NetworkHubTest::test_on_a_single_site_no_return_is_held_or_used' );

		$page = $this->hub_page( 'Cover any page' );
		$_GET = array( 'redirect_to' => get_home_url( $this->site(), '/' ) );

		$this->on_page_during_template_redirect( $page, 'diluxone_users_return_capture' );

		$this->assertArrayNotHasKey( DILUXONE_USERS_RETURN_COOKIE, self::$cookies );
	}

	/* ── Membership ────────────────────────────────────────────────── */

	public function test_nobody_joins_as_nobody_or_nowhere(): void {
		$this->assertFalse( diluxone_users_membership_may_join( 0, get_current_blog_id() ) );
		$this->assertFalse( diluxone_users_membership_may_join( 1, 0 ) );
	}

	public function test_a_site_that_is_not_there_takes_nobody(): void {
		$this->network_only( 'MembershipTest::test_on_a_single_site_there_is_nothing_to_join_or_to_be_invited_to' );

		$this->assertFalse( diluxone_users_membership_site_takes( 999999 ) );
	}

	public function test_an_addition_wordpress_refuses_is_not_announced(): void {
		$this->network_only( 'MembershipTest::test_on_a_single_site_an_administrator_is_added_to_nothing' );

		$site  = $this->site();
		$user  = $this->nobody();
		$heard = 0;
		$count = static function () use ( &$heard ): void {
			++$heard;
		};

		add_filter( 'can_add_user_to_blog', array( $this, 'refuse_addition' ) );
		add_action( 'diluxone_users_member_added', $count );

		$this->assertFalse( diluxone_users_membership_add( $user, $site, 'click' ) );

		remove_action( 'diluxone_users_member_added', $count );

		$this->assertFalse( is_user_member_of_blog( $user, $site ) );
		$this->assertSame( 0, $heard );
	}

	public function test_adding_somebody_back_to_one_site_keeps_the_other_removals(): void {
		$this->network_only( 'MembershipTest::test_on_a_single_site_nothing_is_written_down_about_removals' );

		$one  = $this->site();
		$two  = $this->site();
		$user = $this->make_user();
		add_user_to_blog( $one, $user, 'subscriber' );
		add_user_to_blog( $two, $user, 'subscriber' );

		remove_user_from_blog( $user, $one );
		remove_user_from_blog( $user, $two );
		$this->assertEqualsCanonicalizing( array( $one, $two ), diluxone_users_membership_removed( $user ) );

		add_user_to_blog( $one, $user, 'subscriber' );

		$this->assertSame( array( $two ), diluxone_users_membership_removed( $user ), 'Only the site they were added back to is forgotten' );
	}

	public function test_with_several_networks_the_people_are_this_networks_members(): void {
		$this->network_only( 'MembershipTest::test_on_a_single_site_there_is_no_membership_at_all' );

		$member = $this->make_user();
		$nobody = $this->nobody();

		add_filter( 'networks_pre_query', array( $this, 'two_networks' ), 10, 2 );

		$people = diluxone_users_membership_people( $member - 1, 1000 );

		$this->assertContains( $member, $people );
		$this->assertNotContains( $nobody, $people, 'A member of no site of this network is not its person' );
		$this->assertSame( count( diluxone_users_membership_people( 0, PHP_INT_MAX ) ), diluxone_users_membership_people_count() );
	}

	public function test_a_malformed_job_is_dropped_from_the_queue(): void {
		$this->network_only( 'MembershipTest::test_on_a_single_site_a_new_site_is_not_a_thing' );

		diluxone_users_update_option(
			DILUXONE_USERS_MEMBERSHIP_QUEUE,
			array(
				'not a job',
				array( 'kind' => 'nonsense' ),
				array(
					'kind'  => 'user',
					'id'    => 5,
					'after' => 0,
					'done'  => 0,
					'total' => 1,
				),
			)
		);

		$this->assertSame( array( 'user' ), array_column( diluxone_users_membership_queue(), 'kind' ) );
	}

	public function test_a_run_of_the_queue_is_asked_once_and_taken_back_on_switching_off(): void {
		$this->network_only( 'MembershipTest::test_on_a_single_site_a_new_site_is_not_a_thing' );

		diluxone_users_on_hub( static fn() => wp_clear_scheduled_hook( DILUXONE_USERS_MEMBERSHIP_EVENT ) );

		diluxone_users_membership_schedule( 300 );
		$first = (int) diluxone_users_on_hub( static fn() => wp_next_scheduled( DILUXONE_USERS_MEMBERSHIP_EVENT ) );
		diluxone_users_membership_schedule( 0 );

		$this->assertEqualsWithDelta( time() + 300, $first, 5 );
		$this->assertSame( $first, (int) diluxone_users_on_hub( static fn() => wp_next_scheduled( DILUXONE_USERS_MEMBERSHIP_EVENT ) ), 'Asked once' );

		diluxone_users_membership_unschedule();

		$this->assertFalse( diluxone_users_on_hub( static fn() => wp_next_scheduled( DILUXONE_USERS_MEMBERSHIP_EVENT ) ) );
	}

	public function test_on_a_single_site_switching_off_has_no_run_to_take_back(): void {
		if ( is_multisite() ) {
			$this->markTestSkipped( 'A single-site case; on a network the counterpart is test_a_run_of_the_queue_is_asked_once_and_taken_back_on_switching_off.' );
		}

		diluxone_users_membership_unschedule();

		$this->assertFalse( wp_next_scheduled( DILUXONE_USERS_MEMBERSHIP_EVENT ) );
	}
}
