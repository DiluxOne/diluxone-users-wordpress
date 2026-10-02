<?php
/**
 * Downloading your data, from the account area.
 *
 * The request is WordPress's own export request: the person asks and confirms
 * by e-mail, and then every plugin's exporter hands over what that plugin
 * keeps, and WordPress puts it in a file. WordPress waits for an administrator
 * to make that file from Tools → Export Personal Data; a request filed from
 * the account area is made as soon as it is confirmed, unless the site said
 * it wants to go through them itself. The file is then mailed to the person,
 * as the Tools screen's "Send export link" does, and it can be downloaded
 * from the account area until WordPress clears old exports away.
 *
 * Requests an administrator files by hand from Tools are left as WordPress
 * leaves them.
 *
 * @package DiluxOneUsers
 */

defined( 'ABSPATH' ) || exit;

/** The key an export request filed from the account area carries in its data. */
const DILUXONE_USERS_EXPORT_KEY = 'diluxone_users_export';

/** Is this export request one the account area filed? */
function diluxone_users_exporting_request( int $request_id ): bool {
	$request = wp_get_user_request( $request_id );

	if ( ! $request instanceof WP_User_Request || 'export_personal_data' !== $request->action_name ) {
		return false;
	}

	return ! empty( ( (array) $request->request_data )[ DILUXONE_USERS_EXPORT_KEY ] );
}

/** Will a confirmed export from the account area be made on the spot? */
function diluxone_users_exports_on_confirm( int $request_id ): bool {
	return diluxone_users_exporting_request( $request_id )
		&& 'admin' !== diluxone_users_option( 'diluxone_users_privacy_export_when' )
		&& class_exists( 'ZipArchive' );
}

/**
 * Makes a confirmed export from the account area, there and then.
 *
 * What an administrator's "Send export link" on Tools → Export Personal Data
 * does, done when the person confirms: every registered exporter, page after
 * page until it says it is done; the data grouped the way WordPress groups
 * it; the file written by WordPress's own action; the link mailed; and the
 * request marked completed.
 *
 * Without ZipArchive WordPress cannot write the file, and its writer answers
 * with a JSON error and stops the page, so the request is left for the Tools
 * screen, which says why.
 *
 * After WordPress's own handling of the confirmation (priority 10 marks it
 * confirmed, 12 tells the administrator).
 *
 * @param int $request_id The request that was just confirmed.
 */
