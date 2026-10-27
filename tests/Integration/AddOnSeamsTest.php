<?php
/**
 * The seams an add-on's way in builds on, each one tried from outside.
 *
 * Every hook here is public API (docs/extending.md): an add-on that brings a
 * way in of its own — Users+ Pro's passkeys are the first — puts a panel on
 * the account's Security section, a row and a box on Access, facts and tick
 * boxes on a person's profile, counts and checks on the reports, events in
 * the activity log, and its forms among the hub's. Each test hooks the seam
 * the way an add-on would and reads what the plugin draws or does with it,
 * and what it refuses: a key that is not a setting, a group that does not
 * exist, a row without a state.
 */

namespace Tests\Integration;

use Tests\Integration\Support\CoverAdminSupport;

class AddOnSeamsTest extends IntegrationTestCase {

	use CoverAdminSupport;

	private const SWITCH = 'diluxone_users_addon_door';

	private int $user;

	protected function setUp(): void {
		parent::setUp();

		$this->user = $this->make_user();
	}

	protected function tearDown(): void {
		diluxone_users_delete_option( self::SWITCH );
		$this->cover_admin_reset();
		parent::tearDown();
	}

	/** The add-on's switch, as a setting of the plugin's namespace with a scope. */
	private function the_switch_is_a_setting(): void {
		$this->hook(
			'diluxone_users_option_defaults',
			static function ( array $defaults ): array {
				$defaults[ self::SWITCH ] = 0;

				return $defaults;
			}
		);
		// Where Access is drawn: the hub on a network, the site on its own.
		$this->hook( 'diluxone_users_option_scope', static fn( string $scope, string $key ): string => self::SWITCH === $key ? 'hub' : $scope, 10, 2 );
	}

	/** The box an add-on's way in puts on Access › Ways in. */
	private function a_switch( string $key = self::SWITCH ): void {
		$this->hook(
			'diluxone_users_way_switches',
			static function ( array $switches ) use ( $key ): array {
				$switches[ $key ] = array(
					'title'    => 'A door of the add-on',
					'help'     => 'Opens with something the person has.',
					'settings' => array(
						'url'   => 'https://example.test/door-settings',
						'label' => 'The door’s own settings',
						'help'  => 'Where the rest of it is.',
					),
				);

				return $switches;
			}
		);
	}

	/* ── The account's Security section ───────────────────────────── */

	public function test_an_addon_draws_a_panel_between_the_way_in_and_the_second_step(): void {
		diluxone_users_update_option( 'diluxone_users_2fa_mode', 'optional' );
		diluxone_users_update_option( 'diluxone_users_2fa_methods', array( 'email' ) );

		$seen = null;
		$this->hook(
			'diluxone_users_account_security',
			static function ( \WP_User $user ) use ( &$seen ): void {
				$seen = $user->ID;
				diluxone_users_panel_open( 'The add-on’s panel' );
				echo '<p>its own words</p>';
				diluxone_users_panel_close();
			}
		);

		wp_set_current_user( $this->user );
		ob_start();
		diluxone_users_section_security( get_userdata( $this->user ) );
		$html = (string) ob_get_clean();

		$this->assertSame( $this->user, $seen, 'it is told whose account it is' );

		$panel = strpos( $html, 'The add-on’s panel' );
		$this->assertNotFalse( $panel );
		$this->assertGreaterThan( strpos( $html, 'How you get in' ), $panel, 'after how they get in' );
		$this->assertLessThan( strpos( $html, 'Two-step verification' ), $panel, 'and before the second step it makes unnecessary' );
	}

	public function test_an_addon_adds_the_words_its_action_comes_back_with(): void {
		$this->hook(
			'diluxone_users_security_notices',
			static function ( array $notices ): array {
				$notices['addondone'] = array( 'ok', 'The add-on did its thing.' );
				$notices['broken']    = array( 'ok' );

				return $notices;
			}
		);

		wp_set_current_user( $this->user );
		$_GET = array( 'diluxone-users' => 'addondone' );
		ob_start();
		diluxone_users_section_security( get_userdata( $this->user ) );
		$html = (string) ob_get_clean();

		$this->assertStringContainsString( 'diluxone-users-notice--ok">The add-on did its thing.</p>', $html );

		$_GET = array( 'diluxone-users' => 'broken' );
		ob_start();
		diluxone_users_section_security( get_userdata( $this->user ) );
		$this->assertStringNotContainsString( 'diluxone-users-notice--ok', (string) ob_get_clean(), 'a notice without its sentence is not drawn' );
	}

