<?php
/**
 * The account-area screen: its saves, its one-click actions and every tab.
 *
 * The sections tab has three forms of its own — the order, one section's
 * detail, and the two switches of "Your data" — each with its own nonce,
 * posted to admin_init, and a set of links that turn a section on, off or
 * delete it. Each one checks its nonce and the capability before it reads
 * anything; on a network the sections are the hub's, so Network Admin has
 * nothing to save there.
 *
 * Every case runs on both topologies unless it says otherwise.
 */

namespace Tests\Integration;

use Tests\Integration\Support\CoverSettingsSupport;

class CoverSettingsAccountTest extends IntegrationTestCase {

	use CoverSettingsSupport;

	/** @var mixed The registry of sections before the test: a section the test made is registered in it. */
	private $sections_before;

	/** @var mixed The stored rewrite version before the test: the page saves delete it. */
	private $rewrite_before;

	/** @var string The permalink structure before the test. */
	private string $permalinks_before = '';

	/** @var array<int, int> Menus made by the test. */
	private array $menus = array();

	/** @var mixed The theme's menu locations before the test. */
	private $locations_before;

	protected function setUp(): void {
		parent::setUp();

		$this->sections_before   = $GLOBALS['diluxone_users_sections'] ?? null;
		$this->rewrite_before    = diluxone_users_raw_get( 'diluxone_users_rewrite_version' );
		$this->permalinks_before = (string) get_option( 'permalink_structure' );
		$this->locations_before  = get_theme_mod( 'nav_menu_locations' );

		do_action( 'diluxone_users_register_panels' );
	}

	protected function tearDown(): void {
		$this->cover_settings_reset();

		$GLOBALS['diluxone_users_sections'] = $this->sections_before;

		if ( false === $this->rewrite_before ) {
			diluxone_users_delete_option( 'diluxone_users_rewrite_version' );
		} else {
			diluxone_users_update_option( 'diluxone_users_rewrite_version', $this->rewrite_before );
		}

		update_option( 'permalink_structure', $this->permalinks_before );

		foreach ( $this->menus as $menu ) {
			wp_delete_nav_menu( $menu );
		}

		$this->menus = array();

		if ( false === $this->locations_before ) {
			remove_theme_mod( 'nav_menu_locations' );
		} else {
			set_theme_mod( 'nav_menu_locations', $this->locations_before );
		}

		unregister_nav_menu( 'cover-settings-primary' );

		parent::tearDown();
	}

	/** A published page, deleted with the rest of the test's posts. */
	private function page( string $title ): int {
		return (int) wp_insert_post(
			array(
				'post_type'   => 'page',
				'post_status' => 'publish',
				'post_title'  => $title,
			)
		);
	}

	/** One section's stored configuration. */
	private function config( string $id ): array {
		return (array) ( ( (array) diluxone_users_option( 'diluxone_users_account_sections' ) )[ $id ] ?? array() );
	}

	/**
	 * Sends one of the sections tab's own forms.
	 *
	 * @param array<string, mixed> $post
	 * @param array<string, mixed> $get
	 */
	private function send_sections_form( string $field, string $action, array $post, array $get = array(), string $nonce = '' ): void {
		$this->postAs(
			get_current_user_id(),
			$post + array( $field => '' === $nonce ? wp_create_nonce( $action ) : $nonce ),
			array( 'page' => 'diluxone-users-account' ) + $get
		);
	}

	/**
	 * Presses one of the links that turn a section on, off or delete it.
	 *
	 * @return array{0: string, 1: mixed}
	 */
	private function press( string $section, string $action, string $nonce = '', string $page = 'diluxone-users-account' ): array {
		$_GET     = array(
			'page'                  => $page,
			'section'               => $section,
			'diluxone_users_action' => $action,
		);
		$_REQUEST = $_GET + array( '_wpnonce' => '' === $nonce ? wp_create_nonce( 'diluxone_users_section_action' ) : $nonce );

		return $this->ended( 'diluxone_users_account_actions' );
	}

	/* ── One section, saved from its detail ────────────────────────── */

	/**
	 * A section of the site's own, as an add-on (Users+ Pro) makes one: stored
	 * with `custom`, and registered from what is stored.
	 *
	 * @param array<string, mixed> $config What is stored for it.
	 */
	private function addon_section( string $id, array $config = array() ): void {
		$all        = (array) diluxone_users_option( 'diluxone_users_account_sections' );
		$all[ $id ] = $config + array(
			'label'  => ucfirst( $id ),
			'slug'   => $id,
			'custom' => true,
		);
		diluxone_users_update_option( 'diluxone_users_account_sections', $all );

		$this->hook(
			'diluxone_users_register_sections',
			static function () use ( $id ): void {
				$config = diluxone_users_section_config( $id );

				if ( array() !== $config ) {
					diluxone_users_register_section(
						$id,
						array(
							'label'   => (string) $config['label'],
							'custom'  => true,
							'content' => (string) ( $config['content'] ?? '' ),
						)
					);
				}
			},
			20,
			0
		);
	}

	/* ── One section, saved from its detail ────────────────────────── */

	public function test_an_add_ons_section_is_saved_cleaned_and_opened(): void {
		$this->the_admin();
		$this->addon_section(
			'my-courses',
			array(
				'enabled'  => 1,
				'position' => 900,
			)
		);

		$this->send_sections_form(
			'diluxone_users_section_form_nonce',
			'diluxone_users_section_form',
			array(
				'diluxone_users_section_form' => array(
					'id'         => 'my-courses',
					'label'      => 'My <b>courses</b>',
					'slug'       => '',
					'intro'      => 'What I am <em>taking</em>',
					'content'    => '<p>Hello</p><script>alert(1)</script>',
					'placement'  => 'nowhere',
					'visibility' => 'some',
					'roles'      => array( 'Subscriber', '' ),
				),
			)
		);

		list( $how, $to ) = $this->ended( 'diluxone_users_account_post' );

		$this->assertSame( 'redirect', $how );
		$this->assertSame( 'saved', $this->queryArg( $to, 'diluxone_users_msg' ) );
		$this->assertSame( 'sections', $this->queryArg( $to, 'tab' ) );
		$this->assertSame( 'my-courses', $this->queryArg( $to, 'section' ), 'it opens the section just saved' );

		$config = $this->config( 'my-courses' );

		$this->assertSame( 'My courses', $config['label'], 'tags out of the name' );
		$this->assertSame( 'my-courses', $config['slug'], 'the address made from the name' );
		$this->assertSame( 'What I am taking', $config['intro'] );
		$this->assertStringContainsString( '<p>Hello</p>', $config['content'] );
		$this->assertStringNotContainsString( '<script', $config['content'] );
		$this->assertSame( 'after', $config['placement'], 'a placement that is not one is the usual one' );
		$this->assertSame( array( 'subscriber' ), $config['roles'] );
		$this->assertSame( 'some', $config['visibility'] );
		$this->assertTrue( $config['custom'], 'still the add-on\'s' );
		$this->assertSame( 900, $config['position'], 'editing does not move it' );
	}

