<?php
/**
 * The account area in every shape, its sections, cards and the forms in them.
 *
 * Which sections a person sees (switched off, only some roles, none ticked),
 * which one is open (by address, by identifier, by the rewrite rule's
 * variable, or the first one), where the site's own words go around what the
 * code draws (before, after, instead), its addresses with and without pretty
 * permalinks, the front page's cards, and the area drawn plain, as a cover,
 * with no header, with the menu down the side. Then the forms the sections
 * hold: notifications and the data requests, each refusing somebody signed
 * out and a forged nonce first; and the shortcodes that show the menu and the
 * linked networks on their own.
 */

namespace Tests\Integration;

use Tests\Integration\Support\MockProvider;

class CoverFieldsAccountTest extends IntegrationTestCase {

	/** @var array<int, array{0: string, 1: callable, 2: int}> The hooks a test added. */
	private array $hooks = array();

	protected function setUp(): void {
		parent::setUp();

		require_once ABSPATH . 'wp-admin/includes/user.php';
	}

	protected function tearDown(): void {
		foreach ( $this->hooks as [ $hook, $callback, $priority ] ) {
			remove_filter( $hook, $callback, $priority );
		}

		MockProvider::remove();
		set_query_var( DILUXONE_USERS_ACCOUNT_VAR, '' );

		global $wp_rewrite;
		foreach ( array_keys( (array) $wp_rewrite->extra_rules_top ) as $rule ) {
			if ( false !== strpos( (string) $wp_rewrite->extra_rules_top[ $rule ], DILUXONE_USERS_ACCOUNT_VAR ) ) {
				unset( $wp_rewrite->extra_rules_top[ $rule ] );
			}
		}

		parent::tearDown();
	}

	/** Adds a filter for this test only. */
	private function filter( string $hook, callable $callback, int $priority = 10, int $args = 1 ): void {
		$this->hooks[] = array( $hook, $callback, $priority );
		add_filter( $hook, $callback, $priority, $args );
	}

	/** A published account page, chosen in the settings. */
	private function account_page(): int {
		$id = (int) wp_insert_post(
			array(
				'post_type'    => 'page',
				'post_status'  => 'publish',
				'post_title'   => 'Account',
				'post_name'    => 'cover-fields-account',
				'post_content' => '[diluxone_users_account]',
			)
		);

		diluxone_users_update_option( 'diluxone_users_account_page', $id );

		return $id;
	}

	/** What the admin saved for the sections. */
	private function configure( array $sections ): void {
		diluxone_users_update_option( 'diluxone_users_account_sections', $sections );
	}

	/** A person, signed in. */
	private function signed_in( string $role = 'subscriber' ): int {
		$user = $this->make_user( $role );
		wp_set_current_user( $user );

		return $user;
	}

	/** What the whole area prints. */
	private function area(): string {
		return diluxone_users_shortcode_account();
	}

	/* ── Who sees which section ──────────────────────────────────────── */

	public function test_a_section_for_some_roles_reaches_only_those_and_none_ticked_reaches_nobody(): void {
		$this->signed_in();

		$this->configure( array( 'security' => array( 'visibility' => 'some', 'roles' => array() ) ) );
		$this->assertArrayNotHasKey( 'security', diluxone_users_sections(), 'Nobody ticked' );
		$this->assertArrayHasKey( 'security', diluxone_users_sections( true ), 'Still on the admin’s list' );

		$this->configure( array( 'security' => array( 'visibility' => 'some', 'roles' => array( 'editor' ) ) ) );
		$this->assertArrayNotHasKey( 'security', diluxone_users_sections() );

		$this->signed_in( 'editor' );
		$this->assertArrayHasKey( 'security', diluxone_users_sections() );

		// Saved before the question was asked: roles ticked meant "only those".
		$this->assertSame( 'some', diluxone_users_section_visibility( array( 'roles' => array( 'editor' ) ) ) );
		$this->assertSame( 'all', diluxone_users_section_visibility( array() ) );
	}

