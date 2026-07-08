<?php

use PHPUnit\Framework\Attributes\After;
use PHPUnit\Framework\Attributes\Before;
use PHPUnit\Framework\TestCase;
use Quiote\Context;
use Quiote\Plugin\PluginManager;
use Quiote\Security\Auth\ClientType;
use Quiote\Security\Auth\ClientTypeResolver;
use Quiote\Security\Auth\ClientTypeResolverInterface;
use Quiote\Security\Auth\JwtAuthPlugin;
use Quiote\Testing\UnitTestCase;

final class JwtAuthPluginTest extends UnitTestCase
{
	#[Before]
	#[After]
	public function resetState(): void
	{
		PluginManager::reset();
	}

	public function testRegistersTheDefaultClientTypeResolver(): void
	{
		PluginManager::add(new JwtAuthPlugin());
		PluginManager::bootFromConfig();

		// A fresh, uniquely-named context: PluginManager::addContainerService()
		// only applies to containers built *after* registration, and the
		// default (unnamed) context may already have been built by an
		// earlier test in the same process.
		$context = Context::getInstance('jwt-auth-plugin-test');
		$resolver = $context->getContainer()->get(ClientTypeResolverInterface::class);

		$this->assertInstanceOf(ClientTypeResolver::class, $resolver);
		$this->assertSame(ClientType::User, $resolver->resolve(['sub' => 'user-1']));
	}
}
