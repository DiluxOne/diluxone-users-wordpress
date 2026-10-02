<?php
/**
 * A person's fields, saved: every refusal first, then what each type keeps.
 *
 * The front-end form refuses somebody signed out and a forged form, and only
 * then reads what was sent — by name, one value per field, the block asked
 * for and nothing else. A required field left empty keeps what was there; an
 * empty optional one is deleted. A field the person may not edit is not
 * saved, a field they may edit a few times spends a go only when it really
 * changes, and an administrator fixing somebody else's spends none. Each type
 * is cleaned as what it is. The same save behind the dashboard profile,
 * Users > Add New and WordPress's own registration, each behind its own nonce.
 */

namespace Tests\Integration;

class CoverFieldsSaveTest extends IntegrationTestCase {

	/** @var array<int, array{0: string, 1: callable}> The hooks a test added, taken out after it. */
	private array $hooks = array();

	protected function tearDown(): void {
		foreach ( $this->hooks as [ $hook, $callback ] ) {
			remove_filter( $hook, $callback, 10 );
		}
		unset( $_SERVER['HTTP_REFERER'] );

		parent::tearDown();
	}

	/**
	 * The site's fields, as the admin stores them.
	 *
	 * @param array<int, array<string, mixed>> $fields
	 */
	private function define( array $fields ): void {
		diluxone_users_update_option( 'diluxone_users_fields', $fields );
	}

	/** One field definition with sensible defaults. */
	private function field( string $key, string $type = 'text', array $extra = array() ): array {
		return array_merge(
			array(
				'key'      => $key,
				'label'    => ucfirst( str_replace( '_', ' ', $key ) ),
				'type'     => $type,
				'options'  => array(),
				'required' => 0,
				'group'    => 'main',
				'active'   => 1,
			),
			$extra
		);
	}

	/** The field as the plugin hands it around: normalised. */
	private function normal( string $key, string $type = 'text', array $extra = array() ): array {
		return diluxone_users_normalize_field( $this->field( $key, $type, $extra ) );
	}

	/* ── Each type, cleaned as what it is ────────────────────────────── */

	public function test_each_type_keeps_only_what_it_is(): void {
		$this->assertSame( 'ana@example.test', diluxone_users_sanitize( $this->normal( 'f', 'email' ), ' ana@example.test ' ) );
		$this->assertSame( '', diluxone_users_sanitize( $this->normal( 'f', 'email' ), 'not an address' ) );

		$this->assertSame( 'https://example.test/a%20b', diluxone_users_sanitize( $this->normal( 'f', 'url' ), 'https://example.test/a%20b' ) );
		$this->assertSame( '', diluxone_users_sanitize( $this->normal( 'f', 'url' ), 'javascript:alert(1)' ) );

		$this->assertSame( "line one\nline two", diluxone_users_sanitize( $this->normal( 'f', 'textarea' ), "line one\nline two<script>x</script>" ) );

		$this->assertSame( '3.5', diluxone_users_sanitize( $this->normal( 'f', 'number' ), '3.50' ) );
		$this->assertSame( '0', diluxone_users_sanitize( $this->normal( 'f', 'number' ), 'abc' ) );
		$this->assertSame( '', diluxone_users_sanitize( $this->normal( 'f', 'number' ), '   ' ) );

		$this->assertSame( '1990-05-17', diluxone_users_sanitize( $this->normal( 'f', 'date' ), '1990-05-17' ) );
		$this->assertSame( '', diluxone_users_sanitize( $this->normal( 'f', 'date' ), '17/05/1990' ) );
		$this->assertSame( '', diluxone_users_sanitize( $this->normal( 'f', 'date' ), '2024-13-45' ), 'shaped like a date, and no date' );
		$this->assertSame( '', diluxone_users_sanitize( $this->normal( 'f', 'date' ), '2023-02-29' ), 'no leap day that year' );
		$this->assertSame( '2024-02-29', diluxone_users_sanitize( $this->normal( 'f', 'date' ), '2024-02-29' ) );

		$this->assertSame( '', diluxone_users_sanitize( $this->normal( 'f', 'number' ), '1e999' ), 'infinity is no number to keep' );
		$this->assertSame( '0', diluxone_users_sanitize( $this->normal( 'f', 'number' ), 'NAN' ), 'a word, read as nought like any other' );

		$this->assertSame( '+5491155551234', diluxone_users_sanitize( $this->normal( 'f', 'phone' ), '+54 9 11 5555-1234' ) );
		$this->assertSame( '', diluxone_users_sanitize( $this->normal( 'f', 'phone' ), 'no digits' ) );

		$this->assertSame( 'AR', diluxone_users_sanitize( $this->normal( 'f', 'country' ), ' ar ' ) );
		$this->assertSame( '', diluxone_users_sanitize( $this->normal( 'f', 'country' ), 'XX' ), 'A code that is no country' );

		$this->assertSame( '1', diluxone_users_sanitize( $this->normal( 'f', 'checkbox' ), 'on' ) );
		$this->assertSame( '', diluxone_users_sanitize( $this->normal( 'f', 'checkbox' ), '' ) );

		$select = $this->normal( 'f', 'select', array( 'options' => array( 'Red', 'Blue' ) ) );
		$this->assertSame( 'Blue', diluxone_users_sanitize( $select, 'Blue' ) );
		$this->assertSame( '', diluxone_users_sanitize( $select, 'Green' ), 'A closed list is closed' );

		$this->assertSame( 'Hello', diluxone_users_sanitize( $this->normal( 'f', 'text' ), '<b>Hello</b>' ) );
		$this->assertSame( 'free', diluxone_users_sanitize( $this->normal( 'f', 'datalist' ), 'free' ) );
	}

