<?php
/**
 * The seams a shape, a colour and a tab of an add-on build on, on the Design
 * screen and on the pages it styles — each one tried from outside.
 *
 * Users+ Pro's layouts, its account area with a cover, its "any colour" and
 * its map of the theme's colours are the add-on these are for; nothing here
 * names them. Each test hooks a seam the way an add-on would and reads what
 * the plugin draws, saves or refuses.
 */

namespace Tests\Integration;

use Tests\Integration\Support\CoverAdminSupport;

class DesignSeamsTest extends IntegrationTestCase {

	use CoverAdminSupport;

	private const KEYS = array(
		'diluxone_users_login_template',
		'diluxone_users_account_template',
		'diluxone_users_style_accent',
		'diluxone_users_account_ground',
		'diluxone_users_wp_login_bg',
		'diluxone_users_pro_previews',
	);

	protected function tearDown(): void {
		foreach ( self::KEYS as $key ) {
			diluxone_users_delete_option( $key );
		}

		$this->cover_admin_reset();
		parent::tearDown();
	}

	/** A shape of the sign-in page an add-on brings, with every piece it may add. */
	private function a_login_shape(): void {
		$this->hook(
			'diluxone_users_login_templates',
			static function ( array $shapes ): array {
				$shapes['neon'] = array(
					'label'      => 'Neon',
					'help'       => 'Dark, with a glow.',
					'classes'    => static fn(): array => array( 'diluxone-users-login-frame--glow', 'bad class"' ),
					'properties' => static fn(): array => array(
						'--diluxone-users-login-image' => 'url(https://example.test/a.jpg)',
						'--neon-hue'                   => '200',
						'color'                        => 'red',
						'--escape'                     => 'red;}body{display:none',
						'--quote'                      => '"',
					),
					'inside'     => static function (): void {
						echo '<div class="neon-panel" aria-hidden="true">Glow<script>alert(1)</script></div>';
					},
				);

				return $shapes;
			}
		);
	}

	private function frame(): string {
		return $this->draw(
			static function (): void {
				diluxone_users_login_frame_open();
				echo 'FORM';
				diluxone_users_login_frame_close();
			}
		);
	}

	/* ── The sign-in page's shapes ────────────────────────────────────── */

	public function test_the_plugin_brings_three_shapes_and_reads_anything_else_as_the_card(): void {
		$this->assertSame( array( 'plain', 'card', 'own' ), array_keys( diluxone_users_login_templates() ) );

		foreach ( array( 'split', 'backdrop', 'mystery' ) as $kept ) {
			diluxone_users_update_option( 'diluxone_users_login_template', $kept );
			$this->assertSame( 'card', diluxone_users_login_template(), $kept );
		}
	}

	public function test_the_card_is_a_frame_with_nothing_of_a_shape_it_is_not(): void {
		diluxone_users_update_option( 'diluxone_users_login_template', 'card' );

		$this->assertSame(
			'<div class="diluxone-users-login-page"><div class="diluxone-users-login-frame diluxone-users-login-frame--card"><div class="diluxone-users-login-frame__box">FORM</div></div></div>',
			$this->frame()
		);
	}

	public function test_an_addons_shape_draws_its_classes_its_properties_and_what_it_holds(): void {
		$this->a_login_shape();
		diluxone_users_update_option( 'diluxone_users_login_template', 'neon' );

		$html = $this->frame();

		$this->assertStringContainsString( 'class="diluxone-users-login-frame diluxone-users-login-frame--neon diluxone-users-login-frame--glow badclass"', $html );
		$this->assertStringContainsString( 'style="--diluxone-users-login-image:url(https://example.test/a.jpg);--neon-hue:200;"', $html, 'properties only, and only values that cannot break out' );
		$this->assertStringContainsString( '<div class="neon-panel" aria-hidden="true">Glow', $html );
		$this->assertStringNotContainsString( '<script', $html, 'what it holds goes through the allow-list' );
		$this->assertMatchesRegularExpression( '#neon-panel.*</div><div class="diluxone-users-login-frame__box">FORM</div></div></div>$#s', $html, 'before the box, inside the frame' );
	}

