<?php
/**
 * The fields screen: a listing and, separately, each field's detail.
 *
 * It is the heart of the plugin — what people are asked for — and that is why
 * it is not an accordion of stacked forms: the list is taken in at a glance
 * and you go in to edit one alone. It is how any other WordPress list works.
 *
 * The key (`diluxone_users_something`) proposes itself from the name and
 * afterwards cannot be changed: it is the name the value was stored under on
 * every person, and renaming it would mean losing what is already there.
 *
 * @package DiluxOneUsers
 */

defined( 'ABSPATH' ) || exit;

/**
 * Turns a name into a valid and unique key.
 *
 * @param array<string, mixed> $used
 */
function diluxone_users_key_from( string $label, array $used ): string {
	$base = sanitize_key( remove_accents( $label ) );
	$base = 'diluxone_users_' . ( '' === $base ? 'field' : $base );

	$key = $base;
	$n   = 2;

	// Taken, or reserved: both mean the same thing here, which is that this
	// name is not free. A field called "Avatar" would otherwise be handed the
	// key the avatar itself is stored under and refused a moment later, with
	// nothing on screen to say why.
	//
	// The bound is not decoration. This walks until it finds a free name, so
	// it is only a loop that ends because every name it tries is a different
	// one — and the first version of the reserved list was by prefix, which
	// made every name it tried reserved as well. A hundred is far past any
	// real site and is a request that fails rather than one that never
	// answers.
	while ( $n <= 100 && ( in_array( $key, $used, true ) || ! diluxone_users_field_key_allowed( $key ) ) ) {
		$key = $base . '_' . $n;
		++$n;
	}

	return diluxone_users_field_key_allowed( $key ) && ! in_array( $key, $used, true ) ? $key : '';
}

/**
 * Where a field's "options" come from, which changes with the type.
 *
 * In a closed list they are the values; in a country, the codes pinned to the
 * top; in a phone, the default country. In the rest, nothing: a date field
 * has no options and there is no sense in showing it the box.
 *
 * @param array<string, mixed> $input
 * @return array<int, string>
 */
function diluxone_users_field_options_from( string $type, array $input ): array {
	switch ( $type ) {
		case 'select':
		case 'datalist':
			return array_map( 'trim', explode( "\n", (string) ( $input['options'] ?? '' ) ) );

		case 'country':
			return array_map( 'strval', (array) ( $input['preferred'] ?? array() ) );

		case 'phone':
			$default = (string) ( $input['default_country'] ?? '' );

			return '' === $default ? array() : array( $default );

		default:
			return array();
	}
}

/**
 * Saves a field (new or existing) and returns its key.
 *
 * @param array<string, mixed> $input
 */
function diluxone_users_field_save( array $input ): string {
	$fields = diluxone_users_fields( '', false );
	$key    = sanitize_key( (string) ( $input['key'] ?? '' ) );
	$label  = sanitize_text_field( (string) ( $input['label'] ?? '' ) );

	if ( '' === $label ) {
		return '';
	}

	if ( '' === $key ) {
		$key = diluxone_users_key_from( $label, wp_list_pluck( $fields, 'key' ) );
	}

	// The key is the meta key this field writes to, so a handful of names are
	// not available: WordPress's own, and the ones this plugin uses to hold
	// somebody's second factor and their keys.
	if ( ! diluxone_users_field_key_allowed( $key ) ) {
		return '';
	}

	$field = diluxone_users_normalize_field(
		array(
			'key'         => $key,
			'label'       => $label,
			'type'        => (string) ( $input['type'] ?? 'text' ),
			'edit'        => sanitize_key( (string) ( $input['edit'] ?? 'always' ) ),
			'edit_max'    => (int) ( $input['edit_max'] ?? 1 ),
			'help'        => (string) ( $input['help'] ?? '' ),
			'placeholder' => (string) ( $input['placeholder'] ?? '' ),
			'options'     => diluxone_users_field_options_from( (string) ( $input['type'] ?? 'text' ), $input ),
			'required'    => ! empty( $input['required'] ),
			'group'       => (string) ( $input['group'] ?? 'extra' ),
			'active'      => ! empty( $input['active'] ),
		)
	);

	$replaced = false;

	foreach ( $fields as $i => $existing ) {
		if ( $existing['key'] === $key ) {
			$fields[ $i ] = $field;
			$replaced     = true;
			break;
		}
	}

	if ( ! $replaced ) {
		$fields[] = $field;
	}

	diluxone_users_update_option( 'diluxone_users_fields', $fields );

	return $key;
}

/**
 * Deletes a field definition.
 *
 * The values people have already filled in are NOT touched: if the field
 * comes back tomorrow, they come back with it. Deleting 25,000 rows over one
 * click on a settings screen would be an expensive surprise.
 */
function diluxone_users_field_delete( string $key ): void {
	$fields = array_values(
		array_filter(
			diluxone_users_fields( '', false ),
			static fn( array $f ): bool => $f['key'] !== $key
		)
	);

	diluxone_users_update_option( 'diluxone_users_fields', $fields );
}

/**
 * Saving, deleting or moving a field.
 *
 * On admin_init, not inside the screen: by the time WordPress calls the
 * callback of an admin page it has already printed the headers, and the
 * redirect ends in a "headers already sent" notice in the log.
 */
