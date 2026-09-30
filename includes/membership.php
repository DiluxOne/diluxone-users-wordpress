<?php
/**
 * Who is a member of which site of a network: the network's policy.
 *
 * On a network an account is the network's and a role is each site's, so an
 * account that exists is not yet a member of anything but the site it was
 * made on. Which sites it becomes a member of is one decision for the whole
 * network, taken in Network Admin › Membership, with three answers:
 *
 *   - 'all' (the default): every account is a member of every live site of
 *     the network. A new account is added to every site, a new site gets
 *     every account, and signing in is the safety net that adds the person to
 *     the site they are on and to the hub, for whatever the first two missed.
 *   - 'click': an account is a member of the hub, where it lives; on any other
 *     site the person, signed in, presses "Join this site".
 *   - 'invite': an account is a member of the hub; only an administrator adds
 *     somebody to any other site.
 *
 * The role on each site is that site's: on the hub the one the plugin gives
 * whoever registers, on any other site that site's own "New User Default
 * Role" (WordPress's `default_role`), and subscriber when either would hand
 * out more than a self-registered account may have. A super admin reaches
 * every site already and is never added. An archived, spam or deleted site
 * gets nobody. And an administrator who took somebody off a site is obeyed:
 * the removal is written down on the person, and nothing here adds them back
 * until an administrator does. What WordPress removes on its own — every
 * member of a site being deleted, the main site's membership of somebody
 * activating an invitation to another site, an account being deleted — and
 * what this plugin removes itself, closing an account, is not an
 * administrator's decision and is not written down.
 *
 * A few additions are made on the spot. Past a threshold (50, filterable)
 * they are a job in a queue, a network setting, worked through by WP-Cron in
 * batches under a lock, with its progress on the Membership screen; the same
 * queue carries "Sync everyone now" and `wp diluxone-users network membership
 * sync`.
 *
 * On a single site none of this exists: everybody who has an account is a
 * member of the only site there is.
 *
 * @package DiluxOneUsers
 */

defined( 'ABSPATH' ) || exit;

/** The network setting: 'all', 'click' or 'invite'. */
const DILUXONE_USERS_MEMBERSHIP = 'diluxone_users_membership';

/** The network setting that holds the jobs still to do. */
const DILUXONE_USERS_MEMBERSHIP_QUEUE = 'diluxone_users_membership_queue';

/** The user meta listing the sites an administrator took somebody off. */
const DILUXONE_USERS_MEMBERSHIP_REMOVED = 'diluxone_users_removed_from';

/** The cron event that works through the queue. */
const DILUXONE_USERS_MEMBERSHIP_EVENT = 'diluxone_users_membership_drain';

/** The lock one run of the queue holds, a site transient. */
const DILUXONE_USERS_MEMBERSHIP_LOCK = 'diluxone_users_membership_lock';

/* ── Pure answers ──────────────────────────────────────────────────── */

/**
 * The three policies, in the order the screen offers them.
 *
 * @return array<int, string>
 */
function diluxone_users_membership_policies(): array {
	return array( 'all', 'click', 'invite' );
}

/**
 * A stored policy, read: anything that is not one of the three is 'all'.
 *
 * @param mixed $stored What the setting holds.
 */
function diluxone_users_membership_policy( $stored ): string {
	return is_string( $stored ) && in_array( $stored, diluxone_users_membership_policies(), true ) ? $stored : 'all';
}

/**
 * The role somebody is added with, given what the site says.
 *
 * @param bool   $hub        Whether the site is the hub.
 * @param string $hub_role   The role the hub gives whoever registers, already checked.
 * @param string $site_role  The site's own `default_role`.
 * @param bool   $site_ok    Whether that role exists there and is one a self-registered account may have.
 */
function diluxone_users_membership_pick_role( bool $hub, string $hub_role, string $site_role, bool $site_ok ): string {
	if ( $hub ) {
		return '' !== $hub_role ? $hub_role : 'subscriber';
	}

	return '' !== $site_role && $site_ok ? $site_role : 'subscriber';
}

