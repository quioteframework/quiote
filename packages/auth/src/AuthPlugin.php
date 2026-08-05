<?php
namespace Quiote\Security\Auth;

use Quiote\Context;
use Quiote\Controller\Controller;
use Quiote\DI\Container;
use Quiote\Middleware\RoutingMiddleware;
use Quiote\Middleware\SecurityMiddleware;
use Quiote\Middleware\SessionMiddleware;
use Quiote\Plugin\Attribute\Plugin as PluginAttribute;
use Quiote\Plugin\PluginInterface;
use Quiote\Plugin\PluginRegistrar;
use Quiote\Security\Auth\Hasher\DefaultPasswordHasher;
use Quiote\Security\Auth\Middleware\SessionAuthenticationMiddleware;
use Quiote\Security\Auth\Middleware\StatelessAuthenticationMiddleware;

/**
 * Registers the authentication foundation: a default
 * {@see PasswordHasherInterface}, an empty (no-op) default
 * {@see FirewallMap}, and two `AuthenticationMiddleware` placements
 * ({@see StatelessAuthenticationMiddleware} before
 * `Quiote\Middleware\SessionMiddleware`, so a machine token can flip a
 * request to sessionless before session startup; {@see SessionAuthenticationMiddleware}
 * before `Quiote\Middleware\SecurityMiddleware`, so a successful login is
 * visible to the same request's authorization decision).
 *
 * The default `FirewallMap` has zero firewalls, so both middleware are a
 * complete no-op until an app registers its own `FirewallMap` (built
 * either by hand or via {@see \Quiote\Security\Auth\Config\FirewallFactory}
 * from a `security.xml`) as an earlier-registered `service()` -- see
 * `PluginRegistrar::service()`'s set-if-absent, first-plugin-wins rule.
 * @since      1.0.0
 */
#[PluginAttribute(name: 'quiote/auth')]
final class AuthPlugin implements PluginInterface
{
	/**
	 * @param      PluginRegistrar $registrar The framework's plugin-contribution API.
	 * @return     void
	 * @since      1.0.0
	 */
	public function register(PluginRegistrar $registrar): void
	{
		$registrar->service(
			PasswordHasherInterface::class,
			static fn() => new DefaultPasswordHasher(),
			Container::SCOPE_SINGLETON,
		);

		// The map is configuration an application replaces or adds to at boot; rebuilding it per request
		// would drop whatever was added to it.
		$registrar->service(FirewallMap::class, static fn() => new FirewallMap([]), Container::SCOPE_SINGLETON);

		$registrar->service(
			AuthenticationManager::class,
			static fn(Container $container) => new AuthenticationManager(self::resolveController($container)),
			Container::SCOPE_SINGLETON,
		);

		$registrar->middleware(
			StatelessAuthenticationMiddleware::class,
			static fn(Context $context) => new StatelessAuthenticationMiddleware(
				self::resolveFirewallMap($context->getContainer()),
				self::resolveAuthenticationManager($context->getContainer()),
			),
			before: SessionMiddleware::class,
		);

		$registrar->middleware(
			SessionAuthenticationMiddleware::class,
			static fn(Context $context) => new SessionAuthenticationMiddleware(
				self::resolveFirewallMap($context->getContainer()),
				self::resolveAuthenticationManager($context->getContainer()),
			),
			after: RoutingMiddleware::class,
			before: SecurityMiddleware::class,
		);
	}

	/**
	 * @param      Container $container The context's DI container.
	 * @return     Controller The container's `Controller` service.
	 * @since      1.0.0
	 */
	private static function resolveController(Container $container): Controller
	{
		// No type guard: the container refuses to answer a class name with anything that is not an
		// instance of it, so a wrong binding throws there and says which id it was.
		return $container->get(Controller::class);
	}

	/**
	 * @param      Container $container The context's DI container.
	 * @return     FirewallMap The container's `FirewallMap` service.
	 * @since      1.0.0
	 */
	private static function resolveFirewallMap(Container $container): FirewallMap
	{
		return $container->get(FirewallMap::class);
	}

	/**
	 * @param      Container $container The context's DI container.
	 * @return     AuthenticationManager The container's `AuthenticationManager` service.
	 * @since      1.0.0
	 */
	private static function resolveAuthenticationManager(Container $container): AuthenticationManager
	{
		return $container->get(AuthenticationManager::class);
	}
}
