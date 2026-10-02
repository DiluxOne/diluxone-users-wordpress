<?php
/**
 * Every public hook changes what it says it changes.
 *
 * A hook is a promise to whoever builds on the plugin — an add-on, a theme, a
 * site's own code — and one that is called and then ignored is a promise that
 * does nothing. Each test adds the hook, then asserts the return, the markup,
 * the redirect or the stored value it was meant to move; hook() takes it off
 * after the test. The documented ones (docs/extending.md) come first.
 */

namespace Tests\Integration;

class HooksTest extends IntegrationTestCase {

	protected function tearDown(): void {
		// A way registered for a test stays registered: the registry is static.
		// It is put out of everybody's sight again.
		diluxone_users_register_way(
			'cover_sms',
			array(
				'label'     => 'SMS',
				'render'    => '__return_empty_string',
				'available' => '__return_false',
			)
		);

		parent::tearDown();
	}

	/* ── Documented ────────────────────────────────────────────────── */

	/**
	 * `diluxone_users_2fa_methods`: an add-on's method is offered only when
	 * the site switched it on, comes in its position, and answers the step.
	 */
	public function test_a_second_step_method_an_add_on_adds_works(): void {
		$sent = array();
		$this->hook(
			'diluxone_users_2fa_methods',
			static function ( array $methods ) use ( &$sent ): array {
				$methods['sms'] = array(
					'channel'  => 'sms',
					'label'    => 'SMS',
					'help'     => 'A text message.',
					'ready'    => '__return_true',
					'send'     => static function ( int $user ) use ( &$sent ): bool {
						$sent[] = $user;

						return true;
					},
					'verify'   => static fn( int $user, string $code ): bool => '424242' === $code,
					'position' => 5,
				);

				return $methods;
			}
		);

		diluxone_users_update_option( 'diluxone_users_2fa_methods', array( 'email' ) );
		$user = $this->make_user();
		$this->assertArrayNotHasKey( 'sms', diluxone_users_2fa_available( $user ), 'not switched on: not offered' );

		diluxone_users_update_option( 'diluxone_users_2fa_methods', array( 'email', 'sms' ) );
		diluxone_users_update_option( 'diluxone_users_2fa_mode', 'required' );
		$this->assertSame( 'sms', array_key_first( diluxone_users_2fa_available( $user ) ), 'first, by its position' );

		$url = $this->expectRedirect( fn() => diluxone_users_2fa_challenge( $user, 'password', false, home_url( '/after/' ) ) );
		$this->assertSame( 'sms', $this->queryArg( $url, 'diluxone_users_method' ) );
		$this->assertSame( array( $user ), $sent, 'its own sender' );
		$this->assertTrue( diluxone_users_2fa_verify( $user, 'sms', '424242' ) );
	}

	/** `diluxone_users_allowed_html`: an element added is kept, and a script still is not. */
	public function test_an_element_added_to_the_allowed_markup_is_kept(): void {
		$this->hook( 'diluxone_users_allowed_html', static fn( array $allowed ): array => $allowed + array( 'mark' => array() ) );

		$this->assertSame( '<mark>x</mark>alert(1)', wp_kses( '<mark>x</mark><script>alert(1)</script>', diluxone_users_allowed_html() ) );
	}

	/** `diluxone_users_field_types`: an added type is kept on save and its answers cleaned as text. */
	public function test_a_field_type_an_add_on_adds_is_kept_and_cleaned(): void {
		$this->hook( 'diluxone_users_field_types', static fn( array $types ): array => $types + array( 'rating' => 'Rating' ) );

		$field = diluxone_users_normalize_field( array( 'key' => 'diluxone_users_stars', 'label' => 'Stars', 'type' => 'rating' ) );

		$this->assertSame( 'rating', $field['type'] );
		$this->assertSame( '5', diluxone_users_sanitize( $field, '<b>5</b>' ) );

		remove_all_filters( 'diluxone_users_field_types' );
		$this->assertSame( 'text', diluxone_users_normalize_field( array( 'key' => 'diluxone_users_stars', 'label' => 'Stars', 'type' => 'rating' ) )['type'], 'without it, a text field' );
	}

