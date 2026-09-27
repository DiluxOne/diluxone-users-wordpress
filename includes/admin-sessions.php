<?php
/**
 * Sessions: how long one lasts, and who has one open.
 *
 * Those were one tab, with the rule and its Save button at the top and the
 * list of everybody signed in underneath. They are about the same subject and
 * they are not the same kind of thing — one is decided, the other is looked
 * at — and a table of names under a Save button reads as something that will
 * be written when the button is pressed. So the rule stays on Security and
 * the list is a panel of the Reports screen, registered from this file: the
 * feature is one feature, and it still lives in one place.
 *
 * The list is searched and paginated against the database. A dropdown with
 * every user would be half a megabyte of HTML on each load on a site with
 * twenty-five thousand accounts, and it would be no use anyway: what is
 * needed is to find one person, not to scroll the list.
 *
 * @package DiluxOneUsers
 */

defined( 'ABSPATH' ) || exit;

/** The rule, on Security; who is living under it, on Reports. */
function diluxone_users_sessions_panels(): void {
	diluxone_users_register_panel(
		DILUXONE_USERS_SECURITY,
		'sessions',
		array(
			'label'    => __( 'Sessions', 'diluxone-users' ),
			'position' => 30,
			'render'   => 'diluxone_users_screen_sessions',
			'save'     => 'diluxone_users_sessions_save',
		)
	);

	diluxone_users_register_panel(
		DILUXONE_USERS_REPORTS,
		'sessions',
		array(
			'label'    => __( 'Open sessions', 'diluxone-users' ),
			'position' => 10,
			'render'   => 'diluxone_users_screen_sessions_list',
		)
	);
}
add_action( 'diluxone_users_register_panels', 'diluxone_users_sessions_panels' );

/**
 * Behind a proxy: which header carries the visitor's address.
 *
 * Every limit the plugin keeps per address — how often a link may be asked
 * for, how many accounts an address may create — is only as good as the
 * address. Behind a proxy the connection comes from the proxy and the
 * visitor is in a header, and a header anybody can type is not an address
 * unless the connection it came on is trusted. So: one header, and the
 * proxies it is believed from.
 */
function diluxone_users_proxy_panels(): void {
	diluxone_users_register_panel(
		DILUXONE_USERS_SECURITY,
		'proxy',
		array(
			'label'    => __( 'Behind a proxy', 'diluxone-users' ),
			'position' => 50,
			'render'   => 'diluxone_users_screen_proxy',
			'save'     => 'diluxone_users_proxy_save',
		)
	);
}
add_action( 'diluxone_users_register_panels', 'diluxone_users_proxy_panels' );

/** Saves the header and the proxies. */
function diluxone_users_proxy_save(): void {
	// phpcs:disable WordPress.Security.NonceVerification.Missing -- the panel verifies it.
	$header = strtoupper( sanitize_key( wp_unslash( $_POST['diluxone_users_ip_header'] ?? '' ) ) );

	diluxone_users_save_options(
		array(
			'diluxone_users_ip_header'       => isset( diluxone_users_ip_headers()[ $header ] ) ? $header : '',
			'diluxone_users_trusted_proxies' => sanitize_textarea_field( wp_unslash( $_POST['diluxone_users_trusted_proxies'] ?? '' ) ),
		)
	);
	// phpcs:enable
}

/** The header and the proxies. */
function diluxone_users_screen_proxy(): void {
	diluxone_users_ui_aside_open();

	diluxone_users_intro( __( 'Every limit this plugin keeps per address — how often a link may be asked for, how many accounts an address may create — is only as good as the address. Behind a proxy or a CDN the visitor’s address travels in a header, and a header is only believed when the connection it came on is from a proxy this site trusts.', 'diluxone-users' ) );

	diluxone_users_ui_select(
		array(
			'label'   => __( 'The header with the visitor’s address', 'diluxone-users' ),
			'name'    => 'diluxone_users_ip_header',
			'value'   => diluxone_users_ip_header(),
			'options' => diluxone_users_ip_headers(),
			'help'    => __( 'Leave it on “None” unless this site really is behind a proxy: a header nobody is writing is a header the visitor can write. When there is one, pick the one it writes — X-Forwarded-For for nginx, Traefik and most load balancers, CF-Connecting-IP behind Cloudflare, True-Client-IP behind Akamai.', 'diluxone-users' ),
		)
	);

	diluxone_users_ui_textarea(
		array(
			'label' => __( 'Trusted proxies', 'diluxone-users' ),
			'name'  => 'diluxone_users_trusted_proxies',
			'value' => (string) diluxone_users_option( 'diluxone_users_trusted_proxies' ),
			'rows'  => 4,
			'code'  => true,
			'help'  => __( 'One address or range per line, such as 203.0.113.0/24. Private and local ranges are always trusted, so a proxy on the same machine or network needs nothing here. A CDN’s edges do.', 'diluxone-users' ),
		)
	);

	/*
	 * The answer to "is this set right" is one address, and it was the help
	 * line under the dropdown: a reading of what the site is doing right now,
	 * written where a control explains itself. Beside the two of them it is
	 * the one thing on this tab worth looking at after a save.
	 */
	diluxone_users_ui_aside_close(
		static function (): void {
			diluxone_users_ui_note(
				__( 'What this site sees for you', 'diluxone-users' ),
				array(
					'<code>' . esc_html( diluxone_users_client_ip() ) . '</code>',
					esc_html__( 'If that is your own address, the header and the proxies are set right. If it is your proxy’s, this site is reading the connection and not the header.', 'diluxone-users' ),
				)
			);
		}
	);
}

