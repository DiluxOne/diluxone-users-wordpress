<?php
/**
 * The e-mails of "Your data", in the plugin's words and the site's.
 *
 * WordPress sends four e-mails for a privacy request: the confirmation, for a
 * copy and for an erasure; the link to the finished file; and the notice that
 * the erasure was done. Its words are written for a request an administrator
 * filed ("A request has been made to perform the following action on your
 * account", "Howdy"), in a voice nothing else on the site uses, and the file's
 * e-mail carries the file's own address.
 *
 * For a request filed from the account area these are the plugin's
 * templates instead, listed with the others on Notices → Templates and
 * rewritable there in each language. They are filled in through WordPress's
 * own filters, so everything else WordPress does with those e-mails stays as
 * it is: the recipient, the headers, and its own placeholders, which are left
 * in the text for WordPress to replace (`###CONFIRM_URL###`, `###LINK###`,
 * `###EXPIRATION###`). A request filed from Tools keeps WordPress's words.
 *
 * @package DiluxOneUsers
 */

defined( 'ABSPATH' ) || exit;

/**
 * The four, added to the templates a site can rewrite.
 *
 * @param array<string, array<string, mixed>> $templates
 * @return array<string, array<string, mixed>>
 */
function diluxone_users_data_mail_templates( array $templates ): array {
	$site = __( 'the name of this site', 'diluxone-users' );
	$who  = __( 'the name of the person it goes to, or what comes before the at sign when they have not written one', 'diluxone-users' );
	$only = __( 'Only for requests made from the account area; those filed from Tools keep WordPress’s own words.', 'diluxone-users' );

	$templates['data_export_confirm'] = array(
		'label'    => __( 'Confirming a copy of their data', 'diluxone-users' ),
		'help'     => __( 'Sent when somebody asks for a copy of their data from their account.', 'diluxone-users' ) . ' ' . $only,
		'vars'     => array(
			'{link}' => __( 'the address that confirms it — the e-mail is useless without it', 'diluxone-users' ),
			'{site}' => $site,
			'{name}' => $who,
		),
		'required' => array( '{link}' ),
		'shipped'  => static function (): array {
			return array(
				'subject' => sprintf(
					/* translators: %s: site name */
					__( 'Confirm your copy of your data on %s', 'diluxone-users' ),
					'{site}'
				),
				'body'    => __( "You asked for a copy of everything this site keeps about you. To confirm it, open this link:\n\n{link}\n\nIf you did not ask for it, ignore this message: nothing happens without it.", 'diluxone-users' ),
			);
		},
	);

	$templates['data_delete_confirm'] = array(
		'label'    => __( 'Confirming the deletion of their account', 'diluxone-users' ),
		'help'     => __( 'Sent when somebody asks from their account for the account to be deleted.', 'diluxone-users' ) . ' ' . $only,
		'vars'     => array(
			'{link}' => __( 'the address that confirms it — the e-mail is useless without it', 'diluxone-users' ),
			'{site}' => $site,
			'{name}' => $who,
		),
		'required' => array( '{link}' ),
		'shipped'  => static function (): array {
			return array(
				'subject' => sprintf(
					/* translators: %s: site name */
					__( 'Confirm the deletion of your account on %s', 'diluxone-users' ),
					'{site}'
				),
				'body'    => __( "You asked for your account to be deleted. To go ahead, open this link:\n\n{link}\n\nIf you did not ask for it, ignore this message: nothing is deleted without it. Somebody used your account, so change your password.", 'diluxone-users' ),
			);
		},
	);

	$templates['data_export_ready'] = array(
		'label'    => __( 'Their copy is ready', 'diluxone-users' ),
		'help'     => __( 'Sent when the file with their data has been made.', 'diluxone-users' ) . ' ' . $only,
		'vars'     => array(
			'{download}' => __( 'where to download it: their account, or the file itself if the site mails the link — the e-mail is useless without it', 'diluxone-users' ),
			'{until}'    => __( 'the date the file is deleted', 'diluxone-users' ),
			'{site}'     => $site,
			'{name}'     => $who,
		),
		'required' => array( '{download}' ),
		'shipped'  => static function (): array {
			return array(
				'subject' => sprintf(
					/* translators: %s: site name */
					__( 'Your data from %s is ready', 'diluxone-users' ),
					'{site}'
				),
				'body'    => __( "The file with everything this site keeps about you is ready. Download it here:\n\n{download}\n\nIt is deleted on {until}, so download it before then.", 'diluxone-users' ),
			);
		},
	);

	$templates['data_account_closed'] = array(
		'label'    => __( 'Their account was deleted', 'diluxone-users' ),
		'help'     => __( 'Sent when the deletion they asked for has been carried out.', 'diluxone-users' ) . ' ' . $only,
		'vars'     => array(
			'{site}' => $site,
			'{name}' => $who,
		),
		'required' => array(),
		'shipped'  => static function (): array {
			return array(
				'subject' => sprintf(
					/* translators: %s: site name */
					__( 'Your account on %s was deleted', 'diluxone-users' ),
					'{site}'
				),
				'body'    => __( "Your account was deleted, as you asked, and what this site kept about you has been erased.\n\nIf you ever want to come back, you can make a new account.", 'diluxone-users' ),
			);
		},
	);

	return $templates;
}
add_filter( 'diluxone_users_mail_templates', 'diluxone_users_data_mail_templates', 5 );

