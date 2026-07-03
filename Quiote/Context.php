<?php
namespace Quiote;

use Quiote\Config\Config;
use Quiote\Config\ConfigCache;
use Quiote\Config\APCuConfigCache;
use Quiote\Controller\Controller;
use Quiote\DI\Container;
use Quiote\Exception\DisabledModuleException;
use Quiote\Exception\QuioteException;
use Quiote\Request\WebRequest;
use Quiote\Routing\Routing;
use Quiote\Translation\TranslationManager;
use Quiote\User\ISecurityUser;
use Quiote\User\User;
use Quiote\Util\Toolkit;
use Symfony\Contracts\Service\ResetInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * Context provides information about the current application context,
 * such as the module and action names and the module directory.
 * It also serves as a gateway to the core pieces of the framework, allowing
 * objects with access to the context, to access other useful objects such as
 * the current controller, request, user, database manager etc.
 * @since      1.0.0
 * @version    1.0.0
 */
class Context implements \Stringable, ResetInterface
{
  // Debug: Log when this class version is loaded
  static $debugLoaded = true;

  /**
   * Per-request correlation ID, resolved each handle(): adopted from the
   * configured inbound header (core.correlation_id.header, default
   * X-Correlation-Id) when present and sane, else generated. Echoed back on the
   * response unless core.correlation_id.expose is false.
   */
  protected ?string $correlationId = null;

  /**
   * @var        ?Controller A Controller instance.
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
   * @var        DatabaseManager A DatabaseManager instance.
   */
  protected $databaseManager = null;

  /**
   * @var        WebRequest A Request instance.
   */
  protected $request = null;

  /**
   * @var        Routing A Routing instance.
   */
  protected $routing = null;

  /**
   * @var        Storage A Storage instance.
   */
  protected $storage = null;

  /**
   * @var        TranslationManager A TranslationManager instance.
   */
  protected $translationManager = null;

  /**
   * @var        User A User instance.
   */
  protected $user = null;

  /**
   * @var        array The array used for the shutdown sequence.
   */
  protected $shutdownSequence = [];

  /**
   * @var        array An array of Context instances.
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
  /** @var \Quiote\Middleware\MiddlewarePipeline|null */
  protected static $psrKernel = null;

  /** @var \Quiote\Execution\SlotDispatcher|null */
  protected $slotDispatcher = null;

  /** @var \Quiote\Execution\ActionResolver|null */
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
   * @var        Container|null DI container (docs/DI_MIGRATION_PLAN.md, Phase 1).
   *             Additive/observational for now: factories.xml remains the single
   *             source of truth for construction of the core services below.
   */
  protected ?Container $container = null;

  /**
   * Clone method, overridden to prevent cloning, there can be only one.
   * @since      1.0.0
   */
  public function __clone()
  {
    trigger_error(
      "Cloning an Context instance is not allowed.",
      E_USER_ERROR,
    );
  }

  /**
   * Constructor method, intentionally made protected so the context cannot be
   * created directly.
   * @param      string The name of this context.
   * @since      1.0.0
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
   * @return     string The context name.
   * @see        Context::getName()
   * @since      1.0.0
   */
  public function __toString(): string
  {
    return $this->getName();
  }

  /**
   * Get information on a frequently used class.
   * @param      string The factory identifier.
   * @return     array An associative array (keys 'class' and 'parameters').
   * @since      1.0.0
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
    // shape to satisfy historical expectations (ContextTest).
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
   * @param      string The factory identifier.
   * @param      array An associative array (keys 'class' and 'parameters').
   * @since      1.0.0
   */
  public function setFactoryInfo($for, array $info)
  {
    $this->factories[$for] = $info;
  }

  /**
   * Factory for frequently used classes from factories.xml
   * @param      string The factory identifier.
   * @return     mixed An instance, already initialized with parameters.
   * @throws     Exception If no such identifier exists.
   * @since      1.0.0
   */
  public function createInstanceFor($for)
  {
    $info = $this->getFactoryInfo($for);
    if (null === $info) {
      throw new QuioteException(sprintf('No factory info for "%s"', $for));
    }

    $class = new ($info["class"])();
    $class->initialize($this, $info["parameters"]);
    return $class;
  }

