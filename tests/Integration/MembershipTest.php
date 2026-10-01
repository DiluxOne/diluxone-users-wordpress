<?php
/**
 * Who is a member of which site: the network's membership policy.
 *
 * Every case is played on both topologies, in this one class: each network
 * case skips itself on a single site, and each single-site counterpart on a
 * network. On a network: the three policies, a new account and a new site
 * under "every site" with each site's role, the people and sites left out,
 * the removals an administrator made and the ones nobody did, the queue with
 * its cron and its lock, "Sync everyone now", "Join this site" and its
 * refusals, the invitation, signing in under each policy, and where a
 * sign-in on the hub came from in the activity log. On a single site: none of
 * it exists, and nothing changes.
 */

namespace Tests\Integration;

class MembershipTest extends IntegrationTestCase {

	/** @var array<int, int> Sites made by the test, deleted after it. */
	private array $sites = array();

	/** A live site whose New User Default Role is author. */
	private int $alpha = 0;

	/** A live site whose New User Default Role is editor, too much for a self-registered account. */
	private int $beta = 0;

	/** @var array<int, array{user: int, site: int, role: string, how: string}> What diluxone_users_member_added said. */
	private array $added = array();

	/** @var array<int, int> People made super admin by the test, taken back after it. */
	private array $supers = array();

	protected function setUp(): void {
		parent::setUp();

		diluxone_users_sign_in_from( 0 );
		diluxone_users_membership_unlock();
		diluxone_users_join_drawn( false );

		add_action( 'diluxone_users_member_added', array( $this, 'heard' ), 10, 4 );
		if ( is_multisite() ) {
			$this->alpha = $this->site( 'author' );
			$this->beta  = $this->site( 'editor' );

			// The sites above, born under "every site", queued every account
			// of the database; the tests start from an empty queue.
			diluxone_users_membership_queue_save( array() );
			// And on a database with few of them, added them on the spot:
			// what the tests hear starts after that.
			$this->added = array();

			// A network whose policy was confirmed: the cases of one that was
			// not are the ones that say so.
			diluxone_users_update_option( DILUXONE_USERS_MEMBERSHIP_CONFIRMED, 1 );

			diluxone_users_update_option( 'diluxone_users_login_role', 'contributor' );
		}

		// Added after the sites above, which would otherwise take every
		// account of the test database on the spot. Every live site of the
		// test database is a handful: a new account is added on the spot
		// unless a test says otherwise.
		add_filter( 'diluxone_users_membership_inline', array( $this, 'inline_many' ) );
	}

	protected function tearDown(): void {
		while ( is_multisite() && ms_is_switched() ) {
			restore_current_blog();
		}

		remove_action( 'diluxone_users_member_added', array( $this, 'heard' ), 10 );
		remove_filter( 'diluxone_users_membership_inline', array( $this, 'inline_many' ) );
		remove_all_filters( 'diluxone_users_membership_batch' );

		foreach ( $this->supers as $user ) {
			revoke_super_admin( $user );
		}

		foreach ( $this->sites as $site ) {
			wp_delete_site( $site );
		}

		diluxone_users_membership_queue_save( array() );
		diluxone_users_membership_unlock();
		diluxone_users_sign_in_from( 0 );
		diluxone_users_join_drawn( false );

		parent::tearDown();
	}

	/** @return int */
	public function inline_many(): int {
		return 1000;
	}

	/** The `diluxone_users_member_added` action, heard. */
	public function heard( int $user, int $site, string $role, string $how ): void {
		$this->added[] = array(
			'user' => $user,
			'site' => $site,
			'role' => $role,
			'how'  => $how,
		);
	}

	private function network_only(): void {
		if ( ! is_multisite() ) {
			$this->markTestSkipped( 'Needs a multisite network: the single-site half of this case is in this same class.' );
		}
	}

	private function single_only(): void {
		if ( is_multisite() ) {
			$this->markTestSkipped( 'Needs a single site: the network half of this case is in this same class.' );
		}
	}

	/**
	 * A site of the network, with its own New User Default Role.
	 *
	 * @param array<string, int> $flags archived, spam or deleted.
	 */
	private function site( string $role, array $flags = array() ): int {
		$site = (int) wp_insert_site(
			array_merge(
				array(
					'domain' => (string) get_network()->domain,
					'path'   => '/mb-' . strtolower( wp_generate_password( 6, false ) ) . '/',
					'title'  => 'Membership test site',
				),
				$flags
			)
		);

		$this->assertGreaterThan( 0, $site );
		$this->sites[] = $site;

		update_blog_option( $site, 'default_role', $role );

		return $site;
	}

	/** Somebody with no role on any site: made while the network was "by invitation", the way WordPress makes network users. */
	private function nobody(): int {
		$was = diluxone_users_raw_get( 'diluxone_users_membership', null );

		diluxone_users_update_option( 'diluxone_users_membership', 'invite' );
		$user = (int) wpmu_create_user( 'mb' . strtolower( wp_generate_password( 10, false ) ), wp_generate_password( 16 ), wp_generate_password( 8, false ) . '@example.test' );
		diluxone_users_update_option( 'diluxone_users_membership', null === $was ? 'all' : $was );

		$this->assertGreaterThan( 0, $user );
		$this->assertSame( array(), get_blogs_of_user( $user ), 'made a member of nothing' );

		return $user;
	}

	private function super_admin(): int {
		$user = $this->nobody();
		grant_super_admin( $user );
		$this->supers[] = $user;

		return $user;
	}

	/** The role somebody has on a site, or '' when they are not a member. */
	private function role_on( int $user, int $site ): string {
		if ( ! is_user_member_of_blog( $user, $site ) ) {
			return '';
		}

		switch_to_blog( $site );
		$roles = ( new \WP_User( $user ) )->roles;
		restore_current_blog();

		return (string) ( $roles[0] ?? '' );
	}

	/**
	 * Runs something that ends in wp_die(), and says whether it did.
	 *
	 * The suite's bootstrap turns wp_die() into an exception (see
	 * bootstrap-integration.php); do_shortcode() is avoided below for the
	 * reason NetworkHubTest gives, and the shortcode's callback is called.
	 */
	private function dies( callable $work ): bool {
		try {
			$work();
		} catch ( \WPAjaxDieContinueException $e ) {
			return true;
		}

		return false;
	}

	/* ── The policy ─────────────────────────────────────────────────── */

	public function test_the_policy_is_every_site_by_default_and_only_one_of_three(): void {
		$this->network_only();

		$this->assertSame( 'all', diluxone_users_membership(), 'every site, out of the box' );

		foreach ( array( 'click', 'invite', 'all' ) as $policy ) {
			diluxone_users_update_option( 'diluxone_users_membership', $policy );
			$this->assertSame( $policy, diluxone_users_membership() );
		}

		diluxone_users_update_option( 'diluxone_users_membership', 'everybody-everywhere' );
		$this->assertSame( 'all', diluxone_users_membership(), 'anything else reads as every site' );

		$this->assertSame( 'network', diluxone_users_option_scope( 'diluxone_users_membership' ) );
		$this->assertSame( 'everybody-everywhere', get_site_option( 'diluxone_users_membership' ), 'stored in the network’s options' );
	}