	/** The free plugin edits a section there is, and makes none. */
	public function test_a_section_without_a_name_on_a_taken_address_or_that_nothing_registers_is_refused(): void {
		$this->the_admin();

		foreach ( array(
			'no name'                       => array(
				'id'    => 'details',
				'label' => '   ',
				'slug'  => 'whatever',
			),
			'the address of a code section' => array(
				'id'    => 'details',
				'label' => 'Security',
				'slug'  => 'security',
			),
			'no section at all'             => array(
				'id'    => '',
				'label' => 'Mine',
				'slug'  => '',
			),
			'a section nothing registers'   => array(
				'id'    => 'whatever',
				'label' => 'Whatever',
				'slug'  => '',
			),
		) as $case => $form ) {
			$this->send_sections_form( 'diluxone_users_section_form_nonce', 'diluxone_users_section_form', array( 'diluxone_users_section_form' => $form ), array( 'section' => 'details' ) );

			list( $how, $to ) = $this->ended( 'diluxone_users_account_post' );

			$this->assertSame( 'redirect', $how, $case );
			$this->assertSame( 'error', $this->queryArg( $to, 'diluxone_users_msg' ), $case );
			$this->assertSame( 'details', $this->queryArg( $to, 'section' ), $case . ': back to the form it came from' );
		}

		$this->assertArrayNotHasKey( 'slug', $this->config( 'details' ), 'the code section was not moved' );
		$this->assertSame( array(), $this->config( 'whatever' ) );
		$this->assertSame( array(), $this->config( 'mine' ) );
	}

	/**
	 * Editing a section cannot move it onto another's address, nor onto the
	 * address of a section kept from an add-on that is gone: it comes back on
	 * that address.
	 */
	public function test_a_section_cannot_be_moved_onto_another_sections_address(): void {
		$this->the_admin();
		$this->addon_section( 'mine' );

		$this->assertSame( '', diluxone_users_section_save( array( 'id' => 'mine', 'label' => 'Mine', 'slug' => 'security' ) ) );
		$this->assertSame( 'mine', $this->config( 'mine' )['slug'], 'its own address kept' );

		$this->assertSame( '', diluxone_users_section_save( array( 'id' => 'details', 'label' => 'About you', 'slug' => 'mine' ) ) );
		$this->assertArrayNotHasKey( 'slug', $this->config( 'details' ) );

		// Its own address, again, is its own.
		$this->assertSame( 'mine', diluxone_users_section_save( array( 'id' => 'mine', 'label' => 'Still mine', 'slug' => 'mine' ) ) );

		diluxone_users_section_config_save(
			'kept',
			array(
				'label'  => 'Kept',
				'slug'   => 'kept-here',
				'custom' => true,
			)
		);

		$this->assertFalse( diluxone_users_section_slug_free( 'kept-here' ), 'a kept section holds its address' );
		$this->assertSame( '', diluxone_users_section_save( array( 'id' => 'details', 'label' => 'About you', 'slug' => 'kept-here' ) ) );
		$this->assertTrue( diluxone_users_section_slug_free( 'mine', 'mine' ), 'a section asking for its own address' );
		$this->assertFalse( diluxone_users_section_slug_free( '' ), 'no address is not a free one' );
	}

	/** A section's name sent as a list is no name: nothing is saved, and no section is called "Array". */
	public function test_a_sections_name_sent_as_a_list_saves_nothing(): void {
		$this->the_admin();

		$this->assertSame( '', diluxone_users_section_save( array( 'id' => 'details', 'label' => array( 'x' ), 'slug' => 'lists' ) ) );
		$this->assertSame( 'details', diluxone_users_section_save( array( 'id' => 'details', 'label' => 'Listed', 'slug' => 'listed', 'intro' => array( 'x' ) ) ) );
		$this->assertSame( '', $this->config( 'details' )['intro'] );
	}

	/** On and off for a section there is not write nothing down. */
	public function test_a_section_that_does_not_exist_cannot_be_switched(): void {
		$this->the_admin();

		$this->press( 'ghost', 'on' );
		$this->press( 'ghost', 'off' );

		$this->assertArrayNotHasKey( 'ghost', (array) diluxone_users_option( 'diluxone_users_account_sections' ) );
	}

	public function test_a_section_from_code_is_edited_and_stays_from_code(): void {
		$this->the_admin();

		$this->assertSame(
			'details',
			diluxone_users_section_save(
				array(
					'id'        => 'details',
					'label'     => 'About you',
					'slug'      => 'about-you',
					'placement' => 'before',
				)
			)
		);

		$config   = $this->config( 'details' );
		$sections = diluxone_users_sections( true );

		$this->assertArrayNotHasKey( 'custom', $config );
		$this->assertArrayNotHasKey( 'position', $config, 'editing does not move it' );
		$this->assertSame( 'before', $config['placement'] );
		$this->assertSame( 'all', $config['visibility'] );
		$this->assertSame( 'About you', $sections['details']['label'] );
	}

