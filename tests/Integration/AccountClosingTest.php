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

	/**
	 * A request carries the address it was filed for: one whose account
	 * now has another address — changed since, or a request filed for A
	 * naming B — closes nobody.
	 */
	public function test_a_request_whose_address_is_not_the_accounts_closes_nothing(): void {
		diluxone_users_update_option( 'diluxone_users_membership', 'invite' );
		$a = $this->make_user();
		$b = $this->make_user();

		$request = wp_create_user_request( get_userdata( $a )->user_email, 'remove_personal_data', array( DILUXONE_USERS_CLOSE_KEY => $b ), 'confirmed' );
		$this->assertIsInt( $request );
		do_action( 'wp_privacy_personal_data_erased', $request );
		// WordPress takes one open request per address and action.
		wp_delete_post( $request, true );

		$this->assertInstanceOf( \WP_User::class, get_userdata( $b ), 'B was named, but the request is A’s' );
		$this->assertNotSame( 'deleted-' . $b, get_userdata( $b )->user_login );

		$request = wp_create_user_request( get_userdata( $a )->user_email, 'remove_personal_data', array( DILUXONE_USERS_CLOSE_KEY => $a ), 'confirmed' );
		$this->assertIsInt( $request );
		wp_update_user( array( 'ID' => $a, 'user_email' => 'changed-' . $a . '@example.test' ) );
		do_action( 'wp_privacy_personal_data_erased', $request );

		$this->assertInstanceOf( \WP_User::class, get_userdata( $a ), 'the address changed since it was filed' );
		$this->assertNotSame( 'deleted-' . $a, get_userdata( $a )->user_login );
	}

	/**
	 * An emptied account keeps no session, and cannot be signed into even
	 * with a password set on it afterwards: it is closed, not only renamed.
	 */
	public function test_an_emptied_account_keeps_no_session_and_stays_closed(): void {
		$user = $this->make_user();
		$post = wp_insert_post(
			array(
				'post_title'  => 'Theirs',
				'post_status' => 'publish',
				'post_author' => $user,
			)
		);
		$sessions = \WP_Session_Tokens::get_instance( $user );
		$sessions->create( time() + HOUR_IN_SECONDS );
		$sessions->create( time() + HOUR_IN_SECONDS );

		$this->erase_from_account( $user );

		$this->assertSame( array(), \WP_Session_Tokens::get_instance( $user )->get_all() );

		wp_set_password( 'Known-Now-123', $user );
		$refused = wp_authenticate( 'deleted-' . $user, 'Known-Now-123' );

		$this->assertInstanceOf( \WP_Error::class, $refused );
		$this->assertSame( 'diluxone_users_closed', $refused->get_error_code() );

		wp_delete_post( (int) $post, true );
	}

	/** What decides "it has content" is a filter a site can answer. */
	public function test_a_site_decides_what_counts_as_content(): void {
		if ( is_multisite() ) {
			$this->markTestSkipped( 'A single-site case: on a network a member of another site is anonymised whatever it has (test_on_a_network_a_member_of_another_site_is_anonymised).' );
		}

		$quiet = $this->make_user();
		$this->hook( 'diluxone_users_account_has_content', '__return_true' );
		$this->erase_from_account( $quiet );
		$this->assertSame( 'deleted-' . $quiet, get_userdata( $quiet )->user_login, 'nothing published, kept as a shell' );

		remove_filter( 'diluxone_users_account_has_content', '__return_true' );
		$this->hook( 'diluxone_users_account_has_content', '__return_false' );
		$writer = $this->make_user();
		$post   = wp_insert_post( array( 'post_title' => 'Theirs', 'post_status' => 'publish', 'post_author' => $writer ) );
		$this->erase_from_account( $writer );
		$this->assertFalse( get_userdata( $writer ), 'something published, deleted all the same' );

		wp_delete_post( (int) $post, true );
	}

	/** On a network a super admin is never closed this way. */
	public function test_on_a_network_a_super_admin_is_never_closed_this_way(): void {
		if ( ! is_multisite() ) {
			$this->markTestSkipped( 'A network case; on a single site the counterpart is test_an_administrator_is_never_closed_this_way.' );
		}

		$user = $this->make_user();
		grant_super_admin( $user );

		try {
			$this->erase_from_account( $user );

			$this->assertInstanceOf( \WP_User::class, get_userdata( $user ) );
			$this->assertNotSame( 'deleted-' . $user, get_userdata( $user )->user_login );
		} finally {
			revoke_super_admin( $user );
		}
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

		// No role left on any site it belonged to.
		foreach ( array( get_current_blog_id(), $other ) as $site ) {
			switch_to_blog( $site );
			$this->assertSame( array(), ( new \WP_User( $user ) )->roles, "site $site" );
			$this->assertFalse( user_can( $user, 'read' ), "site $site" );
			restore_current_blog();
		}

		wp_delete_site( $other );
	}

	/** What WordPress keeps on its own behalf is not something the person published. */
	public function test_revisions_requests_and_styles_are_not_content(): void {
		$user = $this->make_user();

		foreach ( array( 'revision', 'user_request', 'wp_global_styles', 'customize_changeset', 'oembed_cache' ) as $type ) {
			wp_insert_post(
				array(
					'post_type'   => $type,
					'post_status' => 'publish',
					'post_title'  => $type,
					'post_author' => $user,
				)
			);
		}

		$this->assertFalse( diluxone_users_account_has_content( $user ) );

		$page = (int) wp_insert_post(
			array(
				'post_type'   => 'page',
				'post_status' => 'draft',
				'post_title'  => 'A draft',
				'post_author' => $user,
			)
		);

		$this->assertTrue( diluxone_users_account_has_content( $user ), 'a page, even a draft, is theirs' );

		wp_delete_post( $page, true );
	}

	/** Every eraser is asked page after page until it is done, and no further than a hundred. */
	public function test_each_eraser_is_asked_page_after_page_until_it_is_done(): void {
		diluxone_users_update_option( 'diluxone_users_membership', 'invite' );
		$user  = $this->make_user();
		$pages = array(
			'two'   => array(),
			'never' => array(),
		);

		$this->hook(
			'wp_privacy_personal_data_erasers',
			static function ( array $erasers ) use ( &$pages ): array {
				$erasers['two']   = array(
					'eraser_friendly_name' => 'Two pages',
					'callback'             => static function ( string $email, int $page ) use ( &$pages ): array {
						$pages['two'][] = $page;

						return array( 'done' => 2 === $page );
					},
				);
				$erasers['never'] = array(
					'eraser_friendly_name' => 'Never done',
					'callback'             => static function ( string $email, int $page ) use ( &$pages ): array {
						$pages['never'][] = $page;

						return array( 'done' => false );
					},
				);
				$erasers['broken'] = array( 'callback' => 'no_such_eraser' );

				return $erasers;
			}
		);

		$request = $this->confirm_from_account( $user );

		$this->assertSame( array( 1, 2 ), $pages['two'] );
		$this->assertSame( range( 1, 100 ), $pages['never'], 'a ceiling, so the request is not held' );
		$this->assertSame( 'request-completed', get_post_status( $request ) );
	}
}