	/** Saved from Network Admin › Membership, and from nowhere else. */
	public function test_the_membership_screen_saves_one_of_the_three_in_network_admin_only(): void {
		$this->network_only();

		$this->assertArrayHasKey( DILUXONE_USERS_MEMBERSHIP_SCREEN, diluxone_users_screens() );
		$this->assertTrue( diluxone_users_screen_here( DILUXONE_USERS_MEMBERSHIP_SCREEN, 'network' ) );
		$this->assertFalse( diluxone_users_screen_here( DILUXONE_USERS_MEMBERSHIP_SCREEN, 'hub' ), 'not on the hub’s menu' );
		$this->assertFalse( diluxone_users_screen_here( DILUXONE_USERS_MEMBERSHIP_SCREEN, 'site' ), 'not on a site’s menu' );

		// From a site's dashboard, a hand-made post writes nothing.
		$_POST = array( DILUXONE_USERS_MEMBERSHIP => 'invite' );
		diluxone_users_membership_save();
		$this->assertSame( 'all', diluxone_users_membership() );

		$this->in_network_admin();

		$_POST = array( DILUXONE_USERS_MEMBERSHIP => 'click' );
		diluxone_users_membership_save();
		$this->assertSame( 'click', diluxone_users_membership() );

		ob_start();
		$_POST = array( DILUXONE_USERS_MEMBERSHIP => 'nobody' );
		$this->assertFalse( diluxone_users_membership_save(), 'refused' );
		ob_end_clean();
		$this->assertSame( 'click', diluxone_users_membership() );
	}

	public function test_on_a_single_site_there_is_no_membership_at_all(): void {
		$this->single_only();

		$this->assertArrayNotHasKey( DILUXONE_USERS_MEMBERSHIP_SCREEN, diluxone_users_screens(), 'no screen' );
		$this->assertFalse( has_action( 'admin_post_diluxone_users_join' ), 'no handler' );
		$this->assertFalse( has_action( 'admin_post_nopriv_diluxone_users_join' ) );
		$this->assertFalse( has_filter( 'login_redirect', 'diluxone_users_join_mark_password' ) );

		$user = $this->make_user();
		wp_set_current_user( $user );

		$this->assertSame( '', diluxone_users_join_state(), 'nothing to join' );
		$this->assertSame( '', diluxone_users_shortcode_join(), 'the shortcode draws nothing' );
		$this->assertSame( home_url( '/x/' ), diluxone_users_join_mark( home_url( '/x/' ), $user ) );
		$this->assertSame( array(), diluxone_users_membership_queue(), 'nothing queued' );
		$this->assertTrue( diluxone_users_membership_drain(), 'nothing to drain' );
		$this->assertTrue( diluxone_users_membership_sync(), 'nothing to sync' );
		$this->assertSame( array(), $this->added, 'nobody announced' );
	}

	/* ── The confirmation ───────────────────────────────────────────── */

	/**
	 * A network that has not confirmed its policy — every network the day the
	 * plugin is network-activated, and every one that ran it before this
	 * setting existed — has the policy do nothing on its own: no new account,
	 * new site or sign-in adds anybody, the queue waits, and Network Admin
	 * says so everywhere but on the screen that asks.
	 */
	public function test_until_the_policy_is_confirmed_it_adds_nobody(): void {
		$this->network_only();

		diluxone_users_update_option( DILUXONE_USERS_MEMBERSHIP_CONFIRMED, 0 );

		$this->assertFalse( diluxone_users_membership_confirmed() );
		$this->assertSame( 'all', diluxone_users_membership(), 'every site is still the answer waiting to be confirmed' );

		// Past the threshold too: nothing is even queued.
		add_filter( 'diluxone_users_membership_inline', '__return_zero', 20 );

		// A new account: what WordPress makes it, nothing more.
		$user = (int) wpmu_create_user( 'mbwait' . strtolower( wp_generate_password( 8, false ) ), wp_generate_password( 16 ), wp_generate_password( 8, false ) . '@example.test' );
		$this->assertSame( array(), get_blogs_of_user( $user ), 'no site' );

		// A new site: nobody.
		$gamma = $this->site( 'author' );
		$this->assertFalse( is_user_member_of_blog( $user, $gamma ) );
		$this->assertSame( array(), diluxone_users_membership_queue(), 'nothing queued' );

		remove_filter( 'diluxone_users_membership_inline', '__return_zero', 20 );

		// Signing in: nobody, not even the hub.
		switch_to_blog( $this->alpha );
		diluxone_users_join_site( $user );
		restore_current_blog();
		$this->assertSame( array(), get_blogs_of_user( $user ) );

		// A job left from before waits for the confirmation, untouched.
		diluxone_users_membership_enqueue_all();
		$this->assertTrue( diluxone_users_membership_drain( 0 ) );
		$this->assertCount( 1, diluxone_users_membership_queue(), 'kept, not dropped' );
		$this->assertTrue( diluxone_users_membership_sync(), 'nothing to sync' );
		$this->assertSame( array(), get_blogs_of_user( $user ) );
		$this->assertSame( array(), $this->added, 'nobody announced' );

		// Network Admin says so, to whoever runs the network, except on the
		// screen that asks the question.
		wp_set_current_user( $this->super_admin() );

		$pagenow            = $GLOBALS['pagenow'] ?? null;
		$GLOBALS['pagenow'] = 'index.php';
		ob_start();
		diluxone_users_membership_unconfirmed_notice();
		$this->assertStringContainsString( 'data-diluxone-users-membership-unconfirmed', (string) ob_get_clean() );

		$_GET = array( 'page' => DILUXONE_USERS_MEMBERSHIP_SCREEN );
		ob_start();
		diluxone_users_membership_unconfirmed_notice();
		$this->assertSame( '', (string) ob_get_clean(), 'quiet on the Membership screen' );

		// Where the plugin is looked at, and not on every screen of Network Admin.
		$GLOBALS['pagenow'] = 'users.php';
		$_GET               = array();
		ob_start();
		diluxone_users_membership_unconfirmed_notice();
		$quiet = (string) ob_get_clean();
		$GLOBALS['pagenow'] = 'admin.php';
		$_GET               = array( 'page' => 'diluxone-users' );
		ob_start();
		diluxone_users_membership_unconfirmed_notice();
		$spoken             = (string) ob_get_clean();
		$GLOBALS['pagenow'] = 'index.php';
		$this->assertSame( '', $quiet, 'quiet on a screen that is not about the plugin' );
		$this->assertStringContainsString( 'data-diluxone-users-membership-unconfirmed', $spoken, 'said on the plugin’s own screens' );

		wp_set_current_user( $this->make_user( 'administrator' ) );
		$_GET = array();
		ob_start();
		diluxone_users_membership_unconfirmed_notice();
		$this->assertSame( '', (string) ob_get_clean(), 'and to a site administrator' );
		$GLOBALS['pagenow'] = $pagenow;
	}

