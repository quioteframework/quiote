<?php

use Nyholm\Psr7\Factory\Psr17Factory;
use Quiote\Security\Auth\AuthenticationException;
use Quiote\Security\Auth\AuthenticationManager;
use Quiote\Security\Auth\AuthenticatorInterface;
use Quiote\Security\Auth\EntryPoint\HttpChallengeEntryPoint;
use Quiote\Security\Auth\EntryPoint\LoginRedirectEntryPoint;
use Quiote\Security\Auth\Firewall;
use Quiote\Security\Auth\Identity\InMemoryUserIdentity;
use Quiote\Security\Auth\ClientType;
use Quiote\Security\Auth\Passport;
use Quiote\Security\Auth\TokenClaims;
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

class AuthManagerRbacUser extends \Quiote\User\RbacSecurityUser
{
	#[\Override]
	protected function loadDefinitions()
	{
		$this->definitions = [
			'session-role' => ['permissions' => ['photos.list']],
			'token-role' => ['permissions' => ['api.call']],
		];
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
		$user = $this->getContext()->getContainer()->get(\Quiote\User\ISecurityUser::class);
		self::assertInstanceOf(SecurityUser::class, $user);
		return $user;
	}

	private function request(): ServerRequestInterface
	{
		return (new Psr17Factory())->createServerRequest('GET', '/');
	}

	public function testAuthenticateReturnsNullWhenNoAuthenticatorSupportsTheRequest(): void
	{
		$manager = new AuthenticationManager($this->getContext()->getContainer()->get(\Quiote\Controller\Controller::class));
		$firewall = new Firewall('main', '^/', [new NeverSupportsAuthenticator()], new LoginRedirectEntryPoint());

		$this->assertNull($manager->authenticate($this->request(), $firewall));
		$this->assertFalse($this->securityUser()->isAuthenticated());
	}

	public function testAuthenticateAppliesASuccessfulPassportToTheSecurityUser(): void
	{
		$identity = new InMemoryUserIdentity('alice', 'hash', ['user']);
		$passport = new Passport($identity, ['user'], stateless: false);
		$authenticator = new AlwaysSupportsAuthenticator($passport);
		$manager = new AuthenticationManager($this->getContext()->getContainer()->get(\Quiote\Controller\Controller::class));
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
		$manager = new AuthenticationManager($this->getContext()->getContainer()->get(\Quiote\Controller\Controller::class));
		$firewall = new Firewall('api', '^/api/', [$authenticator], new HttpChallengeEntryPoint(), stateless: true);

		$manager->authenticate($this->request(), $firewall);

		$this->assertTrue($this->securityUser()->isTokenDerived());
	}

	public function testAuthenticateStoresThePassportsClaimsOnTheSecurityUserForAStatelessFirewall(): void
	{
		$identity = new InMemoryUserIdentity('service', 'hash', ['api']);
		$claims = new TokenClaims('service', ['sub' => 'service'], ClientType::Service);
		$passport = new Passport($identity, ['api'], stateless: true, claims: $claims);
		$authenticator = new AlwaysSupportsAuthenticator($passport);
		$manager = new AuthenticationManager($this->getContext()->getContainer()->get(\Quiote\Controller\Controller::class));
		$firewall = new Firewall('api', '^/api/', [$authenticator], new HttpChallengeEntryPoint(), stateless: true);

		$manager->authenticate($this->request(), $firewall);

		$this->assertSame($claims, $this->securityUser()->getTokenClaims());
	}

	public function testAuthenticateDoesNotSetClaimsForANonStatelessFirewall(): void
	{
		$identity = new InMemoryUserIdentity('alice', 'hash', ['user']);
		$passport = new Passport($identity, ['user'], stateless: false);
		$authenticator = new AlwaysSupportsAuthenticator($passport);
		$manager = new AuthenticationManager($this->getContext()->getContainer()->get(\Quiote\Controller\Controller::class));
		$firewall = new Firewall('main', '^/', [$authenticator], new LoginRedirectEntryPoint());

		$manager->authenticate($this->request(), $firewall);

		$this->assertNull($this->securityUser()->getTokenClaims());
	}

	/**
	 * A stateless firewall re-derives the whole identity from the credential the
	 * caller presented. Whatever the session had rehydrated onto the user before
	 * that -- a browser login on a cookie sent alongside the token -- is not part
	 * of it and must be gone before the passport's own grants land.
	 */
	public function testAuthenticateDropsRehydratedCredentialsForAStatelessFirewall(): void
	{
		$user = $this->securityUser();
		$user->addCredential('session.credential');

		$identity = new InMemoryUserIdentity('service', 'hash', ['api']);
		$passport = new Passport($identity, ['api'], stateless: true);
		$manager = new AuthenticationManager($this->getContext()->getContainer()->get(\Quiote\Controller\Controller::class));
		$firewall = new Firewall('api', '^/api/', [new AlwaysSupportsAuthenticator($passport)], new HttpChallengeEntryPoint(), stateless: true);

		$manager->authenticate($this->request(), $firewall);

		$this->assertTrue($user->hasCredentials('api'));
		$this->assertFalse($user->hasCredentials('session.credential'));
	}

	public function testAuthenticateKeepsRehydratedCredentialsForANonStatelessFirewall(): void
	{
		$user = $this->securityUser();
		$user->addCredential('session.credential');

		$identity = new InMemoryUserIdentity('alice', 'hash', ['user']);
		$passport = new Passport($identity, ['user'], stateless: false);
		$manager = new AuthenticationManager($this->getContext()->getContainer()->get(\Quiote\Controller\Controller::class));
		$firewall = new Firewall('main', '^/', [new AlwaysSupportsAuthenticator($passport)], new LoginRedirectEntryPoint());

		$manager->authenticate($this->request(), $firewall);

		$this->assertTrue($user->hasCredentials('user'));
		$this->assertTrue($user->hasCredentials('session.credential'), 'a form login adds to the session identity');
	}

	/**
	 * The same for an RBAC user, where the roles are the identity: the token's
	 * roles replace the session's rather than joining them.
	 */
	public function testAuthenticateDropsRehydratedRolesForAStatelessFirewall(): void
	{
		$container = $this->getContext()->getContainer();
		$original = $container->get(\Quiote\User\ISecurityUser::class);

		$user = new AuthManagerRbacUser();
		$user->initialize($this->getContext());
		$user->grantRole('session-role');
		$container->set('user', $user, \Quiote\DI\Container::SCOPE_REQUEST);

		try {
			$identity = new InMemoryUserIdentity('service', 'hash', ['token-role']);
			$passport = new Passport($identity, ['token-role'], stateless: true);
			$manager = new AuthenticationManager($container->get(\Quiote\Controller\Controller::class));
			$firewall = new Firewall('api', '^/api/', [new AlwaysSupportsAuthenticator($passport)], new HttpChallengeEntryPoint(), stateless: true);

			$manager->authenticate($this->request(), $firewall);

			$this->assertSame(['token-role'], $user->getRoles());
			$this->assertTrue($user->hasCredentials('api.call'));
			$this->assertFalse($user->hasCredentials('photos.list'));
		} finally {
			// The binding is request-scoped, but every test in this class shares
			// this context's container within the process.
			$container->set('user', $original, \Quiote\DI\Container::SCOPE_REQUEST);
		}
	}

	public function testAuthenticatePropagatesTheAuthenticationExceptionOnFailure(): void
	{
		$authenticator = new FailingAuthenticator(new AuthenticationException('bad credentials'));
		$manager = new AuthenticationManager($this->getContext()->getContainer()->get(\Quiote\Controller\Controller::class));
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
