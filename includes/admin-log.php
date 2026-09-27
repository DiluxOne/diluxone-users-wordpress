<?php
/**
 * The activity log on screen: the rows, and what is written down at all.
 *
 * Two panels and not one, and they sit at the two ends of the Reports screen
 * on purpose. The list is a report — it is looked at, nothing on it is saved,
 * and it belongs beside the open sessions. What is recorded and for how long is
 * a setting, it has a Save button, and putting it under the table would make
 * the table read as something that gets written when the button is pressed,
 * which is the exact shape the Reports screen was created to take off these
 * screens.
 *
 * Why the setting is here at all, rather than on Security with the other
 * rules: this one decides whether the screen beside it has anything to show.
 * A log that can only be turned on two screens away from where it is read is a
 * log that stays off, and the first thing anybody does after finding an empty
 * table is look for the switch. It is last in the tab strip, it says what it is
 * in its own name, and it is the only settings tab on the screen.
 *
 * @package DiluxOneUsers
 */

defined( 'ABSPATH' ) || exit;

/** The two tabs: the rows, and the rules about them. */
function diluxone_users_log_panels(): void {
	diluxone_users_register_panel(
		DILUXONE_USERS_REPORTS,
		'activity',
		array(
			'label'    => __( 'Activity', 'diluxone-users' ),
			'position' => 20,
			'render'   => 'diluxone_users_screen_log',
			// The search is a form of its own, posting to this same screen.
			// A form inside a form is thrown away by the browser, and there is
			// nothing on this tab to save anyway.
			'form'     => false,
		)
	);

	diluxone_users_register_panel(
		DILUXONE_USERS_REPORTS,
		'logging',
		array(
			'label'    => __( 'Log settings', 'diluxone-users' ),
			'position' => 90,
			'render'   => 'diluxone_users_screen_log_settings',
			'save'     => 'diluxone_users_log_settings_save',
		)
	);
}
add_action( 'diluxone_users_register_panels', 'diluxone_users_log_panels' );

/* ── What is recorded ──────────────────────────────────────────────── */

/** Saves the groups and the retention. */
function diluxone_users_log_settings_save(): void {
	// phpcs:disable WordPress.Security.NonceVerification.Missing -- the panel verifies it.
	// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- every element goes through sanitize_key() in the loop below, and only the ones naming a group this plugin has are kept.
	$sent   = (array) wp_unslash( $_POST['diluxone_users_log_levels'] ?? array() );
	$groups = diluxone_users_log_groups();
	$levels = array();

	foreach ( $sent as $group ) {
		$group = sanitize_key( (string) $group );

		if ( isset( $groups[ $group ] ) ) {
			$levels[] = $group;
		}
	}

	diluxone_users_save_options(
		array(
			'diluxone_users_log_levels' => $levels,
			'diluxone_users_log_days'   => absint( $_POST['diluxone_users_log_days'] ?? 90 ),
		)
	);
	// phpcs:enable
}