	public function test_the_cards_of_the_front_page_are_saved_only_from_its_own_form(): void {
		$this->the_admin();

		$cards = array_map( static fn( array $card ): string => (string) $card['id'], diluxone_users_summary_cards() );
		$this->assertContains( 'details', $cards, 'the plugin offers a card for the details' );

		// Another section's form says nothing about the cards.
		diluxone_users_update_option( 'diluxone_users_home_cards_off', array( 'security' ) );
		diluxone_users_section_save(
			array(
				'id'    => 'details',
				'label' => 'Details',
				'cards' => array(),
			)
		);
		$this->assertSame( array( 'security' ), diluxone_users_summaries_hidden() );

		// The front page's form without its marker does not either.
		diluxone_users_section_save(
			array(
				'id'    => 'home',
				'label' => 'Home',
			)
		);
		$this->assertSame( array( 'security' ), diluxone_users_summaries_hidden() );

		// With it, what is not ticked is what is off.
		diluxone_users_section_save(
			array(
				'id'          => 'home',
				'label'       => 'Home',
				'cards_shown' => '1',
				'cards'       => array( 'details' ),
			)
		);

		$off = diluxone_users_summaries_hidden();

		$this->assertNotContains( 'details', $off );
		$this->assertSame( array_values( array_diff( $cards, array( 'details' ) ) ), $off );
	}

	/* ── The order ─────────────────────────────────────────────────── */

	public function test_the_order_is_rewritten_in_steps_of_ten_and_ignores_what_is_not_a_section(): void {
		$this->the_admin();

		$this->send_sections_form(
			'diluxone_users_order_form_nonce',
			'diluxone_users_order_form',
			array( 'diluxone_users_order_form' => array( 'security', 'no-such-section', 'Details', 'home' ) ),
			array( 'section' => 'security' )
		);

		list( $how, $to ) = $this->ended( 'diluxone_users_account_post' );

		$this->assertSame( 'redirect', $how );
		$this->assertSame( 'order', $this->queryArg( $to, 'diluxone_users_msg' ) );
		$this->assertSame( 'security', $this->queryArg( $to, 'section' ) );

		$this->assertSame( 10, $this->config( 'security' )['position'] );
		$this->assertSame( 20, $this->config( 'details' )['position'], 'cleaned before it is looked up' );
		$this->assertSame( 30, $this->config( 'home' )['position'] );
		$this->assertSame( array(), $this->config( 'no-such-section' ) );
		$this->assertSame( array( 'security', 'details', 'home' ), array_slice( array_keys( diluxone_users_sections( true ) ), 0, 3 ) );
	}

	/* ── The "Your data" switches ──────────────────────────────────── */

	public function test_the_privacy_form_saves_each_answer_and_goes_back_to_its_section(): void {
		$this->the_admin();

		$this->postAs(
			get_current_user_id(),
			array(
				'diluxone_users_privacy_nonce'       => wp_create_nonce( 'diluxone_users_privacy' ),
				'diluxone_users_privacy_export'      => '1',
				'diluxone_users_privacy_export_when' => 'admin',
				'diluxone_users_privacy_export_link' => 'direct',
				'diluxone_users_privacy_export_file' => 'link',
				'diluxone_users_privacy_delete_when' => 'whenever',
				'diluxone_users_privacy_delete_link' => '<b>direct</b>',
			),
			array( 'page' => 'diluxone-users-account' )
		);

		list( $how, $to ) = $this->ended( 'diluxone_users_account_post' );

		$this->assertSame( 'redirect', $how );
		$this->assertSame( 'privacy', $this->queryArg( $to, 'diluxone_users_msg' ) );
		$this->assertSame( 'privacy', $this->queryArg( $to, 'section' ) );

		$this->assertSame( 1, (int) diluxone_users_option( 'diluxone_users_privacy_export' ) );
		$this->assertSame( 0, (int) diluxone_users_option( 'diluxone_users_privacy_delete' ), 'unticked is off' );
		$this->assertSame( 'admin', diluxone_users_option( 'diluxone_users_privacy_export_when' ) );
		$this->assertSame( 'direct', diluxone_users_option( 'diluxone_users_privacy_export_link' ) );
		$this->assertSame( 'link', diluxone_users_option( 'diluxone_users_privacy_export_file' ) );
		$this->assertSame( 'confirm', diluxone_users_option( 'diluxone_users_privacy_delete_when' ), 'anything else is the usual answer' );
		$this->assertSame( 'account', diluxone_users_option( 'diluxone_users_privacy_delete_link' ), 'a tag around the answer is not the answer' );
	}

	public function test_a_post_with_none_of_the_three_forms_does_nothing(): void {
		$this->the_admin();
		$this->postAs( get_current_user_id(), array( 'something' => 'else' ), array( 'page' => 'diluxone-users-account' ) );

		$this->assertSame( array( 'returned', null ), $this->ended( 'diluxone_users_account_post' ) );
	}

	/**
	 * Each of the three forms, with a nonce that is not its own and from
	 * somebody who may not change the screen.
	 *
	 * @return array<string, array{0: string, 1: string, 2: array<string, mixed>}>
	 */
	public static function forms(): array {
		return array(
			'the order'     => array( 'diluxone_users_order_form_nonce', 'diluxone_users_order_form', array( 'diluxone_users_order_form' => array( 'security', 'home' ) ) ),
			'one section'   => array(
				'diluxone_users_section_form_nonce',
				'diluxone_users_section_form',
				array(
					'diluxone_users_section_form' => array(
						'id'    => 'details',
						'label' => 'Sneaky',
						'slug'  => 'sneaky',
					),
				),
			),
			'the switches'  => array( 'diluxone_users_privacy_nonce', 'diluxone_users_privacy', array() ),
		);
	}

	/**
	 * @dataProvider forms
	 *
	 * @param array<string, mixed> $post
	 */
	public function test_each_form_stops_on_a_bad_nonce_and_writes_nothing( string $field, string $action, array $post ): void {
		$this->the_admin();

		foreach ( array( 'not-a-nonce', wp_create_nonce( 'diluxone_users_panel_diluxone-users-account' ) ) as $nonce ) {
			$this->send_sections_form( $field, $action, $post, array(), $nonce );

			$this->assertSame( array( 'died', self::EXPIRED ), $this->ended( 'diluxone_users_account_post' ), $nonce );
		}

		$this->assertSame( array(), (array) diluxone_users_option( 'diluxone_users_account_sections' ) );
		$this->assertSame( 1, (int) diluxone_users_option( 'diluxone_users_privacy_export' ) );
	}