	public function test_an_add_on_may_clean_a_value_its_own_way(): void {
		$own           = static fn( $clean, string $value, array $field ) => 'select' === $field['type'] ? strtoupper( $value ) : null;
		$this->hooks[] = array( 'diluxone_users_sanitize_value', $own );
		add_filter( 'diluxone_users_sanitize_value', $own, 10, 3 );

		$this->assertSame( 'GREEN', diluxone_users_sanitize( $this->normal( 'f', 'select', array( 'options' => array( 'Red' ) ) ), 'green' ) );
		$this->assertSame( 'kept', diluxone_users_sanitize( $this->normal( 'f', 'text' ), 'kept' ), 'null leaves it to the plugin' );
	}

	/* ── What the request is read for ────────────────────────────────── */

	public function test_only_the_fields_are_read_one_value_each_and_an_address_keeps_its_encoding(): void {
		$this->define(
			array(
				$this->field( 'diluxone_test_city' ),
				$this->field( 'diluxone_test_site', 'url' ),
				$this->field( 'diluxone_test_mobile', 'phone', array( 'group' => 'extra' ) ),
				$this->field( 'diluxone_test_list' ),
			)
		);

		$this->postAs(
			0,
			array(
				'diluxone_test_city'        => 'Rosario \\"centro\\"',
				'diluxone_test_site'        => 'https://example.test/a%20b',
				'diluxone_test_mobile'      => '11 5555',
				'diluxone_test_mobile_dial' => 'AR',
				'diluxone_test_list'        => array( 'one', 'two' ),
				'wp_capabilities'           => 'administrator',
			)
		);

		$this->assertSame( array(), diluxone_users_posted_fields( 'diluxone_users_fields_save', '_wpnonce' ), 'without its nonce the form posted nothing' );

		$_POST['_wpnonce'] = wp_create_nonce( 'diluxone_users_fields_save' );
		$all               = diluxone_users_posted_fields( 'diluxone_users_fields_save', '_wpnonce' );

		$this->assertSame( 'Rosario "centro"', $all['diluxone_test_city'], 'Unslashed once' );
		$this->assertSame( 'https://example.test/a%20b', $all['diluxone_test_site'] );
		$this->assertSame( '11 5555', $all['diluxone_test_mobile'] );
		$this->assertSame( 'AR', $all['diluxone_test_mobile_dial'] );
		$this->assertArrayNotHasKey( 'diluxone_test_list', $all, 'A list where one answer goes is no answer' );
		$this->assertArrayNotHasKey( 'wp_capabilities', $all, 'Nothing that is not a field' );

		$this->assertSame( array( 'diluxone_test_mobile', 'diluxone_test_mobile_dial' ), array_keys( diluxone_users_posted_fields( 'diluxone_users_fields_save', '_wpnonce', 'extra' ) ) );
	}

