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
namespace Agavi\Action;
/**
 * AgaviAction allows you to separate application and business logic from your
 * presentation. By providing a core set of methods used by the framework,
 * automation in the form of security and validation can occur.
 *
 * @package    agavi
 * @subpackage action
 *
 * @author     Sean Kerr <skerr@mojavi.org>
 * @author     David Zülke <dz@bitxtender.com>
 * @copyright  Authors
 * @copyright  The Agavi Project
 *
 * @since      0.9.0
 *
 * @version    $Id$
 */

use Agavi\Execution\ActionInitContext;
use Agavi\Request\AgaviWebRequest;
use Symfony\Contracts\Service\ResetInterface;
abstract class AgaviAction implements ResetInterface
{
	/**
	 * @var ActionInitContext|null Lightweight initialization context (replaces legacy execution container).
	 */
	protected $initContext = null;

	/**
	 * @var        AgaviContext An AgaviContext instance.
	 */
	protected $context = null;

	/**
	 * Retrieve the current application context.
	 *
	 * @return     AgaviContext The current AgaviContext instance.
	 *
	 * @author     Sean Kerr <skerr@mojavi.org>
	 * @since      0.9.0
	 */
	public final function getContext()
	{
		return $this->context;
	}

	/**
	 * Backward compatible accessor (legacy name) for the init context.
	 *
	 * @deprecated Will be removed once all userland code migrates to getInitContext().
	 */
	public final function getContainer()
	{
		return $this->initContext;
	}

	/**
	 * Retrieve the initialization context for this action.
	 */
	public final function getInitContext(): ?ActionInitContext
	{
		return $this->initContext;
	}

	/**
	 * Retrieve the credential required to access this action.
	 *
	 * @return     mixed Data that indicates the level of security for this
	 *                   action.
	 *
	 * @author     Sean Kerr <skerr@mojavi.org>
	 * @author     David Zülke <dz@bitxtender.com>
	 * @since      0.9.0
	 */
	public function getCredentials()
	{
		return null;
	}

	/**
	 * Execute any post-validation error application logic.
	 *
	 * @param      AgaviWebRequest The action's request data holder.
	 *
	 * @return     mixed A string containing the view name associated with this
	 *                   action.
	 *                   Or an array with the following indices:
	 *                   - The parent module of the view that will be executed.
	 *                   - The view that will be executed.
	 *
	 * @author     Sean Kerr <skerr@mojavi.org>
	 * @author     David Zülke <dz@bitxtender.com>
	 * @since      0.9.0
	 */
	public function handleError(AgaviWebRequest $rd)
	{
		return 'Error';
	}

	/**
	 * Initialize this action with a lightweight initialization context.
	 */
	public function initialize(ActionInitContext $context)
	{
		$this->initContext = $context;
		$this->context = $context->getContext();
	}

	/**
	 * Indicates that this action requires security.
	 *
	 * @return     bool true, if this action requires security, otherwise false.
	 *
	 * @author     Sean Kerr <skerr@mojavi.org>
	 * @since      0.9.0
	 */
	public function isSecure()
	{
		return false;
	}

	/**
	 * Whether or not this action is "simple", i.e. doesn't use validation etc.
	 *
	 * @return     bool true, if this action should act in simple mode, or false.
	 *
	 * @author     David Zülke <dz@bitxtender.com>
	 * @since      0.11.0
	 */
	public function isSimple()
	{
		return false;
	}

	/**
	 * Indicates whether this action's output may be cached. Default false.
	 * Framework middleware will call this unconditionally (no method_exists guard).
	 */
	public function isCacheable(?string $outputType = null): bool
	{
		return false;
	}

	/**
	 * TTL (seconds) for cached content when isCacheable() returns true. Default null (framework default handling).
	 */
	public function cacheTtlSeconds(?string $outputType = null): ?int
	{
		return null;
	}

	/**
	 * Manually register validators for this action.
	 *
	 * @author     Sean Kerr <skerr@mojavi.org>
	 * @since      0.9.0
	 */
	public function registerValidators()
	{
	}

	/**
	 * Manually validate files and parameters.
	 *
	 * @param      AgaviWebRequest The action's request data holder.
	 *
	 * @return     bool true, if validation completed successfully, otherwise
	 *                  false.
	 *
	 * @author     Sean Kerr <skerr@mojavi.org>
	 * @author     David Zülke <dz@bitxtender.com>
	 * @since      0.9.0
	 */
	public function validate(AgaviWebRequest $request)
	{
		return true;
	}

	/**
	 * Get the default View name if this Action doesn't serve the Request method.
	 *
	 * @return     mixed A string containing the view name associated with this
	 *                   action.
	 *                   Or an array with the following indices:
	 *                   - The parent module of the view that will be executed.
	 *                   - The view that will be executed.
	 *
	 * @author     David Zülke <dz@bitxtender.com>
	 * @since      0.11.0
	 */
	public function getDefaultViewName()
	{
		return 'Input';
	}

