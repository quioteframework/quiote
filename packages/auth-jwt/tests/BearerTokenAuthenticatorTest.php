<?php

use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Quiote\Security\Auth\AuthenticationException;
use Quiote\Security\Auth\BearerTokenAuthenticator;
use Quiote\Security\Auth\ClientType;
use Quiote\Security\Auth\ClientTypeResolver;
use Quiote\Security\Auth\Identity\InMemoryUserIdentity;
use Quiote\Security\Auth\Provider\CallableUserProvider;
use Quiote\Security\Auth\TokenClaims;
use Quiote\Security\Auth\TokenValidatorInterface;
use Quiote\Security\Auth\UserIdentity;

class StubTokenValidator implements TokenValidatorInterface
{
	/** @param ?array<string, mixed> $claims */
	public function __construct(private readonly ?array $claims = null, private readonly ?AuthenticationException $failure = null)
	{
	}

	public function validate(string $token): array
	{
		if($this->failure !== null) {
			throw $this->failure;
		}
		return $this->claims ?? [];
	}
}

class BearerTokenAuthenticatorTest extends TestCase
{
	public function testSupportsIsTrueOnlyForABearerAuthorizationHeader(): void
	{
		$authenticator = new BearerTokenAuthenticator(new StubTokenValidator([]), new ClientTypeResolver(), new CallableUserProvider(fn() => null));
		$factory = new Psr17Factory();

		$this->assertTrue($authenticator->supports($factory->createServerRequest('GET', '/')->withHeader('Authorization', 'Bearer abc')));
		$this->assertFalse($authenticator->supports($factory->createServerRequest('GET', '/')->withHeader('Authorization', 'Basic abc')));
		$this->assertFalse($authenticator->supports($factory->createServerRequest('GET', '/')));
	}

	public function testAuthenticateThrowsWhenTheTokenIsEmpty(): void
	{
		$authenticator = new BearerTokenAuthenticator(new StubTokenValidator([]), new ClientTypeResolver(), new CallableUserProvider(fn() => null));
		$request = (new Psr17Factory())->createServerRequest('GET', '/')->withHeader('Authorization', 'Bearer ');

		$this->expectException(AuthenticationException::class);
		$authenticator->authenticate($request);
	}

	public function testAuthenticatePropagatesValidatorFailures(): void
	{
		$authenticator = new BearerTokenAuthenticator(
			new StubTokenValidator(failure: new AuthenticationException('bad signature')),
			new ClientTypeResolver(),
			new CallableUserProvider(fn() => null),
		);
		$request = (new Psr17Factory())->createServerRequest('GET', '/')->withHeader('Authorization', 'Bearer abc');

		$this->expectException(AuthenticationException::class);
		$this->expectExceptionMessage('bad signature');
		$authenticator->authenticate($request);
	}

	public function testAuthenticateThrowsWhenTheClaimsDoNotResolveToAnIdentity(): void
	{
		$authenticator = new BearerTokenAuthenticator(
			new StubTokenValidator(['sub' => 'unknown-user']),
			new ClientTypeResolver(),
			new CallableUserProvider(fn() => null, fn(TokenClaims $claims) => null),
		);
		$request = (new Psr17Factory())->createServerRequest('GET', '/')->withHeader('Authorization', 'Bearer abc');

		$this->expectException(AuthenticationException::class);
		$authenticator->authenticate($request);
	}

	public function testAuthenticateSucceedsAndBuildsAStatelessPassportForAUserToken(): void
	{
		$provider = new CallableUserProvider(
			fn() => null,
			fn(TokenClaims $claims) => new InMemoryUserIdentity($claims->getSubject(), 'n/a', ['user']),
		);
		$authenticator = new BearerTokenAuthenticator(
			new StubTokenValidator(['sub' => 'alice']),
			new ClientTypeResolver(),
			$provider,
		);
		$request = (new Psr17Factory())->createServerRequest('GET', '/')->withHeader('Authorization', 'Bearer abc');

		$passport = $authenticator->authenticate($request);

		$this->assertSame('alice', $passport->getIdentity()->getIdentifier());
		$this->assertSame(['user'], $passport->getCredentials());
		$this->assertTrue($passport->isStateless());
		$this->assertNotNull($passport->getClaims());
		$this->assertSame(ClientType::User, $passport->getClaims()->getClientType());
	}

	public function testAuthenticateResolvesAServiceClientType(): void
	{
		$provider = new CallableUserProvider(
			fn() => null,
			fn(TokenClaims $claims) => new InMemoryUserIdentity($claims->getSubject(), 'n/a'),
		);
		$authenticator = new BearerTokenAuthenticator(
			new StubTokenValidator(['sub' => 'service-1', 'client_id' => 'service-1']),
			new ClientTypeResolver(),
			$provider,
		);
		$request = (new Psr17Factory())->createServerRequest('GET', '/')->withHeader('Authorization', 'Bearer abc');

		$passport = $authenticator->authenticate($request);

		$this->assertNotNull($passport->getClaims());
		$this->assertSame(ClientType::Service, $passport->getClaims()->getClientType());
	}

	public function testOnFailureDefersToTheEntryPoint(): void
	{
		$authenticator = new BearerTokenAuthenticator(new StubTokenValidator([]), new ClientTypeResolver(), new CallableUserProvider(fn() => null));

		$this->assertNull($authenticator->onFailure(new AuthenticationException('invalid')));
	}
}
