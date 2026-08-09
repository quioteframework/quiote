<?php
namespace Quiote\User;

use Quiote\Context;
use Quiote\Security\Auth\TokenClaims;
use Symfony\Contracts\Service\ResetInterface;

/**
 * BasicSecurityUser will handle any type of data as a credential.
 * @since      1.0.0
 * @version    1.0.0
 */
class SecurityUser extends User implements ISecurityUser, ResetInterface
{
	/**
	 * The namespace under which authenticated status will be stored.
	 */
	const AUTH_NAMESPACE = 'org.quiote.user.BasicSecurityUser.authenticated';

	/**
	 * The namespace under which credentials will be stored.
	 */
	const CREDENTIAL_NAMESPACE = 'org.quiote.user.BasicSecurityUser.credentials';

	/**
	 * Storage keys considered part of this user's "core identity" (e.g. a
	 * legacy user id, company id, external uuid). Subclasses populate this
	 * so {@see restoreIdentityFromStorage()} knows which attributes must
	 * survive a FrankenPHP worker cold start.
	 * @var        array<int, string>
	 */
	protected const CORE_IDENTITY_KEYS = [];

	/**
	 * @var        ?bool True if the user is authenticated, otherwise false.
	 */
	protected $authenticated = false;

	/**
	 * @var        ?array<int, mixed> An array of user credentials.
	 */
	protected $credentials   = null;

	/**
	 * Keyed-set index of scalar credentials (scalarCredentialKey() => true),
	 * used by addCredential()/hasCredential() for O(1) lookups instead of an
	 * O(n) in_array() scan per call -- RbacSecurityUser::initialize() rebuilds
	 * credentials via grantRole() -> per-permission addCredential() every
	 * authenticated request, which was O(roles x perms x existing creds).
	 * Null means "stale, rebuild from $credentials on next use" -- every site
	 * that mutates $credentials directly (bypassing addCredential()) resets
	 * this to null instead of trying to keep it in sync inline.
	 * @var        ?array<string, true>
	 */
	protected ?array $credentialIndex = null;

	/**
	 * (Re)build the scalar-credential index from the current $credentials list.
	 * Non-scalar credentials (arrays/objects) have no fast-path entry and stay
	 * on the in_array() fallback in addCredential()/hasCredential().
	 * @return     array<string, true>
	 */
	private function buildCredentialIndex(): array
	{
		$index = [];
		foreach ($this->credentials ?? [] as $existing) {
			if (is_scalar($existing)) {
				$index[self::scalarCredentialKey($existing)] = true;
			}
		}
		return $index;
	}

	/**
	 * Type-preserving key for a scalar credential, matching hasCredential()'s
	 * existing strict (===) comparison semantics: a plain (string) cast would
	 * collide distinct-but-loosely-equal values across types (e.g. int 0,
	 * float 0.0 and string "0" all cast to "0"), which strict comparison
	 * treats as different credentials.
	 */
	private static function scalarCredentialKey(int|float|string|bool $credential): string
	{
		return match (true) {
			is_bool($credential) => 'bool:' . ($credential ? '1' : '0'),
			is_int($credential) => 'int:' . $credential,
			is_float($credential) => 'float:' . $credential,
			default => 'string:' . $credential,
		};
	}

	/**
	 * True when this user's identity/credentials were (re-)established from
	 * a token (bearer/JWT/OIDC) rather than read back from the session as
	 * the source of truth.
	 *
	 * Scoped to the request that presented the token: it is established by
	 * the authenticator (see
	 * {@see \Quiote\Security\Auth\AuthenticationManager::apply()}) and never
	 * persisted, so a token call made with a session cookie attached neither
	 * inherits that session's authentication and credentials nor writes its
	 * own over them. Session *attribute* storage stays live either way.
	 */
	protected bool $tokenDerived = false;

	/**
	 * The validated claims a token authenticator resolved this identity
	 * from, when {@see $tokenDerived} is true. Request-scoped only -- unlike
	 * credentials, claims are not written to the session, since a stateless
	 * caller's identity is re-derived from its token on every request.
	 */
	protected ?TokenClaims $tokenClaims = null;