	public function test_the_design_tab_draws_an_addons_shape_and_hands_it_the_shape_chosen(): void {
		$this->the_admin();
		$this->a_login_shape();
		diluxone_users_update_option( 'diluxone_users_login_template', 'neon' );

		$seen = null;
		$this->hook(
			'diluxone_users_design_login_shape',
			static function ( string $template ) use ( &$seen ): void {
				$seen = $template;
				echo '<p class="neon-settings">Its settings</p>';
			}
		);

		$html = $this->draw( 'diluxone_users_screen_login_shape' );

		$this->assertSame( 'neon', $seen );
		$this->assertStringContainsString( 'value="neon"  checked', str_replace( "'", '"', $html ) );
		$this->assertStringContainsString( 'diluxone-users-templates__art--login-neon', $html );
		$this->assertStringContainsString( 'neon-settings', $html );
	}

	public function test_a_shape_nobody_offers_is_kept_and_the_tab_hands_it_on(): void {
		$this->the_admin();
		diluxone_users_update_option( 'diluxone_users_login_template', 'split' );

		$seen = null;
		$this->hook(
			'diluxone_users_design_login_shape',
			static function ( string $template ) use ( &$seen ): void {
				$seen = $template;
			}
		);

		$html = $this->draw( 'diluxone_users_screen_login_shape' );

		$this->assertSame( 'split', $seen );
		$this->assertStringContainsString( 'Your Pro choice (inactive)', $html );
		$this->assertStringContainsString( 'data-diluxone-users-pro="design/login/shapes"', $html, 'the card after the real answers' );
	}

	/* ── The account area ─────────────────────────────────────────────── */

	public function test_an_addon_adds_classes_properties_and_a_bigger_picture_to_the_account_area(): void {
		$user = $this->make_user();
		wp_set_current_user( $user );

		$this->hook(
			'diluxone_users_account_templates',
			static function ( array $shapes ): array {
				$shapes['band'] = array(
					'label' => 'Band',
					'help'  => 'A band.',
				);

				return $shapes;
			}
		);
		diluxone_users_update_option( 'diluxone_users_account_template', 'band' );

		$seen = array();
		$this->hook(
			'diluxone_users_account_classes',
			static function ( array $classes, string $template ) use ( &$seen ): array {
				$seen[]    = $template;
				$classes[] = 'diluxone-users-account--band-dark';
				$classes[] = 'two words';

				return $classes;
			},
			10,
			2
		);
		$this->hook(
			'diluxone_users_account_style',
			static fn( array $props, string $template ): array => $props + array(
				'--diluxone-users-cover' => '#123456',
				'--bad'                  => 'x;}',
				'background'             => 'red',
			),
			10,
			2
		);
		$this->hook( 'diluxone_users_account_avatar_size', static fn( int $size, string $template ): int => 'band' === $template ? 96 : $size, 10, 2 );

		$html = $this->draw( 'diluxone_users_account_area' );

		$this->assertSame( array( 'band' ), $seen );
		$this->assertMatchesRegularExpression( '#class="diluxone-users-account diluxone-users-account--tabs diluxone-users-account--band [^"]*diluxone-users-account--band-dark twowords"#', $html );
		$this->assertStringContainsString( 'style="--diluxone-users-cover:#123456;"', $html );
		$this->assertMatchesRegularExpression( '#avatar-96#', $html, 'the picture fetched at the size the shape asks for' );
	}

	public function test_the_account_area_reads_a_shape_nobody_offers_as_the_simple_one(): void {
		$this->assertSame( array( 'plain' ), array_keys( diluxone_users_account_templates() ) );

		diluxone_users_update_option( 'diluxone_users_account_template', 'cover' );
		$this->assertSame( 'plain', diluxone_users_account_template() );

		$this->hook( 'diluxone_users_account_avatar_size', static fn(): int => 5000 );
		$this->assertSame( 512, diluxone_users_account_avatar_size( 'plain' ), 'a size is held to something an image can be' );
	}

	public function test_the_account_tab_hands_the_shape_on_and_shows_the_pro_card(): void {
		$this->the_admin();
		diluxone_users_update_option( 'diluxone_users_account_template', 'cover' );

		$seen = null;
		$this->hook(
			'diluxone_users_design_account_shape',
			static function ( string $template ) use ( &$seen ): void {
				$seen = $template;
			}
		);

		$html = $this->draw( 'diluxone_users_screen_appearance_template' );

		$this->assertSame( 'cover', $seen );
		$this->assertStringContainsString( 'Your Pro choice (inactive)', $html );
		$this->assertStringContainsString( 'data-diluxone-users-pro="design/account/shapes"', $html );
		$this->assertStringNotContainsString( 'diluxone_users_account_cover', $html, 'nothing of the cover is drawn here' );
	}

