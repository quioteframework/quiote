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

/**
 * An execution profile -- web, console, a named one -- and the container its services resolve from.
 *
 * The context owns its own identity and lifecycle: it initializes the components the compiled
 * factories configuration declares, binds them, arms and clears per-request state, and shuts them
 * down in order. It does not hand out its collaborators; a class that needs the routing, the user or
 * a service declares that in its constructor and the container supplies it. {@see ContextInterface}
 * is what a collaborator should type-hint, and it is two methods wide for that reason.
 *
 * A subclass named by `core.context_implementation` must keep the constructor signature: the
 * registry builds it knowing only the profile name.
 *
 * @phpstan-consistent-constructor
 * @since      1.0.0
 */
class Context implements \Stringable, ResetInterface, ContextInterface
{
  /**
   * @var        ?\Quiote\Runtime\ContextRequestHandler Serves requests against this context.
   *             Built on first use; see getRequestHandler().
   */
  private ?\Quiote\Runtime\ContextRequestHandler $requestHandler = null;

  /**
   * @var        ?Controller This profile's controller, memoized for the binding that answers it.
   */
  private ?Controller $controller = null;

  /**
   * @var        ?\Quiote\Database\DatabaseManager This profile's database manager, or null when
   *             `core.use_database` is off.
   */
  private ?\Quiote\Database\DatabaseManager $databaseManager = null;

  /**
   * @var        ?WebRequest The request being served, memoized for the request-scoped binding.
   *             {@see installRequest()} replaces it and invalidates that binding's memo.
   */
  private ?WebRequest $request = null;

  /**
   * @var        ?Routing This profile's routing, rebuilt on demand after a worker dropped it.
   */
  private ?Routing $routing = null;



  /**
   * @var        ?TranslationManager This profile's translation manager, or null when no
   *             `translation` configuration was compiled.
   */
  private ?TranslationManager $translationManager = null;

  /**
   * @var        ?User The request's user, memoized for the request-scoped binding.
   */
  private ?User $user = null;

  /**
   * @var        ShutdownSequence The components to shut down, in order.
   */
  private readonly ShutdownSequence $shutdownSequence;

  /**
   * @var        ?\Quiote\Config\Factory\FactoryDefinitions What the compiled factories
   *             configuration declared. The source the lazy worker-mode rebuilds read from.
   */
  private ?\Quiote\Config\Factory\FactoryDefinitions $factoryDefinitions = null;

  /**
   * @var        ContextLifecycle This context's per-request state machine: the flush claim and the
   *             clears that run when a request ends. Populated by registerLifecycleClears().
   */
  private readonly ContextLifecycle $lifecycle;

  /**
   * @var        ?\Quiote\Model\ModelLocator Resolves and hands out this context's models.
   *             Built on first use; see getModelLocator().
   */
  private ?\Quiote\Model\ModelLocator $modelLocator = null;

  /**
   * @var        array<int, mixed> Components a persistent worker runtime resets between requests.
   */
  private array $resetInstances = [];






  /**
   * @var        ?Container This profile's container, built on first use. Every collaborator this
   *             context installs is bound in it, and it is the only way in for a caller that
   *             cannot be wired statically.
   */
  private ?Container $container = null;

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
     * The name of the Context.
     */
    private readonly string $name,
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
   * This profile's container, built on first use.
   *
   * Every component the compiled factories configuration declares is bound here under both its role
   * name and its concrete class name, so `get(\Quiote\User\User::class)` and
   * `get(RbacSecurityUser::class)` answer the same instance.
   *
   * @since      3.2.0
   */
  public function getContainer(): Container
  {
    if ($this->container === null) {
      $this->container = new Container();
    }
    return $this->container;
  }


