<?php
/**
 * Does this site send e-mail?
 *
 * The question looks silly and it is the most important one in the plugin:
 * the sign-in link, the second-factor code and the confirmation for deleting
 * an account are all e-mails. If they do not go out, the site has no way in
 * and nobody finds out until somebody cannot get in.
 *
 * The temptation is to ask `has_filter('phpmailer_init')`: if somebody hooked
 * there, a server is configured. That is a lie — a mail plugin that is active
 * but badly configured hooks all the same — and it is exactly the mistake
 * that made this screen say "ready" while nothing was going out.
 *
 * So nothing is guessed: what really happened the last time WordPress tried
 * to send something is recorded, and a button is offered to test it.
 *
 * @package DiluxOneUsers
 */

defined( 'ABSPATH' ) || exit;

/**
 * Records that a send went through.
 *
 * Any send, the site's and other plugins' alike: the question is whether this
 * site delivers mail at all, and every send answers it. But a site that sends
 * a thousand mails an hour must not write a thousand rows for it — the answer
 * is written when it changes, and refreshed at most once an hour.
 */
function diluxone_users_mail_ok(): void {
	$last = diluxone_users_raw_get( 'diluxone_users_mail_last', false );

	if ( is_array( $last ) && ! empty( $last['ok'] ) && time() - (int) ( $last['time'] ?? 0 ) < HOUR_IN_SECONDS ) {
		return;
	}

	// Not autoloaded: two screens read it, no page needs it.
	diluxone_users_update_option(
		'diluxone_users_mail_last',
		array(
			'ok'    => 1,
			'time'  => time(),
			'error' => '',
		),
		false
	);
}

/**
 * Whether the plugin sends mail of its own on this site.
 *
 * The e-mail link, a second-step code by e-mail or one of its notices. Until
 * one of them is on, the site's mail is none of the plugin's business and it
 * writes nothing down about it; the test on Maintenance › Tools still answers.
 */
function diluxone_users_mail_watched(): bool {
	return diluxone_users_login_has_link()
		|| diluxone_users_notice_2fa_email()
		|| 'never' !== diluxone_users_notice_policy( 'diluxone_users_notify_login' )
		|| 'never' !== diluxone_users_notice_policy( 'diluxone_users_notify_security' );
}

/** A send went through, heard from WordPress. */
function diluxone_users_mail_heard_ok(): void {
	if ( diluxone_users_mail_watched() ) {
		diluxone_users_mail_ok();
	}
}
add_action( 'wp_mail_succeeded', 'diluxone_users_mail_heard_ok' );

/**
 * Records that a send failed, with the reason.
 *
 * @param WP_Error $error
 */
function diluxone_users_mail_failed( $error ): void {
	// The mailer's own words, short: they are what an administrator needs to
	// fix it, and they are only shown on the dashboard.
	diluxone_users_update_option(
		'diluxone_users_mail_last',
		array(
			'ok'    => 0,
			'time'  => time(),
			'error' => mb_substr( sanitize_text_field( $error->get_error_message() ), 0, 200 ),
		),
		false
	);
}

/**
 * A send failed, heard from WordPress.
 *
 * @param WP_Error $error
 */
function diluxone_users_mail_heard_failed( $error ): void {
	if ( $error instanceof WP_Error && diluxone_users_mail_watched() ) {
		diluxone_users_mail_failed( $error );
	}
}
add_action( 'wp_mail_failed', 'diluxone_users_mail_heard_failed' );

/**
 * What is known about outgoing mail.
 *
 * @return array{state: string, time: int, error: string}
 *         state: 'ok', 'fail' or 'unknown'.
 */
function diluxone_users_mail_status(): array {
	$last = diluxone_users_raw_get( 'diluxone_users_mail_last', false );

	if ( ! is_array( $last ) ) {
		return array(
			'state' => 'unknown',
			'time'  => 0,
			'error' => '',
		);
	}

	return array(
		'state' => ! empty( $last['ok'] ) ? 'ok' : 'fail',
		'time'  => (int) ( $last['time'] ?? 0 ),
		'error' => (string) ( $last['error'] ?? '' ),
	);
}

/**
 * Can an e-mail be counted on to arrive?
 *
 * "Not tested yet" counts as yes: there is no reason to frighten anybody with
 * a suspicion, and the first real send will tell the truth.
 */
function diluxone_users_mail_works(): bool {
	return 'fail' !== diluxone_users_mail_status()['state'];
}