	/* ── Saving a tab ─────────────────────────────────────────────────── */

	public function test_an_addon_hears_the_tab_saved_and_its_writes_show_in_the_live_preview(): void {
		$this->the_admin();

		$heard = array();
		$this->hook(
			'diluxone_users_panel_saved',
			static function ( string $screen, string $tab ) use ( &$heard ): void {
				$heard[] = $screen . '/' . $tab;

				if ( 'login' === $tab ) {
					diluxone_users_save_options( array( 'diluxone_users_login_title' => 'From the add-on' ) );
				}
			},
			10,
			2
		);

		$this->postPanel( DILUXONE_USERS_DESIGN, array( 'diluxone_users_login_template' => 'card' ) );
		$panel = array( 'save' => 'diluxone_users_design_login_save' );

		$would = diluxone_users_preview_would_save( $panel, DILUXONE_USERS_DESIGN, 'login' );

		$this->assertSame( array( DILUXONE_USERS_DESIGN . '/login' ), $heard );
		$this->assertSame( 'From the add-on', $would['diluxone_users_login_title'] ?? null, 'what it would write is previewed with the rest' );
		$this->assertSame( '', (string) diluxone_users_raw_get( 'diluxone_users_login_title', '' ), 'and nothing was written' );

		diluxone_users_panel_save( $panel, DILUXONE_USERS_DESIGN, 'login' );
		$this->assertSame( 'From the add-on', diluxone_users_option( 'diluxone_users_login_title' ) );
		diluxone_users_delete_option( 'diluxone_users_login_title' );
	}

	public function test_a_save_that_refused_tells_nobody(): void {
		$heard = false;
		$this->hook(
			'diluxone_users_panel_saved',
			static function () use ( &$heard ): void {
				$heard = true;
			}
		);

		diluxone_users_panel_save( array( 'save' => '__return_false' ), DILUXONE_USERS_DESIGN, 'login' );

		$this->assertFalse( $heard );
	}

	/* ── Stylesheets ──────────────────────────────────────────────────── */

	public function test_an_addon_adds_a_rule_to_the_answers_and_a_sheet_to_the_previews(): void {
		$this->hook( 'diluxone_users_style_css', static fn( string $css ): string => $css . '.addon{color:red}' );
		$this->hook(
			'diluxone_users_preview_stylesheets',
			static fn( array $sheets ): array => $sheets + array(
				'addon-design' => 'https://example.test/addon.css',
				''             => 'https://example.test/nameless.css',
				'empty'        => '',
			)
		);

		$this->assertStringEndsWith( '.addon{color:red}', diluxone_users_style_css() );

		$document = diluxone_users_preview_document( '<p>BODY</p>' );

		$this->assertStringContainsString( "id='addon-design-css'", $document );
		$this->assertStringContainsString( 'https://example.test/addon.css', $document );
		$this->assertStringNotContainsString( 'nameless.css', $document );
		$this->assertLessThan( strpos( $document, 'addon.css' ), strpos( $document, 'diluxone-users.css' ), 'after the plugin’s own' );
		$this->assertStringContainsString( '.addon{color:red}', $document );
	}

	public function test_an_addon_hears_the_stylesheet_enqueued(): void {
		$this->as_front_end();
		diluxone_users_update_option( 'diluxone_users_styles', 1 );
		diluxone_users_styles();

		$heard = 0;
		$this->hook(
			'diluxone_users_enqueue_styles',
			static function () use ( &$heard ): void {
				++$heard;
			}
		);

		diluxone_users_enqueue_styles();

		$this->assertSame( 1, $heard );
		$this->assertTrue( wp_style_is( 'diluxone-users', 'enqueued' ) );
		wp_dequeue_style( 'diluxone-users' );
	}

	/* ── Colours ──────────────────────────────────────────────────────── */

