<?php
/**
 * A spacing of the site menu, as it may be stored and drawn: a number and px
 * or rem, within its range, or nothing. Everything else is refused, so what
 * reaches the menu's CSS is a length and never anything written around one.
 */

namespace Tests\Unit\DiluxOneUsers;

use Tests\Unit\ResetsWpStubs;
use Brain\Monkey;
use PHPUnit\Framework\TestCase;

class SiteMenuLengthTest extends TestCase {

	use ResetsWpStubs;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		require_once DILUXONE_USERS_DIR . 'includes/site-menu.php';
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	/** @return array<string, array{0: string, 1: string}> */
	public function lengths(): array {
		return array(
			'pixels'                  => array( '24px', '24px' ),
			'rem'                     => array( '1.5rem', '1.5rem' ),
			'nothing'                 => array( '0px', '0px' ),
			'spaces and capitals'     => array( '  12PX ', '12px' ),
			'trailing zeros'          => array( '2.500rem', '2.5rem' ),
			'a whole number in rem'   => array( '2.0rem', '2rem' ),
			'empty: the theme’s own'  => array( '', '' ),
			'the most, in px'         => array( '96px', '96px' ),
			'beyond the most, in px'  => array( '500px', '96px' ),
			'the most, in rem'        => array( '6rem', '6rem' ),
			'beyond the most, in rem' => array( '6.5rem', '6rem' ),
		);
	}

	/** @dataProvider lengths */
	public function test_a_length_is_kept_in_its_range( string $sent, string $stored ): void {
		$this->assertSame( $stored, diluxone_users_menu_length( $sent ) );
	}

	/** @return array<string, array{0: string}> */
	public function not_lengths(): array {
		return array(
			'a number alone'         => array( '24' ),
			'below the least'        => array( '-4px' ),
			'another unit'           => array( '2em' ),
			'a percentage'           => array( '10%' ),
			'a word'                 => array( 'auto' ),
			'a calculation'          => array( 'calc(1px + 2px)' ),
			'css after it'           => array( '4px;}body{display:none' ),
			'a variable'             => array( 'var(--x)' ),
			'two of them'            => array( '4px 8px' ),
			'a dot and nothing'      => array( '.5rem' ),
			'a comma'                => array( '1,5rem' ),
			'too long a number'      => array( '123456px' ),
		);
	}

	/** @dataProvider not_lengths */
	public function test_anything_else_is_refused( string $sent ): void {
		$this->assertNull( diluxone_users_menu_length( $sent ) );
	}
}
