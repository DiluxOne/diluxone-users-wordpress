<?php
/**
 * A social network that answers from inside the process.
 *
 * The OAuth round trip is three HTTP requests: the person is sent to the
 * provider, the code is exchanged for a token, the profile is read with it.
 * The first is a redirect the test catches; the other two are answered here
 * through `pre_http_request`, with whatever profile the test decides — so a
 * test can say "the provider returned this e-mail, unverified" and see what
 * the plugin does with it, and nothing leaves the machine.
 */

namespace Tests\Integration\Support;

final class MockProvider {

	public const ID   = 'mock';
	public const BASE = 'https://mock.test/';

	/** @var array<string, mixed> What the profile endpoint answers. */
	public static array $profile = array();

	/** @var array<int, array{url: string, args: array<string, mixed>}> Every request the plugin made. */
	public static array $requests = array();

	/** Registers the provider, ready to use, with its answers wired in. */
	public static function install(): void {
		self::$profile  = array();
		self::$requests = array();

		add_filter( 'diluxone_users_sso_providers', array( self::class, 'providers' ) );
		add_filter( 'pre_http_request', array( self::class, 'http' ), 10, 3 );

		// Configured, tested and turned on: the three states a provider has to
		// go through before the plugin lets anybody use it.
		diluxone_users_update_option(
			'diluxone_users_sso',
			array(
				self::ID => array(
					'active' => 1,
					'id'     => 'client-id',
					'secret' => 'client-secret',
					'tested' => 1,
				),
			)
		);
	}

	public static function remove(): void {
		remove_filter( 'diluxone_users_sso_providers', array( self::class, 'providers' ) );
		remove_filter( 'pre_http_request', array( self::class, 'http' ), 10 );
	}

	/**
	 * @param array<string, array<string, mixed>> $providers
	 * @return array<string, array<string, mixed>>
	 */
	public static function providers( array $providers ): array {
		$providers[ self::ID ] = array(
			'name'      => 'Mock',
			'color'     => '#000000',
			'authorize' => self::BASE . 'authorize',
			'token'     => self::BASE . 'token',
			'profile'   => self::BASE . 'profile',
			'scope'     => 'openid email profile',
			'extra'     => array(),
			'pkce'      => false,
			'map'       => 'diluxone_users_sso_map_oidc',
			'console'   => self::BASE,
			'guide'     => self::BASE,
		);

		return $providers;
	}

	/**
	 * @param mixed                $pre
	 * @param array<string, mixed> $args
	 * @return mixed
	 */
	public static function http( $pre, array $args, string $url ) {
		if ( 0 !== strpos( $url, self::BASE ) ) {
			return $pre;
		}

		self::$requests[] = array(
			'url'  => $url,
			'args' => $args,
		);

		$body = false !== strpos( $url, '/token' ) ? array( 'access_token' => 'mock-token' ) : self::$profile;

		return array(
			'headers'  => array(),
			'body'     => (string) wp_json_encode( $body ),
			'response' => array(
				'code'    => 200,
				'message' => 'OK',
			),
			'cookies'  => array(),
			'filename' => null,
		);
	}
}
