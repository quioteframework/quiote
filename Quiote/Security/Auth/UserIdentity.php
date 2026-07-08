<?php
namespace Quiote\Security\Auth;

/**
 * The identity a {@see UserProviderInterface} resolves a credential to,
 * before it is mapped onto a `Quiote\User\SecurityUser`/`RbacSecurityUser`
 * by `Quiote\Security\Auth\AuthenticationManager` (`packages/auth`).
 * Deliberately minimal: password
 * hash/roles/credentials are provider-specific concerns exposed through
 * whatever shape the provider's own backend needs; this contract only
 * guarantees a stable identifier to key session/credential storage on.
 * @since      1.0.0
 */
interface UserIdentity
{
	/**
	 * The value {@see UserProviderInterface::loadByIdentifier()} was called
	 * with (e.g. an email or username) — stable across requests.
	 * @return     string This identity's stable identifier.
	 * @since      1.0.0
	 */
	public function getIdentifier(): string;

	/**
	 * @return     array<int, string> Role/permission credentials to grant on the SecurityUser.
	 * @since      1.0.0
	 */
	public function getRoles(): array;
}
