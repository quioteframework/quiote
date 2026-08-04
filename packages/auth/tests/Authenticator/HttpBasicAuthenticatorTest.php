<?php

use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\TestCase;
use Quiote\Security\Auth\AuthenticationException;
use Quiote\Security\Auth\Authenticator\HttpBasicAuthenticator;
use Quiote\Security\Auth\Hasher\DefaultPasswordHasher;
use Quiote\Security\Auth\Hasher\DummyPasswordHash;
use Quiote\Security\Auth\PasswordHasherInterface;
use Quiote\Security\Auth\Provider\InMemoryUserProvider;
use Quiote\Security\RateLimit\LoginThrottle;
use Symfony\Component\RateLimiter\Storage\InMemoryStorage;

/**
 * Counts verify() calls, so a test can assert that the unknown-identifier path
 * still pays a key derivation rather than short-circuiting past it.
 */
final class CountingBasicHasher implements PasswordHasherInterface
{
	public int $verifyCalls = 0;

	public function __construct(private readonly DefaultPasswordHasher $inner = new DefaultPasswordHasher())
	{
	}

	public function hash(#[\SensitiveParameter] string $plaintext): string
	{
		return $this->inner->hash($plaintext);
	}

	public function verify(#[\SensitiveParameter] string $plaintext, #[\SensitiveParameter] string $hash): bool
	{
		$this->verifyCalls++;

		return $this->inner->verify($plaintext, $hash);
	}

	public function needsRehash(#[\SensitiveParameter] string $hash): bool
	{
		return $this->inner->needsRehash($hash);
	}
}

class HttpBasicAuthenticatorTest extends TestCase
{
	protected function tearDown(): void
	{
		DummyPasswordHash::reset();
		parent::tearDown();
	}

	private function authenticator(?LoginThrottle $throttle = null): HttpBasicAuthenticator
	{
		$hasher = new DefaultPasswordHasher();
		$provider = new InMemoryUserProvider([
			'alice' => ['password_hash' => $hasher->hash('secret'), 'roles' => ['user']],
		]);
		return new HttpBasicAuthenticator($provider, $hasher, $throttle);
	}

	private function basicRequest(string $credentials, string $peer = '203.0.113.7'): \Psr\Http\Message\ServerRequestInterface
	{
		return (new Psr17Factory())
			->createServerRequest('GET', '/', ['REMOTE_ADDR' => $peer])
			->withHeader('Authorization', 'Basic ' . base64_encode($credentials));
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

	/**
	 * The account-enumeration oracle, asserted as a call count rather than as a
	 * duration -- wall-clock timing is far too noisy in a test suite to assert
	 * on, but the thing that *causes* the timing difference is exactly countable.
	 *
	 * The natural `$identity === null || !verify(...)` short-circuits, so an
	 * unknown username returns after the provider lookup alone while a known one
	 * pays a full argon2id derivation. That difference is measurable over the
	 * network and tells an attacker which usernames exist. Both paths must spend
	 * one verify().
	 */
	public function testUnknownIdentifierStillPaysOneKeyDerivation(): void
	{
		$hasher = new CountingBasicHasher();
		$provider = new InMemoryUserProvider([
			'alice' => ['password_hash' => $hasher->hash('secret'), 'roles' => ['user']],
		]);
		$authenticator = new HttpBasicAuthenticator($provider, $hasher);

		$hasher->verifyCalls = 0;
		try {
			$authenticator->authenticate($this->basicRequest('nobody:secret'));
			$this->fail('an unknown identifier must not authenticate');
		} catch(AuthenticationException $e) {
			$this->assertNotSame('', $e->getMessage(), 'the rejection must carry a reason');
		}
		$unknownCalls = $hasher->verifyCalls;

		$hasher->verifyCalls = 0;
		try {
			$authenticator->authenticate($this->basicRequest('alice:wrong'));
			$this->fail('a wrong password must not authenticate');
		} catch(AuthenticationException $e) {
			$this->assertNotSame('', $e->getMessage(), 'the rejection must carry a reason');
		}
		$knownCalls = $hasher->verifyCalls;

		$this->assertSame(1, $unknownCalls, 'the unknown-identifier path must still derive once');
		$this->assertSame($knownCalls, $unknownCalls, 'both paths must cost the same number of derivations');
	}

	/**
	 * The dummy must be a real hash in the configured algorithm's own format, or
	 * verify() rejects it as malformed without deriving anything and the
	 * equalization above is only apparent.
	 */
	public function testTheDummyHashIsAWellFormedHashThatNoPasswordMatches(): void
	{
		$hasher = new DefaultPasswordHasher();

		$dummy = DummyPasswordHash::for($hasher);

		$this->assertNotSame('', $dummy);
		$this->assertNotNull(password_get_info($dummy)['algo'], 'must be a recognised algorithm, not a plain string');
		$this->assertFalse($hasher->verify('secret', $dummy));
		$this->assertFalse($hasher->verify('', $dummy));
	}

	public function testTheDummyHashIsMemoizedPerHasherInstance(): void
	{
		$one = new DefaultPasswordHasher();
		$two = new DefaultPasswordHasher();

		$this->assertSame(DummyPasswordHash::for($one), DummyPasswordHash::for($one));
		$this->assertNotSame(
			DummyPasswordHash::for($one),
			DummyPasswordHash::for($two),
			'a distinct hasher gets its own dummy, so a distinct cost is honoured',
		);
	}

	/**
	 * Basic auth carries its credential on every request with no form, no token
	 * and no session to obtain first, so an unthrottled Basic surface is the
	 * cheapest password-guessing target an application exposes.
	 */
	public function testThrottleRejectsAfterTooManyFailures(): void
	{
		$throttle = new LoginThrottle(new InMemoryStorage(), maxAttempts: 1, interval: '1 hour');
		$authenticator = $this->authenticator($throttle);

		try {
			$authenticator->authenticate($this->basicRequest('alice:wrong'));
			$this->fail('the first wrong password must be rejected');
		} catch(AuthenticationException $first) {
			$this->assertStringContainsString('Invalid credentials', $first->getMessage());
		}

		// The allowance is spent, so even the *correct* password is now refused.
		$this->expectException(AuthenticationException::class);
		$this->expectExceptionMessageMatches('/Too many attempts/');
		$authenticator->authenticate($this->basicRequest('alice:secret'));
	}

	public function testThrottleAlsoBoundsGuessingSpreadAcrossIdentifiers(): void
	{
		// The per-client key, not the per-identifier one: one attempt each against
		// many accounts must not slip past a throttle keyed only on the account.
		$throttle = new LoginThrottle(new InMemoryStorage(), maxAttempts: 1, interval: '1 hour');
		$authenticator = $this->authenticator($throttle);

		try {
			$authenticator->authenticate($this->basicRequest('someone:wrong'));
			$this->fail('a wrong password must not authenticate');
		} catch(AuthenticationException $e) {
			// The rejection is the precondition; what this test measures is below.
			$this->assertNotSame('', $e->getMessage());
		}

		$this->expectException(AuthenticationException::class);
		$this->expectExceptionMessageMatches('/Too many attempts/');
		$authenticator->authenticate($this->basicRequest('someone-else:wrong'));
	}

	public function testThrottleResetsOnSuccessfulAuthentication(): void
	{
		$throttle = new LoginThrottle(new InMemoryStorage(), maxAttempts: 2, interval: '1 hour');
		$authenticator = $this->authenticator($throttle);

		try {
			$authenticator->authenticate($this->basicRequest('alice:wrong'));
			$this->fail('a wrong password must not authenticate');
		} catch(AuthenticationException $e) {
			// The rejection is the precondition; what this test measures is below.
			$this->assertNotSame('', $e->getMessage());
		}

		$this->assertSame('alice', $authenticator->authenticate($this->basicRequest('alice:secret'))->getIdentity()->getIdentifier());

		// The earlier failure was cleared, so the full allowance is available again.
		try {
			$authenticator->authenticate($this->basicRequest('alice:wrong'));
		} catch(AuthenticationException $e) {
			$this->assertStringContainsString('Invalid credentials', $e->getMessage());
		}
		$this->assertSame('alice', $authenticator->authenticate($this->basicRequest('alice:secret'))->getIdentity()->getIdentifier());
	}

	public function testWithoutAThrottleRepeatedFailuresAreNotBlocked(): void
	{
		// The throttle stays a soft dependency; omitting it must not start
		// rejecting requests for a reason the caller never opted into.
		$authenticator = $this->authenticator();

		for($i = 0; $i < 3; $i++) {
			try {
				$authenticator->authenticate($this->basicRequest('alice:wrong'));
				$this->fail('a wrong password must not authenticate');
			} catch(AuthenticationException $e) {
				$this->assertStringContainsString('Invalid credentials', $e->getMessage());
			}
		}

		$this->assertSame('alice', $authenticator->authenticate($this->basicRequest('alice:secret'))->getIdentity()->getIdentifier());
	}
}
