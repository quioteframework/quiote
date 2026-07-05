<?php
namespace Quiote\Routing;

use Quiote\User\ISecurityUser;

/**
 * RoutingUserSource allows you to provide an user source for the routing
 * @since      1.0.0
 * @version    1.0.0
 */
class RoutingUserSource implements IRoutingSource
{
	/**
	 * @var        ISecurityUser An user instance.
	 */
	protected $user = null;

	/**
	 * Constructor.
	 * @param      ISecurityUser $user An user instance.
	 * @since      1.0.0
	 */
	public function __construct(ISecurityUser $user)
	{
		$this->user = $user;
	}

	/**
	 * Retrieves the value for a given entry from the source.
	 * @param      array<int, string> $parts An array with the name parts for the entry.
	 * @return     mixed The value.
	 * @since      1.0.0
	 */
	public function getSource(array $parts)
	{
		if($parts[0] == 'authenticated') {
			return (int) $this->user->isAuthenticated();
		} elseif($parts[0] == 'credentials' && count($parts) > 1) {
			// throw the 'credentials' entry away and check with the parameters left
			array_shift($parts);
			return (int) $this->user->hasCredentials($parts);
		}

		return null;
	}
}

?>