<?php
namespace Quiote\Security\Auth;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Validates an `Authorization: Bearer` token via a
 * {@see TokenValidatorInterface} (JWS verify + `iss`/`aud`), derives its
 * {@see ClientType} via a {@see ClientTypeResolverInterface}, and resolves
 * the identity via {@see UserProviderInterface::loadByToken()}. Always
 * stateless: identity is re-derived from the token every request. A
 * `service` client type is what flips a request to `auth.sessionless`
 * (applied by `StatelessAuthenticationMiddleware`, not here).
 * @since      1.0.0
 */
final class BearerTokenAuthenticator implements AuthenticatorInterface
{
	/**
	 * @param      TokenValidatorInterface $validator Verifies the token's signature and `iss`/`aud`/time claims.
	 * @param      ClientTypeResolverInterface $clientTypeResolver Derives human-vs-machine from the validated claims.
	 * @param      UserProviderInterface $userProvider Resolves the validated claims to an identity.
	 * @since      1.0.0
	 */
	public function __construct(
		private readonly TokenValidatorInterface $validator,
		private readonly ClientTypeResolverInterface $clientTypeResolver,
		private readonly UserProviderInterface $userProvider,
	) {
	}

	/**
	 * @param      ServerRequestInterface $request The incoming request.
	 * @return     bool True if $request carries an `Authorization: Bearer` header, otherwise false.
	 * @since      1.0.0
	 */
	public function supports(ServerRequestInterface $request): bool
	{
		return str_starts_with($request->getHeaderLine('Authorization'), 'Bearer ');
	}

	/**
	 * @param      ServerRequestInterface $request The incoming request.
	 * @return     Passport The resolved identity, always stateless (re-derived from the token every request).
	 * @throws     AuthenticationException If the token is missing, invalid, or its claims don't resolve to a known identity.
	 * @since      1.0.0
	 */
	public function authenticate(ServerRequestInterface $request): Passport
	{
		$header = $request->getHeaderLine('Authorization');
		$token = substr($header, strlen('Bearer '));
		if($token === '') {
			throw new AuthenticationException('Missing bearer token.');
		}

		$claims = $this->validator->validate($token);
		$clientType = $this->clientTypeResolver->resolve($claims);
		$subjectClaim = $claims['sub'] ?? '';
		$subject = is_string($subjectClaim) ? $subjectClaim : '';
		$tokenClaims = new TokenClaims($subject, $claims, $clientType);

		$identity = $this->userProvider->loadByToken($tokenClaims);
		if($identity === null) {
			throw new AuthenticationException('Token does not resolve to a known identity.');
		}

		return new Passport($identity, $identity->getRoles(), stateless: true, claims: $tokenClaims);
	}

	/**
	 * @param      AuthenticationException $exception The exception thrown by authenticate().
	 * @return     null Always null: defers to the firewall's HttpChallengeEntryPoint.
	 * @since      1.0.0
	 */
	public function onFailure(AuthenticationException $exception): ?ResponseInterface
	{
		return null;
	}
}
