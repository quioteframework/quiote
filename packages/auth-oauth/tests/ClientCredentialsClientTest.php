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
