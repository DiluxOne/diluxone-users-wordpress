<?php
/**
 * User fields: what a person is asked for besides their e-mail.
 *
 * The definition lives in an option (`diluxone_users_fields`) and is edited
 * from the admin; each person's value lives in their user meta, under the
 * field key. There is no table of our own: this is user data and WordPress
 * already has somewhere to put it.
 *
 * @package DiluxOneUsers
 */

defined( 'ABSPATH' ) || exit;

/**
 * The field types the plugin understands.
 *
 * @return array<string, string> type => name to display.
 */
function diluxone_users_field_types(): array {
	$types = array(
		'text'     => __( 'Text', 'diluxone-users' ),
		'textarea' => __( 'Long text', 'diluxone-users' ),
		'email'    => __( 'Email address', 'diluxone-users' ),
		'phone'    => __( 'Phone with country code', 'diluxone-users' ),
		'country'  => __( 'Country', 'diluxone-users' ),
		'url'      => __( 'Web address', 'diluxone-users' ),
		'number'   => __( 'Number', 'diluxone-users' ),
		'date'     => __( 'Date', 'diluxone-users' ),
		'select'   => __( 'Fixed list', 'diluxone-users' ),
		'datalist' => __( 'Text with suggestions', 'diluxone-users' ),
		'checkbox' => __( 'Yes / no', 'diluxone-users' ),
	);

	/**
	 * Filters the field types on offer.
	 *
	 * A type added here shows up in the admin straight away and is drawn as a
	 * text box and cleaned as text, which is the right answer for most of
	 * what anybody would add. To draw it differently there is
	 * `diluxone_users_field_input`, and to clean it differently there is
	 * `diluxone_users_sanitize_value`.
	 *
	 * @since 1.0.0
	 *
	 * @param array<string, string> $types Slug => what it is called.
	 */
	return (array) apply_filters( 'diluxone_users_field_types', $types );
}

/**
 * The fields that already belong to WordPress.
 *
 * First and last name are not this plugin's invention: WordPress has had them
 * forever, shows them in the dashboard and half the plugin world uses them.
 * Having them appear here as well, in the same list and under the same rules
 * as the others, is what makes "Your details" the screen where YOUR details
 * are edited and not only the ones this plugin added.
 *
 * They are not stored in just any user meta: they go where WordPress looks
 * for them.
 *
 * @return array<string, string> key => how it is stored.
 */
function diluxone_users_native_fields(): array {
	return array(
		'first_name' => 'meta',
		'last_name'  => 'meta',
	);
}

/** Is this key one of WordPress's own fields? */
function diluxone_users_field_is_native( string $key ): bool {
	return isset( diluxone_users_native_fields()[ $key ] );
}

/**
 * The fields a new site starts with.
 *
 * They are the ones almost always needed. Any of them can be deleted from the
 * admin: there are no untouchable fields.
 *
 * @return array<int, array<string, mixed>>
 */
function diluxone_users_default_fields(): array {
	return array(
		array(
			'key'      => 'first_name',
			'label'    => __( 'First name', 'diluxone-users' ),
			'type'     => 'text',
			'help'     => '',
			'options'  => array(),
			'required' => 1,
			'group'    => 'main',
			'active'   => 1,
		),
		array(
			'key'      => 'last_name',
			'label'    => __( 'Last name', 'diluxone-users' ),
			'type'     => 'text',
			'help'     => '',
			'options'  => array(),
			'required' => 0,
			'group'    => 'main',
			'active'   => 1,
		),
		array(
			'key'      => 'diluxone_users_country',
			'label'    => __( 'Country', 'diluxone-users' ),
			'type'     => 'country',
			'help'     => '',
			// The ones from the region first: pure alphabetical order leaves the
			// site's own country halfway down a list of nearly two hundred.
			'options'  => array( 'AR', 'CL', 'UY', 'PY', 'BO', 'BR', 'PE', 'MX', 'ES' ),
			'required' => 0,
			'group'    => 'main',
			'active'   => 1,
		),
		array(
			'key'      => 'diluxone_users_birthday',
			'label'    => __( 'Date of birth', 'diluxone-users' ),
			'type'     => 'date',
			'help'     => __( 'So we can wish you a happy birthday.', 'diluxone-users' ),
			'options'  => array(),
			'required' => 0,
			'group'    => 'extra',
			'active'   => 1,
		),
		array(
			'key'      => 'diluxone_users_gender',
			'label'    => __( 'Gender', 'diluxone-users' ),
			'type'     => 'datalist',
			'help'     => __( 'However you identify. Write anything you like, or leave it empty.', 'diluxone-users' ),
			'options'  => array(
				__( 'Woman', 'diluxone-users' ),
				__( 'Man', 'diluxone-users' ),
				__( 'Non-binary', 'diluxone-users' ),
				__( 'Prefer not to say', 'diluxone-users' ),
			),
			'required' => 0,
			'group'    => 'extra',
			'active'   => 1,
		),
		array(
			'key'      => 'diluxone_users_phone',
			'label'    => __( 'Mobile (WhatsApp)', 'diluxone-users' ),
			'type'     => 'phone',
			'help'     => __( 'With country code. Only for notifications you ask for.', 'diluxone-users' ),
			'options'  => array( 'AR' ),
			'required' => 0,
			'group'    => 'extra',
			'active'   => 1,
		),
	);
}

