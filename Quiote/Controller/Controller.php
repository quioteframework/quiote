<?php
namespace Quiote\Controller;
/**
 * Controller directs application flow.
 * @since      1.0.0
 * @version    1.0.0
 */

use Quiote\Action\Action;
use Quiote\Util\ParameterHolder;
use Quiote\Exception\ControllerException;
use Quiote\Config\Config;
use Quiote\Config\ConfigCache;
use Quiote\Config\APCuConfigCache;
use Quiote\Exception\DisabledModuleException;
use Quiote\Response\WebResponse;
use Quiote\Exception\QuioteException;
use Quiote\Util\Toolkit;
use Quiote\Exception\ClassNotFoundException;
use Quiote\Exception\FileNotFoundException;
use Quiote\Context;
use Quiote\Request\IHeadersRequestDataHolder;
use Symfony\Contracts\Service\ResetInterface;
use Psr\Http\Message\ServerRequestInterface;

use \Exception;
use Psr\Http\Message\ResponseInterface;

class Controller extends ParameterHolder implements ResetInterface
{
	/** Enable verbose controller lifecycle logging (worker diagnostics). */
	public const DEBUG = false; // set true for deep debugging

	/**
	 * Indirection around the DEBUG constant with an explicit `bool` return
	 * type so flipping DEBUG to true for local debugging doesn't require
	 * touching every call site, and so the checks below read as an ordinary
	 * runtime condition rather than a compile-time-constant literal.
	 * @return     bool Whether verbose controller lifecycle logging is enabled.
	 */
	private static function debugEnabled(): bool
	{
		return self::DEBUG;
	}

	/**
	 * @var        int The number of execution containers run so far.
	 */
	protected $numExecutions = 0;

	/**
	 * @var        ?Context An Context instance.
	 */
	protected $context = null;

	/**
	 * @var        ?WebResponse The global response.
	 */
	protected $response = null;

	// Legacy filter chain and filters removed (replaced by middleware pipeline)

	/**
	 * @var        ?string The default Output Type.
	 */
	protected $defaultOutputType = null;
	/**
	 * Stores the originally configured default output type name so worker-mode
	 * resets can restore the intended framework configuration instead of
	 * falling back to the first registered output type (which caused request 1
	 * to influence subsequent requests when $defaultOutputType was nulled).
	 * @var        ?string
	 */
	protected $configuredDefaultOutputType = null;
	
	/**
	 * @var        array<string, mixed> An array of registered Output Types.
	 */
	protected $outputTypes = [];

	/**
	 * Return the registered output type names (lowercased keys as configured).
	 * Lightweight helper for middleware that needs the list for negotiation.
	 * @return string[]
	 */
	public function getOutputTypeNames(): array
	{
		return array_map(strtolower(...), array_keys($this->outputTypes));
	}
	
	/**
	 * Increment the execution counter.
	 * Will throw an exception if the maximum amount of runs is exceeded.
	 * @throws     ControllerException If too many execution runs were made.
	 * @return     void
	 * @since      1.0.0
	 */
	public function countExecution()
	{
		$maxExecutions = $this->getParameter('max_executions');
		
		if(++$this->numExecutions > $maxExecutions && $maxExecutions > 0) {
			throw new ControllerException('Too many execution runs have been detected for this Context.');
		}
	}
	
	// Legacy createExecutionContainer* helpers removed – the PSR-15 middleware
	// pipeline now resolves and executes actions directly using descriptors &
	// ExecutionState without allocating ExecutionContainer instances.
	
	/**
	 * Ensure the deterministic per-module directive defaults are present.
	 * These describe the conventional filesystem/naming layout for a module and
	 * do not depend on the module's own configuration. Each key is only set when
	 * absent so a value supplied by the module's config is never overwritten, and
	 * so the call is safe (and cheap) to make on every initializeModule().
	 * @param      string $lowerModuleName The lower-cased module name.
	 * @return     void
	 */
	private function ensureModuleDirectiveDefaults($lowerModuleName)
	{
		$defaults = [
			'modules.' . $lowerModuleName . '.quiote.action.path' => '%core.module_dir%/${moduleName}/Actions/${actionName}Action.php',
			'modules.' . $lowerModuleName . '.quiote.cache.path' => '%core.module_dir%/${moduleName}/cache/${actionName}.xml',
			'modules.' . $lowerModuleName . '.quiote.template.directory' => '%core.module_dir%/${module}/Templates',
			'modules.' . $lowerModuleName . '.quiote.validate.path' => '%core.module_dir%/${moduleName}/Validate/${actionName}.xml',
			'modules.' . $lowerModuleName . '.quiote.view.path' => '%core.module_dir%/${moduleName}/Views/${viewName}View.php',
			'modules.' . $lowerModuleName . '.quiote.view.name' => '${actionName}${viewName}',
		];
		foreach ($defaults as $key => $value) {
			if (Config::get($key) === null) {
				Config::set($key, $value);
			}
		}
	}

