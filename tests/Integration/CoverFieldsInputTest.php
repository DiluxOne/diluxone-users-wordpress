<?php
/**
 * Each field drawn: the input of every type, and the screens it is drawn on.
 *
 * Every type comes out as the control it is — a box, a list, a tick, a list
 * of countries with the preferred ones set apart, a phone split into its
 * dialling code and its number — holding what the person has stored, escaped.
 * A field the person may not change is still shown, read-only or disabled. An
 * add-on may draw its own input, and what it draws goes through the field's
 * tag list. Then where they are drawn: the dashboard profile (without
 * WordPress's own names twice), Users > Add New (empty), WordPress's
 * registration (with its nonce), and the [diluxone_users_fields] shortcode
 * with its block, title and the state it came back with.
 */

namespace Tests\Integration;

class CoverFieldsInputTest extends IntegrationTestCase {

	/** @var callable|null The input filter a test added. */
	private $own_input = null;

	protected function tearDown(): void {
		if ( null !== $this->own_input ) {
			remove_filter( 'diluxone_users_field_input', $this->own_input, 10 );
			$this->own_input = null;
		}

		parent::tearDown();
	}

	/** One field definition, normalised as the plugin hands it around. */
	private function field( string $key, string $type = 'text', array $extra = array() ): array {
		return diluxone_users_normalize_field(
			array_merge(
				array(
					'key'   => $key,
					'label' => 'Label of ' . $key,
					'type'  => $type,
				),
				$extra
			)
		);
	}

	/** What one input prints. */
	private function input( array $field, string $value = '' ): string {
		ob_start();
		diluxone_users_field_input( $field, $value );

		return (string) ob_get_clean();
	}

	/** Captures what a function prints. */
	private function printed( callable $fn ): string {
		ob_start();
		$fn();

		return (string) ob_get_clean();
	}

	/* ── Each type ───────────────────────────────────────────────────── */

	public function test_the_plain_types_are_boxes_of_their_kind_holding_the_value_escaped(): void {
		$cases = array(
			'text'   => 'text',
			'email'  => 'email',
			'url'    => 'url',
			'number' => 'number',
			'date'   => 'date',
		);

		foreach ( $cases as $type => $html_type ) {
			$html = $this->input( $this->field( 'f_' . $type, $type, array( 'placeholder' => 'Type here' ) ), '"><script>' );

			$this->assertStringContainsString( 'type="' . $html_type . '"', $html, $type );
			$this->assertStringContainsString( 'name="f_' . $type . '"', $html );
			$this->assertStringContainsString( 'value="&quot;&gt;&lt;script&gt;"', $html );
			$this->assertStringContainsString( 'placeholder="Type here"', $html );
			$this->assertStringNotContainsString( '<script>', $html );
		}

		$required = $this->input( $this->field( 'f_req', 'text', array( 'required' => 1 ) ) );
		$this->assertStringContainsString( ' required', $required );
	}

	public function test_a_long_text_is_a_textarea(): void {
		$html = $this->input( $this->field( 'f_bio', 'textarea', array( 'required' => 1 ) ), "a\n</textarea><b>" );

		$this->assertStringStartsWith( '<textarea id="f_bio" name="f_bio" rows="4" required', $html );
		$this->assertStringContainsString( '&lt;/textarea&gt;&lt;b&gt;</textarea>', $html );
	}

	public function test_a_fixed_list_marks_the_stored_option(): void {
		$html = $this->input( $this->field( 'f_color', 'select', array( 'options' => array( 'Red', 'Blue' ) ) ), 'Blue' );

		$this->assertStringContainsString( '<select id="f_color" name="f_color">', $html );
		$this->assertStringContainsString( '— Choose —', $html );
		$this->assertStringContainsString( '<option value="Red">Red</option>', $html );
		$this->assertStringContainsString( "<option value=\"Blue\" selected='selected'>Blue</option>", $html );
	}

	public function test_a_tick_carries_its_own_label_and_is_checked_when_stored(): void {
		$html = $this->input( $this->field( 'f_news', 'checkbox' ), '1' );

		$this->assertStringContainsString( 'type="checkbox" id="f_news" name="f_news" value="1"', $html );
		$this->assertStringContainsString( "checked='checked'", $html );
		$this->assertStringContainsString( 'Label of f_news</label>', $html );

		$this->assertStringNotContainsString( 'checked', $this->input( $this->field( 'f_news', 'checkbox' ), '' ) );

		// Unticked it sends nothing, so an empty value goes first under the
		// same name: the save reads it, and empties what was ticked before.
		$this->assertStringStartsWith( '<input type="hidden" name="f_news" value=""><label><input type="checkbox" id="f_news"', $html );
	}

