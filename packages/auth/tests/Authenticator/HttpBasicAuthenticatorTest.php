<?php

use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\TestCase;
use Quiote\Security\Auth\AuthenticationException;
use Quiote\Security\Auth\Authenticator\HttpBasicAuthenticator;
use Quiote\Security\Auth\Hasher\DefaultPasswordHasher;
use Quiote\Security\Auth\Provider\InMemoryUserProvider;

class HttpBasicAuthenticatorTest extends TestCase
{
	private function authenticator(): HttpBasicAuthenticator
	{
		$hasher = new DefaultPasswordHasher();
		$provider = new InMemoryUserProvider([
			'alice' => ['password_hash' => $hasher->hash('secret'), 'roles' => ['user']],
		]);
		return new HttpBasicAuthenticator($provider, $hasher);
	}

	public function testSupportsIsTrueOnlyForABasicAuthorizationHeader(): void
	{
		$authenticator = $this->authenticator();
		$factory = new Psr17Factory();

		$withBasic = $factory->createServerRequest('GET', '/')->withHeader('Authorization', 'Basic ' . base64_encode('alice:secret'));
		$withBearer = $factory->createServerRequest('GET', '/')->withHeader('Authorization', 'Bearer token');
		$withNone = $factory->createServerRequest('GET', '/');

		$this->assertTrue($authenticator->supports($withBasic));
		$this->assertFalse($authenticator->supports($withBearer));
		$this->assertFalse($authenticator->supports($withNone));
	}

	public function testAuthenticateSucceedsWithValidCredentials(): void
	{
		$authenticator = $this->authenticator();
		$request = (new Psr17Factory())->createServerRequest('GET', '/')
			->withHeader('Authorization', 'Basic ' . base64_encode('alice:secret'));

		$passport = $authenticator->authenticate($request);

		$this->assertSame('alice', $passport->getIdentity()->getIdentifier());
		$this->assertSame(['user'], $passport->getCredentials());
		$this->assertTrue($passport->isStateless());
	}

	/**
	 * RFC 9110 makes the auth-scheme case-insensitive. A case-sensitive
	 * comparison meant `basic <creds>` declared no supported credential and the
	 * request fell through as unauthenticated instead of being challenged.
	 */
	public function testSupportsAndAuthenticateAcceptTheSchemeInAnyCase(): void
	{
		$authenticator = $this->authenticator();

		foreach(['basic', 'BASIC', 'BaSiC'] as $scheme) {
			$request = (new Psr17Factory())->createServerRequest('GET', '/')
				->withHeader('Authorization', $scheme . ' ' . base64_encode('alice:secret'));

			$this->assertTrue($authenticator->supports($request), sprintf('"%s" declares the Basic scheme', $scheme));
			$this->assertSame('alice', $authenticator->authenticate($request)->getIdentity()->getIdentifier());
		}
	}

	public function testAuthenticateToleratesExtraWhitespaceAfterTheScheme(): void
	{
		// The separator is a run of whitespace; slicing at a fixed offset left a
		// space on the front of the base64, which then failed to decode.
		$authenticator = $this->authenticator();
		$request = (new Psr17Factory())->createServerRequest('GET', '/')
			->withHeader('Authorization', "Basic \t " . base64_encode('alice:secret'));

		$this->assertSame('alice', $authenticator->authenticate($request)->getIdentity()->getIdentifier());
	}

	public function testAuthenticateThrowsOnABareBasicScheme(): void
	{
		$authenticator = $this->authenticator();
		$request = (new Psr17Factory())->createServerRequest('GET', '/')->withHeader('Authorization', 'Basic');

		// Claimed by supports(), so it must be rejected here and challenged rather
		// than falling through as "no credential presented".
		$this->assertTrue($authenticator->supports($request));
		$this->expectException(AuthenticationException::class);
		$authenticator->authenticate($request);
	}

	public function testAuthenticateThrowsOnMalformedBase64(): void
	{
		$authenticator = $this->authenticator();
		$request = (new Psr17Factory())->createServerRequest('GET', '/')
			->withHeader('Authorization', 'Basic not-valid-base64!!!');

		$this->expectException(AuthenticationException::class);
		$authenticator->authenticate($request);
	}

	public function testAuthenticateThrowsWhenPasswordIsWrong(): void
	{
		$authenticator = $this->authenticator();
		$request = (new Psr17Factory())->createServerRequest('GET', '/')
			->withHeader('Authorization', 'Basic ' . base64_encode('alice:wrong'));

		$this->expectException(AuthenticationException::class);
		$authenticator->authenticate($request);
	}

	public function testAuthenticateThrowsWhenUserIsUnknown(): void
	{
		$authenticator = $this->authenticator();
		$request = (new Psr17Factory())->createServerRequest('GET', '/')
			->withHeader('Authorization', 'Basic ' . base64_encode('nobody:secret'));

		$this->expectException(AuthenticationException::class);
		$authenticator->authenticate($request);
	}

	public function testOnFailureDefersToTheEntryPoint(): void
	{
		$authenticator = $this->authenticator();

		$this->assertNull($authenticator->onFailure(new AuthenticationException('invalid')));
	}
}