	/**
	 * Initialize a module and load its autoload, module config etc.
	 * @param      string $moduleName The name of the module to initialize.
	 * @return     void
	 * @since      1.0.0
	 */
	public function initializeModule($moduleName)
	{
		$lowerModuleName = strtolower((string) $moduleName);

		// Always ensure the deterministic per-module path/name directives exist.
		// These are pure conventions (independent of module.xml) consumed later by
		// e.g. ViewNameResolver. They are normally set once on first init, but the
		// static $initializedModules guard below would otherwise skip re-creating
		// them if Config was cleared in the meantime (some tests clear and
		// restore config; a persistent worker could conceivably reset it too),
		// leaving view-name resolution to fall back to the wrong, unqualified name.
		// Setting only the missing keys keeps this cheap and never clobbers a value
		// a module's own config provided.
		$this->ensureModuleDirectiveDefaults($lowerModuleName);

		// Fast path: skip entirely if this module was already fully initialized
		// in this process (avoids repeated is_readable/Config calls).
		static $initializedModules = [];
		if (isset($initializedModules[$lowerModuleName])) {
			if (!Config::get('modules.' . $lowerModuleName . '.enabled')) {
				throw new DisabledModuleException(sprintf('The module "%1$s" is disabled.', $moduleName));
			}
			return;
		}

		if(null === Config::get('modules.' . $lowerModuleName . '.enabled')) {
			// include the module configuration
		// loaded only once due to the way load() (former import()) works
		if(is_readable(Config::get('core.module_dir') . '/' . $moduleName . '/Config/module.xml')) {
			if(defined('QUIOTE_USE_APCU_CONFIG_CACHE') && QUIOTE_USE_APCU_CONFIG_CACHE) {
				$cacheResult = APCuConfigCache::checkConfig(Config::get('core.module_dir') . '/' . $moduleName . '/Config/module.xml');
				if (str_starts_with($cacheResult, 'APCU:')) {
					eval('?>' . substr($cacheResult, 5));
				} else {
					include_once($cacheResult);
				}
			} else {
				include_once(ConfigCache::checkConfig(Config::get('core.module_dir') . '/' . $moduleName . '/Config/module.xml'));
			}
		} else {
			Config::set('modules.' . $lowerModuleName . '.enabled', true);
		}
		
		if(Config::get('modules.' . $lowerModuleName . '.enabled')) {
				$moduleConfigHandlers = Config::get('core.module_dir') . '/' . $moduleName . '/Config/config_handlers.xml';
				if(is_readable($moduleConfigHandlers)) {
					ConfigCache::addConfigHandlersFile($moduleConfigHandlers);
				}
			}
		}
		
		if(!Config::get('modules.' . $lowerModuleName . '.enabled')) {
			throw new DisabledModuleException(sprintf('The module "%1$s" is disabled.', $moduleName));
		}
		
		// check for a module config.php
		$moduleConfig = Config::get('core.module_dir') . '/' . $moduleName . '/Config.php';
		if(is_readable($moduleConfig)) {
			require_once($moduleConfig);
		}

		// Mark as initialized so subsequent calls skip all the work above
		$initializedModules[$lowerModuleName] = true;
	}
	

