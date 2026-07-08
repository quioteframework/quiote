<?php
namespace Quiote\Security\Auth;

/**
 * A named, path-matched set of authenticators plus the entry point that
 * handles a failed authentication attempt for that path -- the runtime
 * counterpart of a `security.xml` `<firewall>` element.
 * @since      1.0.0
 */
final class Firewall
{
	/**
	 * @param      string $name A diagnostic name for this firewall (e.g. "api", "main").
	 * @param      string $pattern A PCRE pattern (without delimiters) matched against the request path.
	 * @param      AuthenticatorInterface[] $authenticators Tried in order; the first one whose supports() matches wins.
	 * @param      EntryPointInterface $entryPoint Produces the failure response when authentication is required but absent/invalid.
	 * @param      bool $stateless Identity axis: re-derived from the credential every request rather than read back from the session.
	 * @param      bool $sessionless Session axis: no session is started at all for requests under this firewall.
	 * @since      1.0.0
	 */
	public function __construct(
		private readonly string $name,
		private readonly string $pattern,
		private readonly array $authenticators,
		private readonly EntryPointInterface $entryPoint,
		private readonly bool $stateless = false,
		private readonly bool $sessionless = false,
	) {
	}

	/**
	 * @return     string This firewall's diagnostic name.
	 * @since      1.0.0
	 */
	public function getName(): string
	{
		return $this->name;
	}

	/**
	 * Whether $path falls under this firewall. Matched against the request
	 * path rather than the resolved route, so a stateless firewall can be
	 * evaluated before routing has run (see
	 * `Quiote\Security\Auth\Middleware\StatelessAuthenticationMiddleware`).
	 * @param      string $path The request path to test (e.g. `$request->getUri()->getPath()`).
	 * @return     bool True if $path matches this firewall's pattern, otherwise false.
	 * @since      1.0.0
	 */
	public function matches(string $path): bool
	{
		return preg_match('#' . $this->pattern . '#', $path) === 1;
	}

	/**
	 * @return     AuthenticatorInterface[] This firewall's authenticator chain, in try order.
	 * @since      1.0.0
	 */
	public function getAuthenticators(): array
	{
		return $this->authenticators;
	}

	/**
	 * @return     EntryPointInterface The entry point for a failed authentication attempt on this firewall.
	 * @since      1.0.0
	 */
	public function getEntryPoint(): EntryPointInterface
	{
		return $this->entryPoint;
	}

	/**
	 * Identity axis: re-derived from the credential every request rather
	 * than read back from the session as the source of truth.
	 * @return     bool True if this firewall is stateless, otherwise false.
	 * @since      1.0.0
	 */
	public function isStateless(): bool
	{
		return $this->stateless;
	}

	/**
	 * Session axis: no session is started at all for requests under this
	 * firewall (pure machine-to-machine surfaces).
	 * @return     bool True if this firewall is sessionless, otherwise false.
	 * @since      1.0.0
	 */
	public function isSessionless(): bool
	{
		return $this->sessionless;
	}
}
