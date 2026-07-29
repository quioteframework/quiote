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
	/** The token exactly as the authenticator extracted it from the header. */
	public ?string $lastToken = null;

	/** @param ?array<string, mixed> $claims */
	public function __construct(private readonly ?array $claims = null, private readonly ?AuthenticationException $failure = null)
	{
	}

	public function validate(string $token): array
	{
		$this->lastToken = $token;
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

	/**
	 * A curl-style client sends `Authorization: Bearer` with the trailing
	 * space trimmed when the token is empty. That must still be claimed as a
	 * Bearer attempt (and rejected by authenticate() below) rather than
	 * silently falling through as "no credential presented" -- otherwise the
	 * request never gets a 401 challenge and instead lands on the
	 * unauthenticated/system-login-forward path.
	 */
	public function testSupportsIsTrueForABareBearerHeaderWithNoTrailingSpace(): void
	{
		$authenticator = new BearerTokenAuthenticator(new StubTokenValidator([]), new ClientTypeResolver(), new CallableUserProvider(fn() => null));
		$request = (new Psr17Factory())->createServerRequest('GET', '/')->withHeader('Authorization', 'Bearer');

		$this->assertTrue($authenticator->supports($request));
	}

	/**
	 * RFC 9110 makes the auth-scheme case-insensitive, and some clients and
	 * proxies emit it lower-cased. Comparing case-sensitively meant such a
	 * request declared no supported credential at all: it fell through as
	 * unauthenticated onto whatever the session said, and on a stateless
	 * firewall surfaced as a login forward rather than the 401 the entry point
	 * exists to produce.
	 */
	public function testSupportsAcceptsTheSchemeInAnyCase(): void
	{
		$authenticator = new BearerTokenAuthenticator(new StubTokenValidator([]), new ClientTypeResolver(), new CallableUserProvider(fn() => null));
		$factory = new Psr17Factory();

		foreach(['bearer abc', 'BEARER abc', 'BeArEr abc', 'bearer'] as $header) {
			$this->assertTrue(
				$authenticator->supports($factory->createServerRequest('GET', '/')->withHeader('Authorization', $header)),
				sprintf('"%s" declares the Bearer scheme', $header),
			);
		}
	}

	public function testAuthenticateAcceptsTheSchemeInAnyCase(): void
	{
		$authenticator = new BearerTokenAuthenticator(
			new StubTokenValidator(['sub' => 'alice']),
			new ClientTypeResolver(),
			new CallableUserProvider(fn() => null, fn(TokenClaims $claims) => new InMemoryUserIdentity($claims->getSubject(), 'n/a')),
		);
		$request = (new Psr17Factory())->createServerRequest('GET', '/')->withHeader('Authorization', 'bearer abc');

		$this->assertSame('alice', $authenticator->authenticate($request)->getIdentity()->getIdentifier());
	}

	/**
	 * The separator is a run of whitespace, not exactly one space. Slicing at a
	 * fixed offset left the extra space on the front of the token, which failed
	 * signature verification with an error that said nothing about the cause.
	 */
	public function testAuthenticateToleratesExtraWhitespaceAroundTheToken(): void
	{
		$validator = new StubTokenValidator(['sub' => 'alice']);
		$authenticator = new BearerTokenAuthenticator(
			$validator,
			new ClientTypeResolver(),
			new CallableUserProvider(fn() => null, fn(TokenClaims $claims) => new InMemoryUserIdentity($claims->getSubject(), 'n/a')),
		);
		$request = (new Psr17Factory())->createServerRequest('GET', '/')->withHeader('Authorization', "Bearer \t  abc  ");

		$authenticator->authenticate($request);

		$this->assertSame('abc', $validator->lastToken, 'the token must reach the validator without surrounding whitespace');
	}

	public function testAuthenticateThrowsWhenTheTokenIsEmpty(): void
	{
		$authenticator = new BearerTokenAuthenticator(new StubTokenValidator([]), new ClientTypeResolver(), new CallableUserProvider(fn() => null));
		$request = (new Psr17Factory())->createServerRequest('GET', '/')->withHeader('Authorization', 'Bearer ');

		$this->expectException(AuthenticationException::class);
		$authenticator->authenticate($request);
	}

	public function testAuthenticateThrowsWhenTheHeaderIsABareBearerWithNoTrailingSpace(): void
	{
		$authenticator = new BearerTokenAuthenticator(new StubTokenValidator([]), new ClientTypeResolver(), new CallableUserProvider(fn() => null));
		$request = (new Psr17Factory())->createServerRequest('GET', '/')->withHeader('Authorization', 'Bearer');

		$this->expectException(AuthenticationException::class);
		$this->expectExceptionMessage('Missing bearer token.');
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
