<?php
/**
 * The Design screen's saves that had no test of their own — the sign-in page,
 * registration and the account area — and what three of the design settings
 * do once saved: WordPress's own sign-in screen, the theme's colours and the
 * measuring of the ones the theme's palette does not name.
 *
 * Design is the hub's: on a network it is saved from the main site's
 * dashboard. Every case runs on both topologies.
 */

namespace Tests\Integration;

use Tests\Integration\Support\CoverSettingsSupport;

class CoverSettingsDesignTest extends IntegrationTestCase {

	use CoverSettingsSupport;

	/** @var array<int, mixed> What WordPress's login stylesheet carried after it before the test. */
	private array $login_after = array();

	/** @var bool Whether the plugin's stylesheet was registered before the test. */
	private bool $style_registered = false;

	/** @var array<string, array<string, string>> The palette the filter hands over. */
	private array $palette = array();

	protected function setUp(): void {
		parent::setUp();

		$this->login_after      = (array) ( wp_styles()->get_data( 'login', 'after' ) ?: array() );
		$this->style_registered = wp_style_is( 'diluxone-users', 'registered' );

		do_action( 'diluxone_users_register_panels' );
	}

	protected function tearDown(): void {
		$this->cover_settings_reset();

		if ( isset( wp_styles()->registered['login'] ) ) {
			wp_styles()->registered['login']->extra['after'] = $this->login_after;
		}

		wp_dequeue_style( 'diluxone-users' );

		if ( ! $this->style_registered ) {
			wp_deregister_style( 'diluxone-users' );
		}

		foreach ( array( 'diluxone-users-theme-colors-early', 'diluxone-users-theme-colors' ) as $handle ) {
			wp_dequeue_script( $handle );
			wp_deregister_script( $handle );
		}

		remove_filter( 'diluxone_users_theme_palette', array( $this, 'the_palette' ) );
		remove_filter( 'wp_get_attachment_image_src', array( $this, 'a_logo' ) );
		remove_filter( 'diluxone_users_safe_mode', '__return_true' );

		parent::tearDown();
	}

	/**
	 * The palette filter: whatever the test put in $this->palette.
	 *
	 * @param array<string, array<string, string>> $palette
	 * @return array<string, array<string, string>>
	 */
	public function the_palette( array $palette ): array {
		return $this->palette;
	}

	/**
	 * A logo for attachment 4242, which has no file.
	 *
	 * @param array<int, mixed>|false $image
	 * @return array<int, mixed>|false
	 */
	public function a_logo( $image, int $id ) {
		return 4242 === $id ? array( 'https://example.test/cover-logo.png', 200, 80, false ) : $image;
	}

	/* ── The three saves ───────────────────────────────────────────── */

	/** @return array<string, array{0: string, 1: array<string, mixed>, 2: string}> */
	public static function saves(): array {
		return array(
			'the sign-in page' => array( 'diluxone_users_design_login_save', array( 'diluxone_users_login_title' => 'Hacked' ), 'diluxone_users_login_title' ),
			'the account area' => array( 'diluxone_users_design_account_save', array( 'diluxone_users_account_width' => 'full' ), 'diluxone_users_account_width' ),
			'registration'     => array( 'diluxone_users_design_register_save', array( 'diluxone_users_register_title' => 'Hacked' ), 'diluxone_users_register_title' ),
		);
	}

	/**
	 * @dataProvider saves
	 *
	 * @param array<string, mixed> $post
	 */
	public function test_each_save_stops_on_a_bad_nonce_or_a_missing_capability( string $save, array $post, string $option ): void {
		$before = diluxone_users_raw_get( $option );

		$this->the_admin();
		$this->postAs( get_current_user_id(), $post + array( 'diluxone_users_panel_nonce' => 'not-a-nonce' ) );
		$this->assertSame( array( 'died', self::EXPIRED ), $this->ended( $save ), 'a bad nonce' );

		$this->send_panel( 'diluxone-users-login', $post );
		$this->assertSame( array( 'died', self::EXPIRED ), $this->ended( $save ), 'another screen’s nonce' );

		$this->not_allowed();
		$this->send_panel( DILUXONE_USERS_DESIGN, $post );
		$this->assertSame( array( 'died', 'You are not allowed to do this.' ), $this->ended( $save ), 'a subscriber' );

		$this->assertSame( $before, diluxone_users_raw_get( $option ) );
	}