	public function test_a_word_the_plugin_does_not_know_goes_to_the_addon_after_the_checks(): void {
		$heard = array();
		$this->hook(
			'diluxone_users_security_action',
			static function ( string $action, int $user_id, string $target ) use ( &$heard ): void {
				$heard = array( $action, $user_id, $target );
			},
			10,
			3
		);

		wp_set_current_user( $this->user );
		$this->postAs(
			$this->user,
			array(
				'_wpnonce'                => wp_create_nonce( 'diluxone_users_security' ),
				'diluxone_users_security' => 'addon_word',
			)
		);
		$to = $this->expectRedirect( 'diluxone_users_security_submit' );

		$this->assertSame( array( 'addon_word', $this->user, diluxone_users_account_url( 'security' ) ), $heard );
		$this->assertSame( diluxone_users_account_url( 'security' ), $to, 'an add-on that does not redirect sends the person back to the section' );
	}

	public function test_a_forged_word_never_reaches_the_addon(): void {
		$heard = false;
		$this->hook(
			'diluxone_users_security_action',
			static function () use ( &$heard ): void {
				$heard = true;
			}
		);

		$this->postAs( $this->user, array( 'diluxone_users_security' => 'addon_word' ) );

		try {
			diluxone_users_security_submit();
		} catch ( \Throwable $e ) {
			unset( $e ); // The nonce check ends the request.
		}

		$this->assertFalse( $heard );
	}

	/* ── A person's profile, in Users ─────────────────────────────── */

	public function test_an_addon_adds_a_chip_to_the_access_column(): void {
		$this->hook(
			'diluxone_users_person_chips',
			static function ( array $chips, int $user_id ): array {
				$chips[] = array( 'on', 'Door #' . $user_id );
				$chips[] = array( 'only one half' );

				return $chips;
			},
			10,
			2
		);

		$html = diluxone_users_users_column_row( '', 'diluxone_users', $this->user );

		$this->assertStringContainsString( 'diluxone-users-pill--on">Door #' . $this->user . '</span>', $html );
		$this->assertStringNotContainsString( 'Only the e-mail link', $html, 'an account with the add-on’s door is not one with the link only' );
		$this->assertStringNotContainsString( 'only one half', $html, 'a chip without its colour is dropped' );
	}

	public function test_an_addon_adds_facts_and_tick_boxes_to_the_profile(): void {
		$this->the_admin();

		$this->hook(
			'diluxone_users_person_rows',
			static function ( array $rows ): array {
				$rows[] = array(
					'label'  => 'The add-on’s keys',
					'state'  => 'active',
					'detail' => 'Two of them.',
				);

				return $rows;
			}
		);
		$this->hook(
			'diluxone_users_person_take_off',
			static function ( array $choices ): array {
				$choices[] = array(
					'type'  => 'checkbox',
					'name'  => 'addon_forget[]',
					'value' => 'k1',
					'title' => 'Remove the add-on’s key',
				);

				return $choices;
			}
		);

		$html = $this->draw( 'diluxone_users_profile_block', get_userdata( $this->user ) );

		$this->assertStringContainsString( 'The add-on’s keys', $html );
		$this->assertStringContainsString( 'Take a way in off this account', $html, 'with something to take off, the group is drawn' );
		$this->assertStringContainsString( 'name="addon_forget[]"', $html );
	}

	public function test_the_profile_save_tells_the_addon_once_it_is_allowed(): void {
		$saved = array();
		$this->hook(
			'diluxone_users_person_saved',
			static function ( int $user_id ) use ( &$saved ): void {
				$saved[] = $user_id;
			}
		);

		$admin = $this->the_admin();
		$this->postAs( $admin, array( '_wpnonce' => wp_create_nonce( 'update-user_' . $this->user ) ) );
		$this->ended( fn() => diluxone_users_profile_block_save( $this->user ) );

		$this->assertSame( array( $this->user ), $saved );

		$other = $this->make_user();
		wp_set_current_user( $other );
		$this->postAs( $other, array( '_wpnonce' => wp_create_nonce( 'update-user_' . $this->user ) ) );
		$this->ended( fn() => diluxone_users_profile_block_save( $this->user ) );

		$this->assertSame( array( $this->user ), $saved, 'somebody who cannot edit the account does not get that far' );
	}

