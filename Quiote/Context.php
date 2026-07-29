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
use Quiote\Response\WebResponse;
use Quiote\Routing\Routing;
use Quiote\Translation\TranslationManager;
use Quiote\User\ISecurityUser;
use Quiote\User\User;
use Quiote\Util\Toolkit;
use Quiote\Validator\ValidationManager;
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
  /** @var bool */
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
   * @var        array<string, array<string, mixed>|null> An array of class names for frequently used factories.
   */
  protected $factories = [
    // Legacy filters removed; only remaining non-var factories listed here
    "response" => null,
    "validation_manager" => null,
  ];

  /**
   * @var        ?\Quiote\Database\DatabaseManager A DatabaseManager instance.
   */
  protected $databaseManager = null;

  /**
   * @var        ?WebRequest A Request instance.
   */
  protected $request = null;

  /**
   * @var        ?Routing A Routing instance.
   */
  protected $routing = null;

  /**
   * @var        ?\Quiote\Storage\Storage A Storage instance.
   */
  protected $storage = null;

  /**
   * @var        bool True once this request's user->storage flush has run.
   *             Reset per request in handle() and at the end of reset().
   *             See flushRequestState().
   */
  private bool $requestStateFlushed = false;

  /**
   * @var        ?\Quiote\Session\SessionBagInterface The request's session bag.
   *             Null until asked for, and nulled again by reset(): holding one
   *             across a worker request boundary would serve the previous
   *             request's session to the next one.
   */
  private ?\Quiote\Session\SessionBagInterface $sessionBag = null;

  /**
   * @var        bool Whether $sessionBag is the lazily built default wrapper
   *             rather than one installed by setSessionBag(). Only the default
   *             is re-derived when storage changes underneath it; an installed
   *             bag owns its own backend and is left alone.
   */
  private bool $sessionBagIsDefault = false;

  /**
   * @var        ?\Quiote\Session\SessionManager Built once per process from the
   *             `session` factory slot; null when that slot is unconfigured.
   *             Unlike storage this survives the request boundary -- it holds
   *             no per-request state, only cookie configuration.
   */
  private ?\Quiote\Session\SessionManager $sessionManager = null;

  /**
   * @var        ?TranslationManager A TranslationManager instance.
   */
  protected $translationManager = null;

  /**
   * @var        ?User A User instance.
   */
  protected $user = null;

  /**
   * @var        array<int, mixed> The array used for the shutdown sequence.
   */
  protected $shutdownSequence = [];

  /**
   * @var        array<string, self> An array of Context instances.
   */
  protected static $instances = [];

  /**
   * @var        array<class-string, object> An array of SingletonModel instances.
   */
  protected $singletonModelInstances = [];

  /**
   * Per-worker cache of getModel()'s class-name resolution + reflection
   * probe, keyed by "(moduleName ?? '')|(modelName)". The naming-convention
   * probing chain (class_exists() across namespace/legacy candidates, plus
   * manual file requires) and the ReflectionClass construction it feeds are
   * pure functions of (modelName, moduleName) once the class exists -- same
   * pattern as ActionDescriptor::$isSimpleCache and Container::$reflectionCache.
   * @var        array<string, array{class: class-string, singleton: bool, hasCtor: bool}>
   */
  private static array $modelResolutionCache = [];

  /**
   * @var        array<int, mixed> Reset instances for persistent worker runtimes
   */
  protected $resetInstances = [];

  /**
   * @var        ?array{class: class-string<WebRequest>, parameters: array<string, mixed>} Request factory info for worker mode recreation
   */
  protected $requestFactoryInfo = null;

  /**
   * @var        ?array{class: class-string<User>, parameters: array<string, mixed>} User factory info for worker mode recreation
   */
  protected $userFactoryInfo = null;
  /**
   * @var        ?array{class: class-string<Routing>, parameters: array<string, mixed>} Routing factory info for worker mode recreation
   */
  protected $routingFactoryInfo = null;

  /**
   * @var        ?array<string, mixed> Controller factory info for worker mode recreation (prevent dynamic property creation)
   */
  protected $controllerFactoryInfo = null;

  /**
   * @var        ?array<string, mixed> TranslationManager factory info for worker mode recreation (prevent dynamic property creation)
   */
  protected $translationManagerFactoryInfo = null;
  /**
   * @var        ?\Quiote\Middleware\MiddlewarePipeline Per-instance (not shared across
   *             named Context profiles -- see handle()); safe for worker reuse across requests
   *             within the lifetime of this specific Context instance.
   */
  protected $psrKernel = null;

  /** @var ?\Quiote\Execution\SlotDispatcher */
  protected $slotDispatcher = null;

  /** @var ?\Quiote\Execution\ActionResolver */
  protected $actionResolver = null;

  /** @var ?\Quiote\Asset\AssetRegistry */
  protected $assetRegistry = null;

  /**
   * @var        ?array{class: class-string<\Quiote\Storage\Storage>, parameters: array<string, mixed>} Storage factory info for worker mode recreation
   */
  protected $storageFactoryInfo = null;

  /**
   * @var        ?array{class: class-string<\Quiote\Database\DatabaseManager>, parameters: array<string, mixed>} Database manager factory info for worker mode recreation
   */
  protected $databaseManagerFactoryInfo = null;

  /**
   * @var        ?Container DI container.
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
   * @param      string $name The name of this context.
   * @since      1.0.0
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
   * @param      string $for The factory identifier.
   * @return     ?array<string, mixed> An associative array (keys 'class' and 'parameters'), or null if not found.
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
      $factoryInfo = [];
      foreach ($info["factory_info"] as $key => $value) {
        $factoryInfo[(string) $key] = $value;
      }
      return $factoryInfo;
    }
    // Fallback: normalize to expected shape.
    return [
      "class" => $info["class"] ?? null,
      "parameters" => $info["parameters"] ?? [],
    ];
  }

  /**
   * Set information on a frequently used class.
   * @param      string $for The factory identifier.
   * @param      array<string, mixed> $info An associative array (keys 'class' and 'parameters').
   * @return     void
   * @since      1.0.0
   */
  public function setFactoryInfo($for, array $info): void
  {
    $this->factories[$for] = $info;
  }

  /**
   * Factory for frequently used classes from factories.xml
   * @template T of string
   * @param      T $for The factory identifier.
   * @return     (T is 'validation_manager' ? ValidationManager : (T is 'translation_manager' ? TranslationManager : (T is 'controller' ? Controller : (T is 'response' ? WebResponse : object))))
   *             An instance, already initialized with parameters.
   * @throws     QuioteException If no such identifier exists.
   * @since      1.0.0
   */
  public function createInstanceFor($for)
  {
    $info = $this->getFactoryInfo($for);
    if (null === $info) {
      throw new QuioteException(sprintf('No factory info for "%s"', $for));
    }

    $class = new ($info["class"])();
    if (!is_callable([$class, 'initialize'])) {
      throw new QuioteException(sprintf('Factory class for "%s" has no initialize() method', $for));
    }
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
    if ($this->controller === null) {
      throw new QuioteException(
        'Controller is not available: Context::initialize() has not run (or the "controller" factory failed) for context "' . $this->name . '"',
      );
    }
    return $this->controller;
  }

  /**
   * Retrieve (lazily create) the DI container.
   * The core services created by factories.xml
   * are registered here under their role name and concrete class name, so both
   * `getContainer()->get('user')` and `getContainer()->get(RbacSecurityUser::class)`
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
   * The locator escape hatch for legacy call sites
   * and lazy/conditional access (the `IServiceProvider`-injection equivalent from .NET).
   * The preferred path for new code is constructor injection; both resolve through the
   * same container. Thin wrapper — exceptions from the container propagate as-is.
   * Deliberately does not touch getModel(): services and models remain separate
   * conventions.
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
    // Quiote\Service\Service base.
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
    // above win).
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
   * Register the DI-injectable OpenTelemetry provider aliases.
   * No-op unless telemetry is enabled
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
    if (!Config::getBool('telemetry.enabled', false) || !\Quiote\Telemetry\TraceRegistry::hasRealProvider()) {
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
   * @param      ?string $name A database name.
   * @return     mixed A database connection.
   * @throws     \Quiote\Exception\DatabaseException If the requested database name
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
   * @return     ?\Quiote\Database\DatabaseManager The current DatabaseManager instance
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
   * @param      string $profile A name corresponding to a section of the config
   * @return     Context An context instance initialized with the
   *                          settings of the requested context name
   * @since      1.0.0
   */
  public static function getInstance($profile = null)
  {
    try {
      if ($profile === null) {
        $profile = Config::getString("core.default_context");
      }
      $profile = strtolower($profile);
      if (!isset(self::$instances[$profile])) {
        $class = Config::getString("core.context_implementation", static::class);
        $instance = new $class($profile);
        if (!$instance instanceof self) {
          throw new QuioteException(sprintf('core.context_implementation "%s" does not extend Context', $class));
        }
        self::$instances[$profile] = $instance;
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
   * Reset context state between requests in a persistent worker.
   * This method clears request-specific state while preserving the context configuration.
   * Called from the worker request boundary; see WorkerManager::resetForNextRequest().
   * @since      1.0.0
   */
  /**
   * Persist request-scoped state that lives in the session, in the only order
   * that is correct: the user first (it writes into the session), storage
   * second (it closes the session).
   *
   * Idempotent per request -- the first caller wins. SessionMiddleware claims
   * it on the pipeline unwind, while the response has not been emitted and the
   * session can still be written; reset() and shutdown() call it as a backstop
   * for requests that never reached SessionMiddleware (CLI, a failure before
   * the bootstrap phase, a test driving Context directly).
   *
   * This ordering is the invariant the whole session-persistence path depends
   * on. It previously held only by accident of the generated shutdownSequence's
   * declaration order, and it silently broke whenever the session was closed on
   * the pipeline unwind while the user was still shut down afterwards, from
   * reset(), after the response had been emitted: the user's roles and
   * credentials were then written into a session nothing would ever persist.
   *
   * Deliberately reads $this->user rather than calling getUser(): creating a
   * user at unwind for a request that never asked for one would manufacture a
   * session row (and a Set-Cookie) for an anonymous visitor.
   *
   * @param      bool $persistUser False for a sessionless request
   *             (auth.sessionless / jwt.skip_session): there is no session to
   *             persist into, and writing a token-derived identity into
   *             whatever unrelated session cookie the client still carries
   *             would be wrong. The flush is still *claimed*, so the post-emit
   *             reset() does not attempt a late write.
   * @return     void
   * @since      2.1.0
   */
  public function flushRequestState(bool $persistUser = true): void
  {
    if ($this->requestStateFlushed) {
      return;
    }
    $this->requestStateFlushed = true;

    try {
      if ($persistUser && $this->user !== null) {
        $this->user->shutdown();
      }
    } catch (\Throwable $e) {
      $logger = \Quiote\Logging\Log::for($this);
      $logger->error(
        "[Context.flushRequestState] user shutdown failed: " . $e->getMessage(),
      );
    } finally {
      // The session is closed even when the user write threw, so a worker does
      // not carry an open session into the next request.
      try {
        $this->storage?->shutdown();
      } catch (\Throwable $e) {
        $logger = \Quiote\Logging\Log::for($this);
        $logger->error(
          "[Context.flushRequestState] storage shutdown failed: " . $e->getMessage(),
        );
      }
    }
  }

  public function reset(): void
  {
    $logger = \Quiote\Logging\Log::for($this);
    $vd = $logger->isEnabled(\Quiote\Logging\Level::Debug);

    // Reset singleton model instances
    $this->singletonModelInstances = [];
    $this->slotDispatcher = null; // rebuild per request
    $this->assetRegistry = null; // rebuild per request (worker-mode safe)

    // Log user state before reset
    if ($vd) {
      if ($this->user) {
        $userClass = $this->user::class;
        if ($this->user instanceof \Quiote\User\ISecurityUser) {
          $isAuthenticated = $this->user->isAuthenticated() ? "YES" : "NO";
        } else {
          $isAuthenticated = "N/A";
        }
        $logger->debug(
          "[Context.reset] user class=$userClass authenticated=$isAuthenticated",
        );
      } else {
        $logger->debug("[Context.reset] no user object");
      }
    }

    // Reset the controller state if it exists
    if ($this->controller) {
      $this->controller->reset();
      if ($vd) {
        $logger->debug("context.reset controller reset");
      }
    }

    // CRITICAL: Manually execute the shutdown sequence in correct order for worker mode
    // This ensures session data is saved properly before clearing state
    if ($vd) {
      $logger->debug("context.reset manual shutdown sequence");
    }

    // Persist user -> close session. Normally a no-op here, because
    // SessionMiddleware already claimed the flush on the pipeline unwind --
    // which is the whole point. By the time reset() runs the response has been
    // emitted, and a session write at that point silently goes nowhere. This
    // call is the backstop for requests that never reached the middleware.
    $this->flushRequestState();

    // Execute the shutdown sequence in the same order as would happen during normal shutdown
    // But skip components that don't need shutdown or would interfere with worker mode
    foreach ($this->shutdownSequence as $component) {
      if ($component === $this->user || $this->isStorageComponent($component)) {
        // Both belong to flushRequestState(), which owns the ordering between
        // them; shutting them down again here would double-write.
        continue;
      }
      if ($component === $this->databaseManager && $component !== null) {
        // Recycle (ping + null dead connections) instead of full shutdown so the manager
        // stays alive across requests, avoiding re-initialization cost.
        if ($vd) {
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

    if ($vd) {
      $logger->debug("context.reset shutdown complete");
    }

    // Clear the worker-carried session state. Driven off the property rather
    // than off a match in the loop above: identity matching there is exactly
    // what used to fail once getStorage() recreated the object without
    // splicing it back into the sequence, and the consequence -- session_id()
    // and $_SESSION surviving into the next request -- is a cross-user leak.
    // The storage factory slot carries no `must_implement` constraint, so a
    // configured storage need not be resettable; only ask the ones that are.
    if ($this->storage instanceof ResetInterface) {
      if ($vd) {
        $logger->debug("[Context] calling storage reset()");
      }
      $this->storage->reset();
    }

    // Now reset object references for next request
    // In worker mode, null the storage so it gets recreated with fresh startup() call
    // This ensures session_start() is called properly on each request
    $this->storage = null;

    // In lockstep with storage: a bag surviving the boundary would serve
    // request N's session to request N+1 -- the same cross-user leak the
    // storage reset above prevents, relocated one layer up.
    $this->sessionBag = null;
    $this->sessionBagIsDefault = false;
    if ($vd) {
      $logger->debug("context.reset storage nulled");
    }

    // Reset user object (it will be recreated with clean session state)
    $this->user = null;
    if ($vd) {
      $logger->debug("[Context.reset] user cleared");
    }

    // Reset routing component instances
    foreach ($this->resetInstances as $instance) {
      if ($instance instanceof ResetInterface) {
        $instance->reset();
      }
    }
    if ($vd) {
      $logger->debug("[Context.reset] routing reset instances");
    }

    // CRITICAL: Reset routing object to prevent cache corruption in worker mode
    if ($this->routing) {
      $this->routing->reset();
      if ($vd) {
        $logger->debug("[Context.reset] routing object reset");
      }
    }

    // CRITICAL: Reset translation manager to prevent locale bleed across requests in worker mode
    if ($this->translationManager) {
      $this->translationManager->reset();
      if ($vd) {
        $logger->debug("[Context.reset] translationManager object reset");
      }
    }

    // Reset request object (it will be recreated for the next request)
    $this->request = null;
    // Reset PSR middleware kernel for worker mode safety
    if ($vd) {
      $logger->debug("[Context.reset] request nulled");
    }

    // Drop request-scoped container entries in lockstep with the request/storage/user
    // nulling above — otherwise the container would
    // keep serving a discarded per-request instance until the next lazy recreation re-registers it.
    $this->container?->reset();

    // Drop all ambient logging scopes so this request's rid/user/etc. cannot leak
    // into the next request's log lines in a long-lived worker (same cross-request
    // leak class as the session state cleared elsewhere in this reset).
    \Quiote\Logging\LogContext::clear();

    // Re-arm the flush for the next request. Last, so that everything above
    // still sees this request's flush as already claimed.
    $this->requestStateFlushed = false;

    if ($vd) {
      $logger->debug("[Context.reset] completed");
    }
  }

  /**
	 * Reset context state between requests in a persistent worker.
	 * This method clears request-specific state while preserving the context configuration.
	// We intentionally DO NOT reset *FactoryInfo properties as these are immutable across
	// requests and used for lazy recreation (request/user/routing/storage/databaseManager).
	 * @param      ?string $profile The named context profile to reset, or null to reset all.
	 * @return     void
	 * @since      1.0.0
	 */
  public static function resetWorkerState($profile = null): void
  {
    if ($profile !== null) {
      $profile = strtolower($profile);
      if (isset(self::$instances[$profile])) {
        // Reset individual context state
        self::$instances[$profile]->reset();
      }
    } else {
      // Reset all contexts
      foreach (self::$instances as $context) {
        $context->reset();
      }
    }
  }

  public function handle(ServerRequestInterface $request): ResponseInterface
  {
    if ($this->psrKernel === null) {
      $this->psrKernel = new \Quiote\Middleware\MiddlewarePipeline($this);
    }
    $psrKernel = $this->psrKernel;
    // Adopt an inbound correlation ID from the configured header (e.g. an
    // upstream gateway / distributed-tracing correlation id) when present and
    // sane; otherwise generate a fresh one. The header name is configurable so
    // it can match e.g. Azure Application Gateway's own correlation header.
    $correlationId = \Quiote\Support\CorrelationId::fromRequest($request, $this->correlationIdHeaderName())
      ?? \Quiote\Support\CorrelationId::generate();
    $this->correlationId = $correlationId;

    // Start a fresh ambient logging scope for this request so every log line is
    // correlatable by rid. clear() first is defensive: it guards against a scope
    // left behind by a prior worker request whose reset() did not run. The
    // authoritative between-request clear lives in reset().
    \Quiote\Logging\LogContext::clear();
    \Quiote\Logging\LogContext::enrich(["rid" => $correlationId]);

    // Re-arm the per-request session flush. reset() does this too, but this is
    // the authoritative anchor: it also covers a runtime that serves requests
    // without calling reset() between them.
    $this->requestStateFlushed = false;

    // Bridge: ensure a legacy WebRequest exists and attach the current PSR request for BC helpers
    try {
      if (!$this->request) {
        // try to create immediately so later getRequest() in rendering doesn't need lazy recreation
        if ($this->requestFactoryInfo) {
          $className = $this->requestFactoryInfo["class"];
          $parameters = $this->requestFactoryInfo["parameters"];
          $newRequest = new $className();
          $newRequest->initialize($this, $parameters);
          $newRequest->startup();
          $this->request = $newRequest;
        }
      }
      // No need to attachPsrRequest - WebRequest IS the PSR-7 request
      // If needed, ensure context's request is same instance as pipeline request
    } catch (\Throwable) {
      // ignore
    }

    // Propagate correlation ID so middleware can use it without re-generating (avoids redundant random_bytes()).
    $request = $request->withAttribute("quiote.rid", $correlationId);
    $response = $psrKernel->handle($request);

    // Echo the correlation ID back so a caller/gateway can tie its request to
    // our logs/traces (unless disabled). Only add it if the response doesn't
    // already carry the header (e.g. an action set it explicitly).
    if (Config::getBool('core.correlation_id.expose', true)) {
      $header = $this->correlationIdHeaderName();
      if (!$response->hasHeader($header)) {
        $response = $response->withHeader($header, $correlationId);
      }
    }

    // Last hook that sees the full request + response together.
    // No-op with no listeners.
    \Quiote\Event\Events::emitLazy(\Quiote\Event\Lifecycle\ResponseSendingEvent::class, static fn() => new \Quiote\Event\Lifecycle\ResponseSendingEvent($request, $response));

    return $response;
  }

  /** The configured inbound/outbound correlation-ID header name. */
  private function correlationIdHeaderName(): string
  {
    $name = Config::getString('core.correlation_id.header', \Quiote\Support\CorrelationId::DEFAULT_HEADER);
    return $name !== '' ? $name : \Quiote\Support\CorrelationId::DEFAULT_HEADER;
  }

  /**
   * Set the request object explicitly.
   * WebRequest extends ServerRequest, so this is the single source of truth.
   * @param      mixed $request The request object (a WebRequest, a PSR-7
   *             ServerRequestInterface, or null). Anything else is ignored --
   *             $this->request only ever holds a WebRequest or null.
   */
  public function setRequest($request): void
  {
    // Normalize any foreign PSR-7 request into an WebRequest so getRequest()
    // ALWAYS returns an WebRequest (with the Quiote helpers like isHttps()).
    // A plain Nyholm\Psr7\ServerRequest can otherwise flow in via middleware
    // (SlotMiddleware, ValidationMiddleware) or tests. An existing WebRequest
    // passes through unchanged.
    if (
      $request !== null
      && !($request instanceof \Quiote\Request\WebRequest)
      && $request instanceof \Psr\Http\Message\ServerRequestInterface
    ) {
      $request = \Quiote\Request\WebRequest::fromPsr($request);
    }
    if ($request === null || $request instanceof \Quiote\Request\WebRequest) {
      $this->request = $request;
    }
    // setRequest() runs several times per request; only build the diagnostic
    // string (sprintf + spl_object_id) when debug logging is actually enabled.
    $logger = \Quiote\Logging\Log::for($this);
    if ($logger->isEnabled(\Quiote\Logging\Level::Debug)) {
      if (is_object($request)) {
        $message = sprintf(
          "[Context] setRequest id=%d cid=%s",
          spl_object_id($request),
          $this->correlationId,
        );
      } else {
        $message =
          "[Context] setRequest (no id) cid=" . $this->correlationId;
      }
      $logger->debug($message);
    }
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

  /**
   * Retrieve (lazily create) the AssetRegistry shared by the whole render
   * tree for this request (the top-level View and every nested slot View).
   */
  public function getAssetRegistry(): \Quiote\Asset\AssetRegistry
  {
    return $this->assetRegistry ??= new \Quiote\Asset\AssetRegistry();
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
   * @return     void
   * @since      1.0.0
   */
  public function initialize(): void
  {
    $logger = null;
    try {
      $logger = \Quiote\Logging\Log::for($this);
      if (
        defined("QUIOTE_USE_APCU_CONFIG_CACHE") &&
        QUIOTE_USE_APCU_CONFIG_CACHE
      ) {
        $logger->debug(
          "Context using APCu config cache for factories.xml",
        );
        $cacheResult = APCuConfigCache::checkConfig(
          Config::getString("core.config_dir") . "/factories.xml",
          $this->name,
        );

        if (str_starts_with($cacheResult, "APCU:")) {
          $logger->debug(
            "Context executing factories.xml directly from APCu (no file I/O)",
          );
          eval("?>" . substr($cacheResult, 5));
        } else {
          include $cacheResult;
        }
      } else {
        $logger->debug(
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
          Config::getString("core.config_dir") . "/factories.xml",
          $this->name,
        );
      }
    } catch (\Exception $e) {
      // Same reasoning as Context::getInstance(): this runs before any PSR-15
      // pipeline exists, so there is no ErrorHandlingMiddleware to hand off to
      // yet. Log and propagate instead of rendering a template and exit()ing.
      $logger->error(
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
    if (Config::getBool("core.use_database", false)) {
      $invariantList["databaseManagerFactoryInfo"] = "databaseManager";
    }
    foreach ($invariantList as $prop => $label) {
      if ($this->$prop === null) {
        $logger->error(
          "Context invariant failed: missing $prop after initialize() (component '$label')",
        );
        throw new QuioteException(
          "Context initialization failed: missing factory metadata for '$label'",
        );
      }
    }

    // Register reset instances for persistent worker runtimes
    $this->initializeResetInstances();

    // Under a persistent worker runtime we handle shutdown manually in reset()
    // to avoid double shutdown calls that could clear session data. Asked of
    // WorkerRuntimeInfo rather than sniffed from the environment, so this holds
    // for every worker host, not just FrankenPHP.
    $isPersistentWorker = \Quiote\Runtime\Worker\WorkerRuntimeInfo::isPersistent();

    if (!$isPersistentWorker) {
      register_shutdown_function([$this, "shutdown"]);
      $logger->debug(
        "Context registered shutdown function (single-request runtime)",
      );
    } else {
      $logger->debug(
        "Context skipping shutdown function registration (persistent worker runtime)",
      );
    }

    // Worker-mode bootstrap happens before the first real HTTP request, but
    // factories.xml has already eagerly created storage + user and invoked
    // storage->startup() and user->initialize(). No session cookie is visible
    // yet, so storage->startup() defers (sid=null) and user->initialize() reads
    // null auth => authenticated=false gets latched. Later, when the first real
    // request arrives, code that consults isAuthenticated() or user attributes
    // may observe that false before any lazy promotion can occur (e.g. redirect
    // logic), effectively logging a previously authenticated user out after a
    // container restart.
    //
    // Mitigation: while still in pre-request bootstrap, discard the eagerly
    // created user so the first getUser() after the real request starts
    // recreates it *after* storage has had a chance to see the incoming cookie.
    //
    // "Still in bootstrap" is read as "the Kernel has not installed a runtime
    // yet", which it does immediately after Quiote::bootstrap() returns and
    // before any request. The previous test for this was
    // !isset($_SERVER['REQUEST_METHOD']), which cannot work on a runtime that
    // doesn't populate superglobals until mid-request (RoadRunner, Swoole):
    // there the key is absent during *every* Context::initialize(), so the
    // deferral would fire on requests it was never meant to touch.
    if (
      $isPersistentWorker &&
      !\Quiote\Runtime\Worker\WorkerRuntimeInfo::isInstalled() &&
      $this->user !== null
    ) {
      try {
        $logger->debug(
          "Context.initialize pre-request: deferring user creation until first real request",
        );
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

    // Register the core services
    // factories.xml just built (post user-deferral) into the container. Additive only —
    // nothing resolves services through the container yet.
    $this->registerCoreServicesInContainer();
  }

  /**
   * Initialize reset instances for persistent worker runtimes
   * These instances are automatically reset between requests
   * @return     void
   */
  protected function initializeResetInstances(): void
  {
    // Only the callback pool remains relevant; legacy route cache/trie removed.
    if (class_exists(\Quiote\Routing\RoutingCallbackPool::class)) {
      $this->resetInstances[] = \Quiote\Routing\RoutingCallbackPool::getResetInstance();
    }
  }

  /**
   * Shut down this Context and all related factories.
   * @return     void
   * @since      1.0.0
   */
  public function shutdown(): void
  {
    // User and storage first, in that order, through the single owner of that
    // ordering. Without this the non-worker (register_shutdown_function) path
    // would rely on the generated sequence's declaration order, and would also
    // double-write whatever SessionMiddleware already flushed on the unwind.
    $this->flushRequestState();

    foreach ($this->shutdownSequence as $object) {
      if ($object === $this->user || $this->isStorageComponent($object)) {
        continue;
      }
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
              get_debug_type($object) .
              " msg=" .
              $e->getMessage(),
          );
        }
      }
    }
  }

  /**
   * Retrieve a Model implementation instance.
   * @param      string $modelName A model name or fully qualified class name.
   * @param      string $moduleName A module name, if the requested model is a module model,
   *                    or null for global models. (DEPRECATED with namespaces)
   * @param      ?array<int, mixed> $parameters An array of parameters to be passed to initialize() or
   *                    the constructor.
   * @return     \Quiote\Model\Model A Model implementation instance.
   * @throws     QuioteException if class is ultimately not found.
   * @since      1.0.0
   */
  public function getModel(
    $modelName,
    $moduleName = null,
    ?array $parameters = null,
  ) {
    $origModelName = $modelName;

    // Module bootstrapping (autoload/config + the disabled-module check) has
    // real per-request side effects and must run every call regardless of
    // whether the class resolution below is cache-hit -- initializeModule()
    // already has its own fast path for the already-initialized case.
    if ($moduleName !== null) {
      try {
        $this->getController()->initializeModule($moduleName);
      } catch (DisabledModuleException) {
        // swallow, this will load the modules autoload but throw an exception
        // if the module is disabled.
      }
    }

    $cacheKey = ($moduleName ?? "") . "|" . $modelName;
    $resolved = self::$modelResolutionCache[$cacheKey] ?? null;

    if ($resolved === null) {
      $class = null;
      $file = null;

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
        $baseNamespace = Config::getString("core.namespace_prefix", "App");
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
              Config::getString("core.model_dir") . "/" . $modelName . "Model.php";
          } else {
            $file =
              Config::getString("core.module_dir") .
              "/" .
              $moduleName .
              "/Models/" .
              $modelName .
              "Model.php";
          }

          if (is_readable($file)) {
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
      $resolved = [
        "class" => $class,
        "singleton" => $rc->implementsInterface(\Quiote\Model\ISingletonModel::class),
        "hasCtor" => $rc->getConstructor() !== null,
      ];
      self::$modelResolutionCache[$cacheKey] = $resolved;
    }

    $class = $resolved["class"];
    $hasCtor = $resolved["hasCtor"];

    if ($resolved["singleton"]) {
      // it's a singleton
      if (!isset($this->singletonModelInstances[$class])) {
        // no instance yet, so we create one

        if ($parameters === null || !$hasCtor) {
          // it has an initialize() method, or no parameters were given, so we don't hand arguments to the constructor
          $this->singletonModelInstances[$class] = new $class();
        } else {
          // we use this approach so we can pass constructor params or if it doesn't have an initialize() method
          $this->singletonModelInstances[$class] = new $class(...$parameters);
        }
      }
      $model = $this->singletonModelInstances[$class];
    } else {
      // create an instance
      if ($parameters === null || !$hasCtor) {
        // it has an initialize() method, or no parameters were given, so we don't hand arguments to the constructor
        $model = new $class();
      } else {
        // we use this approach so we can pass constructor params or if it doesn't have an initialize() method
        $model = new $class(...$parameters);
      }
    }

    if (is_callable([$model, "initialize"])) {
      // pass the constructor params again. dual use for the win
      $model->initialize($this, (array) $parameters);
    }

    if (!$model instanceof \Quiote\Model\Model) {
      throw new QuioteException(
        sprintf("Resolved class for Model %s does not extend Quiote\\Model\\Model", $origModelName),
      );
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

        $newRequest = new $className();
        // IMPORTANT: Must call initialize() BEFORE startup() to populate request data from superglobals
        // initialize() reads from $_GET, $_POST, etc. and populates the request data holder
        // startup() clears the superglobals (when unset_input parameter is true)
        $newRequest->initialize($this, $parameters);
        $newRequest->startup();
        $this->request = $newRequest;

        // No need to attachPsrRequest - WebRequest IS the PSR-7 request

        // Re-run controller startup so it re-caches the (new) global request data pointer
        $controller = $this->controller;
        if ($controller !== null) {
          try {
            $controller->startup();
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

    if ($this->request === null) {
      throw new QuioteException(
        "Request object is unexpectedly null after recreation",
      );
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
      $vd = $logger->isEnabled(\Quiote\Logging\Level::Debug);
      if ($vd) {
        $logger->debug(
          "Context::getRouting() - Routing object is null, recreating...",
        );
      }
      // Recreate from factory info if available
      if ($this->routingFactoryInfo !== null) {
        $className = $this->routingFactoryInfo["class"];
        $this->routing = new $className();
        if ($vd) {
          $logger->debug(
            "Context::getRouting() - Routing (compat) object recreated via factory info: " .
              $className,
          );
        }
        $this->registerCoreService('routing', $this->routing);
      } else {
        $logger->error(
          "Context::getRouting() - No routing factory info available, cannot recreate routing",
        );
        throw new QuioteException(
          "Routing object is null and no factory info available for recreation in worker mode",
        );
      }
    }
    if ($this->routing === null) {
      throw new QuioteException(
        "Routing object is unexpectedly null after recreation",
      );
    }
    return $this->routing;
  }

  /**
   * Retrieve the storage.
   * @return     \Quiote\Storage\Storage The current Storage implementation instance.
   * @since      1.0.0
   */
  /**
   * Retrieve this request's session bag -- the single seam every consumer of
   * session state goes through.
   *
   * With nothing configured this lazily wraps the `storage` factory slot in a
   * {@see \Quiote\Session\StorageSessionBag}, so behaviour is identical to
   * reaching for getStorage() directly. Middleware for a different backend
   * installs its own bag with setSessionBag() on the way in.
   *
   * Pulled from the context rather than pushed into consumers, because
   * getUser() can recreate a user *after* that middleware has run: a user built
   * mid-request must still reach the same session as everything else.
   *
   * @return     \Quiote\Session\SessionBagInterface
   * @since      2.1.0
   */
  public function getSessionBag(): \Quiote\Session\SessionBagInterface
  {
    $bag = $this->sessionBag;

    // The default wrapper holds a reference to the storage object it wrapped.
    // If storage has since been replaced -- worker recreation, or a direct
    // assignment -- that bag points at a discarded instance, and reads and
    // writes would silently go to the wrong session. Re-derive it.
    if (
      $this->sessionBagIsDefault
      && $bag instanceof \Quiote\Session\StorageSessionBag
      && $bag->getStorage() !== $this->storage
    ) {
      $bag = null;
    }

    if ($bag === null) {
      $bag = new \Quiote\Session\StorageSessionBag($this->getStorage());
      $this->sessionBag = $bag;
      $this->sessionBagIsDefault = true;
      $this->registerCoreService('sessionBag', $bag, Container::SCOPE_REQUEST);
    }

    return $bag;
  }

  /**
   * The configured {@see \Quiote\Session\SessionManager}, or null when the
   * `session` factory slot is not configured (i.e. `core.use_modern_session`
   * is off and the application is on the legacy `storage` path).
   *
   * Built once per process from the slot's SessionFactoryInterface: the
   * manager itself is stateless apart from cookie configuration, so unlike
   * storage it does not need recreating per request.
   *
   * @return     ?\Quiote\Session\SessionManager
   * @since      2.2.0
   */
  public function getSessionManager(): ?\Quiote\Session\SessionManager
  {
    if ($this->sessionManager !== null) {
      return $this->sessionManager;
    }

    $info = $this->factories['session']['factory_info'] ?? $this->factories['session'] ?? null;
    if (!is_array($info)) {
      return null;
    }

    $className = $info['class'] ?? null;
    $parameters = $info['parameters'] ?? [];
    if (!is_string($className) || !class_exists($className) || !is_array($parameters)) {
      return null;
    }

    try {
      $factory = new $className();
      if (!$factory instanceof \Quiote\Session\SessionFactoryInterface) {
        return null;
      }
      /** @var array<string, mixed> $parameters */
      $this->sessionManager = new \Quiote\Session\SessionManager(
        $factory->createPersistence($this, $parameters),
        $parameters,
      );
    } catch (\Throwable $e) {
      \Quiote\Logging\Log::for($this)->error(
        '[Context.getSessionManager] could not build the session backend: ' . $e->getMessage(),
      );

      return null;
    }

    return $this->sessionManager;
  }

  /**
   * Install the session bag for this request, replacing the lazy default.
   *
   * Pass null to drop it, which forces the next getSessionBag() to rebuild --
   * necessary whenever the underlying storage is replaced, or a stale bag would
   * keep pointing at the previous instance.
   *
   * @param      ?\Quiote\Session\SessionBagInterface $bag
   * @return     void
   * @since      2.1.0
   */
  public function setSessionBag(?\Quiote\Session\SessionBagInterface $bag): void
  {
    $this->sessionBag = $bag;
    $this->sessionBagIsDefault = false;
    if ($bag !== null) {
      $this->registerCoreService('sessionBag', $bag, Container::SCOPE_REQUEST);
    }
  }

  /**
   * Whether a shutdown-sequence entry is this context's storage component.
   *
   * Matched by base class *or* by the configured factory class name: the test
   * double in tests/lib/MockStorage.php is duck-typed and deliberately does not
   * extend Storage, yet it is what storageFactoryInfo names.
   */
  private function isStorageComponent(mixed $component): bool
  {
    if ($component instanceof \Quiote\Storage\Storage) {
      return true;
    }

    $configured = $this->storageFactoryInfo['class'] ?? null;

    return is_object($component)
      && is_string($configured)
      && $component::class === $configured;
  }

  /**
   * Replace every stale instance of one component role in $shutdownSequence
   * with $replacement, preserving that role's original position.
   *
   * Used by the lazy getUser()/getStorage() recreation paths in worker mode:
   * reset() nulls the property but leaves the dead object in the sequence, and
   * a component that is never spliced back in silently stops being shut down
   * from the second request onward. Position is preserved rather than
   * unshifting to index 0, which would move the component ahead of
   * controller/routing and skip late mutations those may perform.
   *
   * @param object $replacement The freshly created component.
   * @param callable(mixed):bool $matches Identifies instances of the role.
   * @param callable():int $fallbackIndex Insertion point when the sequence
   *        contained no instance of the role at all.
   * @param string $caller Label for debug logging.
   */
  private function spliceIntoShutdownSequence(
    object $replacement,
    callable $matches,
    callable $fallbackIndex,
    string $caller,
  ): void {
    try {
      $firstIndex = null;
      $removedAny = false;
      foreach ($this->shutdownSequence as $idx => $component) {
        if ($matches($component)) {
          if ($firstIndex === null) {
            $firstIndex = $idx;
          }
          unset($this->shutdownSequence[$idx]);
          $removedAny = true;
        }
      }
      $this->shutdownSequence = array_values($this->shutdownSequence);

      if ($firstIndex === null) {
        $firstIndex = max(0, $fallbackIndex());
      }
      $firstIndex = min($firstIndex, count($this->shutdownSequence));

      array_splice($this->shutdownSequence, $firstIndex, 0, [$replacement]);

      $logger = \Quiote\Logging\Log::for($this);
      if ($logger->isEnabled(\Quiote\Logging\Level::Debug)) {
        $logger->debug(sprintf(
          '[Context.%s] registered component in shutdownSequence replaced=%d idx=%d oid=%d',
          $caller,
          $removedAny ? 1 : 0,
          $firstIndex,
          spl_object_id($replacement),
        ));
      }
    } catch (\Throwable) {
      // Soft failure: reset()/shutdown() drive storage and user directly, so a
      // failed splice degrades ordering for other components only.
    }
  }

  /**
   * Retrieve this context's storage component, recreating it from the captured
   * factory info if a worker-mode reset() nulled it.
   *
   * Annotated rather than natively typed: the storage slot's factory config
   * carries no `must_implement` constraint, so an app (or a test double) may
   * supply a duck-typed object that does not extend Storage, and a native
   * return type would turn that into a TypeError.
   *
   * @return     \Quiote\Storage\Storage The Storage instance.
   * @throws     QuioteException If storage is null and cannot be recreated.
   */
  public function getStorage()
  {
    // Lazy initialization for worker mode - recreate storage object if null after reset
    if ($this->storage === null) {
      $logger = \Quiote\Logging\Log::for($this);
      $vd = $logger->isEnabled(\Quiote\Logging\Level::Debug);
      if ($vd) {
        $logger->debug(
          "[Context.getStorage] - Storage object is null, recreating...",
        );
      }
      // Ensure database manager is available if database use is enabled BEFORE creating storage (storage may need DB)
      if (
        Config::getBool("core.use_database", false) &&
        $this->databaseManager === null
      ) {
        if ($vd) {
          $logger->debug(
            "[Context.getStorage] - Database manager is null, attempting recreation...",
          );
        }
        if ($this->databaseManagerFactoryInfo !== null) {
          $className = $this->databaseManagerFactoryInfo["class"];
          $parameters = $this->databaseManagerFactoryInfo["parameters"];
          try {
            $newDatabaseManager = new $className();
            $newDatabaseManager->initialize($this, $parameters);
            $newDatabaseManager->startup();
            $this->databaseManager = $newDatabaseManager;
            if ($vd) {
              $logger->debug(
                "[Context.getStorage] - Database manager recreated successfully using factory info: " .
                  $className,
              );
            }
            $this->registerCoreService('databaseManager', $this->databaseManager);
          } catch (\Throwable $e) {
            $logger->error(
              "[Context.getStorage] - Failed to recreate database manager: " .
                $e->getMessage(),
            );
          }
        } else {
          $logger->warning(
            "[Context.getStorage] - Database manager factory info missing, cannot recreate (may affect storage)",
          );
        }
      }

      if ($this->storageFactoryInfo !== null) {
        // Recreate the storage object using captured factory info
        $className = $this->storageFactoryInfo["class"];
        $parameters = $this->storageFactoryInfo["parameters"];

        $newStorage = new $className();
        $newStorage->initialize($this, $parameters);
        // Do NOT call startup() here - SessionMiddleware will call it after mirroring PSR-7 cookies to $_COOKIE
        // Calling it here causes session loss because $_COOKIE is empty before SessionMiddleware runs
        // Any lazily built bag still wraps the object being replaced here;
        // getSessionBag() notices the mismatch and rebuilds on next use.
        $this->storage = $newStorage;

        if ($vd) {
          $logger->debug(
            "[Context.getStorage] - Storage object recreated successfully using factory info: " .
              $className,
          );
        }
        $this->registerCoreService('storage', $this->storage, Container::SCOPE_REQUEST);

        // reset() left the previous request's (now dead) storage object in the
        // shutdown sequence and nulled the property. Without splicing the
        // replacement back in, the identity test in reset() stops matching from
        // the second request onward, so storage->reset() never runs again --
        // session_id() and $_SESSION survive into the next request and
        // startup() then skips session_start() on a non-empty id, inheriting
        // the previous request's session.
        $this->spliceIntoShutdownSequence(
          $newStorage,
          fn(mixed $component): bool => $this->isStorageComponent($component),
          // No storage in the sequence: append, so the session is closed after
          // every other component (notably the user) has written to it.
          fn(): int => count($this->shutdownSequence),
          'getStorage',
        );
      } else {
        $logger->error(
          "[Context.getStorage] - No storage factory info available, cannot recreate storage",
        );
        throw new QuioteException(
          "Storage object is null and no factory info available for recreation in worker mode",
        );
      }
    }

    if ($this->storage === null) {
      throw new QuioteException(
        "Storage object is unexpectedly null after recreation",
      );
    }
    return $this->storage;
  }

  /**
   * Retrieve the translation manager.
   * @return     ?TranslationManager The current TranslationManager
   *                                          implementation instance or null if
   *                                          translations are disabled.
   * @since      1.0.0
   */
  public function getTranslationManager()
  {
    // Check if translations are enabled at runtime
    if (!Config::getBool("core.use_translation", false)) {
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
      $vd = $logger->isEnabled(\Quiote\Logging\Level::Debug);
      if ($vd) {
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
                $f["function"]);
          }
          $logger->debug(
            "[getUser] user null, recreating trace=" . json_encode($bt),
          );
        } catch (\Throwable) {
        }
        $logger->debug(
          "[Context.getUser] - User object is null, recreating...",
        );
      }
      // Ensure database manager is available if database use is enabled BEFORE creating user (user may need storage->db)
      if (
        Config::getBool("core.use_database", false) &&
        $this->databaseManager === null
      ) {
        if ($vd) {
          $logger->debug(
            "[Context.getUser] - Database manager is null, attempting recreation before user...",
          );
        }
        if ($this->databaseManagerFactoryInfo !== null) {
          $className = $this->databaseManagerFactoryInfo["class"];
          $parameters = $this->databaseManagerFactoryInfo["parameters"];
          try {
            $newDatabaseManager = new $className();
            $newDatabaseManager->initialize($this, $parameters);
            $newDatabaseManager->startup();
            $this->databaseManager = $newDatabaseManager;
            if ($vd) {
              $logger->debug(
                "[Context.getUser] - Database manager recreated successfully using factory info: " .
                  $className,
              );
            }
            $this->registerCoreService('databaseManager', $this->databaseManager);
          } catch (\Throwable $e) {
            $logger->error(
              "[Context.getUser] - Failed to recreate database manager: " .
                $e->getMessage(),
            );
          }
        } else {
          $logger->warning(
            "[Context.getUser] - Database manager factory info missing, cannot recreate",
          );
        }
      }

      // Ensure storage is available before creating user (user initialization needs storage)
      if ($this->storage === null) {
        if ($vd) {
          $logger->debug(
            "[Context.getUser] - Storage is null, recreating storage first...",
          );
        }
        $this->getStorage(); // This will recreate storage if needed
      }

      if ($this->userFactoryInfo !== null) {
        // Recreate the user object using captured factory info
        $className = $this->userFactoryInfo["class"];
        $parameters = $this->userFactoryInfo["parameters"];

        $newUser = new $className();
        $newUser->initialize($this, $parameters);
        $newUser->startup();
        $this->user = $newUser;
        if ($vd) {
          $logger->debug(
            "[Context.getUser] newUser=" .
              $newUser::class .
              " oid=" .
              spl_object_id($newUser),
          );
        }

        // Replace any stale user instances in shutdown sequence to avoid persisting outdated auth state later
        $this->spliceIntoShutdownSequence(
          $newUser,
          static fn(mixed $component): bool =>
            $component instanceof \Quiote\User\User ||
            $component instanceof \Quiote\User\ISecurityUser,
          // No user in the sequence: insert just before storage, so the user's
          // writes land before the session is closed.
          fn(): int => array_find_key(
            $this->shutdownSequence,
            fn(mixed $component): bool => $this->isStorageComponent($component),
          ) ?? 0,
          'getUser',
        );

        if ($vd) {
          $logger->debug(
            "[Context.getUser] - User object recreated successfully using factory info: " .
              $className,
          );
        }
        $this->registerCoreService('user', $this->user, Container::SCOPE_REQUEST);
      } else {
        $logger->error(
          "[Context.getUser] - No user factory info available, cannot recreate user",
        );
        throw new QuioteException(
          "User object is null and no factory info available for recreation in worker mode",
        );
      }
    }

    if ($this->user === null) {
      throw new QuioteException(
        "User object is unexpectedly null after recreation",
      );
    }
    return $this->user;
  }
}