  /**
   * Bind the components that are built or rebuilt on demand.
   *
   * A consumer resolving `Routing::class` gets a rebuild from the factories declaration, which is how
   * a worker that dropped the instance at a request boundary gets it back. One resolving
   * `Controller::class` gets an explanation instead, because a context without a controller has not
   * finished initialize() and no rebuild can change that.
   *
   * Registered as factories rather than instances, so the closure -- and the property it memoizes into
   * -- decides what the answer is. The routing and the controller are transient in container terms
   * because that property is the cache; the request and the user stay request-scoped, because the
   * captive-dependency guard refuses a singleton that captures a request-scoped service and that
   * refusal is worth more than avoiding a second memo. What it costs is that a write has to invalidate
   * the memo: see {@see installRequest()}.
   *
   * @since      4.0.0
   */
  private function registerLazyCoreComponents(): void
  {
    $container = $this->getContainer();

    $container->setFactory(
      Routing::class,
      function (): Routing {
        // Routing's constructor is its whole setup, so the initialize()/startup() pair is
        // deliberately skipped here.
        return $this->routing ??= $this->rebuildFromFactoryInfo(
          'routing',
          Routing::class,
          Container::SCOPE_SINGLETON,
          runLifecycle: false,
        );
      },
      Container::SCOPE_TRANSIENT,
    );
    $container->alias('routing', Routing::class);

    $container->setFactory(
      \Quiote\Controller\Controller::class,
      function (): \Quiote\Controller\Controller {
        if ($this->controller === null) {
          throw new QuioteException(
            'Controller is not available: Context::initialize() has not run (or the "controller" '
              . 'factory failed) for context "' . $this->name . '"',
          );
        }

        return $this->controller;
      },
      Container::SCOPE_TRANSIENT,
    );
    $container->alias('controller', \Quiote\Controller\Controller::class);
    $container->alias(\Quiote\Controller\ControllerInterface::class, \Quiote\Controller\Controller::class);

    // Bound under the base class and the security contract too, so an application's own subclass is
    // reachable as the type a consumer type-hints -- the same reason SEAM_CONTRACTS exists for the
    // eagerly-built components.
    $container->setFactory('user', fn(): object => $this->aliasConcrete('user', $this->buildUser()), Container::SCOPE_REQUEST);
    $container->alias(User::class, 'user');
    $container->alias(\Quiote\User\ISecurityUser::class, 'user');

    $container->setFactory(
      'request',
      function (): WebRequest {
        $request = $this->buildRequest();
        $this->aliasConcrete('request', $request);

        return $request;
      },
      Container::SCOPE_REQUEST,
    );
    $container->alias(WebRequest::class, 'request');
  }

  /**
   * Alias a built component's own class to its role, and answer the component.
   *
   * An application configures a `request` or `user` subclass, and `registerCoreService()` bound that
   * concrete class alongside the role so `get($instance::class)` reached the same object. A factory
   * binding cannot do that up front -- the class is not known until the closure runs -- so it is done
   * on the way out, once, per built instance.
   *
   * @since      4.0.0
   */
  private function aliasConcrete(string $role, object $instance): object
  {
    $container = $this->getContainer();
    if ($instance::class !== $role && !$container->has($instance::class)) {
      $container->alias($instance::class, $role);
    }

    return $instance;
  }

  /**
   * Bind the on-demand slots the factories configuration declares.
   *
   * A slot -- `response`, `validation_manager`, `session` -- is a class the application names and the
   * framework instantiates *per request for it*, as against the components built once at initialize().
   * `Context::createInstanceFor($role)` used to be that, reading a mirror of the declaration held on
   * this class. Both are gone: a transient container binding is the same thing said in the language
   * the rest of the framework already resolves through, and it answers a typed request rather than
   * `object`.
   *
   * Bound under the role name, the declared class, and every ancestor of that class. The ancestors are
   * the point: an application configuring its own `ValidationManager` subclass should still be
   * reachable as `get(ValidationManager::class)`, which is what a consumer will type-hint. This is the
   * same reasoning as {@see SEAM_CONTRACTS} for the eagerly-built components, resolved per class here
   * rather than from a fixed list, because a slot's class is whatever the application named.
   *
   * `initialize($context, $parameters)` is called by the closure rather than left to autowiring: the
   * slot classes take their configuration that way, and the container cannot know that.
   *
   * @since      4.0.0
   */
  private function registerOnDemandSlots(\Quiote\Config\Factory\FactoryDefinitions $definitions): void
  {
    $container = $this->getContainer();

    foreach ($definitions->factories as $role => $info) {
      $class = $info['class'];
      $parameters = $info['parameters'];

      if (!class_exists($class)) {
        \Quiote\Logging\Log::for($this)->error(sprintf(
          '[Context] the factories configuration declares class "%s" for the "%s" slot, which does '
            . 'not exist; anything asking for that slot will fail to resolve.',
          $class,
          $role,
        ));

        continue;
      }

      $build = function () use ($class, $parameters): object {
        $instance = new $class();

        // Only when the slot takes its configuration that way. A `session` slot is a
        // SessionFactoryInterface, which is handed the context and its parameters at
        // createPersistence() time instead -- createInstanceFor() used to throw for exactly that slot.
        if (is_callable([$instance, 'initialize'])) {
          $instance->initialize($this, $parameters);
        }

        return $instance;
      };

      foreach ([$role, $class, ...array_keys(class_parents($class) ?: [])] as $id) {
        $container->setFactory($id, $build, Container::SCOPE_TRANSIENT);
      }
    }

    $this->registerSessionServices($definitions);
  }