/** The three tick boxes and the number of days. */
function diluxone_users_screen_log_settings(): void {
	$on = diluxone_users_log_levels();

	diluxone_users_ui_aside_open();

	diluxone_users_intro( __( 'What this site writes down about what people do, and how long it keeps it. Everything here decides what the Activity tab beside it can show: a group that is not ticked is not recorded, and what was never recorded cannot be looked up afterwards.', 'diluxone-users' ) );

	diluxone_users_ui_section(
		__( 'What is written down', 'diluxone-users' ),
		__( 'A fresh install records the way in and the way out, and nothing else. The other two are ticked by whoever will be paying for the rows — which is why each one says roughly what it costs.', 'diluxone-users' )
	);

	$choices = array();

	foreach ( diluxone_users_log_groups() as $group => $what ) {
		$choices[] = array(
			'type'    => 'checkbox',
			'name'    => 'diluxone_users_log_levels[]',
			'id'      => 'diluxone-users-log-' . $group,
			'value'   => $group,
			'checked' => in_array( $group, $on, true ),
			'title'   => $what['label'],
			// Two sentences and not one: what it records, and what it costs.
			// The second is the half that is normally missing from a switch
			// like this, and it is the half somebody is actually deciding on.
			'help'    => $what['help'] . ' ' . $what['writes'],
		);
	}

	diluxone_users_ui_choices( $choices );

	diluxone_users_ui_section( __( 'How long it is kept', 'diluxone-users' ) );

	diluxone_users_ui_number(
		array(
			'label'  => __( 'Keep a row for', 'diluxone-users' ),
			'name'   => 'diluxone_users_log_days',
			'value'  => (string) diluxone_users_log_days(),
			'suffix' => __( 'days', 'diluxone-users' ),
			'min'    => 0,
			'help'   => __( 'Anything older goes once a day, on its own. 0 keeps everything for ever, which is a real answer for a site that has to keep it — and the one that has no end.', 'diluxone-users' ),
		)
	);

	diluxone_users_ui_aside_close(
		static function (): void {
			diluxone_users_log_aside_state();

			diluxone_users_ui_note(
				__( 'Every row is disk', 'diluxone-users' ),
				__( 'This is the one setting in the plugin that writes to the database because of what other people do, so it grows with the traffic and not with the configuration. A busy site ticking all three groups writes tens of thousands of rows a month; the number of days above is what stops that from being for ever.', 'diluxone-users' )
			);

			diluxone_users_ui_links(
				__( 'What this fills', 'diluxone-users' ),
				array(
					array(
						'url'   => diluxone_users_admin_url( DILUXONE_USERS_REPORTS, array( 'tab' => 'activity' ) ),
						'label' => __( 'Reports › Activity', 'diluxone-users' ),
						'help'  => __( 'The rows themselves, by person, by kind and by date. It only ever shows what was already being recorded when it happened.', 'diluxone-users' ),
					),
				)
			);
		}
	);
}

/* ── The rows ──────────────────────────────────────────────────────── */

/**
 * How this site stands, for the head of either rail.
 *
 * Both tabs open with the same sentence because it is the same fact, and it is
 * the one this feature was asked for: not "a log may grow", which is true of
 * every site ever installed, but what this table weighs on this site this
 * afternoon.
 */
function diluxone_users_log_aside_state(): void {
	$on     = diluxone_users_log_levels();
	$groups = diluxone_users_log_groups();
	$size   = diluxone_users_log_size();
	$days   = diluxone_users_log_days();

	$names = array();

	foreach ( $on as $group ) {
		$names[] = '<code>' . esc_html( (string) $groups[ $group ]['label'] ) . '</code>';
	}

	/*
	 * The groups are named in the middle of the sentence and never at the end
	 * of one. A `<code>` span carries its own padding, so a full stop written
	 * straight after it lands a space away from the word and reads as a typo —
	 * which is exactly what it looked like on the first draft of this rail.
	 */
	$line = array() === $names
		? esc_html__( 'Nothing is being recorded on this site.', 'diluxone-users' )
		: sprintf(
			/* translators: %s: the groups being recorded, each in <code>. */
			esc_html__( 'This site records %s and nothing else.', 'diluxone-users' ),
			implode( ', ', $names )
		);

	$line .= ' ' . sprintf(
		/* translators: 1: number of rows, 2: size on disk, e.g. "4 MB". */
		esc_html__( 'The table holds %1$s rows and takes %2$s.', 'diluxone-users' ),
		esc_html( number_format_i18n( $size['rows'] ) ),
		esc_html( $size['bytes'] > 0 ? (string) size_format( $size['bytes'] ) : __( 'a size this database will not report', 'diluxone-users' ) )
	);

	if ( '' !== $size['oldest'] ) {
		$line .= ' ' . sprintf(
			/* translators: %s: a date. */
			esc_html__( 'The oldest row is from %s.', 'diluxone-users' ),
			esc_html( (string) wp_date( 'j M Y', (int) strtotime( $size['oldest'] . ' UTC' ) ) )
		);
	}

	diluxone_users_ui_aside_state(
		$line,
		array() === $on ? 'off' : 'active',
		0 === $days
			? __( 'kept for ever', 'diluxone-users' )
			: sprintf(
				/* translators: %s: a number of days. */
				__( 'kept %s days', 'diluxone-users' ),
				number_format_i18n( $days )
			)
	);
}