	/**
	 * Confirming is the first save of the Membership screen. Under "every
	 * site" it adds everybody already there, then the policy runs on its own.
	 */
	public function test_confirming_every_site_adds_everybody_and_from_then_on_the_policy_runs(): void {
		$this->network_only();

		diluxone_users_update_option( DILUXONE_USERS_MEMBERSHIP_CONFIRMED, 0 );

		$person = $this->nobody();

		$this->in_network_admin();
		wp_set_current_user( $this->super_admin() );

		diluxone_users_membership_panels();
		$label = diluxone_users_panels( DILUXONE_USERS_MEMBERSHIP_SCREEN )['policy']['save_label'];
		$this->assertSame( __( 'Confirm the policy', 'diluxone-users' ), $label(), 'the button says what it does' );

		$_POST = array( DILUXONE_USERS_MEMBERSHIP => 'all' );
		ob_start();
		diluxone_users_membership_save();
		ob_end_clean();

		$this->assertTrue( diluxone_users_membership_confirmed() );
		$this->assertSame( __( 'Save changes', 'diluxone-users' ), $label() );
		$this->assertSame( 'contributor', $this->role_on( $person, get_main_site_id() ), 'everybody already there, added' );
		$this->assertSame( 'author', $this->role_on( $person, $this->alpha ) );
		$this->assertSame( 'subscriber', $this->role_on( $person, $this->beta ) );

		// And from now on on its own: a new account joins every live site.
		$other = (int) wpmu_create_user( 'mbconf' . strtolower( wp_generate_password( 8, false ) ), wp_generate_password( 16 ), wp_generate_password( 8, false ) . '@example.test' );
		$this->assertTrue( is_user_member_of_blog( $other, $this->alpha ) );
		$this->assertTrue( is_user_member_of_blog( $other, $this->beta ) );

		// Saving again later is not another sync.
		$later = $this->nobody();
		ob_start();
		diluxone_users_membership_save();
		ob_end_clean();
		$this->assertFalse( is_user_member_of_blog( $later, $this->alpha ), 'a later save adds nobody: that is what Sync is for' );
	}

	public function test_confirming_another_policy_adds_nobody(): void {
		$this->network_only();

		diluxone_users_update_option( DILUXONE_USERS_MEMBERSHIP_CONFIRMED, 0 );

		$person = $this->nobody();

		$this->in_network_admin();
		wp_set_current_user( $this->super_admin() );

		$_POST = array( DILUXONE_USERS_MEMBERSHIP => 'click' );
		ob_start();
		diluxone_users_membership_save();
		ob_end_clean();

		$this->assertTrue( diluxone_users_membership_confirmed() );
		$this->assertSame( array(), get_blogs_of_user( $person ) );
	}

	public function test_on_a_single_site_there_is_nothing_to_confirm(): void {
		$this->single_only();

		$this->assertTrue( diluxone_users_membership_confirmed(), 'every account is a member of the only site' );

		wp_set_current_user( 1 );
		ob_start();
		diluxone_users_membership_unconfirmed_notice();
		$this->assertSame( '', (string) ob_get_clean(), 'no notice' );
	}

	/* ── A new account ──────────────────────────────────────────────── */

	/**
	 * Under "every site", a new account is a member of every live site, with
	 * each site's role: the hub's registration role on the hub, a site's own
	 * default role elsewhere, subscriber where that would be too much. Never
	 * an archived, spam or deleted site; and each addition is announced once.
	 */
	public function test_a_new_account_joins_every_live_site_with_each_sites_role(): void {
		$this->network_only();

		$archived = $this->site( 'author', array( 'archived' => 1 ) );
		$spam     = $this->site( 'author', array( 'spam' => 1 ) );
		$deleted  = $this->site( 'author', array( 'deleted' => 1 ) );
		diluxone_users_membership_queue_save( array() );

		// The way Network Admin › Users › Add New makes one: WordPress takes
		// the role on the current site away right after making it.
		$user = (int) wpmu_create_user( 'mbnew' . strtolower( wp_generate_password( 8, false ) ), wp_generate_password( 16 ), wp_generate_password( 8, false ) . '@example.test' );

		$this->assertSame( 'contributor', $this->role_on( $user, get_main_site_id() ), 'the hub gives its registration role' );
		$this->assertSame( 'author', $this->role_on( $user, $this->alpha ), 'a site gives its own default role' );
		$this->assertSame( 'subscriber', $this->role_on( $user, $this->beta ), 'editor is too much for a self-registered account' );

		foreach ( array( $archived, $spam, $deleted ) as $site ) {
			$this->assertFalse( is_user_member_of_blog( $user, $site ), 'no archived, spam or deleted site' );
		}

		$said = array_values( array_filter( $this->added, static fn( array $one ): bool => $one['user'] === $user ) );
		$this->assertSame( count( diluxone_users_membership_sites() ), count( $said ), 'every live site, announced once each' );
		$this->assertSame( array( 'account' ), array_values( array_unique( array_column( $said, 'how' ) ) ) );

		// And one made by wp_insert_user(), with a role: the same answer.
		$other = $this->make_user();
		$this->assertTrue( is_user_member_of_blog( $other, $this->alpha ) );
		$this->assertTrue( is_user_member_of_blog( $other, $this->beta ) );
	}

	public function test_under_click_or_invite_a_new_account_joins_nothing_more(): void {
		$this->network_only();

		foreach ( array( 'click', 'invite' ) as $policy ) {
			diluxone_users_update_option( 'diluxone_users_membership', $policy );

			$user = $this->make_user();

			$this->assertTrue( is_user_member_of_blog( $user, get_main_site_id() ), "{$policy}: where it was made" );
			$this->assertFalse( is_user_member_of_blog( $user, $this->alpha ), "{$policy}: nowhere else" );
		}

		$this->assertSame( array(), $this->added );
	}

	public function test_on_a_single_site_a_new_account_is_what_wordpress_makes_it(): void {
		$this->single_only();

		$asked  = array();
		$listen = static function ( string $sql ) use ( &$asked ): string {
			$asked[] = $sql;

			return $sql;
		};

		add_filter( 'query', $listen );
		$user = $this->make_user( 'author' );
		remove_filter( 'query', $listen );

		$this->assertSame( array(), array_filter( $asked, static fn( string $sql ): bool => str_contains( $sql, 'blog_id' ) ), 'no network’s sites looked for' );

		$this->assertSame( array( 'author' ), get_userdata( $user )->roles, 'the role it was given, nothing else' );
		$this->assertSame( array(), $this->added );
		$this->assertSame( '', (string) get_user_meta( $user, DILUXONE_USERS_MEMBERSHIP_REMOVED, true ) );
	}

