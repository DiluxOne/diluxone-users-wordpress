<?php
/**
 * The sentences the way in shows, and the store behind them.
 *
 * This is the first PHP test the messages have. Everything about them that
 * matters is a decision taken in a function — which text wins, what an empty
 * box means, whether a rewrite written for Portuguese may be shown to a
 * Spanish reader, what a value somebody edited by hand in the database does
 * to a sign-in page. A browser can prove one of those at a time and takes
 * four seconds each; these take milliseconds and prove all of them.
 *
 * The one thing deliberately left to the end-to-end suite is the round trip
 * through the dashboard, because that is a form, a nonce and a redirect, and
 * the only honest way to test a form is to press its button.
 */

namespace Tests\Integration;

use Tests\Integration\Support\CoverSettingsSupport;

class LoginMessagesTest extends IntegrationTestCase {

	use CoverSettingsSupport;

	protected function setUp(): void {
		parent::setUp();

		// Not one of the options the base class clears: it is a map of
		// locales, not a setting with a default, so it is cleaned where it is
		// known about.
		delete_option( DILUXONE_USERS_LOGIN_MESSAGES );
	}

	protected function tearDown(): void {
		delete_option( DILUXONE_USERS_LOGIN_MESSAGES );
		diluxone_users_delete_option( DILUXONE_USERS_LOGIN_MESSAGES );
		$this->cover_settings_reset();

		parent::tearDown();
	}

	/**
	 * The message boxes one screen's Messages tab draws, by key.
	 *
	 * @return array<int, string>
	 */
	private function boxes( string $screen ): array {
		do_action( 'diluxone_users_register_panels' );

		$_GET = array(
			'page' => $screen,
			'tab'  => 'messages',
		);

		$html = $this->draw( 'diluxone-users-register' === $screen ? 'diluxone_users_screen_registration' : 'diluxone_users_screen_login' );

		preg_match_all( '#name="diluxone_users_message\[([a-z0-9_]+)\]"#', $html, $found );

		return $found[1];
	}

	/* ── Which screen holds which sentences ─────────────────────────── */

	public function test_c2_the_registration_screen_draws_the_seven_registration_messages_and_no_other(): void {
		$this->the_admin();

		$register = array_keys( wp_list_filter( diluxone_users_login_messages(), array( 'group' => 'register' ) ) );

		$this->assertCount( 7, $register );
		$this->assertSame( $register, $this->boxes( 'diluxone-users-register' ) );
	}

	public function test_c3_access_draws_the_sign_in_reset_and_second_step_messages_and_no_registration_one(): void {
		$this->the_admin();

		$boxes = $this->boxes( 'diluxone-users-login' );
		$want  = array_keys(
			array_filter(
				diluxone_users_login_messages(),
				static fn( array $one ): bool => 'register' !== $one['group']
			)
		);

		$this->assertSame( $want, $boxes );
		$this->assertSame( array(), preg_grep( '/^register_/', $boxes ) );
		$this->assertSame( array( 'login', 'two_step', 'reset' ), diluxone_users_login_message_screen_groups( 'diluxone-users-login' ) );
	}

	public function test_c3_saving_access_messages_leaves_every_registration_message_untouched(): void {
		$this->the_admin();

		foreach ( array_keys( wp_list_filter( diluxone_users_login_messages(), array( 'group' => 'register' ) ) ) as $key ) {
			diluxone_users_login_message_rewrite( (string) $key, 'en_US', 'Kept: ' . $key );
		}

		$before = diluxone_users_login_messages_store()['en_US'];

		$this->send_panel(
			'diluxone-users-login',
			array(
				'diluxone_users_message_locale'  => 'en_US',
				'diluxone_users_message'         => array_fill_keys( array_keys( $before ), 'From Access' ) + array( 'login_expired' => 'Ours' ),
				'diluxone_users_message_shipped' => array_fill_keys( array_keys( $before ), '1' ),
			)
		);
		$this->ended( 'diluxone_users_login_messages_save' );

		$after = diluxone_users_login_messages_store()['en_US'];

		$this->assertSame( 'Ours', $after['login_expired'] ?? null );
		unset( $after['login_expired'] );
		$this->assertSame( $before, $after );
	}

