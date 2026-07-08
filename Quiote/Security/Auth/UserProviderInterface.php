<?php
namespace Quiote\Security\Auth;

/**
 * Loads a {@see UserIdentity} either by a stable identifier (form login,
 * HTTP Basic) or from validated token claims (bearer/JWT/OIDC). Backends:
 * an in-memory config-driven provider, a PDO-backed provider reusing
 * `DatabaseManager`, or a callable-based provider for app-custom lookups.
 * @since      1.0.0
 */
interface UserProviderInterface
{
	/**
	 * @param      string $identifier E.g. an email or username.
	 * @return     ?UserIdentity Null if no matching identity exists.
	 * @since      1.0.0
	 */
	public function loadByIdentifier(string $identifier): ?UserIdentity;

	/**
	 * Maps validated token claims (e.g. `jakamo:legacy_user_id`) onto a
	 * {@see UserIdentity}, keeping that mapping in the app's provider
	 * rather than scattered across middleware/User subclasses.
	 * @param      TokenClaims $claims The validated token claims (see {@see TokenValidatorInterface}).
	 * @return     ?UserIdentity Null if the claims don't resolve to a known identity.
	 * @since      1.0.0
	 */
	public function loadByToken(TokenClaims $claims): ?UserIdentity;
}
