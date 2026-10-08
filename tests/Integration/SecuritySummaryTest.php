<?php
/**
 * The Security summary, read as data instead of as a screen.
 *
 * The browser suite proves the table is on the screen, is the first tab, and
 * changes when a person presses Save. What it cannot do cheaply is walk every
 * awkward combination of the settings behind it, and those are where a
 * summary goes wrong: it is the one screen that can be quietly false — no
 * control on it can fail to save, and a row quoting a default reads exactly
 * like a row quoting the setting.
 *
 * So these go at the rows themselves. Every one of them writes an option and
 * asks what the summary then says, which is the only question worth asking of
 * a screen whose whole job is to report.
 *
 * The rows are found by the tab they link to and never by their label: the
 * labels are translated eight ways, the slugs are the plugin's own.
 */

namespace Tests\Integration;

class SecuritySummaryTest extends IntegrationTestCase {

	protected function setUp(): void {
		parent::setUp();

		// The panels are registered on an admin request, which a test is not.
		// Registering twice is harmless — the registry is keyed by slug — so
		// this simply makes sure there is something to read.
		do_action( 'diluxone_users_register_panels' );
	}

	/**
	 * The rows whose "change it" link lands on one tab of Security.
	 *
	 * @return array<int, array<string, string>>
	 */
	private function rows_for( string $tab ): array {
		$needle = 'page=' . DILUXONE_USERS_SECURITY . '&tab=' . $tab;

		return array_values(
			array_filter(
				diluxone_users_security_rows(),
				static fn( array $row ): bool => false !== strpos( $row['url'], $needle )
			)
		);
	}

	public function test_the_summary_is_the_first_tab_and_saves_nothing(): void {
		// On a network, Security is a screen of Network Admin.
		$this->in_network_admin();

		$panels = diluxone_users_panels( DILUXONE_USERS_SECURITY );

		$this->assertSame( 'summary', array_key_first( $panels ), 'Security has to open on its summary, the way Access does.' );
		$this->assertFalse( $panels['summary']['form'], 'A summary draws no form: there is nothing on it to save.' );
		$this->assertSame( '', $panels['summary']['save'] );
	}

	/** Every contributor speaks the one vocabulary, add-ons included. */
	public function test_every_row_carries_a_state_the_pill_knows(): void {
		$states = array_keys( diluxone_users_states() );

		$this->assertNotEmpty( diluxone_users_security_rows() );

		foreach ( diluxone_users_security_rows() as $row ) {
			$this->assertContains( $row['state'], $states, 'A row with a state nothing can draw: ' . $row['label'] );
			$this->assertNotSame( '', $row['label'] );
		}
	}

	/** A row added by something that is not this plugin still has to be a row. */
	public function test_a_row_with_no_state_is_left_out(): void {
		$junk = static function ( array $rows ): array {
			$rows[] = array( 'detail' => 'no label, no state, no business being drawn' );

			return $rows;
		};

		add_filter( 'diluxone_users_security_summary', $junk, 99 );

		$details = array_column( diluxone_users_security_rows(), 'detail' );

		remove_filter( 'diluxone_users_security_summary', $junk, 99 );

		$this->assertNotContains( 'no label, no state, no business being drawn', $details );
	}

	public function test_the_second_step_row_reads_the_saved_mode(): void {
		diluxone_users_update_option( 'diluxone_users_2fa_mode', 'off' );
		$this->assertSame( 'off', $this->rows_for( '2fa' )[0]['state'] );

		diluxone_users_update_option( 'diluxone_users_2fa_mode', 'required' );
		$this->assertSame( 'active', $this->rows_for( '2fa' )[0]['state'] );

		diluxone_users_update_option( 'diluxone_users_2fa_mode', 'optional' );
		$this->assertSame( 'active', $this->rows_for( '2fa' )[0]['state'] );
	}

	/**
	 * The state the tab that sets it cannot see.
	 *
	 * Required, of nobody — an add-on's list of roles nobody filled in — is a
	 * site that believes it asks for a code and asks nobody for anything.
	 */
	public function test_required_of_nobody_is_pending_and_says_so(): void {
		diluxone_users_update_option( 'diluxone_users_2fa_mode', 'required' );
		$this->hook( 'diluxone_users_2fa_who', '__return_empty_string' );

		$row = $this->rows_for( '2fa' )[0];

		$this->assertSame( 'pending', $row['state'] );
		$this->assertNotSame( '', $row['why'], 'A pending pill is never shown bare.' );
	}

