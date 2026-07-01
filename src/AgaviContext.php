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
use Agavi\Request\AgaviWebRequest;
use Agavi\Routing\AgaviRouting;
use Agavi\Translation\AgaviTranslationManager;
use Agavi\User\AgaviISecurityUser;
use Agavi\User\AgaviUser;
use Agavi\Util\AgaviToolkit;
use Symfony\Contracts\Service\ResetInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\ResponseInterface;

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
   * Per-request correlation ID (regenerated each handle()).
   * Used only for diagnostics; not propagated to clients.
   */
  protected ?string $correlationId = null;

  /**
   * @var        ?AgaviController A Controller instance.
   */
  protected $controller = null;

  /**
   * @var        array An array of class names for frequently used factories.
   */
  protected $factories = [
    // Legacy filters removed; only remaining non-var factories listed here
    "response" => null,
    "validation_manager" => null,
  ];

  /**
   * @var        AgaviDatabaseManager A DatabaseManager instance.
   */
  protected $databaseManager = null;

  /**
   * @var        AgaviWebRequest A Request instance.
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
   * @var        array Routing factory info for worker mode recreation
   */
  protected $routingFactoryInfo = null;

  /**
   * @var        array Controller factory info for worker mode recreation (prevent dynamic property creation)
   */
  protected $controllerFactoryInfo = null;

  /**
   * @var        array TranslationManager factory info for worker mode recreation (prevent dynamic property creation)
   */
  protected $translationManagerFactoryInfo = null;
  /** @var \Agavi\Middleware\MiddlewarePipeline|null */
  protected static $psrKernel = null;

  /** @var \Agavi\Execution\SlotDispatcher|null */
  protected $slotDispatcher = null;

  /** @var \Agavi\Execution\ActionResolver|null */
  protected $actionResolver = null;

  /**
   * @var        array Storage factory info for worker mode recreation
   */
  protected $storageFactoryInfo = null;

  /**
   * @var        array Database manager factory info for worker mode recreation
   */
  protected $databaseManagerFactoryInfo = null;

  /**
   * Clone method, overridden to prevent cloning, there can be only one.
   *
   * @author     Mike Vincent <mike@agavi.org>
   * @since      0.9.0
   */
  public function __clone()
  {
    trigger_error(
      "Cloning an AgaviContext instance is not allowed.",
      E_USER_ERROR,
    );
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
    protected $name,
  ) {}

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
    if (!isset($this->factories[$for])) {
      return null;
    }
    $info = $this->factories[$for];
    // New generated factories add a nested 'factory_info' key while legacy tests
    // expect only ['class'=>..,'parameters'=>..]. Prefer the nested structure
    // when present for forward compatibility, but return only the minimal
    // shape to satisfy historical expectations (AgaviContextTest).
    if (
      isset($info["factory_info"]) &&
      is_array($info["factory_info"]) &&
      isset($info["factory_info"]["class"])
    ) {
      return $info["factory_info"];
    }
    // Fallback: normalize to expected shape.
    return [
      "class" => $info["class"] ?? null,
      "parameters" => $info["parameters"] ?? [],
    ];
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
    if (null === $info) {
      throw new AgaviException(sprintf('No factory info for "%s"', $for));
    }

    $class = new ($info["class"])();
    $class->initialize($this, $info["parameters"]);
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
    if ($this->databaseManager !== null) {
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
      if ($profile === null) {
        $profile = AgaviConfig::get("core.default_context");
        if ($profile === null) {
          throw new AgaviException(
            'You must supply a context name to AgaviContext::getInstance() or set the name of the default context to be used in the configuration directive "core.default_context".',
          );
        }
      }
      $profile = strtolower($profile);
      if (!isset(self::$instances[$profile])) {
        $class = AgaviConfig::get("core.context_implementation", static::class);
        self::$instances[$profile] = new $class($profile);
        self::$instances[$profile]->initialize();
      }
      return self::$instances[$profile];
    } catch (\Exception $e) {
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
    $logger = \Agavi\Logging\Log::for($this);

    // Reset singleton model instances
    $this->singletonModelInstances = [];
    $this->slotDispatcher = null; // rebuild per request

    // Log user state before reset
    if ($this->user) {
      $userClass = $this->user::class;
      if ($this->user instanceof \Agavi\User\AgaviISecurityUser) {
        $isAuthenticated = $this->user->isAuthenticated() ? "YES" : "NO";
      } else {
        $isAuthenticated = "N/A";
      }
      if ($logger) {
        $logger->debug(
          "[AgaviContext.reset] user class=$userClass authenticated=$isAuthenticated",
        );
      }
    } else {
      if ($logger) {
        $logger->debug("[AgaviContext.reset] no user object");
      }
    }

    // Reset the controller state if it exists
    if ($this->controller && $this->controller instanceof ResetInterface) {
      $this->controller->reset();
      if ($logger) {
        $logger->debug("context.reset controller reset");
      }
    }

    // CRITICAL: Manually execute the shutdown sequence in correct order for FrankenPHP
    // This ensures session data is saved properly before clearing state
    if ($logger && $logger->isEnabled(\Agavi\Logging\Level::Debug)) {
      $logger->debug("context.reset manual shutdown sequence");
    }

    // Execute the shutdown sequence in the same order as would happen during normal shutdown
    // But skip components that don't need shutdown or would interfere with worker mode
    foreach ($this->shutdownSequence as $component) {
      if ($component === $this->user && $component !== null) {
        if ($logger) {
          $logger->debug("context.reset shutdown user");
        }
        $component->shutdown();
      } elseif ($component === $this->storage && $component !== null) {
        if ($logger) {
          $logger->debug("context.reset shutdown storage");
        }

        // CRITICAL: Must call shutdown() BEFORE reset() to ensure session data is persisted
        // shutdown() persists dirty session data to database/storage
        // reset() clears in-memory state for next request
        $component->shutdown(); // This persists session data

        // Reset storage connections after shutdown if it implements ResetInterface
        if ($component instanceof ResetInterface) {
          if ($logger && $logger->isEnabled(\Agavi\Logging\Level::Debug)) {
            $logger->debug("[AgaviContext] calling storage reset()");
          }
          $component->reset();
        }
      } elseif ($component === $this->databaseManager && $component !== null) {
        // Recycle (ping + null dead connections) instead of full shutdown so the manager
        // stays alive across requests, avoiding re-initialization cost.
        if ($logger && $logger->isEnabled(\Agavi\Logging\Level::Debug)) {
          $logger->debug(
            "context.reset recycleConnections databaseManager - id=" .
              spl_object_id($component),
          );
        }
        $component->recycleConnections();
      }
      // Skip controller, request, routing, translationManager shutdowns
      // as they're not needed for session persistence and might interfere with worker mode
    }

    if ($logger && $logger->isEnabled(\Agavi\Logging\Level::Debug)) {
      $logger->debug("context.reset shutdown complete");
    }

    // Now reset object references for next request
    // In worker mode, null the storage so it gets recreated with fresh startup() call
    // This ensures session_start() is called properly on each request
    $this->storage = null;
    if ($logger && $logger->isEnabled(\Agavi\Logging\Level::Debug)) {
      $logger->debug("context.reset storage nulled");
    }

    // Reset user object (it will be recreated with clean session state)
    $this->user = null;
    if ($logger && $logger->isEnabled(\Agavi\Logging\Level::Debug)) {
      $logger->debug("[AgaviContext.reset] user cleared");
    }

    // Reset routing component instances
    foreach ($this->resetInstances as $instance) {
      if ($instance instanceof ResetInterface) {
        $instance->reset();
      }
    }
    if ($logger && $logger->isEnabled(\Agavi\Logging\Level::Debug)) {
      $logger->debug("[AgaviContext.reset] routing reset instances");
    }

    // CRITICAL: Reset routing object to prevent cache corruption in worker mode
    if ($this->routing && $this->routing instanceof ResetInterface) {
      $this->routing->reset();
      if ($logger) {
        $logger->debug("[AgaviContext.reset] routing object reset");
      }
    }

    // Reset request object (it will be recreated for the next request)
    $this->request = null;
    // Reset PSR middleware kernel for worker mode safety
    if ($logger && $logger->isEnabled(\Agavi\Logging\Level::Debug)) {
      $logger->debug("[AgaviContext.reset] request nulled");
    }

    // Drop all ambient logging scopes so this request's rid/user/etc. cannot leak
    // into the next request's log lines in a long-lived worker (same cross-request
    // leak class as the session state cleared elsewhere in this reset).
    \Agavi\Logging\LogContext::clear();

    if ($logger && $logger->isEnabled(\Agavi\Logging\Level::Debug)) {
      $logger->debug("[AgaviContext.reset] completed");
    }
  }

  /**
	 * Reset context state for FrankenPHP worker mode.
	 * This method clears request-specific state while preserving the context configuration.
	// We intentionally DO NOT reset *FactoryInfo properties as these are immutable across
	// requests and used for lazy recreation (request/user/routing/storage/databaseManager).
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

  public function handle(ServerRequestInterface $request): ResponseInterface
  {
    if (self::$psrKernel === null) {
      self::$psrKernel = new \Agavi\Middleware\MiddlewarePipeline($this);
    }
    // Generate a new correlation ID for this inbound request (simple high-entropy base32 fragment)
    // TODO: Support configurable header name for e.g. Azure Application Gateway correlation ID
    try {
      $bytes = random_bytes(10);
      $this->correlationId = rtrim(
        strtr(base64_encode($bytes), "+/=", "ABC"),
        "=",
      );
    } catch (\Throwable) {
      $this->correlationId = uniqid("req", true);
    }

    // Start a fresh ambient logging scope for this request so every log line is
    // correlatable by rid. clear() first is defensive: it guards against a scope
    // left behind by a prior worker request whose reset() did not run. The
    // authoritative between-request clear lives in reset().
    \Agavi\Logging\LogContext::clear();
    \Agavi\Logging\LogContext::enrich(["rid" => $this->correlationId]);

    // Bridge: ensure a legacy AgaviWebRequest exists and attach the current PSR request for BC helpers
    try {
      if (!$this->request) {
        // try to create immediately so later getRequest() in rendering doesn't need lazy recreation
        if ($this->requestFactoryInfo) {
          $className = $this->requestFactoryInfo["class"];
          $parameters = $this->requestFactoryInfo["parameters"];
          $this->request = new $className();
          $this->request->initialize($this, $parameters);
          $this->request->startup();
        }
      }
      // No need to attachPsrRequest - AgaviWebRequest IS the PSR-7 request
      // If needed, ensure context's request is same instance as pipeline request
    } catch (\Throwable) {
      // ignore
    }

    // Propagate correlation ID so middleware can use it without re-generating (avoids redundant random_bytes()).
    $request = $request->withAttribute("agavi.rid", $this->correlationId);
    $response = self::$psrKernel->handle($request);
    return $response;
  }

  /**
   * Set the request object explicitly.
   * AgaviWebRequest extends ServerRequest, so this is the single source of truth.
   */
  public function setRequest($request): void
  {
    // Normalize any foreign PSR-7 request into an AgaviWebRequest so getRequest()
    // ALWAYS returns an AgaviWebRequest (with the Agavi helpers like isHttps()).
    // A plain Nyholm\Psr7\ServerRequest can otherwise flow in via middleware
    // (SlotMiddleware, ValidationMiddleware) or tests. Non-PSR requests (e.g. a
    // console request) and existing AgaviWebRequests pass through unchanged.
    if (
      $request !== null
      && !($request instanceof \Agavi\Request\AgaviWebRequest)
      && $request instanceof \Psr\Http\Message\ServerRequestInterface
    ) {
      $request = \Agavi\Request\AgaviWebRequest::fromPsr($request);
    }
    $this->request = $request;
    try {
      $message = sprintf(
        "[AgaviContext] setRequest id=%d cid=%s",
        spl_object_id($request),
        $this->correlationId,
      );
    } catch (\Throwable) {
      $message =
        "[AgaviContext] setRequest (no id) cid=" . $this->correlationId;
    }
    \Agavi\Logging\Log::for($this)->debug($message);
  }

  /**
   * Retrieve current correlation ID (may be null outside a handled request).
   */
  public function getCorrelationId(): ?string
  {
    return $this->correlationId;
  }

  /**
   * Retrieve (lazily create) SlotDispatcher for sub-action (slot) execution.
   */
  public function getSlotDispatcher(): \Agavi\Execution\SlotDispatcher
  {
    if ($this->slotDispatcher === null) {
      // New signature: (controller, actionResolver?, executionGuard?, viewNameResolver?)
      $this->slotDispatcher = new \Agavi\Execution\SlotDispatcher(
        $this->getController(),
        $this->getActionResolver(),
      );
    }
    return $this->slotDispatcher;
  }

  public function getActionResolver(): \Agavi\Execution\ActionResolver
  {
    if ($this->actionResolver === null) {
      $this->actionResolver = new \Agavi\Execution\ActionResolver();
    }
    return $this->actionResolver;
  }

  /**
   * Retrieve the current PSR-7 ServerRequest.
   * Since AgaviWebRequest extends ServerRequest, this returns the same object as getRequest().
   * May return null for legacy/CLI execution paths.
   */
  public function getCurrentPsrRequest(): ?ServerRequestInterface
  {
    return $this->request;
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
      $logger = \Agavi\Logging\Log::for($this);
      if (
        defined("AGAVI_USE_APCU_CONFIG_CACHE") &&
        AGAVI_USE_APCU_CONFIG_CACHE
      ) {
        $logger?->debug(
          "AgaviContext using APCu config cache for factories.xml",
        );
        $cacheResult = AgaviAPCuConfigCache::checkConfig(
          AgaviConfig::get("core.config_dir") . "/factories.xml",
          $this->name,
        );

        if (str_starts_with($cacheResult, "APCU:")) {
          $logger?->debug(
            "AgaviContext executing factories.xml directly from APCu (no file I/O)",
          );
          eval("?>" . substr($cacheResult, 5));
        } else {
          include $cacheResult;
        }
      } else {
        $logger?->debug(
          "AgaviContext using regular config cache for factories.xml (constant defined: " .
            (defined("AGAVI_USE_APCU_CONFIG_CACHE") ? "yes" : "no") .
            ", value: " .
            (defined("AGAVI_USE_APCU_CONFIG_CACHE")
              ? (AGAVI_USE_APCU_CONFIG_CACHE
                ? "true"
                : "false")
              : "undefined") .
            ")",
        );
        include AgaviConfigCache::checkConfig(
          AgaviConfig::get("core.config_dir") . "/factories.xml",
          $this->name,
        );
      }
    } catch (\Exception $e) {
      AgaviException::render($e, $this);
    }

    // Invariants: factory info for core components must be present now (set by generated factories cache)
    $invariantList = [
      "userFactoryInfo" => "user",
      "routingFactoryInfo" => "routing",
      "storageFactoryInfo" => "storage",
      "requestFactoryInfo" => "request",
    ];
    if (AgaviConfig::get("core.use_database", false)) {
      $invariantList["databaseManagerFactoryInfo"] = "databaseManager";
    }
    foreach ($invariantList as $prop => $label) {
      if ($this->$prop === null) {
        $logger?->error(
          "AgaviContext invariant failed: missing $prop after initialize() (component '$label')",
        );
        throw new AgaviException(
          "Context initialization failed: missing factory metadata for '$label'",
        );
      }
    }

    // Register reset instances for FrankenPHP worker mode
    $this->initializeResetInstances();

    // In FrankenPHP worker mode, we handle shutdown manually in reset()
    // to avoid double shutdown calls that could clear session data
    $isFrankenPHP =
      function_exists('\frankenphp_request_context') ||
      getenv("FRANKENPHP_VERSION") !== false ||
      (isset($_SERVER["SERVER_SOFTWARE"]) &&
        stripos((string) $_SERVER["SERVER_SOFTWARE"], "frankenphp") !== false) ||
      defined("FRANKENPHP_VERSION");

    if (!$isFrankenPHP) {
      register_shutdown_function([$this, "shutdown"]);
      $logger?->debug(
        "AgaviContext registered shutdown function (not FrankenPHP)",
      );
    } else {
      $logger?->debug(
        "AgaviContext skipping shutdown function registration (FrankenPHP worker mode)",
      );
    }

    // Worker-mode bootstrap happens before the first real HTTP request. At that time
    // superglobals like $_COOKIE and $_SERVER['REQUEST_METHOD'] are not yet populated
    // with the inbound request, but factories.xml has already eagerly created
    // storage + user and invoked storage->startup() and user->initialize(). Because
    // no cookie is visible yet, storage->startup() defers (sid=null) and user->initialize()
    // reads null auth => authenticated=false gets latched. Later, when the first
    // real request arrives, code that consults isAuthenticated() or user attributes
    // may observe this false before any lazy promotion can occur (e.g. redirect logic),
    // effectively logging a previously authenticated user out after a container restart.
    //
    // Mitigation: In FrankenPHP worker mode, if we are still in pre-request bootstrap
    // (no REQUEST_METHOD), discard the eagerly created user so that the first access
    // to getUser() after the real request starts will recreate the user *after* storage
    // has a chance to see the incoming cookie and load the persisted auth state.
    //
    // This is intentionally narrow in scope (FrankenPHP + no REQUEST_METHOD) to avoid
    // impacting CLI or non-worker environments.
    if (
      $isFrankenPHP &&
      !isset($_SERVER["REQUEST_METHOD"]) &&
      $this->user !== null
    ) {
      try {
        if ($logger) {
          $logger->debug(
            "AgaviContext.initialize pre-request: deferring user creation until first real request",
          );
        }
        // Remove existing user from shutdown sequence (keep order of remaining components)
        foreach ($this->shutdownSequence as $idx => $obj) {
          if ($obj === $this->user) {
            unset($this->shutdownSequence[$idx]);
          }
        }
        $this->shutdownSequence = array_values($this->shutdownSequence);
        $this->user = null; // force lazy recreation in getUser()
      } catch (\Throwable) {
        // swallow – failing to defer is a soft failure
      }
    }
  }

  /**
   * Initialize reset instances for FrankenPHP worker mode
   * These instances will be automatically reset by FrankenPHP between requests
   */
  protected function initializeResetInstances()
  {
    // Only the callback pool remains relevant; legacy route cache/trie removed.
    if (class_exists(\Agavi\Routing\AgaviRoutingCallbackPool::class)) {
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
    foreach ($this->shutdownSequence as $object) {
      try {
        if (is_object($object) && method_exists($object, "shutdown")) {
          $object->shutdown();
        }
      } catch (\Throwable $e) {
        // swallow shutdown errors to avoid masking original execution context
        $logger = \Agavi\Logging\Log::for($this);
        if ($logger->isEnabled(\Agavi\Logging\Level::Debug)) {
          $logger->debug(
            "[AgaviContext] shutdown component error " .
              $object::class .
              " msg=" .
              $e->getMessage(),
          );
        }
      }
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
  public function getModel(
    $modelName,
    $moduleName = null,
    ?array $parameters = null,
  ) {
    $origModelName = $modelName;
    $class = null;
    $file = null;
    $rc = null;

    // Check if this is a fully qualified namespaced class name
    if (str_contains((string) $modelName, "\\")) {
      // This is a namespaced class, try it directly first
      $class = $modelName;
      // Also try with 'Model' suffix if it doesn't already end with 'Model'
      if (!str_ends_with($class, "Model")) {
        $class .= "Model";
      }

      if (!class_exists($class)) {
        // Try without the 'Model' suffix
        $class = $modelName;
      }
    } else {
      // Try namespaced approach first with configurable namespace prefix
      $baseNamespace = AgaviConfig::get("core.namespace_prefix", "App");
      $modelName = AgaviToolkit::canonicalName($modelName);
      $longModelName = str_replace("/", "_", $modelName);
      $namespacedModelName = str_replace("/", "\\", $modelName);

      if ($moduleName === null) {
        // Global model - try namespaced version first
        $namespacedClass =
          $baseNamespace . "\\Models\\" . $namespacedModelName . "Model";
        if (class_exists($namespacedClass)) {
          $class = $namespacedClass;
        } else {
          // Fall back to old naming convention
          $class = $longModelName . "Model";
        }
      } else {
        try {
          $this->controller->initializeModule($moduleName);
        } catch (AgaviDisabledModuleException) {
          // swallow, this will load the modules autoload but throw an exception
          // if the module is disabled.
        }

        // Module model - try namespaced version first
        $namespacedClass =
          $baseNamespace .
          "\\Modules\\" .
          $moduleName .
          "\\Models\\" .
          $namespacedModelName .
          "Model";
        if (class_exists($namespacedClass)) {
          $class = $namespacedClass;
        } else {
          // Fall back to old naming convention
          $class = $moduleName . "_" . $longModelName . "Model";
        }
      }

      // If still no class found, try manual file loading (legacy approach)
      if (!class_exists($class)) {
        if ($moduleName === null) {
          $file =
            AgaviConfig::get("core.model_dir") . "/" . $modelName . "Model.php";
        } else {
          $file =
            AgaviConfig::get("core.module_dir") .
            "/" .
            $moduleName .
            "/Models/" .
            $modelName .
            "Model.php";
        }

        if (null !== $file && is_readable($file)) {
          require $file;
        }
      }
    }

    if (!class_exists($class)) {
      // it's not there.
      throw new AgaviException(
        sprintf("Couldn't find class for Model %s", $origModelName),
      );
    }

    // so if we're here, we found something, right? good.

    $rc = new \ReflectionClass($class);

    if ($rc->implementsInterface(\Agavi\Model\AgaviISingletonModel::class)) {
      // it's a singleton
      if (!isset($this->singletonModelInstances[$class])) {
        // no instance yet, so we create one

        if ($parameters === null || $rc->getConstructor() === null) {
          // it has an initialize() method, or no parameters were given, so we don't hand arguments to the constructor
          $this->singletonModelInstances[$class] = new $class();
        } else {
          // we use this approach so we can pass constructor params or if it doesn't have an initialize() method
          $this->singletonModelInstances[$class] = $rc->newInstanceArgs(
            $parameters,
          );
        }
      }
      $model = $this->singletonModelInstances[$class];
    } else {
      // create an instance
      if ($parameters === null || $rc->getConstructor() === null) {
        // it has an initialize() method, or no parameters were given, so we don't hand arguments to the constructor
        $model = new $class();
      } else {
        // we use this approach so we can pass constructor params or if it doesn't have an initialize() method
        $model = $rc->newInstanceArgs($parameters);
      }
    }

    if (is_callable([$model, "initialize"])) {
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
   * @return     AgaviWebRequest The current Request implementation instance.
   *
   * @author     Sean Kerr <skerr@mojavi.org>
   * @since      0.9.0
   */
  public function getRequest()
  {
    // Lazy initialization for worker mode - recreate request object if null after reset
    if ($this->request === null) {
      $logger = \Agavi\Logging\Log::for($this);
      if ($logger->isEnabled(\Agavi\Logging\Level::Debug)) {
        $logger->debug(
          "[AgaviContext] getRequest() Request object is null, recreating...",
        );
      }

      if ($this->requestFactoryInfo !== null) {
        // Recreate the request object using captured factory info
        $className = $this->requestFactoryInfo["class"];
        $parameters = $this->requestFactoryInfo["parameters"];

        $this->request = new $className();
        // IMPORTANT: Must call initialize() BEFORE startup() to populate request data from superglobals
        // initialize() reads from $_GET, $_POST, etc. and populates the request data holder
        // startup() clears the superglobals (when unset_input parameter is true)
        $this->request->initialize($this, $parameters);
        $this->request->startup();

        // No need to attachPsrRequest - AgaviWebRequest IS the PSR-7 request

        // Re-run controller startup so it re-caches the (new) global request data pointer
        if ($this->controller && method_exists($this->controller, "startup")) {
          try {
            $this->controller->startup();
            if ($logger->isEnabled(\Agavi\Logging\Level::Debug)) {
              $logger->debug(
                "[AgaviContext] getRequest() Controller startup re-run after request recreation",
              );
            }
          } catch (\Throwable $e) {
            if ($logger->isEnabled(\Agavi\Logging\Level::Debug)) {
              $logger->debug(
                "[AgaviContext] getRequest() Controller startup failed after request recreation: " .
                  $e->getMessage(),
              );
            }
          }
        }
        if ($logger->isEnabled(\Agavi\Logging\Level::Debug)) {
          $logger->debug(
            "[AgaviContext] getRequest() Request object recreated successfully using factory info: " .
              $className,
          );
        }
      } else {
        if ($logger->isEnabled(\Agavi\Logging\Level::Debug)) {
          $logger->debug(
            "[AgaviContext] getRequest() No request factory info available, cannot recreate request",
          );
        }
        throw new AgaviException(
          "Request object is null and no factory info available for recreation in worker mode",
        );
      }
    }

    return $this->request;
  }

  /**
   * Retrieve the routing.
   *
   * @return     AgaviRouting The current Routing implementation instance.
   *
   * @author     Dominik del Bondio <ddb@bitxtender.com>
   * @since      0.11.0
   */
  public function getRouting()
  {
    // Lazy initialization for worker mode - recreate routing object if null after reset
    if ($this->routing === null) {
      $logger = \Agavi\Logging\Log::for($this);
      $logger?->debug(
        "AgaviContext::getRouting() - Routing object is null, recreating...",
      );
      // Recreate from factory info if available
      if ($this->routingFactoryInfo !== null) {
        $className = $this->routingFactoryInfo["class"];
        $this->routing = new $className();
        $logger?->debug(
          "AgaviContext::getRouting() - Routing (compat) object recreated via factory info: " .
            $className,
        );
      } else {
        $logger?->error(
          "AgaviContext::getRouting() - No routing factory info available, cannot recreate routing",
        );
        throw new AgaviException(
          "Routing object is null and no factory info available for recreation in worker mode",
        );
      }
    }
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
      $logger = \Agavi\Logging\Log::for($this);
      $logger?->debug(
        "[AgaviContext.getStorage] - Storage object is null, recreating...",
      );
      // Ensure database manager is available if database use is enabled BEFORE creating storage (storage may need DB)
      if (
        AgaviConfig::get("core.use_database", false) &&
        $this->databaseManager === null
      ) {
        $logger?->debug(
          "[AgaviContext.getStorage] - Database manager is null, attempting recreation...",
        );
        if ($this->databaseManagerFactoryInfo !== null) {
          $className = $this->databaseManagerFactoryInfo["class"];
          $parameters = $this->databaseManagerFactoryInfo["parameters"];
          try {
            $this->databaseManager = new $className();
            $this->databaseManager->initialize($this, $parameters);
            $this->databaseManager->startup();
            $logger?->debug(
              "[AgaviContext.getStorage] - Database manager recreated successfully using factory info: " .
                $className,
            );
          } catch (\Throwable $e) {
            $logger?->error(
              "[AgaviContext.getStorage] - Failed to recreate database manager: " .
                $e->getMessage(),
            );
          }
        } else {
          $logger?->warning(
            "[AgaviContext.getStorage] - Database manager factory info missing, cannot recreate (may affect storage)",
          );
        }
      }

      if ($this->storageFactoryInfo !== null) {
        // Recreate the storage object using captured factory info
        $className = $this->storageFactoryInfo["class"];
        $parameters = $this->storageFactoryInfo["parameters"];

        $this->storage = new $className();
        $this->storage->initialize($this, $parameters);
        // Do NOT call startup() here - SessionMiddleware will call it after mirroring PSR-7 cookies to $_COOKIE
        // Calling it here causes session loss because $_COOKIE is empty before SessionMiddleware runs

        $logger?->debug(
          "[AgaviContext.getStorage] - Storage object recreated successfully using factory info: " .
            $className,
        );
      } else {
        $logger?->error(
          "[AgaviContext.getStorage] - No storage factory info available, cannot recreate storage",
        );
        throw new AgaviException(
          "Storage object is null and no factory info available for recreation in worker mode",
        );
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
    if (!AgaviConfig::get("core.use_translation", false)) {
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
      // (Simplified) No serialized snapshot restore; always build fresh user below.
      $logger = \Agavi\Logging\Log::for($this);
      if ($logger->isEnabled(\Agavi\Logging\Level::Debug)) {
        try {
          $bt = [];
          $rawBt = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 12);
          foreach ($rawBt as $f) {
            $bt[] =
              ($f["file"] ?? "nofile") .
              ":" .
              ($f["line"] ?? 0) .
              " " .
              (($f["class"] ?? "") .
                ($f["type"] ?? "") .
                ($f["function"] ?? ""));
          }
          $logger->debug(
            "[getUser] user null, recreating trace=" . json_encode($bt),
          );
        } catch (\Throwable) {
        }
      }
      $logger?->debug(
        "[AgaviContext.getUser] - User object is null, recreating...",
      );
      // Ensure database manager is available if database use is enabled BEFORE creating user (user may need storage->db)
      if (
        AgaviConfig::get("core.use_database", false) &&
        $this->databaseManager === null
      ) {
        $logger?->debug(
          "[AgaviContext.getUser] - Database manager is null, attempting recreation before user...",
        );
        if ($this->databaseManagerFactoryInfo !== null) {
          $className = $this->databaseManagerFactoryInfo["class"];
          $parameters = $this->databaseManagerFactoryInfo["parameters"];
          try {
            $this->databaseManager = new $className();
            $this->databaseManager->initialize($this, $parameters);
            $this->databaseManager->startup();
            $logger?->debug(
              "[AgaviContext.getUser] - Database manager recreated successfully using factory info: " .
                $className,
            );
          } catch (\Throwable $e) {
            $logger?->error(
              "[AgaviContext.getUser] - Failed to recreate database manager: " .
                $e->getMessage(),
            );
          }
        } else {
          $logger?->warning(
            "[AgaviContext.getUser] - Database manager factory info missing, cannot recreate",
          );
        }
      }

      // Ensure storage is available before creating user (user initialization needs storage)
      if ($this->storage === null) {
        $logger?->debug(
          "[AgaviContext.getUser] - Storage is null, recreating storage first...",
        );
        $this->getStorage(); // This will recreate storage if needed
      }

      if ($this->userFactoryInfo !== null) {
        // Recreate the user object using captured factory info
        $className = $this->userFactoryInfo["class"];
        $parameters = $this->userFactoryInfo["parameters"];

        $this->user = new $className();
        $this->user->initialize($this, $parameters);
        $this->user->startup();
        if ($logger->isEnabled(\Agavi\Logging\Level::Debug)) {
          $logger->debug(
            "[AgaviContext.getUser] newUser=" .
              $this->user::class .
              " oid=" .
              spl_object_id($this->user),
          );
        }

        // Replace any stale user instances in shutdown sequence to avoid persisting outdated auth state later
        try {
          // Intent: eliminate stale user objects but *preserve original relative ordering*
          // The original generated sequence already places user before storage. Unshifting
          // the new user to index 0 (previous logic) needlessly moved it ahead of
          // controller/routing and could skip late mutations they might perform.
          // Strategy: capture first user index, remove all user instances, then reinsert
          // the fresh instance at the captured index (or just before storage if none).
          $firstUserIndex = null;
          $removedAny = false;
          foreach ($this->shutdownSequence as $idx => $component) {
            if (
              $component instanceof \Agavi\User\AgaviUser ||
              $component instanceof \Agavi\User\AgaviISecurityUser
            ) {
              if ($firstUserIndex === null) {
                $firstUserIndex = $idx;
              }
              unset($this->shutdownSequence[$idx]);
              $removedAny = true;
            }
          }
          $this->shutdownSequence = array_values($this->shutdownSequence);
          if ($firstUserIndex === null) {
            $storagePos = array_find_key($this->shutdownSequence, fn($component) => $component === $this->storage);
            if ($storagePos === null) {
              $firstUserIndex = 0;
            } else {
              $firstUserIndex = max(0, $storagePos);
            }
          }
          // Insert user at calculated index (array_splice preserves order after insertion)
          array_splice($this->shutdownSequence, $firstUserIndex, 0, [
            $this->user,
          ]);
          if ($logger->isEnabled(\Agavi\Logging\Level::Debug)) {
            $logger->debug(
              "[AgaviContext.getUser] registered user in shutdownSequence replaced=" .
                ($removedAny ? 1 : 0) .
                " idx=" .
                $firstUserIndex .
                " oid=" .
                spl_object_id($this->user),
            );
          }
        } catch (\Throwable) {
        }

        $logger?->debug(
          "[AgaviContext.getUser] - User object recreated successfully using factory info: " .
            $className,
        );
      } else {
        $logger?->error(
          "[AgaviContext.getUser] - No user factory info available, cannot recreate user",
        );
        throw new AgaviException(
          "User object is null and no factory info available for recreation in worker mode",
        );
      }
    }

    return $this->user;
  }
}
