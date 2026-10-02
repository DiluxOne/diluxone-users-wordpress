<?php
/**
 * "Download your data" leaves the file ready, once the request is confirmed.
 *
 * WordPress waits for an administrator to make the file from Tools. A request
 * filed from the account area is made when the person confirms it: every
 * exporter, the file written, the link mailed, the request completed. A site
 * that goes through them itself says so, and a request filed from Tools stays
 * WordPress's.
 */

namespace Tests\Integration;

class AccountExportTest extends IntegrationTestCase {

	/** Files a pending request the way the account area does, or Tools does, and confirms it. */
	private function confirm_copy( int $user_id, bool $from_account = true ): int {
		$request = wp_create_user_request(
			get_userdata( $user_id )->user_email,
			'export_personal_data',
			$from_account ? array( DILUXONE_USERS_EXPORT_KEY => $user_id ) : array()
		);

		$this->assertIsInt( $request );

		do_action( 'user_request_action_confirmed', $request );

		return $request;
	}

	protected function tearDown(): void {
		foreach ( (array) glob( wp_privacy_exports_dir() . '*.zip' ) as $file ) {
			wp_delete_file( (string) $file );
		}

		parent::tearDown();
	}

	public function test_confirming_it_makes_the_file(): void {
		$this->needs_zip();

		$user = $this->make_user();
		update_user_meta( $user, 'first_name', 'Ana' );

		$request = $this->confirm_copy( $user );
		$post    = get_post( $request );

		$this->assertSame( 'request-completed', get_post_status( $request ) );
		$this->assertNotSame( '', diluxone_users_data_file( $post ), 'Ready to download from the account' );
		$this->assertFileExists( wp_privacy_exports_dir() . get_post_meta( $request, '_export_file_name', true ) );
		$this->assertSame( '', (string) get_post_meta( $request, '_export_data_grouped', true ), 'Nothing left behind but the file' );
		$this->assertStringContainsString( 'your file is ready', (string) apply_filters( 'user_request_action_confirmed_message', 'WordPress', $request ) );
	}

	/** Every exporter's items, by group and by item, one item's fields merged. */
	public function test_the_data_is_grouped_as_wordpress_groups_it(): void {
		$groups = diluxone_users_export_groups(
			array(
				array(
					'group_id'    => 'user',
					'group_label' => 'User',
					'item_id'     => 'user-1',
					'data'        => array( array( 'name' => 'A', 'value' => '1' ) ),
				),
				array(
					'group_id'    => 'user',
					'group_label' => 'User',
					'item_id'     => 'user-1',
					'data'        => array( array( 'name' => 'B', 'value' => '2' ) ),
				),
				'not an item',
			)
		);

		$this->assertSame( array( 'user' ), array_keys( $groups ) );
		$this->assertCount( 2, $groups['user']['items']['user-1'] );
	}

	/** A site that goes through them itself: confirming only confirms. */
	public function test_a_site_that_makes_them_itself_waits(): void {
		diluxone_users_update_option( 'diluxone_users_privacy_export_when', 'admin' );
		$user = $this->make_user();

		$request = $this->confirm_copy( $user );

		$this->assertSame( 'request-confirmed', get_post_status( $request ) );
		$this->assertSame( '', diluxone_users_data_file( get_post( $request ) ) );
	}

	public function test_a_request_filed_from_tools_waits_for_tools(): void {
		$user = $this->make_user();

		$request = $this->confirm_copy( $user, false );

		$this->assertSame( 'request-confirmed', get_post_status( $request ) );
	}

	/** A file WordPress already cleared away is not offered: the link would 404. */
	public function test_a_file_cleared_away_is_not_offered(): void {
		$user    = $this->make_user();
		$request = wp_create_user_request( get_userdata( $user )->user_email, 'export_personal_data', array(), 'confirmed' );

		update_post_meta( $request, '_export_file_name', 'wp-personal-data-file-gone.zip' );

		$this->assertSame( '', diluxone_users_data_file( get_post( $request ) ) );
	}
}