	public function test_the_open_section_is_read_from_the_address_the_rule_or_falls_back_to_the_first(): void {
		$this->signed_in();
		$this->configure( array( 'details' => array( 'slug' => 'Mis Datos' ) ) );

		$this->assertSame( 'home', diluxone_users_current_section() );

		$_GET['section'] = 'mis-datos';
		$this->assertSame( 'details', diluxone_users_current_section(), 'By its address' );

		$_GET['section'] = 'security';
		$this->assertSame( 'security', diluxone_users_current_section(), 'By its identifier' );

		$_GET['section'] = 'not-a-section';
		$this->assertSame( 'home', diluxone_users_current_section() );

		set_query_var( DILUXONE_USERS_ACCOUNT_VAR, 'mis-datos' );
		$this->assertSame( 'details', diluxone_users_current_section(), 'What the rewrite rule captured wins' );
	}

	/* ── Addresses ───────────────────────────────────────────────────── */

	public function test_a_sections_address_follows_the_permalinks_and_the_rule_routes_it(): void {
		$this->signed_in();
		$this->configure( array( 'details' => array( 'slug' => 'mis-datos' ) ) );

		$this->assertSame( home_url( '/' ), diluxone_users_account_url( 'details' ), 'No page yet' );

		$page = $this->account_page();
		$base = get_permalink( $page );

		$this->assertSame( $base, diluxone_users_account_url() );
		$this->assertSame( $base, diluxone_users_account_url( 'home' ), 'The first section is the page itself' );

		$this->filter( 'pre_option_permalink_structure', static fn() => '' );
		$this->assertSame( add_query_arg( 'section', 'mis-datos', $base ), diluxone_users_account_url( 'details' ) );
		$this->assertSame( add_query_arg( 'section', 'made-up', $base ), diluxone_users_account_url( 'made-up' ) );

		$this->filter( 'pre_option_permalink_structure', static fn() => '/%postname%/', 20 );
		$this->assertSame( trailingslashit( $base ) . 'mis-datos/', diluxone_users_account_url( 'details' ) );

		diluxone_users_account_rule();

		global $wp_rewrite;
		$this->assertSame(
			'index.php?page_id=' . $page . '&' . DILUXONE_USERS_ACCOUNT_VAR . '=$matches[1]',
			$wp_rewrite->extra_rules_top[ '^' . preg_quote( get_page_uri( $page ), '/' ) . '/([^/]+)/?$' ] ?? ''
		);

		$this->assertContains( DILUXONE_USERS_ACCOUNT_VAR, diluxone_users_account_query_var( array( 'p' ) ) );
	}

	public function test_a_page_with_no_address_yet_gets_no_rule(): void {
		$draft = (int) wp_insert_post(
			array(
				'post_type'   => 'page',
				'post_status' => 'draft',
				'post_title'  => '',
			)
		);
		diluxone_users_update_option( 'diluxone_users_account_page', $draft );

		global $wp_rewrite;
		$before = (array) $wp_rewrite->extra_rules_top;

		diluxone_users_account_rule();

		$this->assertSame( '', (string) get_page_uri( $draft ) );
		$this->assertSame( $before, (array) $wp_rewrite->extra_rules_top );
	}

	/* ── What a section holds ────────────────────────────────────────── */

	public function test_the_sites_own_words_go_before_after_or_instead_of_what_the_code_draws(): void {
		$user    = get_userdata( $this->signed_in() );
		$section = array(
			'render'    => static function (): void {
				echo '<p>CODE</p>';
			},
			'content'   => '<p>OWN</p><script>alert(1)</script>',
			'placement' => 'before',
		);

		$draw = function ( array $section ) use ( $user ): string {
			ob_start();
			diluxone_users_account_section( $section, $user );

			return (string) ob_get_clean();
		};

		$this->assertSame( '<p>OWN</p>alert(1)<p>CODE</p>', $draw( $section ) );

		$section['placement'] = 'after';
		$this->assertSame( '<p>CODE</p><p>OWN</p>alert(1)', $draw( $section ) );

		$section['placement'] = 'replace';
		$this->assertSame( '<p>OWN</p>alert(1)', $draw( $section ) );

		$section['content'] = '';
		$this->assertSame( '<p>CODE</p>', $draw( $section ), 'Nothing to replace it with' );

		// A section of the site's own has no code: just its words.
		$this->assertSame( '<p>Only words</p>', $draw( array( 'render' => '', 'content' => '<p>Only words</p>', 'placement' => 'before' ) ) );
	}

