<?php

use PHPUnit\Framework\Attributes\After;
use PHPUnit\Framework\Attributes\Before;
use PHPUnit\Framework\TestCase;
use Quiote\Middleware\MiddlewareCatalog;
use Quiote\Middleware\RoutingMiddleware;
use Quiote\Middleware\SecurityMiddleware;
use Quiote\Middleware\SessionMiddleware;
use Quiote\Plugin\PluginManager;
use Quiote\Security\Auth\AuthPlugin;
use Quiote\Security\Auth\Middleware\SessionAuthenticationMiddleware;
use Quiote\Security\Auth\Middleware\StatelessAuthenticationMiddleware;

/**
 * AuthPlugin::register() -- the default FirewallMap has zero firewalls, so
 * installing packages/auth alone must never change behavior for an app that
 * hasn't configured any firewalls yet.
 */
final class AuthPluginTest extends TestCase
{
	#[Before]
	#[After]
	public function resetState(): void
	{
		PluginManager::reset();
		MiddlewareCatalog::reset();
	}

	public function testRegistersBothAuthenticationMiddlewareAtTheCorrectPositions(): void
	{
		PluginManager::add(new AuthPlugin());
		PluginManager::bootFromConfig();

		$registered = MiddlewareCatalog::getRegistered();

		$this->assertArrayHasKey(StatelessAuthenticationMiddleware::class, $registered);
		$this->assertSame(SessionMiddleware::class, $registered[StatelessAuthenticationMiddleware::class]['before']);

		$this->assertArrayHasKey(SessionAuthenticationMiddleware::class, $registered);
		$this->assertSame(RoutingMiddleware::class, $registered[SessionAuthenticationMiddleware::class]['after']);
		$this->assertSame(SecurityMiddleware::class, $registered[SessionAuthenticationMiddleware::class]['before']);
	}
}