	/* ── A new site ─────────────────────────────────────────────────── */

	/**
	 * Under "every site", a new site gets every account of the network: on
	 * the spot when there are few, and here — the test database has many —
	 * through the queue, which cron works through under its lock.
	 */
	public function test_a_new_site_gets_every_account_through_the_queue_and_cron_under_a_lock(): void {
		$this->network_only();

		$people = array( $this->nobody(), $this->nobody() );
		$super  = $this->super_admin();

		add_filter( 'diluxone_users_membership_inline', '__return_zero', 20 );

		$site = $this->site( 'author' );

		$queue = diluxone_users_membership_queue();
		$this->assertCount( 1, $queue, 'queued, not made on the spot' );
		$this->assertSame( 'site', $queue[0]['kind'] );
		$this->assertSame( $site, $queue[0]['id'] );
		$this->assertSame( diluxone_users_membership_people_count(), $queue[0]['total'] );
		$this->assertNotFalse( diluxone_users_on_hub( static fn() => wp_next_scheduled( DILUXONE_USERS_MEMBERSHIP_EVENT ) ), 'cron is asked, on the hub' );
		$this->assertFalse( is_user_member_of_blog( $people[0], $site ) );

		// Carried on from just before this test's people: the accounts the
		// test database piled up before them are not what is being tested.
		$queue[0]['after'] = min( $people ) - 1;
		diluxone_users_membership_queue_save( $queue );

		// Another run holds the lock: this one does nothing, and looks again later.
		$this->assertTrue( diluxone_users_membership_lock() );
		diluxone_users_membership_cron();
		$this->assertFalse( is_user_member_of_blog( $people[0], $site ), 'nothing while the lock is held' );
		$this->assertCount( 1, diluxone_users_membership_queue() );
		diluxone_users_membership_unlock();

		// One addition a batch: a run of ten batches goes ten people further.
		add_filter( 'diluxone_users_membership_batch', static fn(): int => 1 );
		$this->assertFalse( diluxone_users_membership_drain( 1 ), 'some left after one batch' );
		$this->assertTrue( is_user_member_of_blog( $people[0], $site ), 'the first one' );
		$this->assertFalse( is_user_member_of_blog( $people[1], $site ), 'not yet the second' );
		$this->assertSame( 1, diluxone_users_membership_queue()[0]['done'] );

		remove_all_filters( 'diluxone_users_membership_batch' );
		diluxone_users_membership_cron();

		$this->assertSame( 'author', $this->role_on( $people[1], $site ) );
		$this->assertFalse( is_user_member_of_blog( $super, $site ), 'not a super admin' );
		$this->assertSame( array(), diluxone_users_membership_queue(), 'finished, and forgotten' );
		$this->assertSame( array( 'site' ), array_values( array_unique( array_column( $this->added, 'how' ) ) ) );

		remove_filter( 'diluxone_users_membership_inline', '__return_zero', 20 );
	}

	/** A new account on a network past the threshold: one job, however many times WordPress announces it. */
	public function test_a_new_account_past_the_threshold_is_queued_once(): void {
		$this->network_only();

		add_filter( 'diluxone_users_membership_inline', '__return_zero', 20 );

		$user = (int) wpmu_create_user( 'mbq' . strtolower( wp_generate_password( 8, false ) ), wp_generate_password( 16 ), wp_generate_password( 8, false ) . '@example.test' );

		$queue = diluxone_users_membership_queue();
		$this->assertCount( 1, $queue, 'user_register and wpmu_new_user, one job' );
		$this->assertSame( array( 'user', $user ), array( $queue[0]['kind'], $queue[0]['id'] ) );
		$this->assertFalse( is_user_member_of_blog( $user, $this->alpha ) );

		$this->assertTrue( diluxone_users_membership_drain() );
		$this->assertSame( 'author', $this->role_on( $user, $this->alpha ) );
		$this->assertSame( 'contributor', $this->role_on( $user, get_main_site_id() ) );

		remove_filter( 'diluxone_users_membership_inline', '__return_zero', 20 );
	}

	/** A job queued under "every site" is dropped once the network chose something else. */
	public function test_a_queue_left_from_every_site_is_dropped_under_another_policy(): void {
		$this->network_only();

		add_filter( 'diluxone_users_membership_inline', '__return_zero', 20 );
		$user = $this->nobody();
		diluxone_users_membership_enqueue(
			array(
				'kind' => 'user',
				'id'   => $user,
			)
		);
		remove_filter( 'diluxone_users_membership_inline', '__return_zero', 20 );

		diluxone_users_update_option( 'diluxone_users_membership', 'click' );

		$this->assertTrue( diluxone_users_membership_drain() );
		$this->assertSame( array(), diluxone_users_membership_queue() );
		$this->assertFalse( is_user_member_of_blog( $user, $this->alpha ) );
	}

	public function test_on_a_single_site_a_new_site_is_not_a_thing(): void {
		$this->single_only();

		$user = $this->make_user();

		// WordPress has no sites to make here (no WP_Site at all); told of one
		// anyway, the plugin adds nobody to anything.
		$this->assertFalse( class_exists( 'WP_Site' ) );
		diluxone_users_membership_new_site( (object) array( 'blog_id' => 1 ) );

		$this->assertSame( array(), diluxone_users_membership_queue() );
		$this->assertFalse( wp_next_scheduled( DILUXONE_USERS_MEMBERSHIP_EVENT ), 'no run of a queue scheduled' );
		$this->assertSame( array( 'subscriber' ), get_userdata( $user )->roles );
		$this->assertSame( array(), $this->added );
	}

	/* ── Removals ───────────────────────────────────────────────────── */

	/**
	 * An administrator took somebody off a site: nothing adds them back — not
	 * the policy, not signing in, not a sync — until an administrator does,
	 * which forgets the removal.
	 */
	public function test_somebody_an_administrator_removed_is_not_added_back_until_an_administrator_does(): void {
		$this->network_only();

		$user = $this->make_user();
		$this->assertTrue( is_user_member_of_blog( $user, $this->alpha ) );

		remove_user_from_blog( $user, $this->alpha );

		$this->assertSame( array( $this->alpha ), diluxone_users_membership_removed( $user ), 'written down' );

		// Signing in there.
		switch_to_blog( $this->alpha );
		diluxone_users_join_site( $user );
		restore_current_blog();
		$this->assertFalse( is_user_member_of_blog( $user, $this->alpha ), 'not by signing in' );

		// The policy, however WordPress announces the account again.
		diluxone_users_membership_new_account( $user );
		$this->assertFalse( is_user_member_of_blog( $user, $this->alpha ), 'not by the policy' );

		// A sync of this person.
		$this->assertTrue( diluxone_users_membership_step( array( 'kind' => 'user', 'id' => $user, 'after' => 0, 'done' => 0, 'total' => 0 ) ) === null );
		$this->assertFalse( is_user_member_of_blog( $user, $this->alpha ), 'not by a sync' );

		// Their own click, under "click".
		diluxone_users_update_option( 'diluxone_users_membership', 'click' );
		wp_set_current_user( $user );
		switch_to_blog( $this->alpha );
		$this->assertSame( 'invite', diluxone_users_join_state(), 'by invitation, for them' );
		restore_current_blog();

		// An administrator adds them back: the record is gone.
		add_user_to_blog( $this->alpha, $user, 'subscriber' );
		$this->assertSame( array(), diluxone_users_membership_removed( $user ) );
		$this->assertSame( '', (string) get_user_meta( $user, DILUXONE_USERS_MEMBERSHIP_REMOVED, true ) );
	}

