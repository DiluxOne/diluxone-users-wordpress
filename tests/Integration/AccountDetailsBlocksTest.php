<?php
/**
 * The "Your details" tab: a titled box per block of fields, in one form.
 *
 * Each block with active fields (main, then extra) is drawn in its own panel
 * under a title written for the person; a block with none draws no box. Both
 * boxes sit inside the one fields form, so either Save sends everything, and
 * what the save says is shown once, above them.
 */

namespace Tests\Integration;

class AccountDetailsBlocksTest extends IntegrationTestCase {

	protected function tearDown(): void {
		unset( $_GET['diluxone-users'] );

		parent::tearDown();
	}

	/**
	 * The site's fields: one text field per key, in the block given.
	 *
	 * @param array<string, string> $fields key => group.
	 */
	private function define( array $fields ): void {
		$stored = array();

		foreach ( $fields as $key => $group ) {
			$stored[] = array(
				'key'      => $key,
				'label'    => ucfirst( str_replace( '_', ' ', $key ) ),
				'type'     => 'text',
				'required' => 0,
				'active'   => 1,
				'group'    => $group,
			);
		}

		diluxone_users_update_option( 'diluxone_users_fields', $stored );
	}

	/** The tab, as a person signed in sees it. */
	private function details(): string {
		$user = $this->make_user();
		wp_set_current_user( $user );

		return diluxone_users_render( 'account/details.php', array( 'user' => get_userdata( $user ) ) );
	}

	/**
	 * The panels the fields' form holds, each with its title and the ids in it.
	 *
	 * @return array<int, array{title: string, ids: array<int, string>}>
	 */
	private function boxes( string $html ): array {
		$doc = new \DOMDocument();
		libxml_use_internal_errors( true );
		$doc->loadHTML( '<?xml encoding="utf-8"?><div>' . $html . '</div>' );
		libxml_clear_errors();

		$xpath = new \DOMXPath( $doc );
		$boxes = array();

		foreach ( $xpath->query( '//form[input[@name="action" and @value="diluxone_users_fields_save"]]//div[contains(concat(" ", @class, " "), " diluxone-users-panel ")]' ) as $panel ) {
			$ids = array();
			foreach ( $xpath->query( './/*[@id]', $panel ) as $node ) {
				$ids[] = (string) $node->getAttribute( 'id' );
			}

			$boxes[] = array(
				'title' => trim( (string) $xpath->query( './h3', $panel )->item( 0 )?->textContent ),
				'ids'   => $ids,
			);
		}

		return $boxes;
	}

	public function test_each_block_with_fields_is_a_titled_box_of_its_own(): void {
		$this->define(
			array(
				'blocks_main'  => 'main',
				'blocks_extra' => 'extra',
			)
		);

		$html  = $this->details();
		$boxes = $this->boxes( $html );

		$this->assertCount( 2, $boxes, 'One box per block' );
		$this->assertSame( 'About you', $boxes[0]['title'] );
		$this->assertContains( 'blocks_main', $boxes[0]['ids'] );
		$this->assertNotContains( 'blocks_extra', $boxes[0]['ids'] );
		$this->assertSame( 'A bit more about you', $boxes[1]['title'] );
		$this->assertContains( 'blocks_extra', $boxes[1]['ids'] );
		$this->assertNotContains( 'blocks_main', $boxes[1]['ids'] );

		$this->assertSame( 1, substr_count( $html, 'value="diluxone_users_fields_save"' ), 'One form for both boxes' );
		$this->assertStringContainsString( 'name="diluxone_users_group" value=""', $html, 'It saves every block' );
		$this->assertSame( 2, substr_count( $html, 'diluxone-users-panel__save' ), 'A Save button per box' );
	}

	public function test_a_block_with_no_active_field_draws_no_box(): void {
		$this->define( array( 'blocks_extra' => 'extra' ) );

		$boxes = $this->boxes( $this->details() );

		$this->assertCount( 1, $boxes );
		$this->assertSame( 'A bit more about you', $boxes[0]['title'] );
	}

	public function test_no_field_at_all_draws_no_form(): void {
		diluxone_users_update_option( 'diluxone_users_fields', array() );
		$this->hook( 'diluxone_users_fields', '__return_empty_array' );

		$this->assertStringNotContainsString( 'diluxone_users_fields_save', $this->details() );
	}

	public function test_what_the_save_says_is_shown_once_above_the_boxes(): void {
		$this->define(
			array(
				'blocks_main'  => 'main',
				'blocks_extra' => 'extra',
			)
		);
		$_GET['diluxone-users'] = 'saved';

		$html = $this->details();

		$this->assertSame( 1, substr_count( $html, 'diluxone-users-notice--ok' ) );
		$this->assertLessThan( strpos( $html, '<form class="diluxone-users-form"' ), strpos( $html, 'diluxone-users-notice--ok' ) );
	}
}