	public function test_the_sign_in_page_keeps_its_lines_its_links_and_nothing_else(): void {
		$this->the_admin();

		$this->send_panel(
			DILUXONE_USERS_DESIGN,
			array(
				'diluxone_users_login_template'     => 'Split<b>',
				'diluxone_users_login_side'         => 'RIGHT',
				'diluxone_users_login_image'        => '12px',
				'diluxone_users_login_panel_title'  => "First line\nSecond <i>line</i>",
				'diluxone_users_login_panel_points' => "One\nTwo",
				'diluxone_users_login_panel_foot'   => "A foot\nwith no line",
				'diluxone_users_login_title'        => 'Welcome <script>x</script>',
				'diluxone_users_login_legal'        => 'Read the <a href="https://example.test/terms">terms</a><script>alert(1)</script>',
				'diluxone_users_sent_icon'          => 'circle',
			)
		);
		diluxone_users_design_login_save();

		$this->assertSame( 'plain', diluxone_users_option( 'diluxone_users_login_template' ), 'a shape there is not: the plain one' );
		$this->assertSame( 'right', diluxone_users_option( 'diluxone_users_login_side' ) );
		$this->assertSame( 12, (int) diluxone_users_option( 'diluxone_users_login_image' ) );
		$this->assertSame( "First line\nSecond line", diluxone_users_option( 'diluxone_users_login_panel_title' ), 'its lines kept, its tags not' );
		$this->assertSame( "One\nTwo", diluxone_users_option( 'diluxone_users_login_panel_points' ) );
		$this->assertSame( 'A foot with no line', diluxone_users_option( 'diluxone_users_login_panel_foot' ) );
		$this->assertSame( 'Welcome', diluxone_users_option( 'diluxone_users_login_title' ) );
		$this->assertStringContainsString( '<a href="https://example.test/terms">terms</a>', diluxone_users_option( 'diluxone_users_login_legal' ) );
		$this->assertStringNotContainsString( '<script', diluxone_users_option( 'diluxone_users_login_legal' ) );
		$this->assertSame( 'circle', diluxone_users_option( 'diluxone_users_sent_icon' ) );

		// A side that is not one, a legal line that is not a string, an icon
		// nobody drew.
		$this->send_panel(
			DILUXONE_USERS_DESIGN,
			array(
				'diluxone_users_login_side'  => 'up',
				'diluxone_users_login_legal' => array( '<a>terms</a>' ),
				'diluxone_users_sent_icon'   => 'skull',
			)
		);
		diluxone_users_design_login_save();

		$this->assertSame( 'left', diluxone_users_option( 'diluxone_users_login_side' ) );
		$this->assertSame( '', diluxone_users_option( 'diluxone_users_login_legal' ) );
		$this->assertSame( 'plain', diluxone_users_option( 'diluxone_users_sent_icon' ) );
		$this->assertSame( 'plain', diluxone_users_option( 'diluxone_users_login_template' ), 'nothing sent is the plain page' );
	}

	public function test_registrations_words_are_saved_as_plain_text(): void {
		$this->the_admin();

		$this->send_panel(
			DILUXONE_USERS_DESIGN,
			array(
				'diluxone_users_register_title' => 'Join <b>us</b>',
				'diluxone_users_register_intro' => "It is\nfree",
				'diluxone_users_register_done'  => '<script>x</script>Done',
			)
		);
		diluxone_users_design_register_save();

		$this->assertSame( 'Join us', diluxone_users_option( 'diluxone_users_register_title' ) );
		$this->assertSame( 'It is free', diluxone_users_option( 'diluxone_users_register_intro' ) );
		$this->assertSame( 'Done', diluxone_users_option( 'diluxone_users_register_done' ) );
	}

