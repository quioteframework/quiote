<?php
namespace Quiote\Storage;

use Quiote\Exception\StorageException;
use SessionHandler;
use SessionHandlerInterface;
use Symfony\Contracts\Service\ResetInterface;

/**
 * SessionStorage is the interface used by Quiote to store session data from
 * the User object in a PHP session.
 * <b>Optional parameters:</b>
 * # <b>auto_start</b>              - [true]  - Should startup() start the session
 *                                              itself? With false, nothing is
 *                                              started until the first
 *                                              store()/retrieve()/remove() that
 *                                              actually needs a session.
 * # <b>session_cache_limiter</b>   - []      - The session cache limiter value.
 * # <b>session_cache_expire</b>    - []      - The expire value for the cache
 *                                              limiter header.
 * # <b>session_module_name</b>     - []      - The name of the session module.
 * # <b>session_save_path</b>       - []      - The filesystem location where
 *                                              session data is stored
 * # <b>session_name</b>            - [Quiote] - The name of the session.
 * # <b>session_id</b>              - []      - Static session ID value to set.
 * # <b>session_cookie_lifetime</b> - []      - The session cookie lifetime (in
 *                                              seconds, or strtotime() string).
 * # <b>session_cookie_path</b>     - [?????] - Session cookie path (defaults to
 *                                              base href for web requests).
 * # <b>session_cookie_domain</b>   - []      - Session cookie domain.
 * # <b>session_cookie_secure</b>   - []      - Whether or not session cookies
 *                                              should be limited to HTTPS.
 * # <b>session_cookie_httponly</b> - []      - Session cookie "HTTP-only" flag.
 * All parameters default to whatever PHP would otherwise use, i.e. what's set
 * in php.ini, .htaccess or elsewhere (see {@link http://www.php.net/session}).
 * @since      1.0.0
 * @version    1.0.0
 */
class SessionStorage extends Storage implements SessionHandlerInterface, ResetInterface
{

	/**
	 * @var ?SessionHandler
	 */
	private $defaultHandler;

	/**
	 * @var bool True once a session has actually been started during the
	 *      current request.
	 *
	 * Static because it describes ext/session, which is itself process-global,
	 * and because the consumer -- NativeSessionCookieBridge, which decides
	 * whether the response needs a Set-Cookie -- runs with no reference to this
	 * instance. reset() clears it at the request boundary.
	 *
	 * An explicit flag rather than "is session_id() non-empty?": in a worker
	 * the id is deliberately left non-empty between requests (see reset()), so
	 * emptiness no longer distinguishes "never started" from "carried over",
	 * and inferring from it hands every anonymous visitor a cookie for a
	 * session that does not exist.
	 */
	private static bool $sessionStartedThisRequest = false;

	/**
	 * Whether a session has actually been started during the current request.
	 * @return     bool
	 * @since      2.1.0
	 */
	public static function sessionWasStartedThisRequest(): bool
	{
		return self::$sessionStartedThisRequest;
	}

	public function __construct() {
		$this->defaultHandler = new SessionHandler();
	}

	/**
	 * The default SessionHandler is cleared by reset() (worker mode) to avoid
	 * calling close() twice on an already-persisted session. Lazily recreate
	 * it on demand so a later read()/write()/destroy()/gc()/open() call after
	 * a reset() doesn't fatal on a null handler.
	 * @return     SessionHandler
	 */
	private function getSessionHandler(): SessionHandler
	{
		if ($this->defaultHandler === null) {
			$this->defaultHandler = new SessionHandler();
		}
		return $this->defaultHandler;
	}

	/**
	 * @var ?\Quiote\Logging\CategoryLogger
	 */
	private $loggerCache = null;

	/**
	 * Cached Log::for($this) -- session ops (retrieve/store/remove) run
	 * several times per request (SecurityUser alone does multiple
	 * retrieves/stores at init/shutdown), each guarding a debug() call with
	 * an isEnabled() check; avoids re-resolving the category logger twice
	 * per guarded call site.
	 */
	private function logger(): \Quiote\Logging\CategoryLogger
	{
		return $this->loggerCache ??= \Quiote\Logging\Log::for($this);
	}
	/**
	 * Whether this request has anything to actually load from a session: the
	 * configured session cookie is present, or a specific session id is
	 * forced via the 'session_id' parameter. False means there's no session
	 * to find -- used to defer the actual session_start() (an I/O-bearing
	 * call against the backing store) in startup()/retrieve()/remove() for a
	 * cookieless request, instead of eagerly creating an empty session no
	 * one asked for.
	 */
	private function hasIncomingSessionOrStaticId(): bool
	{
		return $this->incomingSessionId() !== null;
	}

