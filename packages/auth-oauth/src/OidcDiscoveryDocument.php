<?php
namespace Quiote\Security\Auth;

/**
 * An immutable OpenID Provider metadata document (OpenID Connect Discovery
 * 1.0 §3, a superset of RFC 8414 authorization-server metadata), as fetched
 * by {@see OidcDiscoveryClient}.
 *
 * Only `issuer` is required at construction: everything else is optional in
 * practice, because the same metadata format serves pure OAuth 2.0
 * authorization servers (which may have no `jwks_uri` or
 * `authorization_endpoint`) as well as full OpenID providers. Accessors for
 * the endpoints a flow cannot work without ({@see getAuthorizationEndpoint()},
 * {@see getTokenEndpoint()}, {@see getJwksUri()}) therefore throw rather than
 * return null -- a missing one is a provider misconfiguration the caller
 * cannot paper over, and failing at wiring time beats a null reaching
 * `GenericProvider` as an empty endpoint URL.
 * @since      1.2.5
 */
final class OidcDiscoveryDocument
{
	/**
	 * @param      string $issuer The provider's `issuer` identifier, already verified against the requested issuer.
	 * @param      array<string, mixed> $metadata The full decoded metadata document, including members this class has no accessor for.
	 * @since      1.2.5
	 */
	private function __construct(
		private readonly string $issuer,
		private readonly array $metadata,
	) {
	}

	/**
	 * Validates a decoded metadata document and wraps it.
	 *
	 * When $expectedIssuer is given, the document's own `issuer` must match
	 * it exactly -- OpenID Connect Discovery 1.0 §4.3 requires this check,
	 * and skipping it would let a redirect (or a compromised well-known
	 * path) substitute a different provider's endpoints for the one the app
	 * meant to trust.
	 * @param      array<string, mixed> $metadata The decoded metadata document.
	 * @param      ?string $expectedIssuer The issuer the document was requested for, or null to skip the §4.3 check (only for callers that fetched a non-issuer-derived URL).
	 * @return     self The validated document.
	 * @throws     AuthenticationException If `issuer` is missing/not a string, or does not match $expectedIssuer.
	 * @since      1.2.5
	 */
	public static function fromArray(array $metadata, ?string $expectedIssuer = null): self
	{
		$issuer = $metadata['issuer'] ?? null;
		if(!is_string($issuer) || $issuer === '') {
			throw new AuthenticationException('OIDC discovery document is missing a valid "issuer" member.');
		}

		if($expectedIssuer !== null && !hash_equals($expectedIssuer, $issuer)) {
			throw new AuthenticationException(sprintf('OIDC discovery document issuer "%s" does not match the requested issuer "%s".', $issuer, $expectedIssuer));
		}

		return new self($issuer, $metadata);
	}

	/**
	 * @return     string The provider's `issuer` identifier -- also the expected `iss` claim for tokens it mints.
	 * @since      1.2.5
	 */
	public function getIssuer(): string
	{
		return $this->issuer;
	}

	/**
	 * @return     string The `authorization_endpoint`.
	 * @throws     AuthenticationException If the document does not advertise one.
	 * @since      1.2.5
	 */
	public function getAuthorizationEndpoint(): string
	{
		return $this->requireString('authorization_endpoint');
	}

	/**
	 * @return     string The `token_endpoint`.
	 * @throws     AuthenticationException If the document does not advertise one.
	 * @since      1.2.5
	 */
	public function getTokenEndpoint(): string
	{
		return $this->requireString('token_endpoint');
	}

	/**
	 * @return     string The `jwks_uri`, e.g. to hand to `firebase/php-jwt`'s `CachedKeySet` for ID-token verification.
	 * @throws     AuthenticationException If the document does not advertise one.
	 * @since      1.2.5
	 */
	public function getJwksUri(): string
	{
		return $this->requireString('jwks_uri');
	}

	/**
	 * @return     ?string The `userinfo_endpoint`, or null if the provider does not advertise one.
	 * @since      1.2.5
	 */
	public function getUserinfoEndpoint(): ?string
	{
		return $this->optionalString('userinfo_endpoint');
	}

