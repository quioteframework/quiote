<?php
namespace Quiote;

use Quiote\Config\Config;
use Quiote\Config\CompiledConfig;
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
 *
 * A subclass named by `core.context_implementation` must keep the constructor signature: the
 * registry builds it knowing only the profile name.
 *
 * @phpstan-consistent-constructor
 * @since      1.0.0
 * @version    1.0.0
 */
class Context implements \Stringable, ResetInterface, ContextInterface
{
  // Debug: Log when this class version is loaded
  /** @var bool */
  static $debugLoaded = true;

  /**
   * @var        ?\Quiote\Runtime\ContextRequestHandler Serves requests against this context.
   *             Built on first use; see getRequestHandler().
   */
  private ?\Quiote\Runtime\ContextRequestHandler $requestHandler = null;

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
   * @var        ?\Quiote\Session\SessionBagInterface The request's session bag.
   *             Null until asked for, and nulled again by reset(): holding one
   *             across a worker request boundary would serve the previous
   *             request's session to the next one.
   */
  private ?\Quiote\Session\SessionBagInterface $sessionBag = null;


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
   * @var        ShutdownSequence The components to shut down, in order.
   */
  protected ShutdownSequence $shutdownSequence;

  /**
   * @var        ?\Quiote\Config\Factory\FactoryDefinitions What the compiled factories
   *             configuration declared. The source the lazy worker-mode rebuilds read from.
   */
  protected ?\Quiote\Config\Factory\FactoryDefinitions $factoryDefinitions = null;

  /**
   * @var        ContextLifecycle This context's per-request state machine: the flush claim and the
   *             clears that run when a request ends. Populated by registerLifecycleClears().
   */
  protected ContextLifecycle $lifecycle;

  /**
   * @var        ?\Quiote\Model\ModelLocator Resolves and hands out this context's models.
   *             Built on first use; see getModelLocator().
   */
  private ?\Quiote\Model\ModelLocator $modelLocator = null;