	public function test_a_heading_can_be_taken_away_and_an_intro_goes_under_it(): void {
		$user = get_userdata( $this->signed_in() );

		$html = diluxone_users_account_heading_html( array( 'label' => 'Security', 'intro' => ' Where you <b>are</b> signed in ' ), 'security', $user );
		$this->assertSame( '<h2 class="diluxone-users-account__title">Security</h2><p class="diluxone-users-account__intro">Where you &lt;b&gt;are&lt;/b&gt; signed in</p>', $html );

		$this->filter( 'diluxone_users_account_heading', '__return_empty_string', 20 );
		$this->assertSame( '', diluxone_users_account_heading_html( array( 'label' => 'Security' ), 'security', $user ) );
	}

	/* ── The front page's cards ──────────────────────────────────────── */

	public function test_the_cards_count_the_fields_filled_and_the_networks_linked(): void {
		$user = $this->signed_in();

		$this->assertSame( array(), diluxone_users_summary_details(), 'No fields, no card' );
		$this->assertSame( array(), diluxone_users_summary_accounts(), 'No network, no card' );

		diluxone_users_update_option(
			'diluxone_users_fields',
			array(
				array( 'key' => 'diluxone_test_city', 'label' => 'City', 'type' => 'text' ),
				array( 'key' => 'diluxone_test_bio', 'label' => 'Bio', 'type' => 'text' ),
			)
		);
		update_user_meta( $user, 'diluxone_test_city', 'Rosario' );

		$this->assertSame( '1/2', diluxone_users_summary_details()['value'] );

		MockProvider::install();
		$accounts = diluxone_users_summary_accounts();
		$this->assertSame( '0', $accounts['value'] );
		$this->assertSame( 'networks linked', $accounts['note'] );

		update_user_meta( $user, 'diluxone_users_sso_' . MockProvider::ID, 'sub-1' );
		$this->assertSame( 'network linked', diluxone_users_summary_accounts()['note'] );
	}

	public function test_a_card_from_elsewhere_gets_an_id_and_a_summary_that_answers_nothing_is_skipped(): void {
		$this->signed_in();

		$this->filter(
			'diluxone_users_sections',
			static function ( array $sections ): array {
				$sections['cover_fields_quiet'] = array(
					'label'   => 'Quiet',
					'summary' => '__return_null',
				);

				return $sections;
			}
		);
		$this->filter(
			'diluxone_users_summaries',
			static function ( array $cards ): array {
				$cards[] = array(
					'label' => 'Next Broadcast',
					'value' => 'Hablamos de Tecnología',
					'note'  => '',
					'link'  => home_url( '/live/' ),
					'cta'   => 'Watch',
				);

				return $cards;
			}
		);

		$cards = diluxone_users_summary_cards();

		$this->assertNotContains( 'cover_fields_quiet', array_column( $cards, 'id' ) );
		$this->assertContains( 'next-broadcast', array_column( $cards, 'id' ) );

		ob_start();
		diluxone_users_section_home( wp_get_current_user() );
		$html = (string) ob_get_clean();

		$this->assertStringContainsString( 'diluxone-users-card-summary__value is-text">Hablamos de Tecnología', $html );

		// Every card turned off: the front page says so.
		diluxone_users_update_option( 'diluxone_users_home_cards_off', array_column( $cards, 'id' ) );

		ob_start();
		diluxone_users_section_home( wp_get_current_user() );
		$this->assertStringContainsString( 'Nothing to show yet.', (string) ob_get_clean() );
	}

	/* ── The area ────────────────────────────────────────────────────── */

	public function test_the_area_asks_a_visitor_to_sign_in_and_draws_nothing_with_no_section_left(): void {
		$this->assertStringContainsString( esc_url( diluxone_users_login_url() ), $this->area() );
		$this->assertSame( '', diluxone_users_shortcode_account_nav(), 'No menu for a visitor' );

		$this->signed_in();
		$off = array();
		foreach ( array_keys( diluxone_users_sections( true ) ) as $id ) {
			$off[ $id ] = array( 'enabled' => 0 );
		}
		$this->configure( $off );

		$this->assertSame( '', $this->area() );
		$this->assertSame( '', diluxone_users_account_nav(), 'No sections, no menu' );
	}

