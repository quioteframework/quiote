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

		$throttleKey = 'form_login:' . strtolower($identifier);
		if($this->throttle !== null) {
			$retryAfter = $this->throttle->retryAfter($throttleKey);
			if($retryAfter !== null) {
				throw new AuthenticationException(sprintf('Too many attempts; retry after %d seconds.', $retryAfter));
			}
		}

		$identity = $this->userProvider->loadByIdentifier($identifier);
		if(!$identity instanceof PasswordProtectedUserIdentity || !$this->passwordHasher->verify($password, $identity->getPasswordHash())) {
			$this->throttle?->registerFailure($throttleKey);
			throw new AuthenticationException('Invalid credentials.');
		}

		$this->throttle?->reset($throttleKey);

		return new Passport($identity, $identity->getRoles(), stateless: false);
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