/**
 * The form block a field belongs to.
 *
 * There are two, each with a name of its own: the main one — what the site
 * needs — and the additional one, the "tell us a bit more if you like". The
 * old names are accepted so nothing already stored breaks.
 */
function diluxone_users_normalize_group( string $group ): string {
	$old_prefixes = array(
		'basic'    => 'main',
		'optional' => 'extra',
	);
	$group        = $old_prefixes[ $group ] ?? $group;

	return 'main' === $group ? 'main' : 'extra';
}

/**
 * The blocks, for display.
 *
 * @return array<string, string>
 */
function diluxone_users_groups(): array {
	return array(
		'main'  => __( 'Main block — what the site needs', 'diluxone-users' ),
		'extra' => __( 'Extra block — “if you like, tell us more”', 'diluxone-users' ),
	);
}

/**
 * May a field be called this?
 *
 * A field's key is not a label: it is the user meta key the account form
 * writes to, with whatever the person typed. So a field named
 * `diluxone_users_2fa_on` is a box on the front end that turns somebody's
 * second factor off, and one named `wp_capabilities` is a box that edits
 * their role — neither of which goes anywhere near the checks that guard
 * those things properly.
 *
 * WordPress's own two names are allowed because they are the two this plugin
 * deliberately adopts: first and last name are fields like any other, in the
 * same list and under the same rules, and they are stored where WordPress
 * looks for them.
 */
function diluxone_users_field_key_allowed( string $key ): bool {
	if ( '' === $key ) {
		return false;
	}

	if ( diluxone_users_field_is_native( $key ) ) {
		return true;
	}

	/*
	 * Two lists, and the difference between them matters more than it looks.
	 *
	 * The exact names are names: `diluxone_users_avatar` is taken,
	 * `diluxone_users_avatar_size` is not, and a field the site calls "Avatar
	 * size" has to be able to exist. Treating them as prefixes looked tidier
	 * and meant every name starting with a taken one was taken too — which,
	 * for the key generator that appends `_2` until it finds a free name, is
	 * a loop with no way out.
	 *
	 * The prefixes are prefixes: everything under them is generated, one row
	 * per passkey, per provider, per notice, per field counter, so no exact
	 * list of them can be written down.
	 */
	$taken = array(
		'session_tokens',
		'capabilities',
		'user_level',
		'admin_color',
		'locale',
		'nickname',
		'description',
		'diluxone_users_2fa_on',
		'diluxone_users_2fa_pending',
		'diluxone_users_2fa_epoch',
		'diluxone_users_2fa_email',
		'diluxone_users_2fa_fails',
		'diluxone_users_2fa_lock',
		'diluxone_users_totp',
		'diluxone_users_totp_pending',
		'diluxone_users_totp_step',
		'diluxone_users_backup_codes',
		'diluxone_users_passkeys',
		'diluxone_users_handle',
		'diluxone_users_handle_changed',
		'diluxone_users_avatar',
		'diluxone_users_devices',
	);

	if ( in_array( $key, $taken, true ) ) {
		return false;
	}

	foreach ( array( 'wp_', '_diluxone_users', 'diluxone_users_pk_', 'diluxone_users_sso_', 'diluxone_users_notify_', 'diluxone_users_edits_' ) as $start ) {
		if ( 0 === strpos( $key, $start ) ) {
			return false;
		}
	}

	return true;
}

