<?php
/**
 * "Delete my account" deletes the account, once the erasure is carried out.
 *
 * WordPress's erasure request empties an account and leaves it standing. A
 * request filed from the account area closes it as well: deleted when nothing
 * published is theirs, anonymised when something is, so what they wrote keeps
 * no name and nothing tied to their id breaks. A request an administrator
 * files from Tools stays WordPress's: data, not the account.
 */

namespace Tests\Integration;

class AccountClosingTest extends IntegrationTestCase {

	protected function setUp(): void {
		parent::setUp();

		require_once ABSPATH . 'wp-admin/includes/user.php';
	}

	/** Files the request the way the account area does, and carries it out. */
	private function erase_from_account( int $user_id, bool $from_account = true ): void {
		$email   = get_userdata( $user_id )->user_email;
		$request = wp_create_user_request(
			$email,
			'remove_personal_data',
			$from_account ? array( DILUXONE_USERS_CLOSE_KEY => $user_id ) : array(),
			'confirmed'
		);

		$this->assertIsInt( $request );

		do_action( 'wp_privacy_personal_data_erased', $request );
	}

	public function test_an_account_with_nothing_published_is_deleted(): void {
		// On a network, an account that is a member of this site only: under
		// "every site" a new account is a member of all of them.
		diluxone_users_update_option( 'diluxone_users_membership', 'invite' );
		$user = $this->make_user();

		$this->erase_from_account( $user );

		$this->assertFalse( get_userdata( $user ), 'Gone — on a network too, when this is its only site' );
	}

	/** Files a pending request from the account area and confirms it, as the e-mail's link does. */
	private function confirm_from_account( int $user_id ): int {
		$request = wp_create_user_request(
			get_userdata( $user_id )->user_email,
			'remove_personal_data',
			array( DILUXONE_USERS_CLOSE_KEY => $user_id )
		);

		$this->assertIsInt( $request );

		do_action( 'user_request_action_confirmed', $request );

		return $request;
	}

	/**
	 * Confirming is enough: the request is carried out there and then — every
	 * eraser, the request completed, the account closed — with no
	 * administrator to wait for.
	 */
	public function test_confirming_it_carries_it_out(): void {
		diluxone_users_update_option( 'diluxone_users_membership', 'invite' );
		$user = $this->make_user();
		update_user_meta( $user, 'diluxone_users_handle', 'someone-' . $user );

		$request = $this->confirm_from_account( $user );

		$this->assertFalse( get_userdata( $user ) );
		$this->assertSame( 'request-completed', get_post_status( $request ) );
		$this->assertStringContainsString( 'account closed', (string) apply_filters( 'user_request_action_confirmed_message', 'WordPress', $request ) );
	}

	/** A site that carries them out itself: confirming only confirms. */
	public function test_a_site_that_carries_them_out_itself_waits(): void {
		diluxone_users_update_option( 'diluxone_users_privacy_delete_when', 'admin' );
		$user = $this->make_user();

		$request = $this->confirm_from_account( $user );

		$this->assertInstanceOf( \WP_User::class, get_userdata( $user ) );
		$this->assertSame( 'request-confirmed', get_post_status( $request ) );
	}

	public function test_an_account_with_something_published_is_left_empty_and_cannot_sign_in(): void {
		$password = 'Correct-Horse-9';
		$user     = $this->make_user();
		wp_set_password( $password, $user );
		$login = get_userdata( $user )->user_login;
		$email = get_userdata( $user )->user_email;
		update_user_meta( $user, 'first_name', 'Ana' );

		$post = wp_insert_post(
			array(
				'post_title'  => 'Something they wrote',
				'post_status' => 'publish',
				'post_author' => $user,
			)
		);

		$this->erase_from_account( $user );

		$shell = get_userdata( $user );

		$this->assertInstanceOf( \WP_User::class, $shell, 'The account stays, so the post keeps an author' );
		$this->assertSame( 'deleted-' . $user, $shell->user_login );
		$this->assertStringNotContainsString( $email, $shell->user_email );
		$this->assertSame( '', (string) get_user_meta( $user, 'first_name', true ) );
		$this->assertSame( array(), $shell->roles );
		$this->assertNotNull( get_post( $post ), 'What they wrote stays' );
		$this->assertInstanceOf( \WP_Error::class, wp_authenticate( $login, $password ) );
		$this->assertInstanceOf( \WP_Error::class, wp_authenticate( 'deleted-' . $user, $password ) );

		wp_delete_post( $post, true );
	}

	public function test_a_request_filed_from_tools_leaves_the_account(): void {
		$user = $this->make_user();

		$this->erase_from_account( $user, false );

		$this->assertInstanceOf( \WP_User::class, get_userdata( $user ) );
		$this->assertNotSame( 'deleted-' . $user, get_userdata( $user )->user_login );
	}

	public function test_an_administrator_is_never_closed_this_way(): void {
		$user = $this->make_user( 'administrator' );

		$this->erase_from_account( $user );

		$this->assertInstanceOf( \WP_User::class, get_userdata( $user ) );
		$this->assertContains( 'administrator', get_userdata( $user )->roles );
	}

	/**
	 * On a network, a person who is also a member of another site keeps the
	 * account there, emptied: that site was never asked.
	 */
	public function test_on_a_network_a_member_of_another_site_is_anonymised(): void {
		if ( ! is_multisite() ) {
			$this->markTestSkipped( 'Needs a multisite network.' );
		}

		$other = (int) wp_insert_site(
			array(
				'domain' => (string) get_network()->domain,
				'path'   => '/close-' . strtolower( wp_generate_password( 6, false ) ) . '/',
				'title'  => 'Other',
			)
		);
		$user  = $this->make_user();
		add_user_to_blog( $other, $user, 'subscriber' );

		$this->erase_from_account( $user );

		$this->assertInstanceOf( \WP_User::class, get_userdata( $user ) );
		$this->assertSame( 'deleted-' . $user, get_userdata( $user )->user_login );

		wp_delete_site( $other );
	}
}
