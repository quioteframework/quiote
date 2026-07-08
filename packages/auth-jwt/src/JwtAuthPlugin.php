<?php
namespace Quiote\Security\Auth;

use Quiote\Plugin\Attribute\Plugin as PluginAttribute;
use Quiote\Plugin\PluginInterface;
use Quiote\Plugin\PluginRegistrar;

/**
 * Registers the default {@see ClientTypeResolverInterface} (the RFC 9068
 * rule -- see {@see ClientTypeResolver}). `TokenValidatorInterface`/
 * `BearerTokenAuthenticator` are not given framework-wide defaults here:
 * they need app-specific secrets (issuer, audience, JWKS URI or shared
 * key), so an app constructs and registers those itself -- typically
 * inside its own plugin, alongside a `FirewallMap` built with
 * `Quiote\Security\Auth\Config\FirewallFactory`.
 * @since      1.0.0
 */
#[PluginAttribute(name: 'quiote/auth-jwt')]
final class JwtAuthPlugin implements PluginInterface
{
	/**
	 * @param      PluginRegistrar $registrar The framework's plugin-contribution API.
	 * @return     void
	 * @since      1.0.0
	 */
	public function register(PluginRegistrar $registrar): void
	{
		$registrar->service(ClientTypeResolverInterface::class, static fn() => new ClientTypeResolver());
	}
}
