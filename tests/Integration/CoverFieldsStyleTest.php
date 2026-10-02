<?php
/**
 * What the design screen decided, as the CSS the front end gets.
 *
 * Nothing is printed for a question nobody answered — a value that means
 * "nothing" still beats the site's own — and each answer becomes the property
 * the sheet already reads: the accent (unless the colours are the theme's),
 * the corners, the border, the control height, the button shapes, the soft
 * notice, and the account area's rows, ground, strip and body. The sheet is
 * registered only when the site wants it, and enqueued early only on a page
 * that carries one of the shortcodes, or that says it needs it.
 */

namespace Tests\Integration;

class CoverFieldsStyleTest extends IntegrationTestCase {

	/** @var bool Whether the sheet was registered before the test. */
	private bool $registered = false;

	/** @var mixed The global post before the test. */
	private $post = null;

	/** @var callable|null The needs-styles filter a test added. */
	private $needs = null;

	protected function setUp(): void {
		parent::setUp();

		$this->registered = wp_style_is( 'diluxone-users', 'registered' );
		$this->post       = $GLOBALS['post'] ?? null;
	}

	protected function tearDown(): void {
		if ( null !== $this->needs ) {
			remove_filter( 'diluxone_users_needs_styles', $this->needs );
			$this->needs = null;
		}

		$GLOBALS['post'] = $this->post;

		wp_dequeue_style( 'diluxone-users' );

		if ( ! $this->registered ) {
			wp_deregister_style( 'diluxone-users' );
		}

		parent::tearDown();
	}

	/** Several settings at once. */
	private function set( array $options ): void {
		foreach ( $options as $key => $value ) {
			diluxone_users_update_option( $key, $value );
		}
	}

	public function test_nothing_answered_prints_nothing(): void {
		$this->assertSame( '', diluxone_users_style_tokens() );
		$this->assertSame( '', diluxone_users_account_css() );
		$this->assertSame( '', diluxone_users_notice_css() );
		$this->assertSame( '', diluxone_users_button_tokens() );
	}

	public function test_each_answer_is_the_property_the_sheet_reads(): void {
		$this->set(
			array(
				'diluxone_users_style_accent'  => '#008671',
				'diluxone_users_style_radius'  => '3',
				'diluxone_users_style_border'  => '1.5',
				'diluxone_users_style_control' => '44',
				'diluxone_users_button_style'  => 'outline',
			)
		);

		$tokens = diluxone_users_style_tokens();

		$this->assertStringContainsString( '--diluxone-users-accent:#008671;--diluxone-users-accent-bg:#008671;', $tokens );
		$this->assertStringContainsString( '--diluxone-users-radius:3px;--diluxone-users-radius-sm:0px;', $tokens, 'The small corner never goes below zero' );
		$this->assertStringContainsString( '--diluxone-users-border-w:1.5px;', $tokens, 'A half pixel is a real answer' );
		$this->assertStringContainsString( '--diluxone-users-control-h:44px;', $tokens );
		$this->assertStringContainsString( '--diluxone-users-btn-bg:transparent;', $tokens );
		$this->assertStringContainsString( '--diluxone-users-btn-edge:var(--diluxone-users-accent);', $tokens );

		$this->assertStringStartsWith( ':root{', diluxone_users_style_css() );

		// With the colours taken from the theme, the accent picked here is not printed.
		diluxone_users_update_option( 'diluxone_users_colors', 'theme' );
		$this->assertStringNotContainsString( '--diluxone-users-accent:', diluxone_users_style_tokens() );
	}

	public function test_a_soft_button_and_a_soft_notice(): void {
		$this->set(
			array(
				'diluxone_users_button_style' => 'soft',
				'diluxone_users_notice_style' => 'soft',
			)
		);

		$this->assertSame( '--diluxone-users-btn-bg:var(--diluxone-users-accent-soft);--diluxone-users-btn-ink:var(--diluxone-users-accent);--diluxone-users-btn-edge:transparent;', diluxone_users_button_tokens() );
		$this->assertStringStartsWith( '.diluxone-users-notice{--diluxone-users-notice-edge:0;', diluxone_users_notice_css() );
		$this->assertStringContainsString( diluxone_users_notice_css(), diluxone_users_style_css() );
	}

	public function test_the_account_areas_measurements_go_in_as_fallbacks(): void {
		$this->set(
			array(
				'diluxone_users_account_row_w'      => '1100',
				'diluxone_users_account_row_pad'    => '24',
				'diluxone_users_account_ground'     => '#f6f7f7',
				'diluxone_users_account_nav_top'    => '8',
				'diluxone_users_account_bar_gap'    => '12',
				'diluxone_users_account_nav_left'   => '16',
				'diluxone_users_account_body_pad'   => '32',
			)
		);

		$css = diluxone_users_account_css();

		$this->assertStringContainsString( '.diluxone-users-account .diluxone-users-account__body{max-width:var(--diluxone-users-row-w,1100px);margin-inline:auto;padding-inline:var(--diluxone-users-row-pad,24px);}', $css, 'as strong as the sheet\'s rule for a contained area' );
		$this->assertStringContainsString( '.diluxone-users-account{background:var(--diluxone-users-ground,#f6f7f7);}', $css );
		$this->assertStringContainsString( ':root{--diluxone-users-nav-top:8px;--diluxone-users-bar-gap:12px;}', $css );
		$this->assertStringNotContainsString( 'nav-bottom', $css, 'Unanswered, unprinted' );
		$this->assertStringContainsString( '.diluxone-users-account__bar .diluxone-users-account__nav{padding-inline:16px 0px;}', $css );
		$this->assertStringContainsString( 'padding-top:var(--diluxone-users-body-top,32px);', $css );
		$this->assertStringContainsString( 'padding-bottom:var(--diluxone-users-body-end,32px);', $css );

		diluxone_users_update_option( 'diluxone_users_account_nav_left', '' );
		diluxone_users_update_option( 'diluxone_users_account_nav_right', '20' );
		$this->assertStringContainsString( 'padding-inline:0px 20px;', diluxone_users_account_css() );
	}