	/**
	 * What WordPress removes on its own and what the plugin removes itself
	 * are not an administrator's decision: a site being deleted, an
	 * invitation being activated, an account being deleted, an account being
	 * closed.
	 */
	public function test_removals_nobody_decided_are_not_written_down(): void {
		$this->network_only();

		// A site deleted with its members.
		$user = $this->make_user();
		$gone = $this->site( 'author' );
		add_user_to_blog( $gone, $user, 'subscriber' );
		wp_delete_site( $gone );
		$this->sites = array_diff( $this->sites, array( $gone ) );
		$this->assertSame( array(), diluxone_users_membership_removed( $user ), 'a deleted site' );

		// An invitation to /alpha/ activated, as wpmu_activate_signup() does
		// it: the account made, then WordPress takes it off the main site on
		// the way to /alpha/, and "every site" puts it back — one member of
		// the hub, announced once.
		$invited = (int) wpmu_create_user( 'mbinv' . strtolower( wp_generate_password( 8, false ) ), wp_generate_password( 16 ), wp_generate_password( 8, false ) . '@example.test' );
		do_action(
			'wpmu_activate_user',
			$invited,
			'',
			array(
				'add_to_blog' => $this->alpha,
				'new_role'    => 'author',
			)
		);
		$this->assertSame( array(), diluxone_users_membership_removed( $invited ), 'an invitation activated' );
		$this->assertTrue( is_user_member_of_blog( $invited, get_main_site_id() ), 'and still on the hub, under every site' );
		$this->assertCount(
			1,
			array_filter( $this->added, static fn( array $one ): bool => $one['user'] === $invited && get_main_site_id() === $one['site'] ),
			'announced once'
		);
		$this->assertSame( 'author', $this->role_on( $invited, $this->alpha ), 'with the role of the invitation' );

		// The plugin itself.
		$closed = $this->make_user();
		diluxone_users_membership_quietly( fn() => remove_user_from_blog( $closed, $this->alpha ) );
		$this->assertSame( array(), diluxone_users_membership_removed( $closed ), 'the plugin’s own' );

		// An account being deleted from the network.
		require_once ABSPATH . 'wp-admin/includes/ms.php';
		$deleted = $this->make_user();
		$writes  = 0;
		$count   = static function ( $mid, $object, $key ) use ( &$writes ): void {
			if ( DILUXONE_USERS_MEMBERSHIP_REMOVED === $key ) {
				++$writes;
			}
		};
		add_action( 'added_user_meta', $count, 10, 3 );
		wpmu_delete_user( $deleted );
		remove_action( 'added_user_meta', $count, 10 );
		$this->assertSame( 0, $writes, 'an account being deleted' );
	}

	/** Closing an account from the account area takes it off its sites without writing anything down. */
	public function test_closing_an_account_writes_no_removal(): void {
		$this->network_only();

		require_once ABSPATH . 'wp-admin/includes/user.php';

		$writes = 0;
		$count  = static function ( $mid, $object, $key ) use ( &$writes ): void {
			if ( DILUXONE_USERS_MEMBERSHIP_REMOVED === $key ) {
				++$writes;
			}
		};

		diluxone_users_update_option( 'diluxone_users_membership', 'invite' );
		$user    = $this->make_user();
		$request = wp_create_user_request( get_userdata( $user )->user_email, 'remove_personal_data', array( DILUXONE_USERS_CLOSE_KEY => $user ), 'confirmed' );

		add_action( 'added_user_meta', $count, 10, 3 );
		do_action( 'wp_privacy_personal_data_erased', $request );
		remove_action( 'added_user_meta', $count, 10 );

		$this->assertFalse( get_userdata( $user ), 'closed' );
		$this->assertSame( 0, $writes );
	}

	/** What is written down about a removal is the person's: exported, and erased with the rest. */
	public function test_a_removal_is_in_the_export_and_goes_with_the_erasure(): void {
		$this->network_only();

		$user = $this->make_user();
		update_user_meta( $user, DILUXONE_USERS_MEMBERSHIP_REMOVED, array( $this->alpha ) );

		$export = diluxone_users_privacy_export( get_userdata( $user )->user_email );
		$names  = array();

		foreach ( $export['data'] as $item ) {
			foreach ( $item['data'] as $row ) {
				$names[ $row['name'] ] = $row['value'];
			}
		}

		$this->assertSame( (string) get_site( $this->alpha )->blogname, $names['Sites an administrator removed you from'] ?? null );

		diluxone_users_privacy_erase( get_userdata( $user )->user_email );

		$this->assertSame( array(), diluxone_users_membership_removed( $user ), 'erased' );
	}

	public function test_on_a_single_site_nothing_is_written_down_about_removals(): void {
		$this->single_only();

		$user = $this->make_user();

		$this->assertFalse( function_exists( 'remove_user_from_blog' ), 'WordPress has no removal from a site here' );
		diluxone_users_membership_removed_now( $user, 1 );
		$this->assertSame( '', (string) get_user_meta( $user, DILUXONE_USERS_MEMBERSHIP_REMOVED, true ) );
	}

	/* ── Super admins ───────────────────────────────────────────────── */

	public function test_a_super_admin_is_never_added(): void {
		$this->network_only();

		$super = $this->super_admin();

		$this->assertFalse( diluxone_users_membership_add( $super, $this->alpha, 'sync' ) );

		switch_to_blog( $this->alpha );
		diluxone_users_join_site( $super );
		wp_set_current_user( $super );
		$this->assertSame( '', diluxone_users_join_state(), 'nothing to join: every site is theirs already' );
		restore_current_blog();

		$this->assertSame( array(), get_blogs_of_user( $super ) );

		switch_to_blog( $this->alpha );
		$back = home_url( '/' );
		restore_current_blog();
		diluxone_users_update_option( 'diluxone_users_membership', 'invite' );
		$this->assertSame( $back, diluxone_users_join_mark( $back, $super ), 'no site to join on the way back' );
	}