	/* ── The reports ───────────────────────────────────────────────── */

	public function test_an_addon_adds_a_count_and_its_row_to_both_usage_screens(): void {
		$this->the_admin();

		$this->hook(
			'diluxone_users_stats',
			static function ( array $stats ): array {
				$stats['addon_keys'] = 7;
				$stats['users']      = 999999;
				$stats['nonsense']   = 'many';

				return $stats;
			}
		);
		$this->hook(
			'diluxone_users_usage',
			static function ( array $rows, array $stats ): array {
				$rows[] = array(
					'label'  => 'Added the add-on’s key',
					'detail' => 'Something they have.',
					'value'  => $stats['addon_keys'],
				);
				$rows[] = array( 'value' => 3 );

				return $rows;
			},
			10,
			2
		);

		$stats = diluxone_users_stats();

		$this->assertSame( 7, $stats['addon_keys'] );
		$this->assertNotSame( 999999, $stats['users'], 'the plugin’s own counts are kept as they are' );
		$this->assertArrayNotHasKey( 'nonsense', $stats, 'a count is a number' );

		$rows = diluxone_users_usage_rows( $stats );
		$this->assertSame( 'Added the add-on’s key', end( $rows )['label'] );
		$this->assertCount( 3, $rows, 'a row without a label is dropped' );

		$this->assertStringContainsString( 'Added the add-on’s key', $this->draw( 'diluxone_users_panel_usage' ) );
		$this->assertStringContainsString( 'Added the add-on’s key', $this->draw( 'diluxone_users_screen_status_checks' ) );
	}

	public function test_an_addon_adds_a_check_and_one_without_a_state_is_dropped(): void {
		$this->hook(
			'diluxone_users_checks',
			static function ( array $checks ): array {
				$checks[] = array(
					'label'  => 'The add-on’s door',
					'state'  => 'off',
					'detail' => 'Needs HTTPS.',
				);
				$checks[] = array( 'label' => 'Half a check' );

				return $checks;
			}
		);

		$checks = diluxone_users_checks();
		$labels = array_column( $checks, 'label' );

		$this->assertContains( 'The add-on’s door', $labels );
		$this->assertNotContains( 'Half a check', $labels );
		$this->assertSame( 'off', $checks[ array_search( 'The add-on’s door', $labels, true ) ]['state'] );
	}

	public function test_https_is_checked_for_itself(): void {
		$https = array_values( array_filter( diluxone_users_checks(), static fn( array $c ): bool => 'HTTPS' === $c['label'] ) );

		$this->assertCount( 1, $https );
		$this->assertContains( $https[0]['state'], array( 'active', 'pending' ), 'not served over HTTPS waits on the site; it breaks nothing of the plugin’s own' );
	}

	/* ── The activity log ──────────────────────────────────────────── */

	public function test_an_addon_records_events_of_its_own_in_a_group_that_exists(): void {
		$this->hook(
			'diluxone_users_log_events',
			static function ( array $events ): array {
				$events['addon_key_added'] = 'security';
				$events['addon_nowhere']   = 'gossip';
				$events['signed_in']       = 'security';

				return $events;
			}
		);
		$this->hook(
			'diluxone_users_log_labels',
			static function ( array $labels ): array {
				$labels['addon_key_added'] = 'Add-on key added';

				return $labels;
			}
		);

		$events = diluxone_users_log_events();

		$this->assertSame( 'security', $events['addon_key_added'] );
		$this->assertArrayNotHasKey( 'addon_nowhere', $events, 'an event in no group is never written' );
		$this->assertSame( 'access', $events['signed_in'], 'the plugin’s own stay where they are' );
		$this->assertSame( 'Add-on key added', diluxone_users_log_label( 'addon_key_added' ) );
		$this->assertSame( 'Signed in', diluxone_users_log_label( 'signed_in' ) );
	}

