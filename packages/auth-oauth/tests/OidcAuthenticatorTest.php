<?php

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response as GuzzleResponse;
use Nyholm\Psr7\Factory\Psr17Factory;
use Quiote\Security\Auth\AuthenticationException;
use Quiote\Security\Auth\Identity\InMemoryUserIdentity;
use Quiote\Security\Auth\OidcAuthenticator;
use Quiote\Security\Auth\OidcClient;
use Quiote\Security\Auth\OidcStateStorage;
use Quiote\Security\Auth\Provider\CallableUserProvider;
use Quiote\Security\Auth\TokenClaims;
use Quiote\Security\Auth\TokenValidatorInterface;
use Quiote\Testing\UnitTestCase;

class OidcStubTokenValidator implements TokenValidatorInterface
{
	/** @param array<string, mixed> $claims */
	public function __construct(private readonly array $claims)
	{
	}

	public function validate(string $token): array
	{
		return $this->claims;
	}
}

class OidcAuthenticatorTest extends UnitTestCase
{
	#[\Override]
    protected function setUp(): void
	{
		parent::setUp();
		$ctx = $this->getContext();
		$ro = new ReflectionObject($ctx);
		$prop = $ro->getProperty('storage');
		$prop->setValue($ctx, new class {
			/** @var array<string, mixed> */
			private array $data = [];
			public function store(string $id, mixed $data): bool { $this->data[$id] = $data; return true; }
			public function retrieve(string $key): mixed { return $this->data[$key] ?? null; }
			public function remove(string $key): void { unset($this->data[$key]); }
		});
	}

	private function oidcClientReturningIdToken(string $idToken): OidcClient
	{
		$mock = new MockHandler([
			new GuzzleResponse(200, ['Content-Type' => 'application/json'], (string) json_encode([
				'access_token' => 'access-token-value',
				'token_type' => 'Bearer',
				'expires_in' => 3600,
				'id_token' => $idToken,
			])),
		]);
		return new OidcClient(
			'client-id',
			'client-secret',
			'https://app.example.com/callback',
			'https://idp.example.com/authorize',
			'https://idp.example.com/token',
			httpClient: new Client(['handler' => HandlerStack::create($mock)]),
		);
	}

	private function request(string $code, string $state): \Psr\Http\Message\ServerRequestInterface
	{
		return (new Psr17Factory())->createServerRequest('GET', 'https://app.example.com/callback?code=' . $code . '&state=' . $state)
			->withQueryParams(['code' => $code, 'state' => $state]);
	}

	public function testSupportsOnlyMatchesTheCallbackPathWithCodeAndState(): void
	{
		$authenticator = new OidcAuthenticator(
			$this->oidcClientReturningIdToken('irrelevant'),
			new OidcStubTokenValidator([]),
			new CallableUserProvider(fn() => null),
			new OidcStateStorage($this->getContext()),
			'/callback',
		);
		$factory = new Psr17Factory();

		$this->assertTrue($authenticator->supports($this->request('abc', 'xyz')));
		$this->assertFalse($authenticator->supports($factory->createServerRequest('GET', '/callback')));
		$this->assertFalse($authenticator->supports($factory->createServerRequest('GET', '/other')->withQueryParams(['code' => 'a', 'state' => 'b'])));
	}

	public function testAuthenticateSucceedsForAValidCallback(): void
	{
		$stateStorage = new OidcStateStorage($this->getContext());
		$client = $this->oidcClientReturningIdToken('id-token-jwt');
		$authorizationRequest = $client->buildAuthorizationRequest();
		$stateStorage->store($authorizationRequest->getState());

		$authenticator = new OidcAuthenticator(
			$client,
			new OidcStubTokenValidator(['sub' => 'alice', 'nonce' => $authorizationRequest->getState()->getNonce()]),
			new CallableUserProvider(fn() => null, fn(TokenClaims $claims) => new InMemoryUserIdentity($claims->getSubject(), 'n/a', ['user'])),
			$stateStorage,
			'/callback',
		);

		$passport = $authenticator->authenticate($this->request('auth-code', $authorizationRequest->getState()->getState()));

		$this->assertSame('alice', $passport->getIdentity()->getIdentifier());
		$this->assertSame(['user'], $passport->getCredentials());
		$this->assertFalse($passport->isStateless());
	}

	public function testAuthenticateThrowsWhenStateWasNeverStored(): void
	{
		$authenticator = new OidcAuthenticator(
			$this->oidcClientReturningIdToken('irrelevant'),
			new OidcStubTokenValidator([]),
			new CallableUserProvider(fn() => null),
			new OidcStateStorage($this->getContext()),
			'/callback',
		);

		$this->expectException(AuthenticationException::class);
		$this->expectExceptionMessage('Invalid or expired');
		$authenticator->authenticate($this->request('auth-code', 'forged-state'));
	}

	public function testAuthenticateThrowsWhenTheNonceDoesNotMatch(): void
	{
		$stateStorage = new OidcStateStorage($this->getContext());
		$client = $this->oidcClientReturningIdToken('id-token-jwt');
		$authorizationRequest = $client->buildAuthorizationRequest();
		$stateStorage->store($authorizationRequest->getState());

		$authenticator = new OidcAuthenticator(
			$client,
			new OidcStubTokenValidator(['sub' => 'alice', 'nonce' => 'a-different-nonce']),
			new CallableUserProvider(fn() => null),
			$stateStorage,
			'/callback',
		);

		$this->expectException(AuthenticationException::class);
		$this->expectExceptionMessage('nonce');
		$authenticator->authenticate($this->request('auth-code', $authorizationRequest->getState()->getState()));
	}

	public function testAuthenticateThrowsWhenClaimsDoNotResolveToAnIdentity(): void
	{
		$stateStorage = new OidcStateStorage($this->getContext());
		$client = $this->oidcClientReturningIdToken('id-token-jwt');
		$authorizationRequest = $client->buildAuthorizationRequest();
		$stateStorage->store($authorizationRequest->getState());

		$authenticator = new OidcAuthenticator(
			$client,
			new OidcStubTokenValidator(['sub' => 'alice', 'nonce' => $authorizationRequest->getState()->getNonce()]),
			new CallableUserProvider(fn() => null, fn(TokenClaims $claims) => null),
			$stateStorage,
			'/callback',
		);

		$this->expectException(AuthenticationException::class);
		$authenticator->authenticate($this->request('auth-code', $authorizationRequest->getState()->getState()));
	}

	public function testAuthenticateThrowsWhenCodeOrStateAreMissing(): void
	{
		$authenticator = new OidcAuthenticator(
			$this->oidcClientReturningIdToken('irrelevant'),
			new OidcStubTokenValidator([]),
			new CallableUserProvider(fn() => null),
			new OidcStateStorage($this->getContext()),
			'/callback',
		);

		$this->expectException(AuthenticationException::class);
		$authenticator->authenticate((new Psr17Factory())->createServerRequest('GET', '/callback'));
	}

	public function testOnFailureDefersToTheEntryPoint(): void
	{
		$authenticator = new OidcAuthenticator(
			$this->oidcClientReturningIdToken('irrelevant'),
			new OidcStubTokenValidator([]),
			new CallableUserProvider(fn() => null),
			new OidcStateStorage($this->getContext()),
			'/callback',
		);

		$this->assertNull($authenticator->onFailure(new AuthenticationException('invalid')));
	}
}
