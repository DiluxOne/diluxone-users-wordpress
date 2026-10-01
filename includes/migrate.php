<?php
/**
 * The move of a network's settings from each site to the network.
 *
 * Before the settings had scopes, every site of a network kept all of them in
 * its own options table, and two sites could ask for the second step in two
 * different ways. Once the plugin routes each setting by its scope (see
 * options-scope.php), the network's settings are read from the network — and
 * on a network that has been running for a while the network has none yet.
 * This is what gives it some.
 *
 * The main site's value becomes the network's, because it is the one the
 * network's administrator has been looking at. Where another site had set
 * something else, that is written down — not the value itself when it is a
 * credential — and shown once in Network Admin, and for good on the network's
 * Overview. The user fields are the one exception: a field is where people's
 * answers are kept, and a field one site had and the main site did not would
 * leave those answers with no field to show them, so every site's fields are
 * kept, the main site's definition winning where two sites define the same key.
 *
 * The old copies stay where they were until the plugin is deleted: nothing
 * reads them any more, and a move that deletes as it goes cannot be run twice.
 * It can be: every step asks before it writes, and the version is written last,
 * so an interrupted run picks up where it stopped and a finished one does
 * nothing.
 *
 * This file and uninstall.php are the only places that read the plugin's own
 * settings with the options API directly: moving a setting from one place to
 * the other is the one job that has to see both.
 *
 * @package DiluxOneUsers
 */

defined( 'ABSPATH' ) || exit;

/** The shape the network's settings are in. Raised when they move again. */
const DILUXONE_USERS_NETWORK_VERSION = 1;

/** Which shape the network's settings were last moved to. */
const DILUXONE_USERS_NETWORK_VERSION_OPTION = 'diluxone_users_network_version';

/** Where the move is, while it runs in batches. */
const DILUXONE_USERS_NETWORK_MIGRATING = 'diluxone_users_network_migrating';

/** What the move found the sites had set differently. */
const DILUXONE_USERS_NETWORK_CONFLICTS = 'diluxone_users_network_conflicts';

/** Whether the network was told about it. */
const DILUXONE_USERS_NETWORK_CONFLICTS_SEEN = 'diluxone_users_network_conflicts_seen';

/** The cron event that carries on with a network of many sites. */
const DILUXONE_USERS_NETWORK_MIGRATE_EVENT = 'diluxone_users_network_migrate';

/** How many sites are looked at in one go. */
const DILUXONE_USERS_NETWORK_BATCH = 100;

/**
 * The settings that move: every network setting that is a setting.
 *
 * Not the network's own bookkeeping, which is what this file writes, and not
 * the wipe on uninstall — see diluxone_users_network_take_main().
 *
 * @return array<int, string>
 */
function diluxone_users_network_moved_keys(): array {
	$skip = array(
		DILUXONE_USERS_NETWORK_VERSION_OPTION,
		DILUXONE_USERS_NETWORK_MIGRATING,
		DILUXONE_USERS_NETWORK_CONFLICTS,
		DILUXONE_USERS_NETWORK_CONFLICTS_SEEN,
		'diluxone_users_uninstall_wipe',
		// Confirming the membership policy is a decision for the network,
		// taken on its own screen: no site's copy of anything stands for it.
		'diluxone_users_membership_confirmed',
		// The log's own bookkeeping is about the network's table, which no
		// site had: a site's number for the shape of its own table says
		// nothing about it. See migrate-log.php.
		'diluxone_users_log_schema',
		'diluxone_users_log_moved',
		'diluxone_users_log_moving',
		'diluxone_users_log_kept',
	);

	return array_values(
		array_diff(
			array_keys( array_filter( diluxone_users_option_scopes(), static fn( string $scope ): bool => 'network' === $scope ) ),
			$skip
		)
	);
}

/**
 * The settings that are secrets, and are only ever noted as different.
 *
 * @return array<int, string>
 */
function diluxone_users_network_secret_keys(): array {
	return array( 'diluxone_users_sso' );
}

/** Whether the network's settings are already where they belong. */
function diluxone_users_network_migrated(): bool {
	return (int) diluxone_users_raw_get( DILUXONE_USERS_NETWORK_VERSION_OPTION, 0 ) >= DILUXONE_USERS_NETWORK_VERSION;
}

/**
 * Moves the network's settings, one batch of sites at a time.
 *
 * @param int $batch How many sites to look at in this call.
 * @return bool True when the move is finished, false when sites are left.
 */