/** Saves how long a session lasts. */
function diluxone_users_sessions_save(): void {
	// phpcs:disable WordPress.Security.NonceVerification.Missing -- the panel verifies it.
	diluxone_users_save_options(
		array(
			'diluxone_users_session_long_days'  => absint( wp_unslash( $_POST['diluxone_users_session_long_days'] ?? 30 ) ),
			'diluxone_users_session_short_days' => absint( wp_unslash( $_POST['diluxone_users_session_short_days'] ?? 2 ) ),
			'diluxone_users_sessions_show'      => isset( $_POST['diluxone_users_sessions_show'] ) ? 1 : 0,
		)
	);
	// phpcs:enable
}

/** How long a session lasts, and whether each person can see their own. */
function diluxone_users_screen_sessions(): void {
	diluxone_users_ui_aside_open();

	diluxone_users_intro( __( 'A session is what keeps somebody signed in after they close the tab. Here is how long one lasts on this site, and whether each person can see their own.', 'diluxone-users' ) );

	diluxone_users_ui_section(
		__( 'How long a session lasts', 'diluxone-users' ),
		__( 'WordPress ends one after 2 days, or 14 with “remember me”. On a site people come into by e-mail link, that is the inbox again every other day.', 'diluxone-users' )
	);

	diluxone_users_ui_fields_open();

	diluxone_users_ui_number(
		array(
			'label'  => __( 'With “remember me”', 'diluxone-users' ),
			'name'   => 'diluxone_users_session_long_days',
			'value'  => (string) diluxone_users_option( 'diluxone_users_session_long_days' ),
			'suffix' => __( 'days', 'diluxone-users' ),
			'min'    => 1,
			'help'   => __( 'The sign-in link always counts as “remember me”: there is no password to type again.', 'diluxone-users' ),
		)
	);

	diluxone_users_ui_number(
		array(
			'label'  => __( 'Without it', 'diluxone-users' ),
			'name'   => 'diluxone_users_session_short_days',
			'value'  => (string) diluxone_users_option( 'diluxone_users_session_short_days' ),
			'suffix' => __( 'days', 'diluxone-users' ),
			'min'    => 1,
			'help'   => __( 'What somebody gets when they leave the box unticked on a form that offers it.', 'diluxone-users' ),
		)
	);

	diluxone_users_ui_fields_close();

	diluxone_users_ui_section( __( 'In their account', 'diluxone-users' ) );

	diluxone_users_ui_choices(
		array(
			array(
				'type'    => 'checkbox',
				'name'    => 'diluxone_users_sessions_show',
				'value'   => '1',
				'checked' => (bool) diluxone_users_option( 'diluxone_users_sessions_show' ),
				'title'   => __( 'Each person sees where they are signed in, and can close it', 'diluxone-users' ),
				'help'    => __( 'A box under Security in their account area, with one line per device. Unticked, the box is not there and closing a session stays an administrator’s job, from the report of open sessions.', 'diluxone-users' ),
			),
		)
	);

	/*
	 * Where the list went, and when the two numbers start to count. Both are
	 * things this screen tells rather than asks, and underneath the last tick
	 * box they read as more of the form — the aside about the report sat so
	 * close to the box above it that the two were one block of text. Beside
	 * the settings they are what somebody looks up after changing something.
	 */
	diluxone_users_ui_aside_close(
		static function (): void {
			diluxone_users_ui_note(
				__( 'What a change here reaches', 'diluxone-users' ),
				__( 'A session is given its length at the moment it starts, so the two numbers apply to whoever signs in from now on. The sessions already open keep the length they were given and end when they were going to.', 'diluxone-users' )
			);

			diluxone_users_ui_links(
				__( 'The rest of the subject', 'diluxone-users' ),
				array(
					array(
						'url'   => diluxone_users_admin_url( DILUXONE_USERS_REPORTS, array( 'tab' => 'sessions' ) ),
						'label' => __( 'Reports › Open sessions', 'diluxone-users' ),
						'help'  => __( 'Who has a session open right now, from where and until when. It is a report and not a setting: nothing there is saved, and it is where an administrator closes somebody’s sessions.', 'diluxone-users' ),
					),
					array(
						'url'   => diluxone_users_admin_url( 'diluxone-users-account', array( 'tab' => 'sections' ) ),
						'label' => __( 'Account area › Sections', 'diluxone-users' ),
						'help'  => __( 'The box each person gets belongs to the Security section, which is turned on and ordered there.', 'diluxone-users' ),
					),
				)
			);
		}
	);
}