  /**
   * @var        array<int, mixed> Reset instances for persistent worker runtimes
   */
  protected $resetInstances = [];






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
  ) {
    $this->shutdownSequence = new ShutdownSequence();
    $this->lifecycle = new ContextLifecycle();
  }

  /**
   * Build an uninitialized context for a profile.
   *
   * The named constructor {@see ContextRegistry} uses. The registry is what guarantees one
   * context per profile, so it needs a way in that the constructor's protected visibility does
   * not give it -- but going through here rather than opening the constructor keeps `new
   * Context()` from being written casually, since an unregistered context is not the one anything
   * else in the process will find.
   *
   * initialize() is deliberately not called: the registry has to record the instance before
   * initialization runs, so a context reaching back for its own profile mid-initialize finds
   * itself instead of recursing into a second one.
   *
   * @since      4.0.0
   */
  public static function create(string $profile): static
  {
    return new static($profile);
  }

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
   * The service-locator path, for call sites that cannot declare a dependency and for
   * lazy/conditional access (the `IServiceProvider`-injection equivalent from .NET).
   * The preferred path for new code is constructor injection; both resolve through the
   * same container. Thin wrapper — exceptions from the container propagate as-is.
   * Deliberately separate from {@see \Quiote\Model\ModelLocator}: services and models remain
   * separate conventions.
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

    // Also bind the seam contracts a core service satisfies, so a consumer can depend on the
    // contract rather than the concrete class. Registered per instance rather than from a fixed
    // map, so a replaced implementation is what the contract resolves to.
    foreach (self::SEAM_CONTRACTS as $contract) {
      if ($instance instanceof $contract) {
        $container->set($contract, $instance, $scope);
      }
    }
  }

  /**
   * Contracts a core service may satisfy, bound alongside it in the container by
   * {@see registerCoreService()}.
   *
   * Base classes as well as interfaces, and the base classes matter more than they look. An
   * application configures a `request` or `user` subclass, so binding only the concrete class left
   * the natural type-hint -- `WebRequest`, `User` -- unregistered. The container then autowired a
   * brand-new instance for it: a consumer asking for the request got an empty one carrying none of
   * the request's parameters, headers or body, and one asking for the user got an unauthenticated
   * stranger. Both silently. Binding the base classes is also what lets the captive-dependency
   * guard see those type-hints as request-scoped and refuse them in a singleton.
   */
  private const array SEAM_CONTRACTS = [
    \Quiote\Controller\ControllerInterface::class,
    \Quiote\Response\WebResponseInterface::class,
    WebRequest::class,
    User::class,
    ISecurityUser::class,
    Routing::class,
    TranslationManager::class,
    \Quiote\Database\DatabaseManager::class,
  ];

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
    // The seam interfaces resolve to the same instances as their concrete classes, so new
    // code can constructor-inject the interface and get the request's real collaborator.
    $container->alias(ContextInterface::class, static::class);

    // The registry as an injectable collaborator, so code that genuinely needs a *named* profile
    // can declare that dependency instead of reaching for Context::getInstance().
    $container->set(ContextRegistry::class, ContextRegistry::shared());
    $container->alias('contexts', ContextRegistry::class);

    // The configuration as an injectable collaborator, so a service can declare a
    // ConfigRepository dependency instead of reaching for the Config facade.
    $container->set(\Quiote\Config\ConfigRepository::class, Config::repository());
    $container->alias('config', \Quiote\Config\ConfigRepository::class);

    $this->registerCoreService('controller', $this->controller);
    $this->registerCoreService('databaseManager', $this->databaseManager);
    $this->registerCoreService('translationManager', $this->translationManager);
    $this->registerCoreService('routing', $this->routing);
    $this->registerCoreService('request', $this->request, Container::SCOPE_REQUEST);
    $this->registerCoreService('user', $this->user, Container::SCOPE_REQUEST);
    $this->registerTelemetryServicesInContainer();
    $this->registerHttpClientFactory();
    $this->registerModelLocator();
    $this->registerRequestScopeAccessors();
    $this->registerExecutionHelpers();
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
   * Resolve a container service, checked against the type the caller expects.
   *
   * The container answers `mixed`, and every accessor below declares a concrete return type. The
   * check is not ceremony: an application may rebind any of these, and a rebinding to the wrong
   * type should say so here rather than as a type error in the caller.
   *
   * @template   T of object
   * @param      class-string<T> $id
   * @return     T
   * @throws     QuioteException When the container resolves $id to something else.
   * @since      4.0.0
   */
  private function service(string $id): object
  {
    $service = $this->getContainer()->get($id);

    if (!$service instanceof $id) {
      throw new QuioteException(sprintf(
        'The container resolves "%s" to %s, which is not a %s.',
        $id,
        get_debug_type($service),
        $id,
      ));
    }

    return $service;
  }

  /**
   * Bind the per-execution helpers the render tree shares.
   *
   * Their lifetimes are the interesting part, and binding them makes those lifetimes declared
   * rather than maintained by hand. The action resolver is stateless and lives for the process. The
   * asset registry and slot dispatcher are request-scoped, so the container drops them at the
   * request boundary -- which is what two manual nulls in reset() used to do.
   */
  private function registerExecutionHelpers(): void
  {
    $container = $this->getContainer();
    if ($container->has(\Quiote\Execution\ActionResolver::class)) {
      return;
    }

    $container->setFactory(
      \Quiote\Execution\ActionResolver::class,
      static fn(): \Quiote\Execution\ActionResolver => new \Quiote\Execution\ActionResolver(),
      Container::SCOPE_SINGLETON,
    );
    $container->alias('actionResolver', \Quiote\Execution\ActionResolver::class);

    $container->setFactory(
      \Quiote\Asset\AssetRegistry::class,
      static fn(): \Quiote\Asset\AssetRegistry => new \Quiote\Asset\AssetRegistry(),
      Container::SCOPE_REQUEST,
    );
    $container->alias('assetRegistry', \Quiote\Asset\AssetRegistry::class);

    // Depends on the controller, so it is built lazily rather than eagerly: getController() throws
    // before initialize() has run, and this registration happens during it.
    $container->setFactory(
      \Quiote\Execution\SlotDispatcher::class,
      fn(): \Quiote\Execution\SlotDispatcher => new \Quiote\Execution\SlotDispatcher(
        $this->getController(),
        $this->getActionResolver(),
      ),
      Container::SCOPE_REQUEST,
    );
    $container->alias('slotDispatcher', \Quiote\Execution\SlotDispatcher::class);
  }

  /**
   * Bind the model locator so a service can constructor-inject
   * {@see \Quiote\Model\ModelLocator} instead of reaching through the context for a model.
   *
   * A factory rather than an instance, so nothing that never asks for a model pays for one,
   * and so the container and getModel() share the single per-context locator.
   */
  private function registerModelLocator(): void
  {
    $container = $this->getContainer();
    if ($container->has(\Quiote\Model\ModelLocator::class)) {
      return;
    }
    $container->setFactory(
      \Quiote\Model\ModelLocator::class,
      fn(): \Quiote\Model\ModelLocator => $this->getModelLocator(),
      Container::SCOPE_SINGLETON,
    );
    $container->alias('modelLocator', \Quiote\Model\ModelLocator::class);
  }

  /**
   * Bind the two accessors that reach request-scoped state without capturing it.
   *
   * Singleton-scoped deliberately, and that is the whole point: the container refuses to inject
   * the `request` and `user` services into a singleton, because a singleton would keep one
   * request's instance forever. These hold nothing and resolve on every call, so they are safe to
   * capture and are what a singleton injects instead.
   */
  private function registerRequestScopeAccessors(): void
  {
    $container = $this->getContainer();
    if ($container->has(\Quiote\Request\RequestState::class)) {
      return;
    }

    $container->setFactory(
      \Quiote\Request\RequestState::class,
      fn(): \Quiote\Request\RequestState => new \Quiote\Request\RequestState($this),
      Container::SCOPE_SINGLETON,
    );
    $container->alias('requestState', \Quiote\Request\RequestState::class);

    $container->setFactory(
      \Quiote\User\CurrentUser::class,
      fn(): \Quiote\User\CurrentUser => new \Quiote\User\CurrentUser($this),
      Container::SCOPE_SINGLETON,
    );
    $container->alias('currentUser', \Quiote\User\CurrentUser::class);
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
   *
   * Answers from the process-wide {@see ContextRegistry}, which owns the one-context-per-profile
   * guarantee. Constructor-inject that registry in new code; a static reach for a named profile
   * hides whether the caller wants a specific profile or just the current one.
   *
   * @param      string $profile A name corresponding to a section of the config
   * @return     Context An context instance initialized with the
   *                          settings of the requested context name
   * @since      1.0.0
   */
  public static function getInstance($profile = null)
  {
    return ContextRegistry::shared()->get(
      $profile === null ? null : (string) $profile,
      static::class,
    );
  }

  /**
   * Reset context state between requests in a persistent worker.
   * This method clears request-specific state while preserving the context configuration.
   * Called from the worker request boundary; see WorkerManager::resetForNextRequest().
   * @since      1.0.0
   */
  /**
   * Persist request-scoped state that lives in the session.
   *
   * The user is the only thing flushed here: it is the sole writer of roles,
   * credentials and attributes, and those writes must land before the session
   * itself is persisted. Persisting the session is the session middleware's
   * job, on the way out, after this has run.
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
    if (!$this->lifecycle->claimRequestStateFlush()) {
      return;
    }

    try {
      if ($persistUser && $this->user !== null) {
        $this->user->shutdown();
      }
    } catch (\Throwable $e) {
      $logger = \Quiote\Logging\Log::for($this);
      $logger->error(
        "[Context.flushRequestState] user shutdown failed: " . $e->getMessage(),
      );
    }
  }

  public function reset(): void
  {
    $logger = \Quiote\Logging\Log::for($this);
    $vd = $logger->isEnabled(\Quiote\Logging\Level::Debug);

    // Drop the shared singleton models; a model holding this request's data must not
    // serve the next one.
    $this->modelLocator?->reset();
    // The slot dispatcher and asset registry are request-scoped container services now, so the
    // container's own reset in the request-boundary cleanup is what drops them.

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

    // Everything from here to the cleanup can throw -- a controller reset, a user persist, a
    // connection recycle against a socket the peer closed at the request boundary. None of that may
    // be allowed to skip the clears, so they run in the finally: a half-reset context that keeps
    // request N's authenticated user installed would serve request N+1 as that user, which is a
    // cross-user privilege leak and not merely a stale-data bug.
    try {
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
      // Must precede the clears below: it reads $this->user.
      $this->flushRequestState();

      // Walk the sequence in shutdown order, but shut nothing down: at a worker request boundary
      // only the database manager needs anything, and a full shutdown of the rest would either be
      // pointless (controller, request, routing, translation manager) or a double-write (the user,
      // which flushRequestState() above owns).
      foreach ($this->shutdownSequence->all() as $component) {
        if ($component === $this->user) {
          continue;
        }
        if ($component === $this->databaseManager) {
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
    } finally {
      // Runs every clear, guarded, then re-arms for the next request.
      $this->lifecycle->endRequest($logger);
    }

    if ($vd) {
      $logger->debug("[Context.reset] completed");
    }
  }

  /**
   * Register the clears that must happen when a request on this context ends.
   *
   * Order is meaningful and this is where it is decided: the session bag, the user and the request
   * go first, because those three are what turn a failed reset into a cross-user authentication leak
   * rather than stale data. {@see ContextLifecycle} guarantees the rest run even if one of them
   * throws.
   *
   * @return     void
   * @since      4.0.0
   */
  private function registerLifecycleClears(): void
  {
    $cleanup = $this->lifecycle;
    $cleanup->forgetSteps();

    // Drop this request's session. A bag surviving the boundary would serve request N's session to
    // request N+1; the next request's middleware installs its own, and until it does
    // getSessionBag() answers a NullSessionBag.
    $cleanup->onRequestEnd('the session bag', function (): void {
      $this->sessionBag = null;
    });

    // Dropped together with the bag and before anything that can throw: getUser() returns the
    // existing instance rather than rebuilding from the new session, so a surviving user keeps its
    // authenticated flag and granted roles.
    $cleanup->onRequestEnd('the user', function (): void {
      $this->user = null;
    });

    $cleanup->onRequestEnd('the request', function (): void {
      $this->request = null;
    });

    // Drop all ambient logging scopes, so this request's rid/user cannot leak into the next
    // request's log lines -- the same cross-request leak class as the state cleared above.
    $cleanup->onRequestEnd('the ambient logging scope', static function (): void {
      \Quiote\Logging\LogContext::clear();
    });

    // In lockstep with the nulling above: otherwise the container keeps serving a discarded
    // per-request instance until the next lazy recreation re-registers it.
    $cleanup->onRequestEnd('request-scoped container entries', function (): void {
      $this->container?->reset();
    });

    // Drop the cache namespace-version memo. Without this it is a per-process memo, so a version
    // bumped by another worker process is never observed and this process keeps serving
    // action/view/slot output that has already been invalidated, for as long as it lives.
    $cleanup->onRequestEnd('the cache request state', static function (): void {
      \Quiote\Cache\CacheManager::resetRequestState();
    });

    $cleanup->onRequestEnd('the registered reset instances', function (): void {
      foreach ($this->resetInstances as $instance) {
        if ($instance instanceof ResetInterface) {
          $instance->reset();
        }
      }
    });

    // Routing holds compiled-route caches that corrupt if carried across a request.
    $cleanup->onRequestEnd('the routing component', function (): void {
      $this->routing?->reset();
    });

    // The translation manager holds the request's locale, which would otherwise bleed.
    $cleanup->onRequestEnd('the translation manager', function (): void {
      $this->translationManager?->reset();
    });

    // Plugin-contributed clears, after the framework's own.
    \Quiote\Plugin\PluginManager::configureLifecycle($cleanup);
  }

  /**
   * Reset every live context's request-scoped state at a persistent worker's request boundary,
   * preserving each context's configuration. See {@see ContextRegistry::resetAll()}, which owns
   * the ordering and the per-context guarding.
   *
   * The compiled factory declarations are deliberately not reset: they are immutable across
   * requests and are what the lazy request/user/routing/databaseManager recreation rebuilds from.
   *
   * @param      ?string $profile The profile that served the request; it is reset first, but
   *             every other live context is reset too.
   * @return     void
   * @since      1.0.0
   */
  public static function resetWorkerState($profile = null): void
  {
    ContextRegistry::shared()->resetAll(is_string($profile) ? $profile : null);
  }

  /**
   * Serve a request against this context.
   *
   * Delegates to {@see \Quiote\Runtime\ContextRequestHandler}, which owns the middleware pipeline
   * and the per-request setup. Kept because it is what every runtime already calls; new code can
   * hold the handler directly, and it is a real PSR-15 RequestHandlerInterface.
   */
  public function handle(ServerRequestInterface $request): ResponseInterface
  {
    return $this->getRequestHandler()->handle($request);
  }

  /**
   * This context's request handler, built on first use.
   *
   * @since      4.0.0
   */
  public function getRequestHandler(): \Quiote\Runtime\ContextRequestHandler
  {
    return $this->requestHandler ??= new \Quiote\Runtime\ContextRequestHandler($this);
  }

  /**
   * Arm this context for a new request.
   *
   * Re-arms the per-request state flush so the next flushRequestState() actually runs. Called by the
   * request handler on the way in; {@see ContextLifecycle::endRequest()} does it too, on the way
   * out, and this covers a runtime that serves requests without a reset between them.
   *
   * @since      4.0.0
   */
  public function beginRequest(): void
  {
    $this->lifecycle->beginRequest();
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
          $this->getCorrelationId(),
        );
      } else {
        $message =
          "[Context] setRequest (no id) cid=" . $this->getCorrelationId();
      }
      $logger->debug($message);
    }
  }

  /**
   * Retrieve current correlation ID (may be null outside a handled request).
   */
  public function getCorrelationId(): ?string
  {
    return $this->requestHandler?->correlationId();
  }

  /**
   * Retrieve (lazily create) SlotDispatcher for sub-action (slot) execution.
   */
  public function getSlotDispatcher(): \Quiote\Execution\SlotDispatcher
  {
    return $this->service(\Quiote\Execution\SlotDispatcher::class);
  }

  /**
   * Retrieve (lazily create) the AssetRegistry shared by the whole render
   * tree for this request (the top-level View and every nested slot View).
   */
  public function getAssetRegistry(): \Quiote\Asset\AssetRegistry
  {
    return $this->service(\Quiote\Asset\AssetRegistry::class);
  }

  public function getActionResolver(): \Quiote\Execution\ActionResolver
  {
    return $this->service(\Quiote\Execution\ActionResolver::class);
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
   * Read the compiled factories configuration for this context, and return what it declared.
   *
   * The compiled file returns a declaration -- see {@see \Quiote\Config\Factory\FactoryDefinitions}
   * -- so this returns a value rather than relying on what an include did to `$this` on the way
   * past. The APCu branch holds the compiled source rather than a path, so it is evaluated; the
   * `return` inside it is what the eval answers.
   *
   * @return     mixed Whatever the compiled file returned; validated by the caller.
   * @since      4.0.0
   */
  private function loadCompiledFactories(\Quiote\Logging\CategoryLogger $logger): mixed
  {
    $path = Config::getString("core.config_dir") . "/factories.xml";

    $logger->debug("Context reading the compiled factories.xml");

    return CompiledConfig::value($path, $this->name);
  }

  /**
   * The live component for a configuration role, or null when this context has none.
   *
   * The one place role names are mapped to the properties holding them. The compiled configuration
   * names only roles, so this mapping lives in code that a rename breaks visibly.
   *
   * @since      4.0.0
   */
  private function componentForRole(string $role): ?object
  {
    return match ($role) {
      'database_manager' => $this->databaseManager,
      'translation_manager' => $this->translationManager,
      'routing' => $this->routing,
      'request' => $this->request,
      'controller' => $this->controller,
      'user' => $this->user,
      default => null,
    };
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
      $definitions = \Quiote\Config\Factory\FactoryDefinitions::fromCompiled(
        $this->loadCompiledFactories($logger),
        'the compiled factories cache for context "' . $this->name . '"',
      );
      $this->factoryDefinitions = $definitions;

      // The on-demand slots (response, validation_manager, session), in the shape
      // getFactoryInfo()/createInstanceFor() already read. Before the components are built, not
      // after: Controller::initialize() reaches for the `response` slot while it is being built.
      foreach ($definitions->factories as $role => $info) {
        $this->factories[$role] = [
          'class' => $info['class'],
          'parameters' => $info['parameters'],
          'factory_info' => ['class' => $info['class'], 'parameters' => $info['parameters']],
        ];
      }

      // Build the components the definitions declare, then take them by role. Assigning here,
      // by name and against a declared type, is the point of the compiled file being data: a
      // renamed or retyped property below is a static error, where the previous form -- generated
      // statements assigning into these same properties from inside an include -- only failed at
      // runtime, in the boot path, against a stale cache.
      $installed = (new \Quiote\Config\Factory\ComponentInstaller($this))->install($definitions);

      $this->databaseManager = $installed->optional('database_manager', \Quiote\Database\DatabaseManager::class);
      if ($this->databaseManager === null && Config::getBool("core.use_database", false)) {
        // Configured to use a database and given no database manager: the lazy rebuild path would
        // fail later, further from the cause.
        throw new QuioteException(
          'Context initialization failed for "' . $this->name . '": core.use_database is on but the '
          . 'factories configuration declares no database_manager.',
        );
      }
      $this->translationManager = $installed->optional('translation_manager', TranslationManager::class);
      $this->routing = $installed->need('routing', Routing::class);
      $this->request = $installed->need('request', WebRequest::class);
      $this->controller = $installed->need('controller', Controller::class);
      $this->user = $installed->need('user', User::class);

      $this->shutdownSequence->replaceAll(array_map(
        fn(string $role): ?object => $this->componentForRole($role),
        $definitions->shutdownOrder,
      ));
    } catch (\Exception $e) {
      // Same reasoning as Context::getInstance(): this runs before any PSR-15
      // pipeline exists, so there is no ErrorHandlingMiddleware to hand off to
      // yet. Log and propagate instead of rendering a template and exit()ing.
      $logger->error(
        'Context::initialize() failed for context "' . $this->name . '": ' . $e::class . ': ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine()
      );
      throw $e;
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
        // Drop the discarded user from the shutdown sequence too, keeping the order of the
        // remaining components; getUser() splices its replacement back in at the same position.
        $deferred = $this->user;
        $this->shutdownSequence->remove(
          static fn(object $component): bool => $component === $deferred,
        );
        $this->user = null; // force lazy recreation in getUser()
      } catch (\Throwable $e) {
        // The eagerly built user stays installed, so the first real request may observe the
        // authenticated=false latched before any session cookie was visible.
        $logger->warning(
          '[Context.initialize] could not defer the pre-request user; the first request may see a '
          . 'stale authentication state: ' . $e->getMessage()
        );
      }
    }

    // Register the core services
    // factories.xml just built (post user-deferral) into the container. Additive only —
    // nothing resolves services through the container yet.
    $this->registerCoreServicesInContainer();

    // Declare what happens when a request on this context ends. After the container registration,
    // so a plugin contributing a clear has already had its services bound.
    $this->registerLifecycleClears();
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

    $this->shutdownSequence->shutdownAll(skip: $this->user);
  }

  /**
   * The components this context shuts down, in order.
   *
   * The generated factory cache installs the sequence through here, and the lazy component
   * recreation paths splice replacements back into it.
   *
   * @since      4.0.0
   */
  public function getShutdownSequence(): ShutdownSequence
  {
    return $this->shutdownSequence;
  }

  /**
   * This context's per-request lifecycle -- the flush claim and the end-of-request clears.
   *
   * Exposed so a host that drives the context itself can register a clear of its own without going
   * through the plugin registry, and so what a context clears is assertable.
   *
   * @since      4.0.0
   */
  public function getLifecycle(): ContextLifecycle
  {
    return $this->lifecycle;
  }

  /**
   * Retrieve (lazily create) this context's model locator.
   *
   * The locator owns model resolution and model lifetimes; the context only owns the fact that
   * there is one per context. Constructor-inject {@see \Quiote\Model\ModelLocator} in new code.
   *
   * @since      4.0.0
   */
  public function getModelLocator(): \Quiote\Model\ModelLocator
  {
    return $this->modelLocator ??= new \Quiote\Model\ModelLocator(
      $this,
      new \Quiote\Model\ModelClassResolver(),
    );
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
    return $this->getModelLocator()->get(
      (string) $modelName,
      $moduleName === null ? null : (string) $moduleName,
      $parameters,
    );
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
   * Rebuild a core component from the factory metadata captured at initialize().
   *
   * The request, routing, user and database manager are all nulled at the worker request
   * boundary by {@see registerLifecycleClears()}'s clears and rebuilt on first access, and every
   * one of them needs the same sequence: refuse without factory metadata, construct,
   * optionally run the initialize()/startup() lifecycle pair, and re-register the fresh
   * instance in the container so it stops serving the discarded one. This is that
   * sequence, in one place.
   *
   * $runLifecycle is false for a component whose constructor is its whole setup (routing);
   * where the pair does run, initialize() must precede startup(), because startup() acts on
   * state initialize() populates.
   *
   * @template   T of object
   * @param      string $role The configuration role name, also used in diagnostics.
   * @param      class-string<T> $expected The type the caller is assigning into.
   * @param      string $scope The container scope to register the instance under.
   * @param      bool $runLifecycle Whether to call initialize() then startup().
   * @return     T
   * @throws     QuioteException When no factory metadata was captured for $role.
   * @since      3.2.0
   */
  private function rebuildFromFactoryInfo(
    string $role,
    string $expected,
    string $scope = Container::SCOPE_SINGLETON,
    bool $runLifecycle = true,
  ): object {
    $logger = \Quiote\Logging\Log::for($this);
    $vd = $logger->isEnabled(\Quiote\Logging\Level::Debug);

    $info = $this->factoryDefinitions?->buildInfo($role);
    if ($info === null) {
      $logger->error(
        "[Context] cannot rebuild '$role': the factories configuration declares no such component",
      );
      throw new QuioteException(
        ucfirst($role) .
          " object is null and no factory declaration is available for recreation in worker mode",
      );
    }

    if ($vd) {
      $logger->debug("[Context] rebuilding '$role' from factory info");
    }

    $className = $info["class"];
    if (!class_exists($className)) {
      throw new QuioteException(sprintf(
        'Cannot rebuild "%s": the factories configuration declares class "%s", which does not '
        . 'exist. If it was renamed, clear the configuration cache.',
        $role,
        $className,
      ));
    }

    $instance = new $className();
    if (!$instance instanceof $expected) {
      throw new QuioteException(sprintf(
        'Cannot rebuild "%s": the factories configuration declares %s, which is not a %s.',
        $role,
        $className,
        $expected,
      ));
    }
    // Duck-typed for the same reason ComponentInstaller is: not every core component implements
    // ContextComponentInterface yet, though all of them have the two methods.
    if ($runLifecycle && method_exists($instance, 'initialize')) {
      $instance->initialize($this, $info["parameters"]);
      if (method_exists($instance, 'startup')) {
        $instance->startup();
      }
    }

    $this->registerCoreService($role, $instance, $scope);

    if ($vd) {
      $logger->debug(
        "[Context] rebuilt '$role' using factory info: " . $className .
          " oid=" . spl_object_id($instance),
      );
    }

    return $instance;
  }

  /**
   * Retrieve the request.
   * @return     WebRequest The current Request implementation instance.
   * @since      1.0.0
   */
  public function getRequest()
  {
    if ($this->request === null) {
      $this->request = $this->rebuildFromFactoryInfo(
        'request',
        WebRequest::class,
        Container::SCOPE_REQUEST,
      );

      // Re-run controller startup so it re-caches the pointer to the new request's
      // global data. Guarded: a controller that cannot restart is a degraded request,
      // not a reason to withhold the request that was just built.
      $controller = $this->controller;
      if ($controller !== null) {
        try {
          $controller->startup();
        } catch (\Throwable $e) {
          \Quiote\Logging\Log::for($this)->error(
            "[Context.getRequest] controller startup failed after request recreation: " .
              $e->getMessage(),
          );
        }
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
    // Routing's constructor is its whole setup, so the initialize()/startup() pair is
    // deliberately skipped here.
    $this->routing ??= $this->rebuildFromFactoryInfo(
      'routing',
      Routing::class,
      Container::SCOPE_SINGLETON,
      runLifecycle: false,
    );

    return $this->routing;
  }

  /**
   * Retrieve this request's session bag -- the single seam every consumer of
   * session state goes through.
   *
   * SessionMiddleware installs the request's real bag on the way in. Until it
   * does, and for a context with no `session` factory slot configured at all
   * (a console command, a queue worker, a stateless API), this answers a
   * {@see \Quiote\Session\NullSessionBag}: reads return their default and
   * writes are discarded, so consumers never have to ask whether a session
   * exists before touching one.
   *
   * Pulled from the context rather than pushed into consumers, because
   * getUser() can recreate a user *after* the middleware has run: a user built
   * mid-request must still reach the same session as everything else.
   *
   * @return     \Quiote\Session\SessionBagInterface
   * @since      2.1.0
   */
  public function getSessionBag(): \Quiote\Session\SessionBagInterface
  {
    if ($this->sessionBag === null) {
      $this->setSessionBag(new \Quiote\Session\NullSessionBag());
    }

    /** @var \Quiote\Session\SessionBagInterface */
    return $this->sessionBag;
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
   * Install the session manager directly, bypassing the `session` factory slot.
   *
   * The slot is how an application configures its session backend; this is for
   * callers that already hold a built manager -- tests exercising cookie-name or
   * regeneration behaviour, and embedding code that constructs the backend
   * itself. Pass null to drop it so the next getSessionManager() rebuilds from
   * the slot.
   *
   * @param      ?\Quiote\Session\SessionManager $manager
   * @return     void
   * @since      3.0.3
   */
  public function setSessionManager(?\Quiote\Session\SessionManager $manager): void
  {
    $this->sessionManager = $manager;
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
    if ($bag !== null) {
      $this->registerCoreService('sessionBag', $bag, Container::SCOPE_REQUEST);
    }
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
    if ($this->user === null) {
      // The user may read through storage to the database, so the database manager has
      // to exist first. Failure to rebuild it is logged and tolerated: a user that then
      // cannot reach the database fails on its own terms with a better message than one
      // withheld here.
      if (Config::getBool("core.use_database", false) && $this->databaseManager === null) {
        try {
          $this->databaseManager = $this->rebuildFromFactoryInfo(
            'database_manager',
            \Quiote\Database\DatabaseManager::class,
          );
        } catch (\Throwable $e) {
          \Quiote\Logging\Log::for($this)->error(
            "[Context.getUser] could not rebuild the database manager before the user: " .
              $e->getMessage(),
          );
        }
      }

      $newUser = $this->rebuildFromFactoryInfo(
        'user',
        User::class,
        Container::SCOPE_REQUEST,
      );
      $this->user = $newUser;

      // Replace any stale user instances in the shutdown sequence, so an outdated auth
      // state is not the thing that gets persisted.
      $this->shutdownSequence->replaceRole(
        $newUser,
        static fn(object $component): bool =>
          $component instanceof \Quiote\User\User ||
          $component instanceof \Quiote\User\ISecurityUser,
        // No user in the sequence: put it first, so its writes land before
        // anything else the sequence shuts down.
        fallbackIndex: 0,
        caller: 'getUser',
      );

      return $newUser;
    }

    return $this->user;
  }
}