  /**
   * Bind the session manager and the session bag.
   *
   * Both were accessors on this class. They are different lifetimes and that is why they are bound
   * differently: the manager is stateless apart from cookie configuration, so it is built once per
   * process, while the bag belongs to one request and the container drops it at the boundary.
   *
   * The manager is only bound when the `session` slot is declared. That is what makes
   * `tryGet(SessionManager::class)` answer null for an application with no session configured,
   * without anyone having to ask a separate question first.
   *
   * The bag's default is a {@see \Quiote\Session\NullSessionBag}: reading session state before
   * SessionMiddleware has installed the real bag has to answer something, and answering "empty" is
   * what every consumer already expects. SessionMiddleware replaces the binding per request.
   *
   * @since      4.0.0
   */
  private function registerSessionServices(\Quiote\Config\Factory\FactoryDefinitions $definitions): void
  {
    $container = $this->getContainer();

    $container->setFactory(
      \Quiote\Session\SessionBagInterface::class,
      static fn(): \Quiote\Session\SessionBagInterface => new \Quiote\Session\NullSessionBag(),
      Container::SCOPE_REQUEST,
    );
    $container->alias('sessionBag', \Quiote\Session\SessionBagInterface::class);

    if (!isset($definitions->factories['session'])) {
      return;
    }

    $container->setFactory(
      \Quiote\Session\SessionManager::class,
      function () use ($container): \Quiote\Session\SessionManager {
        $factory = $container->get('session');
        if (!$factory instanceof \Quiote\Session\SessionFactoryInterface) {
          throw new QuioteException(sprintf(
            'The class declared for the "session" slot, %s, is not a %s.',
            get_debug_type($factory),
            \Quiote\Session\SessionFactoryInterface::class,
          ));
        }

        $parameters = $this->factoryDefinitions?->factories['session']['parameters'] ?? [];

        return new \Quiote\Session\SessionManager(
          $factory->createPersistence($this, $parameters),
          $parameters,
        );
      },
      Container::SCOPE_SINGLETON,
    );
    $container->alias('sessionManager', \Quiote\Session\SessionManager::class);
  }

  /**
   * Bind a core service that the configuration may legitimately not declare.
   *
   * The translation manager and the database manager are optional, and leaving their bindings out
   * when they are absent is not a safe way to say so: both classes are instantiable with no required
   * constructor arguments, so the container would autowire a brand-new, uninitialized one for a
   * consumer that asked -- a translation manager with no locales, a database manager with no
   * connections, and no indication that anything was wrong.
   *
   * So the binding always exists. When the component is absent it is a factory that explains which
   * configuration would have declared it, which turns a silent empty stand-in into a message naming
   * the cause. That is what makes `__construct(private TranslationManager $t)` a safe thing for an
   * application to write.
   *
   * @param      string $role The container role name.
   * @param      class-string $class The class a consumer type-hints.
   * @param      ?object $instance The component, or null when the configuration declared none.
   * @param      string $declaredBy What would have declared it, for the message.
   * @since      4.0.0
   */
  private function registerOptionalCoreService(
    string $role,
    string $class,
    ?object $instance,
    string $declaredBy,
  ): void {
    if ($instance !== null) {
      $this->registerCoreService($role, $instance);

      return;
    }

    $name = $this->name;
    $container = $this->getContainer();
    $container->setFactory(
      $class,
      static function () use ($class, $name, $declaredBy): never {
        throw new \Quiote\Exception\ConfigurationException(sprintf(
          'Context "%s" has no %s: %s. A class depending on it cannot be built in this context.',
          $name,
          $class,
          $declaredBy,
        ));
      },
      Container::SCOPE_TRANSIENT,
    );
    $container->alias($role, $class);
  }

  /**
   * Register an already-constructed core service instance into the container
   * under its role name and concrete class name.
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
   * Roles bound as container factories rather than as instances.
   *
   * These are the components whose accessor did more than read a property -- a rebuild, a guard -- so
   * the behaviour lives in a factory closure. Binding an instance for one of them anywhere else would
   * silently take precedence over the factory and pin whatever existed at that moment.
   *
   * @var        array<int, string>
   * @since      4.0.0
   */
  private const array FACTORY_BOUND_ROLES = ['routing', 'controller', 'user', 'request'];

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