/**
 * Is a site one people are added to: not archived, not spam, not deleted?
 *
 * @param array<string, mixed> $flags archived, spam and deleted, as WordPress stores them.
 */
function diluxone_users_membership_site_live( array $flags ): bool {
	foreach ( array( 'archived', 'spam', 'deleted' ) as $flag ) {
		if ( ! empty( $flags[ $flag ] ) && '0' !== (string) $flags[ $flag ] ) {
			return false;
		}
	}

	return true;
}

/**
 * The list of sites somebody was taken off, with one more.
 *
 * @param array<int, mixed> $sites What the user meta held.
 * @param int               $site  The site.
 * @return array<int, int>
 */
function diluxone_users_membership_removed_with( array $sites, int $site ): array {
	$sites   = array_map( 'intval', $sites );
	$sites[] = $site;

	return array_values( array_unique( array_filter( $sites, static fn( int $one ): bool => $one > 0 ) ) );
}

/**
 * The same list, without one site.
 *
 * @param array<int, mixed> $sites What the user meta held.
 * @param int               $site  The site.
 * @return array<int, int>
 */
function diluxone_users_membership_removed_without( array $sites, int $site ): array {
	return array_values( array_filter( array_map( 'intval', $sites ), static fn( int $one ): bool => $one > 0 && $one !== $site ) );
}

/**
 * Whether a removal is an administrator's decision, to be written down.
 *
 * @param bool $quiet    The plugin itself is removing (closing an account).
 * @param bool $core     WordPress is removing on its own: a site being deleted, an invitation being activated.
 * @param bool $deleting The account itself is being deleted.
 */
function diluxone_users_membership_removal_counts( bool $quiet, bool $core, bool $deleting ): bool {
	return ! $quiet && ! $core && ! $deleting;
}

/**
 * Whether a number of additions is made on the spot, or queued.
 *
 * @param int $pairs     How many additions (a person on a site is one).
 * @param int $threshold The most made on the spot.
 */
function diluxone_users_membership_inline( int $pairs, int $threshold ): bool {
	return $pairs <= max( 0, $threshold );
}

/**
 * How many people one batch of a "everybody on every site" job takes.
 *
 * As many as fit in a batch of additions, and never none: a network with
 * more sites than a batch takes one person at a time.
 *
 * @param int $batch How many additions a batch makes.
 * @param int $sites How many live sites there are.
 */
function diluxone_users_membership_people_per_batch( int $batch, int $sites ): int {
	return max( 1, intdiv( max( 1, $batch ), max( 1, $sites ) ) );
}

/**
 * How far a job has got, as a whole percentage.
 *
 * @param int $done  Additions looked at.
 * @param int $total Additions the job was expected to look at.
 */
function diluxone_users_membership_percent( int $done, int $total ): int {
	if ( $total <= 0 ) {
		return 100;
	}

	return (int) min( 100, floor( 100 * max( 0, $done ) / $total ) );
}

/* ── The policy, and who may join where ─────────────────────────────── */

/** This network's policy. On a single site, where it means nothing, 'all'. */
function diluxone_users_membership(): string {
	return is_multisite() ? diluxone_users_membership_policy( diluxone_users_option( DILUXONE_USERS_MEMBERSHIP ) ) : 'all';
}

/**
 * Is a site of this network one people are added to?
 *
 * @param int $site_id The site.
 */
function diluxone_users_membership_site_takes( int $site_id ): bool {
	$site = get_site( $site_id );

	if ( ! $site instanceof WP_Site || (int) $site->network_id !== (int) get_current_network_id() ) {
		return false;
	}

	return diluxone_users_membership_site_live(
		array(
			'archived' => $site->archived,
			'spam'     => $site->spam,
			'deleted'  => $site->deleted,
		)
	);
}

/**
 * The sites an administrator took somebody off.
 *
 * @param int $user_id Who.
 * @return array<int, int>
 */
