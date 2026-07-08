<?php
namespace Quiote\User;

use Quiote\Context;
use Quiote\Config\Config;
use Quiote\Config\ConfigCache;
use Symfony\Contracts\Service\ResetInterface;

/**
 * RbacUser will handle roles and permissions for users
 * @since      1.0.0
 * @version    1.0.0
 */
class RbacSecurityUser extends SecurityUser implements ISecurityUser, ResetInterface
{
	/**
	 * The namespace under which roles will be stored.
	 */
	const ROLES_NAMESPACE = 'org.quiote.user.RbacSecurityUser.roles';

	/**
	 * @var        ?array<string, array{permissions: array<int, string>, parent?: string}> An array of roles and permissions.
	 */
	protected $definitions = null;

	/**
	 * @var        array<int, string> An array of roles the user is assigned to.
	 */
	protected $roles = null;

	/**
	 * Set a role membership for this user.
	 * @param      string $role The role name to add to this user.
	 * @return     void
	 * @since      1.0.0
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
	 * @param      array<int, string> $roles An array of role names.
	 * @return     void
	 * @since      1.0.0
	 */
	public function grantRoles(array $roles)
	{
		foreach($roles as $role) {
			$this->grantRole($role);
		}
	}
	
	/**
	 * Revoke a role membership for this user.
	 * @param      string $role The role name to remove from this user.
	 * @return     void
	 * @since      1.0.0
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
	 * @param      string $role The role name to remove from this user.
	 * @return     bool Whether or not the user is a member of the given role.
	 * @since      1.0.0
	 */
	public function hasRole($role)
	{
		return in_array($role, $this->roles);
	}
	
	/**
	 * Return a list of roles this user has been granted.
	 * @return     array<int, string> An array of role names.
	 * @since      1.0.0
	 */
	public function getRoles()
	{
		return $this->roles;
	}
	
	/**
	 * Revoke all roles.
	 * @return     void
	 * @since      1.0.0
	 */
	public function revokeAllRoles()
	{
		foreach($this->roles as $role) {
			$this->revokeRole($role);
		}
	}
	
	/**
	 * Initialize this User.
	 * @param      Context $context An Context instance.
	 * @param      array<string, mixed> $parameters An associative array of initialization parameters.
	 * @return     void
	 * @throws     \Quiote\Exception\InitializationException If an error occurs while
	 *                                                 initializing this User.
	 * @since      1.0.0
	 */
	#[\Override]
    public function initialize(Context $context, array $parameters = [])
	{
		parent::initialize($context, $parameters);

		$this->loadDefinitions();

		if($this->isTokenDerived()) {
			// Token-authenticated identities have their roles re-granted from
			// fresh claims each request (see SecurityUser::$tokenDerived); a
			// stale session role set must not be rehydrated here.
			$this->roles = [];
			return;
		}

		$storedRolesRaw = $this->getContext()->getStorage()->retrieve(self::ROLES_NAMESPACE);
		$this->roles = is_array($storedRolesRaw) ? array_values(array_filter($storedRolesRaw, 'is_string')) : [];

		if(!$this->authenticated) {
			$this->roles = [];
		} else if (count($this->roles) > 0) {
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
				$logger = \Quiote\Logging\Log::for($this);
				if ($logger->isEnabled(\Quiote\Logging\Level::Debug)) {
					$logger->debug('[RbacSecurityUser.initialize] rebuilt creds rolesIn=' . count($storedRoles) . ' rolesNow=' . count($this->roles) . ' credsNow=' . count($this->credentials ?? []));
				}
			} catch (\Throwable) {}
		}
	}

	/**
	 * Load RBAC role and permission definitions.
	 * @return     void
	 * @since      1.0.0
	 */
	protected function loadDefinitions()
	{
		$cfg = $this->getParameter('definitions_file', Config::getString('core.config_dir') . '/rbac_definitions.xml');

		if(is_string($cfg) && is_readable($cfg)) {
			$this->definitions = include(ConfigCache::checkConfig($cfg, $this->getContext()->getName()));
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
		$logger = \Quiote\Logging\Log::for($this);
		if ($logger->isEnabled(\Quiote\Logging\Level::Debug)) {
			$logger->debug('RbacSecurityUser storing roles', ['class' => static::class, 'namespace' => self::ROLES_NAMESPACE, 'roles_count' => count($this->roles)]);
		}
		$this->getContext()->getStorage()->store(self::ROLES_NAMESPACE, $this->roles);
	// Note: credentials are stored by parent SecurityUser::shutdown(). If they were
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