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
namespace Agavi;

use Agavi\Config\AgaviConfig;
use Agavi\Config\AgaviConfigCache;
use Agavi\Config\AgaviAPCuConfigCache;
use Agavi\Controller\AgaviController;
use Agavi\Exception\AgaviDisabledModuleException;
use Agavi\Exception\AgaviException;
use Agavi\Routing\AgaviRouting;
use Agavi\Routing\AgaviSoapRouting;
use Agavi\Routing\AgaviWebRouting;
use Agavi\Translation\AgaviTranslationManager;
use Agavi\User\AgaviISecurityUser;
use Agavi\User\AgaviUser;
use Agavi\Util\AgaviToolkit;
use Symfony\Contracts\Service\ResetInterface;

/**
 * AgaviContext provides information about the current application context, 
 * such as the module and action names and the module directory. 
 * It also serves as a gateway to the core pieces of the framework, allowing
 * objects with access to the context, to access other useful objects such as
 * the current controller, request, user, database manager etc.
 *
 * @package    agavi
 * @subpackage core
 *
 * @author     Sean Kerr <skerr@mojavi.org>
 * @author     Mike Vincent <mike@agavi.org>
 * @author     David Zülke <dz@bitxtender.com>
 * @copyright  Authors
 * @copyright  The Agavi Project
 *
 * @since      0.9.0
 *
 * @version    $Id$
 */
class AgaviContext implements \Stringable, ResetInterface
{
	// Debug: Log when this class version is loaded
	static $debugLoaded = true;
	
	/**
	 * @var        ?AgaviController A Controller instance.
	 */
	protected $controller = null;
	
	/**
	 * @var        array An array of class names for frequently used factories.
	 */
	protected $factories = [
		'dispatch_filter' => null,
		'execution_container' => null,
		'execution_filter' => null,
		'filter_chain' => null,
		'response' => null,
		'security_filter' => null,
		'validation_manager' => null,
	];
	
	/**
	 * @var        AgaviDatabaseManager A DatabaseManager instance.
	 */
	protected $databaseManager = null;
	
	/**
	 * @var        AgaviLoggerManager A LoggerManager instance.
	 */
	protected $loggerManager = null;
	
	/**
	 * @var        AgaviRequest A Request instance.
	 */
	protected $request = null;
	
	/**
	 * @var        AgaviRouting A Routing instance.
	 */
	protected $routing = null;
	
	/**
	 * @var        AgaviStorage A Storage instance.
	 */
	protected $storage = null;
	
	/**
	 * @var        AgaviTranslationManager A TranslationManager instance.
	 */
	protected $translationManager = null;
	
	/**
	 * @var        AgaviUser A User instance.
	 */
	protected $user = null;
	
	/**
	 * @var        array The array used for the shutdown sequence.
	 */
	protected $shutdownSequence = [];
	
	/**
	 * @var        array An array of AgaviContext instances.
	 */
	protected static $instances = [];
	
	/**
	 * @var        array An array of SingletonModel instances.
	 */
	protected $singletonModelInstances = [];
	
	/**
	 * @var        array Reset instances for FrankenPHP worker mode
	 */
	protected $resetInstances = [];
	
	/**
	 * @var        array Request factory info for worker mode recreation
	 */
	protected $requestFactoryInfo = null;

	/**
	 * @var        array User factory info for worker mode recreation
	 */
	protected $userFactoryInfo = null;

	/**
	 * @var        array Storage factory info for worker mode recreation
	 */
	protected $storageFactoryInfo = null;

	/**
	 * Clone method, overridden to prevent cloning, there can be only one.
	 *
	 * @author     Mike Vincent <mike@agavi.org>
	 * @since      0.9.0
	 */
	public function __clone()
	{
		trigger_error('Cloning an AgaviContext instance is not allowed.', E_USER_ERROR);
	}