	public function test_the_account_area_takes_a_colour_only_when_its_box_is_ticked_and_it_is_a_colour(): void {
		$this->the_admin();

		$this->send_panel(
			DILUXONE_USERS_DESIGN,
			array(
				'diluxone_users_account_template'    => 'cover',
				'diluxone_users_account_layout'      => 'Side',
				'diluxone_users_account_width'       => 'full',
				'diluxone_users_account_header'      => '1',
				'diluxone_users_account_since'       => '1',
				'diluxone_users_account_cover_own'   => '1',
				'diluxone_users_account_cover'       => '#AbC123',
				'diluxone_users_account_ground_own'  => '1',
				'diluxone_users_account_ground'      => 'red;}body{display:none',
				'diluxone_users_account_cover_kind'  => 'image',
				'diluxone_users_account_cover_image' => '33',
				'diluxone_users_account_nav_small'   => 'wrap',
				'diluxone_users_account_row_w'       => '960px<b>',
				'diluxone_users_account_bar_gap'     => '12px',
			)
		);
		diluxone_users_design_account_save();

		$this->assertSame( 'cover', diluxone_users_option( 'diluxone_users_account_template' ) );
		$this->assertSame( 'side', diluxone_users_option( 'diluxone_users_account_layout' ) );
		$this->assertSame( 'full', diluxone_users_option( 'diluxone_users_account_width' ) );
		$this->assertSame( 1, (int) diluxone_users_option( 'diluxone_users_account_header' ) );
		$this->assertSame( 0, (int) diluxone_users_option( 'diluxone_users_account_avatar' ), 'unticked' );
		$this->assertSame( 1, (int) diluxone_users_option( 'diluxone_users_account_since' ) );
		$this->assertSame( '#AbC123', diluxone_users_option( 'diluxone_users_account_cover' ) );
		$this->assertSame( '', diluxone_users_option( 'diluxone_users_account_ground' ), 'not a colour: none' );
		$this->assertSame( 'image', diluxone_users_option( 'diluxone_users_account_cover_kind' ) );
		$this->assertSame( 33, (int) diluxone_users_option( 'diluxone_users_account_cover_image' ) );
		$this->assertSame( 'wrap', diluxone_users_option( 'diluxone_users_account_nav_small' ) );
		$this->assertSame( '960px', diluxone_users_option( 'diluxone_users_account_row_w' ) );
		$this->assertSame( '12px', diluxone_users_option( 'diluxone_users_account_bar_gap' ) );

		// The pickers sent without their boxes ticked, or not as text.
		$this->send_panel(
			DILUXONE_USERS_DESIGN,
			array(
				'diluxone_users_account_template'  => 'fancy',
				'diluxone_users_account_layout'    => 'sidebar',
				'diluxone_users_account_width'     => 'huge',
				'diluxone_users_account_cover'     => '#000000',
				'diluxone_users_account_ground_own' => '1',
				'diluxone_users_account_ground'    => array( '#000000' ),
				'diluxone_users_account_nav_small' => 'shrink',
				'diluxone_users_account_nav_style' => 'neon',
				'diluxone_users_account_nav_align' => 'justify',
				'diluxone_users_account_cover_kind' => 'video',
			)
		);
		diluxone_users_design_account_save();

		$this->assertSame( 'pills', diluxone_users_option( 'diluxone_users_account_nav_style' ), 'a look there is not: the first one' );
		$this->assertSame( 'start', diluxone_users_option( 'diluxone_users_account_nav_align' ) );
		$this->assertSame( 'color', diluxone_users_option( 'diluxone_users_account_cover_kind' ) );

		$this->assertSame( 'plain', diluxone_users_option( 'diluxone_users_account_template' ) );
		$this->assertSame( 'tabs', diluxone_users_option( 'diluxone_users_account_layout' ), 'a place there is not: the tabs' );
		$this->assertSame( 'contained', diluxone_users_option( 'diluxone_users_account_width' ) );
		$this->assertSame( '', diluxone_users_option( 'diluxone_users_account_cover' ), 'the box decides, not the picker' );
		$this->assertSame( '', diluxone_users_option( 'diluxone_users_account_ground' ) );
		$this->assertSame( 'scroll', diluxone_users_option( 'diluxone_users_account_nav_small' ) );
	}

