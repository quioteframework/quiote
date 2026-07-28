<?php

use PHPUnit\Framework\TestCase;
use Quiote\Security\Auth\AuthenticationException;
use Quiote\Security\Auth\OidcDiscoveryDocument;

class OidcDiscoveryDocumentTest extends TestCase
{
	/**
	 * @return     array<string, mixed> A metadata document with every member this class exposes an accessor for.
	 */
	private function fullMetadata(): array
	{
		return [
			'issuer' => 'https://idp.example.com',
			'authorization_endpoint' => 'https://idp.example.com/authorize',
			'token_endpoint' => 'https://idp.example.com/token',
			'jwks_uri' => 'https://idp.example.com/keys',
			'userinfo_endpoint' => 'https://idp.example.com/userinfo',
			'introspection_endpoint' => 'https://idp.example.com/introspect',
			'revocation_endpoint' => 'https://idp.example.com/revoke',
			'end_session_endpoint' => 'https://idp.example.com/logout',
			'scopes_supported' => ['openid', 'profile', 'email'],
			'response_types_supported' => ['code'],
			'id_token_signing_alg_values_supported' => ['RS256', 'ES256'],
			'code_challenge_methods_supported' => ['S256'],
			'device_authorization_endpoint' => 'https://idp.example.com/devicecode',
		];
	}

	public function testAccessorsExposeEveryDocumentedMember(): void
	{
		$document = OidcDiscoveryDocument::fromArray($this->fullMetadata());

		$this->assertSame('https://idp.example.com', $document->getIssuer());
		$this->assertSame('https://idp.example.com/authorize', $document->getAuthorizationEndpoint());
		$this->assertSame('https://idp.example.com/token', $document->getTokenEndpoint());
		$this->assertSame('https://idp.example.com/keys', $document->getJwksUri());
		$this->assertSame('https://idp.example.com/userinfo', $document->getUserinfoEndpoint());
		$this->assertSame('https://idp.example.com/introspect', $document->getIntrospectionEndpoint());
		$this->assertSame('https://idp.example.com/revoke', $document->getRevocationEndpoint());
		$this->assertSame('https://idp.example.com/logout', $document->getEndSessionEndpoint());
		$this->assertSame(['openid', 'profile', 'email'], $document->getScopesSupported());
		$this->assertSame(['code'], $document->getResponseTypesSupported());
		$this->assertSame(['RS256', 'ES256'], $document->getIdTokenSigningAlgValuesSupported());
		$this->assertSame(['S256'], $document->getCodeChallengeMethodsSupported());
	}

	public function testUnmappedMembersRemainReachable(): void
	{
		$document = OidcDiscoveryDocument::fromArray($this->fullMetadata());

		$this->assertSame('https://idp.example.com/devicecode', $document->get('device_authorization_endpoint'));
		$this->assertNull($document->get('no_such_member'));
		$this->assertSame($this->fullMetadata(), $document->getMetadata());
	}

	public function testFromArrayAcceptsAMatchingExpectedIssuer(): void
	{
		$document = OidcDiscoveryDocument::fromArray($this->fullMetadata(), 'https://idp.example.com');

		$this->assertSame('https://idp.example.com', $document->getIssuer());
	}

	public function testFromArrayRejectsAMismatchedIssuer(): void
	{
		$this->expectException(AuthenticationException::class);
		$this->expectExceptionMessage('does not match the requested issuer');
		OidcDiscoveryDocument::fromArray($this->fullMetadata(), 'https://evil.example.com');
	}

	public function testFromArrayRejectsAMissingIssuer(): void
	{
		$metadata = $this->fullMetadata();
		unset($metadata['issuer']);

		$this->expectException(AuthenticationException::class);
		$this->expectExceptionMessage('missing a valid "issuer"');
		OidcDiscoveryDocument::fromArray($metadata);
	}

