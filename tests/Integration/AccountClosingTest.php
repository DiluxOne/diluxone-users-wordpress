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
		if ( is_multisite() ) {
			$this->markTestSkipped( 'On a network the account is always anonymised.' );
		}

		$user = $this->make_user();

		$this->erase_from_account( $user );

		$this->assertFalse( get_userdata( $user ) );
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

	public function test_on_a_network_the_account_is_anonymised_not_deleted(): void {
		if ( ! is_multisite() ) {
			$this->markTestSkipped( 'Needs a multisite network.' );
		}

		$user = $this->make_user();

		$this->erase_from_account( $user );

		$this->assertInstanceOf( \WP_User::class, get_userdata( $user ) );
		$this->assertSame( 'deleted-' . $user, get_userdata( $user )->user_login );
	}
}
