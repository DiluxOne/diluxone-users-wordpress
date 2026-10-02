<?php
/**
 * What the plugin adds to WordPress's own Users screens: the Access column and
 * the block on somebody's profile, with the three ways in it can take off.
 *
 * The block is read-only for anybody who cannot edit people, and the save that
 * takes a way in off an account checks the profile form's nonce and whether
 * whoever sent it may edit that account, before it touches anything. What it
 * is sent is cleaned: a provider that does not exist unlinks nothing.
 *
 * Every case runs on both topologies.
 */

namespace Tests\Integration;

use Tests\Integration\Support\CoverAdminSupport;

class CoverAdminPeopleTest extends IntegrationTestCase {

	use CoverAdminSupport;

	protected function tearDown(): void {
		$this->cover_admin_reset();

		parent::tearDown();
	}

	/** Somebody with every way in the block reports on. */
	private function equipped(): int {
		$user = $this->make_user();

		update_user_meta( $user, 'diluxone_users_handle', 'river_song' );
		update_user_meta( $user, 'diluxone_users_totp', 'JBSWY3DPEHPK3PXP' );
		update_user_meta( $user, 'diluxone_users_sso_google', 'g-123' );
		diluxone_users_passkeys_save(
			$user,
			array(
				array( 'id' => 'key-one', 'label' => 'Laptop', 'created' => 1700000000 ),
				array( 'id' => 'key-two', 'label' => 'Phone', 'created' => 1700000000 ),
			)
		);

		$_SERVER['HTTP_USER_AGENT'] = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0 Safari/537.36';
		\WP_Session_Tokens::get_instance( $user )->create( time() + HOUR_IN_SECONDS );
		unset( $_SERVER['HTTP_USER_AGENT'] );

		return $user;
	}

	/* ── The column ────────────────────────────────────────────────── */

	public function test_the_column_is_added_and_only_answers_for_itself(): void {
		$columns = diluxone_users_users_column( array( 'username' => 'Username' ) );

		$this->assertSame( 'Access', $columns['diluxone_users'] );
		$this->assertSame( 'untouched', diluxone_users_users_column_row( 'untouched', 'email', 1 ) );
	}

	public function test_somebody_with_nothing_but_the_link_says_so(): void {
		$html = diluxone_users_users_column_row( '', 'diluxone_users', $this->make_user() );

		$this->assertSame( '<span class="diluxone-users-pill diluxone-users-pill--off">Only the e-mail link</span>', $html );
	}

	public function test_the_public_name_is_the_one_chosen_and_none_when_none_was(): void {
		$user = $this->make_user();

		$this->assertSame( '', diluxone_users_person( $user )['handle'], 'the account\'s own slug is not a public name anybody chose' );

		update_user_meta( $user, 'diluxone_users_handle', 'chosen-name' );
		$this->assertSame( 'chosen-name', diluxone_users_person( $user )['handle'] );
	}

	public function test_somebody_with_every_way_in_gets_a_chip_for_each(): void {
		diluxone_users_update_option( 'diluxone_users_2fa_mode', 'required' );
		diluxone_users_update_option( 'diluxone_users_2fa_methods', array( 'email' ) );

		$user = $this->equipped();

		$this->assertTrue( diluxone_users_person( $user )['second_step'] );

		$html = diluxone_users_users_column_row( '', 'diluxone_users', $user );

		$this->assertStringContainsString( '>2FA</span>', $html );
		$this->assertStringContainsString( '>App</span>', $html );
		$this->assertStringContainsString( '>2 passkeys</span>', $html );
		$this->assertStringContainsString( 'diluxone-users-pill--blank">Google</span>', $html );
	}

	/* ── The block ─────────────────────────────────────────────────── */

	public function test_the_block_reports_and_offers_each_way_in_to_take_off(): void {
		$user = $this->equipped();
		$this->the_admin();

		$html = $this->draw( 'diluxone_users_profile_block', get_userdata( $user ) );

		$this->assertStringContainsString( '<code>river_song</code>', $html );
		$this->assertStringContainsString( 'ago, from', $html, 'the last session' );
		$this->assertStringContainsString( 'An authenticator app is set up.', $html );
		$this->assertStringContainsString( '2 passkeys on this account.', $html );
		$this->assertStringContainsString( 'Google', $html );
		$this->assertStringContainsString( 'name="diluxone_users_forget_totp"', $html );
		$this->assertStringContainsString( 'value="key-one"', $html );
		$this->assertStringContainsString( 'Remove “Laptop”', $html );
		$this->assertStringContainsString( 'name="diluxone_users_unlink[]"', $html );
		$this->assertStringContainsString( 'Take a way in off this account', $html );
		$this->assertStringContainsString( 'tab=sessions', $html );
		$this->assertStringContainsString( 's=' . get_userdata( $user )->user_email, $html, 'the sessions link searches for this account' );
	}