/**
 * Which door somebody came in by, named for whoever is reading the report.
 *
 * Not `diluxone_users_via_label()`, which is the same fact written for the
 * person it happened to — "your password", "a link sent to your email". In a
 * column about somebody else that is the wrong voice and, worse, the wrong
 * person: an administrator reading "your password" on a row about a stranger
 * reads it twice. Here it is the name of the door and nothing more.
 */
function diluxone_users_log_via( string $via ): string {
	$doors = array(
		'password' => __( 'Password', 'diluxone-users' ),
		'link'     => __( 'E-mail link', 'diluxone-users' ),
		'sso'      => __( 'Social account', 'diluxone-users' ),
		'passkey'  => __( 'Passkey', 'diluxone-users' ),
	);

	return (string) ( $doors[ $via ] ?? $via );
}

/**
 * What one row says, beyond its name.
 *
 * The event's own label answers "what happened"; this answers "to what". It is
 * built from the detail the row was written with: a handful of short strings,
 * and for a refused sign-in the name that was typed in the username box. That
 * one is somebody's input, and it is escaped like any other on the way out.
 *
 * @param array<string, mixed> $row One row as diluxone_users_log_search() returns it.
 */
function diluxone_users_log_says( array $row ): string {
	$detail = (array) $row['detail'];

	switch ( (string) $row['event'] ) {
		case 'signed_in':
			return isset( $detail['via'] ) ? diluxone_users_log_via( (string) $detail['via'] ) : '';

		case 'sign_in_failed':
			return (string) ( $detail['tried'] ?? '' );

		case 'email_changed':
		case 'name_changed':
			return trim( (string) ( $detail['was'] ?? '' ) . ' → ' . (string) ( $detail['now'] ?? '' ), ' →' );

		case 'profile_saved':
			return (string) ( $detail['fields'] ?? '' );

		case 'passkey_added':
		case 'passkey_removed':
			return sprintf(
				/* translators: %s: how many passkeys the account has left. */
				__( '%s on the account now', 'diluxone-users' ),
				number_format_i18n( (int) ( $detail['keys'] ?? 0 ) )
			);

		case 'sessions_closed':
			return sprintf(
				/* translators: %s: how many sessions were closed at once. */
				__( '%s closed', 'diluxone-users' ),
				number_format_i18n( (int) ( $detail['closed'] ?? 0 ) )
			);
	}

	return '';
}

/**
 * The rows, filtered.
 *
 * Four filters, and they are the four questions a log is opened with: who,
 * what, and between when and when. There is no dropdown of people for the same
 * reason the sessions report has none — a site with twenty-five thousand
 * accounts would ship half a megabyte of HTML on every load, and what somebody
 * needs is to find one person, not to scroll past the rest.
 *
 * The table is a wide block and the rail stays all the same. What the rail
 * carries here is not a cross-reference: it is how much this table weighs,
 * which is the one thing on the screen that is not a row.
 */
