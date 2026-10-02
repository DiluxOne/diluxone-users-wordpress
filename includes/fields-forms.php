<?php
/**
 * The fields, dropped into the forms that already exist.
 *
 * The plugin does not know how people get into the site, and it does not have
 * to: the same fields appear in WordPress's own registration, in the sign-up
 * an administrator performs, in the dashboard profile and — through a
 * shortcode — on whatever screen the site has on the front end. A site with
 * open registration and one with SSO or an e-mail link end up with the same
 * data stored.
 *
 * @package DiluxOneUsers
 */

defined( 'ABSPATH' ) || exit;

/**
 * A single field: its name above it, the box, and why we ask underneath.
 *
 * It was a row of a `form-table` — the name in the left column, the box in
 * the right — and that shape is the one the plugin stopped drawing. It does
 * not come back through the door of a screen that belongs to WordPress: the
 * block added here is the plugin's, and a person who has just set the field
 * up on the plugin's own screen should meet it in the same shape here.
 *
 * @param array<string, mixed> $field
 */
function diluxone_users_field_control( array $field, int $user_id ): void {
	$id = 'diluxone-users-' . $field['key'];

	diluxone_users_ui_field_open( (string) $field['label'], $id );
	diluxone_users_field_input( $field, diluxone_users_value( $user_id, $field['key'] ), $id, $user_id );
	diluxone_users_ui_field_close( (string) $field['help'] );
}

/**
 * The whole block of them, for a screen of WordPress's own.
 *
 * The dashboard profile and the sign-up an administrator performs want the
 * same thing and differ in one detail: on a sign-up there is nobody yet, so
 * there is nothing answered to show.
 *
 * The ground is not decoration. The design system's measurements — the scale
 * of space, the greys, the accent taken from the colour scheme — are declared
 * on the class `diluxone_users_ui_ground_open()` prints, and these two screens
 * are WordPress's own and have no such ground under them. Without it the
 * pieces get their rules and none of the numbers in them.
 */
function diluxone_users_fields_block( int $user_id ): void {
	diluxone_users_ui_ground_open();

	diluxone_users_ui_section( __( 'Additional details', 'diluxone-users' ) );

	foreach ( diluxone_users_fields() as $field ) {
		// First and last name are already on this screen, drawn by WordPress
		// under "Name" with the same input names. Drawn again here they were
		// two fields posting under one name: the second copy won, and a name
		// changed in WordPress's own field came back as it was.
		if ( diluxone_users_field_is_native( (string) $field['key'] ) ) {
			continue;
		}
		diluxone_users_field_control( $field, $user_id );
	}

	diluxone_users_ui_ground_close();
}

/**
 * A field's control: the matching <input>, <select> or <textarea>.
 *
 * It is separated from the surrounding markup on purpose: it is the only part
 * that cannot change between the dashboard, the registration and the
 * front-end template, so it is written once and all three use it.
 *
 * @param array<string, mixed> $field
 * @param string               $value   What is stored.
 * @param string               $id      The id the label points at; the key when empty.
 * @param int                  $user_id Whose field it is; the person looking when 0.
 */
