<?php
namespace Quiote\Security\Auth;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * The callback leg of the OIDC Authorization Code + PKCE flow: verifies
 * `state` (exact, constant-time comparison), exchanges the code for
 * tokens via {@see OidcClient}, validates the ID token (signature/`iss`/
 * `aud` via the injected {@see TokenValidatorInterface}, plus our own
 * `nonce` check -- `at_hash` is intentionally not checked: it is only
 * REQUIRED by OIDC core when an access token is returned from the
 * *authorization* endpoint (implicit/hybrid flows), and OPTIONAL for a
 * pure Authorization Code exchange at the *token* endpoint, which is the
 * only flow this class implements), then maps the claims to a
 * `UserIdentity` via `UserProviderInterface::loadByToken()` -- the same
 * seam `packages/auth-jwt`'s `BearerTokenAuthenticator` uses.
 *
 * Does not initiate the flow: building the authorization redirect (via
 * {@see OidcClient::buildAuthorizationRequest()}) is left to the app's own
 * login-initiation code (e.g. its login action/controller), since only the
 * app knows when it wants to redirect to the identity provider versus
 * showing another login option.
 * @since      1.0.0
 */
final class OidcAuthenticator implements AuthenticatorInterface
{
	/**
	 * @param      OidcClient $client Exchanges the authorization code for tokens.
	 * @param      TokenValidatorInterface $idTokenValidator Verifies the ID token's signature and `iss`/`aud`/time claims.
	 * @param      UserProviderInterface $userProvider Resolves the validated ID-token claims to an identity.
	 * @param      OidcStateStorage $stateStorage Retrieves the state/PKCE-verifier/nonce persisted before the redirect.
	 * @param      string $callbackPath The path the identity provider redirects back to (matched by supports()).
	 * @since      1.0.0
	 */
	public function __construct(
		private readonly OidcClient $client,
		private readonly TokenValidatorInterface $idTokenValidator,
		private readonly UserProviderInterface $userProvider,
		private readonly OidcStateStorage $stateStorage,
		private readonly string $callbackPath,
	) {
	}

	/**
	 * @param      ServerRequestInterface $request The incoming request.
	 * @return     bool True if $request is the OIDC callback (matches $callbackPath and carries `code`/`state`), otherwise false.
	 * @since      1.0.0
	 */
	public function supports(ServerRequestInterface $request): bool
	{
		$query = $request->getQueryParams();
		return rtrim($request->getUri()->getPath(), '/') === rtrim($this->callbackPath, '/')
			&& isset($query['code'], $query['state']);
	}

	/**
	 * @param      ServerRequestInterface $request The incoming OIDC callback request.
	 * @return     Passport The resolved identity, session-backed (not stateless).
	 * @throws     AuthenticationException If the code/state are missing, the state/nonce don't match, the token exchange fails, or the claims don't resolve to a known identity.
	 * @since      1.0.0
	 */
	public function authenticate(ServerRequestInterface $request): Passport
	{
		$query = $request->getQueryParams();
		$code = $query['code'] ?? null;
		$state = $query['state'] ?? null;
		if(!is_string($code) || !is_string($state)) {
			throw new AuthenticationException('Missing OIDC authorization code or state.');
		}

		$expected = $this->stateStorage->consume($state);
		if($expected === null || !hash_equals($expected->getState(), $state)) {
			throw new AuthenticationException('Invalid or expired OAuth state.');
		}

		$accessToken = $this->client->exchangeCode($code, $expected->getPkceVerifier());

		$idToken = $accessToken->getValues()['id_token'] ?? null;
		if(!is_string($idToken)) {
			throw new AuthenticationException('OIDC token response is missing an ID token.');
		}

		$claims = $this->idTokenValidator->validate($idToken);

		$nonce = $claims['nonce'] ?? null;
		if(!is_string($nonce) || !hash_equals($expected->getNonce(), $nonce)) {
			throw new AuthenticationException('ID token nonce does not match.');
		}

		$subjectClaim = $claims['sub'] ?? '';
		$subject = is_string($subjectClaim) ? $subjectClaim : '';
		$tokenClaims = new TokenClaims($subject, $claims, ClientType::User);

		$identity = $this->userProvider->loadByToken($tokenClaims);
		if($identity === null) {
			throw new AuthenticationException('ID token claims do not resolve to a known identity.');
		}

		return new Passport($identity, $identity->getRoles(), stateless: false, claims: $tokenClaims);
	}

	/**
	 * @param      AuthenticationException $exception The exception thrown by authenticate().
	 * @return     null Always null: defers to the firewall's LoginRedirectEntryPoint.
	 * @since      1.0.0
	 */
	public function onFailure(AuthenticationException $exception): ?ResponseInterface
	{
		return null;
	}
}
