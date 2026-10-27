<?php
/**
 * #188: where the Users+ Pro previews are, an add-on saves what it draws on
 * `diluxone_users_panel_saved`, so every one of those tabs is a form whose
 * save fires it, and the free save tolerates what the add-on's control posts.
 */

namespace Tests\Integration;

class ProSeamsSaveTest extends IntegrationTestCase {

	/** The tab on User fields › After sign-in, as the screen would draw it. */
	private function missing_tab(): array {
		return diluxone_users_panels( 'diluxone-users-fields' )['missing'];
	}

	public function test_after_sign_in_is_a_form_only_while_an_add_on_draws_there(): void {
		$this->assertEmpty( $this->missing_tab()['form'], 'the plugin alone: nothing to save, no Save' );

		$this->hook( 'diluxone_users_pro_choice', static fn( array $choice ): array => $choice );

		$this->assertTrue( (bool) $this->missing_tab()['form'], 'with an add-on drawing: a form' );
		$this->assertTrue( is_callable( $this->missing_tab()['save'] ) );
	}

	public function test_each_tab_with_a_preview_is_a_form_that_saves(): void {
		$this->hook( 'diluxone_users_pro_choice', static fn( array $choice ): array => $choice );

		$tabs = array(
			array( 'diluxone-users-security', '2fa' ),
			array( 'diluxone-users-security', 'sessions' ),
			array( 'diluxone-users-social', 'general' ),
			array( 'diluxone-users-account', 'handle' ),
			array( 'diluxone-users-fields', 'missing' ),
			array( DILUXONE_USERS_DESIGN, 'brand' ),
		);

		foreach ( $tabs as list( $screen, $tab ) ) {
			$panel = diluxone_users_panels( $screen )[ $tab ] ?? null;

			$this->assertIsArray( $panel, "$screen/$tab is a tab here" );
			$this->assertTrue( ! empty( $panel['form'] ) && is_callable( $panel['save'] ), "$screen/$tab is a form with a save" );
		}
	}

	public function test_the_after_sign_in_save_keeps_nothing_and_tells_the_add_on(): void {
		$heard = array();
		$this->hook(
			'diluxone_users_panel_saved',
			static function ( string $screen, string $tab ) use ( &$heard ): void {
				$heard[] = $screen . '/' . $tab;
			},
			10,
			2
		);

		$before = wp_load_alloptions();
		$this->postPanel( 'diluxone-users-fields', array( 'diluxone_users_pro_ask' => '1' ) );
		$this->assertNotFalse( diluxone_users_panel_save( diluxone_users_panels( 'diluxone-users-fields' )['missing'], 'diluxone-users-fields', 'missing' ) );

		$this->assertSame( array( 'diluxone-users-fields/missing' ), $heard );
		$this->assertSame( array_keys( $before ), array_keys( wp_load_alloptions() ), 'the plugin stored nothing' );
	}

	public function test_the_theme_answer_an_add_on_posts_is_the_plugins_own_stylesheet(): void {
		$this->postPanel( DILUXONE_USERS_DESIGN, array( 'diluxone_users_look' => 'theme' ) );
		ob_start();
		diluxone_users_design_brand_save();
		ob_end_clean();

		$this->assertSame( 1, (int) diluxone_users_option( 'diluxone_users_styles' ), 'the stylesheet stays on' );
		$this->assertFalse( get_option( 'diluxone_users_colors' ), 'and nothing of the add-on’s is stored by the plugin' );
	}
}
