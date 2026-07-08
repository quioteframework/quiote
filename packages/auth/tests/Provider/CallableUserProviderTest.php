<?php

use PHPUnit\Framework\TestCase;
use Quiote\Security\Auth\ClientType;
use Quiote\Security\Auth\Identity\InMemoryUserIdentity;
use Quiote\Security\Auth\Provider\CallableUserProvider;
use Quiote\Security\Auth\TokenClaims;

class CallableUserProviderTest extends TestCase
{
	public function testLoadByIdentifierDelegatesToTheCallable(): void
	{
		$provider = new CallableUserProvider(
			fn(string $identifier) => $identifier === 'alice' ? new InMemoryUserIdentity('alice', 'hash') : null,
		);

		$identity = $provider->loadByIdentifier('alice');
		$this->assertNotNull($identity);
		$this->assertSame('alice', $identity->getIdentifier());
		$this->assertNull($provider->loadByIdentifier('bob'));
	}

	public function testLoadByTokenReturnsNullWhenNoCallableProvided(): void
	{
		$provider = new CallableUserProvider(fn(string $identifier) => null);

		$claims = new TokenClaims('alice', [], ClientType::User);

		$this->assertNull($provider->loadByToken($claims));
	}

	public function testLoadByTokenDelegatesToTheCallableWhenProvided(): void
	{
		$provider = new CallableUserProvider(
			fn(string $identifier) => null,
			fn(TokenClaims $claims) => new InMemoryUserIdentity($claims->getSubject(), 'hash'),
		);

		$claims = new TokenClaims('service-1', [], ClientType::Service);

		$identity = $provider->loadByToken($claims);
		$this->assertNotNull($identity);
		$this->assertSame('service-1', $identity->getIdentifier());
	}
}