	public function test_a_tick_unticked_in_the_form_empties_what_was_stored(): void {
		$user = $this->make_user();

		diluxone_users_update_option( 'diluxone_users_fields', array( $this->field( 'f_news', 'checkbox' ) ) );
		update_user_meta( $user, 'f_news', '1' );

		// What the browser sends for that form with the box unticked: the
		// hidden empty value, and nothing else under that name.
		$this->assertSame( array(), diluxone_users_save( $user, array( 'f_news' => '' ) ) );
		$this->assertSame( '', get_user_meta( $user, 'f_news', true ) );
	}

	public function test_the_countries_put_the_preferred_ones_first_and_apart(): void {
		$html = $this->input( $this->field( 'f_country', 'country', array( 'options' => array( 'UY', 'AR' ) ) ), 'AR' );

		$uy  = strpos( $html, 'value="UY"' );
		$ar  = strpos( $html, 'value="AR"' );
		$cut = strpos( $html, '<option value="" disabled>' );
		$af  = strpos( $html, 'value="AF"' );

		$this->assertNotFalse( $cut );
		$this->assertTrue( $uy < $ar && $ar < $cut && $cut < $af, 'Uruguay, Argentina, the line, then the rest' );
		$this->assertStringContainsString( "value=\"AR\" selected='selected'>Argentina", $html );

		// With no preferred ones there is no line.
		$this->assertStringNotContainsString( 'disabled>──', $this->input( $this->field( 'f_country', 'country' ) ) );
	}

	public function test_a_phone_is_split_back_into_its_code_and_its_number_longest_code_first(): void {
		$empty = $this->input( $this->field( 'f_mobile', 'phone', array( 'options' => array( 'ar' ) ) ) );

		$this->assertStringContainsString( '<select id="f_mobile-dial" name="f_mobile_dial"', $empty );
		$this->assertStringContainsString( "value=\"AR\" selected='selected'>Argentina +54", $empty, 'The first preferred one by default' );
		$this->assertStringContainsString( 'type="tel" id="f_mobile" name="f_mobile" value=""', $empty );

		// +1242 is the Bahamas, not the United States with 242 in front.
		$bahamas = $this->input( $this->field( 'f_mobile', 'phone' ), '+12425551234' );

		$this->assertStringContainsString( "value=\"BS\" selected='selected'>Bahamas +1242", $bahamas );
		$this->assertStringContainsString( 'value="5551234"', $bahamas );
	}

	public function test_text_with_suggestions_carries_its_list(): void {
		$html = $this->input( $this->field( 'f_gender', 'datalist', array( 'options' => array( 'Woman', 'Man' ) ) ), 'Other' );

		$this->assertStringContainsString( 'value="Other" list="diluxone-users-list-f_gender"', $html );
		$this->assertStringContainsString( '<datalist id="diluxone-users-list-f_gender"><option value="Woman"></option><option value="Man"></option></datalist>', $html );
	}

	public function test_a_field_the_person_cannot_change_is_shown_read_only_or_disabled(): void {
		$user = $this->make_user();
		wp_set_current_user( $user );

		$this->assertStringContainsString( ' readonly', $this->input( $this->field( 'f_a', 'text', array( 'edit' => 'never' ) ), 'x' ) );
		$this->assertStringContainsString( ' readonly', $this->input( $this->field( 'f_b', 'textarea', array( 'edit' => 'never' ) ) ) );
		$this->assertStringContainsString( ' readonly', $this->input( $this->field( 'f_c', 'datalist', array( 'edit' => 'never' ) ) ) );
		$this->assertStringContainsString( ' disabled', $this->input( $this->field( 'f_d', 'select', array( 'edit' => 'never' ) ) ) );
		$this->assertStringContainsString( ' disabled', $this->input( $this->field( 'f_e', 'checkbox', array( 'edit' => 'never' ) ) ) );
		$this->assertStringContainsString( ' disabled', $this->input( $this->field( 'f_f', 'country', array( 'edit' => 'never' ) ) ) );

		$phone = $this->input( $this->field( 'f_g', 'phone', array( 'edit' => 'never' ) ) );
		$this->assertStringContainsString( 'class="diluxone-users-phone__dial" disabled', $phone );
		$this->assertStringContainsString( 'autocomplete="tel-national" readonly', $phone );
	}