	/**
	 * `diluxone_users_panel_scope`: an add-on's tab moves to Network Admin;
	 * an answer that is none of the three is a site's.
	 */
	public function test_a_tab_an_add_on_says_is_the_networks_moves_there(): void {
		diluxone_users_register_panel(
			'diluxone-users-design',
			'addon',
			array(
				'label'  => 'Add-on',
				'render' => '__return_empty_string',
			)
		);

		$this->hook( 'diluxone_users_panel_scope', static fn( string $scope, string $screen, string $panel ): string => 'addon' === $panel ? 'network' : $scope, 10, 3 );
		$this->assertSame( 'network', diluxone_users_panel_scope( 'diluxone-users-design', 'addon' ) );

		if ( is_multisite() ) {
			$this->assertArrayNotHasKey( 'addon', diluxone_users_panels( 'diluxone-users-design' ), 'not on the hub' );
			$this->in_network_admin();
			$this->assertArrayHasKey( 'addon', diluxone_users_panels( 'diluxone-users-design' ), 'in Network Admin' );
		} else {
			$this->assertArrayHasKey( 'addon', diluxone_users_panels( 'diluxone-users-design' ), 'a single site has every tab' );
		}

		remove_all_filters( 'diluxone_users_panel_scope' );
		$this->hook( 'diluxone_users_panel_scope', static fn(): string => 'everywhere' );
		$this->assertSame( 'site', diluxone_users_panel_scope( 'diluxone-users-design', 'addon' ) );
	}

	/**
	 * `diluxone_users_register_sections` and `diluxone_users_sections`: an
	 * add-on's section appears in its place and draws itself; one a filter
	 * takes out is gone; one with a capability is for those who have it.
	 */
	public function test_a_section_an_add_on_registers_is_drawn_and_can_be_taken_out(): void {
		$this->hook(
			'diluxone_users_register_sections',
			static function (): void {
				diluxone_users_register_section(
					'courses',
					array(
						'label'    => 'Courses',
						'position' => 15,
						'render'   => static function (): void {
							echo 'COURSES-HERE';
						},
					)
				);
				diluxone_users_register_section(
					'staff',
					array(
						'label'      => 'Staff',
						'capability' => 'edit_posts',
						'render'     => '__return_empty_string',
					)
				);
			}
		);

		wp_set_current_user( $this->make_user() );
		$sections = diluxone_users_sections();
		$order    = array_keys( $sections );

		$this->assertArrayHasKey( 'courses', $sections );
		$this->assertArrayNotHasKey( 'staff', $sections, 'a subscriber has no edit_posts' );
		$this->assertLessThan( array_search( 'details', $order, true ), array_search( 'courses', $order, true ), 'in its position' );

		ob_start();
		diluxone_users_account_section( $sections['courses'], wp_get_current_user() );
		$this->assertStringContainsString( 'COURSES-HERE', (string) ob_get_clean() );

		wp_set_current_user( $this->make_user( 'editor' ) );
		$this->assertArrayHasKey( 'staff', diluxone_users_sections(), 'an editor has it' );

		$this->hook(
			'diluxone_users_sections',
			static function ( array $sections ): array {
				unset( $sections['security'] );

				return $sections;
			}
		);
		$this->assertArrayNotHasKey( 'security', diluxone_users_sections() );
	}

	/** `diluxone_users_sso_icon_paths`: a drawing an add-on adds is used, with its script taken out. */
	public function test_a_logo_an_add_on_adds_is_drawn_without_its_script(): void {
		$this->hook( 'diluxone_users_sso_icon_paths', static fn( array $paths ): array => $paths + array( 'acme' => '<path d="M0 0h1"/><script>x</script>' ) );

		$svg = diluxone_users_sso_icon( 'acme' );

		$this->assertStringContainsString( '<path d="M0 0h1"', $svg );
		$this->assertStringNotContainsString( '<script', $svg );
		$this->assertSame( diluxone_users_sso_icon( 'fallback' ), diluxone_users_sso_icon( 'nobody-drew-this' ), 'one nobody drew: the fallback' );
	}

