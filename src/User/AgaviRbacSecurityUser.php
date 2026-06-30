<?php

// +---------------------------------------------------------------------------+
// | This file is part of the Agavi package.                                   |
// | Copyright (c) 2005-2011 the Agavi Project.                                |
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
use Agavi\Config\AgaviConfig;
use Agavi\Config\AgaviConfigCache;
use Symfony\Contracts\Service\ResetInterface;

/**
 * AgaviRbacUser will handle roles and permissions for users
 *
 * @package    agavi
 * @subpackage user
 *
 * @copyright  David Zülke <dz@bitxtender.com>
 * @copyright  Authors
 * @copyright  The Agavi Project
 *
 * @since      0.11.0
 *
 * @version    $Id$
 */
class AgaviRbacSecurityUser extends AgaviSecurityUser implements AgaviISecurityUser, ResetInterface
{
	/**
	 * The namespace under which roles will be stored.
	 */
	const ROLES_NAMESPACE = 'org.agavi.user.RbacSecurityUser.roles';

	/**
	 * @var        array An array of roles and permissions.
	 */
	protected $definitions = null;

	/**
	 * @var        array An array of roles the user is assigned to.
	 */
	protected $roles = null;

	/**
	 * Set a role membership for this user.
	 *
	 * @param      string The role name to add to this user.
	 *
	 * @author     David Zülke <dz@bitxtender.com>
	 * @since      0.11.0
	 */
	public function grantRole($role)
	{
		if(isset($this->definitions[$role]) && !in_array($role, $this->roles)) {
			$this->roles[] = $role;
			
			$next =& $this->definitions[$role];
			while(isset($next)) {
				foreach($next['permissions'] as $permission) {
					$this->addCredential($permission);
				}
				if(isset($next['parent'])) {
					$next =& $this->definitions[$next['parent']];
				} else {
					unset($next);
				}
			}
		}
	}
	
	/**
	 * Set many role memberships for this user.
	 *
	 * @param      array An array of role names.
	 *
	 * @author     David Zülke <dz@bitxtender.com>
	 * @since      0.11.0
	 */
	public function grantRoles(array $roles)
	{
		foreach($roles as $role) {
			$this->grantRole($role);
		}
	}
	
	/**
	 * Revoke a role membership for this user.
	 *
	 * @param      string The role name to remove from this user.
	 *
	 * @author     David Zülke <dz@bitxtender.com>
	 * @since      0.11.0
	 */
	public function revokeRole($role)
	{
		if(isset($this->definitions[$role]) && ($key = array_search($role, $this->roles)) !== false) {
			unset($this->roles[$key]);
			$this->clearCredentials();
			foreach($this->roles as $role) {
				$this->grantRole($role);
			}
		}
	}
	
	/**
	 * Check whether or not a user is a member of a certain role.
	 *
	 * @param      string The role name to remove from this user.
	 *
	 * @return     bool Whether or not the user is a member of the given role.
	 *
	 * @author     David Zülke <dz@bitxtender.com>
	 * @since      0.11.0
	 */
	public function hasRole($role)
	{
		return in_array($role, $this->roles);
	}
	
	/**
	 * Return a list of roles this user has been granted.
	 *
	 * @return     array An array of role names.
	 *
	 * @author     David Zülke <dz@bitxtender.com>
	 * @since      0.11.0
	 */
	public function getRoles()
	{
		return $this->roles;
	}
	
	/**
	 * Revoke all roles.
	 *
	 * @author     David Zülke <dz@bitxtender.com>
	 * @since      0.11.0
	 */
	public function revokeAllRoles()
	{
		foreach($this->roles as $role) {
			$this->revokeRole($role);
		}
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
	 * @author     David Zülke <dz@bitxtender.com>
	 * @author     Harald Kirschner <mail@digitarald.de>
	 * @since      0.11.0
	 */
	#[\Override]
    public function initialize(AgaviContext $context, array $parameters = [])
	{
		parent::initialize($context, $parameters);

		$this->loadDefinitions();
		
		$this->roles = (array) $this->context->getStorage()->retrieve(self::ROLES_NAMESPACE);

		if(!$this->authenticated) {
			$this->roles = [];
		} else if (is_array($this->roles) && count($this->roles) > 0) {
			// We have stored roles. To (re)derive credentials we must NOT skip grantRole() just
			// because the role already appears in $this->roles. The original implementation
			// populated $this->roles first, then called grantRole() which bails when the role
			// already exists, resulting in zero credentials. Fix: capture stored roles, reset
			// roles & credentials, then re-grant so permissions are added.
			$storedRoles = $this->roles;
			$this->roles = [];
			$this->clearCredentials();
			foreach ($storedRoles as $role) {
				$this->grantRole($role);
			}
			try {
				if (\Agavi\Util\DebugFlags::$security) {
					if(!is_dir('/app/log')) { @mkdir('/app/log',0777,true); }
					@file_put_contents('/app/log/agavi_user_debug.log', '[RbacSecurityUser.initialize] rebuilt creds rolesIn=' . count($storedRoles) . ' rolesNow=' . count($this->roles) . ' credsNow=' . count($this->credentials ?? []) . "\n", FILE_APPEND);
				}
			} catch (\Throwable) {}
		}
	}

	/**
	 * Load RBAC role and permission definitions.
	 *
	 * @author     David Zülke <dz@bitxtender.com>
	 * @since      0.11.0
	 */
	protected function loadDefinitions()
	{
		$cfg = $this->getParameter('definitions_file', AgaviConfig::get('core.config_dir') . '/rbac_definitions.xml');
		
		if(is_readable($cfg)) {
			$this->definitions = include(AgaviConfigCache::checkConfig($cfg, $this->context->getName()));
		}
	}

	/**
	 * Execute the shutdown procedure.
	 *
	 * @author     Harald Kirschner <mail@digitarald.de>
	 * @author     David Zülke <dz@bitxtender.com>
	 * @since      0.11.0
	 */
	#[\Override]
    public function shutdown()
	{
		$logger = $this->context?->getLoggerManager()?->getLogger();
		if (\Agavi\Util\DebugFlags::$security) {
			$logger?->debug('RbacSecurityUser storing roles', ['class' => static::class, 'namespace' => self::ROLES_NAMESPACE, 'roles_count' => is_array($this->roles) ? count($this->roles) : 0]);
		}
		$this->context->getStorage()->store(self::ROLES_NAMESPACE, $this->roles);
	// Note: credentials are stored by parent AgaviSecurityUser::shutdown(). If they were
	// rebuilt during initialize, they will be persisted here.
		
		// call the parent shutdown method
		parent::shutdown();
	}

	#[\Override]
    public function reset() : void
	{
		$this->context = null;
		$this->parameters = [];
		$this->attributes = [];
		$this->roles = [];
		$this->definitions = null;
		
		parent::reset();
	}
}

?>