/**
 * Which template a request's e-mail uses, or '' when it keeps WordPress's.
 *
 * @param mixed  $request The request the e-mail is about.
 * @param string $moment  'confirm', 'ready' or 'closed'.
 */
function diluxone_users_data_mail_key( $request, string $moment ): string {
	if ( ! $request instanceof WP_User_Request ) {
		return '';
	}

	$id = (int) $request->ID;

	if ( 'remove_personal_data' === $request->action_name && diluxone_users_closing_request( $id ) > 0 ) {
		return array(
			'confirm' => 'data_delete_confirm',
			'closed'  => 'data_account_closed',
		)[ $moment ] ?? '';
	}

	if ( 'export_personal_data' === $request->action_name && diluxone_users_exporting_request( $id ) ) {
		return array(
			'confirm' => 'data_export_confirm',
			'ready'   => 'data_export_ready',
		)[ $moment ] ?? '';
	}

	return '';
}

/**
 * A request's e-mail composed from its template, or null when it keeps WordPress's.
 *
 * @param mixed                 $request
 * @param array<string, string> $values Placeholder => what goes in its place.
 * @return array{subject: string, body: string}|null
 */
function diluxone_users_data_mail( $request, string $moment, array $values = array() ): ?array {
	$key = diluxone_users_data_mail_key( $request, $moment );

	if ( '' === $key || ! $request instanceof WP_User_Request ) {
		return null;
	}

	return diluxone_users_mail_compose(
		$key,
		$values + array(
			'{site}' => diluxone_users_site_name(),
			'{name}' => diluxone_users_mail_person( (string) $request->email ),
		)
	);
}

/**
 * The confirmation's subject.
 *
 * @param string               $subject
 * @param string               $sitename
 * @param array<string, mixed> $email_data
 */
function diluxone_users_data_confirm_subject( $subject, $sitename, $email_data ): string {
	$mail = diluxone_users_data_mail( $email_data['request'] ?? null, 'confirm' );

	return null === $mail ? (string) $subject : $mail['subject'];
}
add_filter( 'user_request_action_email_subject', 'diluxone_users_data_confirm_subject', 10, 3 );

/**
 * The confirmation's text, with WordPress's own placeholder for the link.
 *
 * @param string               $content
 * @param array<string, mixed> $email_data
 */
function diluxone_users_data_confirm_content( $content, $email_data ): string {
	$mail = diluxone_users_data_mail( $email_data['request'] ?? null, 'confirm', array( '{link}' => '###CONFIRM_URL###' ) );

	return null === $mail ? (string) $content : $mail['body'];
}
add_filter( 'user_request_action_email_content', 'diluxone_users_data_confirm_content', 10, 2 );

/** Where the ready e-mail sends them: the account, unless the site mails the file. */
function diluxone_users_data_mail_download(): string {
	return 'link' === diluxone_users_option( 'diluxone_users_privacy_export_file' )
		? '###LINK###'
		: diluxone_users_account_url( 'privacy' );
}

/**
 * The ready e-mail's subject.
 *
 * @param string               $subject
 * @param string               $sitename
 * @param array<string, mixed> $email_data
 */
function diluxone_users_data_ready_subject( $subject, $sitename, $email_data ): string {
	$mail = diluxone_users_data_mail( $email_data['request'] ?? null, 'ready' );

	return null === $mail ? (string) $subject : $mail['subject'];
}
add_filter( 'wp_privacy_personal_data_email_subject', 'diluxone_users_data_ready_subject', 10, 3 );

/**
 * The ready e-mail's text.
 *
 * @param string               $content
 * @param int                  $request_id
 * @param array<string, mixed> $email_data
 */
function diluxone_users_data_ready_content( $content, $request_id, $email_data ): string {
	$mail = diluxone_users_data_mail(
		$email_data['request'] ?? null,
		'ready',
		array(
			'{download}' => diluxone_users_data_mail_download(),
			'{until}'    => '###EXPIRATION###',
		)
	);

	return null === $mail ? (string) $content : $mail['body'];
}
add_filter( 'wp_privacy_personal_data_email_content', 'diluxone_users_data_ready_content', 10, 3 );

/**
 * The erasure notice's subject.
 *
 * @param string               $subject
 * @param string               $sitename
 * @param array<string, mixed> $email_data
 */
function diluxone_users_data_closed_subject( $subject, $sitename, $email_data ): string {
	$mail = diluxone_users_data_mail( $email_data['request'] ?? null, 'closed' );

	return null === $mail ? (string) $subject : $mail['subject'];
}
add_filter( 'user_erasure_fulfillment_email_subject', 'diluxone_users_data_closed_subject', 10, 3 );

/**
 * The erasure notice's text.
 *
 * @param string               $content
 * @param array<string, mixed> $email_data
 */
function diluxone_users_data_closed_content( $content, $email_data ): string {
	$mail = diluxone_users_data_mail( $email_data['request'] ?? null, 'closed' );

	return null === $mail ? (string) $content : $mail['body'];
}
add_filter( 'user_erasure_fulfillment_email_content', 'diluxone_users_data_closed_content', 10, 2 );