	public function test_the_list_skips_unnamed_switched_off_and_other_blocks_and_finds_one_by_key(): void {
		$this->define(
			array(
				$this->field( 'diluxone_test_a' ),
				$this->field( 'diluxone_test_b', 'text', array( 'label' => '' ) ),
				$this->field( 'diluxone_test_c', 'text', array( 'active' => 0 ) ),
				$this->field( 'diluxone_test_d', 'text', array( 'group' => 'extra' ) ),
			)
		);

		$this->assertSame( array( 'diluxone_test_a', 'diluxone_test_d' ), array_column( diluxone_users_fields(), 'key' ) );
		$this->assertSame( array( 'diluxone_test_d' ), array_column( diluxone_users_fields( 'extra' ), 'key' ) );
		$this->assertSame( array( 'diluxone_test_a', 'diluxone_test_c', 'diluxone_test_d' ), array_column( diluxone_users_fields( '', false ), 'key' ) );

		$this->assertSame( 'diluxone_test_c', diluxone_users_field( 'diluxone_test_c' )['key'], 'Switched off is still a field' );
		$this->assertNull( diluxone_users_field( 'diluxone_test_nope' ) );
	}

	/* ── The save ────────────────────────────────────────────────────── */

	public function test_a_required_field_left_empty_keeps_what_was_there_and_is_named(): void {
		$this->define(
			array(
				$this->field( 'diluxone_test_city', 'text', array( 'required' => 1, 'label' => 'City' ) ),
				$this->field( 'diluxone_test_note' ),
			)
		);
		$user = $this->make_user();
		update_user_meta( $user, 'diluxone_test_city', 'Rosario' );
		update_user_meta( $user, 'diluxone_test_note', 'old' );

		$missing = diluxone_users_save(
			$user,
			array(
				'diluxone_test_city' => '   ',
				'diluxone_test_note' => '',
			)
		);

		$this->assertSame( array( 'City' ), $missing );
		$this->assertSame( 'Rosario', get_user_meta( $user, 'diluxone_test_city', true ) );
		$this->assertSame( array(), get_user_meta( $user, 'diluxone_test_note' ), 'An empty optional field leaves no row behind' );
	}

	public function test_a_phone_is_joined_with_its_dialling_code_and_names_refresh_the_display_name(): void {
		$this->define(
			array(
				$this->field( 'first_name' ),
				$this->field( 'last_name' ),
				$this->field( 'diluxone_test_mobile', 'phone' ),
			)
		);
		$user  = $this->make_user();
		$saved = array();
		$spy           = static function ( int $id, array $input, string $group ) use ( &$saved ): void {
			$saved = array( $id, $group );
		};
		$this->hooks[] = array( 'diluxone_users_fields_saved', $spy );
		add_action( 'diluxone_users_fields_saved', $spy, 10, 3 );

		$this->assertSame(
			array(),
			diluxone_users_save(
				$user,
				array(
					'first_name'                => 'Ana',
					'last_name'                 => 'Pérez',
					'diluxone_test_mobile'      => '11 5555-1234',
					'diluxone_test_mobile_dial' => 'ar',
				)
			)
		);

		$this->assertSame( '+541155551234', get_user_meta( $user, 'diluxone_test_mobile', true ) );
		$this->assertSame( 'Ana Pérez', get_userdata( $user )->display_name );
		$this->assertSame( array( $user, '' ), $saved );

		// Saving the same name again leaves the account alone.
		diluxone_users_save( $user, array( 'first_name' => 'Ana' ) );
		$this->assertSame( 'Ana Pérez', get_userdata( $user )->display_name );
	}

