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
	/*
	 * The directory inside %core.cache_dir% where cached stuff is stored.
	 */
	const CACHE_SUBDIR = 'content';

	/*
	 * The name of the file that holds the cached action data.
	 * Minuses because these are not allowed in an output type name.
	 */
	const ACTION_CACHE_ID = '4-8-15-16-23-42';

	/*
	 * Constants for the cache callback event types.
	 */
	const CACHE_CALLBACK_ACTION_NOT_CACHED = 0;
	const CACHE_CALLBACK_ACTION_CACHE_GONE = 1;
	const CACHE_CALLBACK_VIEW_NOT_CACHEABLE = 2;
	const CACHE_CALLBACK_VIEW_NOT_CACHED = 3;
	const CACHE_CALLBACK_OUTPUT_TYPE_NOT_CACHEABLE = 4;
	const CACHE_CALLBACK_VIEW_CACHE_GONE = 5;
	const CACHE_CALLBACK_ACTION_CACHE_USELESS = 6;
	const CACHE_CALLBACK_VIEW_CACHE_WRITTEN = 7;
	const CACHE_CALLBACK_ACTION_CACHE_WRITTEN = 8;
	
	/**
	 * Method that's called when a cacheable Action/View with a stale cache is
	 * about to be run.
	 * Can be used to prevent stampede situations where many requests to an action
	 * with an out-of-date cache are run in parallel, slowing down everything.
	 * For instance, you could set a flag into memcached with the groups of the
	 * action that's currently run, and in checkCache check for those and return
	 * an old, stale cache until the flag is gone.
	 *
	 * @param      int                     The type of the event that occurred.
	 *                                     See CACHE_CALLBACK_* constants.
	 * @param      array                   The groups.
	 * @param      array                   The caching configuration.
	 * @param      AgaviExecutionContainer The current execution container.
	 *
	 * @author     David Zülke <david.zuelke@bitextender.com>
	 * @since      1.0.0
	 */
	public function startedCacheCreationCallback($eventType, array $groups, array $config, AgaviExecutionContainer $container)
	{
	}
	
	/**
	 * Method that's called when an Action/View that was assumed to be cacheable
	 * turned out not to be (because the View or Output Type isn't).
	 *
	 * @see        AgaviExecutionFilter::startedCacheCreationCallback()
	 *
	 * @param      int                     The type of the event that occurred.
	 *                                     See CACHE_CALLBACK_* constants.
	 * @param      array                   The groups.
	 * @param      array                   The caching configuration.
	 * @param      AgaviExecutionContainer The current execution container.
	 *
	 * @author     David Zülke <david.zuelke@bitextender.com>
	 * @since      1.0.0
	 */
	public function abortedCacheCreationCallback($eventType, array $groups, array $config, AgaviExecutionContainer $container)
	{
	}
	
	/**
	 * Method that's called when a cacheable Action/View with a stale cache has
	 * finished execution and all caches are written.
	 *
	 * @see        AgaviExecutionFilter::startedCacheCreationCallback()
	 *
	 * @param      int                     The type of the event that occurred.
	 *                                     See CACHE_CALLBACK_* constants.
	 * @param      array                   The groups.
	 * @param      array                   The caching configuration.
	 * @param      AgaviExecutionContainer The current execution container.
	 *
	 * @author     David Zülke <david.zuelke@bitextender.com>
	 * @since      1.0.0
	 */
	public function finishedCacheCreationCallback($eventType, array $groups, array $config, AgaviExecutionContainer $container)
	{
	}
	
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
	public function checkCache(array $groups, $lifetime = null)
	{
		foreach($groups as &$group) {
			$group = base64_encode((string) $group);
		}
		$filename = AgaviConfig::get('core.cache_dir') . DIRECTORY_SEPARATOR . self::CACHE_SUBDIR . DIRECTORY_SEPARATOR . implode(DIRECTORY_SEPARATOR, $groups) . '.cefcache';
		$isReadable = is_readable($filename);
		if($lifetime === null || !$isReadable) {
			return $isReadable;
		} else {
			$expiry = strtotime('+' . $lifetime, filemtime($filename));
			if($expiry !== false) {
				return $isReadable && ($expiry >= time());
			} else {
				return false;
			}
		}
	}

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
	public function readCache(array $groups)
	{
		foreach($groups as &$group) {
			$group = base64_encode((string) $group);
		}
		$filename = AgaviConfig::get('core.cache_dir') . DIRECTORY_SEPARATOR . self::CACHE_SUBDIR . DIRECTORY_SEPARATOR . implode(DIRECTORY_SEPARATOR, $groups) . '.cefcache';
		$data = @file_get_contents($filename);
		if($data !== false) {
			return unserialize($data);
		} else {
			throw new AgaviException(sprintf('Failed to read cache file "%s"', $filename));
		}
	}

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
	public function writeCache(array $groups, $data, $lifetime = null)
	{
		// lifetime is not used in this implementation!
		
		foreach($groups as &$group) {
			$group = base64_encode((string) $group);
		}
		@mkdir(AgaviConfig::get('core.cache_dir') . DIRECTORY_SEPARATOR  . self::CACHE_SUBDIR . DIRECTORY_SEPARATOR . implode(DIRECTORY_SEPARATOR , array_slice($groups, 0, -1)), 0777, true);
		return file_put_contents(AgaviConfig::get('core.cache_dir') . DIRECTORY_SEPARATOR . self::CACHE_SUBDIR . DIRECTORY_SEPARATOR . implode(DIRECTORY_SEPARATOR, $groups) . '.cefcache', serialize($data), LOCK_EX);
	}

	/**
	 * Flushes the cache for a group
	 *
	 * @param      array An array of cache groups
	 *
	 * @author     David Zülke <dz@bitxtender.com>
	 * @since      0.11.0
	 */
	public static function clearCache(array $groups = [])
	{
		foreach($groups as &$group) {
			$group = base64_encode((string) $group);
		}
		$path = self::CACHE_SUBDIR . DIRECTORY_SEPARATOR . implode(DIRECTORY_SEPARATOR, $groups);
		if(is_file(AgaviConfig::get('core.cache_dir') . DIRECTORY_SEPARATOR . $path . '.cefcache')) {
			AgaviToolkit::clearCache($path . '.cefcache');
		} else {
			AgaviToolkit::clearCache($path);
		}
	}

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

		$isCacheable = false;
		$cachingDotXml = AgaviToolkit::evaluateModuleDirective(
			$moduleName,
			'agavi.cache.path',
			[
				'moduleName' => $moduleName,
				'actionName' => $actionName,
			]
		);
		if($this->getParameter('enable_caching', true) && is_readable($cachingDotXml)) {
			include(AgaviConfigCache::checkConfig($cachingDotXml, $this->context->getName()));
		}

		$isActionCached = false;

		if($isCacheable) {
			try {
				$groups = $this->determineGroups($config['groups'], $container);
				$actionGroups = array_merge($groups, [self::ACTION_CACHE_ID]);
			} catch(AgaviUncacheableException) {
				$isCacheable = false;
			}
			if($isCacheable) {
				$isActionCached = $this->checkCache(array_merge($groups, [self::ACTION_CACHE_ID]), $config['lifetime']);
			
				if(!$isActionCached) {
					$this->startedCacheCreationCallback(self::CACHE_CALLBACK_ACTION_NOT_CACHED, $actionGroups, $config, $container);
				}
			}
		}

		if($isActionCached) {
			try {
				$actionCache = $this->readCache($actionGroups);
				$actionInstance->setAttributes($actionCache['action_attributes']);
			} catch(AgaviException) {
				$this->startedCacheCreationCallback(self::CACHE_CALLBACK_ACTION_CACHE_GONE, $actionGroups, $config, $container);
				$isActionCached = false;
			}
		}
		
		$isViewCached = false;
		$rememberTheView = null;
		
		while(true) {
			if(!$isActionCached) {
				$actionCache = [];
			
				[$actionCache['view_module'], $actionCache['view_name']] = $container->runAction();
				
				if(isset($rememberTheView) && $actionCache != $rememberTheView) {
					$ourClass = static::class;
					$ourClass::clearCache($groups);
				}
				
				if($isCacheable && is_array($config['views']) && !in_array(['module' => $actionCache['view_module'], 'name' => $actionCache['view_name']], $config['views'], true)) {
					$isCacheable = false;
					$this->abortedCacheCreationCallback(self::CACHE_CALLBACK_VIEW_NOT_CACHEABLE, $actionGroups, $config, $container);

					if(isset($rememberTheView)) {
						$ourClass = static::class;
						$ourClass::clearCache($groups);
					}
				}

				$actionAttributes = $actionInstance->getAttributes();
			}

			$response = $container->getResponse();
			$response->clear();

			$container->clearNext();

			if($actionCache['view_name'] !== AgaviView::NONE) {

				$container->setViewModuleName($actionCache['view_module']);
				$container->setViewName($actionCache['view_name']);

				$key = $request->toggleLock();
				try {
					$viewInstance = $container->getViewInstance();
				} catch(\Exception $e) {
					$request->toggleLock($key);
					throw $e;
				}
				$request->toggleLock($key);

				$outputType = $container->getOutputType()->getName();

				if($isCacheable) {
					if(isset($config['output_types'][$otConfig = $outputType]) || isset($config['output_types'][$otConfig = '*'])) {
						$otConfig = $config['output_types'][$otConfig];
						
						$viewGroups = array_merge($groups, [$outputType]);

						if($isActionCached) {
							$isViewCached = $this->checkCache($viewGroups, $config['lifetime']);
							if(!$isViewCached) {
								$this->startedCacheCreationCallback(self::CACHE_CALLBACK_VIEW_NOT_CACHED, $viewGroups, $config, $container);
							}
						}
					} else {
						$this->abortedCacheCreationCallback(self::CACHE_CALLBACK_OUTPUT_TYPE_NOT_CACHEABLE, $actionGroups, $config, $container);
						$isCacheable = false;
					}
				}

				if($isViewCached) {
					try {
						$viewCache = $this->readCache($viewGroups);
					} catch(AgaviException) {
						$this->startedCacheCreationCallback(self::CACHE_CALLBACK_VIEW_CACHE_GONE, $viewGroups, $config, $container);
						$isViewCached = false;
					}
				}
				if(!$isViewCached) {
					if($isActionCached && !$config['action_attributes']) {
						$isActionCached = false;
						
						if($isCacheable) {
							$this->abortedCacheCreationCallback(self::CACHE_CALLBACK_ACTION_CACHE_USELESS, $viewGroups, $config, $container);
						}
						$this->startedCacheCreationCallback(self::CACHE_CALLBACK_ACTION_CACHE_USELESS, $actionGroups, $config, $container);
						
						$rememberTheView = [
							'view_module' => $actionCache['view_module'],
							'view_name' => $actionCache['view_name'],
						];
						continue;
					}
				
					$viewCache = [];
					$viewCache['next'] = $this->executeView($container);
				}

				if($viewCache['next'] instanceof AgaviExecutionContainer) {
					$container->setNext($viewCache['next']);
				} else {
					$output = [];
					$nextOutput = null;
				
					if($isViewCached) {
						$layers = $viewCache['layers'];
						$response = $viewCache['response'];
						$container->setResponse($response);

						foreach($viewCache['template_variables'] as $name => $value) {
							$viewInstance->setAttribute($name, $value);
						}

						foreach($viewCache['request_attributes'] as $requestAttribute) {
							$request->setAttribute($requestAttribute['name'], $requestAttribute['value'], $requestAttribute['namespace']);
						}
					
						foreach($viewCache['request_attribute_namespaces'] as $ranName => $ranValues) {
							$request->setAttributes($ranValues, $ranName);
						}

						$nextOutput = $response->getContent();
					} else {
						if($viewCache['next'] !== null) {
							$response->setContent($nextOutput = $viewCache['next']);
							$viewCache['next'] = null;
						}

						$layers = $viewInstance->getLayers();

						if($isCacheable) {
							$viewCache['template_variables'] = [];
							foreach($otConfig['template_variables'] as $varName) {
								$viewCache['template_variables'][$varName] = $viewInstance->getAttribute($varName);
							}

							$viewCache['response'] = clone $response;

							$viewCache['layers'] = [];

							$viewCache['slots'] = [];

							$lastCacheableLayer = -1;
							if(is_array($otConfig['layers'])) {
								if(count($otConfig['layers'])) {
									for($i = count($layers)-1; $i >= 0; $i--) {
										$layer = $layers[$i];
										$layerName = $layer->getName();
										if(isset($otConfig['layers'][$layerName])) {
											if(is_array($otConfig['layers'][$layerName])) {
												$lastCacheableLayer = $i - 1;
											} else {
												$lastCacheableLayer = $i;
											}
										}
									}
								}
							} else {
								$lastCacheableLayer = count($layers) - 1;
							}

							for($i = $lastCacheableLayer + 1; $i < count($layers); $i++) {
								$viewCache['layers'][] = clone $layers[$i];
							}
						}
					}

					$attributes =& $viewInstance->getAttributes();

					$assignInnerToSlots = $this->getParameter('assign_inner_to_slots', false);
					
					for($i = 0; $i < count($layers); $i++) {
						$layer = $layers[$i];
						$layerName = $layer->getName();
						foreach($layer->getSlots() as $slotName => $slotContainer) {
							if($isViewCached && isset($viewCache['slots'][$layerName][$slotName])) {
								$slotResponse = $viewCache['slots'][$layerName][$slotName];
							} else {
								$slotResponse = $slotContainer->execute();
								if($isCacheable && !$isViewCached && isset($otConfig['layers'][$layerName]) && is_array($otConfig['layers'][$layerName]) && in_array($slotName, $otConfig['layers'][$layerName])) {
									$viewCache['slots'][$layerName][$slotName] = $slotResponse;
								}
							}
							AgaviArrayPathDefinition::setValue($slotName, $output, $slotResponse->getContent());
							$response->merge($slotResponse);
						}
						$moreAssigns = [
							'container' => $container,
							'inner' => $nextOutput,
							'request_data' => $container->getRequestData(),
							'response' => $response,
							'validation_manager' => $container->getValidationManager(),
							'view' => $viewInstance,
						];
						$key = $request->toggleLock();
						try {
							$nextOutput = $layer->getRenderer()->render($layer, $attributes, $output, $moreAssigns);
						} catch(\Exception $e) {
							$request->toggleLock($key);
							throw $e;
						}
						$request->toggleLock($key);

						$response->setContent($nextOutput);

						if($isCacheable && !$isViewCached && $i === $lastCacheableLayer) {
							$viewCache['response'] = clone $response;
						}

						$output = [];
						if($assignInnerToSlots) {
							$output[$layer->getName()] = $nextOutput;
						}
					}
				}

				if($isCacheable && !$isViewCached) {
					$viewCache['request_attributes'] = [];
					foreach($otConfig['request_attributes'] as $requestAttribute) {
						$viewCache['request_attributes'][] = $requestAttribute + ['value' => $request->getAttribute($requestAttribute['name'], $requestAttribute['namespace'])];
					}
					$viewCache['request_attribute_namespaces'] = [];
					foreach($otConfig['request_attribute_namespaces'] as $requestAttributeNamespace) {
						$viewCache['request_attribute_namespaces'][$requestAttributeNamespace] = $request->getAttributes($requestAttributeNamespace);
					}

					$this->writeCache($viewGroups, $viewCache, $config['lifetime']);

					$this->finishedCacheCreationCallback(self::CACHE_CALLBACK_VIEW_CACHE_WRITTEN, $viewGroups, $config, $container);
				}
			}
		
			if($isCacheable && !$isActionCached) {
				$actionCache['action_attributes'] = [];
				foreach($config['action_attributes'] as $attributeName) {
					$actionCache['action_attributes'][$attributeName] = $actionAttributes[$attributeName];
				}

				$this->writeCache($actionGroups, $actionCache, $config['lifetime']);
			
				$this->finishedCacheCreationCallback(self::CACHE_CALLBACK_ACTION_CACHE_WRITTEN, $actionGroups, $config, $container);
			}
			
			break;
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