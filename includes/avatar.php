<?php
/**
 * The profile picture.
 *
 * WordPress brings an avatar system and settles it with Gravatar: it sends
 * each person's e-mail hash to a third party and fetches an image. That may
 * be fine or not depending on the site, so here there are three layers and
 * all three can be turned on and off:
 *
 *   1. The picture the person uploaded.
 *   2. Gravatar.
 *   3. Their initials over the accent colour.
 *
 * The third one is drawn as an SVG in a data URI, and not as a `<span>` with
 * letters: the contract of `get_avatar` is that it returns an image, and half
 * of WordPress — and half of every theme — assumes that.
 *
 * @package DiluxOneUsers
 */

defined( 'ABSPATH' ) || exit;

/** The attachment the person uploaded, or 0. */
function diluxone_users_avatar_id( int $user_id ): int {
	return (int) get_user_meta( $user_id, 'diluxone_users_avatar', true );
}

/**
 * The site whose media library holds that attachment.
 *
 * A person's meta is the network's and an attachment id is one site's: post
 * 123 on the site next door is somebody else's picture, or no picture at all.
 * So the picture remembers where it was uploaded, and is read and deleted
 * there.
 */
function diluxone_users_avatar_site( int $user_id ): int {
	$site = (int) get_user_meta( $user_id, 'diluxone_users_avatar_site', true );

	return $site > 0 ? $site : get_current_blog_id();
}

/**
 * Runs something on the site that holds this person's picture.
 *
 * @template T
 * @param callable(): T $work
 * @return T
 */
function diluxone_users_avatar_on_its_site( int $user_id, callable $work ) {
	$site   = diluxone_users_avatar_site( $user_id );
	$switch = is_multisite() && get_current_blog_id() !== $site;

	if ( $switch ) {
		switch_to_blog( $site );
	}

	$result = $work();

	if ( $switch ) {
		restore_current_blog();
	}

	return $result;
}

/** The URL of the uploaded picture, at the size asked for. Empty when there is none. */
function diluxone_users_avatar_url( int $user_id, int $size = 96 ): string {
	$id = diluxone_users_avatar_id( $user_id );

	if ( $id <= 0 ) {
		return '';
	}

	return (string) diluxone_users_avatar_on_its_site(
		$user_id,
		static function () use ( $id, $size ): string {
			if ( ! wp_attachment_is_image( $id ) ) {
				return '';
			}

			$src = wp_get_attachment_image_src( $id, $size > 150 ? 'medium' : 'thumbnail' );

			return is_array( $src ) ? (string) $src[0] : '';
		}
	);
}

/** Somebody's initials, for the drawn avatar. */
function diluxone_users_avatar_initials( int $user_id ): string {
	$user = get_userdata( $user_id );

	return $user instanceof WP_User ? diluxone_users_initials( $user ) : '?';
}

/**
 * The drawn avatar: the initials over the accent colour.
 *
 * It goes as a data URI so there is not one extra request nor a file to
 * generate. The colour comes from the same setting as the rest of the sheet,
 * so a site changing its accent changes these avatars too.
 */
function diluxone_users_avatar_svg( int $user_id, int $size ): string {
	$letters = diluxone_users_avatar_initials( $user_id );
	$accent  = diluxone_users_style_accent();

	$svg = sprintf(
		'<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 100 100" width="%1$d" height="%1$d" role="img" aria-hidden="true">'
			. '<rect width="100" height="100" rx="50" fill="%2$s"/>'
			. '<text x="50" y="50" fill="#ffffff" font-family="system-ui, sans-serif" font-size="42" font-weight="700"'
			. ' text-anchor="middle" dominant-baseline="central">%3$s</text></svg>',
		$size,
		esc_attr( $accent ),
		esc_html( $letters )
	);

	return 'data:image/svg+xml;base64,' . base64_encode( $svg );
}