function diluxone_users_field_input( array $field, string $value, string $id = '', int $user_id = 0 ): void {
	$key           = $field['key'];
	$id            = '' === $id ? $key : $id;
	$required_attr = $field['required'] ? ' required' : '';
	// Always there, escaped where it is printed: an empty placeholder is no
	// placeholder, and an attribute built into a string elsewhere is one the
	// escaping cannot be seen on.
	$placeholder = (string) $field['placeholder'];

	// A field that cannot be changed is shown all the same: the data belongs to
	// the person and they have a right to see it. On the ones you type into it
	// is `readonly`, which allows copying and still submits; on the ones you
	// pick from there is no `readonly` and `disabled` has to be used. What
	// rules either way is the server: this is so it is understood, not to
	// prevent anything.
	// Asked about the owner of the field, not the person looking: an
	// administrator on somebody else's profile may change what its owner
	// may not.
	$editable = diluxone_users_field_editable( $field, $user_id > 0 ? $user_id : get_current_user_id() );
	$lock     = $editable ? '' : ' readonly';
	$lock_sel = $editable ? '' : ' disabled';

	/**
	 * Filters the whole input, before the built-in types are tried.
	 *
	 * Return markup and it is printed instead of anything the plugin would
	 * draw. It is a filter and not an action so that whatever answers can be
	 * sure nothing else printed first.
	 *
	 * @since 1.0.0
	 *
	 * @param string               $html  '' to leave it to the plugin.
	 * @param array<string, mixed> $field The field.
	 * @param string               $value What the person has stored.
	 * @param string               $id    The id the label points at.
	 */
	$own = (string) apply_filters( 'diluxone_users_field_input', '', $field, $value, $id );

	if ( '' !== $own ) {
		echo wp_kses( $own, diluxone_users_field_input_tags() );

		return;
	}

	switch ( $field['type'] ) {
		case 'textarea':
			printf(
				'<textarea id="%1$s" name="%2$s" rows="4"%3$s placeholder="%4$s">%5$s</textarea>',
				esc_attr( $id ),
				esc_attr( $key ),
				esc_attr( $required_attr . $lock ),
				esc_attr( $placeholder ),
				esc_textarea( $value )
			);
			return;

		case 'select':
			printf( '<select id="%1$s" name="%2$s"%3$s>', esc_attr( $id ), esc_attr( $key ), esc_attr( $required_attr . $lock_sel ) );
			printf( '<option value="">%s</option>', esc_html__( '— Choose —', 'diluxone-users' ) );

			foreach ( $field['options'] as $option ) {
				printf(
					'<option value="%1$s"%2$s>%1$s</option>',
					esc_attr( $option ),
					selected( $value, $option, false )
				);
			}

			echo '</select>';
			return;

		case 'checkbox':
			// An unticked box sends nothing, and the save leaves alone a key
			// that did not arrive — a form showing some fields must not empty
			// the rest. So an empty value goes first under the same name, and
			// the box, when ticked, comes after it and wins. A locked box is
			// not saved at all and needs none.
			if ( '' === $lock_sel ) {
				printf( '<input type="hidden" name="%s" value="">', esc_attr( $key ) );
			}

			printf(
				'<label><input type="checkbox" id="%1$s" name="%2$s" value="1"%3$s%4$s> %5$s</label>',
				esc_attr( $id ),
				esc_attr( $key ),
				checked( $value, '1', false ),
				esc_attr( $lock_sel ),
				esc_html( $field['label'] )
			);
			return;

		case 'country':
			printf( '<select id="%1$s" name="%2$s"%3$s>', esc_attr( $id ), esc_attr( $key ), esc_attr( $required_attr . $lock_sel ) );
			printf( '<option value="">%s</option>', esc_html__( '— Choose —', 'diluxone-users' ) );

			$preferred = diluxone_users_countries_sorted( $field['options'] );
			$cut       = count( $field['options'] );
			$n         = 0;

			foreach ( $preferred as $iso => $country_name ) {
				// The preferred ones go on top and apart from the rest: otherwise
				// the site's own country is lost halfway down a list of 189.
				if ( $cut > 0 && $n === $cut ) {
					echo '<option value="" disabled>──────────</option>';
				}

				printf(
					'<option value="%1$s"%2$s>%3$s</option>',
					esc_attr( $iso ),
					selected( $value, $iso, false ),
					esc_html( $country_name )
				);

				++$n;
			}

			echo '</select>';
			return;

		case 'phone':
			// Two controls and a single value: the dialling code is picked from a
			// list and the number is typed without it. What is stored is the sum.
			$dial_default = strtoupper( (string) ( $field['options'][0] ?? '' ) );
			$dial         = $dial_default;
			$national     = $value;

			// On reading a stored value back it has to be split again. It is tried
			// from the longest dialling code to the shortest because +1 and +1242
			// coexist.
			if ( '' !== $value ) {
				$digits = ltrim( $value, '+' );
				$best   = 0;

				foreach ( diluxone_users_countries() as $iso => $data ) {
					$len = strlen( $data[1] );

					if ( $len > $best && 0 === strpos( $digits, $data[1] ) ) {
						$best     = $len;
						$dial     = $iso;
						$national = substr( $digits, $len );
					}
				}
			}

			echo '<span class="diluxone-users-phone">';
			printf( '<select id="%1$s-dial" name="%2$s_dial" class="diluxone-users-phone__dial"%3$s>', esc_attr( $id ), esc_attr( $key ), esc_attr( $lock_sel ) );

			foreach ( diluxone_users_countries_sorted( $field['options'] ) as $iso => $country_name ) {
				printf(
					'<option value="%1$s"%2$s>%3$s +%4$s</option>',
					esc_attr( $iso ),
					selected( $dial, $iso, false ),
					esc_html( $country_name ),
					esc_html( diluxone_users_country_dial( $iso ) )
				);
			}

			echo '</select>';

			printf(
				'<input type="tel" id="%1$s" name="%2$s" value="%3$s" inputmode="tel" class="diluxone-users-phone__number" autocomplete="tel-national"%4$s placeholder="%5$s">',
				esc_attr( $id ),
				esc_attr( $key ),
				esc_attr( $national ),
				esc_attr( $required_attr . $lock ),
				esc_attr( $placeholder )
			);

			echo '</span>';
			return;

		case 'datalist':
			$list = 'diluxone-users-list-' . $key;

			printf(
				'<input type="text" id="%1$s" name="%2$s" value="%3$s" list="%4$s" autocomplete="off"%5$s placeholder="%6$s">',
				esc_attr( $id ),
				esc_attr( $key ),
				esc_attr( $value ),
				esc_attr( $list ),
				esc_attr( $required_attr . $lock ),
				esc_attr( $placeholder )
			);

			printf( '<datalist id="%s">', esc_attr( $list ) );

			foreach ( $field['options'] as $option ) {
				printf( '<option value="%s"></option>', esc_attr( $option ) );
			}

			echo '</datalist>';
			return;
	}

	$types = array(
		'email'  => 'email',
		'url'    => 'url',
		'number' => 'number',
		'date'   => 'date',
	);

	printf(
		'<input type="%1$s" id="%2$s" name="%3$s" value="%4$s"%5$s placeholder="%6$s">',
		esc_attr( $types[ $field['type'] ] ?? 'text' ),
		esc_attr( $id ),
		esc_attr( $key ),
		esc_attr( $value ),
		esc_attr( $required_attr . $lock ),
		esc_attr( $placeholder )
	);
}