	public function test_a_display_name_is_rebuilt_only_for_somebody_with_a_name(): void {
		diluxone_users_refresh_display_name( 999999 );

		$user   = $this->make_user();
		$before = get_userdata( $user )->display_name;

		diluxone_users_refresh_display_name( $user );
		$this->assertSame( $before, get_userdata( $user )->display_name, 'No name, nothing to build' );

		update_user_meta( $user, 'last_name', 'Gómez' );
		diluxone_users_refresh_display_name( $user );
		$this->assertSame( 'Gómez', get_userdata( $user )->display_name );
	}

	public function test_a_field_that_is_never_editable_is_shown_and_never_saved_by_its_owner(): void {
		$this->define( array( $this->field( 'diluxone_test_dni', 'text', array( 'edit' => 'never' ) ) ) );
		$user = $this->make_user();
		update_user_meta( $user, 'diluxone_test_dni', '123' );
		wp_set_current_user( $user );

		$field = diluxone_users_field( 'diluxone_test_dni' );

		$this->assertFalse( diluxone_users_field_editable( $field, $user ) );
		$this->assertSame( -1, diluxone_users_field_edits_left( $field, $user ) );
		$this->assertStringContainsString( 'cannot be changed from here', diluxone_users_field_edit_note( $field, $user ) );

		diluxone_users_save( $user, array( 'diluxone_test_dni' => '999' ) );
		$this->assertSame( '123', get_user_meta( $user, 'diluxone_test_dni', true ) );

		// Whoever administers can, for somebody else.
		wp_set_current_user( 1 );
		$this->assertTrue( diluxone_users_field_editable( $field, $user ) );
		diluxone_users_save( $user, array( 'diluxone_test_dni' => '999' ) );
		$this->assertSame( '999', get_user_meta( $user, 'diluxone_test_dni', true ) );
	}

	public function test_a_field_editable_a_few_times_spends_a_go_only_on_a_real_change(): void {
		$this->define( array( $this->field( 'diluxone_test_nick', 'text', array( 'edit' => 'limited', 'edit_max' => 2 ) ) ) );
		$user  = $this->make_user();
		$field = diluxone_users_field( 'diluxone_test_nick' );
		wp_set_current_user( $user );

		$this->assertSame( 2, diluxone_users_field_edits_left( $field, $user ) );
		$this->assertSame( 'You can change this one 2 more times.', diluxone_users_field_edit_note( $field, $user ) );

		diluxone_users_save( $user, array( 'diluxone_test_nick' => 'one' ) );
		$this->assertSame( 1, diluxone_users_field_edits( $user, 'diluxone_test_nick' ) );
		$this->assertSame( 'You can change this one 1 more time.', diluxone_users_field_edit_note( $field, $user ) );

		diluxone_users_save( $user, array( 'diluxone_test_nick' => 'one' ) );
		$this->assertSame( 1, diluxone_users_field_edits( $user, 'diluxone_test_nick' ), 'Saving the same thing twice changed nothing' );

		// Emptying it is a change too.
		diluxone_users_save( $user, array( 'diluxone_test_nick' => '' ) );
		$this->assertSame( 2, diluxone_users_field_edits( $user, 'diluxone_test_nick' ) );
		$this->assertSame( '', get_user_meta( $user, 'diluxone_test_nick', true ) );
		$this->assertStringContainsString( 'already used up the changes', diluxone_users_field_edit_note( $field, $user ) );
		$this->assertFalse( diluxone_users_field_editable( $field, $user ) );

		diluxone_users_save( $user, array( 'diluxone_test_nick' => 'three' ) );
		$this->assertSame( '', get_user_meta( $user, 'diluxone_test_nick', true ), 'Out of goes' );

		// An administrator fixing it spends nobody's quota.
		wp_set_current_user( 1 );
		diluxone_users_save( $user, array( 'diluxone_test_nick' => 'fixed' ) );
		$this->assertSame( 'fixed', get_user_meta( $user, 'diluxone_test_nick', true ) );
		$this->assertSame( 2, diluxone_users_field_edits( $user, 'diluxone_test_nick' ) );
	}

