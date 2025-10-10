<?php

// +---------------------------------------------------------------------------+
// | This file is part of the Agavi package.                                   |
// | Copyright (c) 2005-2011 the Agavi Project.                                |
// | Based on the Mojavi3 MVC Framework, Copyright (c) 2003-2005 Sean Kerr.    |
// |                                                                           |
// | For the full copyright and license information, please view the LICENSE   |
// | file that was distributed with this source code. You can also view the    |
// | LICENSE file online at http://www.agavi.org/LICENSE.txt                   |
// |   vi: set noexpandtab:                                                    |
// |   Local Variables:                                                        |
// |   indent-tabs-mode: t                                                     |
// |   End:                                                                    |
// +---------------------------------------------------------------------------+
namespace Agavi\User;

use Agavi\AgaviContext;
use Agavi\Logging\AgaviDebugLogger;
use Symfony\Contracts\Service\ResetInterface;

/**
 * AgaviBasicSecurityUser will handle any type of data as a credential.
 *
 * @package    agavi
 * @subpackage user
 *
 * @author     Sean Kerr <skerr@mojavi.org>
 * @copyright  Authors
 * @copyright  The Agavi Project
 *
 * @since      0.9.0
 *
 * @version    $Id$
 */
class AgaviSecurityUser extends AgaviUser implements AgaviISecurityUser, ResetInterface
{
	// +-----------------------------------------------------------------------+
	// | CONSTANTS                                                             |
	// +-----------------------------------------------------------------------+

	/**
	 * The namespace under which authenticated status will be stored.
	 */
	const AUTH_NAMESPACE = 'org.agavi.user.BasicSecurityUser.authenticated';

	/**
	 * The namespace under which credentials will be stored.
	 */
	const CREDENTIAL_NAMESPACE = 'org.agavi.user.BasicSecurityUser.credentials';

	/**
	 * @var        bool True if the user is authenticated, otherwise false.
	 */
	protected $authenticated = null;
	
	/**
	 * @var        array An array of user credentials.
	 */
	protected $credentials   = null;

	/**
	 * Indicates an explicit downgrade to unauthenticated was requested (logout or forced).
	 * Used to distinguish between a stale/recreated instance that never loaded credentials
	 * and an intentional logout so we don't clobber a persisted TRUE with null/false.
	 */
	protected bool $logoutIntent = false;

	/**
	 * Add a credential to this user.
	 *
	 * @param      mixed Credential data.
	 *
	 * @author     Sean Kerr <skerr@mojavi.org>
	 * @since      0.9.0
	 */
	public function addCredential($credential)
	{
		if(!in_array($credential, $this->credentials)) {
			$this->credentials[] = $credential;
		}
	}

	/**
	 * Clear all credentials associated with this user.
	 *
	 * @author     Sean Kerr <skerr@mojavi.org>
	 * @since      0.9.0
	 */
	public function clearCredentials()
	{
		$this->credentials = null;
		$this->credentials = [];
	}

	/**
	 * Indicates whether or not this user has a credential or a set of
	 * credentials.
	 *
	 * @param      mixed Credential data. Either a string or an array of
	 *                   credentials which are all required. If these individual
	 *                   credentials are again an array of credentials, one or
	 *                   more of these sub-credentials will be required.
	 *
	 * @return     bool true, if this user has the credential, otherwise false.
	 *
	 * @author     David Zülke <dz@bitxtender.com>
	 * @since      0.9.0
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
	 *
	 * @param      string Credential data.
	 *
	 * @return     bool True if this user has the credential, otherwise false.
	 *
	 * @author     Noah Fontes <nf@bitextender.com>
	 * @since      0.11.2
	 */
	public function hasCredential($credential)
	{
		return in_array($credential, $this->credentials, true);
	}
	
	/**
	 * Returns the list of credentials that this user possesses.
	 *
	 * @return     array This user's credentials.
	 *
	 * @author     Noah Fontes <nf@bitextender.com>
	 * @since      0.11.2
	 */
	public function getCredentials()
	{
		// Reverted: do not perform storage reads here; return in-memory credentials only.
		return $this->credentials;
	}

