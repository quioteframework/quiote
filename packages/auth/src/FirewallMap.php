<?php
namespace Quiote\Security\Auth;

/**
 * An ordered list of {@see Firewall} definitions, matched by request path.
 * Shared by `Quiote\Security\Auth\Middleware\StatelessAuthenticationMiddleware`
 * and `Quiote\Security\Auth\Middleware\SessionAuthenticationMiddleware` so
 * both middleware placements agree on the same firewall configuration.
 * @since      1.0.0
 */
final class FirewallMap
{
	/**
	 * @param      Firewall[] $firewalls Checked in order; the first match wins.
	 * @since      1.0.0
	 */
	public function __construct(private readonly array $firewalls)
	{
	}

	/**
	 * @param      string $path The request path to match (e.g. `$request->getUri()->getPath()`).
	 * @return     ?Firewall The first firewall whose pattern matches $path, or null if none match.
	 * @since      1.0.0
	 */
	public function match(string $path): ?Firewall
	{
		foreach($this->firewalls as $firewall) {
			if($firewall->matches($path)) {
				return $firewall;
			}
		}
		return null;
	}

	/**
	 * @return     Firewall[] Every firewall in this map, in match order.
	 * @since      1.0.0
	 */
	public function all(): array
	{
		return $this->firewalls;
	}
}