	/** Whom it reaches, in the add-on's words when one narrows it, and everybody's when none does. */
	public function test_the_second_step_row_names_whom_it_reaches(): void {
		diluxone_users_update_option( 'diluxone_users_2fa_mode', 'required' );

		$this->assertStringContainsString( 'everybody with an account', $this->rows_for( '2fa' )[0]['detail'] );

		$this->hook( 'diluxone_users_2fa_who', static fn(): string => 'Editor' );

		$this->assertStringContainsString( 'Editor', $this->rows_for( '2fa' )[0]['detail'] );
	}

	/** What carries the code is read from what is ticked, not from the default. */
	public function test_the_method_row_follows_the_methods_that_are_on(): void {
		$this->hook(
			'diluxone_users_2fa_methods',
			static function ( array $methods ): array {
				$methods['device'] = array(
					'channel'  => 'device',
					'label'    => 'A device',
					'ready'    => '__return_true',
					'verify'   => '__return_false',
					'position' => 10,
				);

				return $methods;
			}
		);
		diluxone_users_update_option( 'diluxone_users_2fa_mode', 'optional' );
		diluxone_users_update_option( 'diluxone_users_2fa_methods', array( 'device' ) );

		$one = $this->rows_for( '2fa' )[1];

		$this->assertSame( 'active', $one['state'] );

		// The words are translated eight ways, so what is asserted is that
		// the line moves when the ticks move: one method reads differently
		// from two, whatever language the site is in.
		diluxone_users_update_option( 'diluxone_users_2fa_methods', array( 'device', 'email' ) );

		$this->assertNotSame( $one['detail'], $this->rows_for( '2fa' )[1]['detail'] );

		diluxone_users_update_option( 'diluxone_users_2fa_methods', array() );

		$this->assertSame( 'off', $this->rows_for( '2fa' )[1]['state'], 'Nothing ticked means nothing carries the code.' );
	}

	public function test_the_session_row_quotes_the_numbers_that_are_saved(): void {
		diluxone_users_update_option( 'diluxone_users_session_long_days', 21 );
		diluxone_users_update_option( 'diluxone_users_session_short_days', 3 );

		$detail = $this->rows_for( 'sessions' )[0]['detail'];

		$this->assertStringContainsString( '21', $detail );
		$this->assertStringContainsString( '3', $detail );
		$this->assertStringNotContainsString( '30', $detail, 'The default is 30: this row is reading it instead of the setting.' );
	}

	public function test_the_sessions_row_follows_whether_people_see_their_own(): void {
		diluxone_users_update_option( 'diluxone_users_sessions_show', 1 );
		$this->assertSame( 'active', $this->rows_for( 'sessions' )[1]['state'] );

		diluxone_users_update_option( 'diluxone_users_sessions_show', 0 );
		$this->assertSame( 'off', $this->rows_for( 'sessions' )[1]['state'] );
	}

	/** The one line of the subject that is not on Security at all. */
	public function test_the_open_sessions_row_leads_to_the_report(): void {
		$row = array_values(
			array_filter(
				diluxone_users_security_rows(),
				static fn( array $one ): bool => false !== strpos( $one['url'], 'page=' . DILUXONE_USERS_REPORTS )
			)
		);

		$this->assertCount( 1, $row );
		$this->assertStringContainsString( 'tab=sessions', $row[0]['url'] );
	}

	public function test_the_proxy_row_names_the_header_that_is_read(): void {
		diluxone_users_update_option( 'diluxone_users_ip_header', 'HTTP_CF_CONNECTING_IP' );

		$row = $this->rows_for( 'proxy' )[0];

		$this->assertSame( 'active', $row['state'] );
		$this->assertStringContainsString( 'CF-Connecting-IP', $row['detail'] );
	}

	public function test_the_proxy_row_says_nothing_is_set_when_nothing_is(): void {
		diluxone_users_delete_option( 'diluxone_users_ip_header' );
		diluxone_users_delete_option( 'diluxone_users_trusted_proxies' );

		$row = $this->rows_for( 'proxy' )[0];

		$this->assertSame( 'off', $row['state'] );
		$this->assertStringNotContainsString(
			'X-Forwarded-For',
			$row['detail'],
			'With nothing chosen the row must not name the header it would fall back to: it is not reading one.'
		);
	}
}