	/**
     * Constructor method, intentionally made protected so the context cannot be
     * created directly.
     *
     * @param      string The name of this context.
     *
     * @author     David Zülke <dz@bitxtender.com>
     * @author     Mike Vincent <mike@agavi.org>
     * @since      0.9.0
     * @param string $name
     */
    protected function __construct(
        /**
         * @var        string The name of the Context.
         */
        protected $name
    )
    {
    }

	/**
	 * __toString overload, returns the name of the Context.
	 *
	 * @return     string The context name.
	 *
	 * @see        AgaviContext::getName()
	 *
	 * @author     David Zülke <dz@bitxtender.com>
	 * @since      0.11.0
	 */
	public function __toString(): string
	{
		return $this->getName();
	}
	
	/**
	 * Get information on a frequently used class.
	 *
	 * @param      string The factory identifier.
	 *
	 * @return     array An associative array (keys 'class' and 'parameters').
	 *
	 * @author     David Zülke <dz@bitxtender.com>
	 * @since      0.11.0
	 */
	public function getFactoryInfo($for)
	{
		if(isset($this->factories[$for])) {
			return $this->factories[$for];
		}
	}
	
	/**
	 * Set information on a frequently used class.
	 *
	 * @param      string The factory identifier.
	 * @param      array An associative array (keys 'class' and 'parameters').
	 *
	 * @author     Felix Gilcher <felix.gilcher@bitxtender.com>
	 * @since      1.0.1
	 */
	public function setFactoryInfo($for, array $info)
	{
		$this->factories[$for] = $info;
	}

	/**
	 * Factory for frequently used classes from factories.xml
	 *
	 * @param      string The factory identifier.
	 *
	 * @return     mixed An instance, already initialized with parameters.
	 *
	 * @throws     AgaviException If no such identifier exists.
	 *
	 * @author     David Zülke <david.zuelke@bitextender.com>
	 * @since      1.0.0
	 */
	public function createInstanceFor($for)
	{
		$info = $this->getFactoryInfo($for);
		if(null === $info) {
			throw new AgaviException(sprintf('No factory info for "%s"', $for));
		}
		
		$class = new $info['class']();
		$class->initialize($this, $info['parameters']);
		return $class;
	}

	/**
	 * Retrieve the controller.
	 *
	 * @return     AgaviController The current Controller implementation instance.
	 *
	 * @author     Sean Kerr <skerr@mojavi.org>
	 * @since      0.9.0
	 */
	public function getController()
	{
		return $this->controller;
	}

	/**
	 * Retrieve a database connection from the database manager.
	 *
	 * This is a shortcut to manually getting a connection from an existing
	 * database implementation instance.
	 *
	 * If the core.use_database setting is off, this will return null.
	 *
	 * @param      name A database name.
	 *
	 * @return     mixed A database connection.
	 *
	 * @throws     <b>AgaviDatabaseException</b> If the requested database name 
	 *                                           does not exist.
	 *
	 * @author     Sean Kerr <skerr@mojavi.org>
	 * @since      0.9.0
	 */
	public function getDatabaseConnection($name = null)
	{
		if($this->databaseManager !== null) {
			return $this->databaseManager->getDatabase($name)->getConnection();
		}
	}

	/**
	 * Retrieve the database manager.
	 *
	 * @return     AgaviDatabaseManager|null The current DatabaseManager instance
	 *                                       or null if database support is disabled.
	 *
	 * @author     Sean Kerr <skerr@mojavi.org>
	 * @since      0.9.0
	 */
	public function getDatabaseManager()
	{
		return $this->databaseManager;
	}