function diluxone_users_screen_log(): void {
	// phpcs:disable WordPress.Security.NonceVerification.Recommended -- it is a read-only search.
	$who   = isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : '';
	$event = isset( $_GET['event'] ) ? sanitize_key( wp_unslash( $_GET['event'] ) ) : '';
	$from  = isset( $_GET['from'] ) ? sanitize_text_field( wp_unslash( $_GET['from'] ) ) : '';
	$to    = isset( $_GET['to'] ) ? sanitize_text_field( wp_unslash( $_GET['to'] ) ) : '';
	$page  = isset( $_GET['paged'] ) ? max( 1, absint( $_GET['paged'] ) ) : 1;
	$per   = isset( $_GET['per'] ) ? max( 5, min( 200, absint( $_GET['per'] ) ) ) : 20;
	// phpcs:enable

	$filters = array(
		'who'   => $who,
		'event' => $event,
		'from'  => $from,
		'to'    => $to,
	);

	$result = diluxone_users_log_search( $filters, $page, $per );
	$total  = $result['total'];
	$pages  = (int) max( 1, ceil( $total / $per ) );
	$labels = diluxone_users_log_labels();
	$groups = diluxone_users_log_groups();

	diluxone_users_ui_aside_open();

	diluxone_users_intro( __( 'What happened on this site, newest first. It shows only what was being recorded at the time: a group ticked this morning has nothing in it from yesterday.', 'diluxone-users' ) );
	?>
	<form method="get" class="diluxone-users-search">
		<input type="hidden" name="page" value="<?php echo esc_attr( DILUXONE_USERS_REPORTS ); ?>">
		<input type="hidden" name="tab" value="activity">

		<label class="screen-reader-text" for="diluxone-users-log-s"><?php esc_html_e( 'Search', 'diluxone-users' ); ?></label>
		<input type="search" id="diluxone-users-log-s" name="s" value="<?php echo esc_attr( $who ); ?>" placeholder="<?php esc_attr_e( 'Email, username or name…', 'diluxone-users' ); ?>">

		<label class="screen-reader-text" for="diluxone-users-log-event"><?php esc_html_e( 'What happened', 'diluxone-users' ); ?></label>
		<select id="diluxone-users-log-event" name="event">
			<option value=""><?php esc_html_e( 'Anything that happened', 'diluxone-users' ); ?></option>
			<?php foreach ( diluxone_users_log_events() as $slug => $group ) : ?>
				<option value="<?php echo esc_attr( $slug ); ?>" <?php selected( $event, $slug ); ?>>
					<?php
					printf(
						'%s — %s',
						esc_html( (string) $groups[ $group ]['label'] ),
						esc_html( (string) ( $labels[ $slug ] ?? $slug ) )
					);
					?>
				</option>
			<?php endforeach; ?>
		</select>

		<label class="screen-reader-text" for="diluxone-users-log-from"><?php esc_html_e( 'From', 'diluxone-users' ); ?></label>
		<input type="date" id="diluxone-users-log-from" name="from" value="<?php echo esc_attr( $from ); ?>">

		<label class="screen-reader-text" for="diluxone-users-log-to"><?php esc_html_e( 'To', 'diluxone-users' ); ?></label>
		<input type="date" id="diluxone-users-log-to" name="to" value="<?php echo esc_attr( $to ); ?>">

		<?php submit_button( __( 'Search', 'diluxone-users' ), 'secondary', '', false ); ?>

		<a class="button" href="
		<?php
		echo esc_url(
			diluxone_users_admin_url(
				DILUXONE_USERS_REPORTS,
				array(
					'tab' => 'activity',
					'per' => $per,
				)
			)
		);
		?>
			"><?php esc_html_e( 'Clear', 'diluxone-users' ); ?></a>

		<label class="diluxone-users-search__by">
			<?php esc_html_e( 'Show', 'diluxone-users' ); ?>
			<select name="per" data-diluxone-users-autosubmit>
				<?php foreach ( array( 10, 20, 50, 100 ) as $option ) : ?>
					<option value="<?php echo esc_attr( (string) $option ); ?>" <?php selected( $per, $option ); ?>><?php echo esc_html( number_format_i18n( $option ) ); ?></option>
				<?php endforeach; ?>
			</select>
			<?php esc_html_e( 'per page', 'diluxone-users' ); ?>
		</label>

		<span class="diluxone-users-search__account">
			<?php
			printf(
				/* translators: 1: number of rows, 2: current page, 3: total pages */
				esc_html__( '%1$s rows · page %2$d of %3$d', 'diluxone-users' ),
				esc_html( number_format_i18n( $total ) ),
				(int) $page,
				(int) $pages
			);
			?>
		</span>
	</form>

	<?php diluxone_users_ui_wide_open(); ?>

	<?php
	/*
	 * Without `fixed`, which every other table on these screens carries. A
	 * fixed layout gives six columns a sixth of the width each, and one of
	 * these six holds an e-mail address: at a sixth it broke mid-word, one
	 * letter left on a line of its own. The rest of the columns here are a
	 * date, a short name and an address, so letting the browser size them is
	 * the whole fix, and it is one word rather than a column of widths in the
	 * stylesheet that only this screen would ever use.
	 */
	?>
	<table class="wp-list-table widefat striped diluxone-users-list" data-diluxone-users-log>
		<thead>
			<tr>
				<th><?php esc_html_e( 'When', 'diluxone-users' ); ?></th>
				<th class="diluxone-users-list__name"><?php esc_html_e( 'Person', 'diluxone-users' ); ?></th>
				<th><?php esc_html_e( 'What happened', 'diluxone-users' ); ?></th>
				<th><?php esc_html_e( 'Detail', 'diluxone-users' ); ?></th>
				<th><?php esc_html_e( 'IP', 'diluxone-users' ); ?></th>
				<th><?php esc_html_e( 'Device', 'diluxone-users' ); ?></th>
			</tr>
		</thead>
		<tbody>
			<?php if ( array() === $result['rows'] ) : ?>
				<tr><td colspan="6"><?php esc_html_e( 'Nothing matches that.', 'diluxone-users' ); ?></td></tr>
			<?php endif; ?>

			<?php
			foreach ( $result['rows'] as $row ) :
				$agent = diluxone_users_user_agent( (string) $row['agent'] );
				?>
				<tr data-diluxone-users-event="<?php echo esc_attr( (string) $row['event'] ); ?>">
					<td><?php echo esc_html( (string) wp_date( 'j M Y, H:i', (int) $row['happened'] ) ); ?></td>
					<td class="diluxone-users-list__name">
						<?php if ( $row['user_id'] > 0 && '' !== $row['email'] ) : ?>
							<strong><a href="<?php echo esc_url( (string) get_edit_user_link( (int) $row['user_id'] ) ); ?>"><?php echo esc_html( '' !== $row['name'] ? (string) $row['name'] : (string) $row['login'] ); ?></a></strong>
							<span class="diluxone-users-list__mail"><?php echo esc_html( (string) $row['email'] ); ?></span>
						<?php else : ?>
							<?php
							// Nobody: a refused sign-in belongs to no account, and
							// so does a row whose account has since been deleted.
							?>
							<span class="diluxone-users-list__mail">—</span>
						<?php endif; ?>
					</td>
					<td><?php echo esc_html( diluxone_users_log_label( (string) $row['event'] ) ); ?></td>
					<td><?php echo esc_html( diluxone_users_log_says( $row ) ); ?></td>
					<td><code><?php echo esc_html( (string) $row['ip'] ); ?></code></td>
					<td><?php echo esc_html( trim( $agent['browser'] . ( '' !== $agent['os'] ? ' · ' . $agent['os'] : '' ) ) ); ?></td>
				</tr>
			<?php endforeach; ?>
		</tbody>
	</table>

	<?php if ( $pages > 1 ) : ?>
		<div class="tablenav"><div class="tablenav-pages">
			<?php
			$diluxone_users_base = diluxone_users_admin_url(
				DILUXONE_USERS_REPORTS,
				array(
					'tab'   => 'activity',
					's'     => $who,
					'event' => $event,
					'from'  => $from,
					'to'    => $to,
					'per'   => $per,
				)
			);

			echo wp_kses_post(
				(string) paginate_links(
					array(
						'base'      => $diluxone_users_base . '&paged=%#%',
						'format'    => '',
						'current'   => $page,
						'total'     => $pages,
						'prev_text' => '&lsaquo;',
						'next_text' => '&rsaquo;',
					)
				)
			);
			?>
		</div></div>
	<?php endif; ?>
	<?php
	diluxone_users_ui_wide_close();

	diluxone_users_ui_aside_close(
		static function (): void {
			diluxone_users_log_aside_state();

			diluxone_users_ui_note(
				__( 'What is not here', 'diluxone-users' ),
				__( 'A row exists because its group was ticked at the moment it happened. Turning a group on today fills this from today; turning one off leaves what was already written until the days above run out.', 'diluxone-users' )
			);

			diluxone_users_ui_links(
				__( 'The settings behind this report', 'diluxone-users' ),
				array(
					array(
						'url'   => diluxone_users_admin_url( DILUXONE_USERS_REPORTS, array( 'tab' => 'logging' ) ),
						'label' => __( 'Reports › Log settings', 'diluxone-users' ),
						'help'  => __( 'Which of the three groups is written down, and how many days a row is kept before it goes on its own.', 'diluxone-users' ),
					),
				)
			);
		}
	);
}