function diluxone_users_export_on_confirm( $request_id ): void {
	$request_id = (int) $request_id;

	if ( ! diluxone_users_exports_on_confirm( $request_id ) ) {
		return;
	}

	$request = wp_get_user_request( $request_id );
	$email   = $request instanceof WP_User_Request ? (string) $request->email : '';

	if ( '' === $email || ! wp_mkdir_p( wp_privacy_exports_dir() ) ) {
		return;
	}

	// The file writer and the mail live with the Tools screen.
	require_once ABSPATH . 'wp-admin/includes/file.php';
	require_once ABSPATH . 'wp-admin/includes/privacy-tools.php';

	/** This filter is documented in wp-admin/includes/ajax-actions.php */
	$exporters = (array) apply_filters( 'wp_privacy_personal_data_exporters', array() ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WordPress's own list of exporters, read as its Tools screen reads it.
	$data      = array();

	foreach ( $exporters as $exporter ) {
		if ( ! is_array( $exporter ) || ! isset( $exporter['callback'] ) || ! is_callable( $exporter['callback'] ) ) {
			continue;
		}

		// Page after page, with a ceiling: an exporter that never says it is
		// done does not hold the request.
		for ( $page = 1; $page <= 100; $page++ ) {
			$answer = call_user_func( $exporter['callback'], $email, $page );

			if ( ! is_array( $answer ) ) {
				break;
			}

			if ( isset( $answer['data'] ) && is_array( $answer['data'] ) ) {
				$data = array_merge( $data, $answer['data'] );
			}

			if ( ! empty( $answer['done'] ) ) {
				break;
			}
		}
	}

	update_post_meta( $request_id, '_export_data_grouped', diluxone_users_export_groups( $data ) );

	// WordPress hooks its writer to this action on admin screens only.
	if ( ! has_action( 'wp_privacy_personal_data_export_file', 'wp_privacy_generate_personal_data_export_file' ) ) {
		add_action( 'wp_privacy_personal_data_export_file', 'wp_privacy_generate_personal_data_export_file', 10 );
	}

	/** This action is documented in wp-admin/includes/privacy-tools.php */
	do_action( 'wp_privacy_personal_data_export_file', $request_id ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WordPress's own action, fired as its Tools screen fires it.

	delete_post_meta( $request_id, '_export_data_grouped' );

	if ( '' === (string) get_post_meta( $request_id, '_export_file_name', true ) ) {
		return;
	}

	wp_privacy_send_personal_data_export_email( $request_id );

	wp_update_post(
		array(
			'ID'          => $request_id,
			'post_status' => 'request-completed',
		)
	);
	update_post_meta( $request_id, '_wp_user_request_completed_timestamp', time() );
}
add_action( 'user_request_action_confirmed', 'diluxone_users_export_on_confirm', 20 );

/**
 * The exporters' answers, grouped the way WordPress groups them.
 *
 * By group, and inside a group by item, an item's fields from every exporter
 * merged into one.
 *
 * @param array<int, mixed> $data Every exporter's items, in order.
 * @return array<string, array{group_label: string, group_description: string, items: array<string, array<int, mixed>>}>
 */
function diluxone_users_export_groups( array $data ): array {
	$groups = array();

	foreach ( $data as $datum ) {
		if ( ! is_array( $datum ) || ! isset( $datum['group_id'], $datum['item_id'] ) ) {
			continue;
		}

		$group = (string) $datum['group_id'];
		$item  = (string) $datum['item_id'];

		if ( ! isset( $groups[ $group ] ) ) {
			$groups[ $group ] = array(
				'group_label'       => (string) ( $datum['group_label'] ?? $group ),
				'group_description' => (string) ( $datum['group_description'] ?? '' ),
				'items'             => array(),
			);
		}

		$groups[ $group ]['items'][ $item ] = array_merge( (array) ( $datum['data'] ?? array() ), $groups[ $group ]['items'][ $item ] ?? array() );
	}

	return $groups;
}

/**
 * What the confirmation page says when the file was made on the spot.
 *
 * WordPress's own sentence says the administrator was told and will get to
 * it, which is not what happened.
 *
 * @param string $message    What WordPress would say.
 * @param int    $request_id The request that was confirmed.
 */
function diluxone_users_exported_message( $message, $request_id ): string {
	$request_id = (int) $request_id;

	if ( ! diluxone_users_exporting_request( $request_id ) || 'request-completed' !== get_post_status( $request_id ) ) {
		return (string) $message;
	}

	return '<p class="success">' . esc_html__( 'Done: your file is ready. We have e-mailed you the link, and you can also download it from your account.', 'diluxone-users' ) . '</p>';
}
add_filter( 'user_request_action_confirmed_message', 'diluxone_users_exported_message', 10, 2 );

/**
 * Hands the file over to the account it belongs to.
 *
 * The file's own address works for anybody who has it, so the account never
 * shows it: its Download button comes here, and this checks that the person
 * signed in is the one the request is for before sending a byte.
 *
 * The link's nonce is checked before the request it names is read; whose file
 * it is, is the check after it.
 */
function diluxone_users_data_download(): void {
	check_admin_referer( 'diluxone_users_data_download' );

	$request_id = isset( $_GET['request'] ) ? absint( $_GET['request'] ) : 0;
	$request    = wp_get_user_request( $request_id );
	$user       = wp_get_current_user();
	$post       = get_post( $request_id );

	if ( ! $request instanceof WP_User_Request || ! $post instanceof WP_Post || ! $user->exists() || 0 !== strcasecmp( $user->user_email, $request->email ) ) {
		wp_die( esc_html__( 'This file is not yours to download.', 'diluxone-users' ), '', array( 'response' => 403 ) );
	}

	$path = diluxone_users_data_file_path( $post );

	if ( '' === $path ) {
		wp_die( esc_html__( 'This file is no longer there. Ask for your data again.', 'diluxone-users' ), '', array( 'response' => 404 ) );
	}

	nocache_headers();
	header( 'Content-Type: application/zip' );
	header( 'Content-Disposition: attachment; filename="' . sanitize_file_name( diluxone_users_site_name() . '-' . __( 'my-data', 'diluxone-users' ) . '.zip' ) . '"' );
	header( 'Content-Length: ' . (string) filesize( $path ) );

	// Streamed as it is read: an export can be larger than the memory a
	// request is allowed, and WP_Filesystem reads a whole file into a string.
	readfile( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_readfile -- streaming a file to the browser; WP_Filesystem has no streaming read.
	exit;
}
add_action( 'admin_post_diluxone_users_data_download', 'diluxone_users_data_download' );
