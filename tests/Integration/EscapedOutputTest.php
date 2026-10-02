<?php
/**
 * What the plugin prints from a string it built goes through `wp_kses()`, and
 * `wp_kses()` takes nothing out of it.
 *
 * The first half is what wordpress.org's review asks for: escape late, at the
 * line that prints, so a reviewer reading that line can see it is safe. The
 * second half is what keeps that from breaking the plugin: a tab whose button
 * lost its `form` attribute, or a drawing that lost its `viewBox`, would look
 * escaped and work wrong. So every screen, every tab, the account area and
 * the forms are drawn, every string handed to `wp_kses()` with the plugin's
 * list is caught on the way in, and filtering it again must change nothing.
 */

namespace Tests\Integration;

class EscapedOutputTest extends IntegrationTestCase {

	/** @var string[] What was handed to wp_kses() with the plugin's list. */
	private array $seen = array();

	/** @var string[] */
	private array $get = array();

	protected function setUp(): void {
		parent::setUp();

		$this->get = $_GET;
		add_filter( 'pre_kses', array( $this, 'catch_kses' ), 10, 2 );
	}

	protected function tearDown(): void {
		remove_filter( 'pre_kses', array( $this, 'catch_kses' ), 10 );
		$_GET = $this->get;
		diluxone_users_ui_save_queue();
		parent::tearDown();
	}

	/**
	 * Keeps what the plugin is about to print through its own list.
	 *
	 * @param string|mixed $content
	 * @param mixed        $allowed
	 * @return string|mixed
	 */
	public function catch_kses( $content, $allowed ) {
		if ( is_string( $content ) && '' !== trim( $content ) && diluxone_users_allowed_html() === $allowed ) {
			$this->seen[] = $content;
		}

		return $content;
	}

	public function test_every_tab_of_every_screen_prints_whole(): void {
		require_once ABSPATH . 'wp-admin/includes/admin.php';

		wp_set_current_user( $this->make_user( 'administrator' ) );

		if ( is_multisite() ) {
			grant_super_admin( get_current_user_id() );
		}

		do_action( 'diluxone_users_register_panels' );

		$drawn = $this->draw_every_screen();

		if ( is_multisite() ) {
			$this->in_network_admin();
			$drawn += $this->draw_every_screen( 'network' );
		}

		$this->assertGreaterThan( 20, $drawn, 'Every screen and every tab was drawn' );
		$this->assert_nothing_lost();

		if ( is_multisite() ) {
			revoke_super_admin( get_current_user_id() );
		}
	}

	public function test_the_account_area_and_the_forms_print_whole(): void {
		$user = $this->make_user( 'subscriber' );
		wp_set_current_user( $user );

		diluxone_users_update_option( 'diluxone_users_avatar_upload', 1 );
		diluxone_users_update_option( 'diluxone_users_handle_enabled', 1 );

		ob_start();
		diluxone_users_account_area();

		foreach ( diluxone_users_sections() as $section ) {
			diluxone_users_account_section( $section, wp_get_current_user() );
		}

		diluxone_users_avatar_form();
		diluxone_users_handle_form();
		diluxone_users_fields_form();
		diluxone_users_sessions_list();
		diluxone_users_accounts_list();
		diluxone_users_notifications_form();
		$html = (string) ob_get_clean();

		wp_set_current_user( 0 );
		$html .= diluxone_users_shortcode_login() . diluxone_users_shortcode_register();

		$this->assertStringContainsString( 'diluxone-users-account', $html );
		$this->assert_nothing_lost();
	}

	public function test_a_letters_avatar_keeps_its_picture(): void {
		$user = $this->make_user( 'subscriber' );

		$img = wp_kses( (string) get_avatar( $user, 64 ), diluxone_users_avatar_tags(), diluxone_users_avatar_protocols() );

		if ( false !== strpos( (string) get_avatar_url( $user ), 'data:image/svg+xml' ) ) {
			$this->assertStringContainsString( "src='data:image/svg+xml", $img, 'The drawn avatar is printed, not emptied' );
		}

		$this->assertSame(
			'<img src="x">',
			wp_kses( '<img src="x" onerror="alert(1)"><a href="data:text/html,x">', diluxone_users_avatar_tags(), diluxone_users_avatar_protocols() ),
			'An image and nothing else: no handler, no link'
		);
	}

	public function test_the_list_keeps_scripts_out(): void {
		$this->assertSame(
			'<button type="button">x</button>alert(1)',
			wp_kses( '<button type="button" onclick="alert(1)">x</button><script>alert(1)</script>', diluxone_users_allowed_html() )
		);
		$this->assertStringNotContainsString( 'javascript:', wp_kses( '<a href="javascript:alert(1)">x</a>', diluxone_users_allowed_html() ) );
	}

