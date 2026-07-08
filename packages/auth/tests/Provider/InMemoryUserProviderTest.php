<?php

use PHPUnit\Framework\TestCase;
use Quiote\Security\Auth\ClientType;
use Quiote\Security\Auth\PasswordProtectedUserIdentity;
use Quiote\Security\Auth\Provider\InMemoryUserProvider;
use Quiote\Security\Auth\TokenClaims;

class InMemoryUserProviderTest extends TestCase
{
	public function testLoadByIdentifierReturnsAMatchingIdentity(): void
	{
		$provider = new InMemoryUserProvider([
			'alice@example.com' => ['password_hash' => 'hash1', 'roles' => ['admin']],
		]);

		$identity = $provider->loadByIdentifier('alice@example.com');

		$this->assertInstanceOf(PasswordProtectedUserIdentity::class, $identity);
		$this->assertSame('alice@example.com', $identity->getIdentifier());
		$this->assertSame('hash1', $identity->getPasswordHash());
		$this->assertSame(['admin'], $identity->getRoles());
	}

	public function testLoadByIdentifierReturnsNullForUnknownUser(): void
	{
		$provider = new InMemoryUserProvider([]);

		$this->assertNull($provider->loadByIdentifier('nobody@example.com'));
	}

	public function testLoadByTokenAlwaysReturnsNull(): void
	{
		$provider = new InMemoryUserProvider(['alice@example.com' => ['password_hash' => 'hash1']]);

		$claims = new TokenClaims('alice@example.com', [], ClientType::User);

		$this->assertNull($provider->loadByToken($claims));
	}

	public function testRolesDefaultToEmptyArray(): void
	{
		$provider = new InMemoryUserProvider(['bob@example.com' => ['password_hash' => 'hash2']]);

		$identity = $provider->loadByIdentifier('bob@example.com');
		$this->assertNotNull($identity);
		$this->assertSame([], $identity->getRoles());
	}
}