function diluxone_users_membership_removed( int $user_id ): array {
	$sites = get_user_meta( $user_id, DILUXONE_USERS_MEMBERSHIP_REMOVED, true );

	return is_array( $sites ) ? diluxone_users_membership_removed_without( $sites, 0 ) : array();
}

/**
 * May this person be added to this site, by anything the plugin does?
 *
 * Not somebody already there, not a super admin, not a closed account, not
 * somebody an administrator took off it, and only on a live site of this
 * network. On a single site WordPress counts every account a member of the
 * site, so nobody is ever added there.
 *
 * @param int $user_id Who.
 * @param int $site_id Where.
 */
function diluxone_users_membership_may_join( int $user_id, int $site_id ): bool {
	if ( $user_id <= 0 || $site_id <= 0 ) {
		return false;
	}

	$user = get_userdata( $user_id );

	if ( ! $user instanceof WP_User || is_super_admin( $user_id ) || get_user_meta( $user_id, 'diluxone_users_closed', true ) ) {
		return false;
	}

	if ( in_array( $site_id, diluxone_users_membership_removed( $user_id ), true ) ) {
		return false;
	}

	return ! is_user_member_of_blog( $user_id, $site_id ) && diluxone_users_membership_site_takes( $site_id );
}

/**
 * The role somebody is added to a site with.
 *
 * Asked of the site each time rather than kept: its administrator may change
 * the default role, and the hub's registration role is a setting.
 *
 * @param int $site_id The site.
 */
function diluxone_users_membership_role( int $site_id ): string {
	$hub = diluxone_users_hub_site_id() === $site_id;

	switch_to_blog( $site_id );
	$site_role = sanitize_key( (string) get_option( 'default_role', 'subscriber' ) );
	$site_ok   = diluxone_users_role_self_serve( $site_role );
	$hub_role  = $hub ? diluxone_users_register_role() : '';
	restore_current_blog();

	return diluxone_users_membership_pick_role( $hub, $hub_role, $site_role, $site_ok );
}

/**
 * Runs something while the plugin itself changes memberships.
 *
 * What it removes on the way — closing an account — is not an administrator's
 * decision and is not written down as one.
 *
 * @param callable $work What to do.
 * @return mixed What it returned.
 */
function diluxone_users_membership_quietly( callable $work ) {
	diluxone_users_membership_quiet( true );

	try {
		return $work();
	} finally {
		diluxone_users_membership_quiet( false );
	}
}

/**
 * Whether the plugin is changing memberships itself right now.
 *
 * @param bool|null $set True or false to change it, null to ask.
 */
function diluxone_users_membership_quiet( ?bool $set = null ): bool {
	static $depth = 0;

	if ( true === $set ) {
		++$depth;
	} elseif ( false === $set ) {
		$depth = max( 0, $depth - 1 );
	}

	return $depth > 0;
}

/**
 * Adds somebody to one site, if they may join it.
 *
 * Every addition the plugin makes goes through here, whatever made it: the
 * policy, a sign-in, a press of "Join this site".
 *
 * @param int    $user_id Who.
 * @param int    $site_id Where.
 * @param string $how     What made it: 'account', 'site', 'sync', 'sign-in' or 'click'.
 * @return bool Whether they are a member now and were not before.
 */
function diluxone_users_membership_add( int $user_id, int $site_id, string $how ): bool {
	if ( ! diluxone_users_membership_may_join( $user_id, $site_id ) ) {
		return false;
	}

	$role  = diluxone_users_membership_role( $site_id );
	$added = diluxone_users_membership_quietly( static fn() => add_user_to_blog( $site_id, $user_id, $role ) );

	if ( true !== $added ) {
		return false;
	}

	diluxone_users_membership_announce( $user_id, $site_id, $role, $how );

	return true;
}

