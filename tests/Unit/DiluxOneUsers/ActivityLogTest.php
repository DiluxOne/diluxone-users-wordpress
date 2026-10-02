<?php
/**
 * Which events this site writes down, and which it does not.
 *
 * It is the whole of the activity log that can be got wrong silently. A table
 * that is not written to looks exactly like a site where nothing happened, and
 * a group that records more than it says records people's movements for ninety
 * days without anybody having ticked it. Neither shows on any screen, and
 * neither is something an end-to-end test can ask about without waiting a day.
 *
 * So the decision — this event, on this site, yes or no — is a function of the
 * settings and nothing else, and this is that function asked every way round.
 * Nothing here touches a database: that is the integration suite's half.
 */

namespace Tests\Unit\DiluxOneUsers;

use Tests\Unit\ResetsWpStubs;
use Brain\Monkey;
use PHPUnit\Framework\TestCase;

class ActivityLogTest extends TestCase {

	use ResetsWpStubs;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		if ( ! defined( 'DILUXONE_USERS_FILE' ) ) {
			define( 'DILUXONE_USERS_FILE', DILUXONE_USERS_DIR . 'diluxone-users.php' );
		}

		// The file registers the table's activation and deactivation hooks as
		// it loads. Neither exists outside WordPress and neither is what is
		// being tested, so they are answered rather than stubbed in the shared
		// stub file, where they would be two more functions every other suite
		// carries for nothing.
		Monkey\Functions\when( 'register_activation_hook' )->justReturn( true );
		Monkey\Functions\when( 'register_deactivation_hook' )->justReturn( true );

		require_once DILUXONE_USERS_DIR . 'includes/options.php';
		require_once DILUXONE_USERS_DIR . 'includes/options-scope.php';
		require_once DILUXONE_USERS_DIR . 'includes/log.php';

		$GLOBALS['_test_wp_options'] = array();
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	/** Leaves the site recording exactly these groups. */
	private function recording( array $groups ): void {
		update_option( 'diluxone_users_log_levels', $groups );
	}

	/** Every event of one group, from the map itself rather than from a copy. */
	private function events_of( string $group ): array {
		return array_keys( array_filter( diluxone_users_log_events(), static fn( string $g ): bool => $g === $group ) );
	}

	public function test_a_fresh_install_records_the_way_in_and_nothing_else(): void {
		// Nothing stored: the defaults decide, and the default is the decision
		// that somebody's disk and somebody's privacy are not this plugin's to
		// spend without being asked.
		$this->assertTrue( diluxone_users_log_records( 'signed_in' ) );
		$this->assertTrue( diluxone_users_log_records( 'signed_out' ) );
		$this->assertTrue( diluxone_users_log_records( 'sign_in_failed' ) );

		$this->assertFalse( diluxone_users_log_records( 'email_changed' ) );
		$this->assertFalse( diluxone_users_log_records( 'password_changed' ) );
		$this->assertFalse( diluxone_users_log_records( '2fa_on' ) );
		$this->assertFalse( diluxone_users_log_records( 'passkey_added' ) );
	}

	public function test_each_group_opens_its_own_events_and_no_others(): void {
		foreach ( array_keys( diluxone_users_log_groups() ) as $group ) {
			$this->recording( array( $group ) );

			foreach ( diluxone_users_log_events() as $event => $belongs ) {
				$this->assertSame(
					$group === $belongs,
					diluxone_users_log_records( $event ),
					sprintf( 'with only "%s" ticked, "%s" answered wrong', $group, $event )
				);
			}
		}
	}

	public function test_two_groups_ticked_open_both_and_still_not_the_third(): void {
		$this->recording( array( 'access', 'security' ) );

		foreach ( $this->events_of( 'access' ) as $event ) {
			$this->assertTrue( diluxone_users_log_records( $event ), $event );
		}

		foreach ( $this->events_of( 'security' ) as $event ) {
			$this->assertTrue( diluxone_users_log_records( $event ), $event );
		}

		foreach ( $this->events_of( 'account' ) as $event ) {
			$this->assertFalse( diluxone_users_log_records( $event ), $event );
		}
	}

