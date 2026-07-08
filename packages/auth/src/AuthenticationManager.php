<?php
namespace Quiote\Security\Auth;

use Psr\Http\Message\ServerRequestInterface;
use Quiote\Controller\Controller;
use Quiote\User\RbacSecurityUser;
use Quiote\User\SecurityUser;

/**
 * Runs a firewall's authenticator chain against a request and, on success,
 * populates the request's `SecurityUser`/`RbacSecurityUser`. AuthN only --
 * the existing authZ path (`SecurityService`/`SecurityMiddleware`) is
 * unchanged and runs independently afterward.
 * @since      1.0.0
 */
final class AuthenticationManager
{
	/**
	 * @param      Controller $controller The owning context's Controller, used to reach its `SecurityUser`.
	 * @since      1.0.0
	 */
	public function __construct(private readonly Controller $controller)
	{
	}

	/**
	 * Tries each of $firewall's authenticators in declaration order and
	 * stops at the first one whose supports() matches $request.
	 *
	 * Returns null if none of them supports this request -- no credential
	 * was presented, so the caller should let the request continue
	 * unauthenticated (the existing authZ path still decides whether that's
	 * allowed). Throws if the matching authenticator's credential was
	 * present but invalid, so the caller can route to the firewall's
	 * {@see EntryPointInterface}.
	 * @param      ServerRequestInterface $request The incoming request.
	 * @param      Firewall $firewall The firewall matched for this request.
	 * @return     ?Passport The successful passport, or null if no authenticator supported this request.
	 * @throws     AuthenticationException If the matching authenticator's credential was present but invalid.
	 * @since      1.0.0
	 */
	public function authenticate(ServerRequestInterface $request, Firewall $firewall): ?Passport
	{
		foreach($firewall->getAuthenticators() as $authenticator) {
			if(!$authenticator->supports($request)) {
				continue;
			}
			$passport = $authenticator->authenticate($request);
			$this->apply($passport, $firewall);
			return $passport;
		}
		return null;
	}

	/**
	 * Populate the context's `SecurityUser`/`RbacSecurityUser` from a
	 * successful passport: marks it token-derived for a stateless firewall,
	 * marks it authenticated, and grants each of the passport's
	 * credentials (as roles on a `RbacSecurityUser`, or as flat credentials
	 * otherwise).
	 * @param      Passport $passport The successful passport to apply.
	 * @param      Firewall $firewall The firewall that produced $passport.
	 * @return     void
	 * @since      1.0.0
	 */
	private function apply(Passport $passport, Firewall $firewall): void
	{
		$user = $this->controller->getContext()->getUser();
		if(!$user instanceof SecurityUser) {
			return;
		}

		if($firewall->isStateless()) {
			// Credentials are re-derived from the credential every request;
			// stale session credentials must not be rehydrated (see
			// SecurityUser::$tokenDerived).
			$user->markTokenDerived(true);
		}

		$user->setAuthenticated(true);

		foreach($passport->getCredentials() as $credential) {
			if($user instanceof RbacSecurityUser) {
				$user->grantRole($credential);
			} else {
				$user->addCredential($credential);
			}
		}
	}
}