/**
 * Says, once per request, that somebody became a member of a site.
 *
 * Once: WordPress takes a new account's role away on the site it was made on
 * right after making it (wpmu_create_user()), and the addition is made again
 * then — that is still one person joining one site.
 *
 * @param int    $user_id Who.
 * @param int    $site_id Where.
 * @param string $role    With which role.
 * @param string $how     What made it.
 */
function diluxone_users_membership_announce( int $user_id, int $site_id, string $role, string $how ): void {
	static $said = array();

	if ( isset( $said[ $user_id . ':' . $site_id ] ) ) {
		return;
	}

	$said[ $user_id . ':' . $site_id ] = true;

	/**
	 * Fires when the plugin made somebody a member of a site of the network.
	 *
	 * @since 1.0.0
	 *
	 * @param int    $user_id Who.
	 * @param int    $site_id The site.
	 * @param string $role    The role they were given there.
	 * @param string $how     What made it: 'account' (a new account, under the "every site" policy),
	 *                        'site' (a new site), 'sync', 'sign-in' or 'click' ("Join this site").
	 */
	do_action( 'diluxone_users_member_added', $user_id, $site_id, $role, $how );
}

/* ── Removals ──────────────────────────────────────────────────────── */

/**
 * The accounts being deleted in this request, whose removals are not decisions.
 *
 * @param int $user_id One more, or 0 to only ask.
 * @return array<int, true>
 */
function diluxone_users_membership_deleting( int $user_id = 0 ): array {
	static $deleting = array();

	if ( $user_id > 0 ) {
		$deleting[ $user_id ] = true;
	}

	return $deleting;
}

/**
 * An account is being deleted from the network: its removals are not decisions.
 *
 * @param int $user_id Who.
 */
function diluxone_users_membership_user_going( $user_id ): void {
	diluxone_users_membership_deleting( (int) $user_id );
}
add_action( 'wpmu_delete_user', 'diluxone_users_membership_user_going' );

/**
 * Writes down that an administrator took somebody off a site.
 *
 * @param int $user_id Who.
 * @param int $site_id Which site.
 */
function diluxone_users_membership_removed_now( $user_id, $site_id ): void {
	$user_id = (int) $user_id;
	$site_id = (int) $site_id;

	if ( ! is_multisite() || $user_id <= 0 || $site_id <= 0 ) {
		return;
	}

	$core = doing_action( 'wp_uninitialize_site' ) || doing_action( 'wpmu_activate_user' );

	if ( ! diluxone_users_membership_removal_counts( diluxone_users_membership_quiet(), $core, isset( diluxone_users_membership_deleting()[ $user_id ] ) ) ) {
		return;
	}

	update_user_meta( $user_id, DILUXONE_USERS_MEMBERSHIP_REMOVED, diluxone_users_membership_removed_with( diluxone_users_membership_removed( $user_id ), $site_id ) );
}
add_action( 'remove_user_from_blog', 'diluxone_users_membership_removed_now', 10, 2 );

/**
 * Somebody was added to a site: whatever removal was written down there is over.
 *
 * The plugin never adds somebody an administrator took off, so an addition to
 * a site they were taken off is an administrator's, and it is the answer.
 *
 * @param int    $user_id Who.
 * @param string $role    The role.
 * @param int    $site_id Which site.
 */
function diluxone_users_membership_added_back( $user_id, $role, $site_id ): void {
	$user_id = (int) $user_id;
	$removed = diluxone_users_membership_removed( $user_id );

	if ( ! in_array( (int) $site_id, $removed, true ) ) {
		return;
	}

	$left = diluxone_users_membership_removed_without( $removed, (int) $site_id );

	if ( array() === $left ) {
		delete_user_meta( $user_id, DILUXONE_USERS_MEMBERSHIP_REMOVED );

		return;
	}

	update_user_meta( $user_id, DILUXONE_USERS_MEMBERSHIP_REMOVED, $left );
}
add_action( 'add_user_to_blog', 'diluxone_users_membership_added_back', 10, 3 );

