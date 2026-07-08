<?php

use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\Response;
use PHPUnit\Framework\TestCase;
use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Quiote\Security\Auth\AuthenticationException;
use Quiote\Security\Auth\IntrospectionClient;

class RespondingHttpClient implements ClientInterface
{
	public ?RequestInterface $lastRequest = null;

	public function __construct(private readonly ResponseInterface $response)
	{
	}

	public function sendRequest(RequestInterface $request): ResponseInterface
	{
		$this->lastRequest = $request;
		return $this->response;
	}
}

class ThrowingHttpClient implements ClientInterface
{
	public function sendRequest(RequestInterface $request): ResponseInterface
	{
		throw new class ('network down') extends \RuntimeException implements ClientExceptionInterface {};
	}
}

class IntrospectionClientTest extends TestCase
{
	private function factory(): Psr17Factory
	{
		return new Psr17Factory();
	}

	public function testIntrospectReturnsClaimsForAnActiveToken(): void
	{
		$httpClient = new RespondingHttpClient(new Response(200, ['Content-Type' => 'application/json'], (string) json_encode(['active' => true, 'sub' => 'user-1', 'scope' => 'read'])));
		$client = new IntrospectionClient($httpClient, $this->factory(), $this->factory(), 'https://idp.example.com/introspect', 'client-id', 'client-secret');

		$result = $client->introspect('some-token');

		$this->assertTrue($result['active']);
		$this->assertSame('user-1', $result['sub']);
		$this->assertNotNull($httpClient->lastRequest);
		$this->assertSame('POST', $httpClient->lastRequest->getMethod());
		$this->assertStringStartsWith('Basic ', $httpClient->lastRequest->getHeaderLine('Authorization'));
	}

	public function testIntrospectThrowsWhenTheTokenIsNotActive(): void
	{
		$httpClient = new RespondingHttpClient(new Response(200, ['Content-Type' => 'application/json'], (string) json_encode(['active' => false])));
		$client = new IntrospectionClient($httpClient, $this->factory(), $this->factory(), 'https://idp.example.com/introspect', 'client-id', 'client-secret');

		$this->expectException(AuthenticationException::class);
		$this->expectExceptionMessage('not active');
		$client->introspect('revoked-token');
	}

	public function testIntrospectThrowsOnMalformedJson(): void
	{
		$httpClient = new RespondingHttpClient(new Response(200, ['Content-Type' => 'application/json'], 'not json'));
		$client = new IntrospectionClient($httpClient, $this->factory(), $this->factory(), 'https://idp.example.com/introspect', 'client-id', 'client-secret');

		$this->expectException(AuthenticationException::class);
		$this->expectExceptionMessage('malformed');
		$client->introspect('some-token');
	}

	public function testIntrospectThrowsWhenTheHttpRequestFails(): void
	{
		$client = new IntrospectionClient(new ThrowingHttpClient(), $this->factory(), $this->factory(), 'https://idp.example.com/introspect', 'client-id', 'client-secret');

		$this->expectException(AuthenticationException::class);
		$this->expectExceptionMessage('request failed');
		$client->introspect('some-token');
	}
}
