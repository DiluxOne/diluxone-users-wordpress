<?php
/**
 * The social-login provider table.
 *
 * OAuth 2 is the same dance everywhere: send them off to authorise, come back
 * with a code, exchange it for a token, ask for the profile. The only thing
 * each provider has of its own is four URLs, a scope and how the profile it
 * returns is read. That is a table, not a class per provider: adding a new one
 * is adding a row here.
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
 * Every provider the plugin knows how to handle.
 *
 * Fields of each one:
 *   name       What it is called for people.
 *   color      Its brand colour, for the card and the button.
 *   authorize  URL the person is sent to.
 *   token      URL where the code is exchanged for a token.
 *   profile    URL the profile is read from.
 *   scope      The permissions asked for.
 *   extra      Odd parameters that provider requires.
 *   pkce       Whether it requires PKCE (X does; the rest do not mind).
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
		'linkedin'  => array(
			'name'      => 'LinkedIn',
			'color'     => '#0A66C2',
			'authorize' => 'https://www.linkedin.com/oauth/v2/authorization',
			'token'     => 'https://www.linkedin.com/oauth/v2/accessToken',
			'profile'   => 'https://api.linkedin.com/v2/userinfo',
			'scope'     => 'openid profile email',
			'extra'     => array(),
			'pkce'      => false,
			'map'       => 'diluxone_users_sso_map_oidc',
			'console'   => 'https://www.linkedin.com/developers/apps',
			'guide'     => 'https://learn.microsoft.com/linkedin/consumer/integrations/self-serve/sign-in-with-linkedin-v2',
		),
		'twitter'   => array(
			'name'      => 'X (Twitter)',
			'color'     => '#0F1419',
			'authorize' => 'https://twitter.com/i/oauth2/authorize',
			'token'     => 'https://api.twitter.com/2/oauth2/token',
			'profile'   => 'https://api.twitter.com/2/users/me?user.fields=name,username',
			'scope'     => 'tweet.read users.read',
			'extra'     => array(),
			// X requires PKCE and returns no e-mail: the account is created with
			// an e-mail derived from the username, or linked from the profile.
			'pkce'      => true,
			'map'       => 'diluxone_users_sso_map_twitter',
			'console'   => 'https://developer.twitter.com/en/portal/dashboard',
			'guide'     => 'https://docs.x.com/resources/fundamentals/authentication/oauth-2-0/authorization-code',
		),
		'facebook'  => array(
			'name'      => 'Facebook',
			'color'     => '#1877F2',
			'authorize' => 'https://www.facebook.com/v19.0/dialog/oauth',
			'token'     => 'https://graph.facebook.com/v19.0/oauth/access_token',
			'profile'   => 'https://graph.facebook.com/me?fields=id,email,first_name,last_name',
			'scope'     => 'email',
			'extra'     => array(),
			'pkce'      => false,
			'map'       => 'diluxone_users_sso_map_facebook',
			'console'   => 'https://developers.facebook.com/apps/',
			'guide'     => 'https://developers.facebook.com/docs/facebook-login/guides/advanced/manual-flow',
		),
		'github'    => array(
			'name'      => 'GitHub',
			'color'     => '#24292F',
			'authorize' => 'https://github.com/login/oauth/authorize',
			'token'     => 'https://github.com/login/oauth/access_token',
			'profile'   => 'https://api.github.com/user',
			'scope'     => 'read:user user:email',
			'extra'     => array(),
			'pkce'      => false,
			'map'       => 'diluxone_users_sso_map_github',
			'console'   => 'https://github.com/settings/developers',
			'guide'     => 'https://docs.github.com/apps/oauth-apps/building-oauth-apps/authorizing-oauth-apps',
		),
		'wordpress' => array(
			'name'      => 'WordPress.com',
			'color'     => '#117AC9',
			'authorize' => 'https://public-api.wordpress.com/oauth2/authorize',
			'token'     => 'https://public-api.wordpress.com/oauth2/token',
			'profile'   => 'https://public-api.wordpress.com/rest/v1/me',
			'scope'     => 'auth',
			'extra'     => array(),
			'pkce'      => false,
			'map'       => 'diluxone_users_sso_map_wordpress',
			'console'   => 'https://developer.wordpress.com/apps/',
			'guide'     => 'https://developer.wordpress.com/docs/oauth2/',
		),
		'yahoo'     => array(
			'name'      => 'Yahoo',
			'color'     => '#6001D2',
			'authorize' => 'https://api.login.yahoo.com/oauth2/request_auth',
			'token'     => 'https://api.login.yahoo.com/oauth2/get_token',
			'profile'   => 'https://api.login.yahoo.com/openid/v1/userinfo',
			'scope'     => 'openid email profile',
			'extra'     => array(),
			'pkce'      => false,
			'map'       => 'diluxone_users_sso_map_oidc',
			'console'   => 'https://developer.yahoo.com/apps/',
			'guide'     => 'https://developer.yahoo.com/oauth2/guide/openid_connect/',
		),
		'twitch'    => array(
			'name'      => 'Twitch',
			'color'     => '#9146FF',
			'authorize' => 'https://id.twitch.tv/oauth2/authorize',
			'token'     => 'https://id.twitch.tv/oauth2/token',
			// The OIDC endpoint and not /helix/users: this one works with the
			// Bearer alone, the other also wants the Client-Id header.
			'profile'   => 'https://id.twitch.tv/oauth2/userinfo',
			'scope'     => 'openid user:read:email',
			'extra'     => array( 'claims' => '{"userinfo":{"email":null,"preferred_username":null}}' ),
			'pkce'      => false,
			'map'       => 'diluxone_users_sso_map_oidc',
			'console'   => 'https://dev.twitch.tv/console/apps',
			'guide'     => 'https://dev.twitch.tv/docs/authentication/getting-tokens-oidc/',
		),
		'discord'   => array(
			'name'      => 'Discord',
			'color'     => '#5865F2',
			'authorize' => 'https://discord.com/oauth2/authorize',
			'token'     => 'https://discord.com/api/oauth2/token',
			'profile'   => 'https://discord.com/api/users/@me',
			'scope'     => 'identify email',
			'extra'     => array(),
			'pkce'      => false,
			'map'       => 'diluxone_users_sso_map_discord',
			'console'   => 'https://discord.com/developers/applications',
			'guide'     => 'https://discord.com/developers/docs/topics/oauth2',
		),
		'gitlab'    => array(
			'name'      => 'GitLab',
			'color'     => '#FC6D26',
			'authorize' => 'https://gitlab.com/oauth/authorize',
			'token'     => 'https://gitlab.com/oauth/token',
			'profile'   => 'https://gitlab.com/oauth/userinfo',
			'scope'     => 'openid email profile',
			'extra'     => array(),
			'pkce'      => false,
			'map'       => 'diluxone_users_sso_map_oidc',
			'console'   => 'https://gitlab.com/-/profile/applications',
			'guide'     => 'https://docs.gitlab.com/ee/integration/openid_connect_provider.html',
		),
		'amazon'    => array(
			'name'      => 'Amazon',
			'color'     => '#FF9900',
			'authorize' => 'https://www.amazon.com/ap/oa',
			'token'     => 'https://api.amazon.com/auth/o2/token',
			'profile'   => 'https://api.amazon.com/user/profile',
			'scope'     => 'profile',
			'extra'     => array(),
			'pkce'      => false,
			'map'       => 'diluxone_users_sso_map_amazon',
			'console'   => 'https://developer.amazon.com/loginwithamazon/console/site/lwa/overview.html',
			'guide'     => 'https://developer.amazon.com/docs/login-with-amazon/web-docs.html',
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
			'name'      => $id,
			'color'     => '',
			'authorize' => '',
			'token'     => '',
			'profile'   => '',
			'scope'     => '',
			'extra'     => array(),
			'pkce'      => false,
			'map'       => 'diluxone_users_sso_map_oidc',
			'console'   => '',
			'guide'     => '',
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

	return $row;
}