/**
 * Who is signed in right now.
 *
 * The panel carries no form of its own and it is not a mistake: every row has
 * a "close sessions" form that posts to admin-post, and the search is a form
 * that posts to this very screen. A form inside a form is thrown away by the
 * browser, and there is nothing here to save anyway.
 *
 * Eight columns of rows, so the table is a wide block; and a rail all the
 * same, for the one thing this screen has to say that is not a row. Where the
 * length of a session is decided is a cross-reference, and above the table it
 * was a strip somebody had to read past to reach what they came for — which
 * is the shape this round was about taking off these screens. A wide block
 * does not rule a rail out: the rail is a fixed column, the table takes
 * everything left, and that is still wider than the measure it used to stop
 * at.
 */
function diluxone_users_screen_sessions_list(): void {
	// phpcs:disable WordPress.Security.NonceVerification.Recommended -- it is a read-only search.
	$search = isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : '';
	$page   = isset( $_GET['paged'] ) ? max( 1, absint( wp_unslash( $_GET['paged'] ) ) ) : 1;
	$per    = isset( $_GET['per'] ) ? max( 5, min( 200, absint( wp_unslash( $_GET['per'] ) ) ) ) : 20;
	$done   = isset( $_GET['diluxone_users_done'] ) ? sanitize_key( wp_unslash( $_GET['diluxone_users_done'] ) ) : '';
	// phpcs:enable

	// Back from closing somebody's sessions: it is said, once, at the top.
	if ( 'closed' === $done ) {
		diluxone_users_notice( __( 'Their sessions are closed: they are signed out everywhere.', 'diluxone-users' ) );
	}

	$result = diluxone_users_sessions_search( $search, $page, $per );
	$total  = $result['total'];
	$pages  = (int) max( 1, ceil( $total / $per ) );

	diluxone_users_ui_aside_open();

	diluxone_users_intro( __( 'Who is signed in right now, from where, and until when. Closing somebody’s sessions signs them out everywhere at once; they can come back in as always.', 'diluxone-users' ) );
	?>
	<form method="get" class="diluxone-users-search">
		<input type="hidden" name="page" value="<?php echo esc_attr( DILUXONE_USERS_REPORTS ); ?>">
		<input type="hidden" name="tab" value="sessions">
		<label class="screen-reader-text" for="diluxone-users-s"><?php esc_html_e( 'Search', 'diluxone-users' ); ?></label>
		<input type="search" id="diluxone-users-s" name="s" value="<?php echo esc_attr( $search ); ?>" placeholder="<?php esc_attr_e( 'Email, username or name…', 'diluxone-users' ); ?>">
		<?php submit_button( __( 'Search', 'diluxone-users' ), 'secondary', '', false ); ?>

		<a class="button" href="
		<?php
		echo esc_url(
			diluxone_users_admin_url(
				DILUXONE_USERS_REPORTS,
				array(
					'tab' => 'sessions',
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

		<?php
		$diluxone_users_refresh = diluxone_users_admin_url(
			DILUXONE_USERS_REPORTS,
			array(
				'tab'   => 'sessions',
				's'     => $search,
				'per'   => $per,
				'paged' => $page,
			)
		);
		?>
		<a class="button diluxone-users-search__refresh" href="<?php echo esc_url( $diluxone_users_refresh ); ?>">
			<span class="dashicons dashicons-update" aria-hidden="true"></span>
			<?php esc_html_e( 'Refresh', 'diluxone-users' ); ?>
		</a>

		<span class="diluxone-users-search__account">
			<?php
			printf(
					/* translators: 1: number of people, 2: current page, 3: total pages */
				esc_html__( '%1$s people with an open session · page %2$d of %3$d', 'diluxone-users' ),
				esc_html( number_format_i18n( $total ) ),
				(int) $page,
				(int) $pages
			);
			?>
		</span>
	</form>

	<?php diluxone_users_ui_wide_open(); ?>

	<table class="wp-list-table widefat fixed striped diluxone-users-list">
		<thead>
			<tr>
				<th class="diluxone-users-list__name"><?php esc_html_e( 'Person', 'diluxone-users' ); ?></th>
				<th><?php esc_html_e( 'Last sign-in', 'diluxone-users' ); ?></th>
				<th><?php esc_html_e( 'Expires', 'diluxone-users' ); ?></th>
				<th><?php esc_html_e( 'Status', 'diluxone-users' ); ?></th>
				<th><?php esc_html_e( 'IP', 'diluxone-users' ); ?></th>
				<th><?php esc_html_e( 'Device', 'diluxone-users' ); ?></th>
				<th class="diluxone-users-list__num"><?php esc_html_e( 'Sessions', 'diluxone-users' ); ?></th>
				<th class="diluxone-users-list__order"><?php esc_html_e( 'Actions', 'diluxone-users' ); ?></th>
			</tr>
		</thead>
		<tbody>
			<?php if ( array() === $result['rows'] ) : ?>
				<tr><td colspan="8"><?php esc_html_e( 'Nobody matches that.', 'diluxone-users' ); ?></td></tr>
			<?php endif; ?>

			<?php
			foreach ( $result['rows'] as $row ) :
				$current = $row['expires'] > time();
				?>
				<tr>
					<td class="diluxone-users-list__name">
						<strong><a href="<?php echo esc_url( get_edit_user_link( $row['user_id'] ) ); ?>"><?php echo esc_html( '' !== $row['name'] ? $row['name'] : $row['login'] ); ?></a></strong>
						<span class="diluxone-users-list__mail"><?php echo esc_html( $row['email'] ); ?></span>
					</td>
					<td><?php echo esc_html( $row['started'] ? (string) wp_date( 'j M Y, H:i', $row['started'] ) : '—' ); ?></td>
					<td><?php echo esc_html( $row['expires'] ? (string) wp_date( 'j M Y, H:i', $row['expires'] ) : '—' ); ?></td>
					<td>
						<span class="diluxone-users-pill diluxone-users-pill--<?php echo $current ? 'on' : 'off'; ?>">
							<?php echo $current ? esc_html__( 'Active', 'diluxone-users' ) : esc_html__( 'Expired', 'diluxone-users' ); ?>
						</span>
					</td>
					<td><code><?php echo esc_html( $row['ip'] ); ?></code></td>
					<td><?php echo esc_html( trim( $row['browser'] . ( '' !== $row['os'] ? ' · ' . $row['os'] : '' ) ) ); ?></td>
					<td class="diluxone-users-list__num"><?php echo esc_html( number_format_i18n( $row['sessions'] ) ); ?></td>
					<td class="diluxone-users-list__order">
						<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
							<input type="hidden" name="action" value="diluxone_users_sessions_admin">
							<input type="hidden" name="diluxone_users_user" value="<?php echo esc_attr( (string) $row['user_id'] ); ?>">
							<?php wp_nonce_field( 'diluxone_users_sessions_admin' ); ?>
							<button type="submit" class="button button-small"><?php esc_html_e( 'Close sessions', 'diluxone-users' ); ?></button>
						</form>
					</td>
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
					'tab' => 'sessions',
					's'   => $search,
					'per' => $per,
				)
			);

			echo wp_kses_post(
				paginate_links(
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
			diluxone_users_ui_links(
				__( 'The settings behind this report', 'diluxone-users' ),
				array(
					array(
						'url'   => diluxone_users_admin_url( DILUXONE_USERS_SECURITY, array( 'tab' => 'sessions' ) ),
						'label' => __( 'Security › Sessions', 'diluxone-users' ),
						'help'  => __( 'How long a session lasts, with and without “remember me”, and whether each person can see and close their own.', 'diluxone-users' ),
					),
				)
			);
		}
	);
}

/**
 * Sessions and the proxy, as four lines of the security summary.
 *
 * They come from one file because one file owns them, and they go in at the
 * priority of each tab so the table reads top to bottom the way the tabs do.
 *
 * The third line is not a setting and belongs here all the same: how long a
 * session lasts is a number nobody can check, and the number of people living
 * under it right now is the thing that makes it real. It carries the way to
 * the report, which is the one part of this subject that is not on Security.
 *
 * @param array<int, array<string, string>> $rows
 * @return array<int, array<string, string>>
 */
function diluxone_users_sessions_summary_rows( array $rows ): array {
	$long  = (int) diluxone_users_option( 'diluxone_users_session_long_days' );
	$short = (int) diluxone_users_option( 'diluxone_users_session_short_days' );
	$show  = (bool) diluxone_users_option( 'diluxone_users_sessions_show' );
	$tab   = diluxone_users_admin_url( DILUXONE_USERS_SECURITY, array( 'tab' => 'sessions' ) );

	$rows[] = array(
		'label'  => __( 'How long a session lasts', 'diluxone-users' ),
		'state'  => 'active',
		'detail' => sprintf(
			/* translators: 1: days with "remember me", 2: days without it */
			esc_html__( '%1$s days with “remember me”, %2$s days without. The e-mail link always counts as “remember me”.', 'diluxone-users' ),
			esc_html( number_format_i18n( $long ) ),
			esc_html( number_format_i18n( $short ) )
		),
		'url'    => $tab,
	);

	$rows[] = array(
		'label'  => __( 'Their own sessions', 'diluxone-users' ),
		'state'  => $show ? 'active' : 'off',
		'detail' => $show
			? esc_html__( 'Each person sees where they are signed in, from their account, and can close it.', 'diluxone-users' )
			: esc_html__( 'They see nothing: closing somebody’s session is an administrator’s job, from the report.', 'diluxone-users' ),
		'url'    => $tab,
	);

	// One row asked for, not a page of them: what is wanted here is the
	// number, and the search on the report is what the number is for.
	$open = (int) diluxone_users_sessions_search( '', 1, 1 )['total'];

	$rows[] = array(
		'label'  => __( 'Signed in right now', 'diluxone-users' ),
		// A count, and every other row of this table is a switch. "Off"
		// beside a number reads as though signing in had been turned off at
		// three in the morning, when all it says is that nobody is on.
		'state'  => $open > 0 ? 'active' : 'off',
		'word'   => $open > 0
			? _x( 'Somebody is', 'how many people have a session open', 'diluxone-users' )
			: _x( 'Nobody is', 'how many people have a session open', 'diluxone-users' ),
		'detail' => sprintf(
			/* translators: %s: how many people have a session open */
			esc_html( _n( '%s person has a session open.', '%s people have a session open.', $open, 'diluxone-users' ) ),
			esc_html( number_format_i18n( $open ) )
		),
		'url'    => diluxone_users_admin_url( DILUXONE_USERS_REPORTS, array( 'tab' => 'sessions' ) ),
		'change' => __( 'Reports › Open sessions →', 'diluxone-users' ),
	);

	return $rows;
}
add_filter( 'diluxone_users_security_summary', 'diluxone_users_sessions_summary_rows', 30 );

/**
 * Behind a proxy, as one line of the security summary.
 *
 * What is true today is not which header is chosen but which address the site
 * ends up with, so the line says both: a site reading the wrong header is a
 * site whose limits are counted against the proxy, and the address it shows
 * for the person reading the screen is the quickest way to see that.
 *
 * @param array<int, array<string, string>> $rows
 * @return array<int, array<string, string>>
 */
function diluxone_users_proxy_summary_rows( array $rows ): array {
	$chosen  = '' !== (string) diluxone_users_option( 'diluxone_users_ip_header', '' );
	$proxies = diluxone_users_trusted_proxies();
	$header  = diluxone_users_ip_headers()[ diluxone_users_ip_header() ] ?? diluxone_users_ip_header();
	$ip      = '<code>' . esc_html( diluxone_users_client_ip() ) . '</code>';

	$rows[] = array(
		'label'  => __( 'Behind a proxy', 'diluxone-users' ),
		'state'  => $chosen || array() !== $proxies ? 'active' : 'off',
		'detail' => $chosen || array() !== $proxies
			? sprintf(
				/* translators: 1: the header being read, e.g. X-Forwarded-For; 2: the address this site sees for the person reading the screen */
				esc_html__( 'Reading %1$s from a proxy it trusts, and it sees %2$s for you.', 'diluxone-users' ),
				'<code>' . esc_html( $header ) . '</code>',
				$ip
			)
			: sprintf(
				/* translators: %s: the address this site sees for the person reading the screen */
				esc_html__( 'Nothing is set up: the address is the one the connection came from, and it sees %s for you.', 'diluxone-users' ),
				$ip
			),
		'url'    => diluxone_users_admin_url( DILUXONE_USERS_SECURITY, array( 'tab' => 'proxy' ) ),
	);

	return $rows;
}
add_filter( 'diluxone_users_security_summary', 'diluxone_users_proxy_summary_rows', 50 );
