<?php
/**
 * Issue 69: the signed-out account area on a theme that underlines content
 * links and gives the content column no padding.
 *
 * The block (`templates/account-guest.php`) came out with its title, its line
 * and its Sign in button flush against the left edge, and the button's label
 * underlined. Both are the stylesheet's to settle: the block has to carry its
 * own inner space, and the button has to win against a theme's
 * `.entry-content a { text-decoration: underline }` (one class and one tag).
 * A browser is what draws it, so this reads the template's classes and
 * the stylesheet's rules, and works out which ones reach the block.
 */

namespace Tests\Unit\Repro;

use PHPUnit\Framework\TestCase;

class Issue69Test extends TestCase {

	/** What `.entry-content a` weighs: one class, one tag. */
	private const THEME_LINK_RULE = 101;

	/**
	 * The classes on the template's outer element.
	 *
	 * @return array<int, string>
	 */
	private function guest_classes(): array {
		$source = (string) file_get_contents( DILUXONE_USERS_DIR . 'templates/account-guest.php' );

		$this->assertSame( 1, preg_match( '/<div class="([^"]+)"/', $source, $found ), 'the template opens with a classed element' );

		return preg_split( '/\s+/', trim( $found[1] ) );
	}

	/**
	 * Every rule of the front stylesheet, as selector and declarations.
	 *
	 * @return array<int, array{0: string, 1: string}>
	 */
	private function rules(): array {
		$css = (string) file_get_contents( DILUXONE_USERS_DIR . 'assets/diluxone-users.css' );
		$css = (string) preg_replace( '#/\*.*?\*/#s', '', $css );

		preg_match_all( '/([^{}]+)\{([^{}]*)\}/', $css, $found, PREG_SET_ORDER );

		$rules = array();
		foreach ( $found as $rule ) {
			foreach ( explode( ',', $rule[1] ) as $selector ) {
				$rules[] = array( trim( $selector ), $rule[2] );
			}
		}

		return $rules;
	}

	/**
	 * How much a selector weighs when it can only be about the given classes,
	 * or null when it reaches something else (another class, a tag, a state).
	 * Classes weigh 100, a tag 1; ids do not occur.
	 *
	 * @param array<int, string> $present The classes the block and its button have.
	 * @param string             $last    A class the last compound must carry.
	 */
	private function weight( string $selector, array $present, string $last ): ?int {
		if ( '' === $selector || false !== strpbrk( $selector, ':[#*' ) ) {
			return null;
		}

		$parts  = preg_split( '/\s*[>+~]\s*|\s+/', $selector );
		$weight = 0;

		foreach ( $parts as $part ) {
			if ( 1 === preg_match( '/^a(?=\.)/', $part ) ) {
				$part = substr( $part, 1 );
				++$weight;
			}
			if ( 1 !== preg_match( '/^(\.[A-Za-z0-9_-]+)+$/', $part ) ) {
				return null;
			}
			$classes = explode( '.', substr( $part, 1 ) );
			if ( array() !== array_diff( $classes, $present ) ) {
				return null;
			}
			$weight += 100 * count( $classes );
		}

		$tail = explode( '.', substr( (string) end( $parts ), 1 ) );

		return in_array( $last, $tail, true ) ? $weight : null;
	}

	public function test_the_signed_out_account_block_has_inner_space_of_its_own(): void {
		$classes = $this->guest_classes();
		$padded  = array();

		foreach ( $this->rules() as list( $selector, $body ) ) {
			$last = null;
			foreach ( $classes as $class ) {
				if ( null !== $this->weight( $selector, $classes, $class ) ) {
					$last = $class;
					break;
				}
			}
			if ( null === $last ) {
				continue;
			}
			if ( 1 === preg_match( '/(?:^|;)\s*padding(?:-(?:left|inline|inline-start))?\s*:\s*[^;]*(?:[1-9]|\.\d)/', $body ) ) {
				$padded[] = $selector;
			}
		}

		$this->assertNotSame(
			array(),
			$padded,
			'a theme that gives the content column no padding leaves the title, the line and the button against the left edge unless the block pads itself (classes: ' . implode( ' ', $classes ) . ')'
		);
	}

	public function test_the_sign_in_button_is_not_underlined_by_a_theme_that_underlines_content_links(): void {
		$classes   = $this->guest_classes();
		$present   = array_merge( $classes, array( 'diluxone-users-button' ) );
		$plain     = 0;
		$important = false;

		foreach ( $this->rules() as list( $selector, $body ) ) {
			$weight = $this->weight( $selector, $present, 'diluxone-users-button' );

			if ( null === $weight || 1 !== preg_match( '/(?:^|;)\s*text-decoration(?:-line)?\s*:\s*none(\s*!important)?/', $body, $found ) ) {
				continue;
			}
			if ( ! empty( $found[1] ) ) {
				$important = true;
			}
			$plain = max( $plain, $weight );
		}

		$this->assertTrue(
			$important || $plain > self::THEME_LINK_RULE,
			'the button reaches the page as a link inside the theme\'s content column; its rule against underlining weighs ' . $plain . ' and a theme\'s `.entry-content a` weighs ' . self::THEME_LINK_RULE
		);
	}
}
