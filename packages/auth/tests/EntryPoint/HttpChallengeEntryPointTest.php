<?php

use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\TestCase;
use Quiote\Http\ProblemDetails;
use Quiote\Security\Auth\AuthenticationException;
use Quiote\Security\Auth\EntryPoint\HttpChallengeEntryPoint;

class HttpChallengeEntryPointTest extends TestCase
{
	public function testStartReturnsA401WithABearerChallenge(): void
	{
		$entryPoint = new HttpChallengeEntryPoint();
		$request = (new Psr17Factory())->createServerRequest('GET', '/api/resource');

		$response = $entryPoint->start($request, new AuthenticationException('invalid token'));

		$this->assertSame(401, $response->getStatusCode());
		$this->assertSame('Bearer', $response->getHeaderLine('WWW-Authenticate'));
		$this->assertSame(ProblemDetails::MEDIA_TYPE, $response->getHeaderLine('Content-Type'));
		$this->assertStringContainsString('invalid token', (string) $response->getBody());
	}

	public function testStartIncludesARealmWhenConfigured(): void
	{
		$entryPoint = new HttpChallengeEntryPoint('Basic', 'restricted-area');
		$request = (new Psr17Factory())->createServerRequest('GET', '/api/resource');

		$response = $entryPoint->start($request, new AuthenticationException('missing credentials'));

		$this->assertSame('Basic realm="restricted-area"', $response->getHeaderLine('WWW-Authenticate'));
	}
}