	public function testFromArrayRejectsANonStringIssuer(): void
	{
		$metadata = $this->fullMetadata();
		$metadata['issuer'] = ['https://idp.example.com'];

		$this->expectException(AuthenticationException::class);
		$this->expectExceptionMessage('missing a valid "issuer"');
		OidcDiscoveryDocument::fromArray($metadata);
	}

	public function testFromArrayRejectsAnEmptyIssuer(): void
	{
		$metadata = $this->fullMetadata();
		$metadata['issuer'] = '';

		$this->expectException(AuthenticationException::class);
		OidcDiscoveryDocument::fromArray($metadata);
	}

	public function testRequiredEndpointAccessorsThrowWhenTheProviderOmitsThem(): void
	{
		$document = OidcDiscoveryDocument::fromArray(['issuer' => 'https://idp.example.com']);

		$this->expectException(AuthenticationException::class);
		$this->expectExceptionMessage('does not advertise a "authorization_endpoint"');
		$document->getAuthorizationEndpoint();
	}

	public function testTokenEndpointAccessorThrowsWhenTheProviderOmitsIt(): void
	{
		$document = OidcDiscoveryDocument::fromArray(['issuer' => 'https://idp.example.com']);

		$this->expectException(AuthenticationException::class);
		$this->expectExceptionMessage('token_endpoint');
		$document->getTokenEndpoint();
	}

	public function testJwksUriAccessorThrowsWhenTheProviderOmitsIt(): void
	{
		$document = OidcDiscoveryDocument::fromArray(['issuer' => 'https://idp.example.com']);

		$this->expectException(AuthenticationException::class);
		$this->expectExceptionMessage('jwks_uri');
		$document->getJwksUri();
	}

	public function testRequiredEndpointAccessorsRejectANonStringValue(): void
	{
		$document = OidcDiscoveryDocument::fromArray(['issuer' => 'https://idp.example.com', 'token_endpoint' => 42]);

		$this->expectException(AuthenticationException::class);
		$document->getTokenEndpoint();
	}

	public function testOptionalEndpointAccessorsReturnNullWhenAbsentOrUnusable(): void
	{
		$document = OidcDiscoveryDocument::fromArray([
			'issuer' => 'https://idp.example.com',
			'introspection_endpoint' => '',
			'revocation_endpoint' => 1234,
		]);

		$this->assertNull($document->getUserinfoEndpoint());
		$this->assertNull($document->getIntrospectionEndpoint());
		$this->assertNull($document->getRevocationEndpoint());
		$this->assertNull($document->getEndSessionEndpoint());
	}

	public function testListAccessorsDropNonStringEntriesAndReindex(): void
	{
		$document = OidcDiscoveryDocument::fromArray([
			'issuer' => 'https://idp.example.com',
			'scopes_supported' => ['openid', 42, 'email', null],
			'response_types_supported' => 'code',
		]);

		$this->assertSame(['openid', 'email'], $document->getScopesSupported());
		$this->assertSame([], $document->getResponseTypesSupported());
	}

	public function testSupportsPkceS256WhenAdvertised(): void
	{
		$document = OidcDiscoveryDocument::fromArray([
			'issuer' => 'https://idp.example.com',
			'code_challenge_methods_supported' => ['plain', 'S256'],
		]);

		$this->assertTrue($document->supportsPkceS256());
	}

	public function testSupportsPkceS256TreatsAnAbsentMemberAsUnknownButAllowed(): void
	{
		$document = OidcDiscoveryDocument::fromArray(['issuer' => 'https://idp.example.com']);

		$this->assertTrue($document->supportsPkceS256());
	}

	public function testSupportsPkceS256IsFalseWhenTheProviderAdvertisesOnlyPlain(): void
	{
		$document = OidcDiscoveryDocument::fromArray([
			'issuer' => 'https://idp.example.com',
			'code_challenge_methods_supported' => ['plain'],
		]);

		$this->assertFalse($document->supportsPkceS256());
	}
}
