<?php
/**
 * User fields: the actions that change the list, and the screens that draw it.
 *
 * The list of fields is what every person is asked for, and it is the
 * network's on a network: it is changed from Network Admin, by whoever
 * administers the network, and from nowhere else. Every action here is
 * behind its own nonce; a request without it, or from somebody who may not
 * change the list, or from a site's own dashboard on a network, changes
 * nothing. What a valid request saves is cleaned on the way in.
 *
 * Every case runs on both topologies unless it says otherwise.
 */

namespace Tests\Integration;

use Tests\Integration\Support\CoverAdminSupport;

class CoverAdminFieldsTest extends IntegrationTestCase {

	use CoverAdminSupport;

	/** @var array<int, array{0: string, 1: callable, 2: int}> Filters the test added, taken out after it. */
	private array $filters = array();

	protected function setUp(): void {
		parent::setUp();

		// The edit screen ends in WordPress's own submit_button().
		require_once ABSPATH . 'wp-admin/includes/admin.php';
	}

	protected function tearDown(): void {
		foreach ( $this->filters as $filter ) {
			remove_filter( $filter[0], $filter[1], $filter[2] );
		}

		$this->filters = array();
		$this->cover_admin_reset();

		parent::tearDown();
	}

	private function filter( string $hook, callable $callback, int $priority = 10, int $args = 1 ): void {
		add_filter( $hook, $callback, $priority, $args );
		$this->filters[] = array( $hook, $callback, $priority );
	}

	/** The keys on the stored list, in order. */
	private function keys(): array {
		return array_values( array_map( 'strval', array_column( (array) diluxone_users_raw_get( 'diluxone_users_fields', array() ), 'key' ) ) );
	}

	/** The two names, and one field of the site's own. */
	private function seed(): void {
		diluxone_users_update_option(
			'diluxone_users_fields',
			array_merge(
				diluxone_users_default_fields(),
				array(
					array(
						'key'   => 'diluxone_users_team',
						'label' => 'Team',
						'type'  => 'text',
					),
				)
			)
		);
	}

	/** Every door into an account shut, or one of them open. */
	private function doors( bool $open ): void {
		$this->filter(
			'diluxone_users_option',
			static function ( $value, string $key ) {
				return in_array( $key, array( 'diluxone_users_login_register', 'diluxone_users_sso_register', 'diluxone_users_register_form' ), true ) ? 0 : $value;
			},
			10,
			2
		);
		$this->filter( 'pre_option_users_can_register', static fn() => $open ? '1' : '0' );
	}

	/**
	 * Sends the screen's request as whoever is signed in and says how it ended.
	 *
	 * @param array<string, mixed> $post
	 * @param array<string, mixed> $get
	 * @return array{0: string, 1: string}
	 */
	private function act( array $post, array $get = array() ): array {
		$this->postAs( get_current_user_id(), $post, array( 'page' => 'diluxone-users-fields' ) + $get );

		if ( array() === $post ) {
			$_SERVER['REQUEST_METHOD'] = 'GET';
		}

		return $this->ended( 'diluxone_users_fields_actions' );
	}

	/* ── Saving a field ────────────────────────────────────────────── */

	public function test_a_new_field_is_saved_cleaned_with_a_key_made_from_its_name(): void {
		$this->seed();
		$this->where_network_screens_are();
		$this->the_admin();

		$ended = $this->act(
			array(
				'diluxone_users_field_nonce' => wp_create_nonce( 'diluxone_users_field' ),
				'diluxone_users_field'       => array(
					'label'    => 'Shoe size <script>x</script>',
					'type'     => 'select',
					'options'  => "38\n 39 \n\n40",
					'help'     => '<b>Why</b> we ask',
					'edit'     => 'limited',
					'edit_max' => '3',
					'required' => '1',
					'active'   => '1',
					'group'    => 'extra',
				),
			)
		);

		$this->assertSame( 'redirect', $ended[0] );
		$this->assertSame( 'saved', $this->queryArg( $ended[1], 'diluxone_users_done' ) );

		$field = diluxone_users_field( 'diluxone_users_shoesize' );

		$this->assertNotNull( $field, 'the key is made from the name, spaces and all taken out' );
		$this->assertSame( 'Shoe size', $field['label'], 'no markup kept in the name' );
		$this->assertSame( 'select', $field['type'] );
		$this->assertSame( array( '38', '39', '40' ), $field['options'], 'one value per line, trimmed, blanks dropped' );
		$this->assertSame( 'Why we ask', $field['help'] );
		$this->assertSame( 'limited', $field['edit'] );
		$this->assertSame( 3, $field['edit_max'] );
		$this->assertSame( 1, $field['required'] );
		$this->assertSame( array( 'first_name', 'last_name', 'diluxone_users_team', 'diluxone_users_shoesize' ), $this->keys(), 'added at the end' );
	}

