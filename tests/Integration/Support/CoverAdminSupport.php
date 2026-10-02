<?php
/**
 * What the admin coverage tests share: who is looking, from where, and how a
 * handler ended.
 *
 * A trait and not a base class, so each test still extends IntegrationTestCase
 * and gets its clean-up. A test using it calls cover_admin_reset() in its own
 * tearDown(), before the parent's.
 */

namespace Tests\Integration\Support;

trait CoverAdminSupport {

	/** @var array<int, int> People made super admin by the test, taken back after it. */
	private array $cover_supers = array();

	/** @var array<string, mixed> What $_GET held before the test changed it. */
	private array $cover_get = array();

	/** Puts back what the helpers changed. */
	protected function cover_admin_reset(): void {
		while ( is_multisite() && ms_is_switched() ) {
			restore_current_blog();
		}

		foreach ( $this->cover_supers as $user ) {
			revoke_super_admin( $user );
		}

		$this->cover_supers = array();

		// A button a drawing queued and nothing printed would land on the
		// next test's screen.
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
			$this->cover_supers[] = $user;
		}

		wp_set_current_user( $user );

		return $user;
	}

	/** An administrator of this site and nothing more: on a network, not a super admin. */
	protected function site_admin(): int {
		$user = $this->make_user( 'administrator' );
		wp_set_current_user( $user );

		return $user;
	}

	/** A network screen is looked at from Network Admin; on a single site nothing changes. */
	protected function where_network_screens_are(): void {
		if ( is_multisite() ) {
			$this->in_network_admin();
		}
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
	 * How a handler ended: 'redirect' (and where to), 'died', or 'returned'.
	 *
	 * @return array{0: string, 1: string}
	 */
	protected function ended( callable $handler ): array {
		ob_start();

		try {
			$handler();
		} catch ( RedirectException $e ) {
			return array( 'redirect', $e->url );
		} catch ( \WPAjaxDieContinueException $e ) {
			return array( 'died', $e->getMessage() );
		} finally {
			ob_end_clean();
		}

		return array( 'returned', '' );
	}
}
