<?php
/**
 * Stand-ins for login_header() and login_footer().
 *
 * WordPress declares both inside wp-login.php itself, which cannot be loaded
 * without running the whole sign-in request, so a screen the plugin draws on
 * wp-login.php has nothing to call them from inside the suite. These draw what
 * a test needs to read — the title and every message WordPress would show,
 * with its kind — and the footer throws, because the screen exits right
 * after it. Declared only when WordPress's own are not there; a test that
 * relies on them checks they are these (CoverSignInScreensTest).
 */

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound -- they stand in for WordPress's own functions, under WordPress's own names.

if ( ! function_exists( 'login_header' ) ) {
	/**
	 * @param string|null   $title
	 * @param string        $message
	 * @param WP_Error|null $wp_error
	 */
	function login_header( $title = null, $message = '', $wp_error = null ): void {
		echo '<title>' . esc_html( (string) $title ) . '</title>';

		if ( $wp_error instanceof WP_Error ) {
			foreach ( $wp_error->get_error_codes() as $code ) {
				printf(
					'<div data-code="%1$s" data-kind="%2$s">%3$s</div>',
					esc_attr( (string) $code ),
					esc_attr( (string) $wp_error->get_error_data( $code ) ),
					$wp_error->get_error_message( $code ) // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- printed as wp-login.php prints it: the plugin escaped it when it added it.
				);
			}
		}
	}
}

if ( ! function_exists( 'login_footer' ) ) {
	/** @param string $input_id */
	function login_footer( $input_id = '' ): void {
		echo '<footer data-focus="' . esc_attr( (string) $input_id ) . '"></footer>';

		throw new \Tests\Integration\Support\CoverSignInStop( 'login_footer' );
	}
}