	public function test_the_stored_map_of_the_theme_colours_is_an_addons_to_read(): void {
		$this->hook(
			'diluxone_users_theme_palette',
			static fn(): array => array(
				'primary' => array(
					'name'  => 'Primary',
					'color' => '#111111',
				),
				'brand'   => array(
					'name'  => 'Brand',
					'color' => '#222222',
				),
			)
		);
		$this->theme_colors( true );
		update_option( 'diluxone_users_color_map', array( 'accent' => 'brand' ) );

		$this->assertSame( array( 'accent' => 'primary' ), diluxone_users_color_map(), 'the guess: a stored map is not the plugin’s to read' );

		$this->hook(
			'diluxone_users_color_map',
			static fn( array $map, array $palette ): array => array(
				'accent' => 'brand',
				'text'   => 'nowhere',
				'nobody' => 'brand',
			) + $map,
			10,
			2
		);

		$this->assertSame( array( 'accent' => 'brand' ), diluxone_users_color_map(), 'a part or a colour that is not there is dropped' );
		$this->assertStringContainsString( '--diluxone-users-accent:#222222;', diluxone_users_theme_colors_css() );

		delete_option( 'diluxone_users_color_map' );
	}

	public function test_a_colour_nothing_offers_is_drawn_as_the_nearest_and_kept_on_its_tab(): void {
		$this->the_admin();
		diluxone_users_update_option( 'diluxone_users_style_accent', '#2a5bd9' );

		$this->assertSame( '#2b59d6', diluxone_users_style_accent(), 'the nearest of the palette' );
		$this->assertStringContainsString( '--diluxone-users-accent:#2b59d6;', diluxone_users_style_tokens() );

		$html = $this->draw( 'diluxone_users_brand_own_colour' );

		$this->assertStringContainsString( 'Your Pro colour (inactive)', $html );
		$this->assertMatchesRegularExpression( '#value="\#2a5bd9"\s+checked#', $html );
		$this->assertStringContainsString( 'this site draws Blue instead', $html );
		$this->assertStringContainsString( 'data-diluxone-users-pro="design/brand/any-color"', $html );

		// Sent back as it went out, it stays.
		$this->postPanel( DILUXONE_USERS_DESIGN, array( 'diluxone_users_style_accent' => '#2a5bd9' ) );
		diluxone_users_design_brand_save();
		$this->assertSame( '#2a5bd9', diluxone_users_raw_get( 'diluxone_users_style_accent' ) );

		// Another colour nobody offered, sent by hand, is no answer.
		$this->postPanel( DILUXONE_USERS_DESIGN, array( 'diluxone_users_style_accent' => '#010203' ) );
		diluxone_users_design_brand_save();
		$this->assertSame( '', diluxone_users_raw_get( 'diluxone_users_style_accent' ) );
	}

	public function test_any_colour_is_an_answer_while_an_addon_says_so_and_it_draws_its_own_choice(): void {
		$this->the_admin();
		$this->hook( 'diluxone_users_any_color', '__return_true' );

		$seen = array();
		$this->hook(
			'diluxone_users_color_choices',
			static function ( string $name, string $value ) use ( &$seen ): void {
				$seen[] = array( $name, $value );
				echo '<label class="addon-any"><input type="radio" name="' . esc_attr( $name ) . '" value="' . esc_attr( $value ) . '" checked></label>';
			},
			10,
			2
		);

		$this->postPanel( DILUXONE_USERS_DESIGN, array( 'diluxone_users_style_accent' => '#010203' ) );
		diluxone_users_design_brand_save();

		$this->assertSame( '#010203', diluxone_users_raw_get( 'diluxone_users_style_accent' ) );
		$this->assertSame( '#010203', diluxone_users_style_accent(), 'drawn as it is' );

		$html = $this->draw( 'diluxone_users_brand_own_colour' );

		$this->assertSame( array( array( 'diluxone_users_style_accent', '#010203' ) ), $seen );
		$this->assertStringContainsString( 'addon-any', $html );
		$this->assertStringNotContainsString( 'Your Pro colour (inactive)', $html );
	}