	public function test_an_account_with_nothing_has_nothing_to_take_off(): void {
		$user = $this->make_user();
		$this->the_admin();

		$html = $this->draw( 'diluxone_users_profile_block', get_userdata( $user ) );

		$this->assertStringContainsString( 'No open session.', $html );
		$this->assertStringContainsString( 'No authenticator app.', $html );
		$this->assertStringNotContainsString( 'Take a way in off this account', $html );
		$this->assertStringNotContainsString( 'type="checkbox"', $html );
		$this->assertStringContainsString( 'What this block does', $html, 'the rail says why there is no adding' );
	}

	public function test_somebody_who_cannot_edit_people_sees_no_block(): void {
		$user = $this->equipped();
		wp_set_current_user( $this->make_user( 'editor' ) );

		$this->assertSame( '', $this->draw( 'diluxone_users_profile_block', get_userdata( $user ) ) );
	}

	/* ── Its save ──────────────────────────────────────────────────── */

	public function test_what_is_ticked_is_taken_off_and_nothing_else(): void {
		$user = $this->equipped();
		update_user_meta( $user, 'diluxone_users_sso_github', 'gh-1' );
		$admin = $this->the_admin();

		$this->postAs(
			$admin,
			array(
				'_wpnonce'                      => wp_create_nonce( 'update-user_' . $user ),
				'diluxone_users_forget_totp'    => '1',
				'diluxone_users_forget_passkey' => array( 'key-one' ),
				'diluxone_users_unlink'         => array( 'google', 'not-a-provider', 'session_tokens' ),
			)
		);

		$this->assertSame( 'returned', $this->ended( fn() => diluxone_users_profile_block_save( $user ) )[0] );

		$this->assertFalse( diluxone_users_totp_ready( $user ) );
		$this->assertSame( array( 'key-two' ), array_column( diluxone_users_passkeys( $user ), 'id' ) );
		$this->assertSame( '', get_user_meta( $user, 'diluxone_users_sso_google', true ) );
		$this->assertSame( 'gh-1', get_user_meta( $user, 'diluxone_users_sso_github', true ), 'what was not ticked stays' );
		$this->assertNotEmpty( get_user_meta( $user, 'session_tokens', true ), 'only providers are unlinked: a name that is not one deletes nothing' );
	}

	public function test_a_save_without_the_profiles_nonce_stops_and_takes_nothing(): void {
		$user  = $this->equipped();
		$admin = $this->the_admin();

		$this->postAs(
			$admin,
			array(
				'_wpnonce'                   => wp_create_nonce( 'update-user_' . ( $user + 1 ) ),
				'diluxone_users_forget_totp' => '1',
			)
		);

		$this->assertSame( array( 'died', self::EXPIRED ), $this->ended( fn() => diluxone_users_profile_block_save( $user ) ) );
		$this->assertTrue( diluxone_users_totp_ready( $user ) );
	}

	public function test_somebody_who_may_not_edit_the_account_takes_nothing(): void {
		$user  = $this->equipped();
		$other = $this->make_user();
		wp_set_current_user( $other );

		$this->postAs(
			$other,
			array(
				'_wpnonce'                      => wp_create_nonce( 'update-user_' . $user ),
				'diluxone_users_forget_totp'    => '1',
				'diluxone_users_forget_passkey' => array( 'key-one' ),
				'diluxone_users_unlink'         => array( 'google' ),
			)
		);

		$this->assertSame( 'returned', $this->ended( fn() => diluxone_users_profile_block_save( $user ) )[0] );
		$this->assertTrue( diluxone_users_totp_ready( $user ) );
		$this->assertCount( 2, diluxone_users_passkeys( $user ) );
		$this->assertSame( 'g-123', get_user_meta( $user, 'diluxone_users_sso_google', true ) );
	}
}