	/** An archived, spam or deleted site: signing in on it, or being added to it, adds nobody. */
	public function test_a_site_that_is_not_live_is_joined_by_nobody(): void {
		$this->network_only();

		$user = $this->make_user();

		foreach ( array( 'archived', 'spam', 'deleted' ) as $flag ) {
			$site = $this->site( 'author', array( $flag => 1 ) );

			switch_to_blog( $site );
			diluxone_users_join_site( $user );
			restore_current_blog();

			$this->assertFalse( is_user_member_of_blog( $user, $site ), "{$flag}: not by signing in" );
			$this->assertFalse( diluxone_users_membership_add( $user, $site, 'sync' ), "{$flag}: not at all" );
		}
	}

	/** A closed account is an empty shell: no policy, sync or sign-in makes it a member of anything. */
	public function test_a_closed_account_is_never_added(): void {
		$this->network_only();

		$closed = $this->nobody();
		update_user_meta( $closed, 'diluxone_users_closed', time() );

		$this->assertFalse( diluxone_users_membership_add( $closed, $this->alpha, 'sync' ) );
		diluxone_users_membership_new_account( $closed );
		$this->assertSame( array(), get_blogs_of_user( $closed ) );
	}

	public function test_on_a_single_site_an_administrator_is_added_to_nothing(): void {
		$this->single_only();

		$admin = $this->make_user( 'administrator' );

		$this->assertFalse( diluxone_users_membership_add( $admin, 1, 'sync' ) );

		// Somebody with no role here at all is still a member, as WordPress
		// has it on a single site: nothing is added.
		$bare = $this->make_user();
		delete_user_option( $bare, 'capabilities' );
		$this->assertTrue( is_user_member_of_blog( $bare, 1 ) );
		$this->assertFalse( diluxone_users_membership_add( $bare, 1, 'sync' ) );
		diluxone_users_join_site( $bare );
		$this->assertSame( array(), get_userdata( $bare )->roles );
		diluxone_users_join_site( $admin );
		$this->assertSame( array( 'administrator' ), get_userdata( $admin )->roles );
		$this->assertSame( array(), $this->added );
	}

	/* ── Sync everyone now ──────────────────────────────────────────── */

	public function test_sync_everyone_now_asks_for_the_network_and_adds_everybody_but_the_removed(): void {
		$this->network_only();

		$people  = array( $this->nobody(), $this->nobody() );
		$removed = $this->nobody();
		add_user_to_blog( $this->alpha, $removed, 'subscriber' );
		remove_user_from_blog( $removed, $this->alpha );

		$site_admin = $this->make_user( 'administrator' );
		$network    = $this->super_admin();

		add_filter( 'diluxone_users_membership_inline', '__return_zero', 20 );

		// Somebody who does not run the network.
		wp_set_current_user( $site_admin );
		$_REQUEST = array( '_wpnonce' => wp_create_nonce( 'diluxone_users_membership_sync' ) );
		$this->assertTrue( $this->dies( 'diluxone_users_membership_sync_request' ), 'a site administrator cannot' );
		$this->assertSame( array(), diluxone_users_membership_queue() );

		// Whoever runs the network, without the nonce and with it.
		wp_set_current_user( $network );
		$_REQUEST = array( '_wpnonce' => 'nope' );
		$this->assertTrue( $this->dies( 'diluxone_users_membership_sync_request' ), 'not without the nonce' );

		$_REQUEST = array( '_wpnonce' => wp_create_nonce( 'diluxone_users_membership_sync' ) );
		$landed   = $this->expectRedirect( 'diluxone_users_membership_sync_request' );

		$this->assertSame( 'queued', $this->queryArg( $landed, 'diluxone-users-synced' ) );
		$this->assertStringContainsString( '/wp-admin/network/admin.php', $landed );

		$queue = diluxone_users_membership_queue();
		$this->assertSame( 'all', $queue[0]['kind'] );
		$this->assertSame( count( diluxone_users_membership_sites() ) * diluxone_users_membership_people_count(), $queue[0]['total'] );

		// From just before this test's people, to the end, as cron would.
		$queue[0]['after'] = min( array_merge( $people, array( $removed ) ) ) - 1;
		diluxone_users_membership_queue_save( $queue );
		$this->assertTrue( diluxone_users_membership_drain( 0 ) );

		foreach ( $people as $person ) {
			$this->assertSame( 'contributor', $this->role_on( $person, get_main_site_id() ) );
			$this->assertSame( 'author', $this->role_on( $person, $this->alpha ) );
			$this->assertSame( 'subscriber', $this->role_on( $person, $this->beta ) );
		}

		$this->assertFalse( is_user_member_of_blog( $removed, $this->alpha ), 'the removal stays' );
		$this->assertTrue( is_user_member_of_blog( $removed, $this->beta ), 'everywhere else' );

		remove_filter( 'diluxone_users_membership_inline', '__return_zero', 20 );

		// Under another policy it adds nobody.
		diluxone_users_update_option( 'diluxone_users_membership', 'click' );
		$this->assertTrue( diluxone_users_membership_sync() );
		$this->assertSame( array(), diluxone_users_membership_queue() );
	}

	/**
	 * What `wp diluxone-users network membership sync` runs, in the order it
	 * runs it: the job queued, and one batch after another to the end,
	 * refusing while another run holds the lock.
	 */
	public function test_the_command_line_sync_runs_to_the_end_and_waits_for_nobody(): void {
		$this->network_only();

		$person = $this->nobody();

		diluxone_users_membership_enqueue_all();
		$queue             = diluxone_users_membership_queue();
		$queue[0]['after'] = $person - 1;
		diluxone_users_membership_queue_save( $queue );

		$this->assertTrue( diluxone_users_membership_lock() );
		$this->assertNull( diluxone_users_membership_drain( 1 ), 'held by another run' );
		diluxone_users_membership_unlock();

		add_filter( 'diluxone_users_membership_batch', static fn(): int => 1 );

		$runs = 0;

		do {
			$drained = diluxone_users_membership_drain( 1 );
			++$runs;
		} while ( true !== $drained && $runs < 50 );

		$this->assertTrue( $drained );
		$this->assertTrue( is_user_member_of_blog( $person, $this->alpha ) );
		$this->assertTrue( is_user_member_of_blog( $person, $this->beta ) );
	}

	/** The screen's button and the command line: on a single site, nothing to sync and no command. */
	public function test_on_a_single_site_there_is_nothing_to_sync(): void {
		$this->single_only();

		$user = $this->make_user();

		$this->assertTrue( diluxone_users_membership_sync(), 'done before it starts' );
		$this->assertSame( array(), diluxone_users_membership_queue() );

		wp_set_current_user( $this->make_user( 'administrator' ) );
		$_REQUEST = array( '_wpnonce' => wp_create_nonce( 'diluxone_users_membership_sync' ) );
		$this->assertTrue( $this->dies( 'diluxone_users_membership_sync_request' ), 'the handler refuses: there is no network' );

		$this->assertSame( array( 'subscriber' ), get_userdata( $user )->roles );
		$this->assertSame( array(), $this->added );
	}

	/* ── Join this site ─────────────────────────────────────────────── */

