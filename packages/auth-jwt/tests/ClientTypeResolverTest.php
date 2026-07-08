<?php

use PHPUnit\Framework\TestCase;
use Quiote\Security\Auth\ClientType;
use Quiote\Security\Auth\ClientTypeResolver;

class ClientTypeResolverTest extends TestCase
{
	public function testResolvesServiceWhenSubjectEqualsClientId(): void
	{
		$resolver = new ClientTypeResolver();

		$this->assertSame(ClientType::Service, $resolver->resolve(['sub' => 'client-abc', 'client_id' => 'client-abc']));
	}

	public function testResolvesServiceWhenSubjectEqualsAzp(): void
	{
		$resolver = new ClientTypeResolver();

		$this->assertSame(ClientType::Service, $resolver->resolve(['sub' => 'client-abc', 'azp' => 'client-abc']));
	}

	public function testResolvesUserWhenSubjectDiffersFromClientId(): void
	{
		$resolver = new ClientTypeResolver();

		$this->assertSame(ClientType::User, $resolver->resolve(['sub' => 'user-123', 'client_id' => 'client-abc']));
	}

	public function testResolvesUserWhenThereIsNoClientIdOrAzpClaim(): void
	{
		$resolver = new ClientTypeResolver();

		$this->assertSame(ClientType::User, $resolver->resolve(['sub' => 'user-123']));
	}
}