	/**
	 * @dataProvider forms
	 *
	 * @param array<string, mixed> $post
	 */
	public function test_each_form_refuses_somebody_without_the_capability( string $field, string $action, array $post ): void {
		$this->not_allowed();
		$this->send_sections_form( $field, $action, $post );

		list( $how, $why ) = $this->ended( 'diluxone_users_account_post' );

		$this->assertSame( 'died', $how );
		$this->assertSame( 'You are not allowed to do this.', $why );
		$this->assertSame( array(), (array) diluxone_users_option( 'diluxone_users_account_sections' ) );
		$this->assertSame( 1, (int) diluxone_users_option( 'diluxone_users_privacy_export' ) );
	}

	/**
	 * In Network Admin the sections are not there to save: they are the hub's.
	 *
	 * @dataProvider forms
	 *
	 * @param array<string, mixed> $post
	 */
	public function test_each_form_saves_nothing_from_network_admin( string $field, string $action, array $post ): void {
		$this->network_only( 'CoverSettingsAccountTest::test_each_form_refuses_somebody_without_the_capability' );

		$this->the_admin();
		$this->in_network_admin();
		$this->send_sections_form( $field, $action, $post );

		$this->assertSame( array( 'died', 'You are not allowed to do this.' ), $this->ended( 'diluxone_users_account_post' ) );
		$this->assertSame( array(), (array) diluxone_users_option( 'diluxone_users_account_sections' ) );
		$this->assertSame( 1, (int) diluxone_users_option( 'diluxone_users_privacy_export' ) );
	}

	/* ── On, off, delete ───────────────────────────────────────────── */

	public function test_a_section_is_turned_off_and_on_and_the_screen_comes_back_to_it(): void {
		$this->the_admin();

		list( $how, $to ) = $this->press( 'details', 'off' );

		$this->assertSame( 'redirect', $how );
		$this->assertSame( 'details', $this->queryArg( $to, 'section' ) );
		$this->assertSame( 'sections', $this->queryArg( $to, 'tab' ) );
		$this->assertSame( 0, $this->config( 'details' )['enabled'] );

		$this->press( 'details', 'on' );
		$this->assertSame( 1, $this->config( 'details' )['enabled'] );

		// An action that is not one changes nothing and still goes back.
		list( $how ) = $this->press( 'details', 'sideways' );
		$this->assertSame( 'redirect', $how );
		$this->assertSame( 1, $this->config( 'details' )['enabled'] );
	}

	/** Removing a section is the add-on's that made it: here a press for it removes nothing. */
	public function test_the_free_plugin_removes_no_section(): void {
		$this->the_admin();
		$this->addon_section( 'mine' );

		list( $how ) = $this->press( 'mine', 'delete' );

		$this->assertSame( 'redirect', $how );
		$this->assertSame( 'Mine', $this->config( 'mine' )['label'], 'still there' );
		$this->assertFalse( function_exists( 'diluxone_users_section_delete' ) );
	}

	public function test_a_press_with_a_bad_nonce_stops(): void {
		$this->the_admin();

		$this->assertSame( array( 'died', self::EXPIRED ), $this->press( 'details', 'off', 'not-a-nonce' ) );
		$this->assertSame( array(), $this->config( 'details' ) );
	}

	public function test_a_press_from_somebody_without_the_capability_or_elsewhere_does_nothing(): void {
		$this->not_allowed();
		$this->assertSame( array( 'returned', null ), $this->press( 'details', 'off' ), 'a subscriber' );

		$this->the_admin();
		$this->assertSame( array( 'returned', null ), $this->press( 'details', 'off', '', 'diluxone-users-design' ), 'another screen' );

		$_GET = array( 'page' => 'diluxone-users-account' );
		$this->assertSame( array( 'returned', null ), $this->ended( 'diluxone_users_account_actions' ), 'no action asked' );

		$this->assertSame( array(), $this->config( 'details' ) );
	}

	public function test_a_press_from_network_admin_does_nothing(): void {
		$this->network_only( 'CoverSettingsAccountTest::test_a_press_from_somebody_without_the_capability_or_elsewhere_does_nothing' );

		$this->the_admin();
		$this->in_network_admin();

		$this->assertSame( array( 'returned', null ), $this->press( 'details', 'off' ) );
		$this->assertSame( array(), $this->config( 'details' ) );
	}

	/* ── The panels' saves ─────────────────────────────────────────── */

	/** @return array<string, array{0: string, 1: array<string, mixed>, 2: string}> */
	public static function saves(): array {
		return array(
			'the page'      => array( 'diluxone_users_account_page_save', array( 'diluxone_users_account_page' => '77' ), 'diluxone_users_account_page' ),
			'the menu'      => array( 'diluxone_users_account_menu_save', array( 'diluxone_users_menu_style' => 'name' ), 'diluxone_users_menu_style' ),
			'the dashboard' => array( 'diluxone_users_account_dashboard_save', array( 'diluxone_users_admin_bar' => 'hide-all' ), 'diluxone_users_admin_bar' ),
		);
	}

	/**
	 * @dataProvider saves
	 *
	 * @param array<string, mixed> $post
	 */
	public function test_each_save_stops_on_a_bad_nonce_or_a_missing_capability( string $save, array $post, string $option ): void {
		$before = diluxone_users_option( $option );

		$this->the_admin();
		$this->postAs( get_current_user_id(), $post + array( 'diluxone_users_panel_nonce' => 'not-a-nonce' ) );
		$this->assertSame( array( 'died', self::EXPIRED ), $this->ended( $save ), 'a bad nonce' );

		$this->send_panel( 'diluxone-users-design', $post );
		$this->assertSame( array( 'died', self::EXPIRED ), $this->ended( $save ), 'another screen’s nonce' );

		$this->not_allowed();
		$this->send_panel( 'diluxone-users-account', $post );
		$this->assertSame( array( 'died', 'You are not allowed to do this.' ), $this->ended( $save ), 'a subscriber' );

		$this->assertSame( $before, diluxone_users_option( $option ) );
	}