	/** `diluxone_users_suggested_fields`: an add-on's suggestion can be added; a reserved one cannot. */
	public function test_a_suggestion_an_add_on_makes_is_added_and_a_reserved_one_is_not(): void {
		$this->in_network_admin();
		$this->hook(
			'diluxone_users_suggested_fields',
			static fn( array $fields ): array => array_merge(
				$fields,
				array(
					array( 'key' => 'diluxone_users_vat', 'label' => 'VAT', 'type' => 'text' ),
					array( 'key' => 'wp_capabilities', 'label' => 'Role', 'type' => 'text' ),
				)
			)
		);

		$this->assertSame( 1, diluxone_users_add_suggested_fields( array( 'diluxone_users_vat', 'wp_capabilities' ) ) );
		$this->assertSame( array( 'diluxone_users_vat' ), array_column( (array) diluxone_users_raw_get( 'diluxone_users_fields' ), 'key' ) );
	}

	/** `diluxone_users_template`: a site's own file replaces the plugin's, and none draws nothing. */
	public function test_a_template_a_site_points_at_is_the_one_drawn(): void {
		$file = (string) tempnam( sys_get_temp_dir(), 'du-template' );
		file_put_contents( $file, 'THEMES-OWN-HOME' ); // phpcs:ignore WordPress.WP.AlternativeFunctions

		try {
			$this->hook( 'diluxone_users_template', static fn( string $path, string $asked ): string => 'account/home.php' === $asked ? $file : $path, 10, 2 );

			$this->assertSame( $file, diluxone_users_template( 'account/home' ) );
			$this->assertStringContainsString( 'THEMES-OWN-HOME', diluxone_users_render( 'account/home', array() ) );

			remove_all_filters( 'diluxone_users_template' );
			$this->hook( 'diluxone_users_template', '__return_empty_string' );
			$this->assertSame( '', diluxone_users_render( 'account/home', array() ) );
		} finally {
			wp_delete_file( $file );
		}

	}

	/** `diluxone_users_login_templates`: an add-on's shape is honoured; one nobody registered is the plain one. */
	public function test_a_sign_in_shape_an_add_on_adds_is_honoured(): void {
		diluxone_users_update_option( 'diluxone_users_login_template', 'carousel' );
		$this->assertSame( 'plain', diluxone_users_login_template() );

		$this->hook( 'diluxone_users_login_templates', static fn( array $shapes ): array => $shapes + array( 'carousel' => array( 'label' => 'Carousel' ) ) );
		$this->assertSame( 'carousel', diluxone_users_login_template() );
	}

	/* ── Undocumented, still public ────────────────────────────────── */

	/** `diluxone_users_2fa_email`: the mail can be reworded, and the code it carries still answers. */
	public function test_the_second_steps_mail_can_be_reworded(): void {
		$user = $this->make_user();
		$this->hook( 'diluxone_users_2fa_email', static fn( $mail, $u, $code ): array => array( 'subject' => 'X', 'body' => 'Code: ' . $code ), 10, 3 );

		diluxone_users_2fa_email_send( $user );
		preg_match( '/Code: (\d{6})/', (string) $this->lastMail()['message'], $m );

		$this->assertSame( 'X', $this->lastMail()['subject'] );
		$this->assertTrue( diluxone_users_2fa_email_verify( $user, $m[1] ?? '' ) );
	}