function diluxone_users_network_migrate( int $batch = DILUXONE_USERS_NETWORK_BATCH ): bool {
	if ( ! diluxone_users_scoped_storage_active() || diluxone_users_network_migrated() ) {
		return true;
	}

	$main  = (int) get_main_site_id();
	$state = diluxone_users_raw_get( DILUXONE_USERS_NETWORK_MIGRATING, null );

	if ( ! is_array( $state ) ) {
		diluxone_users_network_take_main( $main );

		$state = array( 'offset' => 0 );
		diluxone_users_update_option( DILUXONE_USERS_NETWORK_MIGRATING, $state );
	}

	$sites = get_sites(
		array(
			'fields'       => 'ids',
			'number'       => max( 1, $batch ),
			'offset'       => (int) ( $state['offset'] ?? 0 ),
			'site__not_in' => array( $main ),
			'network_id'   => get_current_network_id(),
			'orderby'      => 'id',
			'order'        => 'ASC',
		)
	);

	foreach ( $sites as $site ) {
		diluxone_users_network_compare_site( (int) $site );
	}

	if ( count( $sites ) >= max( 1, $batch ) ) {
		$state['offset'] = (int) ( $state['offset'] ?? 0 ) + count( $sites );
		diluxone_users_update_option( DILUXONE_USERS_NETWORK_MIGRATING, $state );

		if ( ! wp_next_scheduled( DILUXONE_USERS_NETWORK_MIGRATE_EVENT ) ) {
			wp_schedule_single_event( time() + MINUTE_IN_SECONDS, DILUXONE_USERS_NETWORK_MIGRATE_EVENT );
		}

		return false;
	}

	diluxone_users_delete_option( DILUXONE_USERS_NETWORK_MIGRATING );

	// Last, so that a move interrupted anywhere above is a move not done.
	diluxone_users_update_option( DILUXONE_USERS_NETWORK_VERSION_OPTION, DILUXONE_USERS_NETWORK_VERSION );

	return true;
}

/** The next batch, from cron. */
function diluxone_users_network_migrate_batch(): void {
	diluxone_users_network_migrate();
}
add_action( DILUXONE_USERS_NETWORK_MIGRATE_EVENT, 'diluxone_users_network_migrate_batch' );

/**
 * Starts the moves on the first request that needs them.
 *
 * On `init` and first thing: anything that reads a network setting before the
 * move — the second step on a sign-in, the fields on a profile — would read
 * the plugin's default instead of what the network had, and the dashboard's
 * own seeding would write that default into the network before the main
 * site's value could get there. A network of many sites does its first batch
 * here, which is when the main site's values are taken, and the rest from
 * cron.
 *
 * Then the sites' activity logs, into the network's table (migrate-log.php),
 * which on a network that has just been switched on for everybody is also
 * what makes that table.
 */
function diluxone_users_network_migrate_when_needed(): void {
	// Not under WP-CLI: there `wp diluxone-users network migrate` is what does
	// it, to the end and saying what it found, and a move made on the way in
	// would leave the command nothing to report.
	if ( ( defined( 'WP_CLI' ) && WP_CLI ) || ! diluxone_users_scoped_storage_active() ) {
		return;
	}

	diluxone_users_network_settings_when_needed();
	diluxone_users_log_move_when_needed();
}
add_action( 'init', 'diluxone_users_network_migrate_when_needed', 0 );

/** The settings' move, unless it is done or cron is already carrying it on. */
function diluxone_users_network_settings_when_needed(): void {
	if ( diluxone_users_network_migrated() ) {
		return;
	}

	if ( is_array( diluxone_users_raw_get( DILUXONE_USERS_NETWORK_MIGRATING, null ) ) && wp_next_scheduled( DILUXONE_USERS_NETWORK_MIGRATE_EVENT ) ) {
		return;
	}

	diluxone_users_network_migrate();
}

/**
 * The main site's settings, made the network's where the network has none.
 *
 * The wipe on uninstall is not taken: it deletes everybody's data, and a box
 * one site ticked for itself is not the network deciding that. The network's
 * starts off; the main site's answer is written down beside the rest.
 *
 * @param int $main The main site.
 */
function diluxone_users_network_take_main( int $main ): void {
	foreach ( diluxone_users_network_moved_keys() as $key ) {
		$theirs = get_blog_option( $main, $key, null );

		if ( null !== $theirs && null === get_site_option( $key, null ) ) {
			add_site_option( $key, $theirs );
		}
	}

	diluxone_users_network_note_wipe( $main );
}

/**
 * One site's settings, held against the network's.
 *
 * @param int $site A site of the network other than the main one.
 */