/**
 * One field, normalised. Fills in whatever is missing so that whoever
 * consumes it does not have to check every key.
 *
 * @param array<string, mixed> $field
 * @return array<string, mixed>
 */
function diluxone_users_normalize_field( array $field ): array {
	$types = diluxone_users_field_types();

	return array(
		'key'         => sanitize_key( (string) ( $field['key'] ?? '' ) ),
		'label'       => sanitize_text_field( (string) ( $field['label'] ?? '' ) ),
		'type'        => isset( $types[ $field['type'] ?? '' ] ) ? (string) $field['type'] : 'text',
		'help'        => sanitize_text_field( (string) ( $field['help'] ?? '' ) ),
		'placeholder' => sanitize_text_field( (string) ( $field['placeholder'] ?? '' ) ),
		'options'     => array_values(
			array_filter(
				array_map(
					static fn( $o ): string => sanitize_text_field( (string) $o ),
					(array) ( $field['options'] ?? array() )
				),
				static fn( string $o ): bool => '' !== $o
			)
		),
		'required'    => empty( $field['required'] ) ? 0 : 1,
		'group'       => diluxone_users_normalize_group( (string) ( $field['group'] ?? '' ) ),
		'active'      => isset( $field['active'] ) && ! $field['active'] ? 0 : 1,
		// What the person who owns the data can do with this field:
		// 'always' change it whenever they like, 'limited' a few times,
		// 'never' just look at it. Whoever administers always can.
		'edit'        => in_array( $field['edit'] ?? '', array( 'always', 'limited', 'never' ), true )
			? (string) $field['edit']
			: 'always',
		'edit_max'    => max( 1, (int) ( $field['edit_max'] ?? 1 ) ),
	);
}

/**
 * Every defined field.
 *
 * @param string $group 'basic', 'optional' or '' for all of them.
 * @param bool   $only_active
 * @return array<int, array<string, mixed>>
 */
function diluxone_users_fields( string $group = '', bool $only_active = true ): array {
	$fields = array_map( 'diluxone_users_normalize_field', (array) get_option( 'diluxone_users_fields', array() ) );

	$fields = array_values(
		array_filter(
			$fields,
			static function ( array $c ) use ( $group, $only_active ): bool {
				if ( '' === $c['key'] || '' === $c['label'] ) {
					return false;
				}
				if ( $only_active && ! $c['active'] ) {
					return false;
				}

				return '' === $group || $group === $c['group'];
			}
		)
	);

	/**
	 * Filters the list of fields.
	 *
	 * This is the place to add or hide one from a theme or from another
	 * plugin, without touching the stored configuration.
	 *
	 * @param array<int, array<string, mixed>> $fields
	 * @param string                           $group
	 */
	return apply_filters( 'diluxone_users_fields', $fields, $group );
}

/**
 * One field by its key, or null.
 *
 * @return array<string, mixed>
 */
function diluxone_users_field( string $key ): ?array {
	foreach ( diluxone_users_fields( '', false ) as $field ) {
		if ( $field['key'] === $key ) {
			return $field;
		}
	}

	return null;
}

/** The value a person has in one field. */
function diluxone_users_value( int $user_id, string $key ): string {
	return (string) get_user_meta( $user_id, $key, true );
}

/**
 * When a first or last name is saved, WordPress expects the display name to
 * rebuild itself.
 *
 * Without this, someone who used to be called "juan@mail.com" keeps appearing
 * that way next to whatever they write, even though they filled in their name
 * a while ago.
 */
function diluxone_users_refresh_display_name( int $user_id ): void {
	$user = get_userdata( $user_id );

	if ( ! $user instanceof WP_User ) {
		return;
	}

	$name = trim( (string) get_user_meta( $user_id, 'first_name', true ) . ' ' . (string) get_user_meta( $user_id, 'last_name', true ) );

	if ( '' === $name || $user->display_name === $name ) {
		return;
	}

	wp_update_user(
		array(
			'ID'           => $user_id,
			'display_name' => $name,
		)
	);
}

/* ── Who can change what ───────────────────────────────────────────── */

/**
 * Records a change, if something really changed and if the person did it.
 *
 * Writing the same thing that was already there does not spend a go: whoever
 * presses "Save" twice in a row changed nothing.
 *
 * @param array<string, mixed> $field
 */