	public function test_a_field_without_a_name_is_not_saved_and_the_screen_says_so(): void {
		$this->seed();
		$this->where_network_screens_are();
		$this->the_admin();

		$ended = $this->act(
			array(
				'diluxone_users_field_nonce' => wp_create_nonce( 'diluxone_users_field' ),
				'diluxone_users_field'       => array( 'label' => '   ' ),
			)
		);

		$this->assertSame( 'nolabel', $this->queryArg( $ended[1], 'diluxone_users_done' ) );
		$this->assertSame( array( 'first_name', 'last_name', 'diluxone_users_team' ), $this->keys() );
	}

	public function test_an_existing_field_is_replaced_in_place_and_its_type_decides_its_options(): void {
		$this->seed();

		$this->assertSame(
			'diluxone_users_team',
			diluxone_users_field_save(
				array(
					'key'       => 'diluxone_users_team',
					'label'     => 'Country of the team',
					'type'      => 'country',
					'preferred' => array( 'AR', 'UY' ),
					'options'   => "not\nthese",
					'active'    => '1',
				)
			)
		);
		$this->assertSame( array( 'first_name', 'last_name', 'diluxone_users_team' ), $this->keys(), 'replaced where it was, not added again' );
		$this->assertSame( array( 'AR', 'UY' ), diluxone_users_field( 'diluxone_users_team' )['options'], 'a country keeps the ones shown first' );

		diluxone_users_field_save(
			array(
				'key'             => 'diluxone_users_team',
				'label'           => 'Team phone',
				'type'            => 'phone',
				'default_country' => 'AR',
			)
		);
		$this->assertSame( array( 'AR' ), diluxone_users_field( 'diluxone_users_team' )['options'], 'a phone keeps its default country' );
		$this->assertSame( 0, diluxone_users_field( 'diluxone_users_team' )['active'], 'an unticked box is a hidden field' );

		diluxone_users_field_save(
			array(
				'key'   => 'diluxone_users_team',
				'label' => 'Team phone',
				'type'  => 'phone',
			)
		);
		$this->assertSame( array(), diluxone_users_field( 'diluxone_users_team' )['options'], 'no default country, no options' );

		diluxone_users_field_save(
			array(
				'key'     => 'diluxone_users_team',
				'label'   => 'Team since',
				'type'    => 'date',
				'options' => "a\nb",
			)
		);
		$this->assertSame( array(), diluxone_users_field( 'diluxone_users_team' )['options'], 'a date has no options' );
	}

	public function test_a_reserved_key_is_refused_and_nothing_is_written(): void {
		$this->seed();

		$this->assertSame( '', diluxone_users_field_save( array( 'key' => 'session_tokens', 'label' => 'Sessions' ) ) );
		$this->assertSame( '', diluxone_users_field_save( array( 'key' => 'diluxone_users_sso_google', 'label' => 'Google' ) ) );
		$this->assertSame( array( 'first_name', 'last_name', 'diluxone_users_team' ), $this->keys() );
	}

	public function test_a_name_taken_or_reserved_gets_the_next_free_key(): void {
		$this->assertSame( 'diluxone_users_avatar_2', diluxone_users_key_from( 'Avatar', array() ), 'the avatar’s own key is not handed out' );
		$this->assertSame( 'diluxone_users_team_3', diluxone_users_key_from( 'Team', array( 'diluxone_users_team', 'diluxone_users_team_2' ) ) );
		$this->assertSame( 'diluxone_users_field', diluxone_users_key_from( '¡¿?!', array() ), 'a name with nothing usable in it' );
		$this->assertSame( 'diluxone_users_cancion', diluxone_users_key_from( 'Canción', array() ), 'accents taken off' );
	}