  /**
   * Retrieve the controller.
   * @return     Controller The current Controller implementation instance.
   * @since      1.0.0
   */
  public function getController()
  {
    return $this->controller;
  }

  /**
   * Retrieve (lazily create) the DI container.
   * Phase 1 of docs/DI_MIGRATION_PLAN.md: the core services created by factories.xml
   * are registered here under their role name and concrete class name, so both
   * `getContainer()->get('user')` and `getContainer()->get(JakamoRbacUser::class)`
   * resolve to the same instance. factories.xml remains the sole construction path;
   * nothing in the framework resolves services *through* the container yet.
   */
  public function getContainer(): Container
  {
    if ($this->container === null) {
      $this->container = new Container();
    }
    return $this->container;
  }

  /**
   * Retrieve a service from the container.
   * Phase 3 of docs/DI_MIGRATION_PLAN.md: the locator escape hatch for legacy call sites
   * and lazy/conditional access (the `IServiceProvider`-injection equivalent from .NET).
   * The preferred path for new code is constructor injection; both resolve through the
   * same container. Thin wrapper — exceptions from the container propagate as-is.
   * Deliberately does not touch getModel(): services and models remain separate
   * conventions (see docs/DI_MIGRATION_PLAN.md §2.5).
   */
  public function getService(string $id): mixed
  {
    return $this->getContainer()->get($id);
  }

  /**
   * Register an already-constructed core service instance into the container
   * under its role name and concrete class name. No-op if $instance is null
   * (e.g. databaseManager/translationManager when disabled by config).
   */
  private function registerCoreService(string $role, ?object $instance, string $scope = Container::SCOPE_SINGLETON): void
  {
    if ($instance === null) {
      return;
    }
    $container = $this->getContainer();
    $container->set($role, $instance, $scope);
    $container->set($instance::class, $instance, $scope);
  }

  /**
   * Register the full set of core services (as they currently stand) into the container.
   * Called once after factories.xml runs, and again whenever a request/user/storage/routing
   * instance is lazily recreated in worker mode, so the container never holds a stale
   * reference to an object Context has already discarded.
   */
  private function registerCoreServicesInContainer(): void
  {
    // Register this context itself, so a service's constructor can type-hint Context
    // (or the app's context subclass) and have it autowired — needed for the transitional
    // Quiote\Service\Service base (docs/DI_MIGRATION_PLAN.md, Phase 3).
    $container = $this->getContainer();
    $container->set('context', $this);
    $container->set(static::class, $this);
    if (static::class !== self::class) {
      $container->alias(self::class, static::class);
    }

    $this->registerCoreService('controller', $this->controller);
    $this->registerCoreService('databaseManager', $this->databaseManager);
    $this->registerCoreService('translationManager', $this->translationManager);
    $this->registerCoreService('routing', $this->routing);
    $this->registerCoreService('request', $this->request, Container::SCOPE_REQUEST);
    $this->registerCoreService('storage', $this->storage, Container::SCOPE_REQUEST);
    $this->registerCoreService('user', $this->user, Container::SCOPE_REQUEST);
    $this->registerTelemetryServicesInContainer();
    $this->registerHttpClientFactory();
    // Plugin-contributed DI services (register-if-absent, so core/app bindings
    // above win). See docs/PLUGIN_AND_EXTENSIBILITY_PLAN.md.
    \Quiote\Plugin\PluginManager::configureContainer($container);
  }

  /**
   * Register the named-HTTP-client factory as a worker-lifetime container
   * singleton, applying any plugin-contributed named-client configs the first
   * time it's built. Constructor-inject {@see \Quiote\Http\Client\HttpClientFactory}
   * (or resolve 'http_client_factory') to obtain named clients.
   */
  private function registerHttpClientFactory(): void
  {
    $container = $this->getContainer();
    if ($container->has(\Quiote\Http\Client\HttpClientFactory::class)) {
      return;
    }
    $container->setFactory(\Quiote\Http\Client\HttpClientFactory::class, static function (): \Quiote\Http\Client\HttpClientFactory {
      $factory = new \Quiote\Http\Client\HttpClientFactory();
      \Quiote\Plugin\PluginManager::configureHttpClients($factory);
      return $factory;
    }, Container::SCOPE_SINGLETON);
    $container->alias('http_client_factory', \Quiote\Http\Client\HttpClientFactory::class);
  }

