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
use Agavi\Request\AgaviRequestDataHolder;
use Agavi\Exception\AgaviDisabledModuleException;
use Agavi\Response\AgaviResponse;
use Agavi\Exception\AgaviException;
use Agavi\Util\AgaviToolkit;
use Agavi\Exception\AgaviClassNotFoundException;
use Agavi\Exception\AgaviFileNotFoundException;
use Agavi\AgaviContext;
use Agavi\Filter\AgaviFilterChain;
use Symfony\Contracts\Service\ResetInterface;

use \Exception;
class AgaviController extends AgaviParameterHolder implements ResetInterface
{
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
		// create a new execution container
		$container = $this->context->createInstanceFor('execution_container');
		$container->setModuleName($moduleName);
		$container->setActionName($actionName);
		$container->setRequestData($this->requestData);
		if($arguments !== null) {
			$container->setArguments($arguments);
		}
		$container->setOutputType($this->context->getController()->getOutputType($outputType));
		if($requestMethod === null) {
			$requestMethod = $this->context->getRequest()->getMethod();
		}
		$container->setRequestMethod($requestMethod);
		return $container;
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
			]);
			// include the module configuration
			// loaded only once due to the way load() (former import()) works
			if(is_readable(AgaviConfig::get('core.module_dir') . '/' . $moduleName . '/Config/module.xml')) {
				include_once(AgaviConfigCache::checkConfig(AgaviConfig::get('core.module_dir') . '/' . $moduleName . '/Config/module.xml'));		} else {
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
		try {
			
			$rq = $this->context->getRequest();
			$rd = $rq->getRequestData();
			
			if($container === null) {
				$container = $this->context->getRouting()->execute();
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
		require(AgaviConfigCache::checkConfig($cfg, $this->context->getName()));
		
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
				require(AgaviConfigCache::checkConfig($config, $this->context->getName()));
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
		
		// Try namespaced version first
		$namespacedClass = $baseNamespace . '\\Modules\\' . $moduleName . '\\Models\\' . $namespacedModelName . 'Model';
		if(class_exists($namespacedClass)) {
			return true;
		}
		
		// Fall back to old file-based check for backward compatibility
		$file = AgaviConfig::get('core.module_dir') . '/' . $moduleName . '/Models/' . $modelName . 'Model.php';
		return is_readable($file);
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
		$file = AgaviConfig::get('core.module_dir') . '/' . $moduleName . '/Config/module.xml';
		return is_readable($file);
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
		// grab a pointer to the request data
		$this->requestData = $this->context->getRequest()->getRequestData();
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
		
		// Try namespaced version first
		$namespacedClass = $baseNamespace . '\\Modules\\' . $moduleName . '\\Views\\' . $namespacedViewName . 'View';
		if(class_exists($namespacedClass)) {
			return true;
		}
		
		// Fall back to old file-based check for backward compatibility
		return $this->checkViewFile($moduleName, $viewName) !== false;
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
		if($name === null) {
			$name = $this->defaultOutputType;
		}
		if(isset($this->outputTypes[$name])) {
			return $this->outputTypes[$name];
		} else {
			throw new AgaviException('Output Type "' . $name . '" has not been configured.');
		}
	}
}

?>