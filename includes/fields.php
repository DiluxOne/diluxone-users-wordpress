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
 * The corner of the plugin's namespace a new field's key is made in.
 *
 * Everything else under `diluxone_users_` is what the plugin, or an add-on of
 * it, keeps about a person: see diluxone_users_field_key_allowed().
 */
const DILUXONE_USERS_FIELD_PREFIX = 'diluxone_users_field_';

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
 * WordPress's own two and nothing else, neither of them required. A plugin
 * that arrives asking everybody who registers for their country, their
 * birthday and a phone number is a plugin that made a privacy decision on
 * the site's behalf — and a required field nobody asked for is a sign-up
 * form that refuses people on the day it is installed. The rest are a
 * suggestion the administrator takes on purpose: see
 * diluxone_users_suggested_fields() and the Suggested fields tab.
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
			'required' => 0,
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
	);
}

/**
 * The fields many sites ask for, offered and never seeded.
 *
 * Added from User fields › Suggested fields, ticked one by one; once added
 * each is a field like any other, edited, moved or deleted from the list.
 * None is required: whether a site needs somebody's birthday is the site's
 * call, made on the field once it is there.
 *
 * @return array<int, array<string, mixed>>
 */
function diluxone_users_suggested_fields(): array {
	$fields = array(
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

	/**
	 * Filters the fields offered on User fields › Suggested fields.
	 *
	 * Each one is a field definition, as the stored list holds them. A key
	 * already on the list is not offered again.
	 *
	 * @since 1.0.0
	 *
	 * @param array<int, array<string, mixed>> $fields
	 */
	return (array) apply_filters( 'diluxone_users_suggested_fields', $fields );
}

/**
 * Adds the suggested fields asked for, the ones not on the list yet.
 *
 * At the end of the list, in the order they are suggested. A key that is
 * already there — added before, or a field of the site's own under the same
 * name — is left exactly as it is.
 *
 * @param array<int, string> $keys The suggested fields' keys.
 * @return int How many were added.
 */
function diluxone_users_add_suggested_fields( array $keys ): int {
	$stored = diluxone_users_fields_stored();
	$have   = array_map( 'strval', array_column( $stored, 'key' ) );
	$added  = 0;

	foreach ( diluxone_users_suggested_fields() as $field ) {
		$key = (string) ( $field['key'] ?? '' );

		if ( ! in_array( $key, $keys, true ) || in_array( $key, $have, true ) || ! diluxone_users_field_key_allowed( $key ) ) {
			continue;
		}

		$stored[] = $field;
		$have[]   = $key;
		++$added;
	}

	if ( $added > 0 ) {
		diluxone_users_update_option( 'diluxone_users_fields', array_values( $stored ) );
	}

	return $added;
}

/**
 * The form block a field belongs to.
 *
 * There are two, each with a name of its own: the main one — what the site
 * needs — and the additional one, the "tell us a bit more if you like".
 */
function diluxone_users_normalize_group( string $group ): string {
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
	 * per provider, per notice, per field counter, so no exact
	 * list of them can be written down.
	 */
	$taken = array_merge(
		diluxone_users_core_user_meta(),
		array(
			'diluxone_users_2fa_on',
			'diluxone_users_2fa_pending',
			'diluxone_users_2fa_email',
			'diluxone_users_2fa_fails',
			'diluxone_users_2fa_lock',
			'diluxone_users_2fa_lock_at',
			'diluxone_users_backup_codes',
			'diluxone_users_handle',
			'diluxone_users_handle_changed',
			'diluxone_users_avatar',
			'diluxone_users_avatar_site',
			'diluxone_users_devices',
			// A closed account's flag, which keeps it from signing in, and the
			// sites an administrator took somebody off, which keeps them off.
			'diluxone_users_closed',
			'diluxone_users_removed_from', // DILUXONE_USERS_MEMBERSHIP_REMOVED.
		)
	);

	/**
	 * Filters the user meta keys no field may be named after.
	 *
	 * An add-on that keeps something about a person under the plugin's
	 * namespace names its keys here, so a field — made on the screen, brought
	 * by a settings file or by a filter — can never write them.
	 *
	 * @since 1.0.0
	 *
	 * @param array<int, string> $taken WordPress's own and the plugin's.
	 */
	$taken = array_map( 'strval', (array) apply_filters( 'diluxone_users_reserved_meta_keys', $taken ) );

	if ( in_array( $key, $taken, true ) ) {
		return false;
	}

	// WordPress keeps a site's roles under the table prefix — `wp_`, or
	// whatever this install chose, and `wp_2_` for the second site of a
	// network — so the prefix is read, not assumed.
	global $wpdb;

	$prefixes = array( 'wp_', $wpdb->base_prefix, '_diluxone_users', 'diluxone_users_sso_', 'diluxone_users_notify_', 'diluxone_users_edits_' );

	/**
	 * Filters the user meta prefixes no field may be named under.
	 *
	 * The same, for the keys an add-on makes one per thing — one per key a
	 * person registered, say — whose exact names cannot be listed.
	 *
	 * @since 1.0.0
	 *
	 * @param array<int, string> $prefixes WordPress's own and the plugin's.
	 */
	$prefixes = array_map( 'strval', (array) apply_filters( 'diluxone_users_reserved_meta_prefixes', $prefixes ) );

	foreach ( array_filter( $prefixes ) as $start ) {
		if ( 0 === strpos( $key, $start ) ) {
			return false;
		}
	}

	/*
	 * The plugin's namespace is the plugin's, and an add-on of it writes there
	 * too: what it keeps about a person is under `diluxone_users_` like
	 * everything else, and a list of its names cannot be written here — the
	 * add-on is not here to ask, and its data may be, waiting for it. So a
	 * field takes a key under the prefix only from the corner the fields
	 * have to themselves, `diluxone_users_field_`, where the screen makes
	 * every new key; or one a field already had before that corner existed,
	 * or a suggested field's, which are the fields' by definition. Every other
	 * name under the prefix is somebody's data.
	 */
	if ( 0 === strpos( $key, 'diluxone_users_' ) ) {
		return diluxone_users_field_key_ours( $key );
	}

	return true;
}

/**
 * Is a key under the plugin's prefix one the fields may use?
 *
 * The fields' own corner of the namespace, a suggested field's key, or the
 * key of a field already on the list: a field made before the corner existed
 * keeps the key everybody's answer is stored under.
 */
function diluxone_users_field_key_ours( string $key ): bool {
	if ( 0 === strpos( $key, DILUXONE_USERS_FIELD_PREFIX ) && strlen( $key ) > strlen( DILUXONE_USERS_FIELD_PREFIX ) ) {
		return true;
	}

	$stored = (array) diluxone_users_raw_get( 'diluxone_users_fields', array() );
	$keys   = array_merge(
		array_column( diluxone_users_suggested_fields(), 'key' ),
		array_column( array_filter( $stored, 'is_array' ), 'key' )
	);

	return in_array( $key, array_map( 'strval', $keys ), true );
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

	// A field can arrive from a settings file somebody edited by hand, so any
	// of its words can be a list. A list is no word: '' and not "Array" — a
	// key of `array` and a TypeError on a list used as an index, before.
	$word = static fn( $value ): string => is_scalar( $value ) ? (string) $value : '';
	$type = $word( $field['type'] ?? '' );

	return array(
		'key'         => sanitize_key( $word( $field['key'] ?? '' ) ),
		'label'       => sanitize_text_field( $word( $field['label'] ?? '' ) ),
		'type'        => isset( $types[ $type ] ) ? $type : 'text',
		'help'        => sanitize_text_field( $word( $field['help'] ?? '' ) ),
		'placeholder' => sanitize_text_field( $word( $field['placeholder'] ?? '' ) ),
		'options'     => array_values(
			array_filter(
				array_map(
					static fn( $o ): string => sanitize_text_field( $word( $o ) ),
					(array) ( $field['options'] ?? array() )
				),
				static fn( string $o ): bool => '' !== $o
			)
		),
		'required'    => empty( $field['required'] ) ? 0 : 1,
		'group'       => diluxone_users_normalize_group( $word( $field['group'] ?? '' ) ),
		'active'      => isset( $field['active'] ) && ! $field['active'] ? 0 : 1,
		// What the person who owns the data can do with this field:
		// 'always' change it whenever they like, 'limited' a few times,
		// 'never' just look at it. Whoever administers always can.
		'edit'        => in_array( $field['edit'] ?? '', array( 'always', 'limited', 'never' ), true )
			? (string) $field['edit']
			: 'always',
		'edit_max'    => max( 1, (int) $word( $field['edit_max'] ?? 1 ) ),
	);
}

/**
 * The list of fields as the site keeps it, raw.
 *
 * A site that never saved a list has the starter fields, read from
 * diluxone_users_default_fields() like any other default and not written
 * anywhere: activating the plugin stores nothing on the site's behalf. A list
 * saved empty on purpose stays empty.
 *
 * @return array<int|string, mixed>
 */
function diluxone_users_fields_stored(): array {
	$stored = diluxone_users_raw_get( 'diluxone_users_fields', null );

	return null === $stored ? diluxone_users_default_fields() : (array) $stored;
}

/**
 * Every defined field.
 *
 * @param string $group 'basic', 'optional' or '' for all of them.
 * @param bool   $only_active
 * @return array<int, array<string, mixed>>
 */
function diluxone_users_fields( string $group = '', bool $only_active = true ): array {
	$fields = array_map( 'diluxone_users_normalize_field', diluxone_users_fields_stored() );

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
	$fields = (array) apply_filters( 'diluxone_users_fields', $fields, $group );

	// What the filter added is filled in like a stored field: a theme that
	// adds one with a key and a label is not expected to know every setting
	// a field has, and nothing downstream should read one that is not there.
	return array_values( array_map( 'diluxone_users_normalize_field', array_filter( $fields, 'is_array' ) ) );
}

/**
 * The active fields that are the site's own, not WordPress's.
 *
 * What the plugin adds to WordPress's profile screens: first and last name
 * are already there, drawn by WordPress.
 *
 * @return array<int, array<string, mixed>>
 */
function diluxone_users_own_fields(): array {
	return array_values(
		array_filter(
			diluxone_users_fields(),
			static fn( array $field ): bool => ! diluxone_users_field_is_native( (string) $field['key'] )
		)
	);
}

/** Has the site a field of its own for WordPress's profile screens? */
function diluxone_users_has_own_fields(): bool {
	return array() !== diluxone_users_own_fields();
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

/**
 * The value a person has in one field.
 *
 * A field's key is a meta key the site chose, and something else may keep a
 * list under the same name: that is not an answer to the field, and casting
 * it would print "Array" and warn. It reads as empty.
 */
function diluxone_users_value( int $user_id, string $key ): string {
	$value = get_user_meta( $user_id, $key, true );

	return is_scalar( $value ) ? (string) $value : '';
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
			// A number that is not finite — 1e999 is infinity to PHP — is no
			// number anybody typed, and "INF" is not one to keep.
			return '' === $value || ! is_finite( floatval( $value ) ) ? '' : (string) floatval( $value );

		case 'date':
			// Stored as YYYY-MM-DD, which is what the input sends and the only
			// form that sorts and compares without ambiguity — and a day there
			// is: 2024-13-45 has the shape and is no date.
			return preg_match( '/^(\d{4})-(\d{2})-(\d{2})$/', $value, $day ) && checkdate( (int) $day[2], (int) $day[3], (int) $day[1] ) ? $value : '';

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
 * Which of the phones a form sent are the wrong length for their country.
 *
 * Asked before anything on the form is saved: a number too short or too long
 * refuses the whole form, the way a missing required field does. A phone
 * sent empty is no number to measure — whether it may be empty is the
 * required check's business — and one sent without a country it can be
 * measured against is left to the save.
 *
 * @param array<string, string>            $sent   What diluxone_users_posted_fields() read.
 * @param array<int, array<string, mixed>> $fields The fields the form drew.
 * @return array<string, string> Field key => 'short' or 'long'.
 */
function diluxone_users_phone_codes( array $sent, array $fields ): array {
	$wrong = array();

	foreach ( $fields as $field ) {
		$key = (string) ( $field['key'] ?? '' );

		if ( 'phone' !== ( $field['type'] ?? '' ) || ! isset( $sent[ $key ] ) || '' === trim( (string) $sent[ $key ] ) ) {
			continue;
		}

		$iso                  = strtoupper( sanitize_text_field( (string) ( $sent[ $key . '_dial' ] ?? '' ) ) );
		list( $iso, $number ) = diluxone_users_phone_resolve( $iso, (string) $sent[ $key ] );

		if ( '' === diluxone_users_country_dial( $iso ) ) {
			continue;
		}

		$code = diluxone_users_phone_check( $iso, $number );

		if ( '' !== $code ) {
			$wrong[ $key ] = $code;
		}
	}

	return $wrong;
}

/**
 * The same, as the sentences a person reads under the number.
 *
 * @param array<string, string>            $sent
 * @param array<int, array<string, mixed>> $fields
 * @return array<string, string> Field key => message.
 */
function diluxone_users_phone_errors( array $sent, array $fields ): array {
	return array_map( 'diluxone_users_phone_message', diluxone_users_phone_codes( $sent, $fields ) );
}

/**
 * What is said of a number the wrong length: the same words under the field
 * the server sends back and in the browser's own check.
 *
 * @param string $code 'short' or 'long'.
 */
function diluxone_users_phone_message( string $code ): string {
	return 'long' === $code
		? __( 'This number is too long for the country chosen.', 'diluxone-users' )
		: __( 'This number is too short for the country chosen.', 'diluxone-users' );
}

/**
 * The phone a form was sent back for, read from the address the refusal
 * redirected to: `diluxone-users=phone`, the field's key and the code.
 *
 * The key is only believed once it is one of the fields being drawn, and
 * the number itself never travels in the address.
 *
 * @param array<int, array<string, mixed>> $fields The fields being drawn.
 * @return array<string, string> Field key => message, or nothing.
 */
function diluxone_users_phone_refused( array $fields ): array {
	// phpcs:disable WordPress.Security.NonceVerification.Recommended -- it only picks which message is shown under which field.
	if ( 'phone' !== diluxone_users_state() || ! isset( $_GET['diluxone-users-field'] ) || ! is_string( $_GET['diluxone-users-field'] ) ) {
		return array();
	}

	$key  = sanitize_key( wp_unslash( $_GET['diluxone-users-field'] ) );
	$code = isset( $_GET['diluxone-users-code'] ) && is_string( $_GET['diluxone-users-code'] ) ? sanitize_key( wp_unslash( $_GET['diluxone-users-code'] ) ) : '';
	// phpcs:enable

	foreach ( $fields as $field ) {
		if ( 'phone' === ( $field['type'] ?? '' ) && $key === (string) $field['key'] ) {
			return array( $key => diluxone_users_phone_message( 'long' === $code ? 'long' : 'short' ) );
		}
	}

	return array();
}

/**
 * Where a form refused for a phone goes back to: the same page, saying which
 * field and why.
 *
 * @param array<string, string> $wrong What diluxone_users_phone_codes() found.
 */
function diluxone_users_phone_back( string $back, array $wrong ): string {
	$key = (string) array_key_first( $wrong );

	return add_query_arg(
		array(
			'diluxone-users'       => 'phone',
			'diluxone-users-field' => $key,
			'diluxone-users-code'  => $wrong[ $key ],
		),
		$back
	);
}

/**
 * The phones refused in this very request, for a screen that draws its form
 * again in the same request instead of redirecting (the profile in wp-admin).
 *
 * @param array<string, string> $add Field key => message, to remember.
 * @return array<string, string>
 */
function diluxone_users_phone_failed( array $add = array() ): array {
	static $failed = array();

	$failed = array_merge( $failed, $add );

	return $failed;
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
 * It reads the request, so it checks the request's nonce itself, first,
 * with the action and the field name of the form its caller drew: a
 * function that reads `$_POST` verifies it where the reading is, not
 * somewhere up the call stack. A request without a valid nonce has posted
 * nothing.
 *
 * @param string $nonce_action The action of the form's nonce.
 * @param string $nonce_name   The field the nonce travels in.
 * @param string $group        Only one block of the form, or all of it.
 * @return array<string, string>
 */
function diluxone_users_posted_fields( string $nonce_action, string $nonce_name, string $group = '' ): array {
	if ( ! isset( $_POST[ $nonce_name ] ) || ! is_string( $_POST[ $nonce_name ] ) || ! wp_verify_nonce( sanitize_key( wp_unslash( $_POST[ $nonce_name ] ) ), $nonce_action ) ) {
		return array();
	}

	$sent = array();

	foreach ( diluxone_users_fields( $group ) as $field ) {
		foreach ( array( (string) $field['key'], (string) $field['key'] . '_dial' ) as $name ) {
			// One value per field: a posted `field[]=` is an array, not an answer.
			if ( ! isset( $_POST[ $name ] ) || ! is_scalar( $_POST[ $name ] ) ) {
				continue;
			}

			// Cleaned as it is read — an address as an address, since text
			// cleaning would strip its %-encoded characters — and cleaned
			// again by its field's type when it is saved.
			$sent[ $name ] = 'url' === ( $field['type'] ?? '' ) && $name === $field['key']
				? esc_url_raw( wp_unslash( (string) $_POST[ $name ] ) )
				: sanitize_textarea_field( wp_unslash( (string) $_POST[ $name ] ) );
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

		// A field reaches this list from a filter or from an option, and
		// neither went through the screen that refuses a key WordPress or the
		// plugin keeps for itself (`wp_capabilities`, a session, a second factor).
		// The save asks again: a field is never the way to write one.
		if ( ! diluxone_users_field_key_allowed( (string) $key ) ) {
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
		// it also holds when the form arrives without JavaScript. The number
		// goes in as the country writes it after its code: digits only, and
		// without the trunk prefix ("011…" in Argentina) typed out of habit.
		// A number written with its own code ("+56 …", "0056 …") belongs to
		// the country of that code, whichever was picked, as in the browser.
		if ( 'phone' === $field['type'] && '' !== trim( $raw ) ) {
			$iso                  = sanitize_text_field( (string) ( $input[ $key . '_dial' ] ?? '' ) );
			list( $iso, $number ) = diluxone_users_phone_resolve( $iso, $raw );
			$dial                 = diluxone_users_country_dial( $iso );
			$raw                  = '+' . $dial . ( '' === $dial ? preg_replace( '/\D/', '', $number ) : diluxone_users_phone_national( $iso, $number ) );

			// A number the wrong length for its country is not saved, whoever
			// called: the forms check first and say so, and a caller that does
			// not (Users → Add New, an add-on) keeps what was there.
			if ( '' !== $dial && '' !== diluxone_users_phone_check( $iso, $number ) ) {
				continue;
			}
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
