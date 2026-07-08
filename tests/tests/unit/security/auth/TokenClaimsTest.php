<?php

use PHPUnit\Framework\TestCase;
use Quiote\Security\Auth\ClientType;
use Quiote\Security\Auth\TokenClaims;

class TokenClaimsTest extends TestCase
{
	public function testAccessorsExposeSubjectClaimsAndClientType(): void
	{
		$claims = new TokenClaims('user-123', ['sub' => 'user-123', 'scope' => 'read'], ClientType::User);

		$this->assertSame('user-123', $claims->getSubject());
		$this->assertSame(['sub' => 'user-123', 'scope' => 'read'], $claims->getClaims());
		$this->assertSame(ClientType::User, $claims->getClientType());
		$this->assertFalse($claims->isService());
	}

	public function testIsServiceTrueForServiceClientType(): void
	{
		$claims = new TokenClaims('client-abc', ['sub' => 'client-abc'], ClientType::Service);

		$this->assertTrue($claims->isService());
	}

	public function testGetClaimReturnsValueWhenPresent(): void
	{
		$claims = new TokenClaims('user-123', ['aud' => 'api'], ClientType::User);

		$this->assertSame('api', $claims->getClaim('aud'));
	}

	public function testGetClaimReturnsDefaultWhenMissing(): void
	{
		$claims = new TokenClaims('user-123', [], ClientType::User);

		$this->assertNull($claims->getClaim('missing'));
		$this->assertSame('fallback', $claims->getClaim('missing', 'fallback'));
	}
}