	/**
	 * The session id this request arrived with: the configured static
	 * 'session_id' parameter if set, otherwise the session cookie's value.
	 * Null when the client presented neither.
	 */
	private function incomingSessionId(): ?string
	{
		$staticId = $this->paramString('session_id');
		if($staticId !== null && $staticId !== '') {
			return $staticId;
		}

		$sessionName = $this->paramString('session_name', 'Quiote') ?? 'Quiote';
		$cookie = $_COOKIE[$sessionName] ?? null;

		return is_string($cookie) && $cookie !== '' ? $cookie : null;
	}

	/**
	 * Narrow a session_* config parameter to string, falling back to
	 * $default for a misconfigured non-string value.
	 */
	private function paramString(string $name, ?string $default = null): ?string
	{
		$value = $this->getParameter($name, $default);
		return is_string($value) ? $value : $default;
	}

	/**
	 * Narrow a session_* config parameter to int, falling back to $default.
	 */
	private function paramInt(string $name, ?int $default = null): ?int
	{
		$value = $this->getParameter($name, $default);
		if(is_int($value)) {
			return $value;
		}
		if(is_numeric($value)) {
			return (int) $value;
		}
		return $default;
	}

	/**
	 * Narrow a config parameter to bool, accepting the string spellings
	 * ('off', 'no', 'false', '0', ...) that XML-declared parameters arrive as,
	 * and falling back to $default for an absent or unintelligible value.
	 */
	private function paramBool(string $name, bool $default): bool
	{
		if(!$this->hasParameter($name)) {
			return $default;
		}
		$value = \Quiote\Util\Toolkit::literalize($this->getParameter($name));
		if(is_bool($value)) {
			return $value;
		}
		if(is_int($value) || is_float($value)) {
			return $value != 0;
		}
		return $default;
	}

	/**
	 * Coerce a mixed value (superglobal entries, config) to string using the
	 * same scalar rule PHP's own (string) cast uses, falling back to '' for
	 * values that can't be meaningfully stringified.
	 */
	private function scalarToString(mixed $value): string
	{
		if(is_string($value)) {
			return $value;
		}
		if(is_scalar($value) || $value instanceof \Stringable) {
			return (string) $value;
		}
		return '';
	}