  /**
   * Register the DI-injectable OpenTelemetry provider aliases
   * (docs/OPENTELEMETRY_PLAN.md, Phase 2). No-op unless telemetry is enabled
   * AND {@see \Quiote\Telemetry\TelemetryBootstrap} actually built a real
   * provider — mirrors {@see registerCoreService()}'s "no-op if unavailable"
   * convention, so `$container->get(TracerProviderInterface::class)` throws
   * the usual `NotFoundException` rather than resolving to null when
   * telemetry is off. The container factory reads the same worker-lifetime
   * singleton {@see \Quiote\Telemetry\TraceRegistry} already holds, so calling
   * this repeatedly (as this method is, per its own docblock above) never
   * creates a second provider instance.
   */
  private function registerTelemetryServicesInContainer(): void
  {
    if (!Config::get('telemetry.enabled', false) || !\Quiote\Telemetry\TraceRegistry::hasRealProvider()) {
      return;
    }
    $container = $this->getContainer();
    $container->setFactory(
      \OpenTelemetry\SDK\Trace\TracerProviderInterface::class,
      fn() => \Quiote\Telemetry\TraceRegistry::tracerProvider(),
      Container::SCOPE_SINGLETON
    );
    $container->alias(\OpenTelemetry\API\Trace\TracerProviderInterface::class, \OpenTelemetry\SDK\Trace\TracerProviderInterface::class);

    $container->setFactory(
      \OpenTelemetry\SDK\Metrics\MeterProviderInterface::class,
      fn() => \Quiote\Telemetry\TraceRegistry::meterProvider(),
      Container::SCOPE_SINGLETON
    );
    $container->alias(\OpenTelemetry\API\Metrics\MeterProviderInterface::class, \OpenTelemetry\SDK\Metrics\MeterProviderInterface::class);
  }

  /**
   * Retrieve a database connection from the database manager.
   * This is a shortcut to manually getting a connection from an existing
   * database implementation instance.
   * If the core.use_database setting is off, this will return null.
   * @param      name A database name.
   * @return     mixed A database connection.
   * @throws     <b>DatabaseException</b> If the requested database name
   *                                           does not exist.
   * @since      1.0.0
   */
  public function getDatabaseConnection($name = null)
  {
    if ($this->databaseManager !== null) {
      return $this->databaseManager->getDatabase($name)->getConnection();
    }
  }

  /**
   * Retrieve the database manager.
   * @return     DatabaseManager|null The current DatabaseManager instance
   *                                       or null if database support is disabled.
   * @since      1.0.0
   */
  public function getDatabaseManager()
  {
    return $this->databaseManager;
  }

  /**
   * Retrieve the Context instance.
   * If you don't supply a profile name this will try to return the context
   * specified in the <kbd>core.default_context</kbd> setting.
   * @param      string A name corresponding to a section of the config
   * @return     Context An context instance initialized with the
   *                          settings of the requested context name
   * @since      1.0.0
   */
  public static function getInstance($profile = null)
  {
    try {
      if ($profile === null) {
        $profile = Config::get("core.default_context");
        if ($profile === null) {
          throw new QuioteException(
            'You must supply a context name to Context::getInstance() or set the name of the default context to be used in the configuration directive "core.default_context".',
          );
        }
      }
      $profile = strtolower($profile);
      if (!isset(self::$instances[$profile])) {
        $class = Config::get("core.context_implementation", static::class);
        self::$instances[$profile] = new $class($profile);
        self::$instances[$profile]->initialize();
      }
      return self::$instances[$profile];
    } catch (\Exception $e) {
      // Bootstrap-time failure (no PSR-15 pipeline exists yet to catch this via
      // ErrorHandlingMiddleware): log and propagate rather than rendering an
      // ad-hoc template and exit()ing, which would kill a persistent worker
      // process outright instead of just failing the request that triggered it.
      \Quiote\Logging\Log::for(self::class)->error(
        'Context::getInstance() failed: ' . $e::class . ': ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine()
      );
      throw $e;
    }
  }