/* ── Who, and where ────────────────────────────────────────────────── */

/**
 * The live sites of this network, by id, after one.
 *
 * Straight from the sites table and in id order, so a job that has done every
 * site up to one carries on from the next whatever was added or deleted in
 * between.
 *
 * @param int $after The last site done, 0 for the start.
 * @param int $limit How many, 0 for all.
 * @return array<int, int>
 */
function diluxone_users_membership_sites( int $after = 0, int $limit = 0 ): array {
	global $wpdb;

	$limit = $limit > 0 ? $limit : PHP_INT_MAX;

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- a cursor over the sites table, which get_sites() cannot express; read as it is now.
	$ids = $wpdb->get_col( $wpdb->prepare( "SELECT blog_id FROM {$wpdb->blogs} WHERE site_id = %d AND archived = '0' AND spam = 0 AND deleted = 0 AND blog_id > %d ORDER BY blog_id ASC LIMIT %d", get_current_network_id(), $after, $limit ) );

	return array_map( 'intval', (array) $ids );
}

/**
 * The people of this network, by id, after one.
 *
 * On an installation with one network every account is the network's. With
 * several, an account is this network's if it is a member of one of its
 * sites. Accounts WordPress marked as spam or deleted are nobody's.
 *
 * @param int $after The last person done, 0 for the start.
 * @param int $limit How many.
 * @return array<int, int>
 */
function diluxone_users_membership_people( int $after, int $limit ): array {
	global $wpdb;

	$limit = max( 1, $limit );

	if ( (int) get_networks( array( 'count' => true ) ) <= 1 ) {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- a cursor over the users table; get_users() pages by offset, which a job cannot resume from safely.
		$ids = $wpdb->get_col( $wpdb->prepare( "SELECT ID FROM {$wpdb->users} WHERE ID > %d AND spam = 0 AND deleted = 0 ORDER BY ID ASC LIMIT %d", $after, $limit ) );

		return array_map( 'intval', (array) $ids );
	}

	$keys = array_map( static fn( int $site ): string => $wpdb->get_blog_prefix( $site ) . 'capabilities', diluxone_users_membership_sites() );

	if ( array() === $keys ) {
		return array();
	}

	$in = implode( ', ', array_fill( 0, count( $keys ), '%s' ) );

	// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- $in is a list of %s placeholders, one per key, filled by prepare() from $args; a cursor no API offers.
	$sql = "SELECT DISTINCT m.user_id FROM {$wpdb->usermeta} m JOIN {$wpdb->users} u ON u.ID = m.user_id WHERE m.meta_key IN ( {$in} ) AND m.user_id > %d AND u.spam = 0 AND u.deleted = 0 ORDER BY m.user_id ASC LIMIT %d";
	$ids = $wpdb->get_col( $wpdb->prepare( $sql, array_merge( $keys, array( $after, $limit ) ) ) );
	// phpcs:enable

	return array_map( 'intval', (array) $ids );
}

/** How many people the network has, for the size of a job. */
function diluxone_users_membership_people_count(): int {
	global $wpdb;

	if ( (int) get_networks( array( 'count' => true ) ) <= 1 ) {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- one count, for the size of a job.
		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->users} WHERE spam = 0 AND deleted = 0" );
	}

	return count( diluxone_users_membership_people( 0, PHP_INT_MAX ) );
}

/**
 * The most additions made on the spot, rather than queued.
 *
 * A new account on a network of forty sites is forty additions, made before
 * the page that created it answers; a network of four thousand queues them.
 */
function diluxone_users_membership_inline_max(): int {
	/**
	 * Filters how many additions to sites are made on the spot.
	 *
	 * A new account under the "every site" policy is one addition per live
	 * site, a new site one per account. Up to this many are made in the
	 * request that caused them; more are queued and made by WP-Cron.
	 *
	 * @since 1.0.0
	 *
	 * @param int $max 50.
	 */
	return max( 0, (int) apply_filters( 'diluxone_users_membership_inline', 50 ) );
}

