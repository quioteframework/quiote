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
use Quiote\Security\Csrf\CsrfManager;
use Quiote\Security\RateLimit\LoginThrottle;

/**
 * Verifies a username/password login POST via a
 * {@see UserProviderInterface}/{@see PasswordHasherInterface} pair. A
 * service the app's own login endpoint/action calls directly -- the
 * framework ships no login page or form-rendering logic, only this
 * verification step -- but it also implements {@see AuthenticatorInterface}
 * so it can sit in a firewall's authenticator chain and be matched by
 * `supports()` against the configured login-check path.
 *
 * `packages/csrf` and `packages/ratelimit` are soft dependencies: pass a
 * {@see CsrfManager}/{@see LoginThrottle} instance to enable CSRF
 * verification / brute-force throttling, or omit them to skip both.
 * @since      1.0.0
 */
final class FormLoginAuthenticator implements AuthenticatorInterface
{
	/**
	 * @param      UserProviderInterface $userProvider Resolves the submitted identifier field to an identity.
	 * @param      PasswordHasherInterface $passwordHasher Verifies the submitted password against the identity's stored hash.
	 * @param      string $checkPath The path a login POST is submitted to (matched by supports()).
	 * @param      string $identifierField The form field name holding the username/email.
	 * @param      string $passwordField The form field name holding the password.
	 * @param      ?CsrfManager $csrf When given, the submitted CSRF token is validated (see `packages/csrf`).
	 * @param      ?LoginThrottle $throttle When given, failed attempts are throttled per identifier (see `packages/ratelimit`).
	 * @since      1.0.0
	 */
	public function __construct(
		private readonly UserProviderInterface $userProvider,
		private readonly PasswordHasherInterface $passwordHasher,
		private readonly string $checkPath = '/login',
		private readonly string $identifierField = 'username',
		private readonly string $passwordField = 'password',
		private readonly ?CsrfManager $csrf = null,
		private readonly ?LoginThrottle $throttle = null,
	) {
	}

	/**
	 * @param      ServerRequestInterface $request The incoming request.
	 * @return     bool True if $request is a POST to the configured login-check path, otherwise false.
	 * @since      1.0.0
	 */
	public function supports(ServerRequestInterface $request): bool
	{
		return strtoupper($request->getMethod()) === 'POST'
			&& rtrim($request->getUri()->getPath(), '/') === rtrim($this->checkPath, '/');
	}

	/**
	 * @param      ServerRequestInterface $request The incoming login POST request.
	 * @return     Passport The resolved identity, session-backed (not stateless).
	 * @throws     AuthenticationException If the form data, CSRF token, or credentials are missing/invalid, or the throttle is exhausted.
	 * @since      1.0.0
	 */
	public function authenticate(ServerRequestInterface $request): Passport
	{
		$body = $request->getParsedBody();
		if(!is_array($body)) {
			throw new AuthenticationException('Missing form data.');
		}

		$identifier = $body[$this->identifierField] ?? null;
		$password = $body[$this->passwordField] ?? null;
		if(!is_string($identifier) || $identifier === '' || !is_string($password) || $password === '') {
			throw new AuthenticationException('Missing username or password.');
		}

		if($this->csrf !== null && $this->csrf->isEnabled()) {
			$token = $body[$this->csrf->fieldName()] ?? $request->getHeaderLine($this->csrf->headerName());
			if(!is_string($token) || !$this->csrf->isValid($token)) {
				throw new AuthenticationException('Invalid CSRF token.');
			}
		}

		// Two keys, not one. Keying only on the identifier throttles vertical
		// brute force against a single account but does nothing about horizontal
		// credential stuffing (one attempt each across thousands of accounts), and
		// it hands an attacker a lockout primitive against a known victim. The
		// client key bounds the attacker; the identifier key bounds the account.
		$throttleKeys = ['form_login:' . strtolower($identifier)];
		$clientKey = $this->clientKey($request);
		if($clientKey !== null) {
			$throttleKeys[] = 'form_login_client:' . $clientKey;
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

		// Verify unconditionally, even with no identity to verify against. Letting
		// PHP short-circuit the `||` here made an unknown identifier return after a
		// single indexed SELECT while a known one paid a full argon2id verification
		// -- a timing oracle worth tens of milliseconds, i.e. a reliable account
		// enumeration primitive. Both paths now cost one KDF.
		$hash = $identity instanceof PasswordProtectedUserIdentity ? $identity->getPasswordHash() : self::dummyHash();
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

		return new Passport($identity, $identity->getRoles(), stateless: false);
	}

	/**
	 * A valid hash of a value no submitted password can match, used to spend the
	 * same KDF time on an unknown identifier as on a known one.
	 *
	 * Computed once per process and cached: it must be a real hash in the
	 * configured algorithm's own format so verify() does the full derivation
	 * rather than bailing on a malformed hash, which would defeat the point.
	 * @return     string
	 * @since      3.0.3
	 */
	private static function dummyHash(): string
	{
		static $hash = null;
		if($hash === null) {
			$hash = password_hash(
				base64_encode(random_bytes(32)),
				defined('PASSWORD_ARGON2ID') ? PASSWORD_ARGON2ID : PASSWORD_BCRYPT,
			);
		}

		return $hash;
	}

	/**
	 * A stable per-caller throttle key, or null when the peer address is unknown.
	 *
	 * Deliberately the connecting peer (`REMOTE_ADDR`) and never a
	 * client-supplied forwarding header: a spoofable key lets an attacker rotate
	 * it per request, which is indistinguishable from no throttling at all.
	 * @param      ServerRequestInterface $request The incoming login request.
	 * @return     ?string
	 * @since      3.0.3
	 */
	private function clientKey(ServerRequestInterface $request): ?string
	{
		$remote = $request->getServerParams()['REMOTE_ADDR'] ?? null;

		return is_string($remote) && $remote !== '' ? $remote : null;
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