  /**
   * Reset context state for FrankenPHP worker mode.
   * This method clears request-specific state while preserving the context configuration.
   * Called automatically by FrankenPHP between requests when using worker mode.
   * @since      1.0.0
   */
  public function reset(): void
  {
    $logger = \Quiote\Logging\Log::for($this);

    // Reset singleton model instances
    $this->singletonModelInstances = [];
    $this->slotDispatcher = null; // rebuild per request

    // Log user state before reset
    if ($this->user) {
      $userClass = $this->user::class;
      if ($this->user instanceof \Quiote\User\ISecurityUser) {
        $isAuthenticated = $this->user->isAuthenticated() ? "YES" : "NO";
      } else {
        $isAuthenticated = "N/A";
      }
      if ($logger) {
        $logger->debug(
          "[Context.reset] user class=$userClass authenticated=$isAuthenticated",
        );
      }
    } else {
      if ($logger) {
        $logger->debug("[Context.reset] no user object");
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
    if ($logger && $logger->isEnabled(\Quiote\Logging\Level::Debug)) {
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
          if ($logger && $logger->isEnabled(\Quiote\Logging\Level::Debug)) {
            $logger->debug("[Context] calling storage reset()");
          }
          $component->reset();
        }
      } elseif ($component === $this->databaseManager && $component !== null) {
        // Recycle (ping + null dead connections) instead of full shutdown so the manager
        // stays alive across requests, avoiding re-initialization cost.
        if ($logger && $logger->isEnabled(\Quiote\Logging\Level::Debug)) {
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

    if ($logger && $logger->isEnabled(\Quiote\Logging\Level::Debug)) {
      $logger->debug("context.reset shutdown complete");
    }

    // Now reset object references for next request
    // In worker mode, null the storage so it gets recreated with fresh startup() call
    // This ensures session_start() is called properly on each request
    $this->storage = null;
    if ($logger && $logger->isEnabled(\Quiote\Logging\Level::Debug)) {
      $logger->debug("context.reset storage nulled");
    }

    // Reset user object (it will be recreated with clean session state)
    $this->user = null;
    if ($logger && $logger->isEnabled(\Quiote\Logging\Level::Debug)) {
      $logger->debug("[Context.reset] user cleared");
    }

    // Reset routing component instances
    foreach ($this->resetInstances as $instance) {
      if ($instance instanceof ResetInterface) {
        $instance->reset();
      }
    }
    if ($logger && $logger->isEnabled(\Quiote\Logging\Level::Debug)) {
      $logger->debug("[Context.reset] routing reset instances");
    }

    // CRITICAL: Reset routing object to prevent cache corruption in worker mode
    if ($this->routing && $this->routing instanceof ResetInterface) {
      $this->routing->reset();
      if ($logger) {
        $logger->debug("[Context.reset] routing object reset");
      }
    }

    // Reset request object (it will be recreated for the next request)
    $this->request = null;
    // Reset PSR middleware kernel for worker mode safety
    if ($logger && $logger->isEnabled(\Quiote\Logging\Level::Debug)) {
      $logger->debug("[Context.reset] request nulled");
    }

    // Drop request-scoped container entries in lockstep with the request/storage/user
    // nulling above (docs/DI_MIGRATION_PLAN.md, Phase 1) — otherwise the container would
    // keep serving a discarded per-request instance until the next lazy recreation re-registers it.
    $this->container?->reset();

    // Drop all ambient logging scopes so this request's rid/user/etc. cannot leak
    // into the next request's log lines in a long-lived worker (same cross-request
    // leak class as the session state cleared elsewhere in this reset).
    \Quiote\Logging\LogContext::clear();

    if ($logger && $logger->isEnabled(\Quiote\Logging\Level::Debug)) {
      $logger->debug("[Context.reset] completed");
    }
  }

  /**
	 * Reset context state for FrankenPHP worker mode.
	 * This method clears request-specific state while preserving the context configuration.
	// We intentionally DO NOT reset *FactoryInfo properties as these are immutable across
	// requests and used for lazy recreation (request/user/routing/storage/databaseManager).
	 * @since      1.0.0
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
      self::$psrKernel = new \Quiote\Middleware\MiddlewarePipeline($this);
    }
    // Adopt an inbound correlation ID from the configured header (e.g. an
    // upstream gateway / distributed-tracing correlation id) when present and
    // sane; otherwise generate a fresh one. The header name is configurable so
    // it can match e.g. Azure Application Gateway's own correlation header.
    $this->correlationId = \Quiote\Support\CorrelationId::fromRequest($request, $this->correlationIdHeaderName())
      ?? \Quiote\Support\CorrelationId::generate();

    // Start a fresh ambient logging scope for this request so every log line is
    // correlatable by rid. clear() first is defensive: it guards against a scope
    // left behind by a prior worker request whose reset() did not run. The
    // authoritative between-request clear lives in reset().
    \Quiote\Logging\LogContext::clear();
    \Quiote\Logging\LogContext::enrich(["rid" => $this->correlationId]);

    // Bridge: ensure a legacy WebRequest exists and attach the current PSR request for BC helpers
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
      // No need to attachPsrRequest - WebRequest IS the PSR-7 request
      // If needed, ensure context's request is same instance as pipeline request
    } catch (\Throwable) {
      // ignore
    }

    // Propagate correlation ID so middleware can use it without re-generating (avoids redundant random_bytes()).
    $request = $request->withAttribute("quiote.rid", $this->correlationId);
    $response = self::$psrKernel->handle($request);

    // Echo the correlation ID back so a caller/gateway can tie its request to
    // our logs/traces (unless disabled). Only add it if the response doesn't
    // already carry the header (e.g. an action set it explicitly).
    if (Config::get('core.correlation_id.expose', true)) {
      $header = $this->correlationIdHeaderName();
      if (!$response->hasHeader($header)) {
        $response = $response->withHeader($header, $this->correlationId);
      }
    }

    // Last hook that sees the full request + response together (see
    // docs/PLUGIN_AND_EXTENSIBILITY_PLAN.md). No-op with no listeners.
    \Quiote\Event\Events::emit(new \Quiote\Event\Lifecycle\ResponseSendingEvent($request, $response));

    return $response;
  }

  /** The configured inbound/outbound correlation-ID header name. */
  private function correlationIdHeaderName(): string
  {
    $name = Config::get('core.correlation_id.header', \Quiote\Support\CorrelationId::DEFAULT_HEADER);
    return is_string($name) && $name !== '' ? $name : \Quiote\Support\CorrelationId::DEFAULT_HEADER;
  }

  /**
   * Set the request object explicitly.
   * WebRequest extends ServerRequest, so this is the single source of truth.
   */
  public function setRequest($request): void
  {
    // Normalize any foreign PSR-7 request into an WebRequest so getRequest()
    // ALWAYS returns an WebRequest (with the Quiote helpers like isHttps()).
    // A plain Nyholm\Psr7\ServerRequest can otherwise flow in via middleware
    // (SlotMiddleware, ValidationMiddleware) or tests. Non-PSR requests (e.g. a
    // console request) and existing WebRequests pass through unchanged.
    if (
      $request !== null
      && !($request instanceof \Quiote\Request\WebRequest)
      && $request instanceof \Psr\Http\Message\ServerRequestInterface
    ) {
      $request = \Quiote\Request\WebRequest::fromPsr($request);
    }
    $this->request = $request;
    try {
      $message = sprintf(
        "[Context] setRequest id=%d cid=%s",
        spl_object_id($request),
        $this->correlationId,
      );
    } catch (\Throwable) {
      $message =
        "[Context] setRequest (no id) cid=" . $this->correlationId;
    }
    \Quiote\Logging\Log::for($this)->debug($message);
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
  public function getSlotDispatcher(): \Quiote\Execution\SlotDispatcher
  {
    if ($this->slotDispatcher === null) {
      // New signature: (controller, actionResolver?, executionGuard?, viewNameResolver?)
      $this->slotDispatcher = new \Quiote\Execution\SlotDispatcher(
        $this->getController(),
        $this->getActionResolver(),
      );
    }
    return $this->slotDispatcher;
  }

  public function getActionResolver(): \Quiote\Execution\ActionResolver
  {
    if ($this->actionResolver === null) {
      $this->actionResolver = new \Quiote\Execution\ActionResolver();
    }
    return $this->actionResolver;
  }

  /**
   * Retrieve the current PSR-7 ServerRequest.
   * Since WebRequest extends ServerRequest, this returns the same object as getRequest().
   * May return null for legacy/CLI execution paths.
   */
  public function getCurrentPsrRequest(): ?ServerRequestInterface
  {
    return $this->request;
  }


  /**
   * (re)Initialize the Context instance.
   * @since      1.0.0
   */
  public function initialize()
  {
    try {
      $logger = \Quiote\Logging\Log::for($this);
      if (
        defined("QUIOTE_USE_APCU_CONFIG_CACHE") &&
        QUIOTE_USE_APCU_CONFIG_CACHE
      ) {
        $logger?->debug(
          "Context using APCu config cache for factories.xml",
        );
        $cacheResult = APCuConfigCache::checkConfig(
          Config::get("core.config_dir") . "/factories.xml",
          $this->name,
        );

        if (str_starts_with($cacheResult, "APCU:")) {
          $logger?->debug(
            "Context executing factories.xml directly from APCu (no file I/O)",
          );
          eval("?>" . substr($cacheResult, 5));
        } else {
          include $cacheResult;
        }
      } else {
        $logger?->debug(
          "Context using regular config cache for factories.xml (constant defined: " .
            (defined("QUIOTE_USE_APCU_CONFIG_CACHE") ? "yes" : "no") .
            ", value: " .
            (defined("QUIOTE_USE_APCU_CONFIG_CACHE")
              ? (QUIOTE_USE_APCU_CONFIG_CACHE
                ? "true"
                : "false")
              : "undefined") .
            ")",
        );
        include ConfigCache::checkConfig(
          Config::get("core.config_dir") . "/factories.xml",
          $this->name,
        );
      }
    } catch (\Exception $e) {
      // Same reasoning as Context::getInstance(): this runs before any PSR-15
      // pipeline exists, so there is no ErrorHandlingMiddleware to hand off to
      // yet. Log and propagate instead of rendering a template and exit()ing.
      $logger?->error(
        'Context::initialize() failed for context "' . $this->name . '": ' . $e::class . ': ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine()
      );
      throw $e;
    }

    // Invariants: factory info for core components must be present now (set by generated factories cache)
    $invariantList = [
      "userFactoryInfo" => "user",
      "routingFactoryInfo" => "routing",
      "storageFactoryInfo" => "storage",
      "requestFactoryInfo" => "request",
    ];
    if (Config::get("core.use_database", false)) {
      $invariantList["databaseManagerFactoryInfo"] = "databaseManager";
    }
    foreach ($invariantList as $prop => $label) {
      if ($this->$prop === null) {
        $logger?->error(
          "Context invariant failed: missing $prop after initialize() (component '$label')",
        );
        throw new QuioteException(
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
        "Context registered shutdown function (not FrankenPHP)",
      );
    } else {
      $logger?->debug(
        "Context skipping shutdown function registration (FrankenPHP worker mode)",
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
            "Context.initialize pre-request: deferring user creation until first real request",
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

    // DI migration Phase 1 (docs/DI_MIGRATION_PLAN.md): register the core services
    // factories.xml just built (post user-deferral) into the container. Additive only —
    // nothing resolves services through the container yet.
    $this->registerCoreServicesInContainer();
  }

  /**
   * Initialize reset instances for FrankenPHP worker mode
   * These instances will be automatically reset by FrankenPHP between requests
   */
  protected function initializeResetInstances()
  {
    // Only the callback pool remains relevant; legacy route cache/trie removed.
    if (class_exists(\Quiote\Routing\RoutingCallbackPool::class)) {
      $this->resetInstances[] = \Quiote\Routing\RoutingCallbackPool::getResetInstance();
    }
  }

  /**
   * Shut down this Context and all related factories.
   * @since      1.0.0
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
        $logger = \Quiote\Logging\Log::for($this);
        if ($logger->isEnabled(\Quiote\Logging\Level::Debug)) {
          $logger->debug(
            "[Context] shutdown component error " .
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
   * @param      string A model name or fully qualified class name.
   * @param      string A module name, if the requested model is a module model,
   *                    or null for global models. (DEPRECATED with namespaces)
   * @param      array  An array of parameters to be passed to initialize() or
   *                    the constructor.
   * @return     Model A Model implementation instance.
   * @throws     AutloadException if class is ultimately not found.
   * @since      1.0.0
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
      $baseNamespace = Config::get("core.namespace_prefix", "App");
      $modelName = Toolkit::canonicalName($modelName);
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
        } catch (DisabledModuleException) {
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
            Config::get("core.model_dir") . "/" . $modelName . "Model.php";
        } else {
          $file =
            Config::get("core.module_dir") .
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
      throw new QuioteException(
        sprintf("Couldn't find class for Model %s", $origModelName),
      );
    }

    // so if we're here, we found something, right? good.

    $rc = new \ReflectionClass($class);

    if ($rc->implementsInterface(\Quiote\Model\ISingletonModel::class)) {
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
   * @return     string A context name.
   * @since      1.0.0
   */
  public function getName()
  {
    return $this->name;
  }

  /**
   * Retrieve the request.
   * @return     WebRequest The current Request implementation instance.
   * @since      1.0.0
   */
  public function getRequest()
  {
    // Lazy initialization for worker mode - recreate request object if null after reset
    if ($this->request === null) {
      $logger = \Quiote\Logging\Log::for($this);
      if ($logger->isEnabled(\Quiote\Logging\Level::Debug)) {
        $logger->debug(
          "[Context] getRequest() Request object is null, recreating...",
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

        // No need to attachPsrRequest - WebRequest IS the PSR-7 request

        // Re-run controller startup so it re-caches the (new) global request data pointer
        if ($this->controller && method_exists($this->controller, "startup")) {
          try {
            $this->controller->startup();
            if ($logger->isEnabled(\Quiote\Logging\Level::Debug)) {
              $logger->debug(
                "[Context] getRequest() Controller startup re-run after request recreation",
              );
            }
          } catch (\Throwable $e) {
            if ($logger->isEnabled(\Quiote\Logging\Level::Debug)) {
              $logger->debug(
                "[Context] getRequest() Controller startup failed after request recreation: " .
                  $e->getMessage(),
              );
            }
          }
        }
        if ($logger->isEnabled(\Quiote\Logging\Level::Debug)) {
          $logger->debug(
            "[Context] getRequest() Request object recreated successfully using factory info: " .
              $className,
          );
        }
        $this->registerCoreService('request', $this->request, Container::SCOPE_REQUEST);
      } else {
        if ($logger->isEnabled(\Quiote\Logging\Level::Debug)) {
          $logger->debug(
            "[Context] getRequest() No request factory info available, cannot recreate request",
          );
        }
        throw new QuioteException(
          "Request object is null and no factory info available for recreation in worker mode",
        );
      }
    }

    return $this->request;
  }

  /**
   * Retrieve the routing.
   * @return     Routing The current Routing implementation instance.
   * @since      1.0.0
   */
  public function getRouting()
  {
    // Lazy initialization for worker mode - recreate routing object if null after reset
    if ($this->routing === null) {
      $logger = \Quiote\Logging\Log::for($this);
      $logger?->debug(
        "Context::getRouting() - Routing object is null, recreating...",
      );
      // Recreate from factory info if available
      if ($this->routingFactoryInfo !== null) {
        $className = $this->routingFactoryInfo["class"];
        $this->routing = new $className();
        $logger?->debug(
          "Context::getRouting() - Routing (compat) object recreated via factory info: " .
            $className,
        );
        $this->registerCoreService('routing', $this->routing);
      } else {
        $logger?->error(
          "Context::getRouting() - No routing factory info available, cannot recreate routing",
        );
        throw new QuioteException(
          "Routing object is null and no factory info available for recreation in worker mode",
        );
      }
    }
    return $this->routing;
  }

  /**
   * Retrieve the storage.
   * @return     Storage The current Storage implementation instance.
   * @since      1.0.0
   */
  public function getStorage()
  {
    // Lazy initialization for worker mode - recreate storage object if null after reset
    if ($this->storage === null) {
      $logger = \Quiote\Logging\Log::for($this);
      $logger?->debug(
        "[Context.getStorage] - Storage object is null, recreating...",
      );
      // Ensure database manager is available if database use is enabled BEFORE creating storage (storage may need DB)
      if (
        Config::get("core.use_database", false) &&
        $this->databaseManager === null
      ) {
        $logger?->debug(
          "[Context.getStorage] - Database manager is null, attempting recreation...",
        );
        if ($this->databaseManagerFactoryInfo !== null) {
          $className = $this->databaseManagerFactoryInfo["class"];
          $parameters = $this->databaseManagerFactoryInfo["parameters"];
          try {
            $this->databaseManager = new $className();
            $this->databaseManager->initialize($this, $parameters);
            $this->databaseManager->startup();
            $logger?->debug(
              "[Context.getStorage] - Database manager recreated successfully using factory info: " .
                $className,
            );
            $this->registerCoreService('databaseManager', $this->databaseManager);
          } catch (\Throwable $e) {
            $logger?->error(
              "[Context.getStorage] - Failed to recreate database manager: " .
                $e->getMessage(),
            );
          }
        } else {
          $logger?->warning(
            "[Context.getStorage] - Database manager factory info missing, cannot recreate (may affect storage)",
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
          "[Context.getStorage] - Storage object recreated successfully using factory info: " .
            $className,
        );
        $this->registerCoreService('storage', $this->storage, Container::SCOPE_REQUEST);
      } else {
        $logger?->error(
          "[Context.getStorage] - No storage factory info available, cannot recreate storage",
        );
        throw new QuioteException(
          "Storage object is null and no factory info available for recreation in worker mode",
        );
      }
    }

    return $this->storage;
  }

  /**
   * Retrieve the translation manager.
   * @return     TranslationManager|null The current TranslationManager
   *                                          implementation instance or null if
   *                                          translations are disabled.
   * @since      1.0.0
   */
  public function getTranslationManager()
  {
    // Check if translations are enabled at runtime
    if (!Config::get("core.use_translation", false)) {
      return null;
    }
    return $this->translationManager;
  }

  /**
   * Retrieve the user.
   * @return     User|ISecurityUser The current User implementation instance.
   * @since      1.0.0
   */
  public function getUser()
  {
    // Lazy initialization for worker mode - recreate user object if null after reset
    if ($this->user === null) {
      // (Simplified) No serialized snapshot restore; always build fresh user below.
      $logger = \Quiote\Logging\Log::for($this);
      if ($logger->isEnabled(\Quiote\Logging\Level::Debug)) {
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
        "[Context.getUser] - User object is null, recreating...",
      );
      // Ensure database manager is available if database use is enabled BEFORE creating user (user may need storage->db)
      if (
        Config::get("core.use_database", false) &&
        $this->databaseManager === null
      ) {
        $logger?->debug(
          "[Context.getUser] - Database manager is null, attempting recreation before user...",
        );
        if ($this->databaseManagerFactoryInfo !== null) {
          $className = $this->databaseManagerFactoryInfo["class"];
          $parameters = $this->databaseManagerFactoryInfo["parameters"];
          try {
            $this->databaseManager = new $className();
            $this->databaseManager->initialize($this, $parameters);
            $this->databaseManager->startup();
            $logger?->debug(
              "[Context.getUser] - Database manager recreated successfully using factory info: " .
                $className,
            );
            $this->registerCoreService('databaseManager', $this->databaseManager);
          } catch (\Throwable $e) {
            $logger?->error(
              "[Context.getUser] - Failed to recreate database manager: " .
                $e->getMessage(),
            );
          }
        } else {
          $logger?->warning(
            "[Context.getUser] - Database manager factory info missing, cannot recreate",
          );
        }
      }

      // Ensure storage is available before creating user (user initialization needs storage)
      if ($this->storage === null) {
        $logger?->debug(
          "[Context.getUser] - Storage is null, recreating storage first...",
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
        if ($logger->isEnabled(\Quiote\Logging\Level::Debug)) {
          $logger->debug(
            "[Context.getUser] newUser=" .
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
              $component instanceof \Quiote\User\User ||
              $component instanceof \Quiote\User\ISecurityUser
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
          if ($logger->isEnabled(\Quiote\Logging\Level::Debug)) {
            $logger->debug(
              "[Context.getUser] registered user in shutdownSequence replaced=" .
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
          "[Context.getUser] - User object recreated successfully using factory info: " .
            $className,
        );
        $this->registerCoreService('user', $this->user, Container::SCOPE_REQUEST);
      } else {
        $logger?->error(
          "[Context.getUser] - No user factory info available, cannot recreate user",
        );
        throw new QuioteException(
          "User object is null and no factory info available for recreation in worker mode",
        );
      }
    }

    return $this->user;
  }
}