	/**
	 * @return     ?string The RFC 7662 `introspection_endpoint`, or null if the provider does not advertise one.
	 * @since      1.2.5
	 */
	public function getIntrospectionEndpoint(): ?string
	{
		return $this->optionalString('introspection_endpoint');
	}

	/**
	 * @return     ?string The RFC 7009 `revocation_endpoint`, or null if the provider does not advertise one.
	 * @since      1.2.5
	 */
	public function getRevocationEndpoint(): ?string
	{
		return $this->optionalString('revocation_endpoint');
	}

	/**
	 * @return     ?string The RP-initiated-logout `end_session_endpoint`, or null if the provider does not advertise one.
	 * @since      1.2.5
	 */
	public function getEndSessionEndpoint(): ?string
	{
		return $this->optionalString('end_session_endpoint');
	}

	/**
	 * @return     array<int, string> The `scopes_supported` list, or an empty list if the provider does not advertise it (it is OPTIONAL, so empty does not mean "no scopes").
	 * @since      1.2.5
	 */
	public function getScopesSupported(): array
	{
		return $this->stringList('scopes_supported');
	}

	/**
	 * @return     array<int, string> The `response_types_supported` list, or an empty list if absent.
	 * @since      1.2.5
	 */
	public function getResponseTypesSupported(): array
	{
		return $this->stringList('response_types_supported');
	}

	/**
	 * @return     array<int, string> The `id_token_signing_alg_values_supported` list, or an empty list if absent.
	 * @since      1.2.5
	 */
	public function getIdTokenSigningAlgValuesSupported(): array
	{
		return $this->stringList('id_token_signing_alg_values_supported');
	}

	/**
	 * @return     array<int, string> The `code_challenge_methods_supported` list, or an empty list if absent.
	 * @since      1.2.5
	 */
	public function getCodeChallengeMethodsSupported(): array
	{
		return $this->stringList('code_challenge_methods_supported');
	}

	/**
	 * A pre-flight check for {@see OidcClient}, which hardcodes PKCE S256
	 * because OAuth 2.1 mandates it. A provider that advertises
	 * `code_challenge_methods_supported` without `S256` will reject the
	 * authorization request; one that advertises nothing at all is not
	 * saying it lacks support (the member is OPTIONAL), so absence counts
	 * as unknown-but-allowed here.
	 * @return     bool False only if the provider advertises code-challenge methods and S256 is not among them.
	 * @since      1.2.5
	 */
	public function supportsPkceS256(): bool
	{
		$methods = $this->getCodeChallengeMethodsSupported();
		return $methods === [] || in_array('S256', $methods, true);
	}

	/**
	 * @param      string $member The metadata member name, e.g. `device_authorization_endpoint`.
	 * @return     mixed The raw member value, or null if the document does not contain it.
	 * @since      1.2.5
	 */
	public function get(string $member): mixed
	{
		return $this->metadata[$member] ?? null;
	}

	/**
	 * @return     array<string, mixed> The full decoded metadata document.
	 * @since      1.2.5
	 */
	public function getMetadata(): array
	{
		return $this->metadata;
	}

	/**
	 * @param      string $member The metadata member name.
	 * @return     string The member's value.
	 * @throws     AuthenticationException If the member is absent or not a non-empty string.
	 * @since      1.2.5
	 */
	private function requireString(string $member): string
	{
		$value = $this->optionalString($member);
		if($value === null) {
			throw new AuthenticationException(sprintf('OIDC provider "%s" does not advertise a "%s".', $this->issuer, $member));
		}

		return $value;
	}

	/**
	 * @param      string $member The metadata member name.
	 * @return     ?string The member's value, or null if absent or not a non-empty string.
	 * @since      1.2.5
	 */
	private function optionalString(string $member): ?string
	{
		$value = $this->metadata[$member] ?? null;
		return is_string($value) && $value !== '' ? $value : null;
	}

	/**
	 * @param      string $member The metadata member name.
	 * @return     array<int, string> The member's string entries, reindexed; an empty list if the member is absent or not an array.
	 * @since      1.2.5
	 */
	private function stringList(string $member): array
	{
		$value = $this->metadata[$member] ?? null;
		if(!is_array($value)) {
			return [];
		}

		$list = [];
		foreach($value as $entry) {
			if(is_string($entry)) {
				$list[] = $entry;
			}
		}

		return $list;
	}
}