	public function test_an_administrator_on_somebody_elses_profile_may_change_what_they_may_not(): void {
		$owner = $this->make_user();
		wp_set_current_user( $this->make_user( 'administrator' ) );

		if ( is_multisite() ) {
			grant_super_admin( get_current_user_id() );
		}

		$field = $this->field( 'f_never', 'text', array( 'edit' => 'never' ) );

		ob_start();
		diluxone_users_field_control( $field, $owner );
		$theirs = (string) ob_get_clean();

		$this->assertStringNotContainsString( ' readonly', $theirs, 'the owner may not; the administrator editing their profile may' );

		// On their own profile the administrator is the owner, and the rule is theirs too.
		ob_start();
		diluxone_users_field_control( $field, get_current_user_id() );
		$this->assertStringContainsString( ' readonly', (string) ob_get_clean() );

		if ( is_multisite() ) {
			revoke_super_admin( get_current_user_id() );
		}
	}

	public function test_an_add_on_may_draw_its_own_input_and_cannot_draw_a_script(): void {
		$this->own_input = static function ( string $html, array $field, string $value, string $id ): string {
			return 'f_own' === $field['key']
				? '<input type="range" id="' . $id . '" name="f_own" value="' . $value . '" data-step="2"><script>alert(1)</script>'
				: $html;
		};
		add_filter( 'diluxone_users_field_input', $this->own_input, 10, 4 );

		$html = $this->input( $this->field( 'f_own' ), '7' );

		$this->assertStringContainsString( '<input type="range" id="f_own" name="f_own" value="7" data-step="2">', $html );
		$this->assertStringNotContainsString( '<script>', $html );

		// Anything else is still the plugin's.
		$this->assertStringContainsString( 'type="text"', $this->input( $this->field( 'f_other' ) ) );

		$tags = diluxone_users_field_input_tags();
		$this->assertArrayNotHasKey( 'script', $tags );
		$this->assertTrue( $tags['input']['data-*'] );
	}

	/* ── The screens they are drawn on ───────────────────────────────── */

	public function test_the_dashboard_profile_draws_the_block_without_wordpresss_own_names(): void {
		diluxone_users_update_option(
			'diluxone_users_fields',
			array(
				array( 'key' => 'first_name', 'label' => 'First name', 'type' => 'text' ),
				array( 'key' => 'diluxone_test_city', 'label' => 'City', 'type' => 'text', 'help' => 'Where you live' ),
			)
		);
		$user = $this->make_user();
		update_user_meta( $user, 'diluxone_test_city', 'Rosario' );

		$this->assertSame( '', $this->printed( fn() => diluxone_users_profile_fields( 'add-new-user' ) ), 'Not a person' );

		$html = $this->printed( fn() => diluxone_users_profile_fields( get_userdata( $user ) ) );

		$this->assertStringContainsString( 'Additional details', $html );
		$this->assertStringContainsString( 'id="diluxone-users-diluxone_test_city" name="diluxone_test_city" value="Rosario"', $html );
		$this->assertStringContainsString( 'Where you live', $html );
		$this->assertStringNotContainsString( 'name="first_name"', $html, 'WordPress already draws it' );

		// Users > Add New: the same block, empty.
		$this->assertSame( '', $this->printed( fn() => diluxone_users_new_user_fields( 'add-existing-user' ) ) );
		$new = $this->printed( fn() => diluxone_users_new_user_fields( 'add-new-user' ) );
		$this->assertStringContainsString( 'name="diluxone_test_city" value=""', $new );
	}

	public function test_no_fields_draw_nothing_on_any_screen(): void {
		diluxone_users_update_option( 'diluxone_users_fields', array() );
		$user = $this->make_user();

		$this->assertSame( '', $this->printed( fn() => diluxone_users_profile_fields( get_userdata( $user ) ) ) );
		$this->assertSame( '', $this->printed( fn() => diluxone_users_new_user_fields( 'add-new-user' ) ) );
		$this->assertSame( '', $this->printed( 'diluxone_users_register_form_fields' ) );
	}

