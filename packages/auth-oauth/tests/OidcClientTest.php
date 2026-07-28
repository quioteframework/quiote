<?php

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;
use Quiote\Security\Auth\AuthenticationException;
use Quiote\Security\Auth\OidcClient;
use Quiote\Security\Auth\OidcDiscoveryDocument;

class OidcClientTest extends TestCase
{
	public function testFromDiscoveryWiresTheDiscoveredAuthorizationAndTokenEndpoints(): void
	{
		$mock = new MockHandler([
			new Response(200, ['Content-Type' => 'application/json'], (string) json_encode([
				'access_token' => 'access-token-value',
				'token_type' => 'Bearer',
				'expires_in' => 3600,
				'id_token' => 'id-token-value',
			])),
		]);
		$httpClient = new Client(['handler' => HandlerStack::create($mock)]);
		$document = OidcDiscoveryDocument::fromArray([
			'issuer' => 'https://idp.example.com',
			'authorization_endpoint' => 'https://idp.example.com/oauth2/v2/authorize',
			'token_endpoint' => 'https://idp.example.com/oauth2/v2/token',
			'code_challenge_methods_supported' => ['S256'],
		]);

		$client = OidcClient::fromDiscovery($document, 'client-id', 'client-secret', 'https://app.example.com/callback', ['openid', 'profile'], $httpClient);

		$authorizationRequest = $client->buildAuthorizationRequest();
		$this->assertStringStartsWith('https://idp.example.com/oauth2/v2/authorize?', $authorizationRequest->getAuthorizationUrl());

		$client->exchangeCode('auth-code', $authorizationRequest->getState()->getPkceVerifier());
		$this->assertNotNull($mock->getLastRequest());
		$this->assertSame('https://idp.example.com/oauth2/v2/token', (string) $mock->getLastRequest()->getUri());
	}

	public function testFromDiscoveryRejectsAProviderThatRulesOutS256Pkce(): void
	{
		$document = OidcDiscoveryDocument::fromArray([
			'issuer' => 'https://idp.example.com',
			'authorization_endpoint' => 'https://idp.example.com/authorize',
			'token_endpoint' => 'https://idp.example.com/token',
			'code_challenge_methods_supported' => ['plain'],
		]);

		$this->expectException(AuthenticationException::class);
		$this->expectExceptionMessage('S256');
		OidcClient::fromDiscovery($document, 'client-id', 'client-secret', 'https://app.example.com/callback');
	}

	public function testFromDiscoveryThrowsWhenTheProviderAdvertisesNoAuthorizationEndpoint(): void
	{
		$document = OidcDiscoveryDocument::fromArray([
			'issuer' => 'https://idp.example.com',
			'token_endpoint' => 'https://idp.example.com/token',
		]);

		$this->expectException(AuthenticationException::class);
		$this->expectExceptionMessage('authorization_endpoint');
		OidcClient::fromDiscovery($document, 'client-id', 'client-secret', 'https://app.example.com/callback');
	}

	public function testBuildAuthorizationRequestGeneratesStateNonceAndAnS256PkceChallenge(): void
	{
		$client = new OidcClient(
			'client-id',
			'client-secret',
			'https://app.example.com/callback',
			'https://idp.example.com/authorize',
			'https://idp.example.com/token',
		);

		$request = $client->buildAuthorizationRequest();

		$url = $request->getAuthorizationUrl();
		parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

		$this->assertSame('S256', $query['code_challenge_method']);
		$this->assertNotEmpty($query['code_challenge']);
		$this->assertNotEmpty($query['nonce']);
		$this->assertSame($query['state'], $request->getState()->getState());
		$this->assertSame($query['nonce'], $request->getState()->getNonce());

		$expectedChallenge = rtrim(strtr(base64_encode(hash('sha256', $request->getState()->getPkceVerifier(), true)), '+/', '-_'), '=');
		$this->assertSame($expectedChallenge, $query['code_challenge']);
	}

	public function testBuildAuthorizationRequestGeneratesAFreshStateEachCall(): void
	{
		$client = new OidcClient('id', 'secret', 'https://app.example.com/callback', 'https://idp.example.com/authorize', 'https://idp.example.com/token');

		$first = $client->buildAuthorizationRequest();
		$second = $client->buildAuthorizationRequest();

		$this->assertNotSame($first->getState()->getState(), $second->getState()->getState());
		$this->assertNotSame($first->getState()->getNonce(), $second->getState()->getNonce());
	}

	public function testExchangeCodeReturnsAnAccessTokenIncludingTheIdToken(): void
	{
		$mock = new MockHandler([
			new Response(200, ['Content-Type' => 'application/json'], (string) json_encode([
				'access_token' => 'access-token-value',
				'token_type' => 'Bearer',
				'expires_in' => 3600,
				'id_token' => 'id-token-value',
			])),
		]);
		$httpClient = new Client(['handler' => HandlerStack::create($mock)]);

		$client = new OidcClient(
			'client-id',
			'client-secret',
			'https://app.example.com/callback',
			'https://idp.example.com/authorize',
			'https://idp.example.com/token',
			httpClient: $httpClient,
		);

		$token = $client->exchangeCode('auth-code', 'pkce-verifier');

		$this->assertSame('access-token-value', $token->getToken());
		$this->assertSame('id-token-value', $token->getValues()['id_token']);
	}

	public function testExchangeCodeThrowsAnAuthenticationExceptionWhenTheTokenEndpointFails(): void
	{
		$mock = new MockHandler([
			new Response(400, ['Content-Type' => 'application/json'], (string) json_encode(['error' => 'invalid_grant'])),
		]);
		$httpClient = new Client(['handler' => HandlerStack::create($mock)]);

		$client = new OidcClient(
			'client-id',
			'client-secret',
			'https://app.example.com/callback',
			'https://idp.example.com/authorize',
			'https://idp.example.com/token',
			httpClient: $httpClient,
		);

		$this->expectException(AuthenticationException::class);
		$client->exchangeCode('bad-code', 'pkce-verifier');
	}
}
