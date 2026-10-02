<?php
/**
 * Your brand: what the tab shows is what the tab saves.
 *
 * The picker for the mark above the sign-in form sat on this tab and nothing
 * on this tab's save read it: "Saved." came back, the picker came back empty,
 * and the sign-in page never showed a logo. Another screen even sent people
 * here to set it.
 */

namespace Tests\Integration;

class BrandSaveTest extends IntegrationTestCase {

	public function test_the_mark_picked_on_the_tab_is_kept(): void {
		$this->postPanel( DILUXONE_USERS_DESIGN, array( 'diluxone_users_login_logo' => '42' ) );

		diluxone_users_design_brand_save();

		$this->assertSame( 42, (int) diluxone_users_raw_get( 'diluxone_users_login_logo' ) );
	}

	public function test_removing_the_mark_clears_it(): void {
		diluxone_users_update_option( 'diluxone_users_login_logo', 42 );
		$this->postPanel( DILUXONE_USERS_DESIGN, array( 'diluxone_users_login_logo' => '' ) );

		diluxone_users_design_brand_save();

		$this->assertSame( 0, (int) diluxone_users_raw_get( 'diluxone_users_login_logo' ) );
	}
}
