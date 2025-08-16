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
namespace Agavi\Filter;

use Agavi\Config\AgaviConfig;
use Agavi\Config\AgaviConfigCache;
use Agavi\Controller\AgaviExecutionContainer;
use Agavi\Exception\AgaviException;
use Agavi\Exception\AgaviUncacheableException;
use Agavi\Request\AgaviRequestDataHolder;
use Agavi\User\AgaviISecurityUser;
use Agavi\Util\AgaviArrayPathDefinition;
use Agavi\Util\AgaviToolkit;
use Agavi\View\AgaviView;

/**
 * AgaviExecutionFilter is the last filter registered for each filter chain.
 * This filter does all action and view execution.
 *
 * @package    agavi
 * @subpackage filter
 *
 * @author     David Zülke <dz@bitxtender.com>
 * @author     Sean Kerr <skerr@mojavi.org>
 * @copyright  Authors
 * @copyright  The Agavi Project
 *
 * @since      0.9.0
 *
 * @version    $Id$
 */
class AgaviExecutionFilter extends AgaviFilter implements AgaviIActionFilter
{
	// Legacy caching system removed: this filter now only runs action + view.
	
	/**
	 * Check if a cache exists and is up-to-date
	 *
	 * @param      array  An array of cache groups
	 * @param      string The lifetime of the cache as a strtotime relative string
	 *                    without the leading plus sign.
	 *
	 * @return     bool true, if the cache is up to date, otherwise false
	 *
	 * @author     David Zülke <dz@bitxtender.com>
	 * @since      0.11.0
	 */
	// Legacy cache file operations removed.

	/**
	 * Read the contents of a cache
	 *
	 * @param      array An array of cache groups
	 *
	 * @return     array The cache data
	 *
	 * @author     David Zülke <dz@bitxtender.com>
	 * @since      0.11.0
	 */
	public function readCache(array $groups) { throw new AgaviException('Legacy caching removed'); }

	/**
	 * Write cache content
	 *
	 * @param      array  An array of cache groups
	 * @param      array  The cache data
	 * @param      string The lifetime of the cache as a strtotime relative string
	 *                    without the leading plus sign.
	 *
	 * @return     bool The result of the write operation
	 *
	 * @author     David Zülke <dz@bitxtender.com>
	 * @since      0.11.0
	 */
	public function writeCache(array $groups, $data, $lifetime = null) { throw new AgaviException('Legacy caching removed'); }

	/**
	 * Flushes the cache for a group
	 *
	 * @param      array An array of cache groups
	 *
	 * @author     David Zülke <dz@bitxtender.com>
	 * @since      0.11.0
	 */
	public static function clearCache(array $groups = []) { /* no-op */ }

	/**
	 * Builds an array of cache groups using the configuration and a container.
	 *
	 * @param      array                   The group array from the configuration.
	 * @param      AgaviExecutionContainer The execution container.
	 *
	 * @return     array An array of groups.
	 *
	 * @author     David Zülke <dz@bitxtender.com>
	 * @since      0.11.0
	 */
	public function determineGroups(array $groups, AgaviExecutionContainer $container)
	{
		$retval = [];

		foreach($groups as $group) {
			$group += ['name' => null, 'source' => null, 'namespace' => null];
			$val = $this->getVariable($group['name'], $group['source'], $group['namespace'], $container);
			
			if(is_object($val) && is_callable([$val, '__toString'])) {
				$val = $val->__toString();
			} elseif(is_object($val)) {
				$val = spl_object_hash($val);
			}
			
			if($val === null || $val === false || $val === '') {
				$val = '0';
			}
			
			if(!is_scalar($val)) {
				throw new AgaviUncacheableException('Group value is not a scalar, cannot construct a meaningful string representation.');
			}
			
			$retval[] = $val;
		}

		$retval[] = $container->getModuleName() . '_' . $container->getActionName();

		return $retval;
	}