/* ── The dashboard profile ─────────────────────────────────────────── */

/**
 * The plugin fields, in the dashboard profile.
 *
 * @param WP_User|string $user The person, or the string the sign-up hook passes.
 */
function diluxone_users_profile_fields( $user ): void {
	if ( ! $user instanceof WP_User ) {
		return;
	}

	if ( array() === diluxone_users_fields() ) {
		return;
	}

	diluxone_users_fields_block( (int) $user->ID );
}
add_action( 'show_user_profile', 'diluxone_users_profile_fields' );
add_action( 'edit_user_profile', 'diluxone_users_profile_fields' );

/**
 * Profile save.
 *
 * WordPress checks the profile form's nonce before it fires these two hooks;
 * it is checked here again, with the capability, so the save does not depend
 * on how it was reached.
 */
function diluxone_users_profile_save( int $user_id ): void {
	check_admin_referer( 'update-user_' . $user_id );

	if ( ! current_user_can( 'edit_user', $user_id ) ) {
		return;
	}

	diluxone_users_save( $user_id, diluxone_users_posted_fields( 'update-user_' . $user_id, '_wpnonce' ) );
}
add_action( 'personal_options_update', 'diluxone_users_profile_save' );
add_action( 'edit_user_profile_update', 'diluxone_users_profile_save' );

