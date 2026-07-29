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
		$this->assertUsablePattern($name, $pattern);
	}

	/**
	 * Reject a pattern that cannot do its job, at construction time rather than on
	 * the first request that slips past it.
	 *
	 * Two failure modes, both of which silently produce an *unprotected* surface:
	 *
	 *  - An unanchored pattern matches anywhere in the path, so `/admin` also
	 *    covers `/public/admin-notes`. Since {@see FirewallMap} is
	 *    first-match-wins, an over-broad early pattern shadows a stricter later
	 *    one, and the surface the operator thought they were protecting ends up
	 *    under the wrong firewall. Anchoring is required rather than applied for
	 *    them: silently prepending `^` would change what an existing pattern means
	 *    without saying so.
	 *  - An invalid pattern makes `preg_match()` return false, which the `=== 1`
	 *    test reads as "no match" -- so a typo in a regex means the firewall never
	 *    matches anything and everything under it is wide open. That has to be a
	 *    hard error.
	 *
	 * @throws     \InvalidArgumentException If $pattern is unanchored or does not compile.
	 * @since      3.0.3
	 */
	private function assertUsablePattern(string $name, string $pattern): void
	{
		if(!str_starts_with($pattern, '^')) {
			throw new \InvalidArgumentException(sprintf(
				'Firewall "%s" has the unanchored pattern "%s". A firewall pattern must start with "^" so it '
				. 'matches a path prefix rather than any substring: "%s" would also match paths that merely '
				. 'contain it (e.g. "/public%s-notes"), and because the first matching firewall wins that can '
				. 'place a protected surface under the wrong firewall entirely. Write "^%s" if that is what '
				. 'you meant.',
				$name,
				$pattern,
				$pattern,
				$pattern,
				$pattern
			));
		}

		if(@preg_match('#' . $pattern . '#', '') === false) {
			throw new \InvalidArgumentException(sprintf(
				'Firewall "%s" has the pattern "%s", which is not a valid PCRE expression (note it is wrapped '
				. 'in "#" delimiters, so a literal "#" must be escaped). An invalid pattern makes preg_match() '
				. 'fail, which reads as "does not match" -- this firewall would never match anything and every '
				. 'path it was meant to protect would be unauthenticated.',
				$name,
				$pattern
			));
		}
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
		if(preg_match('#' . $this->pattern . '#', $path) === 1) {
			return true;
		}

		// Also test the normalized form. The raw path is what the router matches on,
		// and it is deliberately left encoded there (an encoded slash inside a route
		// parameter must not be mistaken for a path separator). But a request whose
		// raw path is `/api/%2e%2e/admin` may still be *dispatched* to the admin
		// action, depending on what the front-end proxy normalized before PHP saw it
		// -- and a firewall that only inspects the raw form would match neither the
		// api nor the admin pattern. Testing both closes that gap without relying on
		// deployment-specific proxy behaviour.
		//
		// Matching on either form can only ever place a request *under* a firewall,
		// never remove it from one, so this is the fail-safe direction. Combined with
		// the mandatory `^` anchor, the over-matching that direction could otherwise
		// cause stays predictable.
		$canonical = self::canonicalize($path);

		return $canonical !== $path && preg_match('#' . $this->pattern . '#', $canonical) === 1;
	}

	/**
	 * Collapse a request path to the form a filesystem-style resolver would reach:
	 * fully percent-decoded, backslashes treated as separators, duplicate slashes
	 * collapsed, and `.`/`..` segments resolved.
	 *
	 * Decoding repeats until stable (bounded) rather than once, so a double-encoded
	 * traversal such as `%252e%252e` is caught regardless of how many decoding
	 * layers the proxy in front already peeled off. Over-decoding is safe here
	 * because the result is only ever used to bring *more* paths under a firewall.
	 *
	 * @param      string $path The raw request path.
	 * @return     string The normalized path, always starting with `/`.
	 * @since      3.0.3
	 */
	public static function canonicalize(string $path): string
	{
		$decoded = $path;
		for($i = 0; $i < 3; $i++) {
			$next = rawurldecode($decoded);
			if($next === $decoded) {
				break;
			}
			$decoded = $next;
		}

		$decoded = str_replace('\\', '/', $decoded);

		$segments = [];
		foreach(explode('/', $decoded) as $segment) {
			if($segment === '' || $segment === '.') {
				continue;
			}
			if($segment === '..') {
				array_pop($segments);
				continue;
			}
			$segments[] = $segment;
		}

		$normalized = '/' . implode('/', $segments);

		// Preserve a meaningful trailing slash: `^/api/` must still match a request
		// for `/api/` itself, which would otherwise normalize to `/api`.
		if($normalized !== '/' && str_ends_with($decoded, '/')) {
			$normalized .= '/';
		}

		return $normalized;
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