	/**
	 * A shape an add-on adds through `diluxone_users_account_templates` can
	 * be chosen and kept. The save knew two shapes by name and threw any
	 * other away, while the area itself already accepted it.
	 */
	public function test_an_account_shape_an_add_on_adds_can_be_chosen(): void {
		$this->hook(
			'diluxone_users_account_templates',
			static fn( array $templates ): array => $templates + array(
				'card' => array(
					'label' => 'Card',
					'help'  => 'A card.',
				),
			)
		);
		$this->the_admin();

		$this->send_panel( DILUXONE_USERS_DESIGN, array( 'diluxone_users_account_template' => 'card' ) );
		diluxone_users_design_account_save();

		$this->assertSame( 'card', diluxone_users_option( 'diluxone_users_account_template' ) );
		$this->assertSame( 'card', diluxone_users_account_template() );
	}

	/**
	 * A colour the palette filter hands over that is not a colour is no
	 * entry. It used to be kept, printed empty — `--diluxone-users-accent:;`,
	 * which wipes the plugin's own accent — and the entry's name was run
	 * through the colour check too and came out empty.
	 */
	public function test_a_palette_entry_that_is_not_a_colour_is_dropped_and_names_survive(): void {
		$this->hook(
			'diluxone_users_theme_palette',
			static fn( array $palette ): array => array(
				'bad'  => array( 'name' => 'Bad', 'color' => 'red;}x{' ),
				'good' => array( 'name' => 'Good <b>one</b>', 'color' => '#123456' ),
			)
		);
		diluxone_users_update_option( 'diluxone_users_colors', 'theme' );
		diluxone_users_update_option( 'diluxone_users_color_map', array( 'accent' => 'bad' ) );

		$palette = diluxone_users_theme_palette();

		$this->assertArrayNotHasKey( 'bad', $palette );
		$this->assertSame( array( 'name' => 'Good one', 'color' => '#123456' ), $palette['good'] );
		$this->assertStringNotContainsString( '--diluxone-users-accent:;', diluxone_users_theme_colors_css() );
		$this->assertContains( 'accent', diluxone_users_color_measured_roles(), 'the accent is measured instead' );
	}

	public function test_a_part_mapped_to_a_colour_the_theme_no_longer_has_is_measured_instead(): void {
		$this->hook(
			'diluxone_users_theme_palette',
			static fn(): array => array( 'ink' => array( 'name' => 'Ink', 'color' => '#111111' ) )
		);
		diluxone_users_update_option( 'diluxone_users_colors', 'theme' );
		diluxone_users_update_option(
			'diluxone_users_color_map',
			array(
				'accent' => 'gone',
				'text'   => 'ink',
			)
		);

		$this->assertSame( ':root{--diluxone-users-text:#111111;}', diluxone_users_theme_colors_css() );
		$this->assertContains( 'accent', diluxone_users_color_measured_roles() );
		$this->assertNotContains( 'text', diluxone_users_color_measured_roles() );
	}