/* ── Sign-up from the dashboard (Users → Add New) ──────────────────── */

/** The plugin fields, in the dashboard sign-up of a person. */
function diluxone_users_new_user_fields( string $type ): void {
	if ( 'add-new-user' !== $type || array() === diluxone_users_fields() ) {
		return;
	}

	// Nobody yet: the account is what this form is about to create, so there
	// is nothing answered to show and every box starts empty.
	diluxone_users_fields_block( 0 );
}
add_action( 'user_new_form', 'diluxone_users_new_user_fields' );

/* ── WordPress's own registration ──────────────────────────────────── */

/**
 * The fields on wp-login.php?action=register.
 *
 * It is there for the site that does have open registration. On a site
 * without passwords this form is never used and this gets in nobody's way.
 */
function diluxone_users_register_form_fields(): void {
	if ( array() === diluxone_users_fields() ) {
		return;
	}

	// WordPress's form has no nonce of its own; the fields added to it do.
	wp_nonce_field( 'diluxone_users_wp_register', 'diluxone_users_wp_register_nonce' );

	foreach ( diluxone_users_fields() as $field ) {
		$id = 'diluxone-users-' . $field['key'];
		?>
		<p>
			<label for="<?php echo esc_attr( $id ); ?>"><?php echo esc_html( $field['label'] ); ?></label>
			<?php diluxone_users_field_input( $field, '', $id ); ?>
			<?php if ( '' !== $field['help'] ) : ?>
				<em class="description"><?php echo esc_html( $field['help'] ); ?></em>
			<?php endif; ?>
		</p>
		<?php
	}
}
add_action( 'register_form', 'diluxone_users_register_form_fields' );

/**
 * Did this request post any of the plugin's fields?
 *
 * Asked by name, whatever the value is — a list posted where one answer goes
 * still counts as somebody sending the field.
 */
function diluxone_users_register_fields_posted(): bool {
	foreach ( diluxone_users_fields() as $field ) {
		foreach ( array( (string) $field['key'], (string) $field['key'] . '_dial' ) as $name ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Missing -- presence only: whether this plugin's form sent the request at all, so another plugin's sign-up is not refused; nothing is read, and from here on the form's nonce is required.
			if ( isset( $_POST[ $name ] ) ) {
				return true;
			}
		}
	}

	return false;
}

/**
 * An empty required field does not let the registration go through.
 *
 * Only for the form that carries the plugin's fields. `registration_errors`
 * runs for every call to register_new_user() — another plugin's sign-up, a
 * shop's checkout, a form that never drew these fields — and refusing those
 * for a field they never showed was refusing every registration on the site
 * but WordPress's own. So with neither the fields' nonce nor any of the
 * fields in the request, there is nothing of this plugin's to check. Once a
 * field of this plugin is posted, the nonce has to be there and be right.
 *
 * @param WP_Error $errors The errors WordPress has already gathered.
 * @param string   $login  The username being registered.
 * @param string   $email  Their e-mail.
 * @return WP_Error
 */
