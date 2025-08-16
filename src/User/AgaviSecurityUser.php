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
		try { @file_put_contents('/tmp/agavi_user_debug.log', '[SecurityUser.initialize] eff auth=' . var_export($this->authenticated,true) . " creds=".json_encode($this->credentials)." storedAuth=".var_export($storedAuth,true)."\n", FILE_APPEND); } catch(\Throwable) {}
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
			// immediate persistence so later initialize() pulls true
			try { $this->getContext()?->getStorage()?->store(self::AUTH_NAMESPACE, true); } catch(\Throwable) {}

			return;
		}

		$this->authenticated = false;
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
		$logger?->debug('SecurityUser shutdown storing authenticated status', ['class' => get_class($this), 'namespace' => self::AUTH_NAMESPACE]);
		$logger?->debug('SecurityUser shutdown storing credentials', ['class' => get_class($this), 'namespace' => self::CREDENTIAL_NAMESPACE]);
		$storage = $this->getContext()->getStorage();

		// store credentials to the storage
		$storage->store(self::AUTH_NAMESPACE,       $this->authenticated);
		$storage->store(self::CREDENTIAL_NAMESPACE, $this->credentials);
		try { @file_put_contents('/tmp/agavi_user_debug.log', '[SecurityUser.shutdown] stored auth=' . var_export($this->authenticated,true) . " creds=".json_encode($this->credentials)."\n", FILE_APPEND); } catch(\Throwable) {}

		// Debug: Check what's in the session after storing
		$logger?->debug('SecurityUser shutdown session snapshot', [
			'session' => isset($_SESSION) ? array_keys($_SESSION) : [],
			'session_id' => function_exists('session_id') ? session_id() : null,
			'session_status' => function_exists('session_status') ? session_status() : null,
		]);

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