/** How many additions one batch of the queue makes. */
function diluxone_users_membership_batch(): int {
	/**
	 * Filters how many additions to sites one batch of the queue makes.
	 *
	 * @since 1.0.0
	 *
	 * @param int $batch 200.
	 */
	return max( 1, (int) apply_filters( 'diluxone_users_membership_batch', 200 ) );
}

/* ── New accounts and new sites ────────────────────────────────────── */

/**
 * A new account, under "every site": a member of every live site.
 *
 * WordPress announces a new account more than once on a network — every
 * account fires `user_register`, one made by wpmu_create_user() then fires
 * `wpmu_new_user` after taking away the role it had on the current site, and
 * one activated from an invitation fires `wpmu_activate_user` after taking it
 * off the main site. Each of them runs this; the additions are only made where
 * the person is not a member yet, so the last word is always "every site";
 * a big network queues the account once, because the queue takes a job only
 * once (diluxone_users_membership_enqueue()).
 *
 * @param int $user_id The new account.
 */
function diluxone_users_membership_new_account( $user_id ): void {
	$user_id = (int) $user_id;

	if ( ! is_multisite() || $user_id <= 0 || 'all' !== diluxone_users_membership() ) {
		return;
	}

	$sites = diluxone_users_membership_sites( 0, diluxone_users_membership_inline_max() + 1 );

	if ( ! diluxone_users_membership_inline( count( $sites ), diluxone_users_membership_inline_max() ) ) {
		diluxone_users_membership_enqueue(
			array(
				'kind'  => 'user',
				'id'    => $user_id,
				'total' => count( diluxone_users_membership_sites() ),
			)
		);

		return;
	}

	foreach ( $sites as $site ) {
		diluxone_users_membership_add( $user_id, $site, 'account' );
	}
}
add_action( 'user_register', 'diluxone_users_membership_new_account', 20 );
add_action( 'wpmu_new_user', 'diluxone_users_membership_new_account', 20 );
add_action( 'wpmu_activate_user', 'diluxone_users_membership_new_account', 20 );

/**
 * A new site, under "every site": every account of the network a member of it.
 *
 * After the plugin's own setup of the site (priority 11), so the site already
 * has what it reads.
 *
 * @param WP_Site $site The new site.
 */
function diluxone_users_membership_new_site( $site ): void {
	if ( ! $site instanceof WP_Site || ! diluxone_users_network_activated() || 'all' !== diluxone_users_membership() ) {
		return;
	}

	$site_id = (int) $site->blog_id;

	if ( ! diluxone_users_membership_site_takes( $site_id ) ) {
		return;
	}

	$max    = diluxone_users_membership_inline_max();
	$people = diluxone_users_membership_people( 0, $max + 1 );

	if ( ! diluxone_users_membership_inline( count( $people ), $max ) ) {
		diluxone_users_membership_enqueue(
			array(
				'kind'  => 'site',
				'id'    => $site_id,
				'total' => diluxone_users_membership_people_count(),
			)
		);

		return;
	}

	foreach ( $people as $person ) {
		diluxone_users_membership_add( $person, $site_id, 'site' );
	}
}
add_action( 'wp_initialize_site', 'diluxone_users_membership_new_site', 20 );

/* ── The queue ─────────────────────────────────────────────────────── */

/**
 * The jobs still to do.
 *
 * @return array<int, array{kind: string, id: int, after: int, done: int, total: int}>
 */
function diluxone_users_membership_queue(): array {
	$jobs = diluxone_users_raw_get( DILUXONE_USERS_MEMBERSHIP_QUEUE, array() );
	$out  = array();

	foreach ( is_array( $jobs ) ? $jobs : array() as $job ) {
		if ( ! is_array( $job ) || ! in_array( $job['kind'] ?? '', array( 'user', 'site', 'all' ), true ) ) {
			continue;
		}

		$out[] = array(
			'kind'  => (string) $job['kind'],
			'id'    => (int) ( $job['id'] ?? 0 ),
			'after' => (int) ( $job['after'] ?? 0 ),
			'done'  => (int) ( $job['done'] ?? 0 ),
			'total' => (int) ( $job['total'] ?? 0 ),
		);
	}

	return $out;
}