	public function test_the_page_is_saved_as_a_number_and_the_rules_are_rebuilt(): void {
		$this->the_admin();
		diluxone_users_update_option( 'diluxone_users_rewrite_version', DILUXONE_USERS_VERSION );

		$this->send_panel( 'diluxone-users-account', array( 'diluxone_users_account_page' => '42abc' ) );
		$this->assertSame( 'returned', $this->ended( 'diluxone_users_account_page_save' )[0] );

		$this->assertSame( 42, diluxone_users_account_page_id() );
		$this->assertFalse( diluxone_users_raw_get( 'diluxone_users_rewrite_version' ), 'the rewrite rules are made again' );
	}

	public function test_the_menu_takes_only_a_place_and_a_look_that_exist(): void {
		register_nav_menu( 'cover-settings-primary', 'Cover primary' );
		$this->the_admin();

		$this->send_panel(
			'diluxone-users-account',
			array(
				'diluxone_users_menu_location' => 'cover-settings-primary',
				'diluxone_users_menu_style'    => 'avatar',
			)
		);
		diluxone_users_account_menu_save();

		$this->assertSame( 'cover-settings-primary', diluxone_users_option( 'diluxone_users_menu_location' ) );
		$this->assertSame( 'avatar', diluxone_users_option( 'diluxone_users_menu_style' ) );

		$this->send_panel(
			'diluxone-users-account',
			array(
				'diluxone_users_menu_location' => 'not-a-place',
				'diluxone_users_menu_style'    => 'giant-photo',
			)
		);
		diluxone_users_account_menu_save();

		$this->assertSame( '', diluxone_users_option( 'diluxone_users_menu_location' ), 'nowhere' );
		$this->assertSame( 'avatar-name', diluxone_users_option( 'diluxone_users_menu_style' ), 'the usual look' );
	}

	public function test_the_dashboard_answers_come_apart_into_their_settings(): void {
		$this->the_admin();

		$this->send_panel(
			'diluxone-users-account',
			array(
				'diluxone_users_wp_profile'       => 'Redirect',
				'diluxone_users_admin_bar'        => 'hide-some',
				'diluxone_users_admin_bar_roles'  => array( 'Subscriber', 'author<script>' ),
				'diluxone_users_bar_account'      => '1',
				'diluxone_users_wp_profile_scope' => 'some',
				'diluxone_users_wp_profile_roles' => array( 'subscriber' ),
			)
		);
		diluxone_users_account_dashboard_save();

		$this->assertSame( 'redirect', diluxone_users_option( 'diluxone_users_wp_profile' ) );
		$this->assertSame( 'hide', diluxone_users_option( 'diluxone_users_admin_bar' ) );
		$this->assertSame( 'some', diluxone_users_option( 'diluxone_users_admin_bar_scope' ) );
		$this->assertSame( array( 'subscriber', 'authorscript' ), diluxone_users_option( 'diluxone_users_admin_bar_roles' ) );
		$this->assertSame( 0, (int) diluxone_users_option( 'diluxone_users_admin_bar_keep_admins' ), 'unticked' );
		$this->assertSame( 1, (int) diluxone_users_option( 'diluxone_users_bar_account' ) );
		$this->assertSame( 'some', diluxone_users_option( 'diluxone_users_wp_profile_scope' ) );

		// Hidden from everybody, and shown.
		$this->send_panel( 'diluxone-users-account', array( 'diluxone_users_admin_bar' => 'hide-all' ) );
		diluxone_users_account_dashboard_save();
		$this->assertSame( 'hide', diluxone_users_option( 'diluxone_users_admin_bar' ) );
		$this->assertSame( 'all', diluxone_users_option( 'diluxone_users_admin_bar_scope' ) );

		$this->send_panel( 'diluxone-users-account', array( 'diluxone_users_admin_bar' => 'whatever' ) );
		diluxone_users_account_dashboard_save();
		$this->assertSame( 'hide', diluxone_users_option( 'diluxone_users_admin_bar' ), 'anything but "wp" hides it' );

		$this->send_panel( 'diluxone-users-account', array() );
		diluxone_users_account_dashboard_save();
		$this->assertSame( 'wp', diluxone_users_option( 'diluxone_users_admin_bar' ), 'nothing sent is WordPress’s own' );
		$this->assertSame( 'redirect', diluxone_users_option( 'diluxone_users_wp_profile' ), 'a question the form did not ask keeps its answer' );
		$this->assertSame( 'some', diluxone_users_option( 'diluxone_users_wp_profile_scope' ) );

		// Answers that are none of the screen's, and roles that are lists.
		$this->send_panel(
			'diluxone-users-account',
			array(
				'diluxone_users_wp_profile'       => 'bogus',
				'diluxone_users_admin_bar'        => 'hide-some',
				'diluxone_users_admin_bar_roles'  => array( array( 'x' ), 'editor' ),
				'diluxone_users_wp_profile_scope' => 'bogus',
				'diluxone_users_wp_profile_roles' => array( array( 'x' ) ),
			)
		);
		diluxone_users_account_dashboard_save();
		$this->assertSame( 'allow', diluxone_users_option( 'diluxone_users_wp_profile' ), 'not a way of closing the profile' );
		$this->assertSame( array( 'editor' ), diluxone_users_option( 'diluxone_users_admin_bar_roles' ), 'a list is no role' );
		$this->assertSame( 'all', diluxone_users_option( 'diluxone_users_wp_profile_scope' ) );
		$this->assertSame( array(), diluxone_users_option( 'diluxone_users_wp_profile_roles' ) );
	}

	/** Each of the five privacy questions keeps the plugin's answer for anything but the other one. */
	public function test_each_privacy_answer_is_one_of_its_two(): void {
		$this->the_admin();
		$post = array( 'diluxone_users_privacy_nonce' => wp_create_nonce( 'diluxone_users_privacy' ) );

		foreach ( array( 'export_when', 'export_link', 'export_file', 'delete_when', 'delete_link' ) as $question ) {
			$post[ 'diluxone_users_privacy_' . $question ] = 'bogus';
		}

		$this->postAs( get_current_user_id(), $post );
		diluxone_users_account_privacy_save();

		$this->assertSame( 'confirm', diluxone_users_option( 'diluxone_users_privacy_export_when' ) );
		$this->assertSame( 'account', diluxone_users_option( 'diluxone_users_privacy_export_link' ) );
		$this->assertSame( 'account', diluxone_users_option( 'diluxone_users_privacy_export_file' ) );
		$this->assertSame( 'confirm', diluxone_users_option( 'diluxone_users_privacy_delete_when' ) );
		$this->assertSame( 'account', diluxone_users_option( 'diluxone_users_privacy_delete_link' ) );
	}

