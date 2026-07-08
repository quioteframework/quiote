<?php

use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Quiote\Security\Auth\AuthenticationException;
use Quiote\Security\Auth\AuthenticatorInterface;
use Quiote\Security\Auth\Config\FirewallFactory;
use Quiote\Security\Auth\EntryPoint\HttpChallengeEntryPoint;
use Quiote\Security\Auth\EntryPoint\LoginRedirectEntryPoint;
use Quiote\Security\Auth\Passport;

class NoOpAuthenticator implements AuthenticatorInterface
{
	public function supports(ServerRequestInterface $request): bool { return false; }
	public function authenticate(ServerRequestInterface $request): Passport { throw new AuthenticationException('n/a'); }
	public function onFailure(AuthenticationException $exception): ?ResponseInterface { return null; }
}

class FirewallFactoryTest extends TestCase
{
	private const CONFIG = [
		'firewalls' => [
			'api' => ['pattern' => '^/api/', 'stateless' => true, 'sessionless' => false, 'entry_point' => 'challenge', 'provider' => null, 'authenticators' => ['http_basic']],
			'main' => ['pattern' => '^/', 'stateless' => false, 'sessionless' => false, 'entry_point' => 'login', 'provider' => 'app', 'authenticators' => ['form_login']],
		],
	];

	public function testBuildResolvesAuthenticatorsAndEntryPointsByRef(): void
	{
		$httpBasic = new NoOpAuthenticator();
		$formLogin = new NoOpAuthenticator();
		$loginEntryPoint = new LoginRedirectEntryPoint();
		$challengeEntryPoint = new HttpChallengeEntryPoint();

		$factory = new FirewallFactory(
			['http_basic' => $httpBasic, 'form_login' => $formLogin],
			['challenge' => $challengeEntryPoint, 'login' => $loginEntryPoint],
		);

		$map = $factory->build(self::CONFIG);

		$api = $map->match('/api/users');
		$this->assertNotNull($api);
		$this->assertSame('api', $api->getName());
		$this->assertSame([$httpBasic], $api->getAuthenticators());
		$this->assertSame($challengeEntryPoint, $api->getEntryPoint());
		$this->assertTrue($api->isStateless());

		$main = $map->match('/dashboard');
		$this->assertNotNull($main);
		$this->assertSame($loginEntryPoint, $main->getEntryPoint());
	}

	public function testBuildThrowsForAnUnknownAuthenticatorRef(): void
	{
		$factory = new FirewallFactory([], ['login' => new LoginRedirectEntryPoint()]);

		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('unknown authenticator "http_basic"');
		$factory->build(self::CONFIG);
	}

	public function testBuildThrowsForAnUnknownEntryPoint(): void
	{
		$factory = new FirewallFactory(['http_basic' => new NoOpAuthenticator(), 'form_login' => new NoOpAuthenticator()], []);

		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('unknown entry point');
		$factory->build(self::CONFIG);
	}
}