	/** `diluxone_users_2fa_failed`: heard with the method, `reauth` at the door that turns it off, never on success or under lock. */
	public function test_a_refused_code_is_announced_with_its_method(): void {
		$heard = array();
		$this->hook(
			'diluxone_users_2fa_failed',
			static function ( int $user, string $method ) use ( &$heard ): void {
				$heard[] = array( $user, $method );
			},
			10,
			2
		);
		diluxone_users_update_option( 'diluxone_users_2fa_methods', array( 'email' ) );
		$user = $this->make_user();

		diluxone_users_2fa_verify( $user, 'email', '000000' );
		diluxone_users_2fa_reauth( $user, '000000' );
		$codes = diluxone_users_backup_generate( $user );
		diluxone_users_2fa_verify( $user, 'email', $codes[0] );
		update_user_meta( $user, 'diluxone_users_2fa_lock', time() + 600 );
		diluxone_users_2fa_verify( $user, 'email', '000000' );

		$this->assertSame( array( array( $user, 'email' ), array( $user, 'reauth' ) ), $heard );
	}

	/** `diluxone_users_account_nav_styles`: a style an add-on adds is kept; one nobody registered is pills. */
	public function test_a_menu_style_an_add_on_adds_is_kept(): void {
		diluxone_users_update_option( 'diluxone_users_account_nav_style', 'ribbons' );
		$this->assertSame( 'pills', diluxone_users_account_nav_style() );

		$this->hook( 'diluxone_users_account_nav_styles', static fn( array $styles ): array => $styles + array( 'ribbons' => 'Ribbons' ) );
		$this->assertSame( 'ribbons', diluxone_users_account_nav_style() );
	}

	/** `diluxone_users_admin_login_is_email`: Add New User keeps the username it was given. */
	public function test_add_new_user_can_keep_its_username(): void {
		if ( is_multisite() ) {
			$this->markTestSkipped( 'A single-site case: on a network the address is never the username (CoverAdminChromeTest::test_on_a_network_add_new_user_is_left_as_wordpress_has_it).' );
		}

		$this->hook( 'diluxone_users_admin_login_is_email', '__return_false' );
		$this->postAs( 1, array( '_wpnonce_create-user' => wp_create_nonce( 'create-user' ), 'action' => 'createuser', 'email' => 'kept@example.test' ) );

		diluxone_users_admin_new_user_login();

		$this->assertArrayNotHasKey( 'user_login', $_POST );
	}

	/** `diluxone_users_admin_scheme`: the admin's colours are the ones handed back. */
	public function test_the_admins_colours_follow_the_scheme_filter(): void {
		$this->hook( 'diluxone_users_admin_scheme', static fn( array $colours ): array => array( 'accent' => '#123456' ) + $colours );

		$this->assertSame( '#123456', diluxone_users_admin_scheme()['accent'] );
	}

	/** `diluxone_users_after_panel_{tab}`: fires after the tab is drawn, with the screen. */
	public function test_something_can_be_drawn_after_a_tab(): void {
		wp_set_current_user( 1 );
		diluxone_users_register_panel(
			'diluxone-users-hooks',
			'live',
			array(
				'label'  => 'Live',
				'render' => static function (): void {
					echo 'THE-TAB';
				},
			)
		);
		$this->hook(
			'diluxone_users_after_panel_live',
			static function ( string $screen ): void {
				echo 'after:' . esc_html( $screen );
			}
		);

		ob_start();
		diluxone_users_screen_panels( 'diluxone-users-hooks', 'Hooks' );
		$html = (string) ob_get_clean();

		$this->assertMatchesRegularExpression( '/THE-TAB.*after:diluxone-users-hooks/s', $html );
	}

	/** `diluxone_users_panels`: a tab taken out is not drawn, and the first remaining one is. */
	public function test_a_tab_can_be_taken_out(): void {
		wp_set_current_user( 1 );

		foreach ( array( 'one' => 10, 'two' => 20 ) as $id => $position ) {
			diluxone_users_register_panel(
				'diluxone-users-hooks2',
				$id,
				array(
					'label'    => $id,
					'position' => $position,
					'render'   => static function () use ( $id ): void {
						echo 'TAB-' . esc_html( $id );
					},
				)
			);
		}

		$this->hook(
			'diluxone_users_panels',
			static function ( array $panels ): array {
				unset( $panels['one'] );

				return $panels;
			}
		);

		ob_start();
		diluxone_users_screen_panels( 'diluxone-users-hooks2', 'Hooks' );
		$html = (string) ob_get_clean();

		$this->assertStringContainsString( 'TAB-two', $html );
		$this->assertStringNotContainsString( 'TAB-one', $html );
	}