	public function test_c2_the_registration_messages_are_saved_there_behind_its_own_nonce(): void {
		$this->the_admin();
		diluxone_users_login_message_rewrite( 'login_expired', 'en_US', 'Signing in says this.' );

		$post = array(
			'diluxone_users_message_locale' => 'en_US',
			'diluxone_users_message'        => array(
				'register_taken' => 'That address has an account: sign in instead.',
				'login_expired'  => 'Not this screen’s to write.',
			),
		);

		$this->send_panel( 'diluxone-users-login', $post );
		$this->assertSame( array( 'died', self::EXPIRED ), $this->ended( 'diluxone_users_register_messages_save' ), 'Access’s nonce does not save it' );

		$this->send_panel( 'diluxone-users-register', $post );
		$this->ended( 'diluxone_users_register_messages_save' );

		$store = diluxone_users_login_messages_store()['en_US'] ?? array();

		$this->assertSame( 'That address has an account: sign in instead.', $store['register_taken'] ?? null );
		$this->assertSame( 'Signing in says this.', $store['login_expired'] ?? null, 'a sentence of Access is left as it was' );
		$this->assertSame( 'That address has an account: sign in instead.', diluxone_users_login_message( 'register_taken' ), 'and the form reads it' );
		$this->assertSame( 1, diluxone_users_login_messages_own( 'en_US', 'diluxone-users-register' ) );
	}

	/** Every message the plugin registers has everything the screen needs. */
	public function test_every_message_is_complete(): void {
		$groups = diluxone_users_login_message_groups();

		$this->assertNotEmpty( diluxone_users_login_messages() );

		foreach ( diluxone_users_login_messages() as $key => $message ) {
			$this->assertArrayHasKey( $message['group'], $groups, "$key belongs to no group" );
			$this->assertContains( $message['tone'], array( 'error', 'ok' ), "$key has no tone" );
			$this->assertNotSame( '', (string) $message['label'], "$key has no name" );
			$this->assertNotSame( '', (string) $message['when'], "$key does not say when it appears" );
			$this->assertNotSame(
				'',
				diluxone_users_login_message_shipped( (string) $key ),
				"$key would be shown as an empty notice"
			);
		}
	}

	/** With nothing written, what is shown is what the plugin ships. */
	public function test_nothing_written_means_the_plugins_own_words(): void {
		$this->assertSame(
			diluxone_users_login_message_shipped( 'login_social' ),
			diluxone_users_login_message( 'login_social' )
		);
	}

	/** And what the site writes is what is shown. */
	public function test_a_rewrite_is_what_a_stranger_reads(): void {
		diluxone_users_login_message_rewrite( 'login_social', get_locale(), 'Esa red no respondió.' );

		$this->assertSame( 'Esa red no respondió.', diluxone_users_login_message( 'login_social' ) );
		// And only that one: rewriting one message must not touch its neighbours.
		$this->assertSame(
			diluxone_users_login_message_shipped( 'login_error' ),
			diluxone_users_login_message( 'login_error' )
		);
	}

	/**
	 * Emptying the box is the way back, and it leaves nothing behind.
	 *
	 * An entry stored as an empty string and no entry at all have to behave
	 * the same; the only way to be sure of that is for one of them never to
	 * exist, which is what the option is asked here.
	 */
	public function test_emptying_a_message_puts_the_plugins_own_words_back(): void {
		diluxone_users_login_message_rewrite( 'login_social', get_locale(), 'Algo nuestro.' );
		diluxone_users_login_message_rewrite( 'login_social', get_locale(), '' );

		$this->assertSame(
			diluxone_users_login_message_shipped( 'login_social' ),
			diluxone_users_login_message( 'login_social' )
		);
		$this->assertSame( array(), get_option( DILUXONE_USERS_LOGIN_MESSAGES ) );
	}

	/**
	 * A rewrite belongs to a language, not to a site.
	 *
	 * A site running in two languages does not show the same sentence to both,
	 * and the language somebody has not touched keeps the plugin's own.
	 */
	public function test_a_rewrite_written_for_another_language_is_not_shown(): void {
		diluxone_users_login_message_rewrite( 'login_social', 'pt_PT', 'Não foi possível.' );

		$this->assertSame(
			diluxone_users_login_message_shipped( 'login_social' ),
			diluxone_users_login_message( 'login_social' )
		);

		$store = diluxone_users_login_messages_store();

		$this->assertSame( 'Não foi possível.', $store['pt_PT']['login_social'] );
	}

	/** The filter has the last word, which is what makes it the seam for code. */
	public function test_the_filter_wins_over_everything(): void {
		diluxone_users_login_message_rewrite( 'login_social', get_locale(), 'Lo que escribió el sitio.' );

		$seen = array();
		$said = static function ( string $text, string $key ) use ( &$seen ): string {
			$seen[] = $key;

			return 'login_social' === $key ? 'Lo que decidió el código.' : $text;
		};

		add_filter( 'diluxone_users_login_message', $said, 10, 2 );

		$this->assertSame( 'Lo que decidió el código.', diluxone_users_login_message( 'login_social' ) );

		remove_filter( 'diluxone_users_login_message', $said, 10 );

		$this->assertSame( array( 'login_social' ), $seen );
		$this->assertSame( 'Lo que escribió el sitio.', diluxone_users_login_message( 'login_social' ) );
	}

