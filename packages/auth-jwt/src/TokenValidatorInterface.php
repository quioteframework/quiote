<?php
namespace Quiote\Security\Auth;

/**
 * Verifies a bearer token's signature and standard time claims
 * (`exp`/`nbf`/`iat`) and returns its raw claim set. Implementations own
 * algorithm allow-listing and key resolution (shared secret, JWKS, ...);
 * `iss`/`aud` pinning must also be enforced by the implementation, per
 * RFC 8725 -- a JWS library only guarantees signature and time validity,
 * never audience restriction.
 * @since      1.0.0
 */
interface TokenValidatorInterface
{
	/**
	 * @param      string $token The raw, encoded token (e.g. the value after `Bearer `).
	 * @return     array<string, mixed> The validated, raw claim set.
	 * @throws     AuthenticationException If the token is malformed, expired, or fails signature/iss/aud checks.
	 * @since      1.0.0
	 */
	public function validate(string $token): array;
}