	/** What a hostile value looks like: a tag, and an attribute breaking out of its quotes. */
	private const HOSTILE = '"><script>alert(9)</script><b onmouseover="alert(9)">x</b>';

	/** That none of it came through as markup. */
	private function assert_nothing_hostile( string $html, string $where ): void {
		$this->assertStringNotContainsString( '<script>alert(9)', $html, $where . ': a script tag' );
		$this->assertStringNotContainsString( 'onmouseover="alert(9)"', $html, $where . ': a handler' );
	}

	/**
	 * Values a person or an administrator typed, hostile ones, everywhere
	 * they are drawn: field labels and help, a section's name and content, a
	 * person's name, the sign-in page's words. Not one comes out as markup —
	 * on any screen, in the account area or on the forms.
	 */
	public function test_hostile_values_come_out_as_text_everywhere(): void {
		require_once ABSPATH . 'wp-admin/includes/admin.php';

		// A shortcode of another plugin's, in a section the site wrote, that
		// prints whatever it likes.
		add_shortcode( 'du_hostile', static fn(): string => self::HOSTILE );

		diluxone_users_update_option(
			'diluxone_users_fields',
			array(
				array( 'key' => 'diluxone_users_hostile', 'label' => self::HOSTILE, 'help' => self::HOSTILE, 'placeholder' => self::HOSTILE, 'type' => 'select', 'options' => array( self::HOSTILE ), 'required' => 1 ),
			)
		);
		diluxone_users_update_option(
			'diluxone_users_account_sections',
			array(
				'details' => array( 'label' => self::HOSTILE, 'intro' => self::HOSTILE ),
				'mine'    => array( 'label' => self::HOSTILE, 'custom' => true, 'content' => '<p>[du_hostile]</p>', 'slug' => 'mine' ),
			)
		);
		diluxone_users_update_option( 'diluxone_users_login_title', self::HOSTILE );
		diluxone_users_update_option( 'diluxone_users_login_intro', self::HOSTILE );
		diluxone_users_update_option( 'diluxone_users_register_form', 1 );

		$person = $this->make_user( 'subscriber' );
		update_user_meta( $person, 'first_name', self::HOSTILE );
		update_user_meta( $person, 'diluxone_users_hostile', self::HOSTILE );
		wp_set_current_user( $person );

		ob_start();
		diluxone_users_account_area();

		foreach ( diluxone_users_sections() as $section ) {
			diluxone_users_account_section( $section, wp_get_current_user() );
		}

		diluxone_users_fields_form();
		$area = (string) ob_get_clean();
		$this->assert_nothing_hostile( $area, 'the account area' );
		$this->assertStringContainsString( '&lt;script&gt;alert(9)', $area, 'it was drawn, as text' );

		wp_set_current_user( 0 );
		$this->assert_nothing_hostile( diluxone_users_shortcode_login() . diluxone_users_shortcode_register(), 'the forms' );

		wp_set_current_user( $this->make_user( 'administrator' ) );

		if ( is_multisite() ) {
			grant_super_admin( get_current_user_id() );
		}

		do_action( 'diluxone_users_register_panels' );
		$this->assert_nothing_hostile( $this->draw_every_screen_html(), 'the screens' );

		if ( is_multisite() ) {
			revoke_super_admin( get_current_user_id() );
		}

		remove_shortcode( 'du_hostile' );
	}

	/** @return array<string, array{0: string, 1: array<string, mixed>}> */
	public static function inputs(): array {
		$h = self::HOSTILE;

		return array(
			'a number' => array( 'diluxone_users_ui_number', array( 'label' => $h, 'name' => 'n', 'value' => $h, 'suffix' => $h, 'help' => $h, 'min' => $h ) ),
			'a range'  => array( 'diluxone_users_ui_range', array( 'label' => $h, 'from' => array( 'name' => 'a', 'value' => $h ), 'to' => array( 'name' => 'b', 'value' => $h ), 'between' => $h, 'help' => $h ) ),
			'a select' => array( 'diluxone_users_ui_select', array( 'label' => $h, 'name' => 's', 'value' => $h, 'options' => array( $h => $h ), 'help' => $h ) ),
			'a colour' => array( 'diluxone_users_ui_color', array( 'label' => $h, 'name' => 'c', 'value' => $h, 'help' => $h ) ),
			'a text'   => array( 'diluxone_users_ui_text', array( 'label' => $h, 'name' => 't', 'value' => $h, 'placeholder' => $h, 'help' => $h ) ),
		);
	}