	/**
	 * Indicates an explicit downgrade to unauthenticated was requested (logout or forced).
	 * Used to distinguish between a stale/recreated instance that never loaded credentials
	 * and an intentional logout so we don't clobber a persisted TRUE with null/false.
	 */
	protected bool $logoutIntent = false;

	/**
	 * Add a credential to this user.
	 * @param      mixed $credential Credential data.
	 * @return     void
	 * @since      1.0.0
	 */
	public function addCredential($credential)
	{
		if (is_scalar($credential)) {
			$this->credentialIndex ??= $this->buildCredentialIndex();
			$key = self::scalarCredentialKey($credential);
			if (isset($this->credentialIndex[$key])) {
				return;
			}
			$this->credentialIndex[$key] = true;
			$this->credentials[] = $credential;
			$this->dirty = true;
			return;
		}
		if (!in_array($credential, $this->credentials ?? [], true)) {
			$this->credentials[] = $credential;
			$this->dirty = true;
		}
	}

	/**
	 * Clear all credentials associated with this user.
	 * @return     void
	 * @since      1.0.0
	 */
	public function clearCredentials()
	{
		$this->credentials = null;
		$this->credentials = [];
		$this->credentialIndex = [];
		$this->dirty = true;
	}

	/**
	 * Indicates whether or not this user has a credential or a set of
	 * credentials.
	 * @param      mixed $credentials Credential data. Either a string or an array of
	 *                   credentials which are all required. If these individual
	 *                   credentials are again an array of credentials, one or
	 *                   more of these sub-credentials will be required.
	 * @return     bool true, if this user has the credential, otherwise false.
	 * @since      1.0.0
	 */
	public function hasCredentials($credentials)
	{
		foreach((array)$credentials as $credential) {
			if(is_array($credential)) {
				// OR
				foreach($credential as $subcred) {
					if($this->hasCredential($subcred)) {
						continue 2;
					}
				}
				return false;
			} else {
				// AND
				if(!$this->hasCredential($credential)) {
					return false;
				}
			}
		}
		return true;
	}
	
	/**
	 * Indicates whether or not this user has a credential.
	 * @param      mixed $credential Credential data.
	 * @return     bool True if this user has the credential, otherwise false.
	 * @since      1.0.0
	 */
	public function hasCredential($credential)
	{
		if (is_scalar($credential)) {
			$this->credentialIndex ??= $this->buildCredentialIndex();
			return isset($this->credentialIndex[self::scalarCredentialKey($credential)]);
		}
		return in_array($credential, $this->credentials ?? [], true);
	}
	
	/**
	 * Returns the list of credentials that this user possesses.
	 * @return     ?array<int, mixed> This user's credentials.
	 * @since      1.0.0
	 */
	public function getCredentials()
	{
		// Reverted: do not perform storage reads here; return in-memory credentials only.
		return $this->credentials;
	}

	/**
	 * Initialize this User.
	 * @param      Context $context An Context instance.
	 * @param      array<string, mixed> $parameters An associative array of initialization parameters.
	 * @throws     \Quiote\Exception\InitializationException If an error occurs while
	 *                                                 initializing this User.
	 * @return     void
	 * @since      1.0.0
	 */
	#[\Override]
    public function initialize(Context $context, array $parameters = [])
	{
		// initialize parent
		parent::initialize($context, $parameters);

		// read data from storage
		$bag = $this->getContext()->getContainer()->get(\Quiote\Session\SessionBagInterface::class);

		$storedAuth = $bag->get(self::AUTH_NAMESPACE);
		$storedCreds = $bag->get(self::CREDENTIAL_NAMESPACE);
		// A rehydrated user is session-derived by definition: whether this
		// request also carries a token is for the authenticator to say, later
		// in the request, on the instance it authenticates (see
		// {@see markTokenDerived()}).
		$this->tokenDerived = false;
		// Preserve externally pre-set authenticated=true (e.g. test) if storage has null
		if($storedAuth !== null) {
			$this->authenticated = (bool)$storedAuth;
		} elseif($this->authenticated === null) {
			$this->authenticated = false;
		}
		if(is_array($storedCreds)) {
			$this->credentials = array_values($storedCreds);
		} elseif($this->credentials === null) {
			$this->credentials = [];
		}
		// $credentials was just replaced wholesale above; the index no longer
		// matches and is rebuilt lazily on the next addCredential()/hasCredential().
		$this->credentialIndex = null;
		$logger = \Quiote\Logging\Log::for($this);
		if($logger->isEnabled(\Quiote\Logging\Level::Debug)) {
			$logger->debugWith(
				fn(): string => '[SecurityUser.initialize] cid=' . ($this->getContext()->getCorrelationId() ?? 'n/a')
					. ' eff auth=' . var_export($this->authenticated, true)
					. ' num creds=' . count($this->credentials)
					. ' storedAuth=' . var_export($storedAuth, true)
			);
		}
		// Rehydration is not mutation: a user restored from storage has nothing
		// new to write back. Last statement, after every field above is settled.
		$this->markClean();
	}