	/**
	 * Retrieve the AgaviContext instance.
	 *
	 * If you don't supply a profile name this will try to return the context 
	 * specified in the <kbd>core.default_context</kbd> setting.
	 *
	 * @param      string A name corresponding to a section of the config
	 *
	 * @return     AgaviContext An context instance initialized with the 
	 *                          settings of the requested context name
	 *
	 * @author     Dominik del Bondio <ddb@bitxtender.com>
	 * @author     David Zülke <dz@bitxtender.com>
	 * @author     Mike Vincent <mike@agavi.org>
	 * @since      0.9.0
	 */
	public static function getInstance($profile = null)
	{
		try {
			if($profile === null) {
				$profile = AgaviConfig::get('core.default_context');
				if($profile === null) {
					throw new AgaviException('You must supply a context name to AgaviContext::getInstance() or set the name of the default context to be used in the configuration directive "core.default_context".');
				}
			}
			$profile = strtolower($profile);
			if(!isset(self::$instances[$profile])) {
				$class = AgaviConfig::get('core.context_implementation', static::class);
				self::$instances[$profile] = new $class($profile);
				self::$instances[$profile]->initialize();
			}
			return self::$instances[$profile];
		} catch(\Exception $e) {
			AgaviException::render($e);
		}
	}
	
	/**
	 * Reset context state for FrankenPHP worker mode.
	 * This method clears request-specific state while preserving the context configuration.
	 * 
	 * Called automatically by FrankenPHP between requests when using worker mode.
	 *
	 * @author     Auto-generated for FrankenPHP compatibility
	 * @since      1.1.0
	 */
	public function reset(): void
	{
		error_log("AgaviContext::reset() - Starting context reset");
		
		// Reset singleton model instances
		$this->singletonModelInstances = [];
		error_log("AgaviContext::reset() - Reset singleton model instances");
		
		// Log user state before reset
		if ($this->user) {
			$userClass = get_class($this->user);
			if($this->user instanceof \Agavi\User\AgaviISecurityUser) {
				$isAuthenticated = $this->user->isAuthenticated() ? 'YES' : 'NO';
			} else {
				$isAuthenticated = 'N/A';
			}
			error_log("AgaviContext::reset() - User before reset: class=$userClass, authenticated=$isAuthenticated");
		} else {
			error_log("AgaviContext::reset() - No user object found");
		}
		
		// Reset the controller state if it exists
		if ($this->controller && $this->controller instanceof ResetInterface) {
			$this->controller->reset();
			error_log("AgaviContext::reset() - Reset controller");
		}
		
		// CRITICAL: Manually execute the shutdown sequence in correct order for FrankenPHP
		// This ensures session data is saved properly before clearing state
		error_log("AgaviContext::reset() - Executing shutdown sequence manually for FrankenPHP");
		
		// Execute the shutdown sequence in the same order as would happen during normal shutdown
		// But skip components that don't need shutdown or would interfere with worker mode
		foreach($this->shutdownSequence as $component) {
			if ($component === $this->user && $component !== null) {
				error_log("AgaviContext::reset() - Shutting down user to save session data");
				$component->shutdown();
			} elseif ($component === $this->storage && $component !== null) {
				error_log("AgaviContext::reset() - Shutting down storage to write session");
				$component->shutdown(); // This calls session_write_close()
			}
			// Skip controller, request, routing, translationManager, databaseManager shutdowns
			// as they're not needed for session persistence and might interfere with worker mode
		}
		
		error_log("AgaviContext::reset() - Shutdown sequence completed");
		
		// Now reset object references for next request
		// In worker mode, null the storage so it gets recreated with fresh startup() call
		// This ensures session_start() is called properly on each request
		$this->storage = null;
		error_log("AgaviContext::reset() - Nulled storage for worker mode (will be recreated)");
		
		// Reset user object (it will be recreated with clean session state)
		$this->user = null;
		error_log("AgaviContext::reset() - Set user to null");
		
		// Reset routing component instances
		foreach ($this->resetInstances as $instance) {
			if ($instance instanceof ResetInterface) {
				$instance->reset();
			}
		}
		error_log("AgaviContext::reset() - Reset routing instances");
		
		// CRITICAL: Reset routing object to prevent cache corruption in worker mode
		if ($this->routing && $this->routing instanceof ResetInterface) {
			$this->routing->reset();
			error_log("AgaviContext::reset() - Reset routing object");
		}
		
		// Reset request object (it will be recreated for the next request)
		$this->request = null;
		error_log("AgaviContext::reset() - Set request to null (will be recreated for next request)");
		
		error_log("AgaviContext::reset() - Context reset completed");
	}
	