	/**
     * Get the global response instance.
     * @return \Quiote\Response\WebResponse The global response.
     * @since      1.0.0
     */
    public function getGlobalResponse()
	{
		return $this->response;
	}
	
	
	/**
	 * Indicates whether or not a module has a specific action file.
	 * Please note that this is only a cursory check and does not 
	 * check whether the file actually contains the proper class
	 * @param      string $moduleName A module name.
	 * @param      string $actionName An action name.
	 * @return     mixed  the path to the action file if the action file 
	 *                    exists and is readable, false in any other case
	 * @since      1.0.0
	 */
	public function checkActionFile($moduleName, $actionName)
	{
		$this->initializeModule($moduleName);
		
		$actionName = Toolkit::canonicalName($actionName);
		$file = Toolkit::evaluateModuleDirective(
			$moduleName,
			'quiote.action.path',
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
	 * @param      string $moduleName A module name.
	 * @param      string $actionName An action name.
	 * @return     Action An Action implementation instance
	 * @throws     Exception if the action could not be found.
	 * @since      1.0.0
	 */
	public function createActionInstance($moduleName, $actionName)
	{
		$this->initializeModule($moduleName);
		
		$actionName = Toolkit::canonicalName($actionName);
		$longActionName = str_replace('/', '_', $actionName);
		
		// Build class names with configurable namespace pattern
		$baseNamespace = Config::get('core.namespace_prefix', 'App');
		
		// For namespaced classes, preserve directory structure as namespaces
		$namespacedActionName = str_replace('/', '\\', $actionName);
		// Avoid double suffix if developer already named class *Action
		$actionSuffix = str_ends_with($namespacedActionName, 'Action') ? '' : 'Action';
		$namespacedClass = $baseNamespace . '\\Modules\\' . $moduleName . '\\Actions\\' . $namespacedActionName . $actionSuffix;
		$oldClass = $moduleName . '_' . $longActionName . 'Action';
		// optional debug logging removed
		
		// Try namespaced class first (autoloader will handle it)
		if(class_exists($namespacedClass)) {
			return $this->makeInstance($namespacedClass);
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
			return $this->makeInstance($oldClass);
		}
		
		// Neither class found
		throw new ClassNotFoundException(sprintf('Unable to find Action class "%s" or "%s" for Action "%s" in Module "%s".', $namespacedClass, $oldClass, $actionName, $moduleName));
	}

	/**
	 * Retrieve the current application context.
	 * @return     Context An Context instance.
	 * @since      1.0.0
	 */
	public final function getContext()
	{
		return $this->context;
	}

	/**
	 * Build an Action/View instance through the container — the single
	 * choke point both createActionInstance() and
	 * createViewInstance() route through. Uses Container::make(): a non-caching autowire,
	 * so every dispatch gets its own fresh instance, same as the plain `new $class()` this
	 * replaces. A class with no constructor is unaffected — zero migration burden.
	 * initialize($initContext) is still called by the executor after this returns, unchanged.
	 * @param      string $class A fully qualified class name.
	 * @return     object A new instance, with any constructor dependencies autowired.
	 */
	private function makeInstance($class)
	{
		return $this->getContext()->getContainer()->make($class);
	}


	
	/**
	 * Indicates whether or not a module has a specific view file.
	 * Please note that this is only a cursory check and does not 
	 * check whether the file actually contains the proper class
	 * @param      string $moduleName A module name.
	 * @param      string $viewName A view name.
	 * @return     mixed  the path to the view file if the view file 
	 *                    exists and is readable, false in any other case
	 * @since      1.0.0
	 */
	public function checkViewFile($moduleName, $viewName)
	{
		$this->initializeModule($moduleName);
		
		$viewName = Toolkit::canonicalName($viewName);
		$file = Toolkit::evaluateModuleDirective(
			$moduleName,
			'quiote.view.path',
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
	 * @param      string $moduleName A module name.
	 * @param      string $viewName A view name.
	 * @return     \Quiote\View\View A View implementation instance,
	 * @throws     Exception if the view could not be found.
	 * @since      1.0.0
	 */
	public function createViewInstance($moduleName, $viewName)
	{
		try {
			$this->initializeModule($moduleName);
		} catch(DisabledModuleException) {
			// views from disabled modules should be usable by definition
			// swallow
		}
		
		$viewName = Toolkit::canonicalName($viewName);
		$longViewName = str_replace('/', '_', $viewName);
		
		// Build class names with configurable namespace pattern
		$baseNamespace = Config::get('core.namespace_prefix', 'App');
		
		// For namespaced classes, preserve directory structure as namespaces
		$namespacedViewName = str_replace('/', '\\', $viewName);
		$namespacedClass = $baseNamespace . '\\Modules\\' . $moduleName . '\\Views\\' . $namespacedViewName . 'View';
		$oldClass = $moduleName . '_' . $longViewName . 'View';
		
		// Try namespaced class first (autoloader will handle it)
		if(class_exists($namespacedClass)) {
			return $this->makeInstance($namespacedClass);
		}

		// Fall back to old naming convention
		if(class_exists($oldClass)) {
			return $this->makeInstance($oldClass);
		}
		
		// Neither class found
		throw new ClassNotFoundException(sprintf('Unable to find View class "%s" or "%s" for View "%s" in Module "%s".', $namespacedClass, $oldClass, $viewName, $moduleName));
	}

	/**
	 * Constructor.
	 * @since      1.0.0
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
	 * @param      Context $context An Context instance.
	 * @param      array<string, mixed> $parameters An array of initialization parameters.
	 * @return     void
	 * @since      1.0.0
	 */
	public function initialize(Context $context, array $parameters = [])
	{
		$this->context = $context;

		$this->setParameters($parameters);

		$this->response = $this->context->createInstanceFor('response');

		$cfg = Config::get('core.config_dir') . '/output_types.xml';
		if(defined('QUIOTE_USE_APCU_CONFIG_CACHE') && QUIOTE_USE_APCU_CONFIG_CACHE) {
			$cacheResult = APCuConfigCache::checkConfig($cfg, $this->context->getName());
			if (str_starts_with($cacheResult, 'APCU:')) {
				eval('?>' . substr($cacheResult, 5));
			} else {
				require($cacheResult);
			}
		} else {
			require(ConfigCache::checkConfig($cfg, $this->context->getName()));
		}

		// Legacy security/dispatch/execution filters removed
	}
	

	/**
	 * Indicates whether or not a module has a specific model.
	 * @param      string $moduleName A module name.
	 * @param      string $modelName A model name.
	 * @return     bool true, if the model exists, otherwise false.
	 * @since      1.0.0
	 */
	public function modelExists($moduleName, $modelName)
	{
		$baseNamespace = Config::get('core.namespace_prefix', 'App');
		$modelName = Toolkit::canonicalName($modelName);
		$namespacedModelName = str_replace('/', '\\', $modelName);

		// APCu key (only if enabled) – immutable deployments benefit most
		$apcuKey = null;
		if(defined('QUIOTE_USE_APCU_CONFIG_CACHE') && QUIOTE_USE_APCU_CONFIG_CACHE && function_exists('apcu_fetch')) {
			$apcuKey = 'quiote_exists_model_' . md5($baseNamespace.'|'.$moduleName.'|'.$namespacedModelName);
			$cached = apcu_fetch($apcuKey, $hit);
			if($hit) {
				return (bool)$cached;
			}
		}

		$namespacedClass = $baseNamespace . '\\Modules\\' . $moduleName . '\\Models\\' . $namespacedModelName . 'Model';
		$exists = class_exists($namespacedClass);
		if(!$exists) {
			$file = Config::get('core.module_dir') . '/' . $moduleName . '/Models/' . $modelName . 'Model.php';
			$exists = is_readable($file);
		}

		if($apcuKey) {
			apcu_store($apcuKey, $exists, 0);
		}
		return $exists;
	}

	/**
	 * Indicates whether or not a module exists.
	 * @param      string $moduleName A module name.
	 * @return     bool true, if the module exists, otherwise false.
	 * @since      1.0.0
	 */
	public function moduleExists($moduleName)
	{
		$apcuKey = null;
		if(defined('QUIOTE_USE_APCU_CONFIG_CACHE') && QUIOTE_USE_APCU_CONFIG_CACHE && function_exists('apcu_fetch')) {
			$apcuKey = 'quiote_exists_module_' . md5((string) $moduleName);
			$cached = apcu_fetch($apcuKey, $hit);
			if($hit) {
				return (bool)$cached;
			}
		}
		$file = Config::get('core.module_dir') . '/' . $moduleName . '/Config/module.xml';
		$exists = is_readable($file);
		if($apcuKey) {
			apcu_store($apcuKey, $exists, 0);
		}
		return $exists;
	}

	/**
	 * Do any necessary startup work after initialization.
	 * This method is not called directly after initialize().
	 * @return     void
	 * @since      1.0.0
	 */
	public function startup()
	{
		$logger = \Quiote\Logging\Log::for($this);
		// RequestData holder deprecated; no action needed. Left intentionally blank.

		// Capture the configured default output type exactly once so we can
		// restore it after each worker reset. We must do this here (after all
		// config handlers have run) but before any request-specific logic might
		// attempt to mutate $defaultOutputType (it normally should not mutate).
		if($this->configuredDefaultOutputType === null && $this->defaultOutputType !== null) {
			$this->configuredDefaultOutputType = $this->defaultOutputType;
			if(self::debugEnabled()) { $logger->debug("controller.startup capture configuredDefaultOT=".var_export($this->configuredDefaultOutputType, true)); }
		}
	}

	/**
	 * Execute the shutdown procedure for this controller.
	 * @return     void
	 * @since      1.0.0
	 */
	public function shutdown()
	{
	}

	/**
	 * Reset the controller state for FrankenPHP worker mode.
	 * This clears request-specific state that could leak between requests.
	 * Called automatically by FrankenPHP between requests when using worker mode.
	 * @since      1.0.0
	 */
	#[\Override]
    public function reset(): void
	{
		$logger = \Quiote\Logging\Log::for($this);
		if(self::debugEnabled()) { $logger->debug("controller.reset begin defaultOT=".var_export($this->defaultOutputType, true)); }
		
		// Reset execution counter
		$this->numExecutions = 0;
		
		// Legacy filter chain/filters removed – nothing to reset here

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
		
		if(self::debugEnabled()) { $logger->debug("controller.reset end defaultOT=".var_export($this->defaultOutputType, true)); }
	}

	/**
	 * Indicates whether or not a module has a specific action.
	 * @param      string $moduleName A module name.
	 * @param      string $actionName A view name.
	 * @return     bool true, if the action exists, otherwise false.
	 * @since      1.0.0
	 */
	public function actionExists($moduleName, $actionName)
	{
		return $this->checkActionFile($moduleName, $actionName) !== false;
	}

	/**
	 * Indicates whether or not a module has a specific view.
	 * @param      string $moduleName A module name.
	 * @param      string $viewName A view name.
	 * @return     bool true, if the view exists, otherwise false.
	 * @since      1.0.0
	 */
	public function viewExists($moduleName, $viewName)
	{
		$baseNamespace = Config::get('core.namespace_prefix', 'App');
		$viewName = Toolkit::canonicalName($viewName);
		$namespacedViewName = str_replace('/', '\\', $viewName);

		$apcuKey = null;
		if(defined('QUIOTE_USE_APCU_CONFIG_CACHE') && QUIOTE_USE_APCU_CONFIG_CACHE && function_exists('apcu_fetch')) {
			$apcuKey = 'quiote_exists_view_' . md5($moduleName.'|'.$namespacedViewName);
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
	 * @param      string $name The optional output type name.
	 * @return     OutputType An Output Type object.
	 * @since      1.0.0
	 */
	public function getOutputType($name = null)
	{
		$logger = \Quiote\Logging\Log::for($this);
		if(self::debugEnabled()) { $logger->debug("controller.get_output_type in name=".var_export($name, true)." default=".var_export($this->defaultOutputType, true)); }
		
		if($name === null) {
			if($this->defaultOutputType !== null) {
				$name = $this->defaultOutputType;
				if(self::debugEnabled()) { $logger->debug("controller.get_output_type use defaultOT $name"); }
			} else {
				// Fall back to first available output type if no default is set
				$name = array_key_first($this->outputTypes) ?: 'html';
				if(self::debugEnabled()) { $logger->debug("controller.get_output_type fallback firstOT $name"); }
			}
		} else {
			if(self::debugEnabled()) { $logger->debug("controller.get_output_type provided $name"); }
		}
		
		if(isset($this->outputTypes[$name])) {
			if(self::debugEnabled()) { $logger->debug("controller.get_output_type return $name"); }
			return $this->outputTypes[$name];
		}
		throw new QuioteException('Output Type "' . $name . '" has not been configured.');
	}
}

?>