	/**
	 * The social buttons keep only the looks the screen offers: a skin, a
	 * shape or a content nobody drew was kept as a class name the stylesheet
	 * knows nothing about, and seven per row as seven.
	 */
	public function test_the_social_buttons_keep_only_the_looks_offered(): void {
		$this->the_admin();

		$this->send_panel(
			DILUXONE_USERS_DESIGN,
			array(
				'diluxone_users_sso_button_skin'    => 'evil',
				'diluxone_users_sso_button_shape'   => '<b>',
				'diluxone_users_sso_button_show'    => 'x',
				'diluxone_users_sso_button_columns' => '7',
			)
		);
		diluxone_users_design_social_save();

		$this->assertSame( 'brand', diluxone_users_option( 'diluxone_users_sso_button_skin' ) );
		$this->assertSame( 'rounded', diluxone_users_option( 'diluxone_users_sso_button_shape' ) );
		$this->assertSame( 'icon-text', diluxone_users_option( 'diluxone_users_sso_button_show' ) );
		$this->assertSame( 2, (int) diluxone_users_option( 'diluxone_users_sso_button_columns' ) );

		$this->send_panel(
			DILUXONE_USERS_DESIGN,
			array(
				'diluxone_users_sso_button_skin'    => 'dark',
				'diluxone_users_sso_button_shape'   => 'pill',
				'diluxone_users_sso_button_show'    => 'icon',
				'diluxone_users_sso_button_columns' => '0',
			)
		);
		diluxone_users_design_social_save();

		$this->assertSame( 'diluxone-users-socials diluxone-users-socials--dark diluxone-users-socials--pill diluxone-users-socials--icon diluxone-users-socials--cols-0', diluxone_users_sso_buttons_class() );
	}

	/* ── WordPress's own sign-in screen ────────────────────────────── */

	/** What the plugin added after WordPress's login stylesheet. */
	private function login_css(): string {
		$after = (array) ( wp_styles()->get_data( 'login', 'after' ) ?: array() );

		return implode( "\n", array_slice( $after, count( $this->login_after ) ) );
	}

	public function test_unbranded_wp_login_is_left_alone(): void {
		diluxone_users_wp_login_styles();

		$this->assertSame( '', $this->login_css() );
		$this->assertSame( 'https://wordpress.org/', diluxone_users_wp_login_url( 'https://wordpress.org/' ) );
		$this->assertSame( 'Powered by WordPress', diluxone_users_wp_login_text( 'Powered by WordPress' ) );
	}

	public function test_branded_wp_login_wears_the_sites_name_colour_and_mark(): void {
		diluxone_users_update_option( 'diluxone_users_wp_login_brand', 1 );
		diluxone_users_update_option( 'diluxone_users_style_radius', '12;}body{display:none' );

		diluxone_users_wp_login_styles();
		$css = $this->login_css();

		$this->assertStringContainsString( 'body.login{background:' . diluxone_users_style_accent() . '}', $css, 'the accent, when nothing else is chosen' );
		$this->assertStringContainsString( 'border-radius:12px', $css, 'a number and px, never the text typed' );
		$this->assertStringNotContainsString( 'display:none', $css );
		$this->assertStringContainsString( 'background-image:none', $css, 'no logo: the name is written instead' );
		$this->assertSame( home_url( '/' ), diluxone_users_wp_login_url( 'https://wordpress.org/' ) );
		$this->assertSame( (string) get_bloginfo( 'name' ), diluxone_users_wp_login_text( 'Powered by WordPress' ) );
	}

	public function test_branded_wp_login_with_its_own_colour_and_logo(): void {
		add_filter( 'wp_get_attachment_image_src', array( $this, 'a_logo' ), 10, 2 );
		diluxone_users_update_option( 'diluxone_users_wp_login_brand', 1 );
		diluxone_users_update_option( 'diluxone_users_wp_login_bg', '#102030' );
		diluxone_users_update_option( 'diluxone_users_wp_login_logo', 4242 );

		diluxone_users_wp_login_styles();
		$css = $this->login_css();

		$this->assertStringContainsString( 'body.login{background:#102030}', $css );
		$this->assertStringContainsString( 'background-image:url(https://example.test/cover-logo.png)', $css );
		$this->assertStringContainsString( 'border-radius:4px', $css, 'the usual radius when none is set' );
	}

	public function test_a_colour_that_is_not_one_prints_nothing(): void {
		diluxone_users_update_option( 'diluxone_users_wp_login_bg', 'red;}' );

		$this->assertSame( '', diluxone_users_wp_login_bg() );
	}