	/**
	 * Indicates whether or not this user is authenticated.
	 * @return     bool true, if this user is authenticated, otherwise false.
	 * @since      1.0.0
	 */
	public function isAuthenticated()
	{
		// The authenticated state is loaded once from storage in initialize() and
		// updated in-memory by setAuthenticated(); it is the canonical value for the
		// request. We deliberately do NOT re-read storage here. The previous lazy
		// "rehydrate" promoted a not-yet-authenticated in-memory user back to
		// authenticated whenever storage still held true, which (combined with the
		// absence of session-ID regeneration) biased the system fail-open and made a
		// stale/fixated session value able to resurrect authentication on a mere read.
		return (bool)$this->authenticated;
	}

	/**
	 * True when this user's identity was (re-)established from a token
	 * rather than the session, per {@see $tokenDerived}.
	 * @since      1.0.0
	 */
	public function isTokenDerived(): bool
	{
		return $this->tokenDerived;
	}

	/**
	 * The validated claims this identity was resolved from, when
	 * {@see isTokenDerived()} is true.
	 * @since      1.0.0
	 */
	public function getTokenClaims(): ?TokenClaims
	{
		return $this->tokenClaims;
	}

	/**
	 * Set (or clear) the validated claims this identity was resolved from.
	 * Called by {@see \Quiote\Security\Auth\AuthenticationManager::apply()}
	 * alongside {@see markTokenDerived()} once a token authenticator has
	 * produced a successful passport.
	 * @since      1.0.0
	 */
	public function setTokenClaims(?TokenClaims $claims): void
	{
		$this->tokenClaims = $claims;
	}

	/**
	 * Mark (or clear) this user as token-derived, for this request only --
	 * the marker is not persisted (see {@see $tokenDerived}). Called by a
	 * token authenticator (e.g. `BearerTokenAuthenticator`) once it has
	 * resolved and granted the credentials for this request.
	 *
	 * Clearing it is how an endpoint that deliberately turns a token into a
	 * browser session (an SPA's session-establishing call) opts the identity
	 * back into session persistence: call `markTokenDerived(false)` before
	 * granting roles and authenticating, and the login is written out like
	 * any form login's.
	 * @since      1.0.0
	 */
	public function markTokenDerived(bool $tokenDerived = true): void
	{
		$this->tokenDerived = $tokenDerived;
		$this->dirty = true;
	}


	/**
	 * Re-populate this user's core identity attributes (see
	 * {@see CORE_IDENTITY_KEYS}) from storage. Framework code does not call
	 * this automatically; it exists so a worker cold start (a fresh
	 * FrankenPHP worker recreating this object from scratch) can restore
	 * identity-critical attributes before a token authenticator repopulates
	 * the request-scoped identity, without every subclass re-implementing
	 * the same storage read.
	 * @since      1.0.0
	 */
	public function restoreIdentityFromStorage(): void
	{
		if(static::CORE_IDENTITY_KEYS === []) {
			return;
		}
		$ns = $this->getDefaultNamespace();
		try {
			$stored = $this->getContext()->getContainer()->get(\Quiote\Session\SessionBagInterface::class)->get($this->storageNamespace);
		} catch(\Throwable) {
			return;
		}
		if(!is_array($stored) || !isset($stored[$ns]) || !is_array($stored[$ns])) {
			return;
		}
		// setAttribute() marks the user dirty, but these values came straight
		// back out of storage -- restoring them is not a change worth writing.
		// Preserve whatever the flag was, in both directions.
		$wasDirty = $this->dirty;
		foreach(static::CORE_IDENTITY_KEYS as $key) {
			if($this->hasAttribute($key, $ns)) {
				continue;
			}
			if(array_key_exists($key, $stored[$ns])) {
				$this->setAttribute($key, $stored[$ns][$key], $ns);
			}
		}
		$this->dirty = $wasDirty;
	}

