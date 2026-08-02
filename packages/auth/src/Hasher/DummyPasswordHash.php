<?php
namespace Quiote\Security\Auth\Hasher;

use Quiote\Security\Auth\PasswordHasherInterface;
use WeakMap;

/**
 * A valid hash of a value no submitted password can match, used to spend the
 * same key-derivation time on an unknown identifier as on a known one.
 *
 * Without it, an authenticator that writes the natural
 * `$identity === null || !verify(...)` short-circuits: an unknown identifier
 * returns after a single indexed SELECT while a known one pays a full argon2id
 * derivation. That difference is tens of milliseconds and is measurable over
 * the network, which makes it a reliable account-enumeration oracle. Verifying
 * against this value on the no-identity path costs both branches exactly one
 * derivation.
 *
 * The hash is produced by the caller's own {@see PasswordHasherInterface}, not
 * by a hardcoded `password_hash()` call, so it lands in the configured
 * algorithm at the configured cost -- a bcrypt-cost-13 deployment needs a
 * bcrypt-cost-13 dummy, or the two branches diverge again in the other
 * direction. It must also be a well-formed hash in that algorithm's own format,
 * or `verify()` would reject it as malformed without deriving anything.
 *
 * Memoized per hasher instance (the derivation is the very cost being matched,
 * so paying it once per process is the point). A {@see WeakMap} rather than an
 * `spl_object_id()`-keyed array: object ids are reused after collection, and a
 * reused id would hand back a dummy built for a hasher that no longer exists,
 * possibly at a different cost.
 * @since      3.0.4
 */
final class DummyPasswordHash
{
	/** @var ?WeakMap<PasswordHasherInterface, string> */
	private static ?WeakMap $cache = null;

	private function __construct()
	{
	}

	/**
	 * @param      PasswordHasherInterface $hasher The hasher whose algorithm/cost the dummy must match.
	 * @return     string A hash no submitted password can match.
	 * @since      3.0.4
	 */
	public static function for(PasswordHasherInterface $hasher): string
	{
		/** @var WeakMap<PasswordHasherInterface, string> $cache */
		$cache = self::$cache ??= new WeakMap();
		if(!isset($cache[$hasher])) {
			$cache[$hasher] = $hasher->hash(base64_encode(random_bytes(32)));
		}

		return $cache[$hasher];
	}

	/**
	 * Drop the memo. Test isolation only -- a suite that swaps hasher
	 * configurations within one process would otherwise keep matching against
	 * the first one's cost.
	 * @return     void
	 * @since      3.0.4
	 */
	public static function reset(): void
	{
		self::$cache = null;
	}
}