	public function test_wordpresss_registration_gets_every_field_with_the_nonce_they_carry(): void {
		diluxone_users_update_option(
			'diluxone_users_fields',
			array(
				array( 'key' => 'first_name', 'label' => 'First name', 'type' => 'text' ),
				array( 'key' => 'diluxone_test_city', 'label' => 'City', 'type' => 'text', 'help' => 'Where you live' ),
			)
		);

		$html = $this->printed( 'diluxone_users_register_form_fields' );

		$this->assertStringContainsString( 'name="diluxone_users_wp_register_nonce"', $html );
		$this->assertMatchesRegularExpression( '/name="diluxone_users_wp_register_nonce" value="([a-f0-9]+)"/', $html );
		$this->assertStringContainsString( 'name="first_name"', $html, 'Registration has no names of its own' );
		$this->assertStringContainsString( '<label for="diluxone-users-diluxone_test_city">City</label>', $html );
		$this->assertStringContainsString( '<em class="description">Where you live</em>', $html );
	}

	/* ── The [diluxone_users_fields] shortcode ───────────────────────── */

	public function test_the_shortcode_draws_nothing_for_somebody_signed_out(): void {
		$this->assertSame( '', diluxone_users_shortcode_fields( array() ) );
	}

	public function test_the_shortcode_draws_the_block_asked_for_with_its_title_notes_and_nonce(): void {
		diluxone_users_update_option(
			'diluxone_users_fields',
			array(
				array( 'key' => 'diluxone_test_city', 'label' => 'City', 'type' => 'text', 'required' => 1, 'help' => 'Where you live', 'group' => 'main' ),
				array( 'key' => 'diluxone_test_news', 'label' => 'News', 'type' => 'checkbox', 'group' => 'main', 'edit' => 'never' ),
				array( 'key' => 'diluxone_test_bio', 'label' => 'Bio', 'type' => 'textarea', 'group' => 'extra' ),
			)
		);
		$user = $this->make_user();
		update_user_meta( $user, 'diluxone_test_city', 'Rosario' );
		wp_set_current_user( $user );

		$html = diluxone_users_shortcode_fields(
			array(
				'group' => 'main',
				'title' => 'About <you>',
			)
		);

		$this->assertStringContainsString( '<h2 class="diluxone-users-fields__title">About &lt;you&gt;</h2>', $html );
		$this->assertStringContainsString( 'name="diluxone_users_group" value="main"', $html );
		$this->assertStringContainsString( 'name="_wpnonce" value="' . wp_create_nonce( 'diluxone_users_fields_save' ) . '"', $html );
		$this->assertStringContainsString( 'action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"', $html );
		$this->assertStringContainsString( 'value="diluxone_users_fields_save"', $html );
		$this->assertStringContainsString( '<label for="diluxone_test_city">', $html );
		$this->assertStringContainsString( 'diluxone-users-field__required', $html );
		$this->assertStringContainsString( 'value="Rosario"', $html );
		$this->assertStringContainsString( 'Where you live', $html );
		$this->assertStringNotContainsString( '<label for="diluxone_test_news">', $html, 'A tick carries its own label' );
		$this->assertStringContainsString( 'cannot be changed from here', $html );
		$this->assertStringNotContainsString( 'diluxone_test_bio', $html, 'Only the block asked for' );
		$this->assertStringNotContainsString( 'diluxone-users-notice', $html );

		$untitled = diluxone_users_shortcode_fields( '' );
		$this->assertStringNotContainsString( 'diluxone-users-fields__title', $untitled );
		$this->assertStringContainsString( 'diluxone_test_bio', $untitled, 'Without a block, all of them' );
	}

	public function test_the_shortcode_says_how_the_last_save_went(): void {
		diluxone_users_update_option( 'diluxone_users_fields', array( array( 'key' => 'diluxone_test_city', 'label' => 'City', 'type' => 'text' ) ) );
		wp_set_current_user( $this->make_user() );

		$_GET['diluxone-users'] = 'saved';
		$this->assertStringContainsString( 'diluxone-users-notice--ok', diluxone_users_shortcode_fields( array() ) );

		$_GET['diluxone-users'] = 'missing';
		$this->assertStringContainsString( 'Some required fields are missing.', diluxone_users_shortcode_fields( array() ) );
	}
}