	/* ── The tabs, drawn ───────────────────────────────────────────── */

	/** The whole screen on one tab, as an administrator sees it. */
	private function screen( string $tab, array $get = array() ): string {
		$_GET = array(
			'page' => 'diluxone-users-account',
			'tab'  => $tab,
		) + $get;

		return $this->draw( 'diluxone_users_screen_account' );
	}

	public function test_the_summary_reads_back_what_the_other_tabs_wrote(): void {
		$this->the_admin();

		// Written at their defaults: a setting nobody saved reads Not
		// configured instead (NotConfiguredTest), and this is about what the
		// tabs wrote.
		diluxone_users_update_option( 'diluxone_users_account_page', 0 );
		diluxone_users_update_option( 'diluxone_users_wp_profile', 'allow' );
		diluxone_users_update_option( 'diluxone_users_admin_bar', 'wp' );
		diluxone_users_update_option( 'diluxone_users_handle_enabled', 0 );

		$html = $this->screen( 'summary' );

		$this->assertStringContainsString( 'This site has no account area.', $html );
		$this->assertStringContainsString( 'Reachable', $html );
		$this->assertStringContainsString( 'Shown, as WordPress does.', $html );
		$this->assertStringContainsString( 'can ask for a copy · can ask to be deleted', $html );
		$this->assertStringContainsString( 'The name comes from their first and last name.', $html );

		$page = $this->page( 'Cover account' );
		diluxone_users_update_option( 'diluxone_users_account_page', $page );
		diluxone_users_update_option( 'diluxone_users_wp_profile', 'redirect' );
		diluxone_users_update_option( 'diluxone_users_admin_bar', 'hide' );
		diluxone_users_update_option( 'diluxone_users_admin_bar_scope', 'some' );
		diluxone_users_update_option( 'diluxone_users_privacy_delete', 0 );
		diluxone_users_update_option( 'diluxone_users_handle_enabled', 1 );

		$html = $this->screen( 'summary' );

		$this->assertStringContainsString( 'Cover account</a>', $html );
		$this->assertStringContainsString( 'Kept out', $html );
		$this->assertStringContainsString( 'Sends people to their account on the site.', $html );
		$this->assertStringContainsString( 'Hidden on the site for some roles.', $html );
		$this->assertStringContainsString( 'can ask for a copy', $html );
		$this->assertStringNotContainsString( 'can ask to be deleted', $html );
		$this->assertStringContainsString( 'People choose the short name they appear under.', $html );

		diluxone_users_update_option( 'diluxone_users_admin_bar_scope', 'all' );
		$this->assertStringContainsString( 'Hidden on the site for everybody.', $this->screen( 'summary' ) );
	}

	public function test_the_page_tab_with_no_page_and_with_one(): void {
		$this->the_admin();
		update_option( 'permalink_structure', '' );

		$html = $this->screen( 'page' );

		$this->assertStringContainsString( 'name="diluxone_users_account_page"', $html );
		$this->assertStringContainsString( 'This site has not got one.', $html );
		$this->assertStringContainsString( 'Links to “my account” from the rest of the site go to the front page.', $html );
		$this->assertStringContainsString( 'With plain permalinks the sections go as ?section=…', $html );
		$this->assertStringContainsString( 'name="diluxone_users_panel_nonce"', $html, 'the screen carries the panel’s nonce' );

		$page = $this->page( 'Cover my account' );
		diluxone_users_update_option( 'diluxone_users_account_page', $page );
		update_option( 'permalink_structure', '/%postname%/' );

		$html = $this->screen( 'page' );

		$this->assertStringContainsString( 'Cover my account</a>', $html );
		$this->assertStringNotContainsString( 'What that means', $html );
		$this->assertStringNotContainsString( 'With plain permalinks', $html );
	}

	public function test_the_dashboard_tab_says_where_people_are_sent(): void {
		$this->the_admin();

		$html = $this->screen( 'dashboard' );

		$this->assertStringContainsString( 'name="diluxone_users_admin_bar"', $html );
		$this->assertStringContainsString( 'Nowhere yet: this site has no account area', $html );

		$page = $this->page( 'Cover where to' );
		diluxone_users_update_option( 'diluxone_users_account_page', $page );
		diluxone_users_update_option( 'diluxone_users_admin_bar', 'hide' );
		diluxone_users_update_option( 'diluxone_users_admin_bar_scope', 'some' );

		$html = $this->screen( 'dashboard' );

		$this->assertStringContainsString( 'Cover where to</a>', $html );
		$this->assertMatchesRegularExpression( '/value="hide-some"[^>]*checked/', $html, 'the answer saved is the one ticked' );
	}

	public function test_the_menu_tab_says_which_menu_the_person_is_in(): void {
		register_nav_menu( 'cover-settings-primary', 'Cover primary' );
		$this->the_admin();

		$this->assertStringContainsString( 'Not shown in any menu.', $this->screen( 'menu' ) );

		// A place with no menu in it.
		diluxone_users_update_option( 'diluxone_users_menu_location', 'cover-settings-primary' );
		$this->assertStringContainsString( 'That place has no menu assigned in Appearance → Menus.', $this->screen( 'menu' ) );

		// And one with a menu.
		$menu          = (int) wp_create_nav_menu( 'Cover menu ' . wp_generate_password( 4, false ) );
		$this->menus[] = $menu;
		set_theme_mod( 'nav_menu_locations', array( 'cover-settings-primary' => $menu ) );

		$html = $this->screen( 'menu' );

		$this->assertStringContainsString( 'Cover menu', $html );
		$this->assertStringNotContainsString( 'That place has no menu assigned', $html );
	}