	/**
	 * Starts the session.
	 * The method must be called after initialize().
	 * This code cannot be run in initialize(), because initialization has to
	 * finish completely, for all instances, before a session can be created:
	 * A Database Session Storage must initialize the parent, then itself, and
	 * may only then call startup() to auto-start the session.
	 * Also, the routing must be fully initialized, too.
	 * @return     void
	 * @since      1.0.0
	 */
	public function startup()
	{
		$logger = $this->logger();
		$dbg = $logger->isEnabled(\Quiote\Logging\Level::Debug);
		if($dbg) { $logger->debug('[SessionStorage] startup enter status=' . session_status() . ' currentSid=' . (function_exists('session_id')?session_id():'') ); }
		if($this->hasParameter('session_cache_expire')) {
			session_cache_expire($this->paramInt('session_cache_expire'));
		}

		if($this->hasParameter('session_cache_limiter')) {
			session_cache_limiter($this->paramString('session_cache_limiter'));
		}

		if($this->hasParameter('session_module_name')) {
			session_module_name($this->paramString('session_module_name'));
		}

		if($this->hasParameter('session_save_path')) {
			session_save_path($this->paramString('session_save_path'));
		}

		if(session_status() === PHP_SESSION_NONE) {
			$desiredName = $this->paramString('session_name', 'Quiote') ?? 'Quiote';
			if($dbg) { $logger->debug('[SessionStorage] setting session_name=' . $desiredName); }
			session_name($desiredName);
		}
		// Diagnostic: log raw incoming cookies before starting session
		if($dbg) {
			$rawCookieHeader = $this->scalarToString($_SERVER['HTTP_COOKIE'] ?? '');
			$rawQuiote = $this->scalarToString($_COOKIE[session_name()] ?? '(none)');
						$logger->debug('[SessionStorage] pre-start cookies rawHeader=' . $rawCookieHeader);
						$logger->debug('[SessionStorage] pre-start $_COOKIE[' . session_name() . ']=' . $rawQuiote);
						$logger->debug('[SessionStorage] ini session.use_cookies=' . ini_get('session.use_cookies') . ' use_only_cookies=' . ini_get('session.use_only_cookies') . ' cookie_samesite=' . ini_get('session.cookie_samesite'));
		}

		// Adopt the id this request actually arrived with, before deciding
		// whether to start.
		//
		// In a persistent worker the session module keeps whatever id the
		// previous request left behind, and reset() replaces it with a fresh
		// placeholder rather than clearing it (see reset() for why clearing is
		// not an option). Either way session_id() is non-empty and unrelated to
		// this client, so PHP would neither adopt the incoming cookie nor start
		// a session at all -- the request would silently run against the
		// previous one, or against nothing.
		//
		// Assigning explicitly is what PHP's session.use_strict_mode would
		// otherwise guard, but that ini is off by default and the framework has
		// never enabled it, so this does not change the fixation posture:
		// session_regenerate_id() at the privilege transition is what defends
		// against fixation here (see SecurityUser::setAuthenticated()).
		$incomingId = $this->incomingSessionId();
		if($incomingId !== null && $incomingId !== session_id() && session_status() !== PHP_SESSION_ACTIVE) {
			if($dbg) { $logger->debug('[SessionStorage] adopting incoming session id=' . $incomingId . ' (was ' . session_id() . ')'); }
			session_id($incomingId);
		}

		$sessionId = session_id();
		$staticSessionId = $this->paramString('session_id');
		if($incomingId !== null || $sessionId === '' || ($staticSessionId && $sessionId !== $staticSessionId)) {
			if($staticSessionId) {
				session_id($staticSessionId);
			}

			if ($this->context === null) {
				throw new StorageException('SessionStorage::startup - cannot start a session without an initialized Context');
			}

			$cookieDefaults = session_get_cookie_params();

			$routing = $this->context->getRouting();
			// set path to true if the default path from php.ini is "/". this will, in startup(), trigger the base href as the path.
			if($cookieDefaults['path'] == '/') { $cookieDefaults['path'] = true; }
			
			$lifetimeParam = $this->getParameter('session_cookie_lifetime', $cookieDefaults['lifetime']);
			if(is_numeric($lifetimeParam)) {
				$lifetime = (int) $lifetimeParam;
			} elseif(is_string($lifetimeParam)) {
				$parsed = strtotime($lifetimeParam, 0);
				$lifetime = $parsed === false ? 0 : $parsed;
			} else {
				$lifetime = 0;
			}
			$path = $this->getParameter('session_cookie_path', $cookieDefaults['path']);
			if($path === true) {
				$path = $this->context->getRouting()->getBasePath();
			}
			// Force root path to ensure cookie is sent for '/' (login was setting path like '/login' leading to new session on subsequent GET /)
			if($path !== '/') {
				if($dbg) { $logger->debug('[SessionStorage] overriding cookie path ' . var_export($path,true) . ' -> "/" for global visibility'); }
				$path = '/';
			}
			$domainParam = $this->getParameter('session_cookie_domain', $cookieDefaults['domain']);
			$domain = is_string($domainParam) ? $domainParam : $cookieDefaults['domain'];
			
			$secure = $cookieDefaults['secure'];
			if($this->hasParameter('session_cookie_secure')) {
				$secureParam = $this->getParameter('session_cookie_secure');
				if($secureParam !== null) {
					$secure = (bool)$secureParam;
				} else {
					$secure = true; // explicit null behaves like "auto secure"
				}
			} else {
				// No explicit configuration provided; enforce secure cookies by default (bug #1541)
				$secure = true;
			}
			$request = $this->context->getRequest();
			if($secure && !$request->isHttps()) {
				// Ensure downstream logic can intentionally disable via config if running on HTTP
				$secure = true;
			}
			
			$httpOnly = (bool) $this->getParameter('session_cookie_httponly', $cookieDefaults['httponly']);
			
			session_set_cookie_params($lifetime, $path, $domain, $secure, $httpOnly);

			// Ensure SameSite is set through ini so PHP's own Set-Cookie includes it; avoid manual duplicate cookie.
			if(!ini_get('session.cookie_samesite')) {
				ini_set('session.cookie_samesite', 'Lax');
				if($dbg) { $logger->debug('[SessionStorage] set ini session.cookie_samesite=Lax'); }
			}

			// Cookie/security configuration above is cheap (no I/O) and always
			// applied so a session started later (see below) is correctly
			// configured either way. The actual session_start() is the
			// expensive part -- for a database-backed storage it's a real
			// read against the backing store -- so it's the one thing gated:
			// only pay for it when the request might already have a session
			// (it carries the cookie, or a specific id is forced). A
			// cookieless request (bot, API client, first-time visitor) is the
			// overwhelmingly common case and has nothing to load; if the
			// action actually writes to the session, store()'s own lazy
			// @session_start() fallback creates one at that point instead,
			// inheriting the cookie params configured just above.
			//
			// 'auto_start' is the app-level version of that same decision: with
			// it off, startup() configures the session but never starts one, so
			// even a request that *does* carry the cookie only pays the read
			// once something asks for session data. The lazy start in
			// retrieve()/remove()/store() is the mechanism either way.
			if(!$this->paramBool('auto_start', true)) {
				if($dbg) { $logger->debug('[SessionStorage] session_start() deferred: auto_start is off'); }
			} elseif($this->hasIncomingSessionOrStaticId() && session_status() !== PHP_SESSION_ACTIVE) {
				if($dbg) { $logger->debug('[SessionStorage] starting session idPre=' . session_id()); }
				session_start();
				self::$sessionStartedThisRequest = true;
				if($dbg) { $logger->debug('[SessionStorage] session started sid=' . session_id()); }
				// Rely on PHP's built-in session cookie emission (session.use_cookies=1). Manual setcookie removed to prevent duplicate headers.
				if($dbg) { $logger->debug('[SessionStorage] relying on PHP for Set-Cookie (no manual duplicate) lifetime=' . $lifetime . ' path=' . $path); }
			} elseif($dbg) {
				$logger->debug('[SessionStorage] session_start() deferred: no incoming session cookie and no static session_id configured');
			}
		}
		elseif($dbg) { $logger->debug('[SessionStorage] startup skipped existing sid=' . session_id()); }
		if($dbg) { $logger->debug('[SessionStorage] startup exit sid=' . session_id()); }
	}