function diluxone_users_network_compare_site( int $site ): void {
	$defaults = diluxone_users_option_defaults();

	foreach ( diluxone_users_network_moved_keys() as $key ) {
		$theirs = get_blog_option( $site, $key, null );

		if ( null === $theirs ) {
			continue;
		}

		if ( 'diluxone_users_fields' === $key ) {
			diluxone_users_network_join_fields( $site, (array) $theirs );
			continue;
		}

		$ours = get_site_option( $key, null );
		$ours = null === $ours ? ( $defaults[ $key ] ?? null ) : $ours;

		if ( diluxone_users_network_same( $theirs, $ours ) ) {
			continue;
		}

		$secret = in_array( $key, diluxone_users_network_secret_keys(), true );

		diluxone_users_network_conflict(
			array(
				'site' => $site,
				'key'  => $key,
				'kind' => $secret ? 'secret' : 'value',
				// Never the value of a credential: this list is shown on a
				// screen and kept in the database for as long as the plugin is.
				'was'  => $secret ? '' : diluxone_users_network_said( $theirs ),
			)
		);
	}

	diluxone_users_network_note_wipe( $site );
}

/**
 * Whether two stored values are the same setting.
 *
 * Loosely: an option read back from the database is a string where it was
 * written as a number, and 1 and "1" are one answer.
 *
 * @param mixed $a One value.
 * @param mixed $b The other.
 */
function diluxone_users_network_same( $a, $b ): bool {
	if ( is_scalar( $a ) && is_scalar( $b ) ) {
		return (string) $a === (string) $b;
	}

	return maybe_serialize( $a ) === maybe_serialize( $b );
}

/**
 * A stored value, short enough for one cell of a table.
 *
 * @param mixed $value The value.
 */
function diluxone_users_network_said( $value ): string {
	if ( is_array( $value ) ) {
		$flat = array();

		array_walk_recursive(
			$value,
			static function ( $one ) use ( &$flat ): void {
				if ( is_scalar( $one ) ) {
					$flat[] = (string) $one;
				}
			}
		);

		$value = implode( ', ', $flat );
	}

	$said = is_scalar( $value ) ? (string) $value : '';

	return mb_strlen( $said ) > 80 ? mb_substr( $said, 0, 79 ) . '…' : $said;
}

/**
 * The fields of one site, added to the network's.
 *
 * By key: a field the network does not have yet is added at the end, and one
 * it has keeps the definition it already had, the main site's, with the other
 * one written down.
 *
 * @param int                      $site   The site.
 * @param array<int|string, mixed> $theirs Its fields.
 */
function diluxone_users_network_join_fields( int $site, array $theirs ): void {
	$fields = get_site_option( 'diluxone_users_fields', null );
	$fields = is_array( $fields ) ? $fields : array();
	$keys   = array();

	foreach ( $fields as $i => $field ) {
		if ( is_array( $field ) && isset( $field['key'] ) ) {
			$keys[ (string) $field['key'] ] = $i;
		}
	}

	$added = false;

	foreach ( $theirs as $field ) {
		if ( ! is_array( $field ) || ! isset( $field['key'] ) || '' === (string) $field['key'] ) {
			continue;
		}

		$key = (string) $field['key'];

		if ( ! isset( $keys[ $key ] ) ) {
			$fields[]     = $field;
			$keys[ $key ] = count( $fields ) - 1;
			$added        = true;
			continue;
		}

		if ( ! diluxone_users_network_same( $field, $fields[ $keys[ $key ] ] ) ) {
			diluxone_users_network_conflict(
				array(
					'site'  => $site,
					'key'   => 'diluxone_users_fields',
					'kind'  => 'field',
					'field' => $key,
				)
			);
		}
	}

	if ( $added ) {
		update_site_option( 'diluxone_users_fields', array_values( $fields ) );
	}
}

/**
 * A site that had asked for everything to go on uninstall, written down.
 *
 * @param int $site The site.
 */
function diluxone_users_network_note_wipe( int $site ): void {
	if ( get_blog_option( $site, 'diluxone_users_uninstall_wipe', null ) ) {
		diluxone_users_network_conflict(
			array(
				'site' => $site,
				'key'  => 'diluxone_users_uninstall_wipe',
				'kind' => 'wipe',
			)
		);
	}
}

/**
 * Writes one difference down, once however many times the move runs.
 *
 * @param array<string, mixed> $conflict site, key, kind and optionally field and was.
 */
function diluxone_users_network_conflict( array $conflict ): void {
	$all = (array) diluxone_users_raw_get( DILUXONE_USERS_NETWORK_CONFLICTS, array() );
	$id  = (int) $conflict['site'] . '|' . (string) $conflict['key'] . '|' . (string) ( $conflict['field'] ?? '' );

	if ( isset( $all[ $id ] ) ) {
		return;
	}

	$all[ $id ] = $conflict;

	diluxone_users_update_option( DILUXONE_USERS_NETWORK_CONFLICTS, $all );
}
