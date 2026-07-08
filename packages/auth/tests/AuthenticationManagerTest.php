<?php

use Nyholm\Psr7\Factory\Psr17Factory;
use Quiote\Security\Auth\AuthenticationException;
use Quiote\Security\Auth\AuthenticationManager;
use Quiote\Security\Auth\AuthenticatorInterface;
use Quiote\Security\Auth\EntryPoint\HttpChallengeEntryPoint;
use Quiote\Security\Auth\EntryPoint\LoginRedirectEntryPoint;
use Quiote\Security\Auth\Firewall;
use Quiote\Security\Auth\Identity\InMemoryUserIdentity;
use Quiote\Security\Auth\Passport;
use Quiote\Testing\UnitTestCase;
use Quiote\User\SecurityUser;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

class AlwaysSupportsAuthenticator implements AuthenticatorInterface
{
	public function __construct(private readonly Passport $passport)
	{
	}

	public function supports(ServerRequestInterface $request): bool
	{
		return true;
	}

	public function authenticate(ServerRequestInterface $request): Passport
	{
		return $this->passport;
	}

	public function onFailure(AuthenticationException $exception): ?ResponseInterface
	{
		return null;
	}
}

class FailingAuthenticator implements AuthenticatorInterface
{
	public function __construct(private readonly AuthenticationException $exception)
	{
	}

	public function supports(ServerRequestInterface $request): bool
	{
		return true;
	}

	public function authenticate(ServerRequestInterface $request): Passport
	{
		throw $this->exception;
	}

	public function onFailure(AuthenticationException $exception): ?ResponseInterface
	{
		return null;
	}
}

class NeverSupportsAuthenticator implements AuthenticatorInterface
{
	public function supports(ServerRequestInterface $request): bool
	{
		return false;
	}

	public function authenticate(ServerRequestInterface $request): Passport
	{
		throw new AuthenticationException('should never be called');
	}

	public function onFailure(AuthenticationException $exception): ?ResponseInterface
	{
		return null;
	}
}

class AuthenticationManagerTest extends UnitTestCase
{
	#[\Override]
    protected function setUp(): void
	{
		parent::setUp();
		$this->securityUser()->setAuthenticated(false);
		$this->securityUser()->clearCredentials();
	}

	private function securityUser(): SecurityUser
	{
		$user = $this->getContext()->getUser();
		self::assertInstanceOf(SecurityUser::class, $user);
		return $user;
	}

	private function request(): ServerRequestInterface
	{
		return (new Psr17Factory())->createServerRequest('GET', '/');
	}

	public function testAuthenticateReturnsNullWhenNoAuthenticatorSupportsTheRequest(): void
	{
		$manager = new AuthenticationManager($this->getContext()->getController());
		$firewall = new Firewall('main', '^/', [new NeverSupportsAuthenticator()], new LoginRedirectEntryPoint());

		$this->assertNull($manager->authenticate($this->request(), $firewall));
		$this->assertFalse($this->securityUser()->isAuthenticated());
	}

	public function testAuthenticateAppliesASuccessfulPassportToTheSecurityUser(): void
	{
		$identity = new InMemoryUserIdentity('alice', 'hash', ['user']);
		$passport = new Passport($identity, ['user'], stateless: false);
		$authenticator = new AlwaysSupportsAuthenticator($passport);
		$manager = new AuthenticationManager($this->getContext()->getController());
		$firewall = new Firewall('main', '^/', [$authenticator], new LoginRedirectEntryPoint());

		$result = $manager->authenticate($this->request(), $firewall);

		$this->assertSame($passport, $result);
		$user = $this->securityUser();
		$this->assertTrue($user->isAuthenticated());
		$this->assertTrue($user->hasCredentials('user'));
		$this->assertFalse($user->isTokenDerived());
	}

	public function testAuthenticateMarksTheUserTokenDerivedForAStatelessFirewall(): void
	{
		$identity = new InMemoryUserIdentity('service', 'hash', ['api']);
		$passport = new Passport($identity, ['api'], stateless: true);
		$authenticator = new AlwaysSupportsAuthenticator($passport);
		$manager = new AuthenticationManager($this->getContext()->getController());
		$firewall = new Firewall('api', '^/api/', [$authenticator], new HttpChallengeEntryPoint(), stateless: true);

		$manager->authenticate($this->request(), $firewall);

		$this->assertTrue($this->securityUser()->isTokenDerived());
	}

	public function testAuthenticatePropagatesTheAuthenticationExceptionOnFailure(): void
	{
		$authenticator = new FailingAuthenticator(new AuthenticationException('bad credentials'));
		$manager = new AuthenticationManager($this->getContext()->getController());
		$firewall = new Firewall('main', '^/', [$authenticator], new LoginRedirectEntryPoint());

		try {
			$manager->authenticate($this->request(), $firewall);
			$this->fail('Expected an AuthenticationException.');
		} catch(AuthenticationException $exception) {
			$this->assertSame('bad credentials', $exception->getMessage());
		}

		$this->assertFalse($this->securityUser()->isAuthenticated());
	}
}