	public function test_an_addon_says_what_its_rows_are_about_and_names_its_door(): void {
		$this->hook(
			'diluxone_users_log_says',
			static fn( string $says, array $row ): string => 'addon_key_added' === $row['event'] ? (string) $row['detail']['keys'] . ' now' : $says,
			10,
			2
		);
		$this->hook(
			'diluxone_users_log_doors',
			static function ( array $doors ): array {
				$doors['addon'] = 'Add-on door';

				return $doors;
			}
		);

		$this->assertSame(
			'2 now',
			diluxone_users_log_says(
				array(
					'event'   => 'addon_key_added',
					'detail'  => array( 'keys' => 2 ),
					'site_id' => get_current_blog_id(),
				)
			)
		);
		$this->assertSame( 'Add-on door', diluxone_users_log_via( 'addon' ) );
		$this->assertSame( 'Password', diluxone_users_log_via( 'password' ) );
	}

	/* ── Access ───────────────────────────────────────────────────── */

	public function test_an_addons_box_is_drawn_with_the_ways_in_and_points_to_its_settings(): void {
		$this->the_admin();
		$this->the_switch_is_a_setting();
		$this->a_switch();
		diluxone_users_update_option( self::SWITCH, 1 );

		$html = $this->draw( 'diluxone_users_screen_login_ways' );

		$this->assertMatchesRegularExpression( '/name="' . self::SWITCH . '"\s+value="1"\s+checked/', $html );
		$this->assertStringContainsString( 'A door of the add-on', $html );
		$this->assertStringContainsString( 'https://example.test/door-settings', $html, 'its settings are listed beside the boxes' );
	}

	public function test_a_box_whose_key_is_not_a_setting_is_not_drawn(): void {
		$this->a_switch( 'diluxone_users_nobody_knows' );

		$this->assertSame( array(), diluxone_users_way_switches() );
	}

	public function test_the_save_writes_the_addons_box_only_while_it_is_drawn(): void {
		$this->the_switch_is_a_setting();
		diluxone_users_update_option( self::SWITCH, 1 );

		// The add-on is off: no box, and the save leaves its setting alone.
		$this->postPanel( 'diluxone-users-login', array( 'diluxone_users_login_method' => array( 'link', 'password' ) ) );
		$this->assertTrue( diluxone_users_login_ways_save() );
		$this->assertSame( 1, (int) diluxone_users_raw_get( self::SWITCH ), 'a box nobody drew does not turn its setting off' );

		// On: unticked is 0, ticked is 1.
		$this->a_switch();
		$this->postPanel( 'diluxone-users-login', array( 'diluxone_users_login_method' => array( 'link', 'password' ) ) );
		diluxone_users_login_ways_save();
		$this->assertSame( 0, (int) diluxone_users_raw_get( self::SWITCH ) );

		$this->postPanel(
			'diluxone-users-login',
			array(
				'diluxone_users_login_method' => array( 'link', 'password' ),
				self::SWITCH                  => '1',
			)
		);
		diluxone_users_login_ways_save();
		$this->assertSame( 1, (int) diluxone_users_raw_get( self::SWITCH ) );
	}

	public function test_an_addon_adds_its_door_to_the_summary_of_signing_in(): void {
		$this->hook(
			'diluxone_users_doors',
			static function ( array $rows ): array {
				$rows[] = array(
					'label' => 'The add-on’s door',
					'state' => 'active',
				);

				return $rows;
			}
		);

		$labels = array_column( diluxone_users_doors(), 'label' );
		$door   = array_search( 'The add-on’s door', $labels, true );

		$this->assertNotContains( 'Creating an account', $labels, 'registration lives on its own screen' );
	}

	public function test_a_strong_way_in_goes_straight_in_and_the_rest_are_asked_the_second_step(): void {
		diluxone_users_register_way(
			'addon_strong_test',
			array(
				'label'     => 'Strong',
				'render'    => '__return_null',
				'available' => '__return_false',
				'outside'   => true,
				'strong'    => true,
			)
		);

		$this->assertTrue( diluxone_users_way_strong( 'addon_strong_test' ) );
		$this->assertFalse( diluxone_users_way_strong( 'password' ), 'the plugin’s own are not' );
		$this->assertFalse( diluxone_users_way_strong( 'nobody' ) );

		diluxone_users_update_option( 'diluxone_users_2fa_mode', 'required' );
		diluxone_users_update_option( 'diluxone_users_2fa_methods', array( 'email' ) );
		$this->assertTrue( diluxone_users_2fa_required( $this->user, 'addon_strong_test' ), 'the second step would be asked of this door' );

		$this->expectRedirect( fn() => diluxone_users_complete_login( $this->user, 'addon_strong_test', false, home_url( '/after/' ) ) );

		$this->assertSame( $this->user, get_current_user_id(), 'and it was not: the session is open' );
	}

