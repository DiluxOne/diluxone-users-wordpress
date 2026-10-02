<?php
/**
 * What the settings coverage tests share: who sends a form, from where, and
 * how a handler ended.
 *
 * A trait and not a base class, so each test still extends IntegrationTestCase
 * and gets its clean-up. A test using it calls cover_settings_reset() in its
 * own tearDown(), before the parent's.
 */

namespace Tests\Integration\Support;

trait CoverSettingsSupport {

	/** @var array<int, int> People made super admin by the test, taken back after it. */
	private array $cover_settings_supers = array();

	/** Puts back what the helpers changed. */
	protected function cover_settings_reset(): void {
		while ( is_multisite() && ms_is_switched() ) {
			restore_current_blog();
		}

		foreach ( $this->cover_settings_supers as $user ) {
			revoke_super_admin( $user );
		}

		$this->cover_settings_supers = array();

		// A save button a drawing queued and nothing printed would land on
		// the next test's screen.
		diluxone_users_ui_save_queue();

		$_GET     = array();
		$_POST    = array();
		$_REQUEST = array();
	}

	/** The test only makes sense on a network; its single-site half is named. */
	protected function network_only( string $counterpart ): void {
		if ( ! is_multisite() ) {
			$this->markTestSkipped( 'A network case; on a single site the counterpart is ' . $counterpart . '.' );
		}
	}

	/** The test only makes sense on a single site; its network half is named. */
	protected function single_only( string $counterpart ): void {
		if ( is_multisite() ) {
			$this->markTestSkipped( 'A single-site case; on a network the counterpart is ' . $counterpart . '.' );
		}
	}

	/**
	 * Whoever administers everything here, signed in: the administrator of a
	 * single site, the super admin of a network.
	 */
	protected function the_admin(): int {
		$user = $this->make_user( 'administrator' );

		if ( is_multisite() ) {
			grant_super_admin( $user );
			$this->cover_settings_supers[] = $user;
		}

		wp_set_current_user( $user );

		return $user;
	}

	/** A network's setting is written from Network Admin; on a single site nothing changes. */
	protected function where_the_network_is_set(): void {
		if ( is_multisite() ) {
			$this->in_network_admin();
		}
	}

	/**
	 * Somebody who may not save the screen: a subscriber, or on a network's
	 * own screen an administrator of this site only.
	 */
	protected function not_allowed( bool $network_screen = false ): int {
		$user = $this->make_user( $network_screen && is_multisite() ? 'administrator' : 'subscriber' );
		wp_set_current_user( $user );

		return $user;
	}

	/**
	 * A panel's form sent by whoever is signed in, with a nonce made for them.
	 *
	 * @param array<string, mixed> $post
	 * @param array<string, mixed> $get
	 */
	protected function send_panel( string $screen, array $post, array $get = array() ): void {
		$this->postAs( get_current_user_id(), $post + array( 'diluxone_users_panel_nonce' => wp_create_nonce( 'diluxone_users_panel_' . $screen ) ), $get );
	}

	/**
	 * What a callable prints.
	 *
	 * @param mixed ...$args
	 */
	protected function draw( callable $what, ...$args ): string {
		ob_start();

		try {
			$what( ...$args );
		} finally {
			$html = (string) ob_get_clean();
		}

		return $html;
	}

	/**
	 * How a handler ended: 'redirect' (and where to), 'died' (and why), or
	 * 'returned' (and what).
	 *
	 * @return array{0: string, 1: mixed}
	 */
	protected function ended( callable $handler ): array {
		ob_start();

		try {
			$value = $handler();
		} catch ( RedirectException $e ) {
			return array( 'redirect', $e->url );
		} catch ( \WPAjaxDieContinueException $e ) {
			return array( 'died', $e->getMessage() );
		} finally {
			ob_end_clean();
		}

		return array( 'returned', $value );
	}
}
