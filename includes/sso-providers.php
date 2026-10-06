<?php
/**
 * The social-login provider table.
 *
 * OAuth 2 is the same dance everywhere: send them off to authorise, come back
 * with a code, exchange it for a token, ask for the profile. The only thing
 * each provider has of its own is four URLs, a scope and how the profile it
 * returns is read. That is a table, not a class per provider: adding a new one
 * is adding a row, here or through `diluxone_users_sso_providers` from an
 * add-on, with no branch in the engine for any of them.
 *
 * Only the ones that work with this flow get in. Deliberately left out:
 *
 *   - **Apple**: the client secret is a JWT signed with ES256 that has to be
 *     regenerated every six months, and the answer comes back by POST
 *     (form_post). That is another flow, not one more row.
 *   - **Steam**: it does not use OAuth 2 but OpenID 2.0, which is a different
 *     protocol and on top of that returns no e-mail.
 *
 * A button that does not work is worse than not having the button.
 *
 * @package DiluxOneUsers
 */

defined( 'ABSPATH' ) || exit;

/**
 * Every provider the plugin knows how to handle: Google and Microsoft, and
 * whatever an add-on registers through the filter below.
 *
 * Fields of each one:
 *   name       What it is called for people.
 *   color      Its brand colour, for the card and the button.
 *   authorize  URL the person is sent to.
 *   token      URL where the code is exchanged for a token.
 *   profile    URL the profile is read from.
 *   scope      The permissions asked for.
 *   extra      Odd parameters that provider requires.
 *   pkce       Whether it requires PKCE.
 *   token_auth How the client proves itself on the token exchange: `post`
 *              (the ID and the secret in the body, the default) or `basic`
 *              (the two in an HTTP Basic header, and not in the body).
 *   map        The function that reads its answer.
 *   console    Where the application is created.
 *   guide      The provider documentation.
 *
 * @return array<string, array<string, mixed>>
 */
function diluxone_users_sso_providers(): array {
	$providers = array(
		'google'    => array(
			'name'      => 'Google',
			'color'     => '#EA4335',
			'authorize' => 'https://accounts.google.com/o/oauth2/v2/auth',
			'token'     => 'https://oauth2.googleapis.com/token',
			'profile'   => 'https://openidconnect.googleapis.com/v1/userinfo',
			'scope'     => 'openid email profile',
			'extra'     => array( 'prompt' => 'select_account' ),
			'pkce'      => false,
			'map'       => 'diluxone_users_sso_map_oidc',
			'console'   => 'https://console.cloud.google.com/apis/credentials',
			'guide'     => 'https://developers.google.com/identity/openid-connect/openid-connect',
		),
		'microsoft' => array(
			'name'      => 'Microsoft',
			'color'     => '#0067B8',
			'authorize' => 'https://login.microsoftonline.com/common/oauth2/v2.0/authorize',
			'token'     => 'https://login.microsoftonline.com/common/oauth2/v2.0/token',
			'profile'   => 'https://graph.microsoft.com/oidc/userinfo',
			'scope'     => 'openid email profile',
			'extra'     => array(),
			'pkce'      => false,
			'map'       => 'diluxone_users_sso_map_oidc',
			'console'   => 'https://entra.microsoft.com/',
			'guide'     => 'https://learn.microsoft.com/entra/identity-platform/v2-protocols-oidc',
		),
	);

	/**
	 * Filters the social-login providers.
	 *
	 * A row has the fields listed above. One that leaves some out gets them
	 * filled in (see diluxone_users_sso_provider_row()), so a provider added
	 * with a missing key is a provider that does not work, never a fatal
	 * error on the sign-in page.
	 *
	 * @param array<string, array<string, mixed>> $providers
	 */
	$providers = (array) apply_filters( 'diluxone_users_sso_providers', $providers );
	$rows      = array();

	foreach ( $providers as $id => $row ) {
		if ( is_string( $id ) && '' !== $id && is_array( $row ) ) {
			$rows[ $id ] = diluxone_users_sso_provider_row( $id, $row );
		}
	}

	return $rows;
}

/**
 * One provider's row, with every field the engine reads.
 *
 * The engine reads each of them without asking: the extra parameters are
 * merged into the address, the map is called on the profile. A row from a
 * filter that left one out, or gave a list where a word goes, used to stop
 * the request there. Each field gets a value of the type it is read as; the
 * map falls back to the OpenID Connect one, which is what a provider that
 * says nothing about its profile most likely speaks.
 *
 * @param string               $id  The provider's id.
 * @param array<string, mixed> $row What was registered.
 * @return array<string, mixed>
 */
function diluxone_users_sso_provider_row( string $id, array $row ): array {
	$row = wp_parse_args(
		$row,
		array(
			'name'       => $id,
			'color'      => '',
			'authorize'  => '',
			'token'      => '',
			'profile'    => '',
			'scope'      => '',
			'extra'      => array(),
			'pkce'       => false,
			'token_auth' => 'post',
			'map'        => 'diluxone_users_sso_map_oidc',
			'console'    => '',
			'guide'      => '',
		)
	);

	foreach ( array( 'name', 'color', 'authorize', 'token', 'profile', 'scope', 'console', 'guide' ) as $word ) {
		$row[ $word ] = is_scalar( $row[ $word ] ) ? (string) $row[ $word ] : '';
	}

	// A provider is called something: its id, when what it said is no name.
	$row['name']  = '' !== $row['name'] ? $row['name'] : $id;
	$row['extra'] = is_array( $row['extra'] ) ? $row['extra'] : array();
	$row['pkce']  = (bool) $row['pkce'];
	$row['map']   = is_callable( $row['map'] ) ? $row['map'] : 'diluxone_users_sso_map_oidc';

	// Two ways to send the secret, and the body is the one OAuth 2 asks every
	// provider to accept: anything else said here is read as that.
	$row['token_auth'] = 'basic' === $row['token_auth'] ? 'basic' : 'post';

	return $row;
}