	public function test_the_plain_area_with_tabs_draws_the_header_the_menu_and_the_open_section(): void {
		$user = $this->signed_in();
		wp_update_user(
			array(
				'ID'         => $user,
				'first_name' => 'Ana',
				'last_name'  => 'López',
			)
		);
		diluxone_users_update_option( 'diluxone_users_account_action', 1 );
		diluxone_users_update_option( 'diluxone_users_account_nav_small', 'wrap' );

		$html = $this->area();

		$this->assertStringContainsString( 'diluxone-users-account--tabs diluxone-users-account--plain diluxone-users-account--contained diluxone-users-account--nav-wrap', $html );
		$this->assertStringContainsString( '<h1 class="diluxone-users-account__name">Ana López</h1>', $html );
		$this->assertStringContainsString( 'Member since', $html );
		$this->assertStringContainsString( 'diluxone-users-account__action', $html );
		$this->assertStringContainsString( 'Edit profile', $html );
		$this->assertStringContainsString( 'diluxone-users-account__bar', $html );
		$this->assertStringContainsString( 'Hello, Ana', $html );
		$this->assertStringNotContainsString( 'style="', substr( $html, 0, (int) strpos( $html, '>' ) ), 'A plain area carries no cover' );

		$this->assertStringContainsString( 'diluxone-users-account__nav', diluxone_users_shortcode_account_nav() );
	}

	public function test_the_cover_without_a_header_and_with_the_menu_down_the_side(): void {
		$this->signed_in();
		$picture = (int) wp_insert_attachment(
			array(
				'post_title'     => 'band',
				'post_mime_type' => 'image/jpeg',
				'post_status'    => 'inherit',
			),
			'2026/10/band.jpg'
		);

		diluxone_users_update_option( 'diluxone_users_account_template', 'cover' );
		diluxone_users_update_option( 'diluxone_users_account_layout', 'side' );
		diluxone_users_update_option( 'diluxone_users_account_header', 0 );
		diluxone_users_update_option( 'diluxone_users_account_width', 'full' );
		diluxone_users_update_option( 'diluxone_users_account_cover', '#123abc' );
		diluxone_users_update_option( 'diluxone_users_account_cover_kind', 'dim' );

		// A picture asked for and none chosen is the colour.
		$this->assertSame( 'color', diluxone_users_account_cover_kind() );

		diluxone_users_update_option( 'diluxone_users_account_cover_image', $picture );
		$this->assertSame( 'dim', diluxone_users_account_cover_kind() );

		$html = $this->area();

		$this->assertStringContainsString( 'diluxone-users-account--side diluxone-users-account--cover diluxone-users-account--full', $html );
		$this->assertStringContainsString( 'diluxone-users-account--bare', $html );
		$this->assertStringContainsString( 'diluxone-users-account--cover-dim', $html );
		$this->assertStringContainsString( '--diluxone-users-cover:#123abc;', $html );
		$this->assertStringContainsString( '--diluxone-users-cover-image:url(', $html );
		$this->assertStringContainsString( '2026/10/band.jpg', $html );
		$this->assertStringNotContainsString( 'diluxone-users-account__header', $html );
		$this->assertStringNotContainsString( 'diluxone-users-account__bar', $html );
		$this->assertStringContainsString( 'diluxone-users-account__nav--column', $html, 'The menu sits in the body, down the side' );

		diluxone_users_update_option( 'diluxone_users_account_cover_kind', 'image' );
		$this->assertStringContainsString( 'diluxone-users-account--cover-image', $this->area() );
	}

	/* ── Notifications ───────────────────────────────────────────────── */

	public function test_the_notifications_form_is_for_a_person_and_saves_only_what_it_shows(): void {
		$this->assertSame( '', diluxone_users_shortcode_notifications() );

		$this->postAs( 0, array() );
		$this->assertSame( diluxone_users_login_url(), $this->expectRedirect( 'diluxone_users_notifications_save' ) );

		$user = $this->signed_in();
		$this->assertStringContainsString( 'diluxone_users_notify_login', diluxone_users_shortcode_notifications() );

		$this->postAs( $user, array( '_wpnonce' => 'forged', 'diluxone_users_notify_login' => '1' ) );
		$this->expectDie( 'diluxone_users_notifications_save', self::EXPIRED, 403 );
		$this->assertSame( '', get_user_meta( $user, 'diluxone_users_notify_login', true ) );

		$this->postAs(
			$user,
			array(
				'_wpnonce'                    => wp_create_nonce( 'diluxone_users_notifications' ),
				'diluxone_users_notify_login' => '1',
				'diluxone_users_notify_other' => '1',
			)
		);
		$url = $this->expectRedirect( 'diluxone_users_notifications_save' );

		$this->assertSame( 'saved', $this->redirectState( $url ) );
		$this->assertSame( '1', get_user_meta( $user, 'diluxone_users_notify_login', true ) );
		$this->assertSame( '0', get_user_meta( $user, 'diluxone_users_notify_security', true ), 'Unticked is a choice' );
		$this->assertSame( '', get_user_meta( $user, 'diluxone_users_notify_other', true ), 'Nothing that was not on the form' );
	}

