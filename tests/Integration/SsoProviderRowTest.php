<?php
/**
 * A provider added through the filter, with fields left out or wrong.
 *
 * The engine reads every field of a row without asking — it merges `extra`
 * into the address, it calls `map` on the profile — so a row that left one
 * out used to stop the request with a fatal error, and the example in
 * docs/extending.md was such a row. Each field now gets a value of the type
 * it is read as.
 *
 * Providers are code, the same on both topologies.
 */

namespace Tests\Integration;

class SsoProviderRowTest extends IntegrationTestCase {

	protected function setUp(): void {
		parent::setUp();

		$this->hook(
			'diluxone_users_sso_providers',
			static function ( array $providers ): array {
				// The example of docs/extending.md, as written there.
				$providers['acme'] = array(
					'name'      => 'Acme ID',
					'color'     => '#0B5FFF',
					'authorize' => 'https://id.acme.example/oauth2/authorize',
					'token'     => 'https://id.acme.example/oauth2/token',
					'profile'   => 'https://id.acme.example/oauth2/userinfo',
					'scope'     => 'openid email profile',
				);
				// And a row with every field wrong.
				$providers['broken'] = array(
					'extra'      => 'not a list',
					'map'        => 'no_such_function',
					'name'       => array( 'a list' ),
					'token_auth' => array( 'basic' ),
				);
				// And one that asks for the secret in a Basic header.
				$providers['basic'] = array( 'token_auth' => 'basic' );
				$providers[7]        = array( 'name' => 'A number for an id' );
				$providers['notrow'] = 'not a row';

				return $providers;
			}
		);
	}

	public function test_every_row_has_every_field_the_engine_reads(): void {
		$providers = diluxone_users_sso_providers();

		$this->assertArrayNotHasKey( 7, $providers, 'an id is a word' );
		$this->assertArrayNotHasKey( 'notrow', $providers, 'a row is a row' );

		foreach ( array( 'acme', 'broken', 'google' ) as $id ) {
			foreach ( array( 'name', 'color', 'authorize', 'token', 'profile', 'scope', 'extra', 'pkce', 'token_auth', 'map', 'console', 'guide' ) as $field ) {
				$this->assertArrayHasKey( $field, $providers[ $id ], "{$id} has {$field}" );
			}

			$this->assertIsArray( $providers[ $id ]['extra'] );
			$this->assertIsCallable( $providers[ $id ]['map'] );
			$this->assertIsString( $providers[ $id ]['name'] );
		}

		$this->assertSame( 'diluxone_users_sso_map_oidc', $providers['acme']['map'], 'a profile nobody described is read as OpenID Connect' );
		$this->assertSame( 'broken', $providers['broken']['name'], 'the id, when the name is not a word' );
		$this->assertSame( 'https://id.acme.example/oauth2/userinfo', $providers['acme']['profile'] );
		$this->assertSame( 'post', $providers['acme']['token_auth'], 'the secret goes in the body unless the row says otherwise' );
		$this->assertSame( 'post', $providers['broken']['token_auth'], 'and when what it says is not a word' );
		$this->assertSame( 'basic', $providers['basic']['token_auth'] );
		$this->assertSame( 'post', $providers['google']['token_auth'] );
		$this->assertSame( 'diluxone_users_sso_map_oidc', $providers['google']['map'], 'the plugin’s own rows are as they were' );
		$this->assertSame( array( 'prompt' => 'select_account' ), $providers['google']['extra'] );
	}

	public function test_the_example_provider_sends_somebody_to_sign_in(): void {
		diluxone_users_sso_save_credentials(
			'acme',
			array(
				'id'     => 'client',
				'secret' => 'secret',
			)
		);

		$url = $this->expectRedirect( static fn() => diluxone_users_sso_authorize( 'acme', diluxone_users_sso_providers()['acme'] ) );

		$this->assertStringStartsWith( 'https://id.acme.example/oauth2/authorize?', $url );
		$this->assertStringContainsString( 'client_id=client', $url );
	}
}