	public function test_the_sheet_is_registered_only_when_wanted_and_enqueued_early_on_a_page_that_needs_it(): void {
		wp_deregister_style( 'diluxone-users' );

		diluxone_users_update_option( 'diluxone_users_styles', 0 );
		diluxone_users_styles();
		$this->assertFalse( wp_style_is( 'diluxone-users', 'registered' ), 'The site styles everything itself' );

		diluxone_users_update_option( 'diluxone_users_styles', 1 );
		diluxone_users_update_option( 'diluxone_users_style_radius', '6' );
		$GLOBALS['post'] = null;
		diluxone_users_styles();

		$this->assertTrue( wp_style_is( 'diluxone-users', 'registered' ) );
		$this->assertFalse( wp_style_is( 'diluxone-users', 'enqueued' ), 'No page, no early sheet' );
		$this->assertContains( diluxone_users_style_css(), (array) wp_styles()->get_data( 'diluxone-users', 'after' ) );

		$GLOBALS['post'] = get_post(
			wp_insert_post(
				array(
					'post_type'    => 'page',
					'post_status'  => 'publish',
					'post_title'   => 'Plain page',
					'post_content' => 'Nothing of ours here.',
				)
			)
		);
		$this->assertFalse( diluxone_users_page_has_shortcode() );

		$this->needs = static fn( bool $needs, \WP_Post $post ): bool => 'Plain page' === $post->post_title;
		add_filter( 'diluxone_users_needs_styles', $this->needs, 10, 2 );
		$this->assertTrue( diluxone_users_page_has_shortcode(), 'A template that draws them says so' );

		$GLOBALS['post'] = get_post(
			wp_insert_post(
				array(
					'post_type'    => 'page',
					'post_status'  => 'publish',
					'post_title'   => 'Profile',
					'post_content' => '<!-- wp:shortcode -->[diluxone_users_fields group="main"]<!-- /wp:shortcode -->',
				)
			)
		);

		foreach ( array( 'diluxone_users_fields', 'diluxone_users_handle', 'diluxone_users_avatar' ) as $tag ) {
			if ( ! shortcode_exists( $tag ) ) {
				add_shortcode( $tag, '__return_empty_string' );
				$added[] = $tag;
			}
		}

		try {
			$this->assertTrue( diluxone_users_page_has_shortcode() );
			diluxone_users_styles();
			$this->assertTrue( wp_style_is( 'diluxone-users', 'enqueued' ) );
		} finally {
			foreach ( $added ?? array() as $tag ) {
				remove_shortcode( $tag );
			}
		}
	}

	public function test_a_page_with_only_the_join_box_gets_the_stylesheet(): void {
		$GLOBALS['post'] = get_post(
			wp_insert_post(
				array(
					'post_type'    => 'page',
					'post_status'  => 'publish',
					'post_title'   => 'Join',
					'post_content' => '[diluxone_users_join]',
				)
			)
		);

		$added = ! shortcode_exists( 'diluxone_users_join' );

		if ( $added ) {
			add_shortcode( 'diluxone_users_join', '__return_empty_string' );
		}

		try {
			$this->assertTrue( diluxone_users_page_has_shortcode(), 'its button is the plugin\'s, and a block theme draws the page after the head' );
		} finally {
			if ( $added ) {
				remove_shortcode( 'diluxone_users_join' );
			}

			unset( $GLOBALS['post'] );
		}
	}

	public function test_a_box_can_end_with_its_own_save_button(): void {
		ob_start();
		diluxone_users_panel_close( 'Save <now>' );
		$html = (string) ob_get_clean();

		$this->assertSame( '<p class="diluxone-users-panel__save"><button type="submit" class="diluxone-users-button">Save &lt;now&gt;</button></p></div></details>', $html );
	}

	/**
	 * What is typed into the boxes that take a length is printed as its
	 * number and nothing else: a value carrying CSS — or the end of the
	 * stylesheet and a script — cannot break out of the rule it lands in.
	 */
	public function test_a_length_with_css_in_it_prints_only_its_number(): void {
		$hostile = '4;}</style><script>x</script>';

		foreach ( array( 'style_radius', 'style_border', 'style_control', 'account_row_w', 'account_row_pad', 'account_body_pad', 'account_nav_top', 'account_nav_bottom', 'account_nav_left', 'account_nav_right', 'account_bar_gap' ) as $key ) {
			diluxone_users_update_option( 'diluxone_users_' . $key, $hostile );
		}

		diluxone_users_update_option( 'diluxone_users_account_ground', 'red;}body{display:none' );

		$css = diluxone_users_style_css();

		$this->assertStringNotContainsString( '<', $css );
		$this->assertStringNotContainsString( 'script', $css );
		$this->assertStringNotContainsString( '}body', $css );
		$this->assertStringNotContainsString( 'red', $css );
		$this->assertStringContainsString( '--diluxone-users-radius:4px;', $css );
	}
}