	/* ── Everything else ───────────────────────────────────────────── */

	public function test_an_addons_forms_go_to_the_hub(): void {
		$this->hook(
			'diluxone_users_hub_posts',
			static function ( array $posts ): array {
				$posts[] = 'addon_manage';

				return $posts;
			}
		);

		$posts = diluxone_users_hub_posts();

		$this->assertContains( 'addon_manage', $posts );
		$this->assertContains( 'diluxone_users_security', $posts, 'and the plugin’s own stay' );

		// Hooked on `init`, after the add-on has loaded: its action is sent
		// to the hub from a site, and to sign in when nobody is signed in.
		diluxone_users_hub_posts_hook();

		$this->assertSame( 0, has_action( 'admin_post_addon_manage', 'diluxone_users_post_to_hub' ) );
		$this->assertSame( 0, has_action( 'admin_post_nopriv_addon_manage', 'diluxone_users_post_signed_out' ) );

		remove_action( 'admin_post_addon_manage', 'diluxone_users_post_to_hub', 0 );
		remove_action( 'admin_post_nopriv_addon_manage', 'diluxone_users_post_signed_out', 0 );
	}

	public function test_an_addon_names_its_door_in_the_new_device_email(): void {
		$this->hook(
			'diluxone_users_via_labels',
			static function ( array $labels ): array {
				$labels['addon'] = 'a key of the add-on';

				return $labels;
			}
		);

		$this->assertSame( 'a key of the add-on', diluxone_users_via_label( 'addon' ) );
		$this->assertSame( 'your password', diluxone_users_via_label( 'password' ) );
	}

	public function test_no_field_is_named_after_what_an_addon_keeps(): void {
		$this->hook(
			'diluxone_users_reserved_meta_keys',
			static function ( array $keys ): array {
				$keys[] = 'diluxone_users_field_addon_list';

				return $keys;
			}
		);
		$this->hook(
			'diluxone_users_reserved_meta_prefixes',
			static function ( array $prefixes ): array {
				$prefixes[] = 'diluxone_users_field_addon_ix_';

				return $prefixes;
			}
		);

		$this->assertFalse( diluxone_users_field_key_allowed( 'diluxone_users_field_addon_list' ) );
		$this->assertFalse( diluxone_users_field_key_allowed( 'diluxone_users_field_addon_ix_abc' ) );
		$this->assertTrue( diluxone_users_field_key_allowed( 'diluxone_users_field_colour' ) );
	}

	/* ── Where Users+ Pro's passkeys would go ─────────────────────── */

	public function test_the_pro_box_follows_the_ways_in_and_the_pro_row_ends_the_security_summary(): void {
		$this->the_admin();

		$ways = $this->draw( 'diluxone_users_screen_login_ways' );
		$box  = strpos( $ways, 'data-diluxone-users-pro="login/ways/more"' );

		$this->assertNotFalse( $box );
		$this->assertGreaterThan( strpos( $ways, 'name="diluxone_users_sso_login"' ), $box, 'after the real ways in' );
		$this->assertMatchesRegularExpression( '/<input\s+type="checkbox"\s+value=""[^>]*disabled/', substr( $ways, $box ), 'the box it would be, switched off and nameless' );

		$summary = $this->draw( 'diluxone_users_screen_security_summary' );
		$row     = strpos( $summary, 'data-diluxone-users-pro="security/summary/more"' );

		$this->assertNotFalse( $row );
		$this->assertStringContainsString( 'class="du-pro du-pro--row"', $summary, 'a row of the table, not a picture under it' );
		$this->assertStringNotContainsString( 'du-pro__picture', $summary );
		$this->assertSame( 1, substr_count( $summary, '<table' ), 'one table' );
	}

	public function test_an_addon_that_brings_them_turns_both_placeholders_off(): void {
		$this->the_admin();
		$this->hook( 'diluxone_users_upsell', static fn( bool $show, string $where ): bool => ! in_array( $where, array( 'login/ways/more', 'security/summary/more' ), true ) && $show, 10, 2 );

		$this->assertStringNotContainsString( 'data-diluxone-users-pro', $this->draw( 'diluxone_users_screen_login_ways' ) );
		$this->assertStringNotContainsString( 'data-diluxone-users-pro', $this->draw( 'diluxone_users_screen_security_summary' ) );
	}
}