	public function test_i_forgot_my_password_goes_to_the_sign_in_page_only_when_it_can(): void {
		$wp = 'https://example.test/wp-login.php?action=lostpassword';

		// WordPress's own answer.
		$this->assertSame( $wp, diluxone_users_lost_password_url( $wp ) );

		diluxone_users_update_option( 'diluxone_users_lost_password', 'link' );

		// No page to point at.
		$this->assertSame( $wp, diluxone_users_lost_password_url( $wp ) );

		$page = (int) wp_insert_post(
			array(
				'post_type'   => 'page',
				'post_status' => 'publish',
				'post_title'  => 'Cover sign in',
			)
		);
		diluxone_users_update_option( 'diluxone_users_login_page', $page );

		$this->assertSame( (string) get_permalink( $page ), diluxone_users_lost_password_url( $wp ), 'one round trip fewer' );

		// No e-mail link to point at.
		diluxone_users_update_option( 'diluxone_users_login_method', 'password' );
		$this->assertSame( $wp, diluxone_users_lost_password_url( $wp ) );

		// The emergency switch gives it back to WordPress.
		diluxone_users_update_option( 'diluxone_users_login_method', 'both' );
		add_filter( 'diluxone_users_safe_mode', '__return_true' );
		$this->assertSame( $wp, diluxone_users_lost_password_url( $wp ) );
	}

	/* ── The theme's colours ───────────────────────────────────────── */

	public function test_a_colour_value_is_one_of_four_shapes_or_nothing(): void {
		$this->assertSame( '', diluxone_users_color_value( '   ' ) );
		$this->assertSame( '#abc', diluxone_users_color_value( ' #abc ' ) );
		$this->assertSame( 'rgba(0, 0, 0, .5)', diluxone_users_color_value( 'rgba(0, 0, 0, .5)' ) );
		$this->assertSame( 'hsl(120 50% 50%)', diluxone_users_color_value( 'hsl(120 50% 50%)' ) );
		$this->assertSame( 'var(--wp--preset--color--primary)', diluxone_users_color_value( 'var(--wp--preset--color--primary)' ) );
		$this->assertSame( '', diluxone_users_color_value( 'red;}body{display:none' ) );
		$this->assertSame( '', diluxone_users_color_value( 'var(--x);}' ) );
	}

	public function test_a_swatch_follows_the_theme_live_with_the_plugins_own_colour_behind(): void {
		$this->palette = array(
			'brand' => array(
				'name'  => 'Brand',
				'color' => 'var(--ast-global-color-0)',
			),
			'ink'   => array(
				'name'  => 'Ink',
				'color' => '#111111',
			),
			'bad'   => array(
				'name'  => 'Bad',
				'color' => 'url(javascript:alert(1))',
			),
		);
		add_filter( 'diluxone_users_theme_palette', array( $this, 'the_palette' ) );

		$this->assertSame( 'var(--ast-global-color-0, #2b59d6)', diluxone_users_color_swatch( 'accent', 'brand' ) );
		$this->assertSame( '#111111', diluxone_users_color_swatch( 'text', 'ink' ) );
		$this->assertSame( '#16181d', diluxone_users_color_swatch( 'text', '' ), 'nothing chosen: the plugin’s own' );
		$this->assertArrayNotHasKey( 'bad', diluxone_users_theme_palette(), 'a filter cannot hand over a value nothing checked' );
	}

	public function test_the_map_keeps_the_sites_answers_over_the_guess_and_measures_the_rest(): void {
		$this->palette = array(
			'primary'  => array(
				'name'  => 'Primary',
				'color' => '#ff0000',
			),
			'contrast' => array(
				'name'  => 'Contrast',
				'color' => '#000000',
			),
		);
		add_filter( 'diluxone_users_theme_palette', array( $this, 'the_palette' ) );

		$this->assertSame( array(), diluxone_users_color_measured_roles(), 'nothing is measured while the colours are the plugin’s' );

		diluxone_users_update_option( 'diluxone_users_colors', 'theme' );
		diluxone_users_update_option(
			'diluxone_users_color_map',
			array(
				'text'       => '',
				'not-a-role' => 'primary',
			)
		);

		$this->assertSame( array( 'accent' => 'primary' ), diluxone_users_color_map(), 'an empty answer beats the guess; a role that is not one is ignored' );

		$measured = diluxone_users_color_measured_roles();

		$this->assertNotContains( 'accent', $measured );
		$this->assertContains( 'text', $measured );
		$this->assertStringContainsString( '--diluxone-users-accent:#ff0000;', diluxone_users_theme_colors_css() );
	}

