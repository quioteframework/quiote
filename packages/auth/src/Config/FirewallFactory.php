<?php
namespace Quiote\Security\Auth\Config;

use Quiote\Security\Auth\AuthenticatorInterface;
use Quiote\Security\Auth\EntryPointInterface;
use Quiote\Security\Auth\Firewall;
use Quiote\Security\Auth\FirewallMap;
use RuntimeException;

/**
 * Builds a live {@see FirewallMap} from {@see SecurityConfigHandler}'s
 * canonical array, resolving each firewall's `<authenticator ref="...">`
 * and `entry-point` against explicit registries the app assembles itself
 * -- no implicit global service-locator lookups, so wiring stays visible
 * and testable. Apps that assemble firewalls purely in PHP never need
 * this class or `security.xml` at all.
 * @since      1.0.0
 */
final class FirewallFactory
{
	/**
	 * @param      array<string, AuthenticatorInterface> $authenticators Keyed by the `ref` used in security.xml.
	 * @param      array<string, EntryPointInterface> $entryPoints Keyed by `entry-point` (e.g. "login", "challenge").
	 * @since      1.0.0
	 */
	public function __construct(
		private readonly array $authenticators,
		private readonly array $entryPoints,
	) {
	}

	/**
	 * @param      array{firewalls: array<string, array{pattern: string, stateless: bool, sessionless: bool, entry_point: ?string, provider: ?string, authenticators: array<int, string>}>} $config The canonical array from {@see SecurityConfigHandler::toCanonicalArray()}.
	 * @return     FirewallMap The resulting firewalls, in the same order as $config.
	 * @throws     RuntimeException If a firewall references an authenticator ref or entry-point key not present in the constructor's registries.
	 * @since      1.0.0
	 */
	public function build(array $config): FirewallMap
	{
		$firewalls = [];
		foreach($config['firewalls'] as $name => $definition) {
			$authenticators = [];
			foreach($definition['authenticators'] as $ref) {
				if(!isset($this->authenticators[$ref])) {
					throw new RuntimeException(sprintf('Firewall "%s" references unknown authenticator "%s".', $name, $ref));
				}
				$authenticators[] = $this->authenticators[$ref];
			}

			$entryPointKey = $definition['entry_point'] ?? 'login';
			if(!isset($this->entryPoints[$entryPointKey])) {
				throw new RuntimeException(sprintf('Firewall "%s" references unknown entry point "%s".', $name, $entryPointKey));
			}

			$firewalls[] = new Firewall(
				$name,
				$definition['pattern'],
				$authenticators,
				$this->entryPoints[$entryPointKey],
				$definition['stateless'],
				$definition['sessionless'],
			);
		}
		return new FirewallMap($firewalls);
	}
}