	public function test_the_handle_tab_is_drawn(): void {
		$this->the_admin();
		diluxone_users_update_option( 'diluxone_users_handle_enabled', 1 );
		diluxone_users_update_option( 'diluxone_users_handle_min', 4 );

		$html = $this->screen( 'handle' );

		$this->assertStringContainsString( 'name="diluxone_users_panel_nonce"', $html );
		$this->assertMatchesRegularExpression( '/<input[^>]*name="diluxone_users_handle_enabled"[^>]*checked/', $html, 'offered: the box is ticked' );
		$this->assertMatchesRegularExpression( '/name="diluxone_users_handle_min"[^>]*value="4"|value="4"[^>]*name="diluxone_users_handle_min"/', $html, 'and the rules show what is saved' );
	}

	public function test_the_sections_tab_opens_on_the_first_one_and_warns_without_a_page(): void {
		$this->the_admin();

		$html = $this->screen( 'sections', array( 'section' => 'not-a-section' ) );

		$this->assertStringContainsString( 'name="diluxone_users_order_form_nonce"', $html );
		$this->assertStringContainsString( 'name="diluxone_users_section_form_nonce"', $html );
		$this->assertStringContainsString( 'There is no account page yet', $html );
		$this->assertMatchesRegularExpression( '/class="diluxone-users-endpoint is-current\s*"/', $html, 'the first one is open' );
		$this->assertStringContainsString( '<h3>Home</h3>', $html, 'and it is the front page' );
		$this->assertStringContainsString( 'name="diluxone_users_section_form[cards_shown]"', $html, 'whose form carries the cards' );
		$this->assertStringContainsString( 'It comes from code, so it cannot be deleted', $html );
		$this->assertStringNotContainsString( 'data-diluxone-users-confirm="Delete this section?"', $html, 'and it has no Remove' );
		$this->assertStringContainsString( 'name="diluxone_users_section_form[placement]"', $html );
	}

	public function test_the_front_page_with_no_card_on_offer_says_so(): void {
		$this->the_admin();
		add_filter( 'diluxone_users_summaries', '__return_empty_array' );

		try {
			$html = $this->screen( 'sections', array( 'section' => 'home' ) );
		} finally {
			remove_filter( 'diluxone_users_summaries', '__return_empty_array' );
		}

		$this->assertStringContainsString( 'No section offers a card yet, so the front page is empty.', $html );
		$this->assertStringNotContainsString( 'name="diluxone_users_section_form[cards][]"', $html );
	}

	/** With nothing drawing it, the new section's address opens the first section, and an add-on's detail draws there when there is one. */
	public function test_an_add_ons_detail_is_drawn_where_a_section_would_be(): void {
		$this->the_admin();

		$html = $this->screen( 'sections', array( 'section' => 'diluxone-users-new' ) );

		$this->assertStringContainsString( '<h3>Home</h3>', $html, 'nothing makes sections, so the first one opens' );
		$this->assertStringNotContainsString( 'New section', $html );
		$this->assertStringNotContainsString( 'Add section', $html );

		$this->hook(
			'diluxone_users_account_details',
			static function ( array $details ): array {
				$details['diluxone-users-new'] = static function ( int $page ): void {
					echo '<p id="addon-new" data-page="' . esc_attr( (string) $page ) . '">new</p>';
				};
				$details['broken']             = 'not_a_function_anybody_has';

				return $details;
			}
		);
		$this->hook(
			'diluxone_users_account_sections_head',
			static function (): void {
				echo '<a id="addon-add">Add</a>';
			}
		);

		$html = $this->screen( 'sections', array( 'section' => 'diluxone-users-new' ) );

		$this->assertStringContainsString( '<p id="addon-new" data-page="0">new</p>', $html );
		$this->assertStringContainsString( '<a id="addon-add">Add</a>', $html, 'beside the heading of the list' );
		$this->assertStringNotContainsString( 'name="diluxone_users_section_form_nonce"', $html, 'no section form beside it' );
		$this->assertStringContainsString( '<h3>Home</h3>', $this->screen( 'sections', array( 'section' => 'broken' ) ), 'a detail nothing can draw is not one' );
	}

	/** An add-on's section is edited like any, its head carries what the add-on adds, and nothing says it comes from code. */
	public function test_an_add_ons_section_is_edited_here_and_its_head_is_the_add_ons(): void {
		$this->the_admin();

		$page = $this->page( 'Cover account page' );
		diluxone_users_update_option( 'diluxone_users_account_page', $page );
		$this->addon_section( 'cover-yours', array( 'label' => 'Cover yours', 'content' => '<p>Mine</p>' ) );
		$this->hook(
			'diluxone_users_account_section_head',
			static function ( string $id, array $section ): void {
				echo '<a id="addon-remove" data-section="' . esc_attr( $id ) . '" data-custom="' . ( empty( $section['custom'] ) ? '0' : '1' ) . '">Remove</a>';
			},
			10,
			2
		);

		$html = $this->screen( 'sections', array( 'section' => 'cover-yours' ) );

		$this->assertStringContainsString( '<h3>Cover yours</h3>', $html );
		$this->assertStringContainsString( '<a id="addon-remove" data-section="cover-yours" data-custom="1">Remove</a>', $html );
		$this->assertStringNotContainsString( 'diluxone_users_action=delete', $html );
		$this->assertStringContainsString( 'diluxone_users_action=off', $html );
		$this->assertStringContainsString( 'Showing', $html );
		$this->assertStringContainsString( 'value="replace"', $html, 'with no code, the content is the whole section' );
		$this->assertStringContainsString( 'target="_blank" rel="noopener"', $html, 'its address, as a link' );
		$this->assertStringNotContainsString( 'There is no account page yet', $html );
		$this->assertStringNotContainsString( 'It comes from code', $html );
	}

