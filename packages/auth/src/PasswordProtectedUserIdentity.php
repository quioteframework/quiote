<?php
namespace Quiote\Security\Auth;

/**
 * A {@see UserIdentity} that can be checked against a password, resolved by
 * `InMemoryUserProvider`/`PdoUserProvider`/`CallableUserProvider` and
 * consumed by `FormLoginAuthenticator`/`HttpBasicAuthenticator` via
 * {@see PasswordHasherInterface}.
 * @since      1.0.0
 */
interface PasswordProtectedUserIdentity extends UserIdentity
{
	/**
	 * @return     string The stored password hash, suitable for {@see PasswordHasherInterface::verify()}.
	 * @since      1.0.0
	 */
	public function getPasswordHash(): string;
}