	/**
	 * A value nobody wrote through the screen cannot reach the sign-in page.
	 *
	 * Hand-edited, restored from a half-finished export, written by an older
	 * version: a row that is not shaped like a rewrite is dropped rather than
	 * repaired, and the message it belonged to goes back to words that read
	 * correctly.
	 *
	 * @dataProvider junkInTheOption
	 * @param mixed $stored
	 */
	public function test_a_broken_option_is_read_as_nothing( $stored ): void {
		update_option( DILUXONE_USERS_LOGIN_MESSAGES, $stored );

		$this->assertSame( array(), diluxone_users_login_messages_clean( $stored ) );
		$this->assertSame(
			diluxone_users_login_message_shipped( 'login_social' ),
			diluxone_users_login_message( 'login_social' )
		);
	}

	/** @return array<string, array{mixed}> */
	public static function junkInTheOption(): array {
		return array(
			'a string where a map was'    => array( 'login_social' ),
			'a number'                    => array( 7 ),
			'a locale holding a string'   => array( array( 'es_ES' => 'una frase' ) ),
			'a message holding an array'  => array( array( 'es_ES' => array( 'login_social' => array( 'a' ) ) ) ),
			'a message stored empty'      => array( array( 'es_ES' => array( 'login_social' => '' ) ) ),
		);
	}

	/**
	 * What the templates actually print.
	 *
	 * The class is what the stylesheet colours and the attribute is what a
	 * test in any of the eight languages holds on to, so both are part of the
	 * contract and not incidental markup.
	 */
	public function test_the_notice_carries_its_tone_and_its_name(): void {
		diluxone_users_login_message_rewrite( 'login_social', get_locale(), 'Probá de nuevo.' );

		ob_start();
		diluxone_users_login_notice( 'login_social' );
		$html = (string) ob_get_clean();

		$this->assertStringContainsString( 'diluxone-users-notice--error', $html );
		$this->assertStringContainsString( 'data-diluxone-users-message="login_social"', $html );
		$this->assertStringContainsString( 'Probá de nuevo.', $html );

		ob_start();
		diluxone_users_login_notice( 'two_step_sent' );
		$ok = (string) ob_get_clean();

		$this->assertStringContainsString( 'diluxone-users-notice--ok', $ok );
	}

	/** Something after the sentence travels inside the same notice. */
	public function test_the_way_out_travels_with_the_message(): void {
		ob_start();
		diluxone_users_login_notice( 'register_taken', '<a href="https://example.test/">Entrar</a>' );
		$html = (string) ob_get_clean();

		$this->assertStringContainsString( '<a href="https://example.test/">Entrar</a>', $html );
		$this->assertSame( 1, substr_count( $html, '</p>' ), 'the link has to be inside the notice' );
	}

	/**
	 * A message nobody registered draws nothing at all.
	 *
	 * The alternative is an empty red box on the sign-in page, which is worse
	 * than saying nothing: it tells somebody that something went wrong and
	 * then refuses to say what.
	 */
	public function test_a_message_that_does_not_exist_draws_nothing(): void {
		ob_start();
		diluxone_users_login_notice( 'nobody_registered_this' );

		$this->assertSame( '', (string) ob_get_clean() );
	}

	/** An add-on can register its own, and it is rewritten like the rest. */
	public function test_an_add_on_can_register_a_message(): void {
		$extra = static function ( array $messages ): array {
			$messages['from_an_add_on'] = array(
				'group'   => 'login',
				'tone'    => 'error',
				'label'   => 'From an add-on',
				'when'    => 'When the add-on says so.',
				'shipped' => static fn(): string => 'The add-on’s own words.',
			);

			return $messages;
		};

		add_filter( 'diluxone_users_login_messages', $extra );

		$this->assertSame( 'The add-on’s own words.', diluxone_users_login_message( 'from_an_add_on' ) );

		diluxone_users_login_message_rewrite( 'from_an_add_on', get_locale(), 'Lo que dice el sitio.' );

		$this->assertSame( 'Lo que dice el sitio.', diluxone_users_login_message( 'from_an_add_on' ) );

		remove_filter( 'diluxone_users_login_messages', $extra );
	}
}
