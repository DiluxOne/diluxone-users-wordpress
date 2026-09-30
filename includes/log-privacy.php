<?php
/**
 * The log, under WordPress's own data rules.
 *
 * This is the file that makes the feature honest. The table keeps addresses,
 * user agents and a history of what somebody did, which is personal data by
 * anybody's definition — and a plugin that stores that and does not answer
 * WordPress's own "export everything about this person" and "erase everything
 * about this person" is a plugin whose data is invisible to the two tools a
 * site owner has for answering a legal request. It is also, for what it is
 * worth, the thing wordpress.org's review asks about first.
 *
 * So both are answered here, in pages, the way WordPress asks for them: a site
 * with a hundred thousand rows for one account must not try to build them into
 * one response and time out halfway.
 *
 * @package DiluxOneUsers
 */

defined( 'ABSPATH' ) || exit;

/** How many rows one page of an export carries. */
const DILUXONE_USERS_LOG_EXPORT_PAGE = 250;

/**
 * Adds the log to what WordPress exports.
 *
 * @param array<string, array<string, mixed>> $exporters
 * @return array<string, array<string, mixed>>
 */
function diluxone_users_log_exporter( array $exporters ): array {
	$exporters['diluxone-users-log'] = array(
		'exporter_friendly_name' => __( 'Activity log', 'diluxone-users' ),
		'callback'               => 'diluxone_users_log_export',
	);

	return $exporters;
}
add_filter( 'wp_privacy_personal_data_exporters', 'diluxone_users_log_exporter' );

/**
 * One page of somebody's rows, as WordPress wants them.
 *
 * `done` is false while a full page came back, which is how WordPress knows to
 * ask again. Asking one page too many at the end costs one query and is the
 * only way to be sure: a person whose row count is exactly the page size would
 * otherwise lose nothing and be reported as finished a page early, which is
 * the same bug either way round.
 *
 * @return array{data: array<int, array<string, mixed>>, done: bool}
 */
function diluxone_users_log_export( string $email, int $page = 1 ): array {
	$user = get_user_by( 'email', $email );

	if ( ! $user instanceof WP_User ) {
		return array(
			'data' => array(),
			'done' => true,
		);
	}

	$rows  = diluxone_users_log_of( (int) $user->ID, max( 1, $page ), DILUXONE_USERS_LOG_EXPORT_PAGE );
	$full  = count( $rows ) >= DILUXONE_USERS_LOG_EXPORT_PAGE;
	$items = array();

	// The refused sign-ins that typed this person's name or address are filed
	// under nobody, but they are data about this person all the same: they
	// go out with the first page.
	if ( 1 === max( 1, $page ) ) {
		$rows = array_merge( $rows, diluxone_users_log_tried( array( $user->user_login, $user->user_email ) ) );
	}

	foreach ( $rows as $row ) {
		$data = array(
			array(
				'name'  => __( 'When', 'diluxone-users' ),
				'value' => (string) wp_date( 'Y-m-d H:i:s', $row['happened'] ),
			),
			array(
				'name'  => __( 'What happened', 'diluxone-users' ),
				'value' => diluxone_users_log_label( $row['event'] ),
			),
			array(
				'name'  => __( 'IP', 'diluxone-users' ),
				'value' => $row['ip'],
			),
			array(
				'name'  => __( 'Device', 'diluxone-users' ),
				'value' => $row['agent'],
			),
		);

		foreach ( $row['detail'] as $key => $value ) {
			$data[] = array(
				'name'  => (string) $key,
				'value' => (string) $value,
			);
		}

		$items[] = array(
			'group_id'    => 'diluxone-users-log',
			'group_label' => __( 'Activity log', 'diluxone-users' ),
			'item_id'     => 'diluxone-users-log-' . $row['id'],
			'data'        => $data,
		);
	}

	return array(
		'data' => $items,
		'done' => ! $full,
	);
}

/**
 * Adds the log to what WordPress erases.
 *
 * @param array<string, array<string, mixed>> $erasers
 * @return array<string, array<string, mixed>>
 */
