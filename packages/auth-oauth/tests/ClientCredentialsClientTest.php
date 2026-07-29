<?php

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;
use Quiote\Security\Auth\AuthenticationException;
use Quiote\Security\Auth\ClientCredentialsClient;
use Quiote\Security\Auth\OidcDiscoveryDocument;

class ClientCredentialsClientTest extends TestCase
{
	public function testFromDiscoveryUsesTheDiscoveredTokenEndpoint(): void
	{
		$mock = new MockHandler([
			new Response(200, ['Content-Type' => 'application/json'], (string) json_encode([
				'access_token' => 'discovered-m2m-token',
				'token_type' => 'Bearer',
				'expires_in' => 3600,
			])),
		]);
		$httpClient = new Client(['handler' => HandlerStack::create($mock)]);
		$document = OidcDiscoveryDocument::fromArray([
			'issuer' => 'https://idp.example.com',
			'token_endpoint' => 'https://idp.example.com/oauth2/v2/token',
		]);

		$client = ClientCredentialsClient::fromDiscovery($document, 'client-id', 'client-secret', ['api://target/.default'], $httpClient);
		$token = $client->getAccessToken();

		$this->assertSame('discovered-m2m-token', $token->getToken());
		$this->assertNotNull($mock->getLastRequest());
		$this->assertSame('https://idp.example.com/oauth2/v2/token', (string) $mock->getLastRequest()->getUri());
	}

	/**
	 * RFC 6749 §3.3: `scope` is space-delimited. league/oauth2-client's default
	 * separator is a comma, which real authorization servers reject or read as
	 * a single unknown scope.
	 */
	public function testTokenRequestDelimitsScopesWithSpaces(): void
	{
		$mock = new MockHandler([
			new Response(200, ['Content-Type' => 'application/json'], (string) json_encode([
				'access_token' => 'm2m-access-token',
				'token_type' => 'Bearer',
				'expires_in' => 3600,
			])),
		]);
		$httpClient = new Client(['handler' => HandlerStack::create($mock)]);

		$client = new ClientCredentialsClient(
			'client-id',
			'client-secret',
			'https://idp.example.com/token',
			['api://target/.default', 'offline_access'],
			$httpClient,
		);
		$client->getAccessToken();

		$lastRequest = $mock->getLastRequest();
		$this->assertNotNull($lastRequest);
		parse_str((string) $lastRequest->getBody(), $body);
		$this->assertSame('api://target/.default offline_access', $body['scope']);
	}

	public function testFromDiscoveryThrowsWhenTheProviderAdvertisesNoTokenEndpoint(): void
	{
		$document = OidcDiscoveryDocument::fromArray(['issuer' => 'https://idp.example.com']);

		$this->expectException(AuthenticationException::class);
		$this->expectExceptionMessage('token_endpoint');
		ClientCredentialsClient::fromDiscovery($document, 'client-id', 'client-secret');
	}

	public function testGetAccessTokenReturnsATokenFromTheTokenEndpoint(): void
	{
		$mock = new MockHandler([
			new Response(200, ['Content-Type' => 'application/json'], (string) json_encode([
				'access_token' => 'm2m-access-token',
				'token_type' => 'Bearer',
				'expires_in' => 3600,
			])),
		]);
		$httpClient = new Client(['handler' => HandlerStack::create($mock)]);

		$client = new ClientCredentialsClient('client-id', 'client-secret', 'https://idp.example.com/token', httpClient: $httpClient);

		$token = $client->getAccessToken();

		$this->assertSame('m2m-access-token', $token->getToken());
	}
}
