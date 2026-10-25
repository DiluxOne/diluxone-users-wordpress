<?php
/**
 * How the site says it is dark, read from what the admin typed.
 *
 * A class, or an attribute with or without the value it must have, and
 * nothing else: the plugin writes it onto the sign-in page of its own as it
 * is, so anything that could run code, paint, or carry markup is no signal.
 */

namespace Tests\Unit;

use Brain\Monkey;
use PHPUnit\Framework\TestCase;

class DarkSignalParseTest extends TestCase {

	use ResetsWpStubs;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		require_once DILUXONE_USERS_DIR . 'includes/theme-colors.php';
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * @param array{on: string, kind: string, name: string, value: ?string}|null $signal
	 */
	private static function said( ?array $signal ): string {
		return null === $signal ? 'refused' : $signal['on'] . ' ' . diluxone_users_dark_signal_text( $signal );
	}

	public function test_c5_a_dark_and_a_light_attribute_with_their_values(): void {
		$this->assertSame(
			array(
				'on'    => 'html',
				'kind'  => 'attr',
				'name'  => 'data-tema',
				'value' => 'oscuro',
			),
			diluxone_users_dark_signal_parse( 'html', 'data-tema="oscuro"' )
		);
		$this->assertSame(
			array(
				'on'    => 'html',
				'kind'  => 'attr',
				'name'  => 'data-tema',
				'value' => 'claro',
			),
			diluxone_users_dark_signal_parse( 'html', 'data-tema="claro"' )
		);
	}

	public function test_every_shape_offered_reads_and_is_written_back_one_way(): void {
		$cases = array(
			array( 'html', '.is-dark', 'html .is-dark' ),
			array( 'body', '.theme-dark_2', 'body .theme-dark_2' ),
			array( 'html', 'data-theme="dark"', 'html data-theme="dark"' ),
			array( 'html', "data-theme='dark'", 'html data-theme="dark"' ),
			array( 'html', 'data-theme=dark', 'html data-theme="dark"' ),
			array( 'body', 'data-mode', 'body data-mode' ),
			array( 'html', 'Data-Theme="Dark"', 'html data-theme="Dark"' ),
			array( 'html', '  data-scheme="night"  ', 'html data-scheme="night"' ),
			array( 'html', 'data-x=""', 'html data-x=""' ),
		);

		foreach ( $cases as list( $on, $typed, $stored ) ) {
			$this->assertSame( $stored, self::said( diluxone_users_dark_signal_parse( $on, $typed ) ), $typed );
		}
	}

	public function test_c7_what_is_not_a_class_or_an_attribute_is_refused(): void {
		$refused = array(
			'',
			'dark mode',
			'.',
			'.is dark',
			'.1dark',
			'onclick="x"',
			'ONLOAD=x',
			'style="color:red"',
			'class="dark"',
			'data-x="<b>"',
			'data-x="a\'b"',
			'data-x=a"b',
			'data-x=<b>',
			'html[x]',
			'[data-theme="dark"]',
			'data-theme="dark" data-x',
			'data-theme=="dark"',
			'#dark',
			'-data',
		);

		foreach ( $refused as $typed ) {
			$this->assertNull( diluxone_users_dark_signal_parse( 'html', $typed ), $typed );
		}

		$this->assertNull( diluxone_users_dark_signal_parse( 'head', '.is-dark' ), 'only <html> or <body>' );
	}
}