	/**
	 * Reset context state for FrankenPHP worker mode.
	 * This method clears request-specific state while preserving the context configuration.
	 *
	 * @param      string The profile name to reset (if null, resets all contexts)
	 *
	 * @author     Auto-generated for FrankenPHP compatibility
	 * @since      1.1.0
	 */
	public static function resetWorkerState($profile = null)
	{
		if ($profile !== null) {
			$profile = strtolower($profile);
			if (isset(self::$instances[$profile])) {
				// Reset individual context state
				$context = self::$instances[$profile];
				if ($context instanceof ResetInterface) {
					$context->reset();
				}
			}
		} else {
			// Reset all contexts
			foreach (self::$instances as $context) {
				if ($context instanceof ResetInterface) {
					$context->reset();
				}
			}
		}
	}
	
	/**
	 * Retrieve the LoggerManager
	 *
	 * @return     AgaviLoggerManager|null The current LoggerManager implementation 
	 *                                     instance or null if logging is disabled.
	 *
	 * @author     David Zülke <dz@bitxtender.com>
	 * @since      0.11.0
	 */
	public function getLoggerManager()
	{
		// Check if logging is enabled at runtime
		if (!AgaviConfig::get('core.use_logging', false)) {
			return null;
		}
		return $this->loggerManager;
	}

	/**
	 * (re)Initialize the AgaviContext instance.
	 *
	 * @author     Dominik del Bondio <ddb@bitxtender.com>
	 * @author     David Zülke <dz@bitxtender.com>
	 * @author     Mike Vincent <mike@agavi.org>
	 * @since      0.10.0
	 */
	public function initialize()
	{
		try {
			if(defined('\AGAVI_USE_APCU_CONFIG_CACHE') && \AGAVI_USE_APCU_CONFIG_CACHE) {
				error_log("DEBUG: AgaviContext using APCu config cache for factories.xml");
				$cacheFile = AgaviAPCuConfigCache::checkConfig(AgaviConfig::get('core.config_dir') . '/factories.xml', $this->name);
				
				// Check if we got an APCu marker
				if (is_string($cacheFile) && strpos($cacheFile, 'APCU:') === 0) {
					// Extract the APCu key and eval the content directly
					$apcuKey = substr($cacheFile, 5); // Remove 'APCU:' prefix
					$content = \apcu_fetch($apcuKey);
					if ($content !== false) {
						error_log("DEBUG: AgaviContext executing factories.xml directly from APCu (no file I/O)");
						eval('?>' . $content);
					} else {
						error_log("ERROR: AgaviContext could not fetch factories.xml from APCu key: " . $apcuKey);
					}
				} else {
					// Regular file include
					include($cacheFile);
				}
			} else {
				error_log("DEBUG: AgaviContext using regular config cache for factories.xml (constant defined: " . (defined('\AGAVI_USE_APCU_CONFIG_CACHE') ? 'yes' : 'no') . ", value: " . (defined('\AGAVI_USE_APCU_CONFIG_CACHE') ? (\AGAVI_USE_APCU_CONFIG_CACHE ? 'true' : 'false') : 'undefined') . ")");
				include(AgaviConfigCache::checkConfig(AgaviConfig::get('core.config_dir') . '/factories.xml', $this->name));
			}
		} catch(\Exception $e) {
			AgaviException::render($e, $this);
		}
		
		// Capture request factory info for worker mode recreation
		if ($this->request !== null) {
			$this->requestFactoryInfo = [
				'class' => get_class($this->request),
				'parameters' => [] // Request typically uses empty parameters
			];
			error_log("DEBUG: AgaviContext captured request factory info: " . $this->requestFactoryInfo['class']);
		}
		
		// Capture user factory info for worker mode recreation
		if ($this->user !== null) {
			$this->userFactoryInfo = [
				'class' => get_class($this->user),
				'parameters' => [] // User typically uses empty parameters
			];
			error_log("DEBUG: AgaviContext captured user factory info: " . $this->userFactoryInfo['class']);
		}
		
		// Capture storage factory info for worker mode recreation
		if ($this->storage !== null) {
			$this->storageFactoryInfo = [
				'class' => get_class($this->storage),
				'parameters' => [] // Storage typically uses empty parameters
			];
			error_log("DEBUG: AgaviContext captured storage factory info: " . $this->storageFactoryInfo['class']);
		}
		
		// Register reset instances for FrankenPHP worker mode
		$this->initializeResetInstances();
		
		// In FrankenPHP worker mode, we handle shutdown manually in reset()
		// to avoid double shutdown calls that could clear session data
		$isFrankenPHP = function_exists('\frankenphp_request_context') || 
		                getenv('FRANKENPHP_VERSION') !== false ||
		                (isset($_SERVER['SERVER_SOFTWARE']) && stripos($_SERVER['SERVER_SOFTWARE'], 'frankenphp') !== false) ||
		                defined('FRANKENPHP_VERSION');
		
		if (!$isFrankenPHP) {
			register_shutdown_function([$this, 'shutdown']);
			error_log("DEBUG: AgaviContext registered shutdown function (not FrankenPHP)");
		} else {
			error_log("DEBUG: AgaviContext skipping shutdown function registration (FrankenPHP worker mode)");
		}
	}
	
