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

use Agavi\Action\AgaviAction;
use Agavi\Util\AgaviParameterHolder;
use Agavi\Exception\AgaviControllerException;
use Agavi\Config\AgaviConfig;
use Agavi\Config\AgaviConfigCache;
use Agavi\Config\AgaviAPCuConfigCache;
use Agavi\Exception\AgaviDisabledModuleException;
use Agavi\Response\AgaviWebResponse;
use Agavi\Exception\AgaviException;
use Agavi\Util\AgaviToolkit;
use Agavi\Exception\AgaviClassNotFoundException;
use Agavi\Exception\AgaviFileNotFoundException;
use Agavi\AgaviContext;
use Agavi\Request\AgaviIHeadersRequestDataHolder;
use Symfony\Contracts\Service\ResetInterface;
use Psr\Http\Message\ServerRequestInterface;

use \Exception;
use Psr\Http\Message\ResponseInterface;

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
	 * @var        AgaviWebResponse The global response.
	 */
	protected $response = null;
	
	// Legacy filter chain and filters removed (replaced by middleware pipeline)
	
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
	 * Return the registered output type names (lowercased keys as configured).
	 * Lightweight helper for middleware that needs the list for negotiation.
	 *
	 * @return string[]
	 */
	public function getOutputTypeNames(): array
	{
		return array_map(strtolower(...), array_keys($this->outputTypes));
	}
	
	/**
	 * Legacy request data reference removed (PSR-7 migration). Retained as null for BC where code checks property existence.
	 */
	private $requestData = null; // no longer populated; use AgaviWebRequest parameter helpers instead
	
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
	
	// Legacy createExecutionContainer* helpers removed – the PSR-15 middleware
	// pipeline now resolves and executes actions directly using descriptors &
	// ExecutionState without allocating AgaviExecutionContainer instances.
	
	/**
	 * Ensure the deterministic per-module directive defaults are present.
	 *
	 * These describe the conventional filesystem/naming layout for a module and
	 * do not depend on the module's own configuration. Each key is only set when
	 * absent so a value supplied by the module's config is never overwritten, and
	 * so the call is safe (and cheap) to make on every initializeModule().
	 *
	 * @param      string The lower-cased module name.
	 */
	private function ensureModuleDirectiveDefaults($lowerModuleName)
	{
		$defaults = [
			'modules.' . $lowerModuleName . '.agavi.action.path' => '%core.module_dir%/${moduleName}/Actions/${actionName}Action.php',
			'modules.' . $lowerModuleName . '.agavi.cache.path' => '%core.module_dir%/${moduleName}/cache/${actionName}.xml',
			'modules.' . $lowerModuleName . '.agavi.template.directory' => '%core.module_dir%/${module}/Templates',
			'modules.' . $lowerModuleName . '.agavi.validate.path' => '%core.module_dir%/${moduleName}/Validate/${actionName}.xml',
			'modules.' . $lowerModuleName . '.agavi.view.path' => '%core.module_dir%/${moduleName}/Views/${viewName}View.php',
			'modules.' . $lowerModuleName . '.agavi.view.name' => '${actionName}${viewName}',
		];
		foreach ($defaults as $key => $value) {
			if (AgaviConfig::get($key) === null) {
				AgaviConfig::set($key, $value);
			}
		}
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

		// Always ensure the deterministic per-module path/name directives exist.
		// These are pure conventions (independent of module.xml) consumed later by
		// e.g. ViewNameResolver. They are normally set once on first init, but the
		// static $initializedModules guard below would otherwise skip re-creating
		// them if AgaviConfig was cleared in the meantime (some tests clear and
		// restore config; a persistent worker could conceivably reset it too),
		// leaving view-name resolution to fall back to the wrong, unqualified name.
		// Setting only the missing keys keeps this cheap and never clobbers a value
		// a module's own config provided.
		$this->ensureModuleDirectiveDefaults($lowerModuleName);

		// Fast path: skip entirely if this module was already fully initialized
		// in this process (avoids repeated is_readable/AgaviConfig calls).
		static $initializedModules = [];
		if (isset($initializedModules[$lowerModuleName])) {
			if (!AgaviConfig::get('modules.' . $lowerModuleName . '.enabled')) {
				throw new AgaviDisabledModuleException(sprintf('The module "%1$s" is disabled.', $moduleName));
			}
			return;
		}

		if(null === AgaviConfig::get('modules.' . $lowerModuleName . '.enabled')) {
			// include the module configuration
		// loaded only once due to the way load() (former import()) works
		if(is_readable(AgaviConfig::get('core.module_dir') . '/' . $moduleName . '/Config/module.xml')) {
			if(defined('AGAVI_USE_APCU_CONFIG_CACHE') && AGAVI_USE_APCU_CONFIG_CACHE) {
				$cacheResult = AgaviAPCuConfigCache::checkConfig(AgaviConfig::get('core.module_dir') . '/' . $moduleName . '/Config/module.xml');
				if (str_starts_with($cacheResult, 'APCU:')) {
					eval('?>' . substr($cacheResult, 5));
				} else {
					include_once($cacheResult);
				}
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

		// Mark as initialized so subsequent calls skip all the work above
		$initializedModules[$lowerModuleName] = true;
	}
	

	/**
	 * Get the global response instance.
	 *
	 * @return     \Agavi\Response\AgaviWebResponse The global response.
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
		// Avoid double suffix if developer already named class *Action
		$actionSuffix = str_ends_with($namespacedActionName, 'Action') ? '' : 'Action';
		$namespacedClass = $baseNamespace . '\\Modules\\' . $moduleName . '\\Actions\\' . $namespacedActionName . $actionSuffix;
		$oldClass = $moduleName . '_' . $longActionName . 'Action';
		// optional debug logging removed
		
		// Try namespaced class first (autoloader will handle it)
		if(class_exists($namespacedClass)) {
			return new $namespacedClass();
		}
		
		// Fall back to old naming convention
		if(!class_exists($oldClass)) {
			// Attempt to include the legacy action file manually for old-style class names
			$file = $this->checkActionFile($moduleName, $actionName);
			if($file && is_readable($file)) {
				include_once $file;
			}
		}
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
		} catch(AgaviDisabledModuleException) {
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
			$cacheResult = AgaviAPCuConfigCache::checkConfig($cfg, $this->context->getName());
			if (str_starts_with($cacheResult, 'APCU:')) {
				eval('?>' . substr($cacheResult, 5));
			} else {
				require($cacheResult);
			}
		} else {
			require(AgaviConfigCache::checkConfig($cfg, $this->context->getName()));
		}

		// Legacy security/dispatch/execution filters removed
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
			$apcuKey = 'agavi_exists_module_' . md5((string) $moduleName);
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
		$logger = \Agavi\Logging\Log::for($this);
		// RequestData holder deprecated; no action needed. Left intentionally blank.

		// Capture the configured default output type exactly once so we can
		// restore it after each worker reset. We must do this here (after all
		// config handlers have run) but before any request-specific logic might
		// attempt to mutate $defaultOutputType (it normally should not mutate).
		if($this->configuredDefaultOutputType === null && $this->defaultOutputType !== null) {
			$this->configuredDefaultOutputType = $this->defaultOutputType;
			if(self::DEBUG) { $logger->debug("controller.startup capture configuredDefaultOT=".var_export($this->configuredDefaultOutputType, true)); }
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
	#[\Override]
    public function reset(): void
	{
		$logger = \Agavi\Logging\Log::for($this);
		if(self::DEBUG) { $logger->debug("controller.reset begin defaultOT=".var_export($this->defaultOutputType, true)); }
		
		// Reset execution counter
		$this->numExecutions = 0;
		
		// Legacy filter chain/filters removed – nothing to reset here
		
		// Clear legacy request data reference (unused in PSR-7 mode)
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
		
		if(self::DEBUG) { $logger->debug("controller.reset end defaultOT=".var_export($this->defaultOutputType, true)); }
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
		$logger = \Agavi\Logging\Log::for($this);
		if(self::DEBUG) { $logger->debug("controller.get_output_type in name=".var_export($name, true)." default=".var_export($this->defaultOutputType, true)); }
		
		if($name === null) {
			if($this->defaultOutputType !== null) {
				$name = $this->defaultOutputType;
				if(self::DEBUG) { $logger->debug("controller.get_output_type use defaultOT $name"); }
			} else {
				// Fall back to first available output type if no default is set
				$name = array_key_first($this->outputTypes) ?: 'html';
				if(self::DEBUG) { $logger->debug("controller.get_output_type fallback firstOT $name"); }
			}
		} else {
			if(self::DEBUG) { $logger->debug("controller.get_output_type provided $name"); }
		}
		
		if(isset($this->outputTypes[$name])) {
			if(self::DEBUG) { $logger->debug("controller.get_output_type return $name"); }
			return $this->outputTypes[$name];
		}
		throw new AgaviException('Output Type "' . $name . '" has not been configured.');
	}
}

?>