	public function test_an_always_editable_field_has_nothing_to_point_out(): void {
		$field = $this->normal( 'diluxone_test_x' );

		$this->assertSame( '', diluxone_users_field_edit_note( $field, 1 ) );
		$this->assertSame( 'always', $field['edit'] );
	}

	/* ── The front-end form's handler ────────────────────────────────── */

	public function test_the_form_refuses_somebody_signed_out(): void {
		$this->postAs( 0, array( '_wpnonce' => 'x' ) );

		$this->expectDie( 'diluxone_users_save_fields_form', 'You have to sign in first.', 401 );
	}

	public function test_the_form_refuses_a_forged_nonce_and_saves_nothing(): void {
		$this->define( array( $this->field( 'diluxone_test_city' ) ) );
		$user = $this->make_user();
		$this->postAs(
			$user,
			array(
				'_wpnonce'           => 'forged',
				'diluxone_test_city' => 'Rosario',
			)
		);

		$this->expectDie( 'diluxone_users_save_fields_form', self::EXPIRED, 403 );
		$this->assertSame( '', get_user_meta( $user, 'diluxone_test_city', true ) );
	}

	public function test_the_form_saves_its_block_and_goes_back_saying_how_it_went(): void {
		$this->define(
			array(
				$this->field( 'diluxone_test_city', 'text', array( 'required' => 1 ) ),
				$this->field( 'diluxone_test_bio', 'textarea', array( 'group' => 'extra' ) ),
			)
		);
		$user = $this->make_user();
		wp_set_current_user( $user );
		$back = home_url( '/my-profile/' );

		$this->postAs(
			$user,
			array(
				'_wpnonce'             => wp_create_nonce( 'diluxone_users_fields_save' ),
				'_wp_http_referer'     => $back,
				'diluxone_users_group' => 'main',
				'diluxone_test_city'   => 'Rosario',
				'diluxone_test_bio'    => 'Not this block',
			)
		);

		$url = $this->expectRedirect( 'diluxone_users_save_fields_form' );

		$this->assertSame( 'saved', $this->redirectState( $url ) );
		$this->assertStringStartsWith( $back, $url );
		$this->assertSame( 'Rosario', get_user_meta( $user, 'diluxone_test_city', true ) );
		$this->assertSame( '', get_user_meta( $user, 'diluxone_test_bio', true ), 'Only the block that was sent' );

		// Missing the required one, and with nowhere to go back to.
		$this->postAs(
			$user,
			array(
				'_wpnonce'           => wp_create_nonce( 'diluxone_users_fields_save' ),
				'diluxone_test_city' => '',
			)
		);

		$url = $this->expectRedirect( 'diluxone_users_save_fields_form' );

		$this->assertSame( 'missing', $this->redirectState( $url ) );
		$this->assertStringStartsWith( home_url( '/' ), $url );
		$this->assertSame( 'Rosario', get_user_meta( $user, 'diluxone_test_city', true ) );
	}

	/* ── The dashboard's profile and Users > Add New ─────────────────── */