/**
 * Works out who what reaches get_avatar refers to.
 *
 * WordPress passes it in five different ways depending on the caller. Without
 * this, the avatar shows up in the profile and not in the comments, or the
 * other way round.
 *
 * @param mixed $id_or_email Whatever WordPress sent: an id, an e-mail,
 *                           a WP_User, a WP_Comment or a WP_Post.
 */
function diluxone_users_avatar_user_id( $id_or_email ): int {
	if ( is_numeric( $id_or_email ) ) {
		return (int) $id_or_email;
	}

	if ( $id_or_email instanceof WP_User ) {
		return (int) $id_or_email->ID;
	}

	if ( $id_or_email instanceof WP_Post ) {
		return (int) $id_or_email->post_author;
	}

	if ( $id_or_email instanceof WP_Comment ) {
		if ( ! empty( $id_or_email->user_id ) ) {
			return (int) $id_or_email->user_id;
		}

		$id_or_email = (string) $id_or_email->comment_author_email;
	}

	if ( is_string( $id_or_email ) && is_email( $id_or_email ) ) {
		$user = get_user_by( 'email', $id_or_email );

		return $user instanceof WP_User ? (int) $user->ID : 0;
	}

	return 0;
}

/**
 * The avatar URL.
 *
 * `pre_get_avatar_data` is filtered and not `get_avatar`, which is where
 * everybody usually piles in: here the data is changed and the markup is
 * still assembled by WordPress, with the classes and sizes every theme
 * expects. And no other filter is touched: if there is another avatar plugin,
 * let them fight it out by priority as they should, not by erasing each other.
 *
 * @param array<string, mixed> $args
 * @param mixed                $id_or_email
 * @return array<string, mixed>
 */
function diluxone_users_avatar_data( array $args, $id_or_email ): array {
	$user_id = diluxone_users_avatar_user_id( $id_or_email );

	if ( $user_id <= 0 ) {
		return $args;
	}

	$size = isset( $args['size'] ) ? (int) $args['size'] : 96;

	if ( diluxone_users_option( 'diluxone_users_avatar_upload' ) ) {
		$url = diluxone_users_avatar_url( $user_id, $size );

		if ( '' !== $url ) {
			$args['url']          = $url;
			$args['found_avatar'] = true;

			return $args;
		}
	}

	if ( diluxone_users_option( 'diluxone_users_avatar_gravatar' ) ) {
		return $args;
	}

	if ( diluxone_users_option( 'diluxone_users_avatar_initials' ) ) {
		$args['url']          = diluxone_users_avatar_svg( $user_id, $size );
		$args['found_avatar'] = true;
	}

	return $args;
}
add_filter( 'pre_get_avatar_data', 'diluxone_users_avatar_data', 99, 2 );

/**
 * Puts the drawn avatar back into the markup WordPress assembled.
 *
 * `get_avatar()` runs the URL through `esc_url()`, and `esc_url()` drops
 * anything whose scheme is not in `wp_allowed_protocols()` — `data:` is not,
 * so the image comes out with an empty `src`. Adding `data` to that list is
 * not an option: it is only filterable before `wp_loaded`, after which the
 * list is frozen, so the change would apply to every `esc_url()` and every
 * `wp_kses()` call in the request, which is precisely the kind of hole `data:`
 * is kept out of.
 *
 * So the URL is put back afterwards, and only on the image we ourselves
 * supplied: if anybody else has already rewritten the markup, the replacement
 * finds nothing and the markup is returned untouched.
 *
 * @param mixed                $id_or_email Whatever WordPress was given.
 * @param array<string, mixed> $args
 */