	/**
	 * Read a variable from the given source and, optionally, namespace.
	 *
	 * @param      string The variable name.
	 * @param      string The optional variable source.
	 * @param      string The optional namespace in the source.
	 * @param      AgaviExecutionContainer The container to use, if necessary.
	 *
	 * @return     mixed The variable.
	 *
	 * @author     David Zülke <dz@bitxtender.com>
	 * @since      0.11.0
	 */
	public function getVariable($name, $source = 'string', $namespace = null, ?AgaviExecutionContainer $container = null)
	{
		$val = $name;
		
		switch($source) {
			case 'callback':
				$val = $container->getActionInstance()->$name();
				break;
			case 'configuration_directive':
				$val = AgaviConfig::get($name);
				break;
			case 'constant':
				$val = constant($name);
				break;
			case 'container_parameter':
				$val = $container->getParameter($name);
				break;
			case 'global_request_data':
				$val = $this->context->getRequest()->getRequestData()->get($namespace ?: AgaviRequestDataHolder::SOURCE_PARAMETERS, $name);
				break;
			case 'locale':
				$val = $this->context->getTranslationManager()->getCurrentLocaleIdentifier();
				break;
			case 'request_attribute':
				$val = $this->context->getRequest()->getAttribute($name, $namespace);
				break;
			case 'request_data':
				$val = $container->getRequestData()->get($namespace ?: AgaviRequestDataHolder::SOURCE_PARAMETERS, $name);
				break;
			case 'request_parameter':
				$val = $this->context->getRequest()->getRequestData()->getParameter($name);
				break;
			case 'user_attribute':
				$val = $this->context->getUser()->getAttribute($name, $namespace);
				break;
			case 'user_authenticated':
				if(($user = $this->context->getUser()) instanceof AgaviISecurityUser) {
					$val = $user->isAuthenticated();
				}
				break;
			case 'user_credential':
				if(($user = $this->context->getUser()) instanceof AgaviISecurityUser) {
					$val = $user->hasCredentials($name);
				}
				break;
			case 'user_parameter':
				$val = $this->context->getUser()->getParameter($name);
				break;
		}
		
		return $val;
	}

	/**
	 * Execute this filter.
	 *
	 * @param      AgaviFilterChain The filter chain.
	 * @param      AgaviExecutionContainer The current execution container.
	 *
	 * @throws     <b>AgaviInitializationException</b> If an error occurs during
	 *                                                 View initialization.
	 * @throws     <b>AgaviViewException</b>           If an error occurs while
	 *                                                 executing the View.
	 *
	 * @author     David Zülke <dz@bitxtender.com>
	 * @author     Felix Gilcher <felix.gilcher@bitextender.com>
	 * @author     Sean Kerr <skerr@mojavi.org>
	 * @since      0.9.0
	 */
	#[\Override]
    public function execute(AgaviFilterChain $filterChain, AgaviExecutionContainer $container)
	{
		
		 // This filter is the end of the chain: just run the action and view.
		// Do NOT call $container->execute() or the filter chain again!

		// get the context, controller and validator manager
		$controller = $this->context->getController();

		// get the current action information
		$actionName = $container->getActionName();
		$moduleName = $container->getModuleName();
		
		// the action instance
		$actionInstance = $container->getActionInstance();

		$request = $this->context->getRequest();


		// Simplified: just run action and view without caching.
		[$viewModule, $viewName] = $container->runAction();
		if($viewName !== AgaviView::NONE) {
			$container->setViewModuleName($viewModule);
			$container->setViewName($viewName);
			$container->getResponse()->clear();
			$result = $this->executeView($container);
			if($result !== null) {
				$container->getResponse()->setContent($result);
			} else {
				// Legacy compatibility: view relied on layers + implicit rendering
				try {
					$viewInstance = $container->getViewInstance();
					if(method_exists($viewInstance,'getLayers') && $viewInstance->getLayers() && method_exists($viewInstance,'renderLayers')) {
						$layerContent = $viewInstance->renderLayers();
						if($layerContent !== '') { $container->getResponse()->setContent($layerContent); }
					}
				} catch(\Throwable) { /* soft fail */ }
			}
		}

	}

	public function isPostFilter(): bool
	{
		return false;
	}

	/**
	 * Execute this container's view instance
	 * 
	 * @return     mixed the view's result
	 * 
	 * @author     David Zülke <david.zuelke@bitextender.com>
	 * @author     Felix Gilcher <felix.gilcher@bitextender.com>
	 * @since      1.0.0
	 */
	protected function executeView(AgaviExecutionContainer $container)
	{
		$outputType = $container->getOutputType()->getName();
		$request = $this->context->getRequest();
		$viewInstance = $container->getViewInstance();
		
		$executeMethod = 'execute' . $outputType;
		if(!is_callable([$viewInstance, $executeMethod])) {
			$executeMethod = 'execute';
		}
		$key = $request->toggleLock();
		try {
			$viewResult = $viewInstance->$executeMethod($container->getRequestData());
		} catch(\Exception $e) {
			$request->toggleLock($key);
			throw $e;
		}
		$request->toggleLock($key);
		return $viewResult;
	}
	
}

?>