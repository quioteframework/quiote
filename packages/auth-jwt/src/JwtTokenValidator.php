<?php
namespace Quiote\Security\Auth;

use Firebase\JWT\CachedKeySet;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Throwable;

/**
 * Verifies a JWS via `firebase/php-jwt` (JWKS + rotation via
 * {@see CachedKeySet} for RS256/ES256, or a single {@see Key} for a shared
 * HS256 secret) and enforces `iss`/`aud` -- the library itself only checks
 * `exp`/`nbf`/`iat`. Callers are responsible for binding $key to
 * `RS256`/`ES256` only (never mixing in a symmetric key), which is a
 * property of how $key is constructed, not this class.
 * @since      1.0.0
 */
final class JwtTokenValidator implements TokenValidatorInterface
{
	/**
	 * @param      Key|array<string, Key>|CachedKeySet $key A single key (shared HS256 secret), a kid-keyed array of keys, or a JWKS-backed {@see CachedKeySet}.
	 * @param      string $issuer The expected `iss` claim (the token authority).
	 * @param      string $audience The expected `aud` claim (this resource's identifier).
	 * @param      int $leeway Clock-skew allowance in seconds applied to `exp`/`nbf`/`iat` (~60 is a reasonable default).
	 * @since      1.0.0
	 */
	public function __construct(
		private readonly Key|array|CachedKeySet $key,
		private readonly string $issuer,
		private readonly string $audience,
		private readonly int $leeway = 60,
	) {
	}

	/**
	 * @param      string $token The raw, encoded token (e.g. the value after `Bearer `).
	 * @return     array<string, mixed> The validated, raw claim set.
	 * @throws     AuthenticationException If the token is malformed, expired, or fails signature/iss/aud checks.
	 * @since      1.0.0
	 */
	public function validate(string $token): array
	{
		$previousLeeway = JWT::$leeway;
		JWT::$leeway = $this->leeway;

		try {
			$decoded = JWT::decode($token, $this->key);
		} catch(Throwable $e) {
			throw new AuthenticationException('Invalid token: ' . $e->getMessage(), previous: $e);
		} finally {
			JWT::$leeway = $previousLeeway;
		}

		$decodedArray = json_decode((string) json_encode($decoded), true);
		if(!is_array($decodedArray)) {
			throw new AuthenticationException('Token payload could not be decoded.');
		}

		/** @var array<string, mixed> $claims */
		$claims = [];
		foreach($decodedArray as $key => $value) {
			$claims[(string) $key] = $value;
		}

		$this->assertIssuer($claims);
		$this->assertAudience($claims);

		return $claims;
	}

	/**
	 * @param      array<string, mixed> $claims The token's decoded claim set.
	 * @return     void
	 * @throws     AuthenticationException If the `iss` claim does not match the configured issuer.
	 * @since      1.0.0
	 */
	private function assertIssuer(array $claims): void
	{
		if(($claims['iss'] ?? null) !== $this->issuer) {
			throw new AuthenticationException('Token issuer does not match the configured authority.');
		}
	}

	/**
	 * @param      array<string, mixed> $claims The token's decoded claim set.
	 * @return     void
	 * @throws     AuthenticationException If the `aud` claim does not include the configured audience.
	 * @since      1.0.0
	 */
	private function assertAudience(array $claims): void
	{
		$aud = $claims['aud'] ?? null;
		$audiences = is_array($aud) ? $aud : [$aud];
		if(!in_array($this->audience, $audiences, true)) {
			throw new AuthenticationException('Token audience does not match this resource.');
		}
	}
}