/**
 * Stores the jobs, or forgets the queue when there are none.
 *
 * @param array<int, array{kind: string, id: int, after: int, done: int, total: int}> $jobs
 */
function diluxone_users_membership_queue_save( array $jobs ): void {
	if ( array() === $jobs ) {
		diluxone_users_delete_option( DILUXONE_USERS_MEMBERSHIP_QUEUE );

		return;
	}

	diluxone_users_update_option( DILUXONE_USERS_MEMBERSHIP_QUEUE, array_values( $jobs ), false );
}

/**
 * Adds a job, unless the same one is waiting, and asks cron for a run.
 *
 * @param array{kind: string, id?: int, total?: int} $job What to do.
 */
function diluxone_users_membership_enqueue( array $job ): void {
	$jobs = diluxone_users_membership_queue();

	foreach ( $jobs as $waiting ) {
		if ( $waiting['kind'] === $job['kind'] && $waiting['id'] === (int) ( $job['id'] ?? 0 ) ) {
			diluxone_users_membership_schedule();

			return;
		}
	}

	$jobs[] = array(
		'kind'  => $job['kind'],
		'id'    => (int) ( $job['id'] ?? 0 ),
		'after' => 0,
		'done'  => 0,
		'total' => (int) ( $job['total'] ?? 0 ),
	);

	diluxone_users_membership_queue_save( $jobs );
	diluxone_users_membership_schedule();
}

/**
 * Asks cron, on the hub, for a run of the queue.
 *
 * @param int $wait Seconds from now.
 */
function diluxone_users_membership_schedule( int $wait = 0 ): void {
	diluxone_users_on_hub(
		static function () use ( $wait ): void {
			if ( ! wp_next_scheduled( DILUXONE_USERS_MEMBERSHIP_EVENT ) ) {
				wp_schedule_single_event( time() + max( 0, $wait ), DILUXONE_USERS_MEMBERSHIP_EVENT );
			}
		}
	);
}

/**
 * Takes the lock for one run, if nobody holds it.
 *
 * Five minutes: a run that dies half-way does not hold the queue for ever.
 */
function diluxone_users_membership_lock(): bool {
	$held = get_site_transient( DILUXONE_USERS_MEMBERSHIP_LOCK );

	if ( false !== $held ) {
		return false;
	}

	return set_site_transient( DILUXONE_USERS_MEMBERSHIP_LOCK, time(), 5 * MINUTE_IN_SECONDS );
}

/** Lets the lock go. */
function diluxone_users_membership_unlock(): void {
	delete_site_transient( DILUXONE_USERS_MEMBERSHIP_LOCK );
}

/**
 * One batch of the first job.
 *
 * @param array{kind: string, id: int, after: int, done: int, total: int} $job
 * @return array{kind: string, id: int, after: int, done: int, total: int}|null The job as it stands, null once it is finished.
 */
function diluxone_users_membership_step( array $job ): ?array {
	$batch = diluxone_users_membership_batch();

	if ( 'user' === $job['kind'] ) {
		$sites = diluxone_users_membership_sites( $job['after'], $batch );

		foreach ( $sites as $site ) {
			diluxone_users_membership_add( $job['id'], $site, 'account' );
			$job['after'] = $site;
		}

		$job['done'] += count( $sites );

		return count( $sites ) < $batch ? null : $job;
	}

	if ( 'site' === $job['kind'] ) {
		$people = diluxone_users_membership_people( $job['after'], $batch );

		foreach ( $people as $person ) {
			diluxone_users_membership_add( $person, $job['id'], 'site' );
			$job['after'] = $person;
		}

		$job['done'] += count( $people );

		return count( $people ) < $batch || ! diluxone_users_membership_site_takes( $job['id'] ) ? null : $job;
	}

	$sites  = diluxone_users_membership_sites();
	$per    = diluxone_users_membership_people_per_batch( $batch, count( $sites ) );
	$people = diluxone_users_membership_people( $job['after'], $per );

	foreach ( $people as $person ) {
		foreach ( $sites as $site ) {
			diluxone_users_membership_add( $person, $site, 'sync' );
		}

		$job['after'] = $person;
	}

	$job['done'] += count( $people ) * count( $sites );

	return count( $people ) < $per ? null : $job;
}