	/**
	 * Retrieve data from this storage.
	 * The preferred format for a key is directory style so naming conflicts can
	 * be avoided.
	 * @param      string $key A unique key identifying your data.
	 * @return     mixed Data associated with the key.
	 * @since      1.0.0
	 */
	public function retrieve($key)
	{
		if(session_status() !== PHP_SESSION_ACTIVE) {
			if(!$this->hasIncomingSessionOrStaticId()) {
				// No cookie means no session was ever created for this client,
				// so there's nothing to find; starting one here would just
				// create an empty session for a read that can only come back
				// null anyway.
				return null;
			}
				if($this->logger()->isEnabled(\Quiote\Logging\Level::Debug)) { $this->logger()->debug('[SessionStorage] lazy-start before retrieve key=' . $key); }
			if(@session_start()) {
				self::$sessionStartedThisRequest = true;
			}
				if($this->logger()->isEnabled(\Quiote\Logging\Level::Debug)) { $this->logger()->debug('[SessionStorage] lazy-start after retrieve sid=' . session_id()); }
		}
		return $_SESSION[$key] ?? null;
	}

	/**
	 * Remove data from this storage.
	 * The preferred format for a key is directory style so naming conflicts can
	 * be avoided.
	 * @param      string $key A unique key identifying your data.
	 * @return     mixed Data associated with the key.
	 * @since      1.0.0
	 */
	public function remove($key)
	{
		if(session_status() !== PHP_SESSION_ACTIVE) {
			if(!$this->hasIncomingSessionOrStaticId()) {
				// No session was ever created for this client, so there's
				// nothing stored under $key to remove.
				return null;
			}
				if($this->logger()->isEnabled(\Quiote\Logging\Level::Debug)) { $this->logger()->debug('[SessionStorage] lazy-start before remove key=' . $key); }
			if(@session_start()) {
				self::$sessionStartedThisRequest = true;
			}
				if($this->logger()->isEnabled(\Quiote\Logging\Level::Debug)) { $this->logger()->debug('[SessionStorage] lazy-start after remove sid=' . session_id()); }
		}
		$retval = null;

		if(isset($_SESSION[$key])) {
			$retval = $_SESSION[$key];
			unset($_SESSION[$key]);
		}

		return $retval;
	}

