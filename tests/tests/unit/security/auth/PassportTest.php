<?php

use PHPUnit\Framework\TestCase;
use Quiote\Security\Auth\ClientType;
use Quiote\Security\Auth\Passport;
use Quiote\Security\Auth\TokenClaims;
use Quiote\Security\Auth\UserIdentity;

class InMemoryUserIdentity implements UserIdentity
{
	/** @param array<int, string> $roles */
	public function __construct(private readonly string $identifier, private readonly array $roles = [])
	{
	}

	public function getIdentifier(): string
	{
		return $this->identifier;
	}

	/** @return array<int, string> */
	public function getRoles(): array
	{
		return $this->roles;
	}
}

class PassportTest extends TestCase
{
	public function testDefaultsToNonStatelessWithNoCredentialsOrClaims(): void
	{
		$identity = new InMemoryUserIdentity('alice@example.com');
		$passport = new Passport($identity);

		$this->assertSame($identity, $passport->getIdentity());
		$this->assertSame([], $passport->getCredentials());
		$this->assertFalse($passport->isStateless());
		$this->assertNull($passport->getClaims());
	}

	public function testCarriesCredentialsStatelessFlagAndClaims(): void
	{
		$identity = new InMemoryUserIdentity('service-client', ['role_service']);
		$claims = new TokenClaims('service-client', ['sub' => 'service-client'], ClientType::Service);

		$passport = new Passport($identity, ['role_service'], true, $claims);

		$this->assertSame(['role_service'], $passport->getCredentials());
		$this->assertTrue($passport->isStateless());
		$this->assertSame($claims, $passport->getClaims());
	}
}
