<?php
namespace Quiote\Security\Auth;

/**
 * Thin contract over PHP's `password_hash()` family, so
 * `FormLoginAuthenticator`/`HttpBasicAuthenticator` (both in the future
 * `packages/auth`) depend on an interface rather than the global
 * functions directly. Default implementation: argon2id, bcrypt fallback.
 * @since      1.0.0
 */
interface PasswordHasherInterface
{
	/**
	 * @param      string $plaintext The plaintext password to hash.
	 * @return     string The resulting hash, suitable for storage.
	 * @since      1.0.0
	 */
	public function hash(string $plaintext): string;

	/**
	 * @param      string $plaintext The plaintext password to check.
	 * @param      string $hash A previously-stored hash (see hash()).
	 * @return     bool True if $plaintext matches $hash, otherwise false.
	 * @since      1.0.0
	 */
	public function verify(string $plaintext, string $hash): bool;

	/**
	 * True if $hash was produced with weaker-than-current-default
	 * parameters (algorithm/cost) and should be re-hashed on next
	 * successful verify.
	 * @param      string $hash A previously-stored hash (see hash()).
	 * @return     bool True if $hash should be re-hashed, otherwise false.
	 * @since      1.0.0
	 */
	public function needsRehash(string $hash): bool;
}
