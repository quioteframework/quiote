<?php
namespace Quiote\Security\Auth\Authenticator;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Quiote\Security\Auth\AuthenticationException;
use Quiote\Security\Auth\AuthenticatorInterface;
use Quiote\Security\Auth\AuthorizationHeader;
use Quiote\Security\Auth\ClientAddress;
use Quiote\Security\Auth\Hasher\DummyPasswordHash;
use Quiote\Security\Auth\Passport;
use Quiote\Security\Auth\PasswordHasherInterface;
use Quiote\Security\Auth\PasswordProtectedUserIdentity;
use Quiote\Security\Auth\UserProviderInterface;
use Quiote\Security\RateLimit\LoginThrottle;

/**
 * Decodes an `Authorization: Basic` header and verifies it against a
 * {@see UserProviderInterface}/{@see PasswordHasherInterface} pair.
 * Stateless: identity is re-derived from the header every request.
 *
 * `packages/ratelimit` is a soft dependency: pass a {@see LoginThrottle} to
 * enable brute-force throttling, or omit it to skip it. It matters more here
 * than on {@see FormLoginAuthenticator} -- a Basic credential rides on every
 * request with no form to fetch, no token to obtain and no session to
 * establish first, so an unthrottled Basic surface is the cheapest
 * password-guessing target an application can expose.
 * @since      1.0.0
 */
final class HttpBasicAuthenticator implements AuthenticatorInterface
{
	/** The RFC 7617 auth scheme this authenticator answers to. */
	private const SCHEME = 'Basic';

	/**
	 * @param      UserProviderInterface $userProvider Resolves the decoded username to an identity.
	 * @param      PasswordHasherInterface $passwordHasher Verifies the decoded password against the identity's stored hash.
	 * @param      ?LoginThrottle $throttle When given, failed attempts are throttled per identifier and per client (see `packages/ratelimit`).
	 * @since      1.0.0
	 */
	public function __construct(
		private readonly UserProviderInterface $userProvider,
		private readonly PasswordHasherInterface $passwordHasher,
		private readonly ?LoginThrottle $throttle = null,
	) {
	}

	/**
	 * @param      ServerRequestInterface $request The incoming request.
	 * @return     bool True if $request carries an `Authorization: Basic` header, otherwise false.
	 * @since      1.0.0
	 */
	public function supports(ServerRequestInterface $request): bool
	{
		return AuthorizationHeader::declares($request, self::SCHEME);
	}

	/**
	 * @param      ServerRequestInterface $request The incoming request.
	 * @return     Passport The resolved identity, stateless (re-derived from the header every request).
	 * @throws     AuthenticationException If the header is malformed, credentials are missing, the user is unknown, the password is wrong, or the throttle is exhausted.
	 * @since      1.0.0
	 */
	public function authenticate(ServerRequestInterface $request): Passport
	{
		$encoded = AuthorizationHeader::credential($request, self::SCHEME) ?? '';
		$decoded = base64_decode($encoded, true);
		if($decoded === false || !str_contains($decoded, ':')) {
			throw new AuthenticationException('Malformed Basic authorization header.');
		}

		[$identifier, $password] = explode(':', $decoded, 2);
		if($identifier === '' || $password === '') {
			throw new AuthenticationException('Missing username or password.');
		}

		// Two keys, for the reasons FormLoginAuthenticator spells out: the
		// identifier key bounds vertical brute force against one account, the
		// client key bounds horizontal credential stuffing across many, and
		// keying only on the identifier would hand an attacker a lockout
		// primitive against a known victim.
		$throttleKeys = ['http_basic:' . strtolower($identifier)];
		$clientKey = ClientAddress::fromRequest($request);
		if($clientKey !== null) {
			$throttleKeys[] = 'http_basic_client:' . $clientKey;
		}

		if($this->throttle !== null) {
			foreach($throttleKeys as $key) {
				$retryAfter = $this->throttle->retryAfter($key);
				if($retryAfter !== null) {
					throw new AuthenticationException(sprintf('Too many attempts; retry after %d seconds.', $retryAfter));
				}
			}
		}

		$identity = $this->userProvider->loadByIdentifier($identifier);

		// Verify unconditionally, even with no identity to verify against, for the
		// reason {@see DummyPasswordHash} spells out: the natural short-circuit
		// returns without deriving anything for an unknown username while a known
		// one pays a full argon2id verification, which is a measurable account
		// enumeration oracle. Both branches now cost exactly one derivation.
		$hash = $identity instanceof PasswordProtectedUserIdentity
			? $identity->getPasswordHash()
			: DummyPasswordHash::for($this->passwordHasher);
		$passwordMatches = $this->passwordHasher->verify($password, $hash);

		if(!$identity instanceof PasswordProtectedUserIdentity || !$passwordMatches) {
			if($this->throttle !== null) {
				foreach($throttleKeys as $key) {
					$this->throttle->registerFailure($key);
				}
			}
			throw new AuthenticationException('Invalid credentials.');
		}

		if($this->throttle !== null) {
			foreach($throttleKeys as $key) {
				$this->throttle->reset($key);
			}
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