	/* ── Deleting and moving ───────────────────────────────────────── */

	public function test_a_field_is_moved_and_deleted_behind_its_nonce_and_the_answers_stay(): void {
		$this->seed();
		$this->where_network_screens_are();
		$this->the_admin();

		$person = $this->make_user();
		update_user_meta( $person, 'diluxone_users_team', 'Blue' );

		$nonce = wp_create_nonce( 'diluxone_users_field_action' );

		$ended = $this->act( array(), array( 'field' => 'diluxone_users_team', 'diluxone_users_action' => 'up', '_wpnonce' => $nonce ) );
		$this->assertSame( 'up', $this->queryArg( $ended[1], 'diluxone_users_done' ) );
		$this->assertSame( array( 'first_name', 'diluxone_users_team', 'last_name' ), $this->keys() );

		$this->act( array(), array( 'field' => 'first_name', 'diluxone_users_action' => 'down', '_wpnonce' => $nonce ) );
		$this->assertSame( array( 'diluxone_users_team', 'first_name', 'last_name' ), $this->keys() );

		// Off either end, or a key that is not there: nothing moves.
		$this->act( array(), array( 'field' => 'diluxone_users_team', 'diluxone_users_action' => 'up', '_wpnonce' => $nonce ) );
		$this->act( array(), array( 'field' => 'last_name', 'diluxone_users_action' => 'down', '_wpnonce' => $nonce ) );
		$this->act( array(), array( 'field' => 'nope', 'diluxone_users_action' => 'up', '_wpnonce' => $nonce ) );
		$this->assertSame( array( 'diluxone_users_team', 'first_name', 'last_name' ), $this->keys() );

		// WordPress's own are never deleted.
		$this->act( array(), array( 'field' => 'first_name', 'diluxone_users_action' => 'delete', '_wpnonce' => $nonce ) );
		$this->assertContains( 'first_name', $this->keys() );

		$ended = $this->act( array(), array( 'field' => 'diluxone_users_team', 'diluxone_users_action' => 'delete', '_wpnonce' => $nonce ) );
		$this->assertSame( 'delete', $this->queryArg( $ended[1], 'diluxone_users_done' ) );
		$this->assertSame( array( 'first_name', 'last_name' ), $this->keys() );
		$this->assertSame( 'Blue', get_user_meta( $person, 'diluxone_users_team', true ), 'what people answered is kept' );
	}

	public function test_an_action_without_its_nonce_stops_and_changes_nothing(): void {
		$this->seed();
		$this->where_network_screens_are();
		$this->the_admin();

		$ended = $this->act( array(), array( 'field' => 'diluxone_users_team', 'diluxone_users_action' => 'delete', '_wpnonce' => 'nope' ) );
		$this->assertSame( 'died', $ended[0] );

		// A save with a nonce that is not the field's is not a save: the
		// request falls through to the link actions, and there is none.
		$ended = $this->act(
			array(
				'diluxone_users_field_nonce' => wp_create_nonce( 'diluxone_users_suggested' ),
				'diluxone_users_field'       => array( 'label' => 'Sneaky' ),
			)
		);
		$this->assertSame( 'returned', $ended[0] );

		$this->assertSame( array( 'first_name', 'last_name', 'diluxone_users_team' ), $this->keys() );
	}

	public function test_somebody_without_the_capability_changes_nothing(): void {
		$this->seed();
		$this->where_network_screens_are();

		// A subscriber on a single site; on a network a site's administrator
		// in Network Admin, without the network's capability.
		wp_set_current_user( $this->make_user( is_multisite() ? 'administrator' : 'subscriber' ) );

		$ended = $this->act(
			array(
				'diluxone_users_field_nonce' => wp_create_nonce( 'diluxone_users_field' ),
				'diluxone_users_field'       => array( 'label' => 'Sneaky' ),
			)
		);
		$this->assertSame( 'returned', $ended[0] );

		$ended = $this->act( array(), array( 'field' => 'diluxone_users_team', 'diluxone_users_action' => 'delete', '_wpnonce' => wp_create_nonce( 'diluxone_users_field_action' ) ) );
		$this->assertSame( 'returned', $ended[0] );

		$this->assertSame( array( 'first_name', 'last_name', 'diluxone_users_team' ), $this->keys() );
	}

