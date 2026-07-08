<?php
namespace Quiote\Security\Auth\Hasher;

use InvalidArgumentException;
use Quiote\Security\Auth\PasswordHasherInterface;

/**
 * Thin wrapper over PHP's `password_hash()` family: argon2id by default,
 * falling back to bcrypt only when the running PHP build lacks argon2
 * support (no libargon2 at compile time).
 * @since      1.0.0
 */
final class DefaultPasswordHasher implements PasswordHasherInterface
{
	private readonly string $algorithm;

	/**
	 * @param      ?string $algorithm One of the `PASSWORD_*` constants; defaults to argon2id (or bcrypt if unavailable).
	 * @param      array<string, mixed> $options Passed through to `password_hash()`/`password_needs_rehash()`.
	 * @throws     InvalidArgumentException If $algorithm is neither `PASSWORD_BCRYPT` nor (when available) `PASSWORD_ARGON2ID`.
	 * @since      1.0.0
	 */
	public function __construct(?string $algorithm = null, private readonly array $options = [])
	{
		$algorithm ??= defined('PASSWORD_ARGON2ID') ? PASSWORD_ARGON2ID : PASSWORD_BCRYPT;
		$supported = [PASSWORD_BCRYPT];
		if(defined('PASSWORD_ARGON2ID')) {
			$supported[] = PASSWORD_ARGON2ID;
		}
		if(!in_array($algorithm, $supported, true)) {
			throw new InvalidArgumentException(sprintf('Unsupported password hashing algorithm "%s".', $algorithm));
		}
		$this->algorithm = $algorithm;
	}

	/**
	 * @param      string $plaintext The plaintext password to hash.
	 * @return     string The resulting hash, suitable for storage.
	 * @since      1.0.0
	 */
	public function hash(string $plaintext): string
	{
		return password_hash($plaintext, $this->algorithm, $this->options);
	}

	/**
	 * @param      string $plaintext The plaintext password to check.
	 * @param      string $hash A previously-stored hash (see hash()).
	 * @return     bool True if $plaintext matches $hash, otherwise false.
	 * @since      1.0.0
	 */
	public function verify(string $plaintext, string $hash): bool
	{
		return password_verify($plaintext, $hash);
	}

	/**
	 * @param      string $hash A previously-stored hash (see hash()).
	 * @return     bool True if $hash was produced with weaker-than-current-default parameters, otherwise false.
	 * @since      1.0.0
	 */
	public function needsRehash(string $hash): bool
	{
		return password_needs_rehash($hash, $this->algorithm, $this->options);
	}
}