	/**
	 * Each input of the design system prints what it is given as text.
	 *
	 * @dataProvider inputs
	 * @param array<string, mixed> $field
	 */
	public function test_each_input_prints_what_it_is_given_as_text( string $helper, array $field ): void {
		ob_start();
		$helper( $field );
		$html = (string) ob_get_clean();

		$this->assert_nothing_hostile( $html, $helper );
		$this->assertStringContainsString( 'alert(9)', $html, 'it was drawn, as text' );
	}

	public function test_a_state_nobody_knows_is_the_neutral_pill_and_its_words_are_text(): void {
		$pill = diluxone_users_state_pill( 'bogus', self::HOSTILE, self::HOSTILE );

		$this->assertStringContainsString( 'diluxone-users-state--unknown', $pill );
		$this->assertStringNotContainsString( 'bogus', $pill );
		$this->assert_nothing_hostile( $pill, 'the pill' );
	}

	/** Every screen and tab of this admin, as one page. */
	private function draw_every_screen_html(): string {
		$callbacks = diluxone_users_screen_callbacks();
		$html      = '';

		foreach ( array_keys( diluxone_users_screens() ) as $slug ) {
			if ( ! diluxone_users_screen_here( $slug, '' ) ) {
				continue;
			}

			$_GET['page'] = $slug;
			$tabs         = array_keys( diluxone_users_panels( $slug ) );

			foreach ( array() === $tabs ? array( '' ) : $tabs as $tab ) {
				$_GET['tab'] = $tab;

				ob_start();
				call_user_func( $callbacks[ $slug ] );
				$html .= (string) ob_get_clean();

				diluxone_users_ui_save_queue();
			}
		}

		$this->assertStringContainsString( 'diluxone-users-admin', $html, 'the screens were drawn' );

		return $html;
	}

	/**
	 * Draws every screen this admin has, every tab of each.
	 *
	 * @return int How many were drawn.
	 */
	private function draw_every_screen( string $context = '' ): int {
		$callbacks = diluxone_users_screen_callbacks();

		if ( 'network' === $context ) {
			$callbacks[ DILUXONE_USERS_MENU ] = 'diluxone_users_screen_network_home';
		}

		$drawn = 0;

		foreach ( array_keys( diluxone_users_screens() ) as $slug ) {
			if ( ! diluxone_users_screen_here( $slug, $context ) ) {
				continue;
			}

			$_GET['page'] = $slug;
			$tabs         = array_keys( diluxone_users_panels( $slug ) );

			// The head of the page, as WordPress prints it before the screen.
			// WordPress's own listeners on that action read the current screen,
			// which a real request has by then; Network Admin set its own above.
			if ( null === get_current_screen() ) {
				set_current_screen( 'diluxone-users_page_' . $slug );
			}

			do_action( 'admin_enqueue_scripts', 'diluxone-users_page_' . $slug );
			ob_start();
			wp_print_styles();
			ob_end_clean();

			foreach ( array() === $tabs ? array( '' ) : $tabs as $tab ) {
				$_GET['tab'] = $tab;

				ob_start();
				call_user_func( $callbacks[ $slug ] );
				ob_end_clean();

				diluxone_users_ui_save_queue();
				++$drawn;
			}
		}

		return $drawn;
	}

	/** Each string caught, filtered again, is the same document. */
	private function assert_nothing_lost(): void {
		remove_filter( 'pre_kses', array( $this, 'catch_kses' ), 10 );

		$this->assertNotEmpty( $this->seen );

		$lost = array();

		foreach ( array_unique( $this->seen ) as $html ) {
			$before = explode( "\n", $this->document( $html ) );
			$after  = explode( "\n", $this->document( wp_kses( $html, diluxone_users_allowed_html() ) ) );

			foreach ( array_diff( $before, $after ) as $line ) {
				$lost[] = substr( trim( $line ), 0, 400 );
			}
		}

		$this->assertSame( array(), array_values( array_unique( $lost ) ), 'wp_kses() took these out' );
	}

	/** The markup as a browser would read it: quoting, entities and stray spaces in an attribute no longer matter. */
	private function document( string $html ): string {
		$dom      = new \DOMDocument();
		$previous = libxml_use_internal_errors( true );
		$dom->loadHTML( '<?xml encoding="utf-8"?><div id="du-root">' . $html . '</div>', LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD );
		libxml_clear_errors();
		libxml_use_internal_errors( $previous );

		foreach ( ( new \DOMXPath( $dom ) )->query( '//*' ) ?: array() as $element ) {
			if ( $element instanceof \DOMElement ) {
				foreach ( iterator_to_array( $element->attributes ?? array() ) as $attribute ) {
					$element->setAttribute( $attribute->name, trim( $attribute->value ) );
				}
			}
		}

		return (string) $dom->saveHTML();
	}
}