function diluxone_users_avatar_markup( string $avatar, $id_or_email, int $size, string $default_value, string $alt, array $args = array() ): string {
	$url = isset( $args['url'] ) ? (string) $args['url'] : '';

	if ( 0 !== strpos( $url, 'data:image/svg+xml' ) ) {
		return $avatar;
	}

	$user_id = diluxone_users_avatar_user_id( $id_or_email );

	if ( $user_id <= 0 ) {
		return $avatar;
	}

	return str_replace(
		array( "src=''", "srcset=' 2x'" ),
		array(
			"src='" . esc_attr( $url ) . "'",
			"srcset='" . esc_attr( diluxone_users_avatar_svg( $user_id, $size * 2 ) ) . " 2x'",
		),
		$avatar
	);
}
add_filter( 'get_avatar', 'diluxone_users_avatar_markup', 10, 6 );

/* ── Uploading and removing ────────────────────────────────────────── */

/** The largest side a profile photo may have, in pixels. */
const DILUXONE_USERS_AVATAR_MAX_SIDE = 6000;

/**
 * The accepted types. No SVG: that is code, not a photograph.
 *
 * @return array<int, string>
 */
function diluxone_users_avatar_types(): array {
	return array( 'image/jpeg', 'image/png', 'image/gif', 'image/webp' );
}

/**
 * Stores the picture somebody uploaded.
 *
 * @param array<string, mixed> $file
 * @return int|WP_Error The attachment id.
 */
function diluxone_users_avatar_upload( int $user_id, array $file ) {
	if ( ! diluxone_users_option( 'diluxone_users_avatar_upload' ) ) {
		return new WP_Error( 'diluxone_users_avatar_off', __( 'This site does not accept profile photos.', 'diluxone-users' ) );
	}

	if ( empty( $file['tmp_name'] ) || ! is_uploaded_file( $file['tmp_name'] ) ) {
		return new WP_Error( 'diluxone_users_avatar_none', __( 'No file arrived.', 'diluxone-users' ) );
	}

	$max = max( 1, (int) diluxone_users_option( 'diluxone_users_avatar_max_kb' ) ) * KB_IN_BYTES;

	if ( (int) ( $file['size'] ?? 0 ) > $max ) {
		return new WP_Error(
			'diluxone_users_avatar_big',
			sprintf(
					/* translators: %s: maximum size, already formatted */
				__( 'The photo is too heavy: at most %s.', 'diluxone-users' ),
				size_format( $max )
			)
		);
	}

	// The type is checked by content and not by file name: the extension is
	// written by whoever uploads.
	$type = wp_check_filetype_and_ext( $file['tmp_name'], (string) ( $file['name'] ?? '' ) );

	if ( empty( $type['type'] ) || ! in_array( $type['type'], diluxone_users_avatar_types(), true ) ) {
		return new WP_Error( 'diluxone_users_avatar_type', __( 'That is not a photo. It has to be a JPG, PNG, GIF or WebP.', 'diluxone-users' ) );
	}

	// A small file can still be a huge picture: a two-megabyte PNG of twenty
	// thousand pixels a side is a few gigabytes once the image library opens
	// it to make the thumbnails. The size in pixels is read from the header,
	// without opening the picture, and refused before anything does.
	$pixels = wp_getimagesize( $file['tmp_name'] );

	if ( ! is_array( $pixels ) || (int) $pixels[0] > DILUXONE_USERS_AVATAR_MAX_SIDE || (int) $pixels[1] > DILUXONE_USERS_AVATAR_MAX_SIDE ) {
		return new WP_Error(
			'diluxone_users_avatar_huge',
			sprintf(
				/* translators: %d: the largest side allowed, in pixels */
				__( 'The photo is too large: at most %d pixels a side.', 'diluxone-users' ),
				DILUXONE_USERS_AVATAR_MAX_SIDE
			)
		);
	}

	require_once ABSPATH . 'wp-admin/includes/file.php';
	require_once ABSPATH . 'wp-admin/includes/media.php';
	require_once ABSPATH . 'wp-admin/includes/image.php';

	$attachment_id = media_handle_sideload(
		array(
			'name'     => $file['name'],
			'tmp_name' => $file['tmp_name'],
		),
		0,
		null,
		array( 'post_author' => $user_id )
	);

	if ( is_wp_error( $attachment_id ) ) {
		return $attachment_id;
	}

	diluxone_users_avatar_delete( $user_id );
	update_user_meta( $user_id, 'diluxone_users_avatar', (int) $attachment_id );
	update_user_meta( $user_id, 'diluxone_users_avatar_site', get_current_blog_id() );

	return (int) $attachment_id;
}