	/**
	 * Initialize reset instances for FrankenPHP worker mode
	 * These instances will be automatically reset by FrankenPHP between requests
	 */
	protected function initializeResetInstances()
	{
		// Register routing component reset instances
		if (class_exists('Agavi\Routing\AgaviRouteCacheManager')) {
			$this->resetInstances[] = \Agavi\Routing\AgaviRouteCacheManager::getInstance();
		}
		
		if (class_exists('Agavi\Routing\AgaviRouteTrie')) {
			$this->resetInstances[] = \Agavi\Routing\AgaviRouteTrie::getResetInstance();
		}
		
		if (class_exists('Agavi\Routing\AgaviRoutingCallbackPool')) {
			$this->resetInstances[] = \Agavi\Routing\AgaviRoutingCallbackPool::getResetInstance();
		}
	}
	
	/**
	 * Shut down this AgaviContext and all related factories.
	 *
	 * @author     David Zülke <dz@bitxtender.com>
	 * @since      0.11.0
	 */
	public function shutdown()
	{
		foreach($this->shutdownSequence as $object) {
			$object->shutdown();
		}
	}
	
	/**
	 * Retrieve a Model implementation instance.
	 *
	 * @param      string A model name or fully qualified class name.
	 * @param      string A module name, if the requested model is a module model,
	 *                    or null for global models. (DEPRECATED with namespaces)
	 * @param      array  An array of parameters to be passed to initialize() or
	 *                    the constructor.
	 *
	 * @return     AgaviModel A Model implementation instance.
	 *
	 * @throws     AgaviAutloadException if class is ultimately not found.
	 *
	 * @author     David Zülke <dz@bitxtender.com>
	 * @since      0.11.0
	 */
	public function getModel($modelName, $moduleName = null, ?array $parameters = null)
	{
		$origModelName = $modelName;
		$class = null;
		$file = null;
		$rc = null;
		
		// Check if this is a fully qualified namespaced class name
		if (strpos($modelName, '\\') !== false) {
			// This is a namespaced class, try it directly first
			$class = $modelName;
			// Also try with 'Model' suffix if it doesn't already end with 'Model'
			if (!str_ends_with($class, 'Model')) {
				$class .= 'Model';
			}
			
			if (!class_exists($class)) {
				// Try without the 'Model' suffix
				$class = $modelName;
			}
		} else {		// Try namespaced approach first with configurable namespace prefix
		$baseNamespace = AgaviConfig::get('core.namespace_prefix', 'App');
		$modelName = AgaviToolkit::canonicalName($modelName);
		$longModelName = str_replace('/', '_', $modelName);
		$namespacedModelName = str_replace('/', '\\', $modelName);
		
		if($moduleName === null) {
			// Global model - try namespaced version first
			$namespacedClass = $baseNamespace . '\\Models\\' . $namespacedModelName . 'Model';
			if(class_exists($namespacedClass)) {
				$class = $namespacedClass;
			} else {
				// Fall back to old naming convention
				$class = $longModelName . 'Model';
			}
		} else {
			try {
				$this->controller->initializeModule($moduleName);
			} catch(AgaviDisabledModuleException) {
				// swallow, this will load the modules autoload but throw an exception 
				// if the module is disabled.
			}
			
			// Module model - try namespaced version first
			$namespacedClass = $baseNamespace . '\\Modules\\' . $moduleName . '\\Models\\' . $namespacedModelName . 'Model';
			if(class_exists($namespacedClass)) {
				$class = $namespacedClass;
			} else {
				// Fall back to old naming convention
				$class = $moduleName . '_' . $longModelName . 'Model';
			}
		}
			
			// If still no class found, try manual file loading (legacy approach)
			if(!class_exists($class)) {
				if($moduleName === null) {
					$file = AgaviConfig::get('core.model_dir') . '/' . $modelName . 'Model.php';
				} else {
					$file = AgaviConfig::get('core.module_dir') . '/' . $moduleName . '/Models/' . $modelName . 'Model.php';
				}
				
				if(null !== $file && is_readable($file)) {
					require($file);
				}
			}
		}

		if(!class_exists($class)) {
			// it's not there. 
			throw new AgaviException(sprintf("Couldn't find class for Model %s", $origModelName));
		}
		
		// so if we're here, we found something, right? good.
		
		$rc = new \ReflectionClass($class);
		
		if($rc->implementsInterface('Agavi\Model\AgaviISingletonModel')) {
			// it's a singleton
			if(!isset($this->singletonModelInstances[$class])) {
				// no instance yet, so we create one
				
				if($parameters === null || $rc->getConstructor() === null) {
					// it has an initialize() method, or no parameters were given, so we don't hand arguments to the constructor
					$this->singletonModelInstances[$class] = new $class();
				} else {
					// we use this approach so we can pass constructor params or if it doesn't have an initialize() method
					$this->singletonModelInstances[$class] = $rc->newInstanceArgs($parameters);
				}
			}
			$model = $this->singletonModelInstances[$class];
		} else {
			// create an instance
			if($parameters === null || $rc->getConstructor() === null) {
				// it has an initialize() method, or no parameters were given, so we don't hand arguments to the constructor
				$model = new $class();
			} else {
				// we use this approach so we can pass constructor params or if it doesn't have an initialize() method
				$model = $rc->newInstanceArgs($parameters);
			}
		}
		
		if(is_callable([$model, 'initialize'])) {
			// pass the constructor params again. dual use for the win
			$model->initialize($this, (array) $parameters);
		}
		
		return $model;
	}