    // The controller and the routing are bound by registerLazyCoreComponents() instead, as factories:
    // binding the instance here would win over the factory and pin whatever existed at initialize(),
    // which is precisely what the lazy rebuild has to be able to replace.
    $this->registerLazyCoreComponents();
    $this->registerOptionalCoreService(
      'databaseManager',
      \Quiote\Database\DatabaseManager::class,
      $this->databaseManager,
      'core.use_database is off, or the factories configuration declares no database_manager',
    );
    $this->registerOptionalCoreService(
      'translationManager',
      TranslationManager::class,
      $this->translationManager,
      'the factories configuration declares no translation_manager',
    );
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
        $this->service(\Quiote\Controller\Controller::class),
        $this->service(\Quiote\Execution\ActionResolver::class),
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
      fn(): \Quiote\Request\RequestState => new \Quiote\Request\RequestState(
        fn(): WebRequest => $this->getContainer()->get(WebRequest::class),
        function (WebRequest|\Psr\Http\Message\ServerRequestInterface $request): void {
          $this->installRequest($request);
        },
      ),
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
    // request N+1; the next request's middleware installs its own, and until it does the container's
    // default answers a NullSessionBag. The binding is request-scoped, so re-registering the default
    // factory is what discards the resolved bag.
    $cleanup->onRequestEnd('the session bag', function (): void {
      $this->getContainer()->setFactory(
        \Quiote\Session\SessionBagInterface::class,
        static fn(): \Quiote\Session\SessionBagInterface => new \Quiote\Session\NullSessionBag(),
        Container::SCOPE_REQUEST,
      );
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
   * This context's request handler, built on first use.
   *
   * Declared as the PSR contract rather than as {@see \Quiote\Runtime\ContextRequestHandler}: every
   * caller outside the handler's own tests wants nothing but handle(), and a runtime that serves a
   * context through a handler of its own is then wiring, not a subclass.
   *
   * @since      4.0.0
   */
  public function getRequestHandler(): \Psr\Http\Server\RequestHandlerInterface
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
   * Install a replacement request, for {@see \Quiote\Request\RequestState::publish()}.
   *
   * This was `setRequest()`. A foreign PSR-7 request is normalized into a WebRequest on the way in,
   * so what the container answers is always one.
   *
   * @since      4.0.0
   */
  private function installRequest(WebRequest|\Psr\Http\Message\ServerRequestInterface $request): void
  {
    // Normalize any foreign PSR-7 request into a WebRequest, so what the container answers is always
    // one (with the Quiote helpers like isHttps()).
    // A plain Nyholm\Psr7\ServerRequest can otherwise flow in via middleware
    // (SlotMiddleware, ValidationMiddleware) or tests. An existing WebRequest
    // passes through unchanged.
    if (!$request instanceof WebRequest) {
      $request = WebRequest::fromPsr($request);
    }

    $this->request = $request;
    // The container memoizes the request at request scope, so the memo is what a later resolution
    // would answer with. Dropping the resolved entry -- not the binding -- is what makes the
    // replacement visible while leaving the rebuild factory in place.
    $this->container?->forgetResolved('request');

    // This runs several times per request; only build the diagnostic string (sprintf +
    // spl_object_id) when debug logging is actually enabled.
    $logger = \Quiote\Logging\Log::for($this);
    if ($logger->isEnabled(\Quiote\Logging\Level::Debug)) {
      $logger->debug(sprintf(
        "[Context] installRequest id=%d cid=%s",
        spl_object_id($request),
        $this->getCorrelationId(),
      ));
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

      // The on-demand slots (response, validation_manager, session) as transient container bindings.
      // Before the components are built, not after: Controller::initialize() reaches for the
      // `response` slot while it is being built.
      $this->registerOnDemandSlots($definitions);

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

    // Not for a role bound as a factory: registerCoreService() binds the *instance*, which would win
    // over the factory and pin this rebuild for the rest of the process -- so the next request
    // boundary would drop the resolved entry and the definition would hand the same object straight
    // back. The factory already returns what this method built.
    if (!in_array($role, self::FACTORY_BOUND_ROLES, true)) {
      $this->registerCoreService($role, $instance, $scope);
    }

    if ($vd) {
      $logger->debug(
        "[Context] rebuilt '$role' using factory info: " . $className .
          " oid=" . spl_object_id($instance),
      );
    }

    return $instance;
  }

  /**
   * Build or reuse this request's WebRequest, for the container binding in
   * {@see registerLazyCoreComponents()}.
   *
   * This was `getRequest()`. Like the user it rebuilds after a worker request boundary dropped the
   * instance, and it restarts the controller afterwards so the controller re-caches its pointer to the
   * new request's global data.
   *
   * @return     WebRequest
   * @since      4.0.0
   */
  private function buildRequest(): WebRequest
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
   * Build or reuse this request's user, for the container binding in
   * {@see registerLazyCoreComponents()}.
   *
   * This was `getUser()`. Not a plain accessor and never was: a worker that dropped the user at a
   * request boundary rebuilds it here, the database manager has to exist first because the user may
   * read through to it, and the shutdown sequence has to be told about the new instance, or an
   * outdated auth state is what gets persisted.
   *
   * @return     User|\Quiote\User\ISecurityUser
   * @since      4.0.0
   */
  private function buildUser()
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
