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
		if(!in_array($credential, $this->credentials ?? [])) {
			$this->credentials[] = $credential;
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
		$storage = $this->getContext()->getStorage();

		$storedAuth = $storage->retrieve(self::AUTH_NAMESPACE);
		$storedCreds = $storage->retrieve(self::CREDENTIAL_NAMESPACE);
		$storedTokenDerived = $storage->retrieve(self::TOKEN_DERIVED_NAMESPACE);
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
		$logger = \Quiote\Logging\Log::for($this);
		if($logger->isEnabled(\Quiote\Logging\Level::Debug)) {
			try {
				$cid = $this->getContext()->getCorrelationId() ?? 'n/a';
				$logger->debug('[SecurityUser.initialize] cid=' . $cid . ' eff auth=' . var_export($this->authenticated,true) . ' num creds=' . count($this->credentials) . ' storedAuth=' . var_export($storedAuth,true));
			} catch(\Throwable) {}
		}
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
		try {
			$this->getContext()->getStorage()->store(self::TOKEN_DERIVED_NAMESPACE, $tokenDerived);
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
			$stored = $this->getContext()->getStorage()->retrieve($this->storageNamespace);
		} catch(\Throwable) {
			return;
		}
		if(!is_array($stored) || !isset($stored[$ns]) || !is_array($stored[$ns])) {
			return;
		}
		foreach(static::CORE_IDENTITY_KEYS as $key) {
			if($this->hasAttribute($key, $ns)) {
				continue;
			}
			if(array_key_exists($key, $stored[$ns])) {
				$this->setAttribute($key, $stored[$ns][$key], $ns);
			}
		}
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
			// immediate persistence so later initialize() pulls true
			try {
				$storage = $this->getContext()->getStorage();
				// Regenerate the session ID on the unauthenticated -> authenticated
				// transition to defeat session fixation: any ID an attacker may have
				// fixed in the victim's browser before login is invalidated. Only do
				// it on the actual privilege transition (not on every re-affirmation)
				// to avoid needless churn. $_SESSION data is preserved.
				if(!$wasAuthenticated && method_exists($storage, 'regenerate')) {
					$storage->regenerate(true);
				}
				$storage->store(self::AUTH_NAMESPACE, true);
				if (method_exists($storage, 'flush')) { $storage->flush(); }
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
			try { $storage = $this->getContext()->getStorage(); if(method_exists($storage,'getId')) { $tmp=$storage->getId(); if(is_string($tmp)&&$tmp!==''){ $sid=$tmp; } } } catch(\Throwable) {}
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
		$this->authenticated = false;
		$this->logoutIntent = true; // mark explicit downgrade
		$this->tokenDerived = false;
		try {
			$storage = $this->getContext()->getStorage();
			$storage->store(self::AUTH_NAMESPACE, false);
			$storage->store(self::TOKEN_DERIVED_NAMESPACE, false);
		} catch(\Throwable) {}
	}

	/**
	 * Execute the shutdown procedure.
	 * @return     void
	 * @since      1.0.0
	 */
	#[\Override]
  	public function shutdown()
	{
		$logger = \Quiote\Logging\Log::for($this);
		if ($logger->isEnabled(\Quiote\Logging\Level::Debug)) {
			$logger->debug('[SecurityUser] Shutdown storing authenticated status', ['class' => static::class, 'namespace' => self::AUTH_NAMESPACE]);
			$logger->debug('[SecurityUser] Shutdown storing credentials', ['class' => static::class, 'namespace' => self::CREDENTIAL_NAMESPACE]);
		}
		$storage = $this->getContext()->getStorage();

		// If this instance is unauthenticated but storage already has AUTH=true, avoid clobbering (stale recreated user)
		try {
			$existingAuth = $storage->retrieve(self::AUTH_NAMESPACE);
			$curr = $this->authenticated;
			$shouldSkip = ($existingAuth === true && $curr !== true && $this->logoutIntent === false);
			if($shouldSkip) {
				if ($logger->isEnabled(\Quiote\Logging\Level::Debug)) {
					$logger->debug('[SecurityUser] Shutdown skip auth downgrade existing=true curr=' . var_export($curr,true) . ' logoutIntent=0');
				}
			} else {
				$storage->store(self::AUTH_NAMESPACE, $curr);
			}
		} catch (\Throwable) {
			// fallback
			try { $storage->store(self::AUTH_NAMESPACE, $this->authenticated); } catch (\Throwable) {}
		}
		// Avoid clobbering non-empty stored credentials with empty ones from a fresh, not-yet-populated instance
		try {
			$existingCreds = $storage->retrieve(self::CREDENTIAL_NAMESPACE);
			$currEmpty = !is_array($this->credentials) || count($this->credentials) === 0;
			$existingNonEmpty = is_array($existingCreds) && count($existingCreds) > 0;
			if ($this->authenticated === true && $currEmpty && $existingNonEmpty) {
				if ($logger->isEnabled(\Quiote\Logging\Level::Debug)) {
					$logger->debug('[SecurityUser] Shutdown skip creds overwrite empty over non-empty');
				}
			} else {
				$storage->store(self::CREDENTIAL_NAMESPACE, $this->credentials);
			}
		} catch (\Throwable) {
			// fallback
			try { $storage->store(self::CREDENTIAL_NAMESPACE, $this->credentials); } catch (\Throwable) {}
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
		$this->tokenDerived = false;
		$this->context = null;
		$this->parameters = [];
		// reset parent
		parent::reset();
	}
}

?>