	/**
	 * A section of the site's own kept after the add-on that made it went:
	 * not drawn, listed with the badge and nothing to open, out of the order,
	 * and kept by every save.
	 */
	public function test_a_section_kept_from_an_add_on_is_listed_and_kept(): void {
		$this->the_admin();
		diluxone_users_section_config_save(
			'staff',
			array(
				'label'   => 'Staff <b>room</b>',
				'slug'    => 'staff',
				'custom'  => true,
				'content' => '<p>For staff</p>',
			)
		);

		$this->assertArrayNotHasKey( 'staff', diluxone_users_sections( true ), 'nothing draws it' );
		$this->assertSame( array( 'staff' => array( 'label' => 'Staff room', 'slug' => 'staff' ) ), diluxone_users_sections_kept() );

		$html = $this->screen( 'sections', array( 'section' => 'staff' ) );

		$this->assertMatchesRegularExpression( '/<li class="diluxone-users-endpoint is-off is-kept">\s*<span class="diluxone-users-endpoint__name">Staff room<\/span>/', $html );
		$this->assertStringNotContainsString( 'name="diluxone_users_order_form[]" value="staff"', $html, 'out of the order' );
		$this->assertStringNotContainsString( 'section=staff', $html, 'nothing to open' );
		$this->assertStringContainsString( '<h3>Home</h3>', $html, 'asked for, it opens the first one instead' );

		diluxone_users_section_reorder( array( 'staff', 'security', 'home' ) );
		diluxone_users_section_save( array( 'id' => 'details', 'label' => 'About you' ) );

		$this->assertSame( '<p>For staff</p>', $this->config( 'staff' )['content'], 'every save keeps it' );
		$this->assertArrayNotHasKey( 'position', $this->config( 'staff' ) );

		$this->addon_section( 'staff' );
		$this->assertArrayHasKey( 'staff', diluxone_users_sections( true ), 'back as it was with the add-on' );
		$this->assertSame( array(), diluxone_users_sections_kept() );
	}

	/** The card about sections of the site's own, under the list, and gone when an add-on says so. */
	public function test_the_pro_button_sits_beside_the_heading_where_add_section_would_be(): void {
		$this->the_admin();

		$html = $this->screen( 'sections' );
		$pro  = (int) strpos( $html, 'data-diluxone-users-pro="account/sections/more"' );

		$this->assertGreaterThan( (int) strpos( $html, 'diluxone-users-endpoints__head' ), $pro, 'beside the heading' );
		$this->assertLessThan( (int) strpos( $html, 'diluxone-users-endpoints__list' ), $pro, 'and before the list' );
		$this->assertStringContainsString( '<button type="button" class="button" disabled>Add a section</button>', $html, 'the button it would be, switched off' );
		$this->assertStringContainsString( 'du-pro--button', $html );
		$this->assertStringNotContainsString( 'du-pro-card', $html, 'and no card under the list' );

		$this->hook( 'diluxone_users_upsell', static fn( bool $show, string $where ): bool => 'account/sections/more' !== $where && $show, 10, 2 );

		$this->assertStringNotContainsString( 'data-diluxone-users-pro="account/sections/more"', $this->screen( 'sections' ) );
	}

	public function test_a_section_that_is_on_and_cannot_show_says_why(): void {
		$this->the_admin();

		// Linked accounts, with no network working: on, and not showing.
		$html = $this->screen( 'sections', array( 'section' => 'accounts' ) );

		$this->assertStringContainsString( 'It is on, but it is not showing', $html );
		$this->assertStringContainsString( 'There is no social network turned on, so there is nothing to link.', $html );

		// Turned off, it says so instead.
		diluxone_users_section_config_save( 'accounts', array( 'enabled' => 0 ) );
		$html = $this->screen( 'sections', array( 'section' => 'accounts' ) );

		$this->assertStringNotContainsString( 'It is on, but it is not showing', $html );
		$this->assertStringContainsString( 'Hidden', $html );
		$this->assertStringContainsString( 'diluxone_users_action=on', $html );
	}

	public function test_the_your_data_section_carries_its_own_form_with_every_answer(): void {
		$this->the_admin();
		diluxone_users_update_option( 'diluxone_users_privacy_export_file', 'link' );
		diluxone_users_update_option( 'diluxone_users_privacy_delete_when', 'nonsense' );

		$html = $this->screen( 'sections', array( 'section' => 'privacy' ) );

		$this->assertStringContainsString( 'id="diluxone-users-privacy-form"', $html );
		$this->assertStringContainsString( 'name="diluxone_users_privacy_nonce"', $html );

		foreach ( array( 'export_when', 'export_link', 'export_file', 'delete_when', 'delete_link' ) as $question ) {
			$this->assertStringContainsString( 'name="diluxone_users_privacy_' . $question . '"', $html, $question );
		}

		$this->assertMatchesRegularExpression( '/name="diluxone_users_privacy_export_file" value="link"[^>]*checked/', $html, 'the saved answer' );
		$this->assertMatchesRegularExpression( '/name="diluxone_users_privacy_delete_when" value="confirm"[^>]*checked/', $html, 'an answer that is not one shows the usual one' );
	}

	/** @return array<string, array{0: string, 1: string}> */
	public static function notices(): array {
		return array(
			'order'   => array( 'order', 'New order saved.' ),
			'saved'   => array( 'saved', 'Section saved.' ),
			'privacy' => array( 'privacy', 'Saved.' ),
			'error'   => array( 'error', 'That section needs a name, and an address that is not taken.' ),
		);
	}

	/** @dataProvider notices */
	public function test_the_notice_says_what_just_happened( string $msg, string $says ): void {
		$_GET = array( 'diluxone_users_msg' => $msg );

		$this->assertStringContainsString( $says, $this->draw( 'diluxone_users_account_notice' ) );
	}

	/** An add-on comes back to the tab with a word of its own. */
	public function test_an_add_on_adds_its_own_notice(): void {
		$this->hook(
			'diluxone_users_account_notices',
			static function ( array $notices ): array {
				$notices['added'] = array( 'success', 'Section added.' );

				return $notices;
			}
		);
		$_GET = array( 'diluxone_users_msg' => 'added' );

		$this->assertStringContainsString( 'Section added.', $this->draw( 'diluxone_users_account_notice' ) );

		$_GET = array( 'diluxone_users_msg' => 'deleted' );

		$this->assertSame( '', $this->draw( 'diluxone_users_account_notice' ), 'the free plugin removes nothing, so it says nothing about it' );
	}

	public function test_no_notice_for_a_message_that_is_not_one(): void {
		$_GET = array( 'diluxone_users_msg' => 'hacked' );

		$this->assertSame( '', $this->draw( 'diluxone_users_account_notice' ) );
	}
}
