<?php
namespace Quiote\Security\Auth\Identity;

use Quiote\Security\Auth\PasswordProtectedUserIdentity;

/**
 * A plain value-object {@see PasswordProtectedUserIdentity}, returned by
 * every foundation `UserProviderInterface` implementation
 * (`InMemoryUserProvider`, `PdoUserProvider`, `CallableUserProvider`).
 * @since      1.0.0
 */
final class InMemoryUserIdentity implements PasswordProtectedUserIdentity
{
	/**
	 * @param      string $identifier The stable identifier (e.g. email/username).
	 * @param      string $passwordHash The stored password hash.
	 * @param      array<int, string> $roles Roles/permissions to grant on the SecurityUser.
	 * @since      1.0.0
	 */
	public function __construct(
		private readonly string $identifier,
		private readonly string $passwordHash,
		private readonly array $roles = [],
	) {
	}

	/**
	 * @return     string This identity's stable identifier.
	 * @since      1.0.0
	 */
	public function getIdentifier(): string
	{
		return $this->identifier;
	}

	/**
	 * @return     string The stored password hash.
	 * @since      1.0.0
	 */
	public function getPasswordHash(): string
	{
		return $this->passwordHash;
	}

	/**
	 * @return     array<int, string> Roles/permissions to grant on the SecurityUser.
	 * @since      1.0.0
	 */
	public function getRoles(): array
	{
		return $this->roles;
	}
}
