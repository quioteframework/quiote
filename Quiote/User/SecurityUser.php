<?php
namespace Quiote\User;

use Quiote\Context;
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
	 * The namespace under which the token-derived marker will be stored.
	 */
	const TOKEN_DERIVED_NAMESPACE = 'org.quiote.user.BasicSecurityUser.tokenDerived';

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
	 * the source of truth. While true, session *credential* rehydration is
	 * skipped in {@see initialize()} -- credentials come from the token/DB
	 * each request -- but session *attribute* storage stays live.
	 */
	protected bool $tokenDerived = false;

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
		$bag = $this->getContext()->getSessionBag();

		$storedAuth = $bag->get(self::AUTH_NAMESPACE);
		$storedCreds = $bag->get(self::CREDENTIAL_NAMESPACE);
		$storedTokenDerived = $bag->get(self::TOKEN_DERIVED_NAMESPACE);
		$this->tokenDerived = (bool)$storedTokenDerived;
		// Preserve externally pre-set authenticated=true (e.g. test) if storage has null
		if($storedAuth !== null) {
			$this->authenticated = (bool)$storedAuth;
		} elseif($this->authenticated === null) {
			$this->authenticated = false;
		}
		if($this->tokenDerived) {
			// Token-authenticated identities are re-derived from the token/DB every
			// request; a stale session credential set must not be rehydrated here.
			$this->credentials = [];
		} elseif(is_array($storedCreds)) {
			$this->credentials = array_values($storedCreds);
		} elseif($this->credentials === null) {
			$this->credentials = [];
		}
		// $credentials was just replaced wholesale above; the index no longer
		// matches and is rebuilt lazily on the next addCredential()/hasCredential().
		$this->credentialIndex = null;
		$logger = \Quiote\Logging\Log::for($this);
		if($logger->isEnabled(\Quiote\Logging\Level::Debug)) {
			try {
				$cid = $this->getContext()->getCorrelationId() ?? 'n/a';
				$logger->debug('[SecurityUser.initialize] cid=' . $cid . ' eff auth=' . var_export($this->authenticated,true) . ' num creds=' . count($this->credentials) . ' storedAuth=' . var_export($storedAuth,true));
			} catch(\Throwable) {}
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
	 * Mark (or clear) this user as token-derived. Called by a token
	 * authenticator (e.g. `BearerTokenAuthenticator`) once it has resolved
	 * and granted the credentials for this request; clearing it (e.g. on
	 * logout, or a subsequent form login) restores normal session
	 * credential rehydration.
	 * @since      1.0.0
	 */
	public function markTokenDerived(bool $tokenDerived = true): void
	{
		$this->tokenDerived = $tokenDerived;
		$this->dirty = true;
		try {
			$bag = $this->getContext()->getSessionBag();
			// A token-authenticated request typically carries no session cookie.
			// Writing this marker unconditionally would manufacture a session --
			// and a Set-Cookie -- for a stateless API client on every call.
			if($bag->exists()) {
				$bag->set(self::TOKEN_DERIVED_NAMESPACE, $tokenDerived);
			}
		} catch(\Throwable) {
		}
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
			$stored = $this->getContext()->getSessionBag()->get($this->storageNamespace);
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
			$wasAuthenticated = ($this->authenticated === true);
			$this->authenticated = true;
			$this->logoutIntent = false; // clear any previous logout marker
			$this->dirty = true;
			// Written eagerly rather than left to shutdown(): a getUser()
			// recreation later in this request must see an authenticated user.
			// Note this reaches $_SESSION only -- the session is written out
			// once, at the request boundary.
			try {
				$bag = $this->getContext()->getSessionBag();
				// Regenerate the session ID on the unauthenticated -> authenticated
				// transition to defeat session fixation: any ID an attacker may have
				// fixed in the victim's browser before login is invalidated. Only do
				// it on the actual privilege transition (not on every re-affirmation)
				// to avoid needless churn. $_SESSION data is preserved.
				if(!$wasAuthenticated) {
					$bag->regenerate(true);
				}
				// Deliberately not gated on an existing session: login is the
				// one write that legitimately creates one, and it is how a
				// first-time visitor gets a session at all.
				$bag->set(self::AUTH_NAMESPACE, true);
			} catch(\Throwable) {}

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
			try { $tmp = $this->getContext()->getSessionBag()->getId(); if($tmp !== '') { $sid = $tmp; } } catch(\Throwable) {}
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
		$this->dirty = true;
		try {
			$bag = $this->getContext()->getSessionBag();
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
			$bag->set(self::TOKEN_DERIVED_NAMESPACE, false);
		} catch(\Throwable) {}
	}

	/**
	 * Discard the current session's contents and move to a fresh id.
	 *
	 * Best-effort: a storage backend that cannot regenerate (NullStorage, an
	 * app's own) simply has its known user keys removed instead.
	 */
	private function destroySessionData(\Quiote\Session\SessionBagInterface $bag): void
	{
		foreach([self::AUTH_NAMESPACE, self::CREDENTIAL_NAMESPACE, self::TOKEN_DERIVED_NAMESPACE, $this->storageNamespace] as $key) {
			try {
				$bag->remove($key);
			} catch(\Throwable) {
			}
		}

		try {
			$bag->destroy();
		} catch(\Throwable) {
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

		$logger = \Quiote\Logging\Log::for($this);
		if ($logger->isEnabled(\Quiote\Logging\Level::Debug)) {
			$logger->debug('[SecurityUser] Shutdown storing authenticated status', ['class' => static::class, 'namespace' => self::AUTH_NAMESPACE]);
			$logger->debug('[SecurityUser] Shutdown storing credentials', ['class' => static::class, 'namespace' => self::CREDENTIAL_NAMESPACE]);
		}
		$bag = $this->getContext()->getSessionBag();

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
			try { $bag->set(self::AUTH_NAMESPACE, $this->authenticated); } catch (\Throwable) {}
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
			try { $bag->set(self::CREDENTIAL_NAMESPACE, $this->credentials); } catch (\Throwable) {}
		}
		if ($logger->isEnabled(\Quiote\Logging\Level::Debug)) {
			try {
				$cid = $this->getContext()->getCorrelationId() ?? 'n/a';
				$logger->debug('[SecurityUser] Shutdown correlation id=' . $cid . ' stored auth=' . var_export($this->authenticated,true) . ' creds count=' . count($this->credentials ?? []));
				$logger->debug('[SecurityUser] Shutdown session snapshot', [
					'session' => isset($_SESSION) ? array_keys($_SESSION) : [],
					'session_id' => function_exists('session_id') ? session_id() : null,
					'session_status' => function_exists('session_status') ? session_status() : null,
				]);	

			} catch(\Throwable) {}
		}

		// Debug: Check what's in the session after storing

		// Note: session_write_close() will be handled by the storage shutdown in the proper sequence
		// This ensures the session is written at the right time without interference

		// call the parent shutdown method
		parent::shutdown();
	}

	#[\Override]
    public function reset() : void
	{
		$this->authenticated = null;
		$this->credentials = null;
		$this->credentialIndex = null;
		$this->tokenDerived = false;
		$this->context = null;
		$this->parameters = [];
		// reset parent
		parent::reset();
	}
}

?>