function diluxone_users_fields_actions(): void {
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- which screen this is; every action below checks its own nonce.
	if ( 'diluxone-users-fields' !== sanitize_key( wp_unslash( $_GET['page'] ?? '' ) ) || ! diluxone_users_admin_owns( 'network' ) || ! current_user_can( diluxone_users_admin_cap() ) ) {
		return;
	}

	// The suggested fields ticked on their tab, added in one go.
	if ( isset( $_POST['diluxone_users_suggested_nonce'] ) && wp_verify_nonce( sanitize_key( wp_unslash( $_POST['diluxone_users_suggested_nonce'] ) ), 'diluxone_users_suggested' ) ) {
		$added = diluxone_users_add_suggested_fields( array_map( 'sanitize_key', (array) wp_unslash( $_POST['diluxone_users_suggested'] ?? array() ) ) );

		wp_safe_redirect( diluxone_users_admin_url( 'diluxone-users-fields', array( 'diluxone_users_done' => $added > 0 ? 'suggested' : 'nosuggested' ) ) );
		exit;
	}

	if ( isset( $_POST['diluxone_users_field_nonce'] ) && wp_verify_nonce( sanitize_key( wp_unslash( $_POST['diluxone_users_field_nonce'] ) ), 'diluxone_users_field' ) ) {
		// Cleaned as it is read (multi-line text keeps its lines: the options of
		// a list are one per line), and typed again field by field on save.
		$key = diluxone_users_field_save( (array) map_deep( wp_unslash( $_POST['diluxone_users_field'] ?? array() ), 'sanitize_textarea_field' ) );

		wp_safe_redirect( diluxone_users_admin_url( 'diluxone-users-fields', array( 'diluxone_users_done' => '' === $key ? 'nolabel' : 'saved' ) ) );
		exit;
	}

	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- whether an action was asked for; check_admin_referer() right below verifies it.
	if ( ! isset( $_GET['diluxone_users_action'], $_GET['field'] ) ) {
		return;
	}

	check_admin_referer( 'diluxone_users_field_action' );

	$key    = sanitize_key( wp_unslash( $_GET['field'] ) );
	$action = sanitize_key( wp_unslash( $_GET['diluxone_users_action'] ) );

	if ( 'delete' === $action && ! diluxone_users_field_is_native( $key ) ) {
		diluxone_users_field_delete( $key );
	} elseif ( 'up' === $action ) {
		diluxone_users_field_move( $key, -1 );
	} elseif ( 'down' === $action ) {
		diluxone_users_field_move( $key, 1 );
	}

	wp_safe_redirect( diluxone_users_admin_url( 'diluxone-users-fields', array( 'diluxone_users_done' => $action ) ) );
	exit;
}
add_action( 'admin_init', 'diluxone_users_fields_actions' );

/** Moves a field up or down in the order. */
function diluxone_users_field_move( string $key, int $dir ): void {
	$fields = diluxone_users_fields( '', false );
	$keys   = wp_list_pluck( $fields, 'key' );
	$i      = array_search( $key, $keys, true );

	if ( false === $i ) {
		return;
	}

	$j = (int) $i + $dir;

	if ( $j < 0 || $j >= count( $fields ) ) {
		return;
	}

	[ $fields[ $i ], $fields[ $j ] ] = array( $fields[ $j ], $fields[ $i ] );

	diluxone_users_update_option( 'diluxone_users_fields', $fields );
}

/* ── The screen ────────────────────────────────────────────────────── */