	public function test_nothing_ticked_writes_nothing_at_all(): void {
		$this->recording( array() );

		foreach ( array_keys( diluxone_users_log_events() ) as $event ) {
			$this->assertFalse( diluxone_users_log_records( $event ), $event );
		}
	}

	public function test_an_event_this_plugin_does_not_have_is_never_written(): void {
		$this->recording( array( 'access', 'account', 'security' ) );

		$this->assertFalse( diluxone_users_log_records( 'whatever_happened' ) );
		$this->assertFalse( diluxone_users_log_records( '' ) );
	}

	public function test_an_option_that_is_not_a_list_records_nothing(): void {
		// A site that came from an option written by hand, or from a migration
		// that went wrong. The safe way round is silence: a broken setting must
		// never be read as permission to start writing rows.
		$this->recording( array() );
		update_option( 'diluxone_users_log_levels', 'access' );

		$this->assertSame( array(), diluxone_users_log_levels() );
		$this->assertFalse( diluxone_users_log_records( 'signed_in' ) );
	}

	public function test_a_group_this_plugin_no_longer_has_is_dropped_and_the_rest_stand(): void {
		update_option( 'diluxone_users_log_levels', array( 'access', 'telepathy', 'access' ) );

		$this->assertSame( array( 'access' ), diluxone_users_log_levels() );
		$this->assertTrue( diluxone_users_log_records( 'signed_in' ) );
	}

	public function test_every_event_belongs_to_a_group_that_exists(): void {
		// The map is the policy: an event filed under a group nobody can tick
		// is an event that is silently never recorded, and nothing else in the
		// plugin would ever say so.
		$groups = diluxone_users_log_groups();

		foreach ( diluxone_users_log_events() as $event => $group ) {
			$this->assertArrayHasKey( $group, $groups, sprintf( '"%s" is filed under a group that does not exist', $event ) );
		}
	}

	public function test_every_event_has_a_name_of_its_own(): void {
		$labels = diluxone_users_log_labels();

		foreach ( array_keys( diluxone_users_log_events() ) as $event ) {
			$this->assertArrayHasKey( $event, $labels, $event );
			$this->assertSame( $labels[ $event ], diluxone_users_log_label( $event ) );
		}

		$this->assertSame( 'made_up', diluxone_users_log_label( 'made_up' ), 'one nobody named is its slug' );
	}

	public function test_every_group_says_what_it_costs(): void {
		// The sentence beside each tick box that says roughly how many rows it
		// writes. It is the half of the question somebody is deciding on, and a
		// group added without one would ship a switch with no price on it.
		foreach ( diluxone_users_log_groups() as $group => $what ) {
			$this->assertNotSame( '', trim( (string) ( $what['label'] ?? '' ) ), $group );
			$this->assertNotSame( '', trim( (string) ( $what['help'] ?? '' ) ), $group );
			$this->assertNotSame( '', trim( (string) ( $what['writes'] ?? '' ) ), $group );
		}
	}

	public function test_a_detail_keeps_short_answers_and_drops_everything_else(): void {
		// The guard that stops a caller dropping a whole object into the row
		// "just in case" — which is how a log ends up holding a password hash,
		// a session token or somebody's full profile for ninety days.
		$kept = diluxone_users_log_detail(
			array(
				'via'      => 'password',
				'closed'   => 3,
				'session'  => array( 'token' => 'secreto' ),
				'user'     => new \stdClass(),
				'MiXeD Ke' => 'x',
			)
		);

		$this->assertSame( 'password', $kept['via'] );
		$this->assertSame( '3', $kept['closed'] );
		$this->assertArrayNotHasKey( 'session', $kept );
		$this->assertArrayNotHasKey( 'user', $kept );
		$this->assertArrayHasKey( 'mixedke', $kept );
	}

	public function test_the_retention_never_comes_back_negative(): void {
		// It is multiplied by a day and subtracted from now. A negative would
		// put the edge in the future and the purge would delete the lot.
		update_option( 'diluxone_users_log_days', -30 );

		$this->assertSame( 0, diluxone_users_log_days() );
	}
}