	/** Presses "Join this site" on a site, as somebody, and returns where it went. */
	private function press_join( int $site, int $user, ?string $nonce = null ): string {
		switch_to_blog( $site );
		wp_set_current_user( $user );

		$_REQUEST = array( '_wpnonce' => $nonce ?? wp_create_nonce( 'diluxone_users_join' ) );
		$_POST    = $_REQUEST;

		try {
			return $this->expectRedirect( 'diluxone_users_join_request' );
		} finally {
			restore_current_blog();
		}
	}

	public function test_under_click_a_person_joins_a_site_with_its_role(): void {
		$this->network_only();

		diluxone_users_update_option( 'diluxone_users_membership', 'click' );
		$user = $this->make_user();

		switch_to_blog( $this->alpha );
		wp_set_current_user( $user );
		$this->assertSame( 'click', diluxone_users_join_state() );
		$box = diluxone_users_shortcode_join();
		$this->assertStringContainsString( 'data-diluxone-users-join="click"', $box );
		$this->assertStringContainsString( 'value="diluxone_users_join"', $box );
		restore_current_blog();

		$landed = $this->press_join( $this->alpha, $user );

		$this->assertSame( 'joined', $this->redirectState( $landed ) );
		$this->assertSame( 'author', $this->role_on( $user, $this->alpha ) );
		$this->assertSame(
			array(
				'user' => $user,
				'site' => $this->alpha,
				'role' => 'author',
				'how'  => 'click',
			),
			end( $this->added ),
			'diluxone_users_member_added, with how it happened'
		);

		// A member now: nothing more to press, and a welcome after the press.
		switch_to_blog( $this->alpha );
		$this->assertSame( '', diluxone_users_join_state() );
		$_GET = array( 'diluxone-users' => 'joined' );
		$this->assertStringContainsString( 'data-diluxone-users-join="joined"', diluxone_users_shortcode_join() );
		$_GET = array();
		$this->assertSame( '', diluxone_users_shortcode_join() );
		restore_current_blog();
	}

	public function test_join_refuses_without_the_nonce_under_another_policy_or_signed_out(): void {
		$this->network_only();

		diluxone_users_update_option( 'diluxone_users_membership', 'click' );
		$user = $this->make_user();

		// Without the nonce.
		switch_to_blog( $this->alpha );
		wp_set_current_user( $user );
		$_REQUEST = array( '_wpnonce' => 'nope' );
		$this->assertTrue( $this->dies( 'diluxone_users_join_request' ), 'no nonce, no join' );
		restore_current_blog();
		$this->assertFalse( is_user_member_of_blog( $user, $this->alpha ) );

		// Under "by invitation" and "every site", pressing it does nothing.
		foreach ( array( 'invite', 'all' ) as $policy ) {
			diluxone_users_update_option( 'diluxone_users_membership', $policy );
			$this->assertSame( 'join-refused', $this->redirectState( $this->press_join( $this->alpha, $user ) ), $policy );
			$this->assertFalse( is_user_member_of_blog( $user, $this->alpha ), $policy );
		}

		// Nobody signed in: to the sign-in.
		diluxone_users_update_option( 'diluxone_users_membership', 'click' );
		switch_to_blog( $this->alpha );
		wp_set_current_user( 0 );
		$landed = $this->expectRedirect( 'diluxone_users_join_request' );
		$this->assertSame( strtok( diluxone_users_login_url(), '?' ), strtok( $landed, '?' ) );
		restore_current_blog();

		// A site that takes nobody.
		$archived = $this->site( 'author', array( 'archived' => 1 ) );
		$this->assertSame( 'join-refused', $this->redirectState( $this->press_join( $archived, $user ) ) );
		$this->assertFalse( is_user_member_of_blog( $user, $archived ) );
	}

	public function test_under_invite_the_site_says_it_is_by_invitation(): void {
		$this->network_only();

		diluxone_users_update_option( 'diluxone_users_membership', 'invite' );
		$user = $this->make_user();

		switch_to_blog( $this->alpha );
		wp_set_current_user( $user );
		$this->assertSame( 'invite', diluxone_users_join_state() );

		$box = diluxone_users_shortcode_join();
		$this->assertStringContainsString( 'data-diluxone-users-join="invite"', $box );
		$this->assertStringNotContainsString( 'value="diluxone_users_join"', $box, 'nothing to press' );

		// Nobody signed in, or a member: nothing at all.
		wp_set_current_user( 0 );
		$this->assertSame( '', diluxone_users_shortcode_join() );
		restore_current_blog();

		// Arriving from the hub, the notice at the top of the page, once.
		switch_to_blog( $this->alpha );
		wp_set_current_user( $user );
		$_GET = array( 'diluxone-users' => 'join' );
		ob_start();
		diluxone_users_join_notice();
		$notice = (string) ob_get_clean();
		$this->assertStringContainsString( 'diluxone-users-join--notice', $notice );
		$this->assertStringContainsString( 'data-diluxone-users-join="invite"', $notice );
		$this->assertSame( '', diluxone_users_shortcode_join(), 'said once on the page' );
		$_GET = array();
		restore_current_blog();
	}

	/** Under any policy stored by hand, a single site offers nothing to join and no invitation. */
	public function test_on_a_single_site_there_is_nothing_to_join_or_to_be_invited_to(): void {
		$this->single_only();

		$user = $this->make_user();

		// Somebody with no role here at all is not offered anything either.
		$bare = $this->make_user();
		delete_user_option( $bare, 'capabilities' );
		$this->assertSame( '', diluxone_users_join_state( $bare ) );

		wp_set_current_user( $user );

		foreach ( array( 'all', 'click', 'invite' ) as $policy ) {
			diluxone_users_update_option( 'diluxone_users_membership', $policy );

			$this->assertSame( 'all', diluxone_users_membership(), 'the policy means nothing here' );
			$this->assertSame( '', diluxone_users_join_state(), $policy );
			$this->assertSame( '', diluxone_users_shortcode_join(), $policy );

			foreach ( array( 'join', 'joined', 'join-refused' ) as $state ) {
				$_GET = array( 'diluxone-users' => $state );
				ob_start();
				diluxone_users_join_notice();
				$this->assertSame( '', (string) ob_get_clean(), "{$policy}, {$state}: no notice" );
				$this->assertSame( '', diluxone_users_shortcode_join(), "{$policy}, {$state}: no box" );
				$_GET = array();
			}
		}
	}

	/* ── Signing in, under each policy ──────────────────────────────── */

