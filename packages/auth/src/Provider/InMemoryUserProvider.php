<?php
namespace Quiote\Security\Auth\Provider;

use Quiote\Security\Auth\Identity\InMemoryUserIdentity;
use Quiote\Security\Auth\TokenClaims;
use Quiote\Security\Auth\UserIdentity;
use Quiote\Security\Auth\UserProviderInterface;

/**
 * A config-driven `UserProviderInterface`, for apps with a small, static
 * user list (the `security.xml` `<provider type="in_memory">` shape).
 * @since      1.0.0
 */
final class InMemoryUserProvider implements UserProviderInterface
{
	/** @var array<string, InMemoryUserIdentity> */
	private readonly array $usersByIdentifier;

	/**
	 * @param      array<string, array{password_hash: string, roles?: array<int, string>}> $users Keyed by identifier (e.g. email/username).
	 * @since      1.0.0
	 */
	public function __construct(array $users)
	{
		$byIdentifier = [];
		foreach($users as $identifier => $definition) {
			$byIdentifier[$identifier] = new InMemoryUserIdentity(
				$identifier,
				$definition['password_hash'],
				$definition['roles'] ?? [],
			);
		}
		$this->usersByIdentifier = $byIdentifier;
	}

	/**
	 * @param      string $identifier E.g. an email or username.
	 * @return     ?UserIdentity Null if no matching identity exists.
	 * @since      1.0.0
	 */
	public function loadByIdentifier(string $identifier): ?UserIdentity
	{
		return $this->usersByIdentifier[$identifier] ?? null;
	}

	/**
	 * @param      TokenClaims $claims The validated token claims.
	 * @return     null Always null: a static, config-driven user list has no token/claim mapping;
	 *                  token-derived identity resolution belongs to a Pdo/CallableUserProvider.
	 * @since      1.0.0
	 */
	public function loadByToken(TokenClaims $claims): ?UserIdentity
	{
		return null;
	}
}