	public function test_on_a_network_a_sites_own_dashboard_changes_nothing_even_for_the_super_admin(): void {
		$this->network_only( 'test_a_new_field_is_saved_cleaned_with_a_key_made_from_its_name' );

		$this->seed();
		$this->the_admin();

		$ended = $this->act(
			array(
				'diluxone_users_field_nonce' => wp_create_nonce( 'diluxone_users_field' ),
				'diluxone_users_field'       => array( 'label' => 'From a site' ),
			)
		);

		$this->assertSame( 'returned', $ended[0] );
		$this->assertSame( array( 'first_name', 'last_name', 'diluxone_users_team' ), $this->keys() );
	}

	public function test_another_screen_is_none_of_this_handlers_business(): void {
		$this->seed();
		$this->where_network_screens_are();
		$this->the_admin();

		$this->postAs(
			get_current_user_id(),
			array(
				'diluxone_users_field_nonce' => wp_create_nonce( 'diluxone_users_field' ),
				'diluxone_users_field'       => array( 'label' => 'Elsewhere' ),
			),
			array( 'page' => 'diluxone-users-design' )
		);

		$this->assertSame( 'returned', $this->ended( 'diluxone_users_fields_actions' )[0] );
		$this->assertSame( array( 'first_name', 'last_name', 'diluxone_users_team' ), $this->keys() );
	}

	public function test_suggested_fields_none_ticked_says_nothing_was_added(): void {
		$this->seed();
		$this->where_network_screens_are();
		$this->the_admin();

		$ended = $this->act( array( 'diluxone_users_suggested_nonce' => wp_create_nonce( 'diluxone_users_suggested' ) ) );

		$this->assertSame( 'nosuggested', $this->queryArg( $ended[1], 'diluxone_users_done' ) );
		$this->assertSame( array( 'first_name', 'last_name', 'diluxone_users_team' ), $this->keys() );
	}

	/* ── The screens ───────────────────────────────────────────────── */

	public function test_the_list_shows_each_field_with_its_actions_and_the_notice_of_the_last_one(): void {
		diluxone_users_update_option(
			'diluxone_users_fields',
			array_merge(
				diluxone_users_default_fields(),
				array(
					array( 'key' => 'diluxone_users_doc', 'label' => 'Document <i>no</i>', 'type' => 'text', 'edit' => 'never', 'required' => 1 ),
					array( 'key' => 'diluxone_users_nick', 'label' => 'Nick', 'type' => 'text', 'edit' => 'limited', 'edit_max' => 2, 'active' => 0 ),
				)
			)
		);
		$this->where_network_screens_are();
		$this->the_admin();
		do_action( 'diluxone_users_register_panels' );
		$this->doors( true );

		$_GET = array(
			'page'                => 'diluxone-users-fields',
			'diluxone_users_done' => 'delete',
		);
		$html = $this->draw( 'diluxone_users_screen_fields' );

		$this->assertStringContainsString( 'Field deleted.', $html );
		$this->assertStringContainsString( 'notice-success', $html );
		$this->assertStringContainsString( '<code>diluxone_users_doc</code>', $html );
		$this->assertStringContainsString( 'Document no', $html, 'the name as text' );
		$this->assertStringNotContainsString( '<i>no</i>', $html );
		$this->assertStringContainsString( 'Read only', $html );
		$this->assertStringContainsString( '2 times', $html );
		$this->assertStringContainsString( 'diluxone-users-pill--off', $html, 'a hidden field says so' );
		$this->assertStringContainsString( 'Add suggested fields', $html, 'the suggestions are still to add' );
		$this->assertStringContainsString( 'data-diluxone-users-dialog', $html, 'the dialog is on the page' );
		$this->assertStringContainsString( 'diluxone_users_fields</code>', $html, 'where it all lives' );
		$this->assertStringNotContainsString( 'registration closed', $html, 'a door is open' );

		// One delete link per field of the site's own, none for WordPress's.
		$this->assertSame( 2, substr_count( $html, 'diluxone_users_action=delete' ) );
		$this->assertSame( 4, substr_count( $html, 'diluxone_users_action=up' ) );

		$_GET['diluxone_users_done'] = 'nolabel';
		$html                         = $this->draw( 'diluxone_users_screen_fields' );
		$this->assertStringContainsString( 'A field needs a name.', $html );
		$this->assertStringContainsString( 'notice-error', $html );
	}

