<?php
namespace Quiote\Security\Auth\Authenticator;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Quiote\Security\Auth\AuthenticationException;
use Quiote\Security\Auth\AuthenticatorInterface;
use Quiote\Security\Auth\Passport;
use Quiote\Security\Auth\PasswordHasherInterface;
use Quiote\Security\Auth\PasswordProtectedUserIdentity;
use Quiote\Security\Auth\UserProviderInterface;

/**
 * Decodes an `Authorization: Basic` header and verifies it against a
 * {@see UserProviderInterface}/{@see PasswordHasherInterface} pair.
 * Stateless: identity is re-derived from the header every request.
 * @since      1.0.0
 */
final class HttpBasicAuthenticator implements AuthenticatorInterface
{
	/**
	 * @param      UserProviderInterface $userProvider Resolves the decoded username to an identity.
	 * @param      PasswordHasherInterface $passwordHasher Verifies the decoded password against the identity's stored hash.
	 * @since      1.0.0
	 */
	public function __construct(
		private readonly UserProviderInterface $userProvider,
		private readonly PasswordHasherInterface $passwordHasher,
	) {
	}

	/**
	 * @param      ServerRequestInterface $request The incoming request.
	 * @return     bool True if $request carries an `Authorization: Basic` header, otherwise false.
	 * @since      1.0.0
	 */
	public function supports(ServerRequestInterface $request): bool
	{
		return str_starts_with($request->getHeaderLine('Authorization'), 'Basic ');
	}

	/**
	 * @param      ServerRequestInterface $request The incoming request.
	 * @return     Passport The resolved identity, stateless (re-derived from the header every request).
	 * @throws     AuthenticationException If the header is malformed, credentials are missing, the user is unknown, or the password is wrong.
	 * @since      1.0.0
	 */
	public function authenticate(ServerRequestInterface $request): Passport
	{
		$header = $request->getHeaderLine('Authorization');
		$encoded = substr($header, strlen('Basic '));
		$decoded = base64_decode($encoded, true);
		if($decoded === false || !str_contains($decoded, ':')) {
			throw new AuthenticationException('Malformed Basic authorization header.');
		}

		[$identifier, $password] = explode(':', $decoded, 2);
		if($identifier === '' || $password === '') {
			throw new AuthenticationException('Missing username or password.');
		}

		$identity = $this->userProvider->loadByIdentifier($identifier);
		if(!$identity instanceof PasswordProtectedUserIdentity || !$this->passwordHasher->verify($password, $identity->getPasswordHash())) {
			throw new AuthenticationException('Invalid credentials.');
		}

		return new Passport($identity, $identity->getRoles(), stateless: true);
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
