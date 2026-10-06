<?php
/**
 * The plugin's namespace, accounted for by its prefix.
 *
 * What the plugin keeps about a person is under `diluxone_users_`, and so is
 * what an add-on of it keeps. The add-on may not be running when somebody
 * asks for their data or for it to be erased, so neither can depend on it
 * being there to say what it wrote: the export names every key of the
 * namespace nothing explained, without its value, and the erasure takes every
 * one of them — but the two the plugin keeps on purpose, and nobody else's.
 *
 * Users are the installation's, so this is the same on both topologies.
 */

namespace Tests\Integration;

class PrivacyNamespaceTest extends IntegrationTestCase {

	private int $user = 0;

	protected function setUp(): void {
		parent::setUp();

		$this->user = $this->make_user();

		update_user_meta( $this->user, 'diluxone_users_addon_secret', 'secret-value' );
		update_user_meta( $this->user, '_diluxone_users_addon_tmp', 'tmp-value' );
		update_user_meta( $this->user, 'diluxone_users_sso_addon_provider', 'subject-123' );
		update_user_meta( $this->user, 'diluxone_users_handle', 'someone' );
		update_user_meta( $this->user, DILUXONE_USERS_MEMBERSHIP_REMOVED, array( 3 ) );
		update_user_meta( $this->user, 'diluxone_users_closed', 1 );
		update_user_meta( $this->user, 'other_plugin_note', 'not ours' );
	}

	/** @return array<int, array<string, mixed>> The export's rows of one group. */
	private function group( string $group ): array {
		$email = get_userdata( $this->user )->user_email;
		$items = diluxone_users_privacy_export( $email )['data'];

		return array_values( array_filter( $items, static fn( array $item ): bool => $group === $item['group_id'] ) );
	}

	public function test_the_export_names_what_nothing_explained_and_shows_none_of_it(): void {
		$other = $this->group( 'diluxone-users-other' );

		$this->assertCount( 1, $other );

		$names = wp_list_pluck( $other[0]['data'], 'name' );
		sort( $names );

		$this->assertSame( array( '_diluxone_users_addon_tmp', 'diluxone_users_addon_secret', 'diluxone_users_sso_addon_provider' ), $names, 'the namespace’s unexplained keys, and only those' );

		$flat = (string) wp_json_encode( $other );

		foreach ( array( 'secret-value', 'tmp-value', 'subject-123' ) as $value ) {
			$this->assertStringNotContainsString( $value, $flat, 'named, never quoted' );
		}
	}

	public function test_an_add_on_explains_its_keys_and_adds_its_rows(): void {
		$this->hook(
			'diluxone_users_privacy_keys',
			static function ( array $keys ): array {
				$keys[] = 'diluxone_users_addon_secret';

				return $keys;
			}
		);
		$this->hook(
			'diluxone_users_privacy_export_items',
			static function ( array $items, int $user_id ): array {
				$items[] = array(
					'group_id'    => 'addon',
					'group_label' => 'Add-on',
					'item_id'     => 'addon-' . $user_id,
					'data'        => array(
						array(
							'name'  => 'Thing',
							'value' => 'set up',
						),
					),
				);

				return $items;
			},
			10,
			2
		);

		$this->assertCount( 1, $this->group( 'addon' ) );
		$this->assertNotContains( 'diluxone_users_addon_secret', wp_list_pluck( $this->group( 'diluxone-users-other' )[0]['data'], 'name' ), 'explained, so not listed again' );
	}

	public function test_the_erasure_takes_the_namespace_and_nothing_else(): void {
		// Even a filter asking for WordPress's own: never the plugin's to erase.
		$this->hook(
			'diluxone_users_privacy_keys',
			static function ( array $keys ): array {
				$keys[] = 'wp_capabilities';
				$keys[] = 'session_tokens';

				return $keys;
			}
		);
		$caps = get_user_meta( $this->user, $GLOBALS['wpdb']->get_blog_prefix() . 'capabilities', true );

		diluxone_users_privacy_erase( get_userdata( $this->user )->user_email );

		foreach ( array( 'diluxone_users_addon_secret', '_diluxone_users_addon_tmp', 'diluxone_users_sso_addon_provider', 'diluxone_users_handle' ) as $key ) {
			$this->assertSame( '', get_user_meta( $this->user, $key, true ), $key . ' is erased' );
		}

		$this->assertSame( array( 3 ), get_user_meta( $this->user, DILUXONE_USERS_MEMBERSHIP_REMOVED, true ), 'the removals keep them off those sites' );
		$this->assertSame( '1', (string) get_user_meta( $this->user, 'diluxone_users_closed', true ), 'a closed account stays closed' );
		$this->assertSame( 'not ours', get_user_meta( $this->user, 'other_plugin_note', true ), 'another plugin’s data is not ours to erase' );
		$this->assertSame( $caps, get_user_meta( $this->user, $GLOBALS['wpdb']->get_blog_prefix() . 'capabilities', true ), 'and nor is WordPress’s' );
	}
}