	/* ── Your data ───────────────────────────────────────────────────── */

	public function test_a_data_request_needs_a_session_and_its_nonce(): void {
		$this->postAs( 0, array( 'diluxone_users_request' => 'export' ) );
		$this->assertSame( diluxone_users_login_url(), $this->expectRedirect( 'diluxone_users_data_request' ) );

		$user = $this->make_user();
		$this->postAs( $user, array( '_wpnonce' => 'forged', 'diluxone_users_request' => 'export' ) );
		$this->expectDie( 'diluxone_users_data_request', self::EXPIRED, 403 );
		$this->assertSame( array(), diluxone_users_data_requests( get_userdata( $user )->user_email ) );
	}

	public function test_a_data_request_switched_off_or_for_an_administrator_is_refused(): void {
		$user = $this->signed_in();
		$post = array( '_wpnonce' => wp_create_nonce( 'diluxone_users_data_request' ) );

		diluxone_users_update_option( 'diluxone_users_privacy_export', 0 );
		$this->postAs( $user, $post + array( 'diluxone_users_request' => 'export' ) );
		$this->assertSame( 'error', $this->redirectState( $this->expectRedirect( 'diluxone_users_data_request' ) ) );
		$this->assertSame( array(), diluxone_users_data_requests( get_userdata( $user )->user_email ) );

		$admin = $this->signed_in( 'administrator' );
		$this->postAs( $admin, array( '_wpnonce' => wp_create_nonce( 'diluxone_users_data_request' ), 'diluxone_users_request' => 'erase' ) );
		$this->assertSame( 'admin', $this->redirectState( $this->expectRedirect( 'diluxone_users_data_request' ) ) );
		$this->assertSame( array(), diluxone_users_data_requests( get_userdata( $admin )->user_email ) );
	}

	/** Erasure switched off: a hand-made request for it is refused, and nothing is filed. */
	public function test_an_erasure_switched_off_is_refused(): void {
		$user = $this->signed_in();
		diluxone_users_update_option( 'diluxone_users_privacy_delete', 0 );

		$this->postAs( $user, array( '_wpnonce' => wp_create_nonce( 'diluxone_users_data_request' ), 'diluxone_users_request' => 'erase' ) );

		$this->assertSame( 'error', $this->redirectState( $this->expectRedirect( 'diluxone_users_data_request' ) ) );
		$this->assertSame( array(), diluxone_users_data_requests( get_userdata( $user )->user_email ) );
		$this->assertSame( array(), self::$mail );
	}

	/**
	 * "Your data" turned off in Sections, or kept from this person's role,
	 * is a section they do not have: the request its form would have sent is
	 * refused too, although both requests are still allowed on the site.
	 */
	public function test_a_data_request_without_the_section_is_refused(): void {
		$user  = $this->signed_in();
		$email = get_userdata( $user )->user_email;
		$post  = array( '_wpnonce' => wp_create_nonce( 'diluxone_users_data_request' ), 'diluxone_users_request' => 'export' );

		diluxone_users_update_option( 'diluxone_users_account_sections', array( 'privacy' => array( 'enabled' => 0 ) ) );
		$this->postAs( $user, $post );
		$this->assertSame( 'error', $this->redirectState( $this->expectRedirect( 'diluxone_users_data_request' ) ), 'turned off' );

		diluxone_users_update_option( 'diluxone_users_account_sections', array( 'privacy' => array( 'visibility' => 'some', 'roles' => array( 'editor' ) ) ) );
		$this->postAs( $user, $post );
		$this->assertSame( 'error', $this->redirectState( $this->expectRedirect( 'diluxone_users_data_request' ) ), 'not for this role' );

		$this->assertSame( array(), diluxone_users_data_requests( $email ) );
		$this->assertSame( array(), self::$mail );

		// Back on, the same request goes through.
		diluxone_users_delete_option( 'diluxone_users_account_sections' );
		$this->postAs( $user, $post );
		$this->assertSame( 'requested', $this->redirectState( $this->expectRedirect( 'diluxone_users_data_request' ) ) );
	}