	/* ── Measuring what the palette does not name ──────────────────── */

	public function test_the_measured_colours_are_kept_per_theme_and_version(): void {
		$theme = wp_get_theme();

		$this->assertSame( 'diluxone-users-theme-colors:' . $theme->get_stylesheet() . ':' . $theme->get( 'Version' ) . ':' . DILUXONE_USERS_VERSION, diluxone_users_measure_key() );
	}

	public function test_nothing_is_measured_while_the_colours_are_the_plugins(): void {
		$this->as_front_end();

		diluxone_users_measure_early();
		diluxone_users_measure();

		$this->assertFalse( wp_script_is( 'diluxone-users-theme-colors-early', 'enqueued' ) );
		$this->assertFalse( wp_script_is( 'diluxone-users-theme-colors', 'enqueued' ) );
	}

	public function test_the_theme_is_measured_on_the_pages_that_carry_the_plugin(): void {
		$this->palette = array();
		add_filter( 'diluxone_users_theme_palette', array( $this, 'the_palette' ) );
		diluxone_users_update_option( 'diluxone_users_colors', 'theme' );
		$this->as_front_end();

		diluxone_users_measure_early();

		$this->assertTrue( wp_script_is( 'diluxone-users-theme-colors-early', 'enqueued' ), 'the colours kept from an earlier page, before the first paint' );
		$this->assertStringContainsString( wp_json_encode( diluxone_users_measure_key() ), implode( '', (array) wp_scripts()->get_data( 'diluxone-users-theme-colors-early', 'after' ) ) );

		// A page without the plugin's stylesheet has nothing of it to paint.
		diluxone_users_measure();
		$this->assertFalse( wp_script_is( 'diluxone-users-theme-colors', 'enqueued' ) );

		wp_enqueue_style( 'diluxone-users', DILUXONE_USERS_URL . 'assets/diluxone-users.css', array(), DILUXONE_USERS_VERSION );
		diluxone_users_measure();

		$this->assertTrue( wp_script_is( 'diluxone-users-theme-colors', 'enqueued' ) );
		$this->assertStringContainsString( '"accent"', (string) wp_scripts()->get_data( 'diluxone-users-theme-colors', 'data' ), 'every part the palette did not name' );
	}

	public function test_nothing_is_measured_in_the_dashboard(): void {
		$this->palette = array();
		add_filter( 'diluxone_users_theme_palette', array( $this, 'the_palette' ) );
		diluxone_users_update_option( 'diluxone_users_colors', 'theme' );
		wp_enqueue_style( 'diluxone-users', DILUXONE_USERS_URL . 'assets/diluxone-users.css', array(), DILUXONE_USERS_VERSION );

		require_once ABSPATH . 'wp-admin/includes/class-wp-screen.php';
		require_once ABSPATH . 'wp-admin/includes/screen.php';
		$GLOBALS['current_screen'] = \WP_Screen::get( 'dashboard' );

		diluxone_users_measure_early();
		diluxone_users_measure();

		$this->assertFalse( wp_script_is( 'diluxone-users-theme-colors-early', 'enqueued' ) );
		$this->assertFalse( wp_script_is( 'diluxone-users-theme-colors', 'enqueued' ) );
	}

	/** A layout stored that is none of the three draws the tabs, never an area with no menu. */
	public function test_a_layout_that_is_none_of_the_places_draws_the_tabs(): void {
		diluxone_users_update_option( 'diluxone_users_account_layout', 'bogus' );
		wp_set_current_user( $this->make_user() );

		$this->assertSame( 'tabs', diluxone_users_account_layout() );

		ob_start();
		diluxone_users_account_area();
		$html = (string) ob_get_clean();

		$this->assertStringNotContainsString( 'bogus', $html );
		$this->assertStringContainsString( 'diluxone-users-account--tabs', $html );
	}
}