	/**
	 * Execute the shutdown procedure.
	 * @return     void
	 * @since      1.0.0
	 */
	public function shutdown()
	{
		if($this->logger()->isEnabled(\Quiote\Logging\Level::Debug)) { $this->logger()->debug('[SessionStorage] shutdown sid=' . (function_exists('session_id')?session_id():'') ); }
		session_write_close();
	}

	/**
	 * Store data in this storage.
	 * The preferred format for a key is directory style so naming conflicts can
	 * be avoided.
	 * @param      string $id A unique key identifying your data.
	 * @param      mixed $data Data associated with your key.
	 * @since      1.0.0
	 */
	public function store(string $id, mixed $data): bool
	{
		if(session_status() !== PHP_SESSION_ACTIVE) {
				if($this->logger()->isEnabled(\Quiote\Logging\Level::Debug)) { $this->logger()->debug('[SessionStorage] lazy-start before store key=' . $id); }
			if(@session_start()) {
				self::$sessionStartedThisRequest = true;
			}
				if($this->logger()->isEnabled(\Quiote\Logging\Level::Debug)) { $this->logger()->debug('[SessionStorage] lazy-start after store sid=' . session_id()); }
		}
		if($this->logger()->isEnabled(\Quiote\Logging\Level::Debug)) { $this->logger()->debug('[SessionStorage] store key=' . $id . ' type=' . gettype($data) . ' sid=' . session_id()); }
		$_SESSION[$id] = $data;
		return true;
	}

	public function write(string $id, string $data): bool
	{
		if($this->logger()->isEnabled(\Quiote\Logging\Level::Debug)) { $this->logger()->debug('[SessionStorage] write raw sid=' . $id . ' len=' . strlen($data)); }
		return $this->getSessionHandler()->write($id, $data);
	}

	public function read(string $key) : string|false
	{
		if($this->logger()->isEnabled(\Quiote\Logging\Level::Debug)) { $this->logger()->debug('[SessionStorage] read raw key=' . $key); }
		return $this->getSessionHandler()->read($key);
	}

	public function close(): bool
	{
		// SessionHandlerInterface callback: PHP invokes this from *inside*
		// session_write_close() and session_regenerate_id(). Calling
		// session_write_close() here would therefore recurse -- latent today
		// only because plain SessionStorage never registers itself as a save
		// handler (PdoSessionStorage does, and overrides this). Delegate to the
		// default handler, exactly as read()/write()/open()/destroy()/gc() do.
		// shutdown(), not this, is the write-close entry point for callers.
		try {
			return $this->getSessionHandler()->close();
		} catch(\Error) {
			// "Cannot call default session handler": PHP refuses SessionHandler's
			// methods unless it is the registered handler, which is exactly the
			// case where this object owns no open backing store to close. The
			// same applies to the sibling delegating methods; only close() is
			// reachable from application code, so only close() absorbs it.
			return true;
		}
	}

	/**
	 * The active session id, or '' when no session is active.
	 *
	 * Several call sites probe for this with method_exists() before logging a
	 * session id; until this existed those branches were permanently dead.
	 * @return     string The active session id, or '' if there is no session.
	 * @since      2.0.2
	 */
	public function getId(): string
	{
		if(session_status() !== PHP_SESSION_ACTIVE) {
			return '';
		}
		$id = session_id();
		return is_string($id) ? $id : '';
	}

	/**
	 * Whether a write can land in a session that already exists, rather than
	 * manufacturing one for a client that has none.
	 *
	 * True when a session is active, or when one can be lazily started from an
	 * incoming cookie or a configured static id. Callers writing default or
	 * empty state (a logout, a token-derived identity) use this to avoid
	 * creating a session -- and therefore a persisted row and a Set-Cookie --
	 * for a visitor who never had one. A deliberate write that *should* create
	 * a session (a login) simply doesn't consult it.
	 * @return     bool
	 * @since      2.0.2
	 */
	public function hasSession(): bool
	{
		return session_status() === PHP_SESSION_ACTIVE || $this->hasIncomingSessionOrStaticId();
	}

