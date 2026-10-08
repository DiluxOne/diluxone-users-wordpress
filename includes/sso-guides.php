<?php
/**
 * The step-by-step for registering the application with each provider.
 *
 * It lives apart from the provider table on purpose.
 * `diluxone_users_sso_providers()` is protocol — URLs, scopes, how the profile
 * is read — and is consulted on every request, even before WordPress loads
 * the translations. This is documentation: only a person standing in the
 * admin looks at it, and there it can be translated without WordPress warning
 * that a translation was asked for too early.
 *
 * Each guide has:
 *   steps   The steps, in order, with the literal names of that console's
 *           fields. No "configure the app": which button to press.
 *   gotcha  What costs an afternoon. Empty when there is none.
 *
 * @package DiluxOneUsers
 */

defined( 'ABSPATH' ) || exit;

/**
 * One provider's guide.
 *
 * @param string $id Provider identifier.
 * @return array{steps: array<int, string>, gotcha: string}
 */
function diluxone_users_sso_guide( string $id ): array {
	$guides = diluxone_users_sso_guides();

	$guide = $guides[ $id ] ?? array();

	return array(
		'steps'  => $guide['steps'] ?? array(),
		'gotcha' => $guide['gotcha'] ?? '',
	);
}

/**
 * Every guide.
 *
 * @return array<string, array{steps: array<int, string>, gotcha: string}>
 */
function diluxone_users_sso_guides(): array {
	$guides = array(

		'google'    => array(
			'steps'  => array(
				__( 'Open the Google Cloud console and pick a project in the selector at the top, or create one. Everything below happens inside that project.', 'diluxone-users' ),
				__( 'Go to “APIs & Services” → “OAuth consent screen”. Choose user type “External”, and fill in the app name, the support email and the developer contact email. Save.', 'diluxone-users' ),
				__( 'Still on the consent screen, under “Audience”, either add your own account under “Test users” or press “Publish app”. While the app is in testing, only the test users can sign in.', 'diluxone-users' ),
				__( 'Go to “APIs & Services” → “Credentials” → “Create credentials” → “OAuth client ID”.', 'diluxone-users' ),
				__( 'Application type: “Web application”. Give it a name — this one is only for you.', 'diluxone-users' ),
				__( 'Under “Authorized redirect URIs” press “Add URI” and paste the redirect URL from above. Under “Authorized JavaScript origins” put just the domain, with no path.', 'diluxone-users' ),
				__( 'Press “Create”. Google shows the client ID and the client secret in a dialog — copy both before closing it.', 'diluxone-users' ),
			),
			'gotcha' => __( 'A brand new project needs no API enabled: sign-in works with the OAuth client alone. What does stop it is leaving the app in “Testing” and forgetting to add the person as a test user — they get “access blocked”.', 'diluxone-users' ),
		),

		'microsoft' => array(
			'steps'  => array(
				__( 'Open the Microsoft Entra admin center and go to “Identity” → “Applications” → “App registrations” → “New registration”.', 'diluxone-users' ),
				__( 'Give it a name — this is what people see on the consent screen.', 'diluxone-users' ),
				__( 'Supported account types: “Accounts in any organizational directory and personal Microsoft accounts”. Any narrower option locks out @outlook.com and @hotmail.com addresses.', 'diluxone-users' ),
				__( 'Under “Redirect URI” pick the platform “Web” and paste the redirect URL from above. Then press “Register”.', 'diluxone-users' ),
				__( 'On the overview page copy the “Application (client) ID”.', 'diluxone-users' ),
				__( 'Go to “Certificates & secrets” → “New client secret”, set an expiry, and press “Add”.', 'diluxone-users' ),
				__( 'Copy the secret’s “Value”, not its “Secret ID”. The value is shown once and never again.', 'diluxone-users' ),
			),
			'gotcha' => __( 'The openid, email and profile permissions are already there by default — you do not have to add anything under “API permissions”.', 'diluxone-users' ),
		),
	);

	/**
	 * Filters each provider's registration guide.
	 *
	 * @param array<string, array{steps: array<int, string>, gotcha: string}> $guides
	 */
	return apply_filters( 'diluxone_users_sso_guides', $guides );
}