	/**
	 * Retrieve the name of this Context.
	 *
	 * @return     string A context name.
	 *
	 * @author     David Zülke <dz@bitxtender.com>
	 * @since      0.11.0
	 */
	public function getName()
	{
		return $this->name;
	}
	
	/**
	 * Retrieve the request.
	 *
	 * @return     AgaviRequest The current Request implementation instance.
	 *
	 * @author     Sean Kerr <skerr@mojavi.org>
	 * @since      0.9.0
	 */
	public function getRequest()
	{
		// Lazy initialization for worker mode - recreate request object if null after reset
		if ($this->request === null) {
			error_log("AgaviContext::getRequest() - Request object is null, recreating...");
			
			if ($this->requestFactoryInfo !== null) {
				// Recreate the request object using captured factory info
				$className = $this->requestFactoryInfo['class'];
				$parameters = $this->requestFactoryInfo['parameters'];
				
				$this->request = new $className();
				// IMPORTANT: Must call initialize() BEFORE startup() to populate request data from superglobals
				// initialize() reads from $_GET, $_POST, etc. and populates the request data holder
				// startup() clears the superglobals (when unset_input parameter is true)
				$this->request->initialize($this, $parameters);
				$this->request->startup();
				
				// Re-run controller startup so it re-caches the (new) global request data pointer
				if ($this->controller && method_exists($this->controller, 'startup')) {
					try {
						$this->controller->startup();
						error_log("AgaviContext::getRequest() - Controller startup re-run after request recreation");
					} catch(\Throwable $e) {
						error_log("AgaviContext::getRequest() - Controller startup failed: ".$e->getMessage());
					}
				}
				
				error_log("AgaviContext::getRequest() - Request object recreated successfully using factory info: $className");
			} else {
				error_log("AgaviContext::getRequest() - No request factory info available, cannot recreate request");
				throw new AgaviException("Request object is null and no factory info available for recreation in worker mode");
			}
		}
		
		return $this->request;
	}

