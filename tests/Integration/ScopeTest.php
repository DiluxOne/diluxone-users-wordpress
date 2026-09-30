<?php
/**
 * "To everybody" or "only to some roles".
 *
 * The old shape of this was a list of tick boxes where none ticked meant
 * everybody — which is the opposite of what the screen looked like it was
 * saying. These tests pin the new reading down, including the one case that
 * changed meaning: some roles chosen and none of them ticked now reaches
 * nobody, literally, instead of quietly reaching all.
 */

namespace Tests\Integration;

class ScopeTest extends IntegrationTestCase {

	private const PREFIX = 'diluxone_users_2fa';

	protected function setUp(): void {
		parent::setUp();

		diluxone_users_delete_option( self::PREFIX . '_scope' );
		diluxone_users_delete_option( self::PREFIX . '_roles' );
	}

	public function test_to_everybody_reaches_everybody(): void {
		diluxone_users_update_option( self::PREFIX . '_scope', 'all' );
		diluxone_users_update_option( self::PREFIX . '_roles', array( 'administrator' ) );

		$this->assertTrue(
			diluxone_users_scope_includes( $this->make_user( 'subscriber' ), self::PREFIX ),
			'A subscriber is reached even though only administrator is ticked: the radio rules, not the list.'
		);
	}

	public function test_to_some_reaches_only_those(): void {
		diluxone_users_update_option( self::PREFIX . '_scope', 'some' );
		diluxone_users_update_option( self::PREFIX . '_roles', array( 'editor' ) );

		$this->assertTrue( diluxone_users_scope_includes( $this->make_user( 'editor' ), self::PREFIX ) );
		$this->assertFalse( diluxone_users_scope_includes( $this->make_user( 'subscriber' ), self::PREFIX ) );
	}

	public function test_to_some_with_nothing_chosen_reaches_nobody(): void {
		diluxone_users_update_option( self::PREFIX . '_scope', 'some' );
		diluxone_users_update_option( self::PREFIX . '_roles', array() );

		$this->assertFalse( diluxone_users_scope_includes( $this->make_user( 'administrator' ), self::PREFIX ) );
	}

	/** With nothing stored, the answer is the default: everybody. */
	public function test_with_no_answer_stored_it_is_everybody(): void {
		diluxone_users_delete_option( self::PREFIX . '_scope' );
		diluxone_users_update_option( self::PREFIX . '_roles', array( 'editor' ) );

		$this->assertSame( 'all', diluxone_users_scope( self::PREFIX ), 'Roles kept from before do not change the answer' );
	}
}