	/**
	 * Asking for a link proves nothing, and adds nobody; opening it is signing
	 * in, the safety net of "every site".
	 */
	public function test_under_every_site_membership_comes_with_the_click_not_with_the_request(): void {
		$this->network_only();

		$user = get_userdata( $this->nobody() );

		switch_to_blog( $this->alpha );
		diluxone_users_update_option( 'diluxone_users_login_register', 1 );

		$this->postAs(
			0,
			array(
				'diluxone_users_nonce' => wp_create_nonce( 'diluxone_users_login' ),
				'diluxone_users_email' => $user->user_email,
			)
		);
		$this->expectRedirect( 'diluxone_users_login_request' );

		$this->assertFalse( is_user_member_of_blog( $user->ID, $this->alpha ), 'asking proves nothing' );

		$_GET = array(
			'diluxone_users_login' => (string) $user->ID,
			'diluxone_users_token' => diluxone_users_token_create( $user->ID ),
		);
		$this->expectRedirect( 'diluxone_users_login_consume' );
		restore_current_blog();

		$this->assertSame( 'author', $this->role_on( $user->ID, $this->alpha ), 'the click does: the site it was on' );
		$this->assertSame( 'contributor', $this->role_on( $user->ID, get_main_site_id() ), 'and the hub' );
		$this->assertFalse( is_user_member_of_blog( $user->ID, $this->beta ), 'not a site it was not on' );
	}

	public function test_under_click_or_invite_signing_in_joins_only_the_hub(): void {
		$this->network_only();

		foreach ( array( 'click', 'invite' ) as $policy ) {
			diluxone_users_update_option( 'diluxone_users_membership', $policy );
			$user = $this->nobody();

			switch_to_blog( $this->alpha );
			diluxone_users_join_site( $user );
			restore_current_blog();

			$this->assertFalse( is_user_member_of_blog( $user, $this->alpha ), "{$policy}: not the site" );
			$this->assertSame( 'contributor', $this->role_on( $user, get_main_site_id() ), "{$policy}: the hub, where the account lives" );
		}
	}

	public function test_on_a_single_site_signing_in_changes_nobodys_role(): void {
		$this->single_only();

		$user = $this->make_user( 'author' );
		diluxone_users_join_site( $user );

		$this->assertSame( array( 'author' ), get_userdata( $user )->roles );
		$this->assertSame( array(), $this->added );
	}

	/* ── The activity log: where a sign-in came from ────────────────── */

	/** Signs somebody in on the hub for a site of the network, as the way back does. */
	private function sign_in_for( int $site, int $user ): void {
		switch_to_blog( $site );
		$back = home_url( '/a-page/' );
		restore_current_blog();

		$_GET = array( 'redirect_to' => $back );
		diluxone_users_return_capture();
		$_GET = array();

		apply_filters( 'diluxone_users_login_redirect', home_url( '/' ), $user );
		do_action( 'diluxone_users_logged_in', $user, 'link' );
	}

	public function test_a_sign_in_on_the_hub_for_a_site_is_in_that_sites_report_and_the_hubs(): void {
		$this->network_only();

		require_once ABSPATH . 'wp-admin/includes/template.php';

		diluxone_users_log_install();
		$user = $this->make_user();

		$this->sign_in_for( $this->beta, $user );

		$hub   = get_main_site_id();
		$rows  = diluxone_users_log_search( array( 'site' => $hub, 'arrivals' => true, 'event' => 'signed_in' ), 1, 200 )['rows'];
		$mine  = array_values( array_filter( $rows, static fn( array $row ): bool => $row['user_id'] === $user ) );

		$this->assertCount( 1, $mine, 'on the hub’s report, where it happened' );
		$this->assertSame( $hub, $mine[0]['site_id'] );
		$this->assertSame( (string) $this->beta, $mine[0]['detail']['from_site'] ?? '', 'with the site it came from' );

		$beta = diluxone_users_log_search( array( 'site' => $this->beta, 'arrivals' => true ), 1, 200 )['rows'];
		$this->assertSame( array( $user ), array_column( $beta, 'user_id' ), 'and on /beta/’s report' );

		$alpha = diluxone_users_log_search( array( 'site' => $this->alpha, 'arrivals' => true ), 1, 200 )['rows'];
		$this->assertSame( array(), $alpha, 'not on another site’s' );

		$strict = diluxone_users_log_search( array( 'site' => $this->beta ), 1, 200 )['rows'];
		$this->assertSame( array(), $strict, 'without arrivals, a site’s own rows only' );

		// On screen: /beta/'s report has the row and says where it happened;
		// the network's has a From column naming /beta/.
		wp_set_current_user( $this->super_admin() );
		switch_to_blog( $this->beta );
		ob_start();
		diluxone_users_log_report( false );
		$report = (string) ob_get_clean();
		restore_current_blog();

		$this->assertStringContainsString( 'data-diluxone-users-site="' . $hub . '"', $report );
		$this->assertStringContainsString( esc_html( diluxone_users_log_site_name( $hub ) ), $report );

		$this->in_network_admin();
		ob_start();
		diluxone_users_log_report( true );
		$network = (string) ob_get_clean();

		$this->assertStringContainsString( '<th>' . esc_html__( 'From', 'diluxone-users' ) . '</th>', $network );
		$this->assertStringContainsString( 'data-diluxone-users-from="' . $this->beta . '"', $network );
	}

	public function test_a_sign_in_on_the_hub_itself_comes_from_nowhere(): void {
		$this->network_only();

		diluxone_users_log_install();
		$user = $this->make_user();

		do_action( 'diluxone_users_logged_in', $user, 'password' );

		$rows = diluxone_users_log_search( array( 'site' => get_main_site_id(), 'arrivals' => true, 'who' => get_userdata( $user )->user_email ), 1, 20 )['rows'];

		$this->assertCount( 1, $rows );
		$this->assertArrayNotHasKey( 'from_site', $rows[0]['detail'] );
		$this->assertSame( 0, diluxone_users_log_from( $rows[0] ) );
	}

	public function test_on_a_single_site_a_sign_in_comes_from_nowhere_and_the_report_is_its_rows(): void {
		$this->single_only();

		require_once ABSPATH . 'wp-admin/includes/template.php';

		diluxone_users_log_install();
		$user = $this->make_user();

		do_action( 'diluxone_users_logged_in', $user, 'link' );

		$rows = diluxone_users_log_search( array( 'site' => 1, 'who' => get_userdata( $user )->user_email ), 1, 20 )['rows'];

		$this->assertCount( 1, $rows );
		$this->assertSame( array( 'via' => 'link' ), $rows[0]['detail'], 'no from_site' );

		$asked  = array();
		$listen = static function ( string $sql ) use ( &$asked ): string {
			$asked[] = $sql;

			return $sql;
		};

		add_filter( 'query', $listen );
		ob_start();
		diluxone_users_log_report( false );
		$report = (string) ob_get_clean();
		remove_filter( 'query', $listen );

		$this->assertStringNotContainsString( '<th>' . esc_html__( 'From', 'diluxone-users' ) . '</th>', $report, 'no From column' );
		$this->assertNotEmpty( $asked );
		$this->assertSame( array(), array_filter( $asked, static fn( string $sql ): bool => str_contains( $sql, 'l.detail LIKE' ) ), 'the query is the site’s rows, as it always was' );
	}
}
