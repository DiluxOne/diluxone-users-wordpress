<?php
/**
 * A required field is required on the server, or it is only a suggestion.
 *
 * The registration form marks every field it asks for `required` — being
 * required is the whole of what puts a field on that form — and the browser
 * does enforce it, right up until it does not. `novalidate` is one attribute
 * away in the inspector, and a form posted by a script never had a browser to
 * enforce anything. Before this, the account was created and the field was
 * simply empty: the site had said it could not do without the answer, and the
 * account existed without it, with no screen anywhere that would ask again.
 */

namespace Tests\Integration;

class RegisterRequiredTest extends IntegrationTestCase {

	/** @var array<int, array<string, mixed>> */
	private const FIELDS = array(
		array(
			'key'      => 'test_city',
			'label'    => 'City',
			'type'     => 'text',
			'required' => 1,
			'active'   => 1,
			'group'    => 'main',
			'edit'     => 'always',
		),
	);

	protected function setUp(): void {
		parent::setUp();

		diluxone_users_update_option( 'diluxone_users_fields', self::FIELDS );
	}

	public function test_the_form_asks_for_the_required_field(): void {
		$keys = wp_list_pluck( diluxone_users_register_fields(), 'key' );

		$this->assertContains( 'test_city', $keys );
	}

	public function test_an_answer_that_is_there_is_not_missing(): void {
		$this->assertSame( array(), diluxone_users_register_missing( array( 'test_city' => 'Mendoza' ) ) );
	}

	/**
	 * @dataProvider whatDoesNotCount
	 * @param array<string, mixed> $input
	 */
	public function test_what_does_not_count_as_an_answer( array $input ): void {
		$this->assertSame( array( 'City' ), diluxone_users_register_missing( $input ) );
	}

	/** @return array<string, array{array<string, mixed>}> */
	public static function whatDoesNotCount(): array {
		return array(
			// Not sent at all: the field was taken off the form.
			'the key is not there' => array( array() ),
			'empty'                => array( array( 'test_city' => '' ) ),
			// Sanitising is what decides, not a bare trim: a value that is only
			// whitespace is an answer to nobody.
			'only spaces'          => array( array( 'test_city' => "   \t\n" ) ),
			// An array where a string is expected is what a hand-built post
			// looks like; it is not an answer either.
			'an array'             => array( array( 'test_city' => array( 'Mendoza' ) ) ),
		);
	}

	public function test_a_field_that_is_not_required_is_never_missing(): void {
		$fields             = self::FIELDS;
		$fields[0]['required'] = 0;
		diluxone_users_update_option( 'diluxone_users_fields', $fields );

		$this->assertSame( array(), diluxone_users_register_missing( array() ) );
	}
}
