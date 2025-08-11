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
namespace Agavi\Controller;
/**
 * AgaviController directs application flow.
 *
 * @package    agavi
 * @subpackage controller
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
use Agavi\Util\AgaviParameterHolder;
use Agavi\Exception\AgaviControllerException;
use Agavi\Config\AgaviConfig;
use Agavi\Config\AgaviConfigCache;
use Agavi\Config\AgaviAPCuConfigCache;
use Agavi\Request\AgaviRequestDataHolder;
use Agavi\Exception\AgaviDisabledModuleException;
use Agavi\Response\AgaviResponse;
use Agavi\Exception\AgaviException;
use Agavi\Util\AgaviToolkit;
use Agavi\Exception\AgaviClassNotFoundException;
use Agavi\Exception\AgaviFileNotFoundException;
use Agavi\AgaviContext;
use Agavi\Filter\AgaviFilterChain;
use Agavi\Request\AgaviIHeadersRequestDataHolder;
use Symfony\Contracts\Service\ResetInterface;

use \Exception;
class AgaviController extends AgaviParameterHolder implements ResetInterface
{
	/** Enable verbose controller lifecycle logging (worker diagnostics). */
	public const DEBUG = false; // set true for deep debugging
	/**
	 * @var        int The number of execution containers run so far.
	 */
	protected $numExecutions = 0;
	
	/**
	 * @var        AgaviContext An AgaviContext instance.
	 */
	protected $context = null;
	
	/**
	 * @var        AgaviResponse The global response.
	 */
	protected $response = null;
	
	/**
	 * @var        AgaviFilterChain The global filter chain.
	 */
	protected $filterChain = null;
	
	/**
	 * @var        array An array of filter instances for reuse.
	 */
	protected $filters = [
		'global' => [],
		'action' => [
			'*' => null
		],
		'dispatch' => null,
		'execution' => null,
		'security' => null
	];
	
	/**
	 * @var        string The default Output Type.
	 */
	protected $defaultOutputType = null;
	/**
	 * Stores the originally configured default output type name so worker-mode
	 * resets can restore the intended framework configuration instead of
	 * falling back to the first registered output type (which caused request 1
	 * to influence subsequent requests when $defaultOutputType was nulled).
	 */
	protected $configuredDefaultOutputType = null;
	
	/**
	 * @var        array An array of registered Output Types.
	 */
	protected $outputTypes = [];
	
	/**
	 * @var        array Ref to the request data object from the request.
	 */
	private $requestData = null;
	
	/**
	 * Increment the execution counter.
	 * Will throw an exception if the maximum amount of runs is exceeded.
	 *
	 * @throws     AgaviControllerException If too many execution runs were made.
	 *
	 * @author     David Zülke <dz@bitxtender.com>
	 * @since      0.11.0
	 */
	public function countExecution()
	{
		$maxExecutions = $this->getParameter('max_executions');
		
		if(++$this->numExecutions > $maxExecutions && $maxExecutions > 0) {
			throw new AgaviControllerException('Too many execution runs have been detected for this Context.');
		}
	}
	
	/**
	 * Create and initialize new execution container instance.
	 *
	 * @param      string                 The name of the module.
	 * @param      string                 The name of the action.
	 * @param      AgaviRequestDataHolder A RequestDataHolder with additional
	 *                                    request arguments.
	 * @param      string                 Optional name of an initial output type
	 *                                    to set.
	 * @param      string                 Optional name of the request method to
	 *                                    be used in this container.
	 *
	 * @return     AgaviExecutionContainer A new execution container instance,
	 *                                     fully initialized.
	 *
	 * @author     David Zülke <dz@bitxtender.com>
	 * @since      0.11.0
	 */
	public function createExecutionContainer($moduleName = null, $actionName = null, ?AgaviRequestDataHolder $arguments = null, $outputType = null, $requestMethod = null)
	{
		$logger = $this->context?->getLoggerManager()?->getLogger();
		if(self::DEBUG && $logger) { $logger->debug("controller.create_container start module=$moduleName action=$actionName incomingOT=".var_export($outputType, true)); }
		
		// create a new execution container
		$container = $this->context->createInstanceFor('execution_container');
		$container->setModuleName($moduleName);
		$container->setActionName($actionName);
		// IMPORTANT:
		// We MUST NOT call $this->context->getRequest()->getRequestData() here unconditionally.
		// During action or view execution the global request is deliberately locked to enforce
		// usage of the local (cloned) AgaviRequestDataHolder. Nested execution containers (forwards,
		// slots, layers, fragments) may be created while the request is locked. The previous
		// FrankenPHP changes introduced an unconditional call which now triggers the
		// "Access to request data is locked" exception the user reports.
		//
		// Original Agavi relied on a cached pointer ($this->requestData) grabbed during controller
		// startup BEFORE any locking occurs. We restore that behaviour with a lazy fallback:
		//  - If we already have a cached pointer, reuse it (safe inside locked section).
		//  - If we don't (first container after a reset), we capture it now while the request should
		//    still be unlocked.
		if($this->requestData === null) {
			$rq = $this->context->getRequest();
			if($rq->isLocked()) {
				// This should normally not happen for the very first container of a request lifecycle.
				// Log and fall back to creating a safe clone via the execution container logic later.
				if($logger) { $logger->warning('controller.create_container request locked while priming requestData cache'); }
				// We intentionally DO NOT call getRequestData() to avoid exception. We'll let
				// the container clone the global data later via initRequestData().
			} else {
				// Safe to capture pointer now.
				$this->requestData = $rq->getRequestData();
			}
		}
		$requestData = $this->requestData;
		
		$container->setRequestData($requestData);
		if($arguments !== null) {
			$container->setArguments($arguments);
		}
		
		if(self::DEBUG && $logger) { $logger->debug("controller.create_container resolveOT param=".var_export($outputType, true)." default=".var_export($this->defaultOutputType, true)); }
		
		$resolvedOutputType = $this->context->getController()->getOutputType($outputType);
		if(self::DEBUG && $logger) { $logger->debug("controller.create_container resolvedOT=".$resolvedOutputType->getName()); }
		
		$container->setOutputType($resolvedOutputType);
		
		if($requestMethod === null) {
			$requestMethod = $this->context->getRequest()->getMethod();
		}
		$container->setRequestMethod($requestMethod);
		
		if(self::DEBUG && $logger) { $logger->debug("controller.create_container finalOT=".$container->getOutputType()->getName()); }
		
		return $container;
	}

	/**
	 * Phase 1 PSR pipeline helper: create an execution container from the current
	 * request (module/action already resolved OR will fall back to defaults).
	 * Intentionally minimal – routing integration will replace this later.
	 */
	public function createExecutionContainerFromRequest($legacyRequest): AgaviExecutionContainer
	{
		// Determine module/action parameters if present
		$module = null; $action = null; $outputType = null;
		if($legacyRequest) {
			$rd = $legacyRequest->getRequestData();
			$ma = $legacyRequest->getParameter('module_accessor');
			$aa = $legacyRequest->getParameter('action_accessor');
			if($ma && $aa && $rd->hasParameter($ma) && $rd->hasParameter($aa)) {
				$module = $rd->getParameter($ma);
				$action = $rd->getParameter($aa);
			}
		}
		if(!$module) { $module = \Agavi\Config\AgaviConfig::get('actions.default_module'); }
		if(!$action) { $action = \Agavi\Config\AgaviConfig::get('actions.default_action'); }
		return $this->createExecutionContainer($module, $action, null, $outputType, null);
	}
	
	/**
	 * Initialize a module and load its autoload, module config etc.
	 *
	 * @param      string The name of the module to initialize.
	 *
	 * @author     Felix Gilcher <felix.gilcher@bitextender.com>
	 * @since      1.0.0
	 */
	public function initializeModule($moduleName)
	{
		$lowerModuleName = strtolower((string) $moduleName);
		
		if(null === AgaviConfig::get('modules.' . $lowerModuleName . '.enabled')) {
			// set some defaults first
			AgaviConfig::fromArray([
				'modules.' . $lowerModuleName . '.agavi.action.path' => '%core.module_dir%/${moduleName}/Actions/${actionName}Action.php',
				'modules.' . $lowerModuleName . '.agavi.cache.path' => '%core.module_dir%/${moduleName}/cache/${actionName}.xml',
				'modules.' . $lowerModuleName . '.agavi.template.directory' => '%core.module_dir%/${module}/Templates',
				'modules.' . $lowerModuleName . '.agavi.validate.path' => '%core.module_dir%/${moduleName}/Validate/${actionName}.xml',
				'modules.' . $lowerModuleName . '.agavi.view.path' => '%core.module_dir%/${moduleName}/Views/${viewName}View.php',
				'modules.' . $lowerModuleName . '.agavi.view.name' => '${actionName}${viewName}',
			]);		// include the module configuration
		// loaded only once due to the way load() (former import()) works
		if(is_readable(AgaviConfig::get('core.module_dir') . '/' . $moduleName . '/Config/module.xml')) {
			if(defined('\AGAVI_USE_APCU_CONFIG_CACHE') && \AGAVI_USE_APCU_CONFIG_CACHE) {
				include_once(AgaviAPCuConfigCache::checkConfig(AgaviConfig::get('core.module_dir') . '/' . $moduleName . '/Config/module.xml'));
			} else {
				include_once(AgaviConfigCache::checkConfig(AgaviConfig::get('core.module_dir') . '/' . $moduleName . '/Config/module.xml'));
			}
		} else {
			AgaviConfig::set('modules.' . $lowerModuleName . '.enabled', true);
		}
		
		if(AgaviConfig::get('modules.' . $lowerModuleName . '.enabled')) {
				$moduleConfigHandlers = AgaviConfig::get('core.module_dir') . '/' . $moduleName . '/Config/config_handlers.xml';
				if(is_readable($moduleConfigHandlers)) {
					AgaviConfigCache::addConfigHandlersFile($moduleConfigHandlers);
				}
			}
		}
		
		if(!AgaviConfig::get('modules.' . $lowerModuleName . '.enabled')) {
			throw new AgaviDisabledModuleException(sprintf('The module "%1$s" is disabled.', $moduleName));
		}
		
		// check for a module config.php
		$moduleConfig = AgaviConfig::get('core.module_dir') . '/' . $moduleName . '/Config.php';
		if(is_readable($moduleConfig)) {
			require_once($moduleConfig);
		}
	}
	
	/**
	 * Dispatch a request
	 *
	 * @param      AgaviRequestDataHolder  An optional request data holder object
	 *                                     with additional request data.
	 * @param      AgaviExecutionContainer An optional execution container that,
	 *                                     if given, will be executed right away,
	 *                                     skipping routing execution.
	 *
	 * @return     AgaviResponse The response produced during this dispatch call.
	 *
	 * @author     David Zülke <dz@bitxtender.com>
	 * @since      0.9.0
	 */
	public function dispatch(?AgaviRequestDataHolder $arguments = null, ?AgaviExecutionContainer $container = null)
	{
		$logger = $this->context?->getLoggerManager()?->getLogger();
		if(self::DEBUG && $logger) { $logger->debug("controller.dispatch start defaultOT=".var_export($this->defaultOutputType, true)); }
		
		try {
			
			$rq = $this->context->getRequest();
			$rd = $rq->getRequestData();
			
			if($container === null) {
				if(self::DEBUG && $logger) { $logger->debug("controller.dispatch running routing"); }
				
				// DEBUG: Check routing object state before calling execute
				$routing = $this->context->getRouting();
				if(self::DEBUG && $logger) { $logger->debug("controller.dispatch routing class=" . get_class($routing) . " id=" . spl_object_id($routing)); }
				
				// Use reflection to check the context property
				$reflection = new \ReflectionClass($routing);
				if ($reflection->hasProperty('context')) {
					$contextProperty = $reflection->getProperty('context');
					$contextProperty->setAccessible(true);
					$routingContext = $contextProperty->getValue($routing);
						if(self::DEBUG) {
							if($logger) { $logger->debug("controller.dispatch routing context=" . ($routingContext === null ? 'NULL' : 'OK(' . get_class($routingContext) . ')')); }
						}
				}
				
				$container = $routing->execute();
				if(self::DEBUG && $logger) { $logger->debug("controller.dispatch routedOT=".$container->getOutputType()->getName()); }
			} else {
				if(self::DEBUG && $logger) { $logger->debug("controller.dispatch providedOT=".$container->getOutputType()->getName()); }
			}
			
			if($container instanceof AgaviExecutionContainer) {
				if($arguments !== null) {
					$rd->merge($arguments);
				}
				
				$moduleName = $container->getModuleName();
				$actionName = $container->getActionName();
				if(!$moduleName) {
					$ma = $rq->getParameter('module_accessor');
					$aa = $rq->getParameter('action_accessor');
					if($rd->hasParameter($ma) && $rd->hasParameter($aa)) {
						$moduleName = $rd->getParameter($ma);
						$actionName = $rd->getParameter($aa);
					} else {
						$moduleName = AgaviConfig::get('actions.default_module');
						$actionName = AgaviConfig::get('actions.default_action');
					}
					$container->setModuleName($moduleName);
					$container->setActionName($actionName);
				}
				
				if(!AgaviConfig::get('core.available', false)) {
					$container = $container->createSystemActionForwardContainer('unavailable');
				}
				
				// create a new filter chain
				$filterChain = $this->getFilterChain();
				
				$this->loadFilters($filterChain, 'global');
				
				// register the dispatch filter as a pre-filter
				$filterChain->registerPre($this->filters['dispatch'], 'agavi_dispatch_filter');
				
				// execute pre-filters, action, post-filters
				$filterChain->execute($container, function($container): void {
						// No-op: action execution is handled by the execution filter in the action filter chain.
				});
				
				$response = $container->getResponse();
			} elseif($container instanceof AgaviResponse) {
				$response = $container;
				$container = null;
			} else {
				throw new AgaviException('AgaviRouting::execute() returned neither AgaviExecutionContainer nor AgaviResponse object.');
			}
			$response->merge($this->response);
			
			if($this->getParameter('send_response')) {
				$response->send();
			}
			
			return $response;
			
		} catch(Exception $e) {
			AgaviException::render($e, $this->context, $container);
		}
	}
	
	/**
	 * Get the global response instance.
	 *
	 * @return     AgaviResponse The global response.
	 *
	 * @author     David Zülke <dz@bitxtender.com>
	 * @since      0.11.0
	 */
	public function getGlobalResponse()
	{
		return $this->response;
	}
	
	
	/**
	 * Indicates whether or not a module has a specific action file.
	 * 
	 * Please note that this is only a cursory check and does not 
	 * check whether the file actually contains the proper class
	 *
	 * @param      string A module name.
	 * @param      string An action name.
	 *
	 * @return     mixed  the path to the action file if the action file 
	 *                    exists and is readable, false in any other case
	 *
	 * @author     Felix Gilcher <felix.gilcher@bitextender.com>
	 * @since      1.0.0
	 */
	public function checkActionFile($moduleName, $actionName)
	{
		$this->initializeModule($moduleName);
		
		$actionName = AgaviToolkit::canonicalName($actionName);
		$file = AgaviToolkit::evaluateModuleDirective(
			$moduleName,
			'agavi.action.path',
			[
				'moduleName' => $moduleName,
				'actionName' => $actionName,
			]
		);
		
		if(is_readable($file) && !str_starts_with($actionName, '/')) {
			return $file;
		}
		
		return false;
	}
	
	/**
	 * Retrieve an Action implementation instance.
	 *
	 * @param      string A module name.
	 * @param      string An action name.
	 *
	 * @return     AgaviAction An Action implementation instance
	 *
	 * @throws     AgaviException if the action could not be found.
	 *
	 * @author     Sean Kerr <skerr@mojavi.org>
	 * @author     Mike Vincent <mike@agavi.org>
	 * @author     David Zülke <dz@bitxtender.com>
	 * @since      0.9.0
	 */
	public function createActionInstance($moduleName, $actionName)
	{
		$this->initializeModule($moduleName);
		
		$actionName = AgaviToolkit::canonicalName($actionName);
		$longActionName = str_replace('/', '_', $actionName);
		
		// Build class names with configurable namespace pattern
		$baseNamespace = AgaviConfig::get('core.namespace_prefix', 'App');
		
		// For namespaced classes, preserve directory structure as namespaces
		$namespacedActionName = str_replace('/', '\\', $actionName);
		$namespacedClass = $baseNamespace . '\\Modules\\' . $moduleName . '\\Actions\\' . $namespacedActionName . 'Action';
		$oldClass = $moduleName . '_' . $longActionName . 'Action';
		
		// Try namespaced class first (autoloader will handle it)
		if(class_exists($namespacedClass)) {
			return new $namespacedClass();
		}
		
		// Fall back to old naming convention
		if(class_exists($oldClass)) {
			return new $oldClass();
		}
		
		// Neither class found
		throw new AgaviClassNotFoundException(sprintf('Unable to find Action class "%s" or "%s" for Action "%s" in Module "%s".', $namespacedClass, $oldClass, $actionName, $moduleName));
	}

	/**
	 * Retrieve the current application context.
	 *
	 * @return     AgaviContext An AgaviContext instance.
	 *
	 * @author     Sean Kerr <skerr@mojavi.org>
	 * @since      0.9.0
	 */
	public final function getContext()
	{
		return $this->context;
	}


	
	/**
	 * Indicates whether or not a module has a specific view file.
	 * 
	 * Please note that this is only a cursory check and does not 
	 * check whether the file actually contains the proper class
	 *
	 * @param      string A module name.
	 * @param      string A view name.
	 *
	 * @return     mixed  the path to the view file if the view file 
	 *                    exists and is readable, false in any other case
	 * 
	 * @author     Felix Gilcher <felix.gilcher@bitextender.com>
	 * @since      1.0.0
	 */
	public function checkViewFile($moduleName, $viewName)
	{
		$this->initializeModule($moduleName);
		
		$viewName = AgaviToolkit::canonicalName($viewName);
		$file = AgaviToolkit::evaluateModuleDirective(
			$moduleName,
			'agavi.view.path',
			[
				'moduleName' => $moduleName,
				'viewName' => $viewName,
			]
		);
		
		if(is_readable($file) && !str_starts_with($viewName, '/')) {
			return $file;
		}
		
		return false;
	}
	
	/**
	 * Retrieve a View implementation instance.
	 *
	 * @param      string A module name.
	 * @param      string A view name.
	 *
	 * @return     AgaviView A View implementation instance,
	 *
	 * @throws     AgaviException if the view could not be found.
	 *
	 * @author     Sean Kerr <skerr@mojavi.org>
	 * @author     Mike Vincent <mike@agavi.org>
	 * @author     David Zülke <dz@bitxtender.com>
	 * @since      0.9.0
	 */
	public function createViewInstance($moduleName, $viewName)
	{
		try {
			$this->initializeModule($moduleName);
		} catch(AgaviDisabledModuleException $e) {
			// views from disabled modules should be usable by definition
			// swallow
		}
		
		$viewName = AgaviToolkit::canonicalName($viewName);
		$longViewName = str_replace('/', '_', $viewName);
		
		// Build class names with configurable namespace pattern
		$baseNamespace = AgaviConfig::get('core.namespace_prefix', 'App');
		
		// For namespaced classes, preserve directory structure as namespaces
		$namespacedViewName = str_replace('/', '\\', $viewName);
		$namespacedClass = $baseNamespace . '\\Modules\\' . $moduleName . '\\Views\\' . $namespacedViewName . 'View';
		$oldClass = $moduleName . '_' . $longViewName . 'View';
		
		// Try namespaced class first (autoloader will handle it)
		if(class_exists($namespacedClass)) {
			return new $namespacedClass();
		}
		
		// Fall back to old naming convention
		if(class_exists($oldClass)) {
			return new $oldClass();
		}
		
		// Neither class found
		throw new AgaviClassNotFoundException(sprintf('Unable to find View class "%s" or "%s" for View "%s" in Module "%s".', $namespacedClass, $oldClass, $viewName, $moduleName));
	}

	/**
	 * Constructor.
	 *
	 * @author     David Zülke <dz@bitxtender.com>
	 * @since      0.11.0
	 */
	public function __construct()
	{
		parent::__construct();
		$this->setParameters([
			'max_executions' => 20,
			'send_response' => true,
		]);
	}
	
	/**
	 * Initialize this controller.
	 *
	 * @param      AgaviContext An AgaviContext instance.
	 * @param      array        An array of initialization parameters.
	 *
	 * @author     David Zülke <dz@bitxtender.com>
	 * @since      0.9.0
	 */
	public function initialize(AgaviContext $context, array $parameters = [])
	{
		$this->context = $context;
		
		$this->setParameters($parameters);
		
		$this->response = $this->context->createInstanceFor('response');
		
		$cfg = AgaviConfig::get('core.config_dir') . '/output_types.xml';
		if(defined('\AGAVI_USE_APCU_CONFIG_CACHE') && \AGAVI_USE_APCU_CONFIG_CACHE) {
			require(AgaviAPCuConfigCache::checkConfig($cfg, $this->context->getName()));
		} else {
			require(AgaviConfigCache::checkConfig($cfg, $this->context->getName()));
		}
		
		if(AgaviConfig::get('core.use_security', false)) {
			$this->filters['security'] = $this->context->createInstanceFor('security_filter');
		}
		
		$this->filters['dispatch'] = $this->context->createInstanceFor('dispatch_filter');
		
		$this->filters['execution'] = $this->context->createInstanceFor('execution_filter');
	}
	
	/**
	 * Get a filter.
	 *
	 * @param      string The name of the filter list section.
	 *
	 * @return     AgaviFilter A filter instance, or null.
	 *
	 * @author     David Zülke <dz@bitxtender.com>
	 * @since      0.11.0
	 */
	public function getFilter($which)
	{
		return ($this->filters[$which] ?? null);
	}
	
	/**
	 * Get the global filter chain.
	 *
	 * @return     AgaviFilterChain The global filter chain.
	 *
	 * @author     David Zülke <david.zuelke@bitextender.com>
	 * @since      1.1.0
	 */
	public function getFilterChain()
	{
		if($this->filterChain === null) {
			$this->filterChain = $this->context->createInstanceFor('filter_chain');
			$this->filterChain->setType(AgaviFilterChain::TYPE_GLOBAL);
		}
		
		return $this->filterChain;
	}
	
	/**
	 * Load filters.
	 *
	 * @param      AgaviFilterChain A FilterChain instance.
	 * @param      string           "global" or "action".
	 * @param      string           A module name, or "*" for the generic config.
	 *
	 * @author     David Zülke <dz@bitxtender.com>
	 * @since      0.11.0
	 */
	public function loadFilters(AgaviFilterChain $filterChain, $which = 'global', $module = null)
	{
		if($module === null) {
			$module = '*';
		}
		
		if(($which != 'global' && !isset($this->filters[$which][$module])) || $which == 'global' && $this->filters[$which] == null) {
			if($which == 'global') {
				$this->filters[$which] = [];
				$filters =& $this->filters[$which];
			} else {
				$this->filters[$which][$module] = [];
				$filters =& $this->filters[$which][$module];
			}
			$config = ($module == '*' ? AgaviConfig::get('core.config_dir') : AgaviConfig::get('core.module_dir') . '/' . $module . '/Config') . '/' . $which . '_filters.xml';
			if(is_readable($config)) {
				if(defined('\AGAVI_USE_APCU_CONFIG_CACHE') && \AGAVI_USE_APCU_CONFIG_CACHE) {
					require(AgaviAPCuConfigCache::checkConfig($config, $this->context->getName()));
				} else {
					require(AgaviConfigCache::checkConfig($config, $this->context->getName()));
				}
			}
		} else {
			if($which == 'global') {
				$filters =& $this->filters[$which];
			} else {
				$filters =& $this->filters[$which][$module];
			}
		}
		
		foreach($filters as $name => $filter) {
			if(method_exists($filter, 'isPostFilter') && $filter->isPostFilter()) {
				$filterChain->registerPost($filter, $name);
			} else {
				$filterChain->registerPre($filter, $name);
			}
		}
	}

	/**
	 * Indicates whether or not a module has a specific model.
	 *
	 * @param      string A module name.
	 * @param      string A model name.
	 *
	 * @return     bool true, if the model exists, otherwise false.
	 *
	 * @author     Sean Kerr <skerr@mojavi.org>
	 * @since      0.9.0
	 */
	public function modelExists($moduleName, $modelName)
	{
		$baseNamespace = AgaviConfig::get('core.namespace_prefix', 'App');
		$modelName = AgaviToolkit::canonicalName($modelName);
		$namespacedModelName = str_replace('/', '\\', $modelName);

		// APCu key (only if enabled) – immutable deployments benefit most
		$apcuKey = null;
		if(defined('\AGAVI_USE_APCU_CONFIG_CACHE') && \AGAVI_USE_APCU_CONFIG_CACHE && function_exists('apcu_fetch')) {
			$apcuKey = 'agavi_exists_model_' . md5($baseNamespace.'|'.$moduleName.'|'.$namespacedModelName);
			$cached = apcu_fetch($apcuKey, $hit);
			if($hit) {
				return (bool)$cached;
			}
		}

		$namespacedClass = $baseNamespace . '\\Modules\\' . $moduleName . '\\Models\\' . $namespacedModelName . 'Model';
		$exists = class_exists($namespacedClass);
		if(!$exists) {
			$file = AgaviConfig::get('core.module_dir') . '/' . $moduleName . '/Models/' . $modelName . 'Model.php';
			$exists = is_readable($file);
		}

		if($apcuKey) {
			apcu_store($apcuKey, $exists, 0);
		}
		return $exists;
	}

	/**
	 * Indicates whether or not a module exists.
	 *
	 * @param      string A module name.
	 *
	 * @return     bool true, if the module exists, otherwise false.
	 *
	 * @author     Sean Kerr <skerr@mojavi.org>
	 * @since      0.9.0
	 */
	public function moduleExists($moduleName)
	{
		$apcuKey = null;
		if(defined('\AGAVI_USE_APCU_CONFIG_CACHE') && \AGAVI_USE_APCU_CONFIG_CACHE && function_exists('apcu_fetch')) {
			$apcuKey = 'agavi_exists_module_' . md5($moduleName);
			$cached = apcu_fetch($apcuKey, $hit);
			if($hit) {
				return (bool)$cached;
			}
		}
		$file = AgaviConfig::get('core.module_dir') . '/' . $moduleName . '/Config/module.xml';
		$exists = is_readable($file);
		if($apcuKey) {
			apcu_store($apcuKey, $exists, 0);
		}
		return $exists;
	}

	/**
	 * Do any necessary startup work after initialization.
	 *
	 * This method is not called directly after initialize().
	 *
	 * @author     David Zülke <dz@bitxtender.com>
	 * @since      0.11.0
	 */
	public function startup()
	{
		$logger = $this->context?->getLoggerManager()?->getLogger();
		// grab a pointer to the request data
		$this->requestData = $this->context->getRequest()->getRequestData();

		// Capture the configured default output type exactly once so we can
		// restore it after each worker reset. We must do this here (after all
		// config handlers have run) but before any request-specific logic might
		// attempt to mutate $defaultOutputType (it normally should not mutate).
		if($this->configuredDefaultOutputType === null && $this->defaultOutputType !== null) {
			$this->configuredDefaultOutputType = $this->defaultOutputType;
			if(self::DEBUG && $logger) { $logger->debug("controller.startup capture configuredDefaultOT=".var_export($this->configuredDefaultOutputType, true)); }
		}
	}

	/**
	 * Execute the shutdown procedure for this controller.
	 *
	 * @author     Sean Kerr <skerr@mojavi.org>
	 * @since      0.9.0
	 */
	public function shutdown()
	{
	}

	/**
	 * Reset the controller state for FrankenPHP worker mode.
	 * This clears request-specific state that could leak between requests.
	 * 
	 * Called automatically by FrankenPHP between requests when using worker mode.
	 *
	 * @author     Auto-generated for FrankenPHP compatibility
	 * @since      2.0.0
	 */
	public function reset(): void
	{
		$logger = $this->context?->getLoggerManager()?->getLogger();
		if(self::DEBUG && $logger) { $logger->debug("controller.reset begin defaultOT=".var_export($this->defaultOutputType, true)); }
		
		// Reset execution counter
		$this->numExecutions = 0;
		
		// Clear filter chain (will be recreated on next request)
		$this->filterChain = null;
		
		// Reset action-specific filters (keep global ones as they're reusable)
		$this->filters['action'] = ['*' => null];
		
		// Clear request data reference
		$this->requestData = null;
		
		// Reset the global response to a fresh instance
		if ($this->context) {
			$this->response = $this->context->createInstanceFor('response');
		}
		
		// IMPORTANT: Do NOT null $defaultOutputType here. Doing so made the next
		// request fall back to the *first* registered output type (often 'json'),
		// which then caused containers without an explicit route-level
		// output_type to assume the wrong type (e.g. executeJson() instead of
		// executeHtml()). Instead, restore the originally configured default so
		// each request starts from a consistent framework baseline.
		if($this->configuredDefaultOutputType !== null) {
			$this->defaultOutputType = $this->configuredDefaultOutputType;
		} else {
			// Fallback safety: leave as-is (likely null only during very early init)
		}
		
		if(self::DEBUG && $logger) { $logger->debug("controller.reset end defaultOT=".var_export($this->defaultOutputType, true)); }
	}

	/**
	 * Indicates whether or not a module has a specific action.
	 *
	 * @param      string A module name.
	 * @param      string A view name.
	 *
	 * @return     bool true, if the action exists, otherwise false.
	 *
	 * @author     David Zülke <david.zuelke@bitextender.com>
	 * @since      1.0.1
	 */
	public function actionExists($moduleName, $actionName)
	{
		return $this->checkActionFile($moduleName, $actionName) !== false;
	}

	/**
	 * Indicates whether or not a module has a specific view.
	 *
	 * @param      string A module name.
	 * @param      string A view name.
	 *
	 * @return     bool true, if the view exists, otherwise false.
	 *
	 * @author     Sean Kerr <skerr@mojavi.org>
	 * @since      0.9.0
	 */
	public function viewExists($moduleName, $viewName)
	{
		$baseNamespace = AgaviConfig::get('core.namespace_prefix', 'App');
		$viewName = AgaviToolkit::canonicalName($viewName);
		$namespacedViewName = str_replace('/', '\\', $viewName);

		$apcuKey = null;
		if(defined('\AGAVI_USE_APCU_CONFIG_CACHE') && \AGAVI_USE_APCU_CONFIG_CACHE && function_exists('apcu_fetch')) {
			$apcuKey = 'agavi_exists_view_' . md5($moduleName.'|'.$namespacedViewName);
			$cached = apcu_fetch($apcuKey, $hit);
			if($hit) {
				return (bool)$cached;
			}
		}

		$namespacedClass = $baseNamespace . '\\Modules\\' . $moduleName . '\\Views\\' . $namespacedViewName . 'View';
		$exists = class_exists($namespacedClass);
		if(!$exists) {
			$exists = ($this->checkViewFile($moduleName, $viewName) !== false);
		}
		if($apcuKey) {
			apcu_store($apcuKey, $exists, 0);
		}
		return $exists;
	}
	
	/**
	 * Retrieve an Output Type object
	 *
	 * @param      string The optional output type name.
	 *
	 * @return     AgaviOutputType An Output Type object.
	 *
	 * @author     David Zülke <dz@bitxtender.com>
	 * @since      0.11.0
	 */
	public function getOutputType($name = null)
	{
		$logger = $this->context?->getLoggerManager()?->getLogger();
		if(self::DEBUG && $logger) { $logger->debug("controller.get_output_type in name=".var_export($name, true)." default=".var_export($this->defaultOutputType, true)); }
		
		if($name === null) {
			if($this->defaultOutputType !== null) {
				$name = $this->defaultOutputType;
				if(self::DEBUG && $logger) { $logger->debug("controller.get_output_type use defaultOT $name"); }
			} else {
				// Fall back to first available output type if no default is set
				$name = array_key_first($this->outputTypes) ?: 'html';
				if(self::DEBUG && $logger) { $logger->debug("controller.get_output_type fallback firstOT $name"); }
			}
		} else {
			if(self::DEBUG && $logger) { $logger->debug("controller.get_output_type provided $name"); }
		}
		
		if(isset($this->outputTypes[$name])) {
			if(self::DEBUG && $logger) { $logger->debug("controller.get_output_type return $name"); }
			return $this->outputTypes[$name];
		}
		throw new AgaviException('Output Type "' . $name . '" has not been configured.');
	}
}

?>