	public function test_a_data_request_is_filed_marked_as_the_accounts_and_mailed_once(): void {
		$user  = $this->signed_in();
		$email = get_userdata( $user )->user_email;
		$post  = array(
			'_wpnonce'               => wp_create_nonce( 'diluxone_users_data_request' ),
			'diluxone_users_request' => 'erase',
		);

		$this->postAs( $user, $post );
		$this->assertSame( 'requested', $this->redirectState( $this->expectRedirect( 'diluxone_users_data_request' ) ) );

		$requests = diluxone_users_data_requests( $email, 'remove_personal_data' );
		$this->assertCount( 1, $requests );
		$this->assertSame( $user, (int) ( wp_get_user_request( $requests[0]->ID )->request_data[ DILUXONE_USERS_CLOSE_KEY ] ?? 0 ) );
		$this->assertSame( $email, $this->lastMail()['to'] );

		// The same again while the first is waiting: WordPress refuses it.
		$this->postAs( $user, $post );
		$this->assertSame( 'error', $this->redirectState( $this->expectRedirect( 'diluxone_users_data_request' ) ) );
	}

	public function test_arriving_from_the_e_mail_to_close_the_account_asks_on_the_privacy_tab(): void {
		$user    = $this->signed_in();
		$request = wp_create_user_request( get_userdata( $user )->user_email, 'remove_personal_data', array( DILUXONE_USERS_CLOSE_KEY => $user ) );
		$key     = wp_generate_user_request_key( $request );

		$_GET = array(
			'diluxone-users-request' => $request,
			'diluxone-users-key'     => $key,
		);

		ob_start();
		diluxone_users_section_privacy( get_userdata( $user ) );
		$html = (string) ob_get_clean();

		$this->assertStringContainsString( 'Delete your account for good?', $html );
		$this->assertStringContainsString( 'name="diluxone_users_request_id" value="' . $request . '"', $html );
	}

	/* ── The linked networks on their own ────────────────────────────── */

	public function test_the_networks_shortcode_splits_linked_from_available(): void {
		$this->assertSame( '', diluxone_users_shortcode_accounts( array() ), 'Signed out' );

		MockProvider::install();
		$user = $this->signed_in();

		$this->assertStringNotContainsString( '<strong>Mock</strong>', diluxone_users_shortcode_accounts( array( 'only' => 'linked' ) ) );
		$this->assertStringContainsString( '<strong>Mock</strong>', diluxone_users_shortcode_accounts( array( 'only' => 'available' ) ) );
		$this->assertStringContainsString( '<strong>Mock</strong>', diluxone_users_shortcode_accounts( '' ) );

		update_user_meta( $user, 'diluxone_users_sso_' . MockProvider::ID, 'sub-1' );

		$this->assertStringContainsString( '<strong>Mock</strong>', diluxone_users_shortcode_accounts( array( 'only' => 'linked' ) ) );
		$this->assertStringNotContainsString( '<strong>Mock</strong>', diluxone_users_shortcode_accounts( array( 'only' => 'available' ) ) );

		ob_start();
		diluxone_users_section_accounts( get_userdata( $user ) );
		$this->assertStringContainsString( 'Linked to your account', (string) ob_get_clean() );
	}

	public function test_the_sessions_shortcode_is_for_a_person_only(): void {
		$this->assertSame( '', diluxone_users_shortcode_sessions( array() ) );

		$this->signed_in();
		$this->assertStringContainsString( 'diluxone-users', diluxone_users_shortcode_sessions( '' ) );
	}

	/* ── What no other test asked ─────────────────────────────────── */

	/** A section switched off cannot be opened by its address. */
	public function test_a_section_switched_off_cannot_be_opened_by_its_address(): void {
		$this->signed_in();
		$_GET = array( 'section' => 'security' );
		$this->assertSame( 'security', diluxone_users_current_section(), 'the control: on, it opens' );

		diluxone_users_update_option( 'diluxone_users_account_sections', array( 'security' => array( 'enabled' => 0 ) ) );

		$this->assertSame( diluxone_users_default_section(), diluxone_users_current_section() );
		$this->assertArrayNotHasKey( 'security', diluxone_users_sections() );
	}