	/**
	 * `diluxone_users_countries`: what a country field accepts follows it.
	 *
	 * The list is filtered once per request and kept, so the filter has to be
	 * there before the first country is asked for — as a site's code is — and
	 * the test runs in a process where nothing asked before it.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_the_countries_a_field_accepts_follow_the_filter(): void {
		$this->hook( 'diluxone_users_countries', static fn(): array => array( 'AR' => array( 'Argentina', '54' ) ) );

		$field = diluxone_users_normalize_field( array( 'key' => 'diluxone_users_country', 'label' => 'Country', 'type' => 'country' ) );

		$this->assertSame( 'AR', diluxone_users_sanitize( $field, 'AR' ) );
		$this->assertSame( '', diluxone_users_sanitize( $field, 'UY' ) );
	}

	/** `diluxone_users_login_burst` and `diluxone_users_register_burst`: the counts follow, never below one. */
	public function test_the_bursts_follow_their_filters_and_never_go_below_one(): void {
		$this->hook( 'diluxone_users_login_burst', static fn(): int => 2 );
		$this->hook( 'diluxone_users_register_burst', static fn(): int => 0 );

		$this->assertSame( 2, diluxone_users_login_burst() );
		$this->assertSame( 1, diluxone_users_register_burst() );
	}

	/** `diluxone_users_login_email`: the sign-in mail can be reworded and still carries the link, to the right address. */
	public function test_the_sign_in_mail_can_be_reworded(): void {
		$user  = $this->make_user();
		$email = get_userdata( $user )->user_email;
		$seen  = array();
		$this->hook(
			'diluxone_users_login_email',
			static function ( $message, $to, $url ) use ( &$seen ): array {
				$seen = array( $to, $url );

				return array( 'subject' => 'S', 'body' => $url );
			},
			10,
			3
		);

		diluxone_users_login_send( $user, $email, diluxone_users_token_create( $user ) );

		$this->assertSame( 'S', $this->lastMail()['subject'] );
		$this->assertSame( $seen[1], $this->lastMail()['message'] );
		$this->assertSame( $email, $seen[0] );
		$this->assertStringContainsString( 'diluxone_users_token=', $seen[1] );
	}

	/** `diluxone_users_options_with_markup`: a setting it names keeps its links; one it does not, loses them. */
	public function test_a_setting_may_be_allowed_markup(): void {
		$html = '<a href="/x">x</a><script>1</script>';

		diluxone_users_save_options( array( 'diluxone_users_login_intro' => $html ) );
		$this->assertStringNotContainsString( '<a', (string) diluxone_users_raw_get( 'diluxone_users_login_intro' ), 'plain text without it' );

		$this->hook( 'diluxone_users_options_with_markup', static fn( array $keys ): array => array_merge( $keys, array( 'diluxone_users_login_intro' ) ) );
		diluxone_users_save_options( array( 'diluxone_users_login_intro' => $html ) );
		$this->assertStringContainsString( '<a href="/x">x</a>', (string) diluxone_users_raw_get( 'diluxone_users_login_intro' ), 'the link kept with it' );
		$this->assertStringNotContainsString( '<script', (string) diluxone_users_raw_get( 'diluxone_users_login_intro' ), 'and never a script' );
	}

	/** `diluxone_users_passkey_rp_id`: the domain a passkey belongs to follows it, in the options and in the check. */
	public function test_the_passkeys_domain_follows_the_filter(): void {
		$this->hook( 'diluxone_users_passkey_rp_id', static fn(): string => 'example.test' );

		$this->assertSame( 'example.test', diluxone_users_passkey_rp_id() );
		$this->assertSame( 'example.test', diluxone_users_passkeys_login_options()['rpId'] );
		$this->assertNotNull( diluxone_users_passkey_auth_data( hash( 'sha256', 'example.test', true ) . chr( 0x05 ) . pack( 'N', 1 ) ) );
		$this->assertNull( diluxone_users_passkey_auth_data( hash( 'sha256', (string) wp_parse_url( home_url(), PHP_URL_HOST ), true ) . chr( 0x05 ) . pack( 'N', 1 ) ), 'the host itself is no longer it' );
	}

