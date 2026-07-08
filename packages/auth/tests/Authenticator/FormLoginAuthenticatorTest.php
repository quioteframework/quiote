<?php

use Nyholm\Psr7\Factory\Psr17Factory;
use Quiote\Config\Config;
use Quiote\Security\Auth\AuthenticationException;
use Quiote\Security\Auth\Authenticator\FormLoginAuthenticator;
use Quiote\Security\Auth\Hasher\DefaultPasswordHasher;
use Quiote\Security\Auth\Provider\InMemoryUserProvider;
use Quiote\Security\Csrf\CsrfManager;
use Quiote\Security\RateLimit\LoginThrottle;
use Quiote\Testing\UnitTestCase;
use Symfony\Component\RateLimiter\Storage\InMemoryStorage;

class FormLoginAuthenticatorTest extends UnitTestCase
{
	private DefaultPasswordHasher $hasher;

	private InMemoryUserProvider $provider;

	#[\Override]
    protected function setUp(): void
	{
		parent::setUp();
		$this->hasher = new DefaultPasswordHasher();
		$this->provider = new InMemoryUserProvider([
			'alice' => ['password_hash' => $this->hasher->hash('secret'), 'roles' => ['user']],
		]);
	}

	private function authenticator(): FormLoginAuthenticator
	{
		return new FormLoginAuthenticator($this->provider, $this->hasher);
	}

	public function testSupportsOnlyMatchesAPostToTheCheckPath(): void
	{
		$authenticator = $this->authenticator();
		$factory = new Psr17Factory();

		$this->assertTrue($authenticator->supports($factory->createServerRequest('POST', '/login')));
		$this->assertFalse($authenticator->supports($factory->createServerRequest('GET', '/login')));
		$this->assertFalse($authenticator->supports($factory->createServerRequest('POST', '/other')));
	}

	public function testAuthenticateSucceedsWithValidCredentials(): void
	{
		$request = (new Psr17Factory())->createServerRequest('POST', '/login')
			->withParsedBody(['username' => 'alice', 'password' => 'secret']);

		$passport = $this->authenticator()->authenticate($request);

		$this->assertSame('alice', $passport->getIdentity()->getIdentifier());
		$this->assertSame(['user'], $passport->getCredentials());
		$this->assertFalse($passport->isStateless());
	}

	public function testAuthenticateThrowsWhenFormDataIsMissing(): void
	{
		$request = (new Psr17Factory())->createServerRequest('POST', '/login');

		$this->expectException(AuthenticationException::class);
		$this->authenticator()->authenticate($request);
	}

	public function testAuthenticateThrowsWhenPasswordIsBlank(): void
	{
		$request = (new Psr17Factory())->createServerRequest('POST', '/login')
			->withParsedBody(['username' => 'alice', 'password' => '']);

		$this->expectException(AuthenticationException::class);
		$this->authenticator()->authenticate($request);
	}

	public function testAuthenticateThrowsOnWrongPassword(): void
	{
		$request = (new Psr17Factory())->createServerRequest('POST', '/login')
			->withParsedBody(['username' => 'alice', 'password' => 'wrong']);

		$this->expectException(AuthenticationException::class);
		$this->authenticator()->authenticate($request);
	}

	public function testAuthenticateThrowsForUnknownUser(): void
	{
		$request = (new Psr17Factory())->createServerRequest('POST', '/login')
			->withParsedBody(['username' => 'nobody', 'password' => 'secret']);

		$this->expectException(AuthenticationException::class);
		$this->authenticator()->authenticate($request);
	}

	public function testCsrfIntegrationRejectsAMissingToken(): void
	{
		$originalEnabled = Config::getBool('core.csrf.enabled');
		Config::set('core.csrf.enabled', true);
		$this->injectInMemoryStorage();

		try {
			$csrf = new CsrfManager($this->getContext());
			$authenticator = new FormLoginAuthenticator($this->provider, $this->hasher, csrf: $csrf);

			$request = (new Psr17Factory())->createServerRequest('POST', '/login')
				->withParsedBody(['username' => 'alice', 'password' => 'secret']);

			$this->expectException(AuthenticationException::class);
			$authenticator->authenticate($request);
		} finally {
			Config::set('core.csrf.enabled', $originalEnabled);
		}
	}

	public function testCsrfIntegrationAcceptsAValidToken(): void
	{
		$originalEnabled = Config::getBool('core.csrf.enabled');
		Config::set('core.csrf.enabled', true);
		$this->injectInMemoryStorage();

		try {
			$csrf = new CsrfManager($this->getContext());
			$token = $csrf->getTokenValue();
			$authenticator = new FormLoginAuthenticator($this->provider, $this->hasher, csrf: $csrf);

			$request = (new Psr17Factory())->createServerRequest('POST', '/login')
				->withParsedBody(['username' => 'alice', 'password' => 'secret', $csrf->fieldName() => $token]);

			$passport = $authenticator->authenticate($request);

			$this->assertSame('alice', $passport->getIdentity()->getIdentifier());
		} finally {
			Config::set('core.csrf.enabled', $originalEnabled);
		}
	}

	public function testThrottleRejectsAfterTooManyFailures(): void
	{
		$throttle = new LoginThrottle(new InMemoryStorage(), maxAttempts: 1, interval: '1 hour');
		$authenticator = new FormLoginAuthenticator($this->provider, $this->hasher, throttle: $throttle);

		$badRequest = (new Psr17Factory())->createServerRequest('POST', '/login')
			->withParsedBody(['username' => 'alice', 'password' => 'wrong']);

		try {
			$authenticator->authenticate($badRequest);
			$this->fail('Expected an AuthenticationException for the wrong password.');
		} catch(AuthenticationException) {
			// expected: this is the failure that exhausts the 1-attempt budget
		}

		$goodRequest = (new Psr17Factory())->createServerRequest('POST', '/login')
			->withParsedBody(['username' => 'alice', 'password' => 'secret']);

		$this->expectException(AuthenticationException::class);
		$authenticator->authenticate($goodRequest);
	}

	public function testThrottleResetsOnSuccessfulLogin(): void
	{
		$throttle = new LoginThrottle(new InMemoryStorage(), maxAttempts: 1, interval: '1 hour');
		$authenticator = new FormLoginAuthenticator($this->provider, $this->hasher, throttle: $throttle);

		$goodRequest = (new Psr17Factory())->createServerRequest('POST', '/login')
			->withParsedBody(['username' => 'alice', 'password' => 'secret']);

		$passport = $authenticator->authenticate($goodRequest);

		$this->assertSame('alice', $passport->getIdentity()->getIdentifier());
		$this->assertNull($throttle->retryAfter('form_login:alice'));
	}

	public function testOnFailureDefersToTheEntryPoint(): void
	{
		$this->assertNull($this->authenticator()->onFailure(new AuthenticationException('invalid')));
	}

	private function injectInMemoryStorage(): void
	{
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
}