	/** A section of the site's own that was deleted is gone at once, not at the next request. */
	public function test_a_deleted_section_of_the_sites_own_is_gone_in_the_same_request(): void {
		diluxone_users_update_option(
			'diluxone_users_account_sections',
			array(
				'gone-soon' => array(
					'label'  => 'Gone soon',
					'custom' => true,
				),
			)
		);
		$this->assertArrayHasKey( 'gone-soon', diluxone_users_sections( true ) );

		diluxone_users_update_option( 'diluxone_users_account_sections', array() );

		$this->assertArrayNotHasKey( 'gone-soon', diluxone_users_sections( true ) );
	}

	/** What the site writes in a section of its own keeps its links and loses its scripts and handlers. */
	public function test_a_sections_own_words_keep_links_and_lose_scripts(): void {
		$this->signed_in();
		diluxone_users_update_option(
			'diluxone_users_account_sections',
			array(
				'mine' => array(
					'label'   => 'Mine',
					'custom'  => true,
					'slug'    => 'mine',
					'content' => '<p>x</p><script>alert(1)</script><a onclick="x" href="javascript:y">l</a><a href="https://example.test/">ok</a>',
				),
			)
		);

		ob_start();
		diluxone_users_account_section_own( diluxone_users_sections()['mine'] );
		$html = (string) ob_get_clean();

		$this->assertStringContainsString( '<p>x</p>', $html );
		$this->assertStringContainsString( 'href="https://example.test/"', $html );
		$this->assertStringNotContainsString( '<script', $html );
		$this->assertStringNotContainsString( 'onclick', $html );
		$this->assertStringNotContainsString( 'javascript:', $html );
	}

	/** The notification choices a site adds through its filter are saved; one it takes out is not. */
	public function test_the_notification_choices_follow_their_filter(): void {
		$user = $this->signed_in();
		$this->hook(
			'diluxone_users_notification_prefs',
			static function ( array $prefs ): array {
				unset( $prefs['diluxone_users_notify_login'] );
				$prefs['acme_notify_x'] = array( 'label' => 'Acme', 'default' => '0' );

				return $prefs;
			}
		);

		$this->postAs(
			$user,
			array(
				'_wpnonce'                    => wp_create_nonce( 'diluxone_users_notifications' ),
				'acme_notify_x'               => '1',
				'diluxone_users_notify_login' => '1',
			)
		);
		$this->expectRedirect( 'diluxone_users_notifications_save' );

		$this->assertSame( '1', get_user_meta( $user, 'acme_notify_x', true ) );
		$this->assertSame( '', get_user_meta( $user, 'diluxone_users_notify_login', true ), 'taken out: nothing written' );
	}

	/** With the second step off, its notice is not one of the ones that go out whatever anybody chose. */
	public function test_with_the_second_step_off_its_notice_is_not_a_must(): void {
		diluxone_users_update_option( 'diluxone_users_2fa_mode', 'off' );
		diluxone_users_update_option( 'diluxone_users_2fa_methods', array( 'email' ) );

		$this->assertArrayNotHasKey( 'diluxone_users_notify_2fa', diluxone_users_notification_musts() );
	}

	/** The initials picture, when on, is drawn here and carries the person's initials. */
	public function test_the_initials_picture_carries_the_initials(): void {
		$user = $this->make_user();
		update_user_meta( $user, 'first_name', 'Ada' );
		update_user_meta( $user, 'last_name', 'Lovelace' );
		diluxone_users_update_option( 'diluxone_users_avatar_gravatar', 0 );
		diluxone_users_update_option( 'diluxone_users_avatar_initials', 1 );

		$url = (string) get_avatar_url( $user );

		$this->assertStringStartsWith( 'data:image/svg+xml', $url );
		$this->assertStringContainsString( 'AL', rawurldecode( (string) preg_replace( '/^data:image\/svg\+xml[^,]*,/', '', $url ) ) . base64_decode( (string) preg_replace( '/^data:image\/svg\+xml;base64,/', '', $url ) ) );
		$this->assertStringNotContainsString( 'gravatar.com', $url );
	}
}