/** The fields screen: the listing, or one field's detail. */
function diluxone_users_screen_fields(): void {
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- which field the screen shows, nothing is changed.
	$editing = isset( $_GET['field'] ) ? sanitize_key( wp_unslash( $_GET['field'] ) ) : '';

	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- which message to show after the redirect, from a fixed list.
	$done = isset( $_GET['diluxone_users_done'] ) ? sanitize_key( wp_unslash( $_GET['diluxone_users_done'] ) ) : '';

	if ( '' !== $editing || isset( $_GET['diluxone_users_new'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- it only picks what the screen shows.

		/*
		 * The dialog opens this very screen in the background and lifts the
		 * form out of it: there is no second copy of the form to keep in step,
		 * and the address it fetches is the address the link already points
		 * at — so with JavaScript off the link is still a link to a screen
		 * that works.
		 */
		diluxone_users_screen_field_edit( $editing );
		return;
	}

	$notices = array(
		'saved'       => __( 'Field saved.', 'diluxone-users' ),
		'delete'      => __( 'Field deleted. The data already stored was left alone.', 'diluxone-users' ),
		'nolabel'     => __( 'A field needs a name.', 'diluxone-users' ),
		'suggested'   => __( 'The fields ticked were added at the end of the list. Each one can be edited, moved or deleted like any other.', 'diluxone-users' ),
		'nosuggested' => __( 'Nothing was added: no suggested field was ticked, or the ones ticked were already on the list.', 'diluxone-users' ),
	);

	if ( isset( $notices[ $done ] ) ) {
		diluxone_users_notice( $notices[ $done ], in_array( $done, array( 'nolabel', 'nosuggested' ), true ) ? 'error' : 'success' );
	}

	diluxone_users_screen_panels( 'diluxone-users-fields', __( 'User fields', 'diluxone-users' ) );
}

/**
 * Its tabs, by the registry, so an add-on can add one of its own.
 *
 * Both of them draw their own markup and neither is a settings form — the
 * list has its own actions and the other only explains — so both go in with
 * `form => false`.
 */
function diluxone_users_fields_panels(): void {
	diluxone_users_register_panel(
		'diluxone-users-fields',
		'list',
		array(
			'label'    => __( 'Fields', 'diluxone-users' ),
			'position' => 10,
			'render'   => 'diluxone_users_screen_fields_list',
			'form'     => false,
		)
	);

	diluxone_users_register_panel(
		'diluxone-users-fields',
		'suggested',
		array(
			'label'    => __( 'Suggested fields', 'diluxone-users' ),
			'position' => 15,
			'render'   => 'diluxone_users_screen_fields_suggested',
			'form'     => false,
		)
	);

	diluxone_users_register_panel(
		'diluxone-users-fields',
		'usage',
		array(
			'label'    => __( 'How to use them', 'diluxone-users' ),
			'position' => 20,
			'render'   => 'diluxone_users_screen_fields_usage',
			'form'     => false,
		)
	);
}
add_action( 'diluxone_users_register_panels', 'diluxone_users_fields_panels' );

/**
 * What both screens say while nobody can register on this site.
 *
 * The fields are what the registration form asks for, so a site where nobody
 * signs themselves up has a screenful of questions that are put to nobody.
 * That is not a mistake and there is nothing to fix — an administrator
 * creating accounts by hand still fills them in — but it is worth saying
 * where the questions are, rather than leaving somebody to wonder why the
 * form they spent the afternoon on never appears anywhere.
 *
 * The same sentence belongs on the registration tab, said from the other
 * side: there the doors are the subject and the fields are the consequence.
 * It is written once, here, so the two cannot drift apart — and what it asks
 * is the registration screen's own answer, `diluxone_users_register_doors_open()`,
 * rather than a second reading of the four options underneath it.
 *
 * @param string $from 'fields' on the fields screen, 'register' on the registration tab.
 */
function diluxone_users_nobody_registers_notice( string $from = 'fields' ): void {
	if ( array() !== diluxone_users_register_doors_open() ) {
		return;
	}

	$here = 'register' === $from;

	$says = __( 'This site has registration closed: no door into an account is open, so nobody signs themselves up.', 'diluxone-users' ) . ' ' . (
		$here
			? __( 'Which decides the user fields as well: they are asked of nobody, and the only person who ever fills them in is whoever administers the site, creating an account by hand.', 'diluxone-users' )
			: __( 'So nothing on this screen is asked of anybody: the only person who ever fills these in is whoever administers the site, creating an account by hand.', 'diluxone-users' )
	);

	diluxone_users_ui_notice(
		$says . sprintf(
			' <a href="%s">%s</a>',
			esc_url(
				$here
					? diluxone_users_admin_url( 'diluxone-users-fields' )
					: diluxone_users_admin_url( 'diluxone-users-login', array( 'tab' => 'register' ) )
			),
			$here
				? esc_html__( 'The fields it is about →', 'diluxone-users' )
				: esc_html__( 'Open a door →', 'diluxone-users' )
		),
		'warning'
	);
}

/**
 * Screen fields list.
 *
 * Seven columns of what a field is: its name, its type, where it goes,
 * whether the form needs it, how often it can change, whether it is on, and
 * the two arrows that move it. That is a table of rows and not a column of
 * options, so it takes the width WordPress gives instead of the measure — at
 * the measure the same seven columns cut their own words in half and left the
 * right-hand third of the window empty to do it.
 *
 * It has a rail all the same, and that is the part this screen had wrong. A
 * wide block and a rail are not a choice between two: the rail is a fixed
 * column at the side and the table takes everything else, which is more room
 * than it had when the whole screen stopped at the measure. What the rail is
 * for is the two things this tab used to say above the table and under it —
 * that nobody signs themselves up on this site, and where the definitions and
 * the answers are actually stored. Neither is something to do, and above the
 * table the first one pushed the table itself below the fold.
 */
function diluxone_users_screen_fields_list(): void {
	$fields = diluxone_users_fields( '', false );
	$types  = diluxone_users_field_types();
	$groups = diluxone_users_groups();

	diluxone_users_ui_aside_open();
	?>
	<p class="diluxone-users-admin__actions">
		<a class="button button-primary"
			href="<?php echo esc_url( diluxone_users_admin_url( 'diluxone-users-fields', array( 'diluxone_users_new' => 1 ) ) ); ?>"
			data-diluxone-users-field-dialog
			data-diluxone-users-dialog-title="<?php esc_attr_e( 'New field', 'diluxone-users' ); ?>">
			<?php esc_html_e( 'Add field', 'diluxone-users' ); ?>
		</a>
		<?php if ( array() !== diluxone_users_suggested_missing() ) : ?>
			<a class="button" href="<?php echo esc_url( diluxone_users_admin_url( 'diluxone-users-fields', array( 'tab' => 'suggested' ) ) ); ?>"><?php esc_html_e( 'Add suggested fields', 'diluxone-users' ); ?></a>
		<?php endif; ?>
	</p>

	<?php diluxone_users_ui_wide_open(); ?>

	<table class="wp-list-table widefat fixed striped diluxone-users-list">
		<thead>
			<tr>
				<th class="diluxone-users-list__name"><?php esc_html_e( 'Name', 'diluxone-users' ); ?></th>
				<th><?php esc_html_e( 'Type', 'diluxone-users' ); ?></th>
				<th><?php esc_html_e( 'Where', 'diluxone-users' ); ?></th>
				<th><?php esc_html_e( 'Required', 'diluxone-users' ); ?></th>
				<th><?php esc_html_e( 'They can change it', 'diluxone-users' ); ?></th>
				<th><?php esc_html_e( 'Status', 'diluxone-users' ); ?></th>
				<th class="diluxone-users-list__order"><?php esc_html_e( 'Order', 'diluxone-users' ); ?></th>
			</tr>
		</thead>
		<tbody>
			<?php if ( array() === $fields ) : ?>
				<tr><td colspan="7"><?php esc_html_e( 'No fields yet.', 'diluxone-users' ); ?></td></tr>
			<?php endif; ?>

			<?php
			foreach ( $fields as $field ) :
				$edit = diluxone_users_admin_url( 'diluxone-users-fields', array( 'field' => $field['key'] ) );
				?>
				<tr>
					<td class="diluxone-users-list__name">
						<strong><a href="<?php echo esc_url( $edit ); ?>" data-diluxone-users-field-dialog data-diluxone-users-dialog-title="<?php echo esc_attr( $field['label'] ); ?>"><?php echo esc_html( $field['label'] ); ?></a></strong>
						<code><?php echo esc_html( $field['key'] ); ?></code>
						<div class="row-actions">
							<span class="edit"><a href="<?php echo esc_url( $edit ); ?>" data-diluxone-users-field-dialog data-diluxone-users-dialog-title="<?php echo esc_attr( $field['label'] ); ?>"><?php esc_html_e( 'Edit', 'diluxone-users' ); ?></a></span>

							<?php
							/*
							 * WordPress's own are not deleted: the value exists all
							 * the same and the dashboard and half the site's plugins
							 * use it. They are hidden.
							 */
							?>
							<?php if ( ! diluxone_users_field_is_native( $field['key'] ) ) : ?>
								<span class="trash"> |
									<a class="diluxone-users-danger"
										href="
										<?php
										echo esc_url(
											wp_nonce_url(
												diluxone_users_admin_url(
													'diluxone-users-fields',
													array(
														'field' => $field['key'],
														'diluxone_users_action' => 'delete',
													)
												),
												'diluxone_users_field_action'
											)
										);
										?>
												"
										data-diluxone-users-confirm="<?php esc_attr_e( 'Delete this field? The data already stored is kept.', 'diluxone-users' ); ?>">
										<?php esc_html_e( 'Delete', 'diluxone-users' ); ?>
									</a>
								</span>
							<?php endif; ?>
						</div>
					</td>
					<td><?php echo esc_html( $types[ $field['type'] ] ?? $field['type'] ); ?></td>
					<td><?php echo esc_html( $groups[ $field['group'] ] ?? $field['group'] ); ?></td>
					<td><?php echo $field['required'] ? esc_html__( 'Yes', 'diluxone-users' ) : '—'; ?></td>
					<td>
						<?php
						if ( 'never' === $field['edit'] ) {
							esc_html_e( 'Read only', 'diluxone-users' );
						} elseif ( 'limited' === $field['edit'] ) {
							printf(
									/* translators: %d: how many times it can be changed */
								esc_html( _n( '%d time', '%d times', (int) $field['edit_max'], 'diluxone-users' ) ),
								(int) $field['edit_max']
							);
						} else {
							echo '&mdash;';
						}
						?>
					</td>
					<td>
						<span class="diluxone-users-pill diluxone-users-pill--<?php echo $field['active'] ? 'on' : 'off'; ?>">
							<?php echo $field['active'] ? esc_html__( 'Active', 'diluxone-users' ) : esc_html__( 'Hidden', 'diluxone-users' ); ?>
						</span>
					</td>
					<td class="diluxone-users-list__order">
						<a class="button button-small" href="
						<?php
						echo esc_url(
							wp_nonce_url(
								diluxone_users_admin_url(
									'diluxone-users-fields',
									array(
										'field' => $field['key'],
										'diluxone_users_action' => 'up',
									)
								),
								'diluxone_users_field_action'
							)
						);
						?>
																" aria-label="<?php esc_attr_e( 'Move up', 'diluxone-users' ); ?>">&uarr;</a>
						<a class="button button-small" href="
						<?php
						echo esc_url(
							wp_nonce_url(
								diluxone_users_admin_url(
									'diluxone-users-fields',
									array(
										'field' => $field['key'],
										'diluxone_users_action' => 'down',
									)
								),
								'diluxone_users_field_action'
							)
						);
						?>
																" aria-label="<?php esc_attr_e( 'Move down', 'diluxone-users' ); ?>">&darr;</a>
					</td>
				</tr>
			<?php endforeach; ?>
		</tbody>
	</table>

	<?php
	diluxone_users_ui_wide_close();

	diluxone_users_ui_aside_close(
		static function (): void {
			diluxone_users_nobody_registers_notice();
			diluxone_users_fields_where();
		}
	);

	diluxone_users_field_dialog();
}

/**
 * The suggested fields that are not on the list yet.
 *
 * @return array<int, array<string, mixed>>
 */
function diluxone_users_suggested_missing(): array {
	$have = wp_list_pluck( diluxone_users_fields( '', false ), 'key' );

	return array_values(
		array_filter(
			diluxone_users_suggested_fields(),
			static fn( array $field ): bool => ! in_array( (string) ( $field['key'] ?? '' ), $have, true )
		)
	);
}

/**
 * Suggested fields: the ones a site often asks for, added on purpose.
 *
 * The plugin starts with the first and last name and nothing else; the rest
 * used to arrive with it, and a site found itself asking strangers for their
 * birthday and their phone because a plugin was turned on. Here they are one
 * tick away instead, each with what it is, and pressing the button in the
 * rail adds the ones ticked to the list. A tab of its own and its own form:
 * the list beside it is a table of links, not a form.
 */
function diluxone_users_screen_fields_suggested(): void {
	$missing = diluxone_users_suggested_missing();
	$types   = diluxone_users_field_types();
	$form    = 'diluxone-users-suggested-form';

	diluxone_users_ui_aside_open();

	diluxone_users_intro( __( 'A few questions many sites ask the people who register. None of them is asked until it is added here: tick the ones this site needs and they join the list, where each can be edited, made required, moved or deleted like any other field.', 'diluxone-users' ) );

	diluxone_users_ui_section( __( 'Add suggested fields', 'diluxone-users' ) );

	if ( array() === $missing ) {
		diluxone_users_not_now(
			__( 'Every suggested field is already on the list.', 'diluxone-users' ),
			diluxone_users_admin_url( 'diluxone-users-fields' ),
			__( 'The fields →', 'diluxone-users' )
		);
	} else {
		$cards = array();

		foreach ( $missing as $field ) {
			$type    = (string) ( $field['type'] ?? 'text' );
			$cards[] = array(
				'type'  => 'checkbox',
				'name'  => 'diluxone_users_suggested[]',
				'value' => (string) $field['key'],
				'title' => (string) $field['label'],
				// Its type, and what it says to the person filling it in.
				'help'  => esc_html( (string) ( $types[ $type ] ?? $type ) ) . ( '' !== (string) ( $field['help'] ?? '' ) ? ' · ' . esc_html( (string) $field['help'] ) : '' ),
			);
		}

		printf( '<form method="post" id="%1$s" action="%2$s">', esc_attr( $form ), esc_url( diluxone_users_admin_url( 'diluxone-users-fields', array( 'tab' => 'suggested' ) ) ) );
		wp_nonce_field( 'diluxone_users_suggested', 'diluxone_users_suggested_nonce' );
		diluxone_users_ui_choices( $cards );
		echo '</form>';

		diluxone_users_ui_save( $form, __( 'Add the ones ticked', 'diluxone-users' ) );
	}

	diluxone_users_ui_aside_close(
		static function () use ( $missing ): void {
			diluxone_users_ui_aside_state(
				array() === $missing
					? esc_html__( 'Every suggested field is on the list.', 'diluxone-users' )
					: esc_html(
						sprintf(
							/* translators: %s: how many suggested fields are not on the list */
							_n( '%s suggested field is not on the list.', '%s suggested fields are not on the list.', count( $missing ), 'diluxone-users' ),
							number_format_i18n( count( $missing ) )
						)
					),
				array() === $missing ? 'active' : 'off'
			);

			diluxone_users_ui_note(
				__( 'Why they are not there from the start', 'diluxone-users' ),
				array(
					esc_html__( 'Asking for somebody’s birthday, gender or phone is a decision about their data, and it is the site’s to make — not something a plugin should do on the day it is turned on.', 'diluxone-users' ),
					esc_html__( 'A field deleted later keeps the answers already given, the same as any other.', 'diluxone-users' ),
				)
			);
		}
	);
}

/**
 * Where the definitions and the answers actually live.
 *
 * In the rail, which is where background belongs: above the table it pushed
 * the table below the fold, and under it it was read after somebody had
 * stopped reading.
 *
 * It is a note of the design system's and not a panel of its own any more. It
 * was a box with a title, a rule down its left and an icon, drawn here by
 * hand — which is to say it was the plugin's second way of saying something
 * with no control in it, two screens away from the first.
 */
function diluxone_users_fields_where(): void {
	// The two names go in a paragraph of their own and not inside the sentence
	// before them. A <code> chip is an atom the browser cannot break, so in
	// the middle of a paragraph it jumps to the next line whole and leaves the
	// previous one short — which reads as a line break that nobody typed.
	diluxone_users_ui_note(
		__( 'Where all this lives', 'diluxone-users' ),
		array(
			esc_html__( 'What each field IS —its name, type and behaviour— is one WordPress option. What each PERSON answered is user meta: one row per person and per field, with the field key as the name. No extra tables.', 'diluxone-users' ),
			sprintf(
				'%1$s <code>diluxone_users_fields</code> · %2$s <code>%3$s</code>',
				esc_html__( 'The definition:', 'diluxone-users' ),
				esc_html__( 'The answers:', 'diluxone-users' ),
				esc_html( $GLOBALS['wpdb']->usermeta )
			),
			esc_html__( 'That is why the key cannot change once the field exists, and why deleting a field leaves the answers alone: they are two different things.', 'diluxone-users' ),
		)
	);
}

/**
 * The dialog a field is edited in.
 *
 * It is empty markup: the form is fetched from the screen that already draws
 * it and dropped in here (see diluxone-users-admin.js). A <dialog> and not a
 * div with a z-index, because the browser already knows how to grey out what
 * is behind it, keep the keyboard inside it and close it on Escape — three
 * things that are easy to write badly by hand.
 */
function diluxone_users_field_dialog(): void {
	?>
	<dialog class="diluxone-users-dialog" data-diluxone-users-dialog
		data-diluxone-users-cancel="<?php esc_attr_e( 'Cancel', 'diluxone-users' ); ?>"
		aria-labelledby="diluxone-users-dialog-title">
		<div class="diluxone-users-dialog__head">
			<h2 id="diluxone-users-dialog-title" data-diluxone-users-dialog-heading></h2>
			<button type="button" class="diluxone-users-dialog__close" data-diluxone-users-dialog-close aria-label="<?php esc_attr_e( 'Close', 'diluxone-users' ); ?>">
				<span class="dashicons dashicons-no-alt" aria-hidden="true"></span>
			</button>
		</div>
		<div class="diluxone-users-dialog__body" data-diluxone-users-dialog-body>
			<p class="diluxone-users-dialog__loading"><?php esc_html_e( 'Loading…', 'diluxone-users' ); ?></p>
		</div>
	</dialog>
	<?php
}

/** A field's detail. With an empty key, it is a new one. */
function diluxone_users_screen_field_edit( string $key ): void {
	$field = '' === $key ? diluxone_users_normalize_field(
		array(
			'group'  => 'extra',
			'active' => 1,
		)
	) : diluxone_users_field( $key );

	if ( null === $field ) {
		diluxone_users_screen_open( __( 'User fields', 'diluxone-users' ) );
		diluxone_users_notice( __( 'That field does not exist.', 'diluxone-users' ), 'error' );
		diluxone_users_screen_close();
		return;
	}

	$fresh = '' === $key;

	diluxone_users_screen_open(
		$fresh
		? __( 'New field', 'diluxone-users' )
		: sprintf( /* translators: %s: field name */ __( 'Field: %s', 'diluxone-users' ), $field['label'] )
	);
	?>
	<p><a href="<?php echo esc_url( diluxone_users_admin_url( 'diluxone-users-fields' ) ); ?>">&larr; <?php esc_html_e( 'Back to the list', 'diluxone-users' ); ?></a></p>

	<?php
	/*
	 * The class is what the dialog lifts. This screen is fetched whole and the
	 * form is taken out of it (see diluxone-users-admin.js), so it is the one
	 * thing on the page that has to be findable by name — and it was not, so
	 * every "Edit" fell through to loading the whole screen instead of opening
	 * the dialog over the list.
	 */
	?>
	<form method="post" class="diluxone-users-form-admin">
		<?php wp_nonce_field( 'diluxone_users_field', 'diluxone_users_field_nonce' ); ?>
		<input type="hidden" name="diluxone_users_field[key]" value="<?php echo esc_attr( $field['key'] ); ?>">

		<?php diluxone_users_ui_section( __( 'What it is', 'diluxone-users' ) ); ?>

		<?php
		diluxone_users_ui_text(
			array(
				'label'    => __( 'Name', 'diluxone-users' ),
				'id'       => 'diluxone-users-label',
				'name'     => 'diluxone_users_field[label]',
				'value'    => (string) $field['label'],
				'required' => true,
				'help'     => $fresh
					? __( 'The key is generated from the name.', 'diluxone-users' )
					: sprintf(
						/* translators: %s: the key the answers are stored under */
						__( 'Key: <code>%s</code> — it cannot change: it is the name the data is stored under.', 'diluxone-users' ),
						esc_html( $field['key'] )
					),
			)
		);
		?>

		<?php
		/*
		 * WordPress's own come with their type set: changing the type of the
		 * first name does not improve it, and can break it. Switched off, the
		 * box sends nothing — so `keep` posts the type it is showing, and the
		 * save reads a locked field as the field it is rather than as a field
		 * whose type somebody cleared.
		 */
		diluxone_users_ui_select(
			array(
				'label'    => __( 'Type', 'diluxone-users' ),
				'id'       => 'diluxone-users-type',
				'name'     => 'diluxone_users_field[type]',
				'value'    => (string) $field['type'],
				'options'  => diluxone_users_field_types(),
				'disabled' => diluxone_users_field_is_native( $field['key'] ),
				'keep'     => true,
				'help'     => diluxone_users_field_is_native( $field['key'] )
					? __( 'This one is WordPress’s own: it can be renamed, reordered, made required or hidden, but it keeps its type and cannot be deleted.', 'diluxone-users' )
					: __( '“Country” shows the full list with its dial codes; “Phone with country code” splits the number in two and stores it in international format.', 'diluxone-users' ),
			)
		);
		?>

		<?php
		/*
		 * "How many times" belongs to the answer that limits them, and to no
		 * other. Loose underneath the three, it was a number nobody could
		 * place: it did nothing under two of the answers and the screen never
		 * said which one it was for.
		 */
		diluxone_users_ui_section(
			__( 'Who can change it', 'diluxone-users' ),
			__( 'This is about the person the data belongs to. Whoever administers the site can always change it from that person’s profile: otherwise a field with one change in it turns into a typo nobody can fix.', 'diluxone-users' )
		);

		diluxone_users_ui_choices(
			array(
				array(
					'name'    => 'diluxone_users_field[edit]',
					'value'   => 'always',
					'checked' => 'always' === $field['edit'],
					'title'   => __( 'Whenever they want', 'diluxone-users' ),
					'help'    => __( 'The field stays open in their account area, and they correct it the day it changes.', 'diluxone-users' ),
				),
				array(
					'name'     => 'diluxone_users_field[edit]',
					'value'    => 'limited',
					'checked'  => 'limited' === $field['edit'],
					'title'    => __( 'Only a few times, and then no more', 'diluxone-users' ),
					'help'     => __( 'The field closes once they have used up their goes. For what should not keep moving: a document number, a date of birth.', 'diluxone-users' ),
					'children' => static function () use ( $field ): void {
						diluxone_users_ui_number(
							array(
								'label'  => __( 'How many times', 'diluxone-users' ),
								'id'     => 'diluxone-users-edit-max',
								'name'   => 'diluxone_users_field[edit_max]',
								'value'  => (string) $field['edit_max'],
								'suffix' => __( 'changes, and then it closes', 'diluxone-users' ),
								'min'    => 1,
								'max'    => 99,
								'help'   => __( 'Counted one person at a time, and only for the changes they make themselves: saving the same thing again spends nothing, and an administrator fixing it spends nobody’s goes.', 'diluxone-users' ),
							)
						);
					},
				),
				array(
					'name'    => 'diluxone_users_field[edit]',
					'value'   => 'never',
					'checked' => 'never' === $field['edit'],
					'title'   => __( 'Never: they see it, and whoever administers changes it', 'diluxone-users' ),
					'help'    => __( 'It is shown to them and cannot be typed in. For what the site decides and not the person: a membership number, a category.', 'diluxone-users' ),
				),
			)
		);
		?>

		<?php diluxone_users_ui_section( __( 'What the person sees', 'diluxone-users' ) ); ?>

		<?php
		diluxone_users_ui_select(
			array(
				'label'   => __( 'Where it goes', 'diluxone-users' ),
				'id'      => 'diluxone-users-group',
				'name'    => 'diluxone_users_field[group]',
				'value'   => (string) $field['group'],
				'options' => diluxone_users_groups(),
				'help'    => __( 'The profile form shows two blocks: the essentials first, and underneath the optional ones with their own explanation. This decides which one the field lands in.', 'diluxone-users' ),
			)
		);

		diluxone_users_ui_text(
			array(
				'label' => __( 'Help text', 'diluxone-users' ),
				'id'    => 'diluxone-users-help',
				'name'  => 'diluxone_users_field[help]',
				'value' => (string) $field['help'],
				'help'  => __( 'Why we are asking. Shown under the field.', 'diluxone-users' ),
			)
		);
		?>

		<?php
		/*
		 * The rest of this block belongs to a type and not to every field: a
		 * date has no list of values and a country needs no placeholder. The
		 * wrapper is what the script hides — the piece the design system
		 * draws knows nothing about types, and does not need to.
		 */
		?>
		<div class="diluxone-users-if-type" data-type="text textarea email url number datalist phone">
			<?php
			diluxone_users_ui_text(
				array(
					'label' => __( 'Placeholder text', 'diluxone-users' ),
					'id'    => 'diluxone-users-placeholder',
					'name'  => 'diluxone_users_field[placeholder]',
					'value' => (string) $field['placeholder'],
					'help'  => __( 'Shown in grey inside the empty field.', 'diluxone-users' ),
				)
			);
			?>
		</div>

		<div class="diluxone-users-if-type" data-type="select datalist">
			<?php
			diluxone_users_ui_textarea(
				array(
					'label' => __( 'Values', 'diluxone-users' ),
					'id'    => 'diluxone-users-options',
					'name'  => 'diluxone_users_field[options]',
					'value' => implode( "\n", 'country' === $field['type'] || 'phone' === $field['type'] ? array() : $field['options'] ),
					'rows'  => 5,
					'code'  => true,
					'help'  => __( 'One per line. In a fixed list they are the only accepted values; in text with suggestions they are just hints and the person can write something else.', 'diluxone-users' ),
				)
			);
			?>
		</div>

		<div class="diluxone-users-if-type" data-type="country">
			<?php diluxone_users_ui_field_open( __( 'Countries shown first', 'diluxone-users' ), 'diluxone-users-preferred' ); ?>
				<select id="diluxone-users-preferred" name="diluxone_users_field[preferred][]" multiple size="8" class="diluxone-users-multi">
					<?php foreach ( diluxone_users_countries_sorted() as $iso => $country_name ) : ?>
						<option value="<?php echo esc_attr( $iso ); ?>" <?php selected( in_array( $iso, $field['options'], true ) ); ?>>
							<?php echo esc_html( $country_name ); ?>
						</option>
					<?php endforeach; ?>
				</select>
			<?php
			diluxone_users_ui_field_close(
				sprintf(
					/* translators: %d: number of countries */
					esc_html__( 'Optional. The list of %d countries comes with the plugin — there is nothing to load. These ones go on top, separated from the rest, so nobody has to scroll to find the one next door.', 'diluxone-users' ),
					count( diluxone_users_countries() )
				)
			);
			?>
		</div>

		<div class="diluxone-users-if-type" data-type="phone">
			<?php
			$diluxone_users_dials = array( '' => __( '— None —', 'diluxone-users' ) );

			foreach ( diluxone_users_countries_sorted() as $diluxone_users_iso => $diluxone_users_country ) {
				$diluxone_users_dials[ $diluxone_users_iso ] = $diluxone_users_country . ' +' . diluxone_users_country_dial( (string) $diluxone_users_iso );
			}

			diluxone_users_ui_select(
				array(
					'label'   => __( 'Country selected by default', 'diluxone-users' ),
					'id'      => 'diluxone-users-default-country',
					'name'    => 'diluxone_users_field[default_country]',
					'value'   => (string) ( $field['options'][0] ?? '' ),
					'options' => $diluxone_users_dials,
					'help'    => __( 'The dial code the field comes with. The person can change it: the full list is always there.', 'diluxone-users' ),
				)
			);
			?>
		</div>

		<?php
		diluxone_users_ui_section( __( 'What the form does with it', 'diluxone-users' ) );

		diluxone_users_ui_choices(
			array(
				array(
					'type'    => 'checkbox',
					'name'    => 'diluxone_users_field[required]',
					'value'   => '1',
					'checked' => (bool) $field['required'],
					'title'   => __( 'Required: no form goes through without it', 'diluxone-users' ),
					'help'    => __( 'It is also one of the things asked for on the registration form, before the account exists.', 'diluxone-users' ),
				),
				array(
					'type'    => 'checkbox',
					'name'    => 'diluxone_users_field[active]',
					'value'   => '1',
					'checked' => (bool) $field['active'],
					'title'   => __( 'Active: it shows up in the forms', 'diluxone-users' ),
					'help'    => __( 'Unticked it leaves every form and keeps both its definition and everything already answered, waiting for the day it comes back.', 'diluxone-users' ),
				),
			)
		);
		?>

		<?php submit_button( $fresh ? __( 'Add field', 'diluxone-users' ) : __( 'Save field', 'diluxone-users' ) ); ?>
	</form>
	<?php
	diluxone_users_screen_close();
}

/**
 * Where the fields turn up on the site, and how somebody puts them there.
 *
 * The tab used to be six lines of shortcode with a sentence each, and the
 * sentence assumed the reader already knew what a shortcode was and where one
 * goes — which is the one thing somebody on this tab does not know, or they
 * would not be reading it. So it starts a step earlier: what the thing in
 * square brackets is, where it is typed, and what turns up on the page when
 * it is.
 *
 * The other half of the old tab was copying files out of templates/ into a
 * theme, and that is not a smaller version of the same job: it is a different
 * person, on a day when the shortcode is already on the page and the markup
 * is not what they want. It keeps its own block, with who it is for in the
 * title, so nobody pasting a shortcode reads it as a step they have missed.
 */
function diluxone_users_screen_fields_usage(): void {
	diluxone_users_ui_aside_open();

	diluxone_users_intro( __( 'Inside the dashboard the fields place themselves: they are on every profile, on the form for adding a person and on WordPress’s own registration form, with nothing to set up. On the pages visitors see, you say where they go — and that is what the lines below are for.', 'diluxone-users' ) );

	diluxone_users_ui_section(
		__( 'What a shortcode is, if you have never used one', 'diluxone-users' ),
		__( 'A word between square brackets, typed into a page. Nobody visiting the site ever sees the brackets: while the page is being drawn, WordPress swaps the line for the real thing — a form, a list, a set of buttons. Put it twice and the thing appears twice; delete the line and it is gone. Nothing else on the page is affected.', 'diluxone-users' )
	);

	diluxone_users_ui_steps(
		array(
			__( 'Open the page it belongs on, or make one: Pages › Add page.', 'diluxone-users' ),
			__( 'Add a block and pick the one called “Shortcode”. Typing “shortcode” into the block search finds it.', 'diluxone-users' ),
			__( 'Paste one of the lines below into that block — the square brackets included, and nothing else with them.', 'diluxone-users' ),
			__( 'Publish the page and open it the way a visitor would. What the line stands for is what you see.', 'diluxone-users' ),
		)
	);

	diluxone_users_ui_note(
		__( 'If your site does not use blocks', 'diluxone-users' ),
		__( 'With the classic editor, or inside a page builder’s text widget, there is no block to add: type the line into the text of the page and it works the same way.', 'diluxone-users' )
	);

	diluxone_users_ui_section(
		__( 'The lines, and what each one puts on the page', 'diluxone-users' ),
		__( 'One of them is enough for a page: everything each line needs — its heading, its buttons, its messages — comes with it.', 'diluxone-users' )
	);
	?>
	<table class="widefat striped diluxone-users-shortcodes">
		<tbody>
			<tr>
				<td><code>[diluxone_users_fields]</code></td>
				<td><?php esc_html_e( 'A form with every field on it, for whoever is signed in to fill in and save. Somebody who is not signed in sees nothing at all in its place.', 'diluxone-users' ); ?></td>
			</tr>
			<tr>
				<td><code>[diluxone_users_fields group="basic"]</code></td>
				<td><?php esc_html_e( 'The same form with only the basic fields on it. With group="optional" it is the other half, which is how the two end up on two pages, or one under each heading of the same page.', 'diluxone-users' ); ?></td>
			</tr>
			<tr>
				<td><code>[diluxone_users_login]</code></td>
				<td><?php esc_html_e( 'The way in: the box that asks for an e-mail address, and under it whichever buttons are switched on — the social networks, the password, the passkey. Somebody already signed in is told so instead.', 'diluxone-users' ); ?></td>
			</tr>
			<tr>
				<td><code>[diluxone_users_login title="yes"]</code></td>
				<td><?php esc_html_e( 'The same box with a “Sign in” heading above it, for a page whose own title does not already say what it is for.', 'diluxone-users' ); ?></td>
			</tr>
			<tr>
				<td><code>[diluxone_users_accounts]</code></td>
				<td><?php esc_html_e( 'The social networks this person signs in with, each with the button that links a new one or unlinks one they have. It belongs on the page where people look after their own account.', 'diluxone-users' ); ?></td>
			</tr>
			<tr>
				<td><code>[diluxone_users_sessions]</code></td>
				<td><?php esc_html_e( 'Every browser and phone this person is still signed in on, when each one signed in, and a button that closes any of them — the page somebody wants after losing a laptop.', 'diluxone-users' ); ?></td>
			</tr>
		</tbody>
	</table>
	<?php
	diluxone_users_ui_aside_close(
		static function (): void {
			diluxone_users_ui_note(
				__( 'None of this needs a designer', 'diluxone-users' ),
				__( 'What these lines draw already takes the colour, the corners and the wording set on the Design screen, so a pasted shortcode looks like the rest of the site on the day it is pasted. There is nothing to style and nothing below to do.', 'diluxone-users' )
			);

			diluxone_users_ui_note(
				__( 'For whoever writes the theme', 'diluxone-users' ),
				array(
					sprintf(
						/* translators: 1: the plugin's templates folder, 2: the folder to copy it into, inside the theme */
						esc_html__( 'The markup itself can be replaced, and it is not a step anybody else has missed: copy any file from %1$s into the theme, inside a %2$s folder, and edit it there. The plugin uses the theme’s copy from then on, and its own updates leave it alone.', 'diluxone-users' ),
						'<code>templates/</code>',
						'<code>diluxone-users/</code>'
					),
					esc_html__( 'A theme that would rather write all of it, classes included, can turn the plugin’s stylesheet off on the Design screen.', 'diluxone-users' ),
				)
			);

			diluxone_users_ui_links(
				__( 'Where the look is set', 'diluxone-users' ),
				array(
					array(
						'url'   => diluxone_users_admin_url( 'diluxone-users-design', array( 'tab' => 'brand' ) ),
						'label' => __( 'Your brand', 'diluxone-users' ),
						'help'  => __( 'The colour, the corners and the switch that turns the plugin’s stylesheet off.', 'diluxone-users' ),
					),
					array(
						'url'   => diluxone_users_admin_url( 'diluxone-users-account' ),
						'label' => __( 'Account area', 'diluxone-users' ),
						'help'  => __( 'Where these fields end up for the person they belong to, section by section.', 'diluxone-users' ),
					),
				)
			);
		}
	);
}