	public function test_an_empty_list_says_so_and_a_closed_site_says_nobody_is_asked(): void {
		diluxone_users_update_option( 'diluxone_users_fields', array() );
		$this->where_network_screens_are();
		$this->the_admin();
		$this->doors( false );

		$this->assertSame( array(), diluxone_users_register_doors_open() );

		$html = $this->draw( 'diluxone_users_screen_fields_list' );

		$this->assertStringContainsString( 'No fields yet.', $html );
		$this->assertStringContainsString( 'registration closed', $html );
		$this->assertStringContainsString( 'Open a door', $html );
		$this->assertStringContainsString( 'tab=register', $html );

		$html = $this->draw( 'diluxone_users_nobody_registers_notice', 'register' );
		$this->assertStringContainsString( 'The fields it is about', $html );
		$this->assertStringContainsString( 'page=diluxone-users-fields', $html );
	}

	public function test_the_edit_screen_of_a_field_of_the_sites_own(): void {
		diluxone_users_update_option(
			'diluxone_users_fields',
			array(
				array( 'key' => 'diluxone_users_shirt', 'label' => 'Shirt', 'type' => 'select', 'options' => array( 'S', 'M' ), 'edit' => 'limited', 'edit_max' => 4 ),
			)
		);
		$this->where_network_screens_are();
		$this->the_admin();

		$_GET = array(
			'page'  => 'diluxone-users-fields',
			'field' => 'diluxone_users_shirt',
		);
		$html = $this->draw( 'diluxone_users_screen_fields' );

		$this->assertStringContainsString( 'Field: Shirt', $html );
		$this->assertStringContainsString( 'name="diluxone_users_field_nonce"', $html );
		$this->assertStringContainsString( 'value="diluxone_users_shirt"', $html );
		$this->assertStringContainsString( 'it cannot change', $html );
		$this->assertMatchesRegularExpression( '/name="diluxone_users_field\[options\]"[^>]*>\s*S\nM/', $html, 'the values, one per line' );
		$this->assertStringContainsString( 'value="4"', $html, 'how many times' );
		$this->assertStringContainsString( 'Save field', $html );
		$this->assertStringNotContainsString( 'it keeps its type and cannot be deleted', $html );
	}

	public function test_the_edit_screen_of_wordpresss_own_field_and_of_a_country(): void {
		diluxone_users_update_option(
			'diluxone_users_fields',
			array_merge(
				diluxone_users_default_fields(),
				array(
					array( 'key' => 'diluxone_users_from', 'label' => 'From', 'type' => 'country', 'options' => array( 'AR' ) ),
					array( 'key' => 'diluxone_users_tel', 'label' => 'Tel', 'type' => 'phone', 'options' => array( 'UY' ) ),
				)
			)
		);
		$this->where_network_screens_are();
		$this->the_admin();

		$html = $this->draw( 'diluxone_users_screen_field_edit', 'first_name' );
		$this->assertStringContainsString( 'it keeps its type and cannot be deleted', $html );

		$html = $this->draw( 'diluxone_users_screen_field_edit', 'diluxone_users_from' );
		$this->assertMatchesRegularExpression( '/<option value="AR"\s+selected/', $html, 'the country shown first is picked' );

		$html = $this->draw( 'diluxone_users_screen_field_edit', 'diluxone_users_tel' );
		$this->assertMatchesRegularExpression( '/<option value="UY"\s+selected/', $html, 'the default country is picked' );
	}

	public function test_a_new_field_and_a_field_that_does_not_exist(): void {
		$this->seed();
		$this->where_network_screens_are();
		$this->the_admin();

		$_GET = array(
			'page'               => 'diluxone-users-fields',
			'diluxone_users_new' => '1',
		);
		$html = $this->draw( 'diluxone_users_screen_fields' );
		$this->assertStringContainsString( 'New field', $html );
		$this->assertStringContainsString( 'The key is generated from the name.', $html );
		$this->assertStringContainsString( 'Add field', $html );

		$html = $this->draw( 'diluxone_users_screen_field_edit', 'diluxone_users_nobody' );
		$this->assertStringContainsString( 'That field does not exist.', $html );
		$this->assertStringNotContainsString( '<form', $html );
	}