	/**
	 * @see        AgaviAttributeHolder::clearAttributes()
	 *
	 * @author     David Zülke <dz@bitxtender.com>
	 * @since      0.9.0
	 */
	public function clearAttributes()
	{
		if($this->initContext instanceof \Agavi\Util\AgaviAttributeHolder) {
			$this->initContext->clearAttributes();
		}
	}

	/**
	 * @see        AgaviAttributeHolder::getAttribute()
	 *
	 * @author     David Zülke <dz@bitxtender.com>
	 * @since      0.9.0
	 */
	public function &getAttribute($name, $default = null)
	{
		if($this->initContext instanceof \Agavi\Util\AgaviAttributeHolder) {
			return $this->initContext->getAttribute($name, null, $default);
		}
		return $default;
	}

	/**
	 * @see        AgaviAttributeHolder::getAttributeNames()
	 *
	 * @author     David Zülke <dz@bitxtender.com>
	 * @since      0.9.0
	 */
	public function getAttributeNames()
	{
		if($this->initContext instanceof \Agavi\Util\AgaviAttributeHolder) {
			return $this->initContext->getAttributeNames();
		}
		return [];
	}

	/**
	 * @see        AgaviAttributeHolder::getAttributes()
	 *
	 * @author     David Zülke <dz@bitxtender.com>
	 * @since      0.11.0
	 */
	public function &getAttributes()
	{
		if($this->initContext instanceof \Agavi\Util\AgaviAttributeHolder) {
			return $this->initContext->getAttributes();
		}
		$empty = [];
		return $empty;
	}

	/**
	 * @see        AgaviAttributeHolder::hasAttribute()
	 *
	 * @author     David Zülke <dz@bitxtender.com>
	 * @since      0.9.0
	 */
	public function hasAttribute($name)
	{
		if($this->initContext instanceof \Agavi\Util\AgaviAttributeHolder) {
			return $this->initContext->hasAttribute($name);
		}
		return false;
	}

	/**
	 * @see        AgaviAttributeHolder::removeAttribute()
	 *
	 * @author     David Zülke <dz@bitxtender.com>
	 * @since      0.9.0
	 */
	public function &removeAttribute($name)
	{
		if($this->initContext instanceof \Agavi\Util\AgaviAttributeHolder) {
			return $this->initContext->removeAttribute($name);
		}
		$null = null; return $null;
	}

	/**
	 * @see        AgaviAttributeHolder::setAttribute()
	 *
	 * @author     David Zülke <dz@bitxtender.com>
	 * @since      0.9.0
	 */
	public function setAttribute($name, $value)
	{
		if($this->initContext instanceof \Agavi\Util\AgaviAttributeHolder) {
			$this->initContext->setAttribute($name, $value);
		}
	}

	/**
	 * @see        AgaviAttributeHolder::appendAttribute()
	 *
	 * @author     David Zülke <dz@bitxtender.com>
	 * @since      0.10.0
	 */
	public function appendAttribute($name, $value)
	{
		if($this->initContext instanceof \Agavi\Util\AgaviAttributeHolder) {
			$this->initContext->appendAttribute($name, $value);
		}
	}

	/**
	 * @see        AgaviAttributeHolder::setAttributeByRef()
	 *
	 * @author     David Zülke <dz@bitxtender.com>
	 * @since      0.9.0
	 */
	public function setAttributeByRef($name, &$value)
	{
		if($this->initContext instanceof \Agavi\Util\AgaviAttributeHolder) {
			$this->initContext->setAttributeByRef($name, $value);
		}
	}

	/**
	 * @see        AgaviAttributeHolder::appendAttributeByRef()
	 *
	 * @author     David Zülke <dz@bitxtender.com>
	 * @since      0.10.0
	 */
	public function appendAttributeByRef($name, &$value)
	{
		if($this->initContext instanceof \Agavi\Util\AgaviAttributeHolder) {
			$this->initContext->appendAttributeByRef($name, $value);
		}
	}

	/**
	 * @see        AgaviAttributeHolder::setAttributes()
	 *
	 * @author     David Zülke <dz@bitxtender.com>
	 * @since      0.9.0
	 */
	public function setAttributes(array $attributes)
	{
		if($this->initContext instanceof \Agavi\Util\AgaviAttributeHolder) {
			$this->initContext->setAttributes($attributes);
		}
	}

	/**
	 * @see        AgaviAttributeHolder::setAttributesByRef()
	 *
	 * @author     David Zülke <dz@bitxtender.com>
	 * @since      0.9.0
	 */
	public function setAttributesByRef(array &$attributes)
	{
		if($this->initContext instanceof \Agavi\Util\AgaviAttributeHolder) {
			$this->initContext->setAttributesByRef($attributes);
		}
	}

	/**
	 * Reset action state for FrankenPHP worker compatibility.
	 * Clears request-specific properties that could leak between requests.
	 *
	 * @author     Generated for FrankenPHP worker compatibility
	 * @since      1.1.0
	 */
	public function reset(): void
	{
		$this->initContext = null;
		$this->context = null;
	}
}

?>