function diluxone_users_field_count_edit( int $user_id, array $field, string $value ): void {
	if ( 'limited' !== $field['edit'] || get_current_user_id() !== $user_id ) {
		return;
	}

	if ( diluxone_users_value( $user_id, $field['key'] ) === $value ) {
		return;
	}

	update_user_meta( $user_id, 'diluxone_users_edits_' . $field['key'], diluxone_users_field_edits( $user_id, $field['key'] ) + 1 );
}


/**
 * How many times this person has changed this field.
 *
 * Only what they do with their own data is counted. An administrator fixing
 * somebody else's surname spends nobody's quota: the limit exists so that a
 * name does not change every day, not to leave a typo beyond repair.
 */
function diluxone_users_field_edits( int $user_id, string $key ): int {
	return (int) get_user_meta( $user_id, 'diluxone_users_edits_' . $key, true );
}

/**
 * How many changes they have left. -1 when there is no limit.
 *
 * @param array<string, mixed> $field
 */
function diluxone_users_field_edits_left( array $field, int $user_id ): int {
	if ( 'limited' !== $field['edit'] ) {
		return -1;
	}

	return max( 0, (int) $field['edit_max'] - diluxone_users_field_edits( $user_id, $field['key'] ) );
}

/**
 * Can this person change this field right now?
 *
 * Whoever administers always can: otherwise a single-edit field turns into a
 * value nobody can correct any more, not even with good reason.
 *
 * @param array<string, mixed> $field
 */
function diluxone_users_field_editable( array $field, int $user_id ): bool {
	if ( current_user_can( 'edit_users' ) && get_current_user_id() !== $user_id ) {
		return true;
	}

	if ( 'never' === $field['edit'] ) {
		return false;
	}

	return 'limited' !== $field['edit'] || diluxone_users_field_edits_left( $field, $user_id ) > 0;
}

/**
 * What the person is told below the field about how many times they can
 * change it. Empty when there is nothing to point out.
 *
 * @param array<string, mixed> $field
 */
function diluxone_users_field_edit_note( array $field, int $user_id ): string {
	if ( 'never' === $field['edit'] ) {
		return __( 'This one cannot be changed from here. Write to us if it is wrong.', 'diluxone-users' );
	}

	if ( 'limited' !== $field['edit'] ) {
		return '';
	}

	$left = diluxone_users_field_edits_left( $field, $user_id );

	if ( 0 === $left ) {
		return __( 'You already used up the changes for this one. Write to us if it is wrong.', 'diluxone-users' );
	}

	return sprintf(
			/* translators: %d: how many more times they can change it */
		_n( 'You can change this one %d more time.', 'You can change this one %d more times.', $left, 'diluxone-users' ),
		$left
	);
}

/**
 * Cleans a value according to the field type.
 *
 * @param array<string, mixed> $field
 */
function diluxone_users_sanitize( array $field, string $value ): string {
	$value = trim( $value );

	/**
	 * Filters how a value is cleaned, before the built-in types are tried.
	 *
	 * Return anything but null and it is used as it is — so whatever answers
	 * this is responsible for the value being safe to store.
	 *
	 * @since 1.0.0
	 *
	 * @param string|null          $clean null to leave it to the plugin.
	 * @param string               $value What arrived, trimmed.
	 * @param array<string, mixed> $field The field it belongs to.
	 */
	$own = apply_filters( 'diluxone_users_sanitize_value', null, $value, $field );

	if ( null !== $own ) {
		return (string) $own;
	}

	switch ( $field['type'] ) {
		case 'email':
			return sanitize_email( $value );

		case 'url':
			return esc_url_raw( $value );

		case 'textarea':
			return sanitize_textarea_field( $value );

		case 'number':
			return '' === $value ? '' : (string) floatval( $value );

		case 'date':
			// Stored as YYYY-MM-DD, which is what the input sends and the only
			// form that sorts and compares without ambiguity.
			return preg_match( '/^\d{4}-\d{2}-\d{2}$/', $value ) ? $value : '';

		case 'phone':
			// Stored in international format: "+" and digits, nothing else. What
			// is seen with spaces is assembled by the form; the stored value has
			// to be usable against an API without cleaning it again.
			$digits = (string) preg_replace( '/\D/', '', $value );

			return '' === $digits ? '' : '+' . $digits;

		case 'country':
			// The ISO code is stored, not the name: the name changes with the
			// language and with the mood of geopolitics, the code does not.
			$iso = strtoupper( trim( $value ) );

			return isset( diluxone_users_countries()[ $iso ] ) ? $iso : '';

		case 'checkbox':
			return '' === $value ? '' : '1';

		case 'select':
			// A closed list is closed: what is not on the list does not get in.
			return in_array( $value, $field['options'], true ) ? $value : '';

		default:
			return sanitize_text_field( $value );
	}
}

