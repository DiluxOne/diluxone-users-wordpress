<?php
/**
 * A refusal answers with the status that says why.
 *
 * wp_die() answers 500 when it is given no status, which tells a browser, a
 * proxy and a monitoring tool that the server broke. Somebody who is not
 * signed in is 401, somebody without the right is 403: neither is an error of
 * the site's.
 *
 * @package DiluxOneUsers
 */

namespace Tests\Integration;

class RefusalStatusTest extends IntegrationTestCase {

	public function test_the_tools_refuse_somebody_without_the_capability_with_403(): void {
		$this->postAs( $this->make_user(), array( 'diluxone_users_tool' => 'flush' ) );

		$this->expectDie( 'diluxone_users_tools_action', 'You are not allowed to do this.', 403 );
	}

	public function test_emptying_the_log_refuses_somebody_without_the_capability_with_403(): void {
		$this->postAs( $this->make_user( 'editor' ), array() );

		$this->expectDie( 'diluxone_users_log_empty', 'You are not allowed to do this.', 403 );
	}

	public function test_emptying_the_networks_log_refuses_a_site_administrator_with_403(): void {
		$this->postAs( $this->make_user( 'administrator' ), array() );

		$this->expectDie( 'diluxone_users_log_empty_network', 'You are not allowed to do this.', 403 );
	}

	public function test_the_membership_sync_refuses_a_site_administrator_with_403(): void {
		$this->postAs( $this->make_user( 'administrator' ), array() );

		$this->expectDie( 'diluxone_users_membership_sync_request', 'You are not allowed to do this.', 403 );
	}

	public function test_the_fields_form_refuses_somebody_signed_out_with_401(): void {
		$this->postAs( 0, array( '_wpnonce' => 'x' ) );

		$this->expectDie( 'diluxone_users_save_fields_form', 'You have to sign in first.', 401 );
	}
}