	public function test_the_dashboard_profile_saves_behind_its_nonce_and_the_capability(): void {
		$this->define( array( $this->field( 'diluxone_test_city' ) ) );
		$user  = $this->make_user();
		$other = $this->make_user();

		$this->postAs( $user, array( '_wpnonce' => 'forged', 'diluxone_test_city' => 'X' ) );
		$this->expectDie( fn() => diluxone_users_profile_save( $user ), self::EXPIRED, 403 );
		$this->assertSame( '', get_user_meta( $user, 'diluxone_test_city', true ) );

		// A subscriber with a valid nonce for somebody else's profile still cannot edit it.
		$this->postAs( $user, array( '_wpnonce' => wp_create_nonce( 'update-user_' . $other ), 'diluxone_test_city' => 'X' ) );
		diluxone_users_profile_save( $other );
		$this->assertSame( '', get_user_meta( $other, 'diluxone_test_city', true ) );

		$this->postAs( $user, array( '_wpnonce' => wp_create_nonce( 'update-user_' . $user ), 'diluxone_test_city' => 'Córdoba' ) );
		diluxone_users_profile_save( $user );
		$this->assertSame( 'Córdoba', get_user_meta( $user, 'diluxone_test_city', true ) );
	}

	public function test_add_new_saves_only_for_somebody_who_may_create_accounts_with_the_screens_nonce(): void {
		$this->define( array( $this->field( 'diluxone_test_city' ) ) );
		$made = $this->make_user();
		$sub  = $this->make_user();

		$this->postAs( $sub, array( '_wpnonce_create-user' => wp_create_nonce( 'create-user' ), 'diluxone_test_city' => 'X' ) );
		diluxone_users_new_user_save( $made );
		$this->assertSame( '', get_user_meta( $made, 'diluxone_test_city', true ), 'A subscriber creates nobody' );

		$this->postAs( 1, array( '_wpnonce_create-user' => 'forged', 'diluxone_test_city' => 'X' ) );
		$this->expectDie( fn() => diluxone_users_new_user_save( $made ), self::EXPIRED, 403 );
		$this->assertSame( '', get_user_meta( $made, 'diluxone_test_city', true ) );

		$this->postAs( 1, array( '_wpnonce_create-user' => wp_create_nonce( 'create-user' ), 'diluxone_test_city' => 'Mendoza' ) );
		diluxone_users_new_user_save( $made );
		$this->assertSame( 'Mendoza', get_user_meta( $made, 'diluxone_test_city', true ) );
	}

	/* ── WordPress's own registration ────────────────────────────────── */

	public function test_registration_is_left_alone_with_no_fields_or_none_of_ours_posted(): void {
		$errors = new \WP_Error();

		$this->assertSame( $errors, diluxone_users_register_validate( $errors, 'ana', 'ana@example.test' ) );
		$this->assertFalse( $errors->has_errors(), 'No fields at all' );

		$this->define( array( $this->field( 'diluxone_test_city', 'text', array( 'required' => 1 ) ) ) );
		$this->postAs( 0, array( 'user_login' => 'ana' ) );

		$this->assertFalse( diluxone_users_register_fields_posted() );
		$this->assertFalse( diluxone_users_register_validate( new \WP_Error(), 'ana', 'ana@example.test' )->has_errors(), 'Another sign-up that never drew them' );
	}

	public function test_registration_with_our_fields_needs_their_nonce_and_every_required_one(): void {
		$this->define(
			array(
				$this->field( 'diluxone_test_city', 'text', array( 'required' => 1, 'label' => 'City' ) ),
				$this->field( 'diluxone_test_note' ),
			)
		);

		// A list posted where one answer goes still counts as sending the field.
		$this->postAs( 0, array( 'diluxone_test_note' => array( 'x' ) ) );
		$this->assertTrue( diluxone_users_register_fields_posted() );
		$this->assertSame( array( 'diluxone_users_nonce' ), diluxone_users_register_validate( new \WP_Error(), 'ana', 'a@example.test' )->get_error_codes() );

		$this->postAs( 0, array( 'diluxone_users_wp_register_nonce' => 'forged', 'diluxone_test_city' => 'X' ) );
		$this->assertSame( array( 'diluxone_users_nonce' ), diluxone_users_register_validate( new \WP_Error(), 'ana', 'a@example.test' )->get_error_codes() );

		$nonce = wp_create_nonce( 'diluxone_users_wp_register' );

		$this->postAs( 0, array( 'diluxone_users_wp_register_nonce' => $nonce, 'diluxone_test_city' => ' ' ) );
		$errors = diluxone_users_register_validate( new \WP_Error(), 'ana', 'a@example.test' );
		$this->assertSame( array( 'diluxone_users_diluxone_test_city' ), $errors->get_error_codes() );
		$this->assertStringContainsString( 'City', $errors->get_error_message() );

		$this->postAs( 0, array( 'diluxone_users_wp_register_nonce' => $nonce, 'diluxone_test_city' => 'Salta' ) );
		$this->assertFalse( diluxone_users_register_validate( new \WP_Error(), 'ana', 'a@example.test' )->has_errors() );
	}

