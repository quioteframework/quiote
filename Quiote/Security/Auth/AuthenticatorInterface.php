<?php
namespace Quiote\Security\Auth;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Generalizes `Quiote\Mcp\Auth\McpAuthenticatorInterface` into a
 * framework-wide contract: one implementation per credential mechanism
 * (form login, HTTP Basic, bearer/JWT, OIDC). A firewall runs its ordered
 * authenticator chain, calling `supports()` to pick the first match, then
 * `authenticate()`.
 * @since      1.0.0
 */
interface AuthenticatorInterface
{
	/**
	 * Whether this authenticator can attempt to extract a credential from
	 * $request (e.g. presence of an `Authorization` header of the right
	 * scheme). Does not validate the credential itself.
	 * @param      ServerRequestInterface $request The incoming request.
	 * @return     bool True if this authenticator should attempt authenticate(), otherwise false.
	 * @since      1.0.0
	 */
	public function supports(ServerRequestInterface $request): bool;

	/**
	 * Extract and validate this authenticator's credential from $request and
	 * resolve it to an identity.
	 * @param      ServerRequestInterface $request The incoming request. Only ever
	 *                    called after supports() returned true for it.
	 * @return     Passport The resolved identity, credentials/roles, and statelessness flag.
	 * @throws     AuthenticationException If the presented credential is absent, malformed, or invalid.
	 * @since      1.0.0
	 */
	public function authenticate(ServerRequestInterface $request): Passport;

	/**
	 * Optional authenticator-specific failure response (e.g. a scheme-specific
	 * `WWW-Authenticate` value). Return null to defer to the firewall's
	 * {@see EntryPointInterface}.
	 * @param      AuthenticationException $exception The exception thrown by authenticate().
	 * @return     ?ResponseInterface A response to short-circuit with, or null to defer to the firewall's entry point.
	 * @since      1.0.0
	 */
	public function onFailure(AuthenticationException $exception): ?ResponseInterface;
}
