<?php
/**
 * Reports › Activity and its settings: what is drawn, and what is saved.
 *
 * The groups the log records are saved only behind the screen's nonce and its
 * capability — on a network, the network's, from Network Admin — and only the
 * groups the plugin has are kept. The report says what each row is about, a
 * row with nobody behind it shows nobody, a long report is paged, and a
 * network past a hundred sites is filtered by number. The rail says what is
 * and is not being recorded and for how long.
 *
 * Every case runs on both topologies unless it says otherwise.
 */

namespace Tests\Integration;

use Tests\Integration\Support\CoverAdminSupport;

class CoverAdminLogTest extends IntegrationTestCase {

	use CoverAdminSupport;

	/** @var mixed */
	private $moved;

	protected function setUp(): void {
		parent::setUp();

		diluxone_users_log_install();
		$this->moved = diluxone_users_raw_get( DILUXONE_USERS_LOG_MOVED, null );
	}

	protected function tearDown(): void {
		remove_filter( 'sites_pre_query', array( $this, 'a_hundred_and_one_sites' ) );

		if ( null === $this->moved ) {
			diluxone_users_delete_option( DILUXONE_USERS_LOG_MOVED );
		} else {
			diluxone_users_update_option( DILUXONE_USERS_LOG_MOVED, $this->moved );
		}

		$this->cover_admin_reset();

		parent::tearDown();
	}

	/**
	 * The `sites_pre_query` filter: a network past a hundred sites, without making them.
	 *
	 * @param mixed $sites
	 * @return mixed
	 */
	public function a_hundred_and_one_sites( $sites, \WP_Site_Query $query ) {
		return 'ids' === $query->query_vars['fields'] && 101 === (int) $query->query_vars['number'] ? range( 1, 101 ) : $sites;
	}

	/* ── The settings ──────────────────────────────────────────────── */

	public function test_only_the_groups_the_plugin_has_are_saved_with_the_days(): void {
		$this->where_network_screens_are();
		$this->the_admin();

		$this->postPanel(
			DILUXONE_USERS_REPORTS,
			array(
				'diluxone_users_log_levels' => array( 'access', 'not-a-group', 'Security' ),
				'diluxone_users_log_days'   => '30',
			)
		);

		$this->assertSame( 'returned', $this->ended( 'diluxone_users_log_settings_save' )[0] );
		$this->assertSame( array( 'access', 'security' ), diluxone_users_log_levels() );
		$this->assertSame( 30, diluxone_users_log_days() );
	}

	/**
	 * `diluxone_users_log_levels[0][]=access` is a list inside the list: no
	 * group, and no "Array to string" warning on the way to finding that out.
	 */
	public function test_a_group_sent_as_a_list_is_no_group(): void {
		$this->where_network_screens_are();
		$this->the_admin();

		$this->postPanel(
			DILUXONE_USERS_REPORTS,
			array(
				'diluxone_users_log_levels' => array( array( 'access' ), 'security' ),
				'diluxone_users_log_days'   => '30',
			)
		);

		$warnings = array();
		set_error_handler(
			static function ( int $level, string $message ) use ( &$warnings ): bool {
				$warnings[] = $message;

				return true;
			}
		);

		try {
			$this->assertSame( 'returned', $this->ended( 'diluxone_users_log_settings_save' )[0] );
		} finally {
			restore_error_handler();
		}

		$this->assertSame( array(), $warnings );
		$this->assertSame( array( 'security' ), diluxone_users_log_levels() );
	}

	/**
	 * No days sent is the default, and a minus sign is dropped: the box takes
	 * no negative number, so a "-7" is a typing slip for seven, never an
	 * order to keep nothing.
	 */
	public function test_days_nobody_sent_are_the_default_and_a_minus_sign_is_dropped(): void {
		$this->where_network_screens_are();
		$this->the_admin();

		$this->postPanel( DILUXONE_USERS_REPORTS, array( 'diluxone_users_log_levels' => array( 'access' ) ) );
		$this->assertSame( 'returned', $this->ended( 'diluxone_users_log_settings_save' )[0] );
		$this->assertSame( 90, diluxone_users_log_days() );

		$this->postPanel(
			DILUXONE_USERS_REPORTS,
			array(
				'diluxone_users_log_levels' => array( 'access' ),
				'diluxone_users_log_days'   => '-7',
			)
		);
		$this->assertSame( 'returned', $this->ended( 'diluxone_users_log_settings_save' )[0] );
		$this->assertSame( 7, diluxone_users_log_days() );
	}

	public function test_the_settings_are_not_saved_without_the_capability(): void {
		$this->where_network_screens_are();

		// A site's administrator in Network Admin, or an editor on a single site.
		$who = $this->make_user( is_multisite() ? 'administrator' : 'editor' );
		wp_set_current_user( $who );
		$this->postAs(
			$who,
			array(
				'diluxone_users_log_levels'  => array( 'access' ),
				'diluxone_users_log_days'    => '1',
				'diluxone_users_panel_nonce' => wp_create_nonce( 'diluxone_users_panel_' . DILUXONE_USERS_REPORTS ),
			)
		);

		$before = diluxone_users_raw_get( 'diluxone_users_log_days' );

		$this->assertSame( array( 'died', 'You are not allowed to do this.' ), $this->ended( 'diluxone_users_log_settings_save' ) );
		$this->assertSame( $before, diluxone_users_raw_get( 'diluxone_users_log_days' ) );
	}