	public function test_the_ground_and_wp_login_take_their_palettes(): void {
		$this->the_admin();

		$this->postPanel(
			DILUXONE_USERS_DESIGN,
			array(
				'diluxone_users_wp_login_brand' => '1',
				'diluxone_users_wp_login_bg'    => '#0F766E',
			)
		);
		diluxone_users_design_wp_save();
		$this->assertSame( '#0f766e', diluxone_users_raw_get( 'diluxone_users_wp_login_bg' ) );
		$this->assertSame( '#0f766e', diluxone_users_wp_login_bg() );

		$this->postPanel( DILUXONE_USERS_DESIGN, array( 'diluxone_users_wp_login_bg' => '' ) );
		diluxone_users_design_wp_save();
		$this->assertSame( diluxone_users_style_accent(), diluxone_users_wp_login_bg(), 'nothing is the accent, followed' );

		// Kept from a time any colour was an answer: the nearest, read under white.
		diluxone_users_update_option( 'diluxone_users_wp_login_bg', '#ffd700' );
		$this->assertMatchesRegularExpression( '/^#[0-9a-f]{6}$/', diluxone_users_wp_login_bg() );
		$this->assertGreaterThanOrEqual( 4.5, diluxone_users_contrast( diluxone_users_wp_login_bg(), '#ffffff' ) );

		diluxone_users_update_option( 'diluxone_users_account_ground', '#f4f5f9' );
		$this->assertSame( '#f5f6f8', diluxone_users_account_ground() );
		$this->assertStringContainsString( '.diluxone-users-account{background:var(--diluxone-users-ground,#f5f6f8);}', diluxone_users_account_css() );

		// With the theme's colours the shade is mixed from them, still through the property.
		$this->theme_colors( true );
		$this->assertStringContainsString( '.diluxone-users-account{background:var(--diluxone-users-ground,' . diluxone_users_ground_tint( '#f5f6f8' ) . ');}', diluxone_users_account_css() );
		$this->assertStringContainsString( 'follow the theme’s dark mode', $this->draw( 'diluxone_users_screen_appearance_template' ) );
		$this->theme_colors( false );
		$this->assertStringNotContainsString( 'follow the theme’s dark mode', $this->draw( 'diluxone_users_screen_appearance_template' ) );

		$this->postPanel( DILUXONE_USERS_DESIGN, array( 'diluxone_users_account_ground' => '#EEF6F1' ) );
		diluxone_users_design_account_save();
		$this->assertSame( '#eef6f1', diluxone_users_raw_get( 'diluxone_users_account_ground' ) );
	}

	public function test_a_fresh_install_prints_no_ground_and_offers_the_themes_first(): void {
		$this->the_admin();

		$this->assertFalse( diluxone_users_option_is_set( 'diluxone_users_account_ground' ), 'nothing stored' );
		$this->assertSame( '', diluxone_users_account_ground() );

		foreach ( array( 'own', 'theme' ) as $colours ) {
			$this->theme_colors( 'theme' === $colours );

			$css = diluxone_users_account_css();

			$this->assertStringNotContainsString( '--diluxone-users-ground', $css, $colours );
			$this->assertStringNotContainsString( '.diluxone-users-account{background', $css, $colours );
		}

		$html = $this->draw( 'diluxone_users_screen_appearance_template' );

		$this->assertMatchesRegularExpression(
			'#data-diluxone-users-swatches="diluxone_users_account_ground"><label class="du-swatch du-swatch--none is-on"><input type="radio" name="diluxone_users_account_ground" value="" checked=\'checked\'><span class="du-swatch__chip" aria-hidden="true"></span><span class="du-swatch__name">The theme’s</span>#u',
			$html,
			'"The theme’s" is the first swatch, and the one chosen'
		);
	}

	/* ── Where Users+ Pro would go ────────────────────────────────────── */

	public function test_each_design_placeholder_is_one_an_addon_turns_off(): void {
		$this->the_admin();
		$this->theme_colors( true );
		$this->hook(
			'diluxone_users_theme_palette',
			static fn(): array => array(
				'primary' => array(
					'name'  => 'Primary',
					'color' => '#111111',
				),
			)
		);

		$places = array(
			'design/login/shapes'    => 'diluxone_users_screen_login_shape',
			'design/account/shapes'  => 'diluxone_users_screen_appearance_template',
			'design/brand/any-color' => 'diluxone_users_brand_own_colour',
			'design/brand/color-map' => 'diluxone_users_screen_design_brand',
		);

		foreach ( $places as $where => $screen ) {
			$this->assertStringContainsString( 'data-diluxone-users-pro="' . $where . '"', $this->draw( $screen ), $where );
		}

		$this->hook( 'diluxone_users_upsell', static fn( bool $show, string $where ): bool => 0 !== strpos( $where, 'design/' ) && $show, 10, 2 );

		foreach ( $places as $where => $screen ) {
			$this->assertStringNotContainsString( 'data-diluxone-users-pro="' . $where . '"', $this->draw( $screen ), $where );
		}
	}
}
