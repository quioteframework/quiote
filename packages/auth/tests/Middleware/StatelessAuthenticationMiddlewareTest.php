<?php

use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\Response as Psr7Response;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Quiote\Security\Auth\AuthenticationManager;
use Quiote\Security\Auth\Authenticator\HttpBasicAuthenticator;
use Quiote\Security\Auth\EntryPoint\HttpChallengeEntryPoint;
use Quiote\Security\Auth\Firewall;
use Quiote\Security\Auth\FirewallMap;
use Quiote\Security\Auth\Hasher\DefaultPasswordHasher;
use Quiote\Security\Auth\Middleware\StatelessAuthenticationMiddleware;
use Quiote\Security\Auth\Provider\InMemoryUserProvider;
use Quiote\Testing\UnitTestCase;
use Quiote\User\SecurityUser;

class TrackingRequestHandler implements RequestHandlerInterface
{
	public bool $called = false;

	public ?ServerRequestInterface $seenRequest = null;

	public function handle(ServerRequestInterface $request): ResponseInterface
	{
		$this->called = true;
		$this->seenRequest = $request;
		return new Psr7Response(200);
	}
}

class StatelessAuthenticationMiddlewareTest extends UnitTestCase
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

	private function trackingHandler(): TrackingRequestHandler
	{
		return new TrackingRequestHandler();
	}

	private function firewallMap(bool $sessionless = false): FirewallMap
	{
		$hasher = new DefaultPasswordHasher();
		$provider = new InMemoryUserProvider(['alice' => ['password_hash' => $hasher->hash('secret'), 'roles' => ['api']]]);
		$authenticator = new HttpBasicAuthenticator($provider, $hasher);
		$firewall = new Firewall('api', '^/api/', [$authenticator], new HttpChallengeEntryPoint(), stateless: true, sessionless: $sessionless);
		return new FirewallMap([$firewall]);
	}

	public function testNonMatchingPathPassesThroughUnchanged(): void
	{
		$middleware = new StatelessAuthenticationMiddleware($this->firewallMap(), new AuthenticationManager($this->getContext()->getController()));
		$handler = $this->trackingHandler();

		$response = $middleware->process((new Psr17Factory())->createServerRequest('GET', '/web/page'), $handler);

		$this->assertTrue($handler->called);
		$this->assertSame(200, $response->getStatusCode());
		$this->assertFalse($this->securityUser()->isAuthenticated());
	}

	public function testMatchingPathWithNoCredentialPassesThroughUnauthenticated(): void
	{
		$middleware = new StatelessAuthenticationMiddleware($this->firewallMap(), new AuthenticationManager($this->getContext()->getController()));
		$handler = $this->trackingHandler();

		$response = $middleware->process((new Psr17Factory())->createServerRequest('GET', '/api/resource'), $handler);

		$this->assertTrue($handler->called);
		$this->assertSame(200, $response->getStatusCode());
		$this->assertFalse($this->securityUser()->isAuthenticated());
	}

	public function testValidCredentialAuthenticatesAndCallsHandler(): void
	{
		$middleware = new StatelessAuthenticationMiddleware($this->firewallMap(), new AuthenticationManager($this->getContext()->getController()));
		$handler = $this->trackingHandler();
		$request = (new Psr17Factory())->createServerRequest('GET', '/api/resource')
			->withHeader('Authorization', 'Basic ' . base64_encode('alice:secret'));

		$response = $middleware->process($request, $handler);

		$this->assertTrue($handler->called);
		$this->assertSame(200, $response->getStatusCode());
		$this->assertTrue($this->securityUser()->isAuthenticated());
		$this->assertTrue($this->securityUser()->isTokenDerived());
	}

	public function testInvalidCredentialShortCircuitsWithTheEntryPointResponse(): void
	{
		$middleware = new StatelessAuthenticationMiddleware($this->firewallMap(), new AuthenticationManager($this->getContext()->getController()));
		$handler = $this->trackingHandler();
		$request = (new Psr17Factory())->createServerRequest('GET', '/api/resource')
			->withHeader('Authorization', 'Basic ' . base64_encode('alice:wrong'));

		$response = $middleware->process($request, $handler);

		$this->assertFalse($handler->called);
		$this->assertSame(401, $response->getStatusCode());
		$this->assertFalse($this->securityUser()->isAuthenticated());
	}

	public function testSessionlessFirewallSetsTheAuthSessionlessAttribute(): void
	{
		$middleware = new StatelessAuthenticationMiddleware($this->firewallMap(sessionless: true), new AuthenticationManager($this->getContext()->getController()));
		$handler = $this->trackingHandler();
		$request = (new Psr17Factory())->createServerRequest('GET', '/api/resource')
			->withHeader('Authorization', 'Basic ' . base64_encode('alice:secret'));

		$middleware->process($request, $handler);

		$this->assertNotNull($handler->seenRequest);
		$this->assertTrue($handler->seenRequest->getAttribute('auth.sessionless'));
	}
}
