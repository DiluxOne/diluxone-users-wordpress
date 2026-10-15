<?php
/**
 * Everything about keeping an account from being taken over.
 *
 * Its tabs come from the features that are installed: the second factor
 * registers its own, sessions registers its own, and so does an add-on's
 * feature. The screen itself knows about none of them, which is what lets any
 * one of them live in its own files and leave with them.
 *
 * If nothing registers anything, the screen does not appear in the menu at
 * all — an empty screen is worse than no screen.
 *
 * @package DiluxOneUsers
 */

defined( 'ABSPATH' ) || exit;

const DILUXONE_USERS_SECURITY = 'diluxone-users-security';

/** The security screen. */
function diluxone_users_screen_security(): void {
	diluxone_users_screen_panels( DILUXONE_USERS_SECURITY, diluxone_users_screens()[ DILUXONE_USERS_SECURITY ] );
}

/**
 * The summary tab, when there is anything to summarise.
 *
 * Registered after the features, at a later priority on the same hook, and
 * only if one of them registered something: this screen appears because a
 * feature put a tab on it, and a Summary of nothing would bring back the
 * empty screen the file above is written to avoid.
 *
 * Position 0 makes it the first tab and therefore the one the screen opens
 * on, which is the point of it — the same shape Access has, where the tab
 * that edits nothing is the one you land on.
 */
function diluxone_users_security_summary_panel(): void {
	// The registry itself and not the tabs drawn here: which of them belong
	// where the admin is looked at from is decided when they are drawn.
	if ( array() === diluxone_users_panel_registry( DILUXONE_USERS_SECURITY ) ) {
		return;
	}

	diluxone_users_register_panel(
		DILUXONE_USERS_SECURITY,
		'summary',
		array(
			'label'    => __( 'Summary', 'diluxone-users' ),
			'position' => 0,
			'render'   => 'diluxone_users_screen_security_summary',
			'form'     => false,
		)
	);
}
add_action( 'diluxone_users_register_panels', 'diluxone_users_security_summary_panel', 20 );

/**
 * Every line of the summary, from the features that are installed.
 *
 * The screen knows about none of them — that is the whole argument of this
 * file — so it cannot write down the rows either: a hard-coded row for a
 * feature that is not installed is a summary that lies, which is worse than
 * no summary. Each feature adds its own lines from its own file, at the
 * priority its tab sits at, so the table reads in the order of the tabs.
 *
 * A line is what it is, how it is doing, one sentence of detail and the tab
 * that changes it — the shape `diluxone_users_summary_table()` draws — and
 * every one of them is read from the saved settings, never from what the
 * defaults would have been.
 *
 * @return array<int, array<string, string>>
 */
function diluxone_users_security_rows(): array {
	/**
	 * Filters the lines of the security summary.
	 *
	 * @since 1.0.0
	 *
	 * @param array<int, array<string, string>> $rows Each: label, state, and optionally why, detail, url, change.
	 */
	$rows = (array) apply_filters( 'diluxone_users_security_summary', array() );

	$clean = array();

	foreach ( $rows as $row ) {
		$row = (array) $row;

		// A row with nothing to say and no state to say it in is not a row.
		// Whatever added it is welcome to try again next release.
		if ( ! isset( $row['label'], $row['state'] ) ) {
			continue;
		}

		$one = array(
			'label'  => (string) $row['label'],
			'state'  => (string) $row['state'],
			'why'    => (string) ( $row['why'] ?? '' ),
			'detail' => (string) ( $row['detail'] ?? '' ),
			'url'    => (string) ( $row['url'] ?? '' ),
		);

		// Left out rather than emptied: the table falls back to its own words
		// for the link, and an empty string is a link with nothing to click.
		if ( '' !== (string) ( $row['change'] ?? '' ) ) {
			$one['change'] = (string) $row['change'];
		}

		// The same for the pill's word: left out, the state's own is drawn.
		if ( '' !== (string) ( $row['word'] ?? '' ) ) {
			$one['word'] = (string) $row['word'];
		}

		$clean[] = $one;
	}

	return $clean;
}

/** How this site is protecting its accounts. Nothing is edited here. */
function diluxone_users_screen_security_summary(): void {
	diluxone_users_ui_aside_open();

	diluxone_users_intro( __( 'What is being asked of somebody who already reached the sign-in form, and how long they stay in once they are. Every line is read from the settings the other tabs write, and nothing is edited here.', 'diluxone-users' ) );

	/*
	 * Where passkeys would add their line, the line itself: the summary is
	 * where a feature of this screen says how it stands, so the one Users+
	 * Pro brings is one more row of it, with the badge where the state goes,
	 * and not a tab of its own for an upsell. Users+ Pro answers false to it
	 * once it brings them.
	 */
	diluxone_users_summary_table(
		array_merge(
			diluxone_users_security_rows(),
			array_filter(
				array(
					diluxone_users_ui_pro_row(
						array(
							'where'  => 'security/summary/more',
							'screen' => 'security',
							'title'  => __( 'Passkeys', 'diluxone-users' ),
							'text'   => __( 'Users+ Pro adds passkeys: which ones are accepted, whether the fingerprint, the face or the PIN is required, and who has one.', 'diluxone-users' ),
						)
					),
				)
			)
		)
	);

	// No rail: the table is the whole of what this tab reports. Which doors
	// there are at all is Access's question, and its own tab says so.
	diluxone_users_ui_aside_close( static function (): void {} );
}