	/**
	 * Initialize this User.
	 *
	 * @param      AgaviContext An AgaviContext instance.
	 * @param      array        An associative array of initialization parameters.
	 *
	 * @throws     <b>AgaviInitializationException</b> If an error occurs while
	 *                                                 initializing this User.
	 *
	 * @author     Sean Kerr <skerr@mojavi.org>
	 * @since      0.9.0
	 */
	#[\Override]
    public function initialize(AgaviContext $context, array $parameters = [])
	{
		// initialize parent
		parent::initialize($context, $parameters);

		// read data from storage
		$storage = $this->getContext()->getStorage();

		$storedAuth = $storage->retrieve(self::AUTH_NAMESPACE);
		$storedCreds = $storage->retrieve(self::CREDENTIAL_NAMESPACE);
		// Preserve externally pre-set authenticated=true (e.g. test) if storage has null
		if($storedAuth !== null) {
			$this->authenticated = (bool)$storedAuth;
		} elseif($this->authenticated === null) {
			$this->authenticated = false;
		}
		if(is_array($storedCreds)) {
			$this->credentials = $storedCreds;
		} elseif($this->credentials === null) {
			$this->credentials = [];
		}
		if(getenv('AGAVI_DEBUG_SECURITY')) {
			try {
				$cid = method_exists($this->getContext(), 'getCorrelationId') ? ($this->getContext()->getCorrelationId() ?? 'n/a') : 'n/a';
				AgaviDebugLogger::debug('[SecurityUser.initialize] cid=' . $cid . ' eff auth=' . var_export($this->authenticated,true) . ' num creds=' . (is_array($this->credentials) ? count($this->credentials) : 0) . ' storedAuth=' . var_export($storedAuth,true), $this->getContext());
			} catch(\Throwable) {}
		}
	}

	/**
	 * Indicates whether or not this user is authenticated.
	 *
	 * @return     bool true, if this user is authenticated, otherwise false.
	 *
	 * @author     Sean Kerr <skerr@mojavi.org>
	 * @since      0.9.0
	 */
	public function isAuthenticated()
	{
		// Lazy rehydrate: If currently unauthenticated (false) but no explicit logout, re-check storage.
		if ($this->authenticated === false && $this->logoutIntent === false) {
			try {
				$storage = $this->getContext()?->getStorage();
				if ($storage) {
					$storedAuth = $storage->retrieve(self::AUTH_NAMESPACE);
					if ($storedAuth === true) {
						$this->authenticated = true;
						if (!is_array($this->credentials) || count($this->credentials) === 0) {
							$storedCreds = $storage->retrieve(self::CREDENTIAL_NAMESPACE);
							if (is_array($storedCreds)) { $this->credentials = $storedCreds; }
						}
						if (getenv('AGAVI_DEBUG_SECURITY')) {
							AgaviDebugLogger::debug('[SecurityUser.lazyRehydrate] promoted auth=true credsCount=' . (is_array($this->credentials) ? count($this->credentials) : 0), $this->getContext());
						}
					}
				}
			} catch (\Throwable) {}
		}
		return $this->authenticated;
	}

	/**
	 * Remove a credential from this user.
	 *
	 * @param      mixed Credential data.
	 *
	 * @author     Sean Kerr <skerr@mojavi.org>
	 * @since      0.9.0
	 */
	public function removeCredential($credential)
	{
		if($this->hasCredentials($credential)) {
			// we have the credential, now we have to find it
			// let's not foreach here and do exact instance checks
			// for future safety
			if(($key = array_search($credential, $this->credentials, true)) !== false) {
				// found it, let's nuke it
				unset($this->credentials[$key]);
			}
		}
	}