	/**
	 * Remove a credential from this user.
	 * @param      mixed $credential Credential data.
	 * @return     void
	 * @since      1.0.0
	 */
	public function removeCredential($credential)
	{
		if($this->hasCredentials($credential)) {
			// we have the credential, now we have to find it
			// let's not foreach here and do exact instance checks
			// for future safety
			if(($key = array_search($credential, $this->credentials ?? [], true)) !== false) {
				// found it, let's nuke it
				unset($this->credentials[$key]);
				if ($this->credentialIndex !== null && is_scalar($credential)) {
					unset($this->credentialIndex[self::scalarCredentialKey($credential)]);
				}
				$this->dirty = true;
			}
		}
	}

	/**
	 * Set the authenticated status of this user.
	 * @param      mixed $authenticated A flag indicating the authenticated status of this user.
	 *                    Intentionally compared with `=== true` below rather than typed
	 *                    `bool`: truthy-but-non-bool values (e.g. `1`) must be rejected, not
	 *                    coerced.
	 * @return     void
	 * @since      1.0.0
	 */
	public function setAuthenticated($authenticated)
	{
		if($authenticated === true) {
			$this->authenticated = true;
			$this->logoutIntent = false; // clear any previous logout marker
			$this->dirty = true;
			if($this->tokenDerived) {
				// This identity lasts exactly as long as the request that
				// presented the token. Recording it would log whatever session
				// the call happened to carry in as the token's bearer, and
				// regenerating that session's id would break the browser tab
				// that owns it.
				return;
			}
			// Written eagerly rather than left to shutdown(): a getUser()
			// recreation later in this request must see an authenticated user.
			// Note this reaches $_SESSION only -- the session is written out
			// once, at the request boundary.
			try {
				$bag = $this->getContext()->getContainer()->get(\Quiote\Session\SessionBagInterface::class);
				// Regenerate the session ID on the unauthenticated -> authenticated
				// transition to defeat session fixation: any ID an attacker may have
				// fixed in the victim's browser before login is invalidated. Only do
				// it on the actual privilege transition (not on every re-affirmation)
				// to avoid needless churn. Session data is preserved.
				//
				// privilegeTransition: true is what makes the old id stop resolving
				// *immediately* instead of after the migration grace window. Without
				// it the old id stayed rideable for a few seconds after every login,
				// which is precisely the fixation window regenerating is meant to
				// close.
				//
				// The transition read is the session's, not this object's: an identity
				// authenticated in memory that never reached the session -- a
				// token-derived request promoting itself to a browser session by
				// clearing its marker -- is still a first login as far as the cookie
				// is concerned, and skipping the rotation would leave exactly the id
				// an attacker could have fixed.
				if($bag->get(self::AUTH_NAMESPACE) !== true) {
					$bag->regenerate(true, privilegeTransition: true);
				}
				// Deliberately not gated on an existing session: login is the
				// one write that legitimately creates one, and it is how a
				// first-time visitor gets a session at all.
				$bag->set(self::AUTH_NAMESPACE, true);
			} catch(\Throwable $e) {
				// Reported at error level rather than thrown, so a session backend outage does
				// not turn a successful authentication into a 500 -- but this request is
				// authenticated and the next one will not be, and if regenerate() is what
				// failed the pre-login id may still resolve. Both are security-relevant.
				\Quiote\Logging\Log::for($this)->error(
					'[SecurityUser] could not persist authentication to the session; this login will '
					. 'not survive the request and session fixation may not have been closed: '
					. $e->getMessage()
				);
			}

			return;
		}

		// Transition to unauthenticated – capture diagnostic context if enabled
		$logger = \Quiote\Logging\Log::for($this);
		$debug = $logger->isEnabled(\Quiote\Logging\Level::Debug);
		if($debug) {
			$bt = [];
			try {
				$raw = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 15);
				foreach($raw as $f) {
					$fn = ($f['class'] ?? '') . ($f['type'] ?? '') . $f['function'];
					$bt[] = ($f['file'] ?? 'nofile') . ':' . ($f['line'] ?? 0) . ' ' . $fn;
				}
			} catch(\Throwable) { $bt[] = 'backtrace_failed'; }
			$reqUri = $_SERVER['REQUEST_URI'] ?? 'unknown';
			$sid = 'no-sid';
			try {
				$tmp = $this->getContext()->getContainer()->get(\Quiote\Session\SessionBagInterface::class)->getId();
				if($tmp !== '') { $sid = $tmp; }
			} catch(\Throwable $e) {
				// Diagnostic only; $sid keeps its placeholder.
				\Quiote\Logging\Log::for($this)->debug(
					'[SecurityUser] session id unavailable for diagnostics: ' . $e->getMessage()
				);
			}
			$pid = getmypid();
			$worker = getenv('FRANKENPHP_WORKER') ?: getenv('FRANKENPHP_WORKER_ID') ?: 'n/a';
			$tracePayload = [
				'event' => 'setAuthenticated(false)',
				'sid' => $sid,
				'pid' => $pid,
				'worker' => $worker,
				'req' => $reqUri,
				'backtrace' => $bt,
			];
			$logger->debug('[SecurityUser.authFalse] ' . json_encode($tracePayload));
		}
		$wasAuthenticated = ($this->authenticated === true);
		$this->authenticated = false;
		$this->logoutIntent = true; // mark explicit downgrade
		$this->tokenDerived = false;
		$this->tokenClaims = null;
		$this->dirty = true;
		try {
			$bag = $this->getContext()->getContainer()->get(\Quiote\Session\SessionBagInterface::class);
			// A logout on a client that has no session must not manufacture one
			// just to record "not authenticated" -- that is the default state.
			if(!$bag->exists()) {
				return;
			}

			if($wasAuthenticated) {
				// Invalidate the session id itself on the authenticated ->
				// unauthenticated transition, mirroring what the reverse
				// transition does above. Writing AUTH=false alone left the
				// post-logout id valid and replayable: anyone who had captured
				// it could keep using it, and a later login on the same id
				// would inherit whatever the logged-out session still held.
				$this->clearCredentials();
				$this->destroySessionData($bag);
			}

			$bag->set(self::AUTH_NAMESPACE, false);
		} catch(\Throwable $e) {
			// A logout that did not land leaves a session that still authenticates, which is
			// the most consequential failure in this class.
			$logger->error(
				'[SecurityUser] could not record the logout in the session; the session may still '
				. 'authenticate: ' . $e->getMessage()
			);
		}
	}

	/**
	 * Discard the current session's contents and move to a fresh id.
	 *
	 * Best-effort: a storage backend that cannot regenerate (NullStorage, an
	 * app's own) simply has its known user keys removed instead.
	 */
	private function destroySessionData(\Quiote\Session\SessionBagInterface $bag): void
	{
		foreach([self::AUTH_NAMESPACE, self::CREDENTIAL_NAMESPACE, $this->storageNamespace] as $key) {
			try {
				$bag->remove($key);
			} catch(\Throwable $e) {
				// Continue clearing the remaining keys: stopping here would leave more of the
				// logged-out session intact than pressing on does.
				\Quiote\Logging\Log::for($this)->error(
					'[SecurityUser] could not clear session key "' . $key . '" during logout: '
					. $e->getMessage()
				);
			}
		}

		try {
			$bag->destroy();
		} catch(\Throwable $e) {
			\Quiote\Logging\Log::for($this)->error(
				'[SecurityUser] could not destroy the session during logout; its contents may '
				. 'survive: ' . $e->getMessage()
			);
		}
	}

	/**
	 * Execute the shutdown procedure.
	 * @return     void
	 * @since      1.0.0
	 */
	#[\Override]
  	public function shutdown()
	{
		if (!$this->isDirty()) {
			// Nothing changed this request. Writing anyway is what made every
			// anonymous request -- health checks, bots, read-only API calls --
			// create a session row and a Set-Cookie.
			return;
		}

		if ($this->tokenDerived) {
			// The credential this identity came from is presented again on the
			// next request, so there is nothing here worth keeping -- and
			// writing it would leave a session cookie that happened to ride
			// along authenticated as the token's bearer. Attributes still go
			// out, via the parent.
			parent::shutdown();
			return;
		}

		$logger = \Quiote\Logging\Log::for($this);
		if ($logger->isEnabled(\Quiote\Logging\Level::Debug)) {
			$logger->debug('[SecurityUser] Shutdown storing authenticated status', ['class' => static::class, 'namespace' => self::AUTH_NAMESPACE]);
			$logger->debug('[SecurityUser] Shutdown storing credentials', ['class' => static::class, 'namespace' => self::CREDENTIAL_NAMESPACE]);
		}
		$bag = $this->getContext()->getContainer()->get(\Quiote\Session\SessionBagInterface::class);

		// If this instance is unauthenticated but storage already has AUTH=true, avoid clobbering (stale recreated user)
		//
		// Second line of defence since dirty tracking landed: a stale recreated
		// user has only run initialize(), so it is clean and returns above
		// before ever reaching here. This still catches the case where the user
		// is dirty for an unrelated reason but its auth/credential rehydration
		// came back empty. Cheap ($_SESSION reads, not I/O), so it stays.
		try {
			$existingAuth = $bag->get(self::AUTH_NAMESPACE);
			$curr = $this->authenticated;
			$shouldSkip = ($existingAuth === true && $curr !== true && $this->logoutIntent === false);
			if($shouldSkip) {
				if ($logger->isEnabled(\Quiote\Logging\Level::Debug)) {
					$logger->debug('[SecurityUser] Shutdown skip auth downgrade existing=true curr=' . var_export($curr,true) . ' logoutIntent=0');
				}
			} else {
				$bag->set(self::AUTH_NAMESPACE, $curr);
			}
		} catch (\Throwable) {
			// fallback
			try {
				$bag->set(self::AUTH_NAMESPACE, $this->authenticated);
			} catch (\Throwable $e) {
				$logger->error(
					'[SecurityUser] could not persist the authentication flag on shutdown; the next '
					. 'request will read a stale value: ' . $e->getMessage()
				);
			}
		}
		// Avoid clobbering non-empty stored credentials with empty ones from a fresh, not-yet-populated instance
		try {
			$existingCreds = $bag->get(self::CREDENTIAL_NAMESPACE);
			$currEmpty = !is_array($this->credentials) || count($this->credentials) === 0;
			$existingNonEmpty = is_array($existingCreds) && count($existingCreds) > 0;
			if ($this->authenticated === true && $currEmpty && $existingNonEmpty) {
				if ($logger->isEnabled(\Quiote\Logging\Level::Debug)) {
					$logger->debug('[SecurityUser] Shutdown skip creds overwrite empty over non-empty');
				}
			} else {
				$bag->set(self::CREDENTIAL_NAMESPACE, $this->credentials);
			}
		} catch (\Throwable) {
			// fallback
			try {
				$bag->set(self::CREDENTIAL_NAMESPACE, $this->credentials);
			} catch (\Throwable $e) {
				$logger->error(
					'[SecurityUser] could not persist credentials on shutdown; the next request will '
					. 'read a stale set: ' . $e->getMessage()
				);
			}
		}
		if ($logger->isEnabled(\Quiote\Logging\Level::Debug)) {
			$logger->debugWith(
				fn(): string => '[SecurityUser] Shutdown correlation id='
					. ($this->getContext()->getCorrelationId() ?? 'n/a')
					. ' stored auth=' . var_export($this->authenticated, true)
					. ' creds count=' . count($this->credentials ?? [])
			);
		}

		// Debug: Check what's in the session after storing

		// Note: session_write_close() will be handled by the storage shutdown in the proper sequence
		// This ensures the session is written at the right time without interference

		// call the parent shutdown method
		parent::shutdown();
	}

	/**
	 * Clears the authentication state on top of the parent reset.
	 *
	 * Forgets whether the user was authenticated, its credentials and credential
	 * index, and any claims derived from a stateless token, then delegates to the
	 * parent for the attribute and context state. Called between requests in a
	 * long-running worker so no identity survives into the next one.
	 */
	#[\Override]
    public function reset() : void
	{
		$this->authenticated = null;
		$this->credentials = null;
		$this->credentialIndex = null;
		$this->tokenDerived = false;
		$this->tokenClaims = null;
		$this->context = null;
		$this->parameters = [];
		// reset parent
		parent::reset();
	}
}

?>