function diluxone_users_log_eraser( array $erasers ): array {
	$erasers['diluxone-users-log'] = array(
		'eraser_friendly_name' => __( 'Activity log', 'diluxone-users' ),
		'callback'             => 'diluxone_users_log_erase',
	);

	return $erasers;
}
add_filter( 'wp_privacy_personal_data_erasers', 'diluxone_users_log_eraser' );

/**
 * Somebody's rows, gone.
 *
 * All of them, in one pass, and `done` is true: the delete goes by the index
 * on the account and takes no longer than the person has rows, which is orders
 * of magnitude less than the export — the export has to build a structure per
 * row and hand it back through the browser.
 *
 * The refused sign-ins are deliberately not touched, and it is worth being
 * clear about why rather than leaving it as an omission. A row that says a
 * password was typed wrong belongs to no account — that is the whole point of
 * filing it under nobody — and there is no way to tell one that was this
 * person mistyping from one that was somebody trying to get into their account.
 * Erasing them on request would hand whoever asked a way to clear the evidence
 * of their own attempts. So they are kept, and the answer says so: WordPress
 * shows the site owner what was retained and why, which is what the law asks
 * of a retention and what a silent `false` would hide. They leave with the
 * rest of the log when their retention runs out.
 *
 * @return array{items_removed: bool, items_retained: bool, messages: array<int, string>, done: bool}
 */
function diluxone_users_log_erase( string $email, int $page = 1 ): array {
	$user = get_user_by( 'email', $email );

	if ( ! $user instanceof WP_User ) {
		return array(
			'items_removed'  => false,
			'items_retained' => false,
			'messages'       => array(),
			'done'           => true,
		);
	}

	$gone = diluxone_users_log_forget( (int) $user->ID );
	$kept = count( diluxone_users_log_tried( array( $user->user_login, $user->user_email ) ) );

	return array(
		'items_removed'  => $gone > 0,
		'items_retained' => $kept > 0,
		'messages'       => $kept > 0 ? array(
			sprintf(
				/* translators: %d: how many refused sign-in attempts are kept. */
				_n(
					'%d refused sign-in attempt that typed this person\'s username or e-mail was kept: it is security evidence, and it is deleted when the activity log\'s retention runs out.',
					'%d refused sign-in attempts that typed this person\'s username or e-mail were kept: they are security evidence, and they are deleted when the activity log\'s retention runs out.',
					$kept,
					'diluxone-users'
				),
				$kept
			),
		) : array(),
		'done'           => true,
	);
}

/**
 * An account that is deleted takes its rows with it.
 *
 * Nothing legal about this one: it is the orphan problem. A log row pointing at
 * an id nobody has is a row that can never be exported, never erased on
 * request and never explained, and a table full of them grows for ever. The
 * privacy tools above are for the person who asks; this is for the account
 * that simply goes.
 */
function diluxone_users_log_user_deleted( int $user_id ): void {
	diluxone_users_log_forget( $user_id );
}
add_action( 'deleted_user', 'diluxone_users_log_user_deleted' );

/**
 * The same, for an account deleted from a whole network.
 *
 * `deleted_user` fires on the site the deletion was made from; the person's
 * rows are in the log of every site they signed in to.
 *
 * @param int $user_id The account being deleted.
 */
function diluxone_users_log_user_deleted_everywhere( $user_id ): void {
	foreach ( get_sites(
		array(
			'fields' => 'ids',
			'number' => 0,
		)
	) as $site ) {
		switch_to_blog( (int) $site );

		if ( DILUXONE_USERS_LOG_SCHEMA === (int) diluxone_users_raw_get( DILUXONE_USERS_LOG_SCHEMA_OPTION ) ) {
			diluxone_users_log_forget( (int) $user_id );
		}

		restore_current_blog();
	}
}
add_action( 'wpmu_delete_user', 'diluxone_users_log_user_deleted_everywhere' );
