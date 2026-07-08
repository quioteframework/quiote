<?php
namespace Quiote\Security\Auth;

/**
 * Validated claims from a bearer/JWT/OIDC token, plus the {@see ClientType}
 * derived from them by a {@see ClientTypeResolverInterface}. Immutable
 * value object; produced by a token validator, consumed by
 * {@see UserProviderInterface::loadByToken()}.
 * @since      1.0.0
 */
final class TokenClaims
{
	/**
	 * @param string $subject The token's `sub` claim.
	 * @param array<string, mixed> $claims The full, already-validated claim set.
	 * @param ClientType $clientType Human vs. machine, per RFC 9068.
	 */
	public function __construct(
		private readonly string $subject,
		private readonly array $claims,
		private readonly ClientType $clientType,
	) {
	}

	/**
	 * @return     string The token's `sub` claim.
	 * @since      1.0.0
	 */
	public function getSubject(): string
	{
		return $this->subject;
	}

	/**
	 * @return     array<string, mixed> The full, already-validated claim set.
	 * @since      1.0.0
	 */
	public function getClaims(): array
	{
		return $this->claims;
	}

	/**
	 * @return     ClientType Human vs. machine, per RFC 9068.
	 * @since      1.0.0
	 */
	public function getClientType(): ClientType
	{
		return $this->clientType;
	}

	/**
	 * @return     bool True if getClientType() is {@see ClientType::Service}, otherwise false.
	 * @since      1.0.0
	 */
	public function isService(): bool
	{
		return $this->clientType === ClientType::Service;
	}

	/**
	 * @param      string $name The claim name (e.g. `sub`, `scope`, `aud`).
	 * @param      mixed $default The value to return if $name is absent.
	 * @return     mixed The raw value of a single claim, or $default if absent.
	 * @since      1.0.0
	 */
	public function getClaim(string $name, mixed $default = null): mixed
	{
		return $this->claims[$name] ?? $default;
	}
}