/**
 * What a form sent for the fields this plugin defines, and nothing else.
 *
 * The one place that reads the superglobal for a field, and it reads it by
 * name: for every field the site has declared, its key and — for a phone —
 * its dialling-code companion. Whatever else is in the request is not in the
 * answer, which is what makes `diluxone_users_save()` a function that takes
 * an array rather than a function that takes the request.
 *
 * It is also how the callers stop handing `$_POST` to a callee. That the
 * callee sanitised every value it recognised was true; it was true three
 * calls deep, where nobody reading the call site could see it.
 *
 * @param string $group Only one block of the form, or all of it.
 * @return array<string, string>
 */
function diluxone_users_posted_fields( string $group = '' ): array {
	$sent = array();

	foreach ( diluxone_users_fields( $group ) as $field ) {
		foreach ( array( (string) $field['key'], (string) $field['key'] . '_dial' ) as $name ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Missing -- every caller verifies before asking; the values are sanitised per field type in diluxone_users_save().
			if ( ! isset( $_POST[ $name ] ) ) {
				continue;
			}

			// phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- as above: the field's own sanitiser runs on save, and it is the one that knows the type.
			$value = wp_unslash( $_POST[ $name ] );

			if ( is_scalar( $value ) ) {
				$sent[ $name ] = (string) $value;
			}
		}
	}

	return $sent;
}

/**
 * Saves a person's fields from an array of what a form sent — the one
 * `diluxone_users_posted_fields()` builds, already unslashed and holding
 * nothing but this plugin's own field names. It only looks at the keys that
 * exist as a field.
 *
 * Unslashing happens once, there. It used to happen here as well, on a value
 * one caller had already unslashed, and a name with a backslash in it lost
 * the backslash on the way through registration and kept it everywhere else.
 *
 * An empty value deletes the meta instead of storing an empty string: that
 * way the user does not pile up rows that say nothing.
 *
 * @param array<string, string> $input
 * @param string                $group Limits it to one group, or '' for all.
 * @return array<int, string> Labels of the required fields that are missing.
 */
function diluxone_users_save( int $user_id, array $input, string $group = '' ): array {
	$missing = array();

	foreach ( diluxone_users_fields( $group ) as $field ) {
		$key = $field['key'];

		if ( ! array_key_exists( $key, $input ) ) {
			continue;
		}

		// The browser control can be removed with the inspector, so this is the
		// one that rules: what cannot be edited is not saved.
		if ( ! diluxone_users_field_editable( $field, $user_id ) ) {
			continue;
		}

		$raw = (string) $input[ $key ];

		// The phone arrives in two parts: the country dialling code, from its
		// list, and the number. They are joined here and not in the browser so
		// it also holds when the form arrives without JavaScript.
		if ( 'phone' === $field['type'] && '' !== trim( $raw ) ) {
			$dial = diluxone_users_country_dial( sanitize_text_field( (string) ( $input[ $key . '_dial' ] ?? '' ) ) );
			$raw  = '+' . $dial . preg_replace( '/\D/', '', $raw );
		}

		$value = diluxone_users_sanitize( $field, $raw );

		if ( '' === $value ) {
			if ( $field['required'] ) {
				$missing[] = $field['label'];
				continue;
			}

			diluxone_users_field_count_edit( $user_id, $field, '' );
			delete_user_meta( $user_id, $key );
			continue;
		}

		diluxone_users_field_count_edit( $user_id, $field, $value );

		update_user_meta( $user_id, $key, $value );

		if ( diluxone_users_field_is_native( $key ) ) {
			$refresh = true;
		}
	}

	if ( ! empty( $refresh ) ) {
		diluxone_users_refresh_display_name( $user_id );
	}

	/**
	 * Runs after a person's fields are saved.
	 *
	 * @param int                  $user_id
	 * @param array<string, mixed> $input
	 * @param string               $group
	 */
	do_action( 'diluxone_users_fields_saved', $user_id, $input, $group );

	return $missing;
}