	/** `diluxone_users_plugin_name`: the menu and the browser tab say the name given. */
	public function test_the_plugins_name_follows_the_filter(): void {
		$this->hook( 'diluxone_users_plugin_name', static fn(): string => 'Members' );

		$this->assertSame( 'Members', diluxone_users_plugin_name() );
	}

	/** `diluxone_users_register_fields`: the fields the form asks for follow it. */
	public function test_the_registration_forms_fields_follow_the_filter(): void {
		diluxone_users_update_option( 'diluxone_users_fields', array( array( 'key' => 'diluxone_users_city', 'label' => 'City', 'required' => 1 ) ) );
		$this->assertSame( array( 'diluxone_users_city' ), array_column( diluxone_users_register_fields(), 'key' ) );

		$this->hook( 'diluxone_users_register_fields', '__return_empty_array' );
		$this->assertSame( array(), diluxone_users_register_fields() );
		$this->assertSame( array(), diluxone_users_register_missing( array() ), 'nothing asked, nothing missing' );
	}

	/** `diluxone_users_register_ways`: an add-on's way in is known, offered when available, and drawn in its place. */
	public function test_a_way_in_an_add_on_registers_is_drawn_in_its_place(): void {
		diluxone_users_register_way(
			'cover_sms',
			array(
				'label'     => 'SMS',
				'icon'      => 'unknown',
				'render'    => static function (): void {
					echo 'SMS-FORM';
				},
				'available' => '__return_false',
				'position'  => 25,
			)
		);

		$this->assertArrayHasKey( 'cover_sms', diluxone_users_ways_known() );
		$this->assertArrayNotHasKey( 'cover_sms', diluxone_users_ways() );

		diluxone_users_register_way(
			'cover_sms',
			array(
				'label'     => 'SMS',
				'icon'      => 'unknown',
				'render'    => static function (): void {
					echo 'SMS-FORM';
				},
				'available' => '__return_true',
				'position'  => 25,
			)
		);

		$this->assertArrayHasKey( 'cover_sms', diluxone_users_ways() );

		ob_start();
		diluxone_users_ways_render();
		$this->assertStringContainsString( 'SMS-FORM', (string) ob_get_clean() );
	}

	/** `diluxone_users_role_forbidden_caps`: a site can let people give themselves a role it would otherwise refuse. */
	public function test_a_site_can_let_people_take_a_role_it_would_refuse(): void {
		add_role( 'cover_mod', 'Cover mod', array( 'read' => true, 'moderate_comments' => true ) );

		try {
			$this->assertFalse( diluxone_users_role_self_serve( 'cover_mod' ) );

			$this->hook( 'diluxone_users_role_forbidden_caps', static fn( array $caps ): array => array_values( array_diff( $caps, array( 'moderate_comments' ) ) ) );
			$this->assertTrue( diluxone_users_role_self_serve( 'cover_mod' ) );

			diluxone_users_update_option( 'diluxone_users_login_role', 'cover_mod' );
			$this->assertSame( 'cover_mod', diluxone_users_register_role() );
		} finally {
			remove_role( 'cover_mod' );
		}
	}

	/** `diluxone_users_sso_base`: the social return lives under the segment given. */
	public function test_the_social_return_moves_with_its_base(): void {
		$this->hook( 'diluxone_users_sso_base', static fn(): string => '/social-login/' );

		$this->assertSame( 'social-login', diluxone_users_sso_base() );
		$this->assertStringEndsWith( '/social-login/google/', diluxone_users_sso_redirect_uri( 'google' ) );
	}
}