	/**
	 * Retrieve the routing.
	 *
	 * @return     AgaviRouting|AgaviWebRouting|AgaviSoapRouting The current Routing implementation instance.
	 *
	 * @author     Dominik del Bondio <ddb@bitxtender.com>
	 * @since      0.11.0
	 */
	public function getRouting()
	{
		return $this->routing;
	}

	/**
	 * Retrieve the storage.
	 *
	 * @return     AgaviStorage The current Storage implementation instance.
	 *
	 * @author     Sean Kerr <skerr@mojavi.org>
	 * @since      0.9.0
	 */
	public function getStorage()
	{
		// Lazy initialization for worker mode - recreate storage object if null after reset
		if ($this->storage === null) {
			error_log("AgaviContext::getStorage() - Storage object is null, recreating...");
			
			if ($this->storageFactoryInfo !== null) {
				// Recreate the storage object using captured factory info
				$className = $this->storageFactoryInfo['class'];
				$parameters = $this->storageFactoryInfo['parameters'];
				
				$this->storage = new $className();
				$this->storage->initialize($this, $parameters);
				$this->storage->startup();
				
				error_log("AgaviContext::getStorage() - Storage object recreated successfully using factory info: $className");
			} else {
				error_log("AgaviContext::getStorage() - No storage factory info available, cannot recreate storage");
				throw new AgaviException("Storage object is null and no factory info available for recreation in worker mode");
			}
		}
		
		return $this->storage;
	}

	/**
	 * Retrieve the translation manager.
	 *
	 * @return     AgaviTranslationManager|null The current TranslationManager
	 *                                          implementation instance or null if
	 *                                          translations are disabled.
	 *
	 * @author     Dominik del Bondio <ddb@bitxtender.com>
	 * @since      0.11.0
	 */
	public function getTranslationManager()
	{
		// Check if translations are enabled at runtime
		if (!AgaviConfig::get('core.use_translation', false)) {
			return null;
		}
		return $this->translationManager;
	}

	/**
	 * Retrieve the user.
	 *
	 * @return     AgaviUser|AgaviISecurityUser The current User implementation instance.
	 *
	 * @author     Sean Kerr <skerr@mojavi.org>
	 * @since      0.9.0
	 */
	public function getUser()
	{
		// Lazy initialization for worker mode - recreate user object if null after reset
		if ($this->user === null) {
			error_log("AgaviContext::getUser() - User object is null, recreating...");
			
			// Ensure storage is available before creating user (user initialization needs storage)
			if ($this->storage === null) {
				error_log("AgaviContext::getUser() - Storage is null, recreating storage first...");
				$this->getStorage(); // This will recreate storage if needed
			}
			
			if ($this->userFactoryInfo !== null) {
				// Recreate the user object using captured factory info
				$className = $this->userFactoryInfo['class'];
				$parameters = $this->userFactoryInfo['parameters'];
				
				$this->user = new $className();
				$this->user->initialize($this, $parameters);
				$this->user->startup();
				
				error_log("AgaviContext::getUser() - User object recreated successfully using factory info: $className");
			} else {
				error_log("AgaviContext::getUser() - No user factory info available, cannot recreate user");
				throw new AgaviException("User object is null and no factory info available for recreation in worker mode");
			}
		}
		
		return $this->user;
	}
}

?>