	/**
	 * Set the authenticated status of this user.
	 *
	 * @param      bool A flag indicating the authenticated status of this user.
	 *
	 * @author     Sean Kerr <skerr@mojavi.org>
	 * @since      0.9.0
	 */
	public function setAuthenticated($authenticated)
	{
		if($authenticated === true) {
			$this->authenticated = true;
			$this->logoutIntent = false; // clear any previous logout marker
			// immediate persistence so later initialize() pulls true
			try {
				$storage = $this->getContext()?->getStorage();
				if($storage) {
					$storage->store(self::AUTH_NAMESPACE, true);
					if (method_exists($storage, 'flush')) { $storage->flush(); }
				}
			} catch(\Throwable) {}

			return;
		}

		// Transition to unauthenticated – capture diagnostic context if enabled
		$debug = getenv('AGAVI_DEBUG_SECURITY') || getenv('AGAVI_DEBUG_AUTH');
		if($debug) {
			$bt = [];
			try {
				$raw = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 15);
				foreach($raw as $f) {
					$fn = ($f['class'] ?? '') . ($f['type'] ?? '') . ($f['function'] ?? '');
					$bt[] = ($f['file'] ?? 'nofile') . ':' . ($f['line'] ?? 0) . ' ' . $fn;
				}
			} catch(\Throwable) { $bt[] = 'backtrace_failed'; }
			$reqUri = $_SERVER['REQUEST_URI'] ?? 'unknown';
			$sid = 'no-sid';
			try { $storage = $this->getContext()?->getStorage(); if($storage && method_exists($storage,'getId')) { $tmp=$storage->getId(); if(is_string($tmp)&&$tmp!==''){ $sid=$tmp; } } } catch(\Throwable) {}
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
			AgaviDebugLogger::debug('[SecurityUser.authFalse] ' . json_encode($tracePayload), $this->getContext());
		}
		$this->authenticated = false;
		$this->logoutIntent = true; // mark explicit downgrade
		try { $this->getContext()?->getStorage()?->store(self::AUTH_NAMESPACE, false); } catch(\Throwable) {}
	}

	/**
	 * Execute the shutdown procedure.
	 *
	 * @author     Sean Kerr <skerr@mojavi.org>
	 * @since      0.9.0
	 */
	#[\Override]
  	public function shutdown()
	{
		$logger = $this->getContext()?->getLoggerManager()?->getLogger();
		if (getenv('AGAVI_DEBUG_SECURITY')) {
			$logger?->debug('[AgaviSecurityUser] Shutdown storing authenticated status', ['class' => get_class($this), 'namespace' => self::AUTH_NAMESPACE]);
			$logger?->debug('[AgaviSecurityUser] Shutdown storing credentials', ['class' => get_class($this), 'namespace' => self::CREDENTIAL_NAMESPACE]);
		}
		$storage = $this->getContext()->getStorage();

		// If this instance is unauthenticated but storage already has AUTH=true, avoid clobbering (stale recreated user)
		try {
			$existingAuth = $storage->retrieve(self::AUTH_NAMESPACE);
			$curr = $this->authenticated;
			$shouldSkip = ($existingAuth === true && $curr !== true && $this->logoutIntent === false);
			if($shouldSkip) {
				if (getenv('AGAVI_DEBUG_SECURITY')) {
					AgaviDebugLogger::debug('[AgaviSecurityUser] Shutdown skip auth downgrade existing=true curr=' . var_export($curr,true) . ' logoutIntent=0', $this->getContext());
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
				if (getenv('AGAVI_DEBUG_SECURITY')) {
					AgaviDebugLogger::debug('[AgaviSecurityUser] Shutdown skip creds overwrite empty over non-empty', $this->getContext());
				}
			} else {
				$storage->store(self::CREDENTIAL_NAMESPACE, $this->credentials);
			}
		} catch (\Throwable) {
			// fallback
			try { $storage->store(self::CREDENTIAL_NAMESPACE, $this->credentials); } catch (\Throwable) {}
		}
		if (getenv('AGAVI_DEBUG_SECURITY')) {
			try {
				$cid = method_exists($this->getContext(), 'getCorrelationId') ? ($this->getContext()->getCorrelationId() ?? 'n/a') : 'n/a';
				AgaviDebugLogger::debug('[AgaviSecurityUser] Shutdown correlation id=' . $cid . ' stored auth=' . var_export($this->authenticated,true) . ' creds count=' . count($this->credentials), $this->getContext());
				$logger?->debug('[AgaviSecurityUser] Shutdown session snapshot', [
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

	public function reset() : void
	{
		$this->authenticated = null;
		$this->credentials = null;
		$this->context = null;
		$this->parameters = [];
		// reset parent
		parent::reset();
	}
}

?>