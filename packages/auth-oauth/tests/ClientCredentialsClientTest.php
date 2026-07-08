<?php

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;
use Quiote\Security\Auth\ClientCredentialsClient;

class ClientCredentialsClientTest extends TestCase
{
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