	public function test_the_registration_answers_are_saved_only_with_the_forms_nonce(): void {
		$this->define( array( $this->field( 'diluxone_test_city' ) ) );
		$user = $this->make_user();

		$this->postAs( 0, array( 'diluxone_test_city' => 'X' ) );
		diluxone_users_register_save( $user );
		$this->assertSame( '', get_user_meta( $user, 'diluxone_test_city', true ), 'No nonce' );

		$this->postAs( 0, array( 'diluxone_users_wp_register_nonce' => 'forged', 'diluxone_test_city' => 'X' ) );
		diluxone_users_register_save( $user );
		$this->assertSame( '', get_user_meta( $user, 'diluxone_test_city', true ), 'A forged nonce' );

		$this->postAs( 0, array( 'diluxone_users_wp_register_nonce' => wp_create_nonce( 'diluxone_users_wp_register' ), 'diluxone_test_city' => 'Jujuy' ) );
		diluxone_users_register_save( $user );
		$this->assertSame( 'Jujuy', get_user_meta( $user, 'diluxone_test_city', true ) );
	}

	/**
	 * Every block the Fields › Usage tab tells a site to write in
	 * `[diluxone_users_fields group="…"]` draws that block's fields. It told
	 * them to write `basic` and `optional` while the blocks are `main` and
	 * `extra`, and those drew an empty form.
	 */
	public function test_the_groups_the_usage_tab_names_draw_their_fields(): void {
		$this->define(
			array(
				$this->field( 'diluxone_test_city' ),
				$this->field( 'diluxone_test_bio', 'textarea', array( 'group' => 'extra' ) ),
			)
		);
		wp_set_current_user( $this->make_user() );

		ob_start();
		diluxone_users_screen_fields_usage();
		preg_match_all( '/group=(?:&quot;|")([a-z]+)(?:&quot;|")/', (string) ob_get_clean(), $named );

		$this->assertSame( array( 'main', 'extra' ), array_values( array_unique( $named[1] ) ), 'the tab names both blocks' );

		foreach ( array( 'main' => 'diluxone_test_city', 'extra' => 'diluxone_test_bio' ) as $group => $key ) {
			ob_start();
			diluxone_users_fields_form( array( 'group' => $group ) );
			$html = (string) ob_get_clean();

			$this->assertStringContainsString( 'name="' . $key . '"', $html, $group );
			$this->assertSame( 1, preg_match_all( '/name="diluxone_test_/', $html ), $group . ': that block only' );
		}
	}

	/**
	 * A field another plugin injects through `diluxone_users_fields` under a
	 * key the plugin keeps for itself is never written, whatever is posted.
	 */
	public function test_a_field_injected_under_a_reserved_key_is_never_written(): void {
		$user = $this->make_user();
		update_user_meta( $user, 'diluxone_users_2fa_on', '1' );

		$this->hook( 'diluxone_users_fields', static fn( array $fields ): array => array_merge( $fields, array( diluxone_users_normalize_field( array( 'key' => 'diluxone_users_2fa_on', 'label' => 'Sneaky' ) ) ) ) );

		diluxone_users_save( $user, array( 'diluxone_users_2fa_on' => '0' ) );

		$this->assertSame( '1', get_user_meta( $user, 'diluxone_users_2fa_on', true ) );
	}
}