	/**
	 * Regenerate the session ID, preserving the session's data.
	 * Called at privilege transitions (e.g. login) to defeat session fixation.
	 * Uses PHP's native session_regenerate_id(); $_SESSION contents are kept and
	 * moved to the new ID.
	 * @param      bool $deleteOldSession Whether to delete the old session file/record.
	 * @return     bool True on success (or no-op when no session is active).
	 * @since      1.0.0
	 */
	public function regenerate(bool $deleteOldSession = true): bool
	{
		if(session_status() !== PHP_SESSION_ACTIVE) {
			if(@session_start()) {
				self::$sessionStartedThisRequest = true;
			}
		}
		if(session_status() !== PHP_SESSION_ACTIVE) {
			return false;
		}
		$old = function_exists('session_id') ? session_id() : '';
		$result = session_regenerate_id($deleteOldSession);
		// $_COOKIE still holds the pre-rotation id, and PHP does not update it.
		// hasIncomingSessionOrStaticId() -- which gates the lazy session_start()
		// in retrieve()/remove() -- reads $_COOKIE, so leaving the stale value
		// there makes the rest of this request reason about a session id that
		// was just deleted. That matters immediately: setAuthenticated(true)
		// regenerates and then reads credentials back out of storage.
		$new = session_id();
		if(is_string($new) && $new !== '') {
			$_COOKIE[session_name()] = $new;
		}
		if($this->logger()->isEnabled(\Quiote\Logging\Level::Debug)) {
			$this->logger()->debug('[SessionStorage] regenerate old=' . $old . ' new=' . session_id() . ' deleteOld=' . (int)$deleteOldSession);
		}
		return $result;
	}

	public function destroy($sessionId): bool
	{
		if($this->logger()->isEnabled(\Quiote\Logging\Level::Debug)) { $this->logger()->debug('[SessionStorage] destroy raw sid=' . $sessionId); }
		return $this->getSessionHandler()->destroy($sessionId);
	}

	public function gc(int $maxlifetime): int|false
	{
		if($this->logger()->isEnabled(\Quiote\Logging\Level::Debug)) { $this->logger()->debug('[SessionStorage] gc maxlifetime=' . $maxlifetime); }
		return $this->getSessionHandler()->gc($maxlifetime);
	}

	public function open($savePath, $sessionName): bool
	{
		if($this->logger()->isEnabled(\Quiote\Logging\Level::Debug)) { $this->logger()->debug('[SessionStorage] open savePath=' . $savePath . ' name=' . $sessionName); }
		return $this->getSessionHandler()->open($savePath, $sessionName);
	}

	#[\Override]
    public function reset() : void {
		// Only call close() if the session is still active; PHP 8.5 throws "Session is not
		// active" if session_write_close() (called by shutdown()) already ended the session.
		if ($this->defaultHandler !== null && session_status() === PHP_SESSION_ACTIVE) {
			$this->defaultHandler->close();
		}
		$this->defaultHandler = null;

		// Worker mode: the PHP process is long-lived, so the session module keeps
		// the previous request's session id and $_SESSION contents even after
		// session_write_close() (called by shutdown() just before this). Left in
		// place, the next request would silently run against the previous user's
		// session -- a cross-user auth/data leak.
		//
		// $_SESSION is emptied, and the id is replaced with a fresh, unpredictable
		// placeholder. Deliberately NOT session_id(''): an empty assignment leaves
		// the module treating the id as explicitly set, so it stops consulting
		// $_COOKIE and every later request in that worker starts a brand-new
		// session instead of resuming the client's. That failure is silent, and it
		// looks exactly like "login never sticks". The placeholder breaks the
		// carry-over just as effectively, and startup() overwrites it with the id
		// the next request actually arrives with.
		//
		// Only touch these when no session is active (the normal post-shutdown
		// state); never stomp an active, unpersisted session.
		self::$sessionStartedThisRequest = false;

		if (session_status() !== PHP_SESSION_ACTIVE) {
			$_SESSION = [];
			if (session_id() !== '') {
				$placeholder = session_create_id();
				session_id($placeholder === false ? bin2hex(random_bytes(16)) : $placeholder);
			}
			if($this->logger()->isEnabled(\Quiote\Logging\Level::Debug)) {
				$this->logger()->debug('[SessionStorage] reset cleared $_SESSION and session id for next worker request');
			}
		}
	}
}

?>