	public function test_the_rail_says_when_nothing_is_recorded_and_that_rows_are_kept_for_ever(): void {
		$this->the_admin();
		diluxone_users_update_option( 'diluxone_users_log_levels', array() );
		diluxone_users_update_option( 'diluxone_users_log_days', 0 );

		$html = $this->draw( 'diluxone_users_log_aside_state' );

		$this->assertStringContainsString( 'Nothing is being recorded on this site.', $html );
		$this->assertStringContainsString( 'kept for ever', $html );
	}

	public function test_in_network_admin_the_rail_speaks_for_every_site(): void {
		$this->network_only( 'test_the_rail_says_when_nothing_is_recorded_and_that_rows_are_kept_for_ever' );

		$this->in_network_admin();
		$this->the_admin();
		diluxone_users_update_option( 'diluxone_users_log_levels', array() );

		$this->assertStringContainsString( 'Nothing is being recorded on any site of the network.', $this->draw( 'diluxone_users_log_aside_state' ) );
	}

	public function test_the_box_that_empties_the_log_says_how_many_rows_went(): void {
		$this->the_admin();
		$_GET = array( 'diluxone-users-emptied' => '1234' );

		$html = $this->draw( 'diluxone_users_log_empty_box', 'site' );

		$this->assertStringContainsString( 'The activity log was emptied: 1,234 rows deleted.', $html );
	}

	/* ── What a row says ───────────────────────────────────────────── */

	public function test_each_kind_of_row_says_what_it_was_about(): void {
		$row = static fn( string $event, array $detail ): array => array(
			'event'   => $event,
			'detail'  => $detail,
			'site_id' => diluxone_users_log_site(),
		);

		$this->assertSame( 'bob<script>', diluxone_users_log_says( $row( 'sign_in_failed', array( 'tried' => 'bob<script>' ) ) ), 'escaped where it is printed' );
		$this->assertSame( 'a@x.test → b@x.test', diluxone_users_log_says( $row( 'email_changed', array( 'was' => 'a@x.test', 'now' => 'b@x.test' ) ) ) );
		$this->assertSame( 'Ana', diluxone_users_log_says( $row( 'name_changed', array( 'now' => 'Ana' ) ) ) );
		$this->assertSame( 'phone, country', diluxone_users_log_says( $row( 'profile_saved', array( 'fields' => 'phone, country' ) ) ) );
		$this->assertSame( '2 on the account now', diluxone_users_log_says( $row( 'passkey_removed', array( 'keys' => 2 ) ) ) );
		$this->assertSame( '3 closed', diluxone_users_log_says( $row( 'sessions_closed', array( 'closed' => 3 ) ) ) );
	}

	/* ── The report ────────────────────────────────────────────────── */

	public function test_a_long_report_is_paged_and_a_row_without_a_person_shows_nobody(): void {
		$this->the_admin();
		diluxone_users_update_option( 'diluxone_users_log_levels', array( 'access' ) );

		for ( $i = 0; $i < 6; $i++ ) {
			diluxone_users_log_record( 'sign_in_failed', 0, array( 'tried' => 'ghost' . $i ) );
		}

		$_GET = array(
			'per' => '5',
			's'   => '',
		);
		$html = $this->draw( 'diluxone_users_log_report', false );

		$this->assertStringContainsString( 'ghost5', $html, 'newest first' );
		$this->assertStringContainsString( '<span class="diluxone-users-list__mail">—</span>', $html );
		$this->assertStringContainsString( 'tablenav-pages', $html );
		$this->assertStringContainsString( 'paged=2', $html );
	}

	public function test_a_network_past_a_hundred_sites_is_filtered_by_number(): void {
		$this->network_only( 'test_a_long_report_is_paged_and_a_row_without_a_person_shows_nobody' );

		add_filter( 'sites_pre_query', array( $this, 'a_hundred_and_one_sites' ), 10, 2 );

		$this->assertNull( diluxone_users_log_site_choices() );

		$this->in_network_admin();
		$this->the_admin();
		$_GET = array( 'site' => '7' );

		$html = $this->draw( 'diluxone_users_log_report', true );

		$this->assertMatchesRegularExpression( '/<input type="number" id="diluxone-users-log-site" name="site"[^>]*value="7"/', $html );
	}

	public function test_while_old_rows_are_still_moving_both_reports_say_so(): void {
		$this->network_only( 'test_a_long_report_is_paged_and_a_row_without_a_person_shows_nobody' );

		diluxone_users_update_option( DILUXONE_USERS_LOG_MOVED, 0 );
		$this->the_admin();

		$this->assertStringContainsString( 'This site’s older rows are still being moved', $this->draw( 'diluxone_users_log_moving_notice', false ) );
		$this->assertStringContainsString( 'The sites’ older rows are still being moved', $this->draw( 'diluxone_users_log_moving_notice', true ) );

		diluxone_users_update_option( DILUXONE_USERS_LOG_MOVED, 1 );
		$this->assertSame( '', $this->draw( 'diluxone_users_log_moving_notice', true ) );
	}

	public function test_a_single_site_has_nothing_to_move(): void {
		$this->single_only( 'test_while_old_rows_are_still_moving_both_reports_say_so' );

		$this->the_admin();

		$this->assertSame( '', $this->draw( 'diluxone_users_log_moving_notice', false ) );
	}
}
