<?php

use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\Response as Psr7Response;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Quiote\Security\Auth\AuthenticationManager;
use Quiote\Security\Auth\Authenticator\FormLoginAuthenticator;
use Quiote\Security\Auth\EntryPoint\LoginRedirectEntryPoint;
use Quiote\Security\Auth\Firewall;
use Quiote\Security\Auth\FirewallMap;
use Quiote\Security\Auth\Hasher\DefaultPasswordHasher;
use Quiote\Security\Auth\Middleware\SessionAuthenticationMiddleware;
use Quiote\Security\Auth\Provider\InMemoryUserProvider;
use Quiote\Testing\UnitTestCase;
use Quiote\User\SecurityUser;

class SessionTrackingRequestHandler implements RequestHandlerInterface
{
	public bool $called = false;

	public function handle(ServerRequestInterface $request): ResponseInterface
	{
		$this->called = true;
		return new Psr7Response(200);
	}
}

class SessionAuthenticationMiddlewareTest extends UnitTestCase
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

	private function trackingHandler(): SessionTrackingRequestHandler
	{
		return new SessionTrackingRequestHandler();
	}

	private function firewallMap(): FirewallMap
	{
		$hasher = new DefaultPasswordHasher();
		$provider = new InMemoryUserProvider(['alice' => ['password_hash' => $hasher->hash('secret'), 'roles' => ['user']]]);
		$authenticator = new FormLoginAuthenticator($provider, $hasher);
		$firewall = new Firewall('main', '^/', [$authenticator], new LoginRedirectEntryPoint());
		return new FirewallMap([$firewall]);
	}

	public function testANonLoginRequestPassesThroughUnauthenticated(): void
	{
		$middleware = new SessionAuthenticationMiddleware($this->firewallMap(), new AuthenticationManager($this->getContext()->getController()));
		$handler = $this->trackingHandler();

		$response = $middleware->process((new Psr17Factory())->createServerRequest('GET', '/dashboard'), $handler);

		$this->assertTrue($handler->called);
		$this->assertSame(200, $response->getStatusCode());
		$this->assertFalse($this->securityUser()->isAuthenticated());
	}

	public function testAValidLoginPostAuthenticatesAndCallsHandler(): void
	{
		$middleware = new SessionAuthenticationMiddleware($this->firewallMap(), new AuthenticationManager($this->getContext()->getController()));
		$handler = $this->trackingHandler();
		$request = (new Psr17Factory())->createServerRequest('POST', '/login')
			->withParsedBody(['username' => 'alice', 'password' => 'secret']);

		$response = $middleware->process($request, $handler);

		$this->assertTrue($handler->called);
		$this->assertSame(200, $response->getStatusCode());
		$this->assertTrue($this->securityUser()->isAuthenticated());
		$this->assertFalse($this->securityUser()->isTokenDerived());
	}

	public function testAnInvalidLoginPostShortCircuitsWithARedirect(): void
	{
		$middleware = new SessionAuthenticationMiddleware($this->firewallMap(), new AuthenticationManager($this->getContext()->getController()));
		$handler = $this->trackingHandler();
		$request = (new Psr17Factory())->createServerRequest('POST', '/login')
			->withParsedBody(['username' => 'alice', 'password' => 'wrong']);

		$response = $middleware->process($request, $handler);

		$this->assertFalse($handler->called);
		$this->assertSame(302, $response->getStatusCode());
		$this->assertFalse($this->securityUser()->isAuthenticated());
	}
}
