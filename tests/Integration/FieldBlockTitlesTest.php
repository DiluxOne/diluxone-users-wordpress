<?php
/**
 * What the boxes of the "Your details" tab are called.
 *
 * The site types its own title for each block on User fields › Fields; a
 * block it left empty keeps the plugin's translated title. Whatever is typed
 * is text: saved without tags, drawn escaped.
 */

namespace Tests\Integration;

class FieldBlockTitlesTest extends IntegrationTestCase {

	protected function setUp(): void {
		parent::setUp();

		diluxone_users_update_option(
			'diluxone_users_fields',
			array(
				array(
					'key'      => 'titles_main',
					'label'    => 'Titles main',
					'type'     => 'text',
					'required' => 0,
					'active'   => 1,
					'group'    => 'main',
				),
				array(
					'key'      => 'titles_extra',
					'label'    => 'Titles extra',
					'type'     => 'text',
					'required' => 0,
					'active'   => 1,
					'group'    => 'extra',
				),
			)
		);
	}

	/** The tab, as a person signed in sees it. */
	private function details(): string {
		$user = $this->make_user();
		wp_set_current_user( $user );

		return diluxone_users_render( 'account/details.php', array( 'user' => get_userdata( $user ) ) );
	}

	/**
	 * The titles of the boxes the fields' form holds, in order.
	 *
	 * @return array<int, string>
	 */
	private function titles( string $html ): array {
		$doc = new \DOMDocument();
		libxml_use_internal_errors( true );
		$doc->loadHTML( '<?xml encoding="utf-8"?><div>' . $html . '</div>' );
		libxml_clear_errors();

		$titles = array();

		foreach ( ( new \DOMXPath( $doc ) )->query( '//div[contains(concat(" ", @class, " "), " diluxone-users-fields__block ")]/h3' ) as $h3 ) {
			$titles[] = trim( (string) $h3->textContent );
		}

		return $titles;
	}

	/** Saves User fields › Fields as its form sends it, from where the network's settings are written. */
	private function save( array $titles ): void {
		$this->in_network_admin();
		$this->postPanel( 'diluxone-users-fields', array( 'diluxone_users_field_block_titles' => $titles ) );

		$this->assertTrue( diluxone_users_screen_fields_list_save() );
	}

	public function test_a_title_typed_for_the_main_block_titles_its_box(): void {
		$this->save(
			array(
				'main'  => 'Datos del curso',
				'extra' => '',
			)
		);

		$this->assertSame( 'Datos del curso', diluxone_users_field_blocks()['main']['title'] );
		$this->assertSame( array( 'Datos del curso', 'A bit more about you' ), $this->titles( $this->details() ) );
	}

	public function test_the_title_is_the_networks_on_a_network(): void {
		diluxone_users_update_option( 'diluxone_users_field_block_titles', array( 'main' => 'Datos del curso' ) );

		if ( is_multisite() ) {
			$this->assertSame( array( 'main' => 'Datos del curso' ), get_site_option( 'diluxone_users_field_block_titles' ) );
			$this->assertFalse( get_option( 'diluxone_users_field_block_titles' ), 'Not a setting of one site' );
		}

		$this->assertSame( 'Datos del curso', $this->titles( $this->details() )[0] );
	}

	public function test_the_fields_tab_is_a_form_with_both_titles_and_the_dialog_outside_it(): void {
		diluxone_users_update_option( 'diluxone_users_field_block_titles', array( 'main' => 'Datos del curso' ) );
		$this->in_network_admin();
		wp_set_current_user( 1 );
		$_GET = array( 'tab' => 'list' );
		do_action( 'diluxone_users_register_panels' );

		ob_start();
		diluxone_users_screen_panels( 'diluxone-users-fields', 'User fields' );
		$html = (string) ob_get_clean();

		$form = substr( $html, (int) strpos( $html, '<form method="post" id="' . DILUXONE_USERS_PANEL_FORM . '"' ) );
		$form = substr( $form, 0, (int) strpos( $form, '</form>' ) );

		$this->assertStringContainsString( 'name="diluxone_users_field_block_titles[main]"', $form );
		$this->assertStringContainsString( 'value="Datos del curso"', $form );
		$this->assertStringContainsString( 'name="diluxone_users_field_block_titles[extra]"', $form );
		$this->assertStringContainsString( 'placeholder="A bit more about you"', $form );
		$this->assertStringNotContainsString( '<dialog', $form, 'No form inside the form' );
		$this->assertStringContainsString( 'data-diluxone-users-dialog', $html, 'The dialog is still drawn' );
		$this->assertStringContainsString( 'form="' . DILUXONE_USERS_PANEL_FORM . '"', $html, 'Its Save is in the rail' );
	}

	public function test_with_no_title_typed_the_boxes_keep_the_plugins_titles(): void {
		$this->assertSame( array( 'About you', 'A bit more about you' ), $this->titles( $this->details() ), 'Nothing stored' );

		$this->save(
			array(
				'main'  => '',
				'extra' => '  ',
			)
		);

		$this->assertNull( diluxone_users_raw_get( 'diluxone_users_field_block_titles', null ), 'Both empty: nothing is stored' );

		diluxone_users_update_option(
			'diluxone_users_field_block_titles',
			array(
				'main'  => '',
				'extra' => '',
			)
		);

		$this->assertSame( array( 'About you', 'A bit more about you' ), $this->titles( $this->details() ), 'Stored empty' );
	}

	public function test_the_plugins_titles_are_translated(): void {
		$this->hook(
			'gettext',
			static function ( string $translation, string $text, string $domain ): string {
				$words = array(
					'About you'            => 'Sobre vos',
					'A bit more about you' => 'Un poco más sobre vos',
				);

				return 'diluxone-users' === $domain && isset( $words[ $text ] ) ? $words[ $text ] : $translation;
			},
			10,
			3
		);

		$this->assertSame( array( 'Sobre vos', 'Un poco más sobre vos' ), $this->titles( $this->details() ) );
	}
}
