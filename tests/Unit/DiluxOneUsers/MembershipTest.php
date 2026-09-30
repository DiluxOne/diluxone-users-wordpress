<?php
/**
 * The membership policy's pure answers, without WordPress.
 *
 * Which of the three policies a stored value is, which role a site gives,
 * which sites take people, what a removal list becomes, which removals are an
 * administrator's decision, and the arithmetic of the queue: when additions
 * are made on the spot, how many people a batch takes, how far a job is.
 * The same answers whatever the topology: on a single site nothing calls
 * them, and diluxone_users_membership() says 'all' without asking.
 */

namespace Tests\Unit\DiluxOneUsers;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;

class MembershipTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		Functions\when( 'register_deactivation_hook' )->justReturn( null );
		require_once DILUXONE_USERS_DIR . 'includes/options.php';
		require_once DILUXONE_USERS_DIR . 'includes/options-scope.php';
		require_once DILUXONE_USERS_DIR . 'includes/membership.php';
	}

	protected function tearDown(): void {
		unset( $GLOBALS['_test_multisite'] );
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * @dataProvider storedPolicies
	 * @param mixed $stored
	 */
	public function test_a_stored_policy_is_one_of_three_and_every_site_otherwise( $stored, string $policy ): void {
		$this->assertSame( $policy, diluxone_users_membership_policy( $stored ) );
	}

	public static function storedPolicies(): array {
		return array(
			'every site'           => array( 'all', 'all' ),
			'whoever asks'         => array( 'click', 'click' ),
			'by invitation'        => array( 'invite', 'invite' ),
			'never written'        => array( null, 'all' ),
			'nothing'              => array( '', 'all' ),
			'something else'       => array( 'everybody', 'all' ),
			'in capitals'          => array( 'CLICK', 'all' ),
			'a number'             => array( 1, 'all' ),
			'a list'               => array( array( 'click' ), 'all' ),
		);
	}

	public function test_the_three_policies_in_the_order_the_screen_offers_them(): void {
		$this->assertSame( array( 'all', 'click', 'invite' ), diluxone_users_membership_policies() );
	}

	public function test_on_a_single_site_the_policy_is_every_site_without_asking(): void {
		$GLOBALS['_test_multisite'] = false;
		Functions\expect( 'diluxone_users_option' )->never();

		$this->assertSame( 'all', diluxone_users_membership() );
	}

	public function test_on_a_network_the_policy_is_the_stored_one(): void {
		$GLOBALS['_test_multisite'] = true;
		Functions\when( 'diluxone_users_option' )->justReturn( 'invite' );

		$this->assertSame( 'invite', diluxone_users_membership() );
	}

	/**
	 * @dataProvider roles
	 */
	public function test_the_role_a_site_gives( bool $hub, string $hub_role, string $site_role, bool $site_ok, string $role ): void {
		$this->assertSame( $role, diluxone_users_membership_pick_role( $hub, $hub_role, $site_role, $site_ok ) );
	}

	public static function roles(): array {
		return array(
			'the hub: its registration role'            => array( true, 'contributor', 'author', true, 'contributor' ),
			'the hub, whatever its default role says'   => array( true, 'subscriber', 'author', true, 'subscriber' ),
			'the hub with none: subscriber'             => array( true, '', 'author', true, 'subscriber' ),
			'another site: its default role'            => array( false, 'contributor', 'author', true, 'author' ),
			'another site, a role too big: subscriber'  => array( false, 'contributor', 'editor', false, 'subscriber' ),
			'another site with none: subscriber'        => array( false, 'contributor', '', true, 'subscriber' ),
		);
	}

	/**
	 * @dataProvider sites
	 * @param array<string, mixed> $flags
	 */
	public function test_which_sites_take_people( array $flags, bool $live ): void {
		$this->assertSame( $live, diluxone_users_membership_site_live( $flags ) );
	}

	public static function sites(): array {
		return array(
			'live, as WordPress stores it' => array( array( 'archived' => '0', 'spam' => '0', 'deleted' => '0' ), true ),
			'live, as numbers'             => array( array( 'archived' => 0, 'spam' => 0, 'deleted' => 0 ), true ),
			'nothing said'                 => array( array(), true ),
			'archived'                     => array( array( 'archived' => '1', 'spam' => '0', 'deleted' => '0' ), false ),
			'spam'                         => array( array( 'archived' => '0', 'spam' => 1, 'deleted' => '0' ), false ),
			'deleted'                      => array( array( 'archived' => '0', 'spam' => '0', 'deleted' => '1' ), false ),
		);
	}

	public function test_a_removal_list_takes_a_site_once_and_gives_it_back(): void {
		$this->assertSame( array( 3 ), diluxone_users_membership_removed_with( array(), 3 ) );
		$this->assertSame( array( 3, 5 ), diluxone_users_membership_removed_with( array( 3 ), 5 ) );
		$this->assertSame( array( 3, 5 ), diluxone_users_membership_removed_with( array( '3', 5 ), 3 ), 'once' );
		$this->assertSame( array( 5 ), diluxone_users_membership_removed_with( array( 0, -1, 'x' ), 5 ), 'nothing that is not a site' );

		$this->assertSame( array( 5 ), diluxone_users_membership_removed_without( array( 3, 5 ), 3 ) );
		$this->assertSame( array(), diluxone_users_membership_removed_without( array( 3 ), 3 ) );
		$this->assertSame( array( 3 ), diluxone_users_membership_removed_without( array( 3 ), 9 ), 'a site not on it' );
	}

	/**
	 * @dataProvider removals
	 */
	public function test_only_an_administrators_removal_is_written_down( bool $quiet, bool $core, bool $deleting, bool $counts ): void {
		$this->assertSame( $counts, diluxone_users_membership_removal_counts( $quiet, $core, $deleting ) );
	}

	public static function removals(): array {
		return array(
			'an administrator'             => array( false, false, false, true ),
			'the plugin closing an account' => array( true, false, false, false ),
			'WordPress on its own'         => array( false, true, false, false ),
			'an account being deleted'     => array( false, false, true, false ),
		);
	}

	/**
	 * @dataProvider sizes
	 */
	public function test_small_jobs_are_made_on_the_spot_and_big_ones_queued( int $pairs, int $threshold, bool $inline ): void {
		$this->assertSame( $inline, diluxone_users_membership_inline( $pairs, $threshold ) );
	}

	public static function sizes(): array {
		return array(
			'nothing'              => array( 0, 50, true ),
			'under'                => array( 12, 50, true ),
			'exactly'              => array( 50, 50, true ),
			'one past'             => array( 51, 50, false ),
			'a threshold of none'  => array( 1, 0, false ),
			'a negative threshold' => array( 0, -5, true ),
		);
	}

	public function test_how_many_people_a_batch_takes(): void {
		$this->assertSame( 50, diluxone_users_membership_people_per_batch( 200, 4 ) );
		$this->assertSame( 66, diluxone_users_membership_people_per_batch( 200, 3 ), 'rounded down: never more additions than a batch' );
		$this->assertSame( 1, diluxone_users_membership_people_per_batch( 200, 5000 ), 'more sites than a batch: one at a time, never none' );
		$this->assertSame( 200, diluxone_users_membership_people_per_batch( 200, 0 ), 'no site' );
	}

	public function test_how_far_a_job_is(): void {
		$this->assertSame( 0, diluxone_users_membership_percent( 0, 300 ) );
		$this->assertSame( 33, diluxone_users_membership_percent( 100, 300 ) );
		$this->assertSame( 100, diluxone_users_membership_percent( 300, 300 ) );
		$this->assertSame( 100, diluxone_users_membership_percent( 400, 300 ), 'never past the end' );
		$this->assertSame( 100, diluxone_users_membership_percent( 0, 0 ), 'a job of nothing is done' );
	}
}