function diluxone_users_register_validate( $errors, $login, $email ) {
	$fields = diluxone_users_fields();

	if ( array() === $fields ) {
		return $errors;
	}

	if ( ! isset( $_POST['diluxone_users_wp_register_nonce'] ) && ! diluxone_users_register_fields_posted() ) {
		return $errors;
	}

	// WordPress's registration form carries no nonce of its own, so the
	// fields this plugin adds to it carry one (see
	// diluxone_users_register_form_fields()). A form without it is not the
	// form that was drawn.
	if ( ! isset( $_POST['diluxone_users_wp_register_nonce'] ) || ! wp_verify_nonce( sanitize_key( wp_unslash( $_POST['diluxone_users_wp_register_nonce'] ) ), 'diluxone_users_wp_register' ) ) {
		$errors->add( 'diluxone_users_nonce', esc_html__( 'Error: the form expired. Load the page again and send it once more.', 'diluxone-users' ) );

		return $errors;
	}

	$sent = diluxone_users_posted_fields( 'diluxone_users_wp_register', 'diluxone_users_wp_register_nonce' );

	foreach ( $fields as $field ) {
		if ( ! $field['required'] ) {
			continue;
		}

		$value = diluxone_users_sanitize( $field, (string) ( $sent[ $field['key'] ] ?? '' ) );

		if ( '' === $value ) {
			$errors->add(
				'diluxone_users_' . $field['key'],
				sprintf(
						/* translators: %s: field name */
					esc_html__( 'Error: “%s” is required.', 'diluxone-users' ),
					esc_html( $field['label'] )
				)
			);
		}
	}

	return $errors;
}
add_filter( 'registration_errors', 'diluxone_users_register_validate', 10, 3 );

/**
 * The answers given on WordPress's own registration form.
 *
 * On `register_new_user`, which fires for that form only, and not on
 * `user_register`, which fires for every account created anywhere — the REST
 * API, a shop's checkout, another plugin — and would read whatever this
 * request happened to post into the new person's profile. The nonce is the
 * one the form's fields carry; the validation above already refused the
 * registration without it.
 */
function diluxone_users_register_save( int $user_id ): void {
	if ( ! isset( $_POST['diluxone_users_wp_register_nonce'] ) || ! wp_verify_nonce( sanitize_key( wp_unslash( $_POST['diluxone_users_wp_register_nonce'] ) ), 'diluxone_users_wp_register' ) ) {
		return;
	}

	diluxone_users_save( $user_id, diluxone_users_posted_fields( 'diluxone_users_wp_register', 'diluxone_users_wp_register_nonce' ) );
}
add_action( 'register_new_user', 'diluxone_users_register_save' );

/**
 * The answers given on Users → Add New, where the same fields are drawn.
 *
 * WordPress checks that screen's own nonce (`create-user`) before it creates
 * the account; what is asked here is only whether this person may create
 * accounts at all.
 */
function diluxone_users_new_user_save( int $user_id ): void {
	if ( ! current_user_can( 'create_users' ) ) {
		return;
	}

	check_admin_referer( 'create-user', '_wpnonce_create-user' );

	diluxone_users_save( $user_id, diluxone_users_posted_fields( 'create-user', '_wpnonce_create-user' ) );
}
add_action( 'edit_user_created_user', 'diluxone_users_new_user_save' );

/**
 * What markup a field's input is allowed to be.
 *
 * An add-on drawing its own input goes through this, so a filter cannot turn
 * a text box into a script tag. Form elements and the attributes they need,
 * and nothing else.
 *
 * @return array<string, array<string, bool>>
 */
function diluxone_users_field_input_tags(): array {
	$attrs = array(
		'id'           => true,
		'name'         => true,
		'class'        => true,
		'value'        => true,
		'type'         => true,
		'placeholder'  => true,
		'required'     => true,
		'readonly'     => true,
		'disabled'     => true,
		'checked'      => true,
		'selected'     => true,
		'multiple'     => true,
		'min'          => true,
		'max'          => true,
		'step'         => true,
		'rows'         => true,
		'cols'         => true,
		'maxlength'    => true,
		'pattern'      => true,
		'autocomplete' => true,
		'inputmode'    => true,
		'list'         => true,
		'for'          => true,
		'data-*'       => true,
		'aria-*'       => true,
	);

	return array(
		'input'    => $attrs,
		'textarea' => $attrs,
		'select'   => $attrs,
		'option'   => $attrs,
		'optgroup' => $attrs,
		'datalist' => $attrs,
		'label'    => $attrs,
		'span'     => $attrs,
		'div'      => $attrs,
		'p'        => $attrs,
		'fieldset' => $attrs,
		'legend'   => $attrs,
	);
}