	public function test_the_suggested_tab_offers_what_is_missing_and_then_nothing(): void {
		$this->seed();
		$this->where_network_screens_are();
		$this->the_admin();

		$html = $this->draw( 'diluxone_users_screen_fields_suggested' );
		$this->assertStringContainsString( 'name="diluxone_users_suggested_nonce"', $html );
		$this->assertStringContainsString( 'value="diluxone_users_country"', $html );
		$this->assertStringContainsString( '4 suggested fields are not on the list.', $html );
		diluxone_users_ui_save_queue();

		diluxone_users_add_suggested_fields( array_column( diluxone_users_suggested_fields(), 'key' ) );

		$html = $this->draw( 'diluxone_users_screen_fields_suggested' );
		$this->assertStringContainsString( 'Every suggested field is already on the list.', $html );
		$this->assertStringNotContainsString( 'diluxone_users_suggested_nonce', $html );

		$list = $this->draw( 'diluxone_users_screen_fields_list' );
		$this->assertStringNotContainsString( 'Add suggested fields', $list, 'nothing left to suggest' );
	}

	public function test_the_usage_tab_names_every_shortcode(): void {
		$this->the_admin();

		$html = $this->draw( 'diluxone_users_screen_fields_usage' );

		foreach ( array( '[diluxone_users_fields]', '[diluxone_users_login]', '[diluxone_users_accounts]', '[diluxone_users_sessions]' ) as $code ) {
			$this->assertStringContainsString( $code, $html );
		}

		$this->assertStringContainsString( '<code>templates/</code>', $html );
		$this->assertStringContainsString( 'page=diluxone-users-design', $html );
	}

	/**
	 * A field's words sent as lists are no words: a name sent as a list is
	 * no name and nothing is saved, and the rest fall back as if not sent —
	 * never a field called "Array", and no warning.
	 */
	public function test_a_fields_words_sent_as_lists_are_no_words(): void {
		$this->seed();
		$this->where_network_screens_are();
		$this->the_admin();
		$before = $this->keys();

		$warnings = array();
		set_error_handler(
			static function ( int $level, string $message ) use ( &$warnings ): bool {
				$warnings[] = $message;

				return true;
			}
		);

		try {
			$ended = $this->act( array( 'diluxone_users_field_nonce' => wp_create_nonce( 'diluxone_users_field' ), 'diluxone_users_field' => array( 'label' => array( 'x' ) ) ) );
			$this->assertSame( 'nolabel', $this->queryArg( $ended[1], 'diluxone_users_done' ) );
			$this->assertSame( $before, $this->keys(), 'nothing saved' );

			$this->act(
				array(
					'diluxone_users_field_nonce' => wp_create_nonce( 'diluxone_users_field' ),
					'diluxone_users_field'       => array(
						'label' => 'Lists',
						'type'  => array( 'select' ),
						'group' => array( 'main' ),
						'help'  => array( 'h' ),
					),
				)
			);
		} finally {
			restore_error_handler();
		}

		$this->assertSame( array(), $warnings );

		$saved = array_values( wp_list_filter( diluxone_users_fields( '', false ), array( 'label' => 'Lists' ) ) )[0] ?? array();
		$this->assertSame( 'text', $saved['type'] ?? null );
		$this->assertSame( 'extra', $saved['group'] ?? null );
		$this->assertSame( '', $saved['help'] ?? null );
	}

	/** An action the screen does not know changes nothing. */
	public function test_an_unknown_action_changes_nothing(): void {
		$this->seed();
		$this->where_network_screens_are();
		$this->the_admin();
		$before = diluxone_users_fields( '', false );

		$ended = $this->act( array(), array( 'diluxone_users_action' => 'explode', 'field' => 'first_name', '_wpnonce' => wp_create_nonce( 'diluxone_users_field_action' ) ) );

		$this->assertSame( 'redirect', $ended[0] );
		$this->assertSame( $before, diluxone_users_fields( '', false ) );
	}
}
