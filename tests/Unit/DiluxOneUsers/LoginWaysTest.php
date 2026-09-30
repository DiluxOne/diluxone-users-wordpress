<?php
/**
 * Two decisions the browser suite cannot reach.
 *
 * The end-to-end specs always say which arrangement they want, because a test
 * that measured the height of "whatever the plugin decided" would be
 * measuring two things at once. So the rule that decides it when nobody says
 * — stacked up to two ways in, tabs from three — is only ever exercised on a
 * real site, by the site that never touched the setting. That is the
 * commonest site there is and it had no test at all.
 *
 * The other is the order, and it is here rather than in the browser for a
 * different reason: what is worth pinning is not that a saved order is drawn,
 * which the browser proves, but what happens to a way in that is NOT in the
 * saved order — the one an add-on registered this morning, against an order
 * the site saved last year. It has to appear. A registry where installing
 * something makes it invisible is not a seam, and the failure would be silent
 * on every screen.
 */

namespace Tests\Unit\DiluxOneUsers;

use Brain\Monkey;
use PHPUnit\Framework\TestCase;

class LoginWaysTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		// With Brain Monkey up, because the file hooks itself to `init` as it
		// loads and those hooks are deliberately not stubbed.
		Monkey\setUp();
		require_once DILUXONE_USERS_DIR . 'includes/options.php';
		require_once DILUXONE_USERS_DIR . 'includes/options-scope.php';
		require_once DILUXONE_USERS_DIR . 'includes/login-ways.php';
	}

	protected function tearDown(): void {
		unset(
			$GLOBALS['_test_wp_options']['diluxone_users_login_layout'],
			$GLOBALS['_test_wp_options']['diluxone_users_login_order']
		);
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * The count decides, and 3 is where it turns over.
	 *
	 * Two doors read better one under the other than behind a strip that asks
	 * you to choose before you can see either; from three, the column is
	 * taller than the laptop it is read on.
	 *
	 * @dataProvider howManyWaysIn
	 */
	public function test_the_arrangement_nobody_chose_follows_the_count(int $tabs, string $expected): void {
		$this->assertSame(
			$expected,
			diluxone_users_way_layout($tabs),
			"With {$tabs} ways in behind tabs, a site that never touched the setting should get '{$expected}'"
		);
	}

	public static function howManyWaysIn(): array {
		return [
			'the only way in'  => [1, 'stack'],
			'two'              => [2, 'stack'],
			'three is the turn' => [3, 'tabs'],
			'all four'         => [4, 'tabs'],
			'and an add-on'    => [5, 'tabs'],
		];
	}

	/**
	 * What the site asked for beats the count, in both directions.
	 *
	 * @dataProvider whatTheSiteAsked
	 */
	public function test_the_site_has_the_last_word(string $setting, int $tabs, string $expected): void {
		$GLOBALS['_test_wp_options']['diluxone_users_login_layout'] = $setting;

		$this->assertSame($expected, diluxone_users_way_layout($tabs));
	}

	public static function whatTheSiteAsked(): array {
		return [
			'tabs over two'            => ['tabs', 2, 'tabs'],
			'stacked over four'        => ['stack', 4, 'stack'],
			'asked for nothing'        => ['auto', 4, 'tabs'],
			// A value from an older version, or a hand-edited row: it counts
			// rather than drawing an arrangement that does not exist.
			'a word that means nothing' => ['columns', 4, 'tabs'],
		];
	}

	/** Whatever stays out of the tabs is drawn first, whatever its position says. */
	public function test_what_never_goes_behind_a_tab_is_drawn_first(): void {
		$ordered = diluxone_users_ways_in_order([
			'email'   => $this->way(10),
			'passkey' => $this->way(90, true),
		]);

		$this->assertSame(['passkey', 'email'], array_keys($ordered));
	}

	/** With nothing saved, the order is the one the authors asked for. */
	public function test_with_nothing_saved_the_positions_decide(): void {
		$ordered = diluxone_users_ways_in_order([
			'password' => $this->way(30),
			'social'   => $this->way(10),
			'email'    => $this->way(20),
		]);

		$this->assertSame(['social', 'email', 'password'], array_keys($ordered));
	}

	/** And once the site has dragged them, that is the order. */
	public function test_the_saved_order_wins_over_the_positions(): void {
		$GLOBALS['_test_wp_options']['diluxone_users_login_order'] = ['password', 'email', 'social'];

		$ordered = diluxone_users_ways_in_order([
			'social'   => $this->way(10),
			'email'    => $this->way(20),
			'password' => $this->way(30),
		]);

		$this->assertSame(['password', 'email', 'social'], array_keys($ordered));
	}

	/**
	 * The one this file exists for.
	 *
	 * A way in the site has never seen — installed after the order was saved —
	 * follows the ones that were, in the place its author asked for. It does
	 * not vanish for not being on a list written before it existed, and it
	 * does not jump to the front either.
	 */
	public function test_a_way_in_nobody_has_dragged_yet_is_still_drawn(): void {
		$GLOBALS['_test_wp_options']['diluxone_users_login_order'] = ['password', 'email'];

		$ordered = diluxone_users_ways_in_order([
			'email'    => $this->way(20),
			'password' => $this->way(30),
			'sms'      => $this->way(40),
			'kiosk'    => $this->way(5),
		]);

		$this->assertSame(['password', 'email', 'kiosk', 'sms'], array_keys($ordered));
	}

	/**
	 * And a name in the saved order that is no way in — an add-on that was
	 * removed — moves nothing. It is a name, not a gap on the screen.
	 */
	public function test_a_saved_name_that_is_no_longer_a_way_in_is_ignored(): void {
		$GLOBALS['_test_wp_options']['diluxone_users_login_order'] = ['gone', 'password', '', 'social'];

		$ordered = diluxone_users_ways_in_order([
			'social'   => $this->way(10),
			'password' => $this->way(30),
		]);

		$this->assertSame(['password', 'social'], array_keys($ordered));
	}

	/**
	 * One registered way in, with only what the ordering looks at.
	 *
	 * @return array<string, mixed>
	 */
	private function way(int $position, bool $outside = false): array {
		return ['position' => $position, 'outside' => $outside];
	}
}
