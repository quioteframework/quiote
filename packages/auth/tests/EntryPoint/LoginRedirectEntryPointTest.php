<?php

use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\TestCase;
use Quiote\Security\Auth\AuthenticationException;
use Quiote\Security\Auth\EntryPoint\LoginRedirectEntryPoint;

class LoginRedirectEntryPointTest extends TestCase
{
	public function testStartReturnsA302RedirectToTheLoginPath(): void
	{
		$entryPoint = new LoginRedirectEntryPoint('/login');
		$request = (new Psr17Factory())->createServerRequest('POST', '/login');

		$response = $entryPoint->start($request, new AuthenticationException('invalid credentials'));

		$this->assertSame(302, $response->getStatusCode());
		$this->assertSame('/login?error=1', $response->getHeaderLine('Location'));
	}

	public function testStartAppendsTheErrorParameterWithAnAmpersandWhenTheLoginPathAlreadyHasAQueryString(): void
	{
		$entryPoint = new LoginRedirectEntryPoint('/login?next=/dashboard');
		$request = (new Psr17Factory())->createServerRequest('POST', '/login');

		$response = $entryPoint->start($request, new AuthenticationException('invalid credentials'));

		$this->assertSame('/login?next=/dashboard&error=1', $response->getHeaderLine('Location'));
	}
}