/**
 * Deletes somebody's picture.
 *
 * It is checked that the attachment is theirs before touching it: without
 * that, a meta holding another person's picture id deletes that other
 * person's picture.
 */
function diluxone_users_avatar_delete( int $user_id ): void {
	$id = diluxone_users_avatar_id( $user_id );

	if ( $id <= 0 ) {
		return;
	}

	diluxone_users_avatar_on_its_site(
		$user_id,
		static function () use ( $id, $user_id ): void {
			if ( (int) get_post_field( 'post_author', $id ) === $user_id ) {
				wp_delete_attachment( $id, true );
			}
		}
	);

	delete_user_meta( $user_id, 'diluxone_users_avatar' );
	delete_user_meta( $user_id, 'diluxone_users_avatar_site' );
}

/** The picture form. Shortcode: [diluxone_users_avatar] */
function diluxone_users_shortcode_avatar(): string {
	if ( ! is_user_logged_in() || ! diluxone_users_option( 'diluxone_users_avatar_upload' ) ) {
		return '';
	}

	$user = wp_get_current_user();

	return diluxone_users_render(
		'account/avatar',
		array(
			'user'  => $user,
			'has'   => diluxone_users_avatar_id( $user->ID ) > 0,
			'error' => diluxone_users_flash_take( $user->ID, 'avatar' ),
		)
	);
}
add_shortcode( 'diluxone_users_avatar', 'diluxone_users_shortcode_avatar' );

/** Receives the picture or removes it. */
function diluxone_users_avatar_submit(): void {
	if ( ! is_user_logged_in() ) {
		wp_safe_redirect( diluxone_users_login_url() );
		exit;
	}

	check_admin_referer( 'diluxone_users_avatar' );

	$user_id = get_current_user_id();
	$target  = diluxone_users_account_url( 'details' );

	if ( isset( $_POST['diluxone_users_avatar_remove'] ) ) {
		diluxone_users_avatar_delete( $user_id );
		wp_safe_redirect( add_query_arg( 'diluxone-users', 'saved', $target ) );
		exit;
	}

	// Only the three things the upload needs, each checked for what it is:
	// a posted `tmp_name[]` is an array, and an array is not a path.
	// phpcs:disable WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- tmp_name is PHP's own temporary path, never slashed and checked with is_uploaded_file(); the name is sanitised here.
	$posted = isset( $_FILES['diluxone_users_avatar_file'] ) && is_array( $_FILES['diluxone_users_avatar_file'] ) ? $_FILES['diluxone_users_avatar_file'] : array();
	$file   = array(
		'tmp_name' => isset( $posted['tmp_name'] ) && is_string( $posted['tmp_name'] ) ? $posted['tmp_name'] : '',
		'name'     => isset( $posted['name'] ) && is_string( $posted['name'] ) ? sanitize_file_name( $posted['name'] ) : '',
		'size'     => isset( $posted['size'] ) && is_numeric( $posted['size'] ) ? (int) $posted['size'] : 0,
	);
	// phpcs:enable

	$result = diluxone_users_avatar_upload( $user_id, $file );

	if ( is_wp_error( $result ) ) {
		diluxone_users_flash_set( $user_id, 'avatar', $result->get_error_message() );
		wp_safe_redirect( $target );
		exit;
	}

	wp_safe_redirect( add_query_arg( 'diluxone-users', 'saved', $target ) );
	exit;
}
add_action( 'admin_post_diluxone_users_avatar', 'diluxone_users_avatar_submit' );