/**
 * Works through the queue: a number of batches, under the lock.
 *
 * Under any policy but "every site" there is nothing to add, and the queue is
 * dropped: a job queued before the policy changed is not a decision anybody
 * still stands by.
 *
 * @param int $batches How many batches, 0 for all of them.
 * @return bool|null True when the queue is empty afterwards, false when some is left, null when another run holds the lock.
 */
function diluxone_users_membership_drain( int $batches = 10 ) {
	if ( 'all' !== diluxone_users_membership() ) {
		diluxone_users_membership_queue_save( array() );

		return true;
	}

	if ( array() === diluxone_users_membership_queue() ) {
		return true;
	}

	if ( ! diluxone_users_membership_lock() ) {
		return null;
	}

	$runs = 0;
	$jobs = diluxone_users_membership_queue();

	try {

		while ( array() !== $jobs && ( $batches <= 0 || $runs < $batches ) ) {
			$job = diluxone_users_membership_step( $jobs[0] );

			// Read again before writing: a job added by another request while
			// this batch ran is kept.
			$jobs = diluxone_users_membership_queue();

			if ( null === $job ) {
				array_shift( $jobs );
			} else {
				$jobs[0] = $job;
			}

			diluxone_users_membership_queue_save( $jobs );
			++$runs;
		}
	} finally {
		diluxone_users_membership_unlock();
	}

	return array() === $jobs;
}

/** A run from cron, which asks for the next one while there is work left. */
function diluxone_users_membership_cron(): void {
	$drained = diluxone_users_membership_drain();

	// Held by another run: that run asks for the next itself, and this one
	// looks again in a minute in case it died holding the lock.
	if ( true !== $drained ) {
		diluxone_users_membership_schedule( null === $drained ? MINUTE_IN_SECONDS : 0 );
	}
}
add_action( DILUXONE_USERS_MEMBERSHIP_EVENT, 'diluxone_users_membership_cron' );

/**
 * Everybody on every live site: a job for the queue.
 *
 * @return int How many additions it looks at: people times live sites.
 */
function diluxone_users_membership_enqueue_all(): int {
	$pairs = count( diluxone_users_membership_sites() ) * diluxone_users_membership_people_count();

	diluxone_users_membership_enqueue(
		array(
			'kind'  => 'all',
			'total' => $pairs,
		)
	);

	return $pairs;
}

/**
 * "Sync everyone now": made on the spot when it is small enough; otherwise
 * queued, and cron takes it from there.
 *
 * @return bool Whether it is all done already.
 */
function diluxone_users_membership_sync(): bool {
	if ( ! is_multisite() || 'all' !== diluxone_users_membership() ) {
		return true;
	}

	if ( diluxone_users_membership_inline( diluxone_users_membership_enqueue_all(), diluxone_users_membership_inline_max() ) ) {
		return true === diluxone_users_membership_drain( 0 );
	}

	return false;
}

/** A plugin switched off leaves no run of the queue scheduled. */
function diluxone_users_membership_unschedule(): void {
	if ( is_multisite() ) {
		diluxone_users_on_hub( static fn() => wp_clear_scheduled_hook( DILUXONE_USERS_MEMBERSHIP_EVENT ) );
	}
}
register_deactivation_hook( DILUXONE_USERS_FILE, 'diluxone_users_membership_unschedule' );
