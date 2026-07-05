<?php
namespace Quiote\Controller;

/**
 * A container used for each action execution that holds necessary information,
 * such as the output type, the response etc.
 * DEPRECATION: This class is being phased out in favor of the container-less
 * execution pipeline (ActionExecutionContext + ExecutionState). See
 * TODO_CONTAINER_REMOVAL.md for the multi-phase removal plan. Instantiation
 * under consolidated no-container flags emits a deprecation warning.
 * @deprecated Will be removed after the QUIOTE_NO_CONTAINER_ALL flag graduates
 *             (target: next minor release after full parity & cache refactor).
 * @since      1.0.0
 * @version    1.0.0
 */
use Quiote\Util\AttributeHolder;
use Quiote\Exception\QuioteException;
use Quiote\Exception\DisabledModuleException;
use Quiote\Exception\FileNotFoundException;
use Quiote\Exception\ConfigurationException;
use Quiote\Context;
use Quiote\Config\Config;
use Quiote\View\View;
use Quiote\Util\Toolkit;
use Quiote\Config\ConfigCache;
use Quiote\Config\APCuConfigCache;
use Quiote\Response\WebResponse;
use Symfony\Contracts\Service\ResetInterface;

use \Exception;
class ExecutionContainer extends AttributeHolder implements ResetInterface
{
	/**
	 * Emit a deprecation warning when instantiated while the global no-container
	 * execution mode is effectively enabled. This occurs when either:
	 *  - QUIOTE_NO_CONTAINER_ALL=1 (explicit global bypass), or
	 *  - Both dispatch context flags (simple + non-simple) AND all slot no-container
	 *    flags are enabled, making the container unnecessary for runtime execution.
	 * The warning is suppressed in test processes unless the env
	 * QUIOTE_CONTAINER_DEPRECATION_STRICT=1 is set to reduce noise; tests can enable
	 * strict mode to assert the warning via expectDeprecation().
	 */
	public function __construct()
	{
		$noContainerAll = getenv('QUIOTE_NO_CONTAINER_ALL');
		$dcSimple = getenv('QUIOTE_DISPATCH_CONTEXT_SIMPLE');
		$dcNonSimple = getenv('QUIOTE_DISPATCH_CONTEXT_NONSIMPLE');
		$slotSimple = getenv('QUIOTE_SLOT_SIMPLE_NO_CONTAINER');
		$slotAll = getenv('QUIOTE_SLOT_NO_CONTAINER_ALL');
		$strict = getenv('QUIOTE_CONTAINER_DEPRECATION_STRICT');
		$effectiveBypass = ($noContainerAll)
			|| (($dcSimple && $dcNonSimple) && ($slotSimple || $slotAll));
		if($effectiveBypass && ($strict || PHP_SAPI !== 'cli')) { // default suppress in CLI tests unless strict
			\Quiote\Util\DeprecationSilencer::triggerOnce('ExecutionContainer is deprecated under no-container execution mode and will be removed in a future release.');
		}
		parent::__construct();
	}

	/**
	 * @var ?string The context name
	 */
	protected final $contextName;

	/**
	 * @var ?string The output type name
	 */
	protected final $outputTypeName;
	
	/**
	 * @var        ?Context The context instance.
	 */
	protected $context = null;

	// Legacy filter chain removed; container now invokes action/view directly.

	/**
	 * @var        ?\Quiote\Validator\ValidationManager The validation manager instance.
	 */
	protected $validationManager = null;

	/**
	 * @var        ?string The request method for this container.
	 */
	protected $requestMethod = null;

	/**
	 * @var        array<string, mixed>|object|null A request data holder with request info.
	 */
	protected $requestData = null; // TODO: check if this can actually be protected
	                               // or whether it should be private (would break actiontests though)

	/**
	 * @var        ?object A pointer to the global request data.
	 */
	private $globalRequestData = null;

	/**
	 * @var        array<string, mixed>|null Additional request arguments.
	 */
	protected $arguments = null;

	/**
	* @var        ?WebResponse A response instance holding the Action's output.
	 */
	protected $response = null;

	/**
	 * @var        ?OutputType The output type for this container.
	 */
	protected $outputType = null;

	/**
	 * @var        ?float The microtime at which this container was initialized.
	 */
	protected $microtime = null;

	/**
	 * @var        ?\Quiote\Action\Action The Action instance that belongs to this container.
	 */
	protected $actionInstance = null;

	/**
	 * @var        ?\Quiote\View\View The View instance that belongs to this container.
	 */
	protected $viewInstance = null;

	/**
	 * @var        ?\Quiote\Execution\LegacyContainerInitContext Shared init context passed to
	 *             this container's action and view instances, so attributes set during
	 *             the action's initialize() remain visible to the view's.
	 */
	protected $initContext = null;

	/**
	 * Whether validation has already been performed for this container (early validation middleware).
	 */
	protected bool $validationPerformed = false;

	/**
	 * Result of validation when performed.
	 */
	protected bool $validationSucceeded = true;

	/**
	 * @var        ?string The name of the Action's Module.
	 */
	protected $moduleName = null;

	/**
	 * @var        ?string The name of the Action.
	 */
	protected $actionName = null;

	/**
	 * @var        ?string Name of the module of the View returned by the Action.
	 */
	protected $viewModuleName = null;

	/**
	 * @var        ?string The name of the View returned by the Action.
	 */
	protected $viewName = null;

	/**
	 * @var        ?ExecutionContainer The next container to execute.
	 */
	protected $next = null;

	/**
	 * @var bool Indicates if this container is the result of a security forward.
	 */
	protected $securityForwarded = false;

	/**
	 * Action names may contain any valid PHP token, as well as dots and slashes
	 * (for sub-actions).
	 */
	const SANE_ACTION_NAME = '/[a-zA-Z_\x7f-\xff][a-zA-Z0-9_\x7f-\xff\/.]*/';
	
	/**
	 * View names may contain any valid PHP token, as well as dots and slashes
	 * (for sub-actions).
	 */
	const SANE_VIEW_NAME   = '/[a-zA-Z_\x7f-\xff][a-zA-Z0-9_\x7f-\xff\/.]*/';
	
	/**
	 * Only valid PHP tokens are allowed in module names.
	 */
	const SANE_MODULE_NAME = '/[a-zA-Z_\x7f-\xff][a-zA-Z0-9_\x7f-\xff]*/';
	
	/**
	 * Pre-serialization callback.
	 * Will set the name of the context instead of the instance, and the name of
	 * the output type instead of the instance. Both will be restored by __wakeup
	 * @since      1.0.0
	 */
	public function __sleep()
	{
		$this->contextName = $this->context->getName();
		if(!empty($this->outputType)) {
			$this->outputTypeName = $this->outputType->getName();	
		}
		$arr = get_object_vars($this);
		unset($arr['context'], $arr['outputType'], $arr['requestData'], $arr['globalRequestData']);
		return array_keys($arr);
	}

	/**
	 * Post-unserialization callback.
	 * Will restore the context and output type instances based on their names set
	 * by __sleep.
	 * @since      1.0.0
	 */
	public function __wakeup()
	{
		$this->context = Context::getInstance($this->contextName);
		
		if(!empty($this->outputTypeName)) {
			$this->outputType = $this->context->getController()->getOutputType($this->outputTypeName);
		}
		
		$this->globalRequestData = null;
		unset($this->contextName, $this->outputTypeName);
	}

	/**
	 * Initialize the container. This will create a response instance.
	 * @param      Context $context The current Context instance.
	 * @param      array<string, mixed> $parameters An array of initialization parameters.
	 * @return     void
	 * @since      1.0.0
	 */
	public function initialize(Context $context, array $parameters = [])
	{
		$this->microtime = microtime(true);

		$this->context = $context;

		$this->parameters = $parameters;

		$this->response = $this->context->createInstanceFor('response');
	}

	/**
	 * Creates a new container instance with the same output type and request
	 * method as this one.
	 * @param      string $moduleName The name of the module.
	 * @param      string $actionName The name of the action.
	 * @param      array<string, mixed>|null $arguments Additional request arguments.
	 * @param      string $outputType Optional name of an initial output type
	 *                                    to set.
	 * @param      string $requestMethod Optional name of the request method to
	 *                                    be used in this container.
	 * @return     ExecutionContainer A new execution container instance,
	 *                                     fully initialized.
	 * @since      1.0.0
	 */
	public function createExecutionContainer($moduleName = null, $actionName = null, ?array $arguments = null, $outputType = null, $requestMethod = null)
	{
		// DEPRECATED: Container spawning retained only for legacy forward paths.
		$logger = \Quiote\Logging\Log::for($this);
		$logger->debug('container.create (legacy) module=' . ($moduleName ?? 'null') . ' action=' . ($actionName ?? 'null'));
		$container = new self();
		$container->initialize($this->context, []);
		if($moduleName !== null) { $container->setModuleName($moduleName); }
		if($actionName !== null) { $container->setActionName($actionName); }
		if($arguments !== null) { $container->setArguments($arguments); }
		if($outputType !== null) { $container->setOutputType($this->context->getController()->getOutputType($outputType)); }
		if($requestMethod !== null) { $container->setRequestMethod($requestMethod); }
		// propagate selected parameters (slot/forward flags)
		$container->setParameters($this->getParameters());
		return $container;
	}

	/**
	 * Start execution.
	 * This will create an instance of the action and merge in request parameters.
	 * This method returns a response. It is not necessarily the same response as
	 * the one of this container, but instead the one that contains the actual
	 * content that should be used for output etc, since the container's own
	 * response might be empty or invalid due to a "next" container that has been
	 * set and executed.
	* @return     WebResponse The "real" response.
	 * @since      1.0.0
	 */
	public function execute()
	{
		
		// Slot recursion guard. $slotStack is static so it survives across
		// FrankenPHP worker requests; it MUST be popped on every exit (including
		// exceptions) or it would grow unbounded and eventually mis-fire the
		// recursion check for a slot merely rendered on many requests. The stack
		// mirrors the current (properly nested) slot call stack: the count of
		// $key in it is the live recursion depth of that specific slot.
		static $slotStack = [];
		$slotPushed = false;
		$isSlot = $this->getParameter('is_slot', false);
		if ($isSlot) {
			$key = $this->getModuleName() . '/' . $this->getActionName();
			$slotStack[] = $key;
			$slotPushed = true;
			$count = 0;
			foreach ($slotStack as $slot) {
				if ($slot === $key) $count++;
			}
			if ($count > 10) { // Arbitrary limit, adjust as needed
				array_pop($slotStack); // undo our push before bailing out
				throw new QuioteException("Infinite slot recursion detected for slot: $key");
			}
		}

		try {
			$controller = $this->context->getController();

			$controller->countExecution();

			$moduleName = $this->getModuleName();

			try {
				$actionInstance = $this->getActionInstance();
			} catch(DisabledModuleException) {
				$this->setNext($this->createSystemActionForwardContainer('module_disabled'));
				return $this->proceed();
			} catch(FileNotFoundException) {
				$this->setNext($this->createSystemActionForwardContainer('error_404'));
				return $this->proceed();
			}

			// copy and merge request data as required
			$this->initRequestData();

		// Legacy filter chain + security/validation filters removed. Execution proceeds directly.

			// Directly run action + view since legacy filter chain removed.
			$this->runAction();
			return $this->proceed();
		} finally {
			if ($slotPushed) {
				array_pop($slotStack);
			}
		}
	}
	
	/**
	 * Copies and merges the global request data.
	 * @return       void
	 * @since        1.0.0
	 */
	public function initRequestData()
	{
		// Idempotent: only initialize once
		if($this->requestData !== null) {
			return;
		}
		if($this->getActionInstance()->isSimple()) {
			if($this->arguments !== null) {
				// arrays are value types in PHP, so a plain assignment already
				// gives us a copy that mutations won't affect the original
				$this->requestData = $this->arguments;
			} else {
				$rdhc = $this->getContext()->getRequest()->getParameter('request_data_holder_class');
				$this->requestData = new $rdhc();
			}
		} else {
			// mmmh I smell awesomeness... clone the RD JIT, yay, that's the spirit
			$this->requestData = clone $this->globalRequestData;

			if($this->arguments !== null) {
				$this->requestData->merge($this->arguments);
			}
		}
	}

	/**
	 * Backwards compatible alias.
	 */
	public function ensureRequestDataInitialized(): void
	{
		$this->initRequestData();
	}

	public function hasValidationPerformed(): bool { return $this->validationPerformed; }
	public function wasValidationSuccessful(): bool { return $this->validationSucceeded; }
	public function setValidationState(bool $performed, bool $succeeded): void { $this->validationPerformed = $performed; $this->validationSucceeded = $succeeded; }
	
	/**
	 * Create a system forward container
	 * Calling this method will set the attributes:
	 *  - requested_module
	 *  - requested_action
	 *  - (optional) exception
	 * in the appropriate namespace on the created container as well as the global
	 * request (for legacy reasons)
	 * @param      string $type The type of forward to create (error_404, 
	 *                             module_disabled, secure, login, unavailable).
	 * @param      Exception $e Optional exception thrown by the controller
	 *                             while resolving the module/action.
	 * @return     ExecutionContainer The forward container.
	 * @since      1.0.0
	 */
	public function createSystemActionForwardContainer($type, ?Exception $e = null)
	{
		if(!in_array($type, ['error_404', 'module_disabled', 'secure', 'login', 'unavailable'])) {
			throw new QuioteException(sprintf('Unknown system forward type "%1$s"', $type));
		}
		
		// track the requested module so we have access to the data in the error 404 page
		$forwardInfoData = [
			'requested_module' => $this->getModuleName(),
			'requested_action' => $this->getActionName(),
			'exception'        => $e,
		];
		$forwardInfoNamespace = 'org.quiote.controller.forwards.' . $type;
		
		$moduleName = Config::getNullableString('actions.' . $type . '_module');
		$actionName = Config::getNullableString('actions.' . $type . '_action');
		
		if(false === $this->context->getController()->checkActionFile($moduleName, $actionName)) {
			// cannot find unavailable module/action
			$error = 'Invalid configuration settings: actions.%3$s_module "%1$s", actions.%3$s_action "%2$s"';
			$error = sprintf($error, $moduleName, $actionName, $type);
			
			throw new ConfigurationException($error);
		}
		
		$forwardContainer = $this->createExecutionContainer($moduleName, $actionName);
		
		$forwardContainer->setAttributes($forwardInfoData, $forwardInfoNamespace);
		// legacy: WebRequest has no namespaced bulk setter, so mirror each entry individually
		$request = $this->context->getRequest();
		foreach ($forwardInfoData as $key => $value) {
			$request->setAttribute($forwardInfoNamespace . '.' . $key, $value);
		}
		
		return $forwardContainer;
	}
	
	/**
     * Proceed to the "next" container by running it and returning its response,
     * or return our response if there is no "next" container.
     * @return \Quiote\Response\WebResponse The "real" response.
     * @since      1.0.0
     */
    protected function proceed()
	{
		if($this->next !== null) {
			return $this->next->execute();
		} else {
			return $this->getResponse();
		}
	}

	/**
	 * Get the Context.
	 * @return     Context The Context.
	 * @since      1.0.0
	 */
	public final function getContext()
	{
		return $this->context;
	}

	/**
	 * Retrieve the ValidationManager
	 * @return     \Quiote\Validator\ValidationManager The container's ValidationManager
	 *                                    implementation instance.
	 * @since      1.0.0
	 */
	public function getValidationManager()
	{
		if($this->validationManager === null) {
			$this->validationManager = $this->context->createInstanceFor('validation_manager');
		}
		return $this->validationManager;
	}
	
	// getFilterChain() removed with legacy filter system.
	
	/**
	 * Execute the Action.
	 * @return     mixed The processed View information returned by the Action.
	 * @since      1.0.0
	 */
	public function runAction()
	{
	$viewName = null;

		$validationManager = $this->getValidationManager();

		// get the current action instance
    /** @var \Quiote\Action\Action $actionInstance */
    $actionInstance = $this->getActionInstance();

		// get the current action information
		$moduleName = $this->getModuleName();
		$actionName = $this->getActionName();

		// get the (already formatted) request method
		$method = $this->getRequestMethod();

		$requestData = $this->getRequestData();

	// Use centralized ActionResolver for method selection (preserves legacy semantics)
	$resolver = $this->context->getActionResolver();
	$executeMethod = 'execute' . $method;
	$useGenericMethods = !is_callable([$actionInstance, $executeMethod]);

		if($actionInstance->isSimple() || ($useGenericMethods && !is_callable([$actionInstance, $executeMethod]))) {
			// this action will skip validation/execution for this method
			// get the default view
			$viewName = $actionInstance->getDefaultViewName();

			// run the validation manager for non-simple actions if not already performed (early middleware may have done it)
			if(!$actionInstance->isSimple() && !$this->hasValidationPerformed()) {
				$success = $validationManager->execute($requestData);
				$this->setValidationState(true, $success);
			}
		} else {
			// perform validation unless already done by early middleware
			$validationOk = $this->hasValidationPerformed() ? $this->wasValidationSuccessful() : $this->performValidation();
			if($validationOk) {
				// execute the action
				/** @var \Quiote\Action\Action $actionInstance */
				$viewName = $resolver->execute($actionInstance, $method, $requestData);
			} else {
				// validation failed
				$handleErrorMethod = 'handle' . $method . 'Error';
				if(!is_callable([$actionInstance, $handleErrorMethod])) {
					$handleErrorMethod = 'handleError';
				}
				/** @var \Quiote\Action\Action $actionInstance */
				$viewName = $resolver->execute($actionInstance, $handleErrorMethod === 'handleError' ? '' : $method . 'Error', $requestData); // falls back internally
			}
		}

	// Delegate view resolution to pure ViewNameResolver (legacy facade removed)
	$viewNameResolver = new \Quiote\Execution\ViewNameResolver();
	[$viewModule, $canonical] = $viewNameResolver->resolve($moduleName, $actionName, $viewName);
	return [$viewModule, $canonical];
	}
	
	/**
	 * Performs validation for this execution container.
	 * @return     bool true if the data validated successfully, false otherwise.
	 * @since      1.0.0
	 */
	public function performValidation()
	{
		$validationManager = $this->getValidationManager();

		// Ensure validation manager is properly reset for worker mode
		$validationManager->reset();

		// get the current action instance
		$actionInstance = $this->getActionInstance();
		// get the (already formatted) request method
		$method = $this->getRequestMethod();

		$requestData = $this->getRequestData();
		
		// set default validated status
		$validated = true;

		$this->registerValidators();

		// process validators
		$validated = $validationManager->execute($requestData);
		$this->setValidationState(true, $validated);

		$validateMethod = 'validate' . $method;
		if(!is_callable([$actionInstance, $validateMethod])) {
			$validateMethod = 'validate';
		}

		// process manual validation
		$manual = $actionInstance->$validateMethod($requestData);
		$this->validationSucceeded = $this->validationSucceeded && $manual;
		return $this->validationSucceeded;
	}

	/**
	 * Register validators for this execution container.
	 * @return     void
	 * @since      1.0.0
	 */
	public function registerValidators()
	{
		$validationManager = $this->getValidationManager();

		// get the current action instance
		$actionInstance = $this->getActionInstance();
		
		// get the current action information
		$moduleName = $this->getModuleName();
		$actionName = $this->getActionName();
		
		// get the (already formatted) request method
		$method = $this->getRequestMethod();

		// get the current action validation configuration
		$validationConfig = Toolkit::evaluateModuleDirective(
			$moduleName,
			'quiote.validate.path',
			[
				'moduleName' => $moduleName,
				'actionName' => $actionName,
			]
		);
		
		if(is_readable($validationConfig)) {
			// load validation configuration
			// do NOT use require_once
			if(defined('QUIOTE_USE_APCU_CONFIG_CACHE') && QUIOTE_USE_APCU_CONFIG_CACHE) {
				$cacheResult = APCuConfigCache::checkConfig($validationConfig, $this->context->getName());
				if (str_starts_with($cacheResult, 'APCU:')) {
					eval('?>' . substr($cacheResult, 5));
				} else {
					require($cacheResult);
				}
			} else {
				require(ConfigCache::checkConfig($validationConfig, $this->context->getName()));
			}
		}

		// manually load validators
		$registerValidatorsMethod = 'register' . $method . 'Validators';
		if(!is_callable([$actionInstance, $registerValidatorsMethod])) {
			$registerValidatorsMethod = 'registerValidators';
		}
		
		$actionInstance->$registerValidatorsMethod();
	}
	
	/**
	 * Retrieve this container's request method name.
	 * @return     string The request method name.
	 * @since      1.0.0
	 */
	public function getRequestMethod()
	{
		return $this->requestMethod;
	}

	/**
	 * Set this container's request method name.
	 * @param      string $requestMethod The request method name.
	 * @return     void
	 * @since      1.0.0
	 */
	public function setRequestMethod($requestMethod)
	{
		$this->requestMethod = $requestMethod;
	}

	/**
	 * Retrieve this container's request data holder instance.
	 * @return     array<string, mixed>|object|null The request data holder.
	 * @since      1.0.0
	 */
	public final function getRequestData()
	{
		return $this->requestData;
	}

	/**
	 * Set this container's global request data holder reference.
	 * @param      object $rd The request data holder.
	 * @since      1.0.0
	 */
	public final function setRequestData(object $rd): void
	{
		$this->globalRequestData = $rd;
	}

	/**
	 * Get this container's additional arguments.
	 * @return     array<string, mixed>|null The additional arguments.
	 * @since      1.0.0
	 */
	public function getArguments()
	{
		return $this->arguments;
	}

	/**
	 * Set this container's additional arguments.
	 * @param      array<string, mixed> $arguments The additional arguments.
	 * @since      1.0.0
	 */
	public function setArguments(array $arguments): void
	{
		$this->arguments = $arguments;
	}

	/**
	 * Retrieve this container's response instance.
	 * @return     ?\Quiote\Response\WebResponse The Response instance for this action.
	 * @since      1.0.0
	 */
	public function getResponse()
	{
		return $this->response;
	}

	/**
	 * Set a new response.
	* @param      WebResponse $response A new Response instance.
	 * @return     void
	 * @since      1.0.0
	 */
	public function setResponse(WebResponse $response)
	{
		$this->response = $response;
		// do not set the output type on the response here!
	}

	/**
	 * Retrieve the output type of this container.
	 * @return     OutputType The output type object.
	 * @since      1.0.0
	 */
	public function getOutputType()
	{
		return $this->outputType;
	}

	/**
	 * Set a different output type for this container.
	 * @param      OutputType $outputType An output type object.
	 * @return     void
	 * @since      1.0.0
	 */
	public function setOutputType(OutputType $outputType)
	{
		$logger = \Quiote\Logging\Log::for($this);
		$logger->debug('container.set_output_type value=' . $outputType->getName());
		
		$this->outputType = $outputType;
		if($this->response) {
			$this->response->setOutputType($outputType);
			$logger->debug('container.set_output_type applied_to_response');
		}
	}

	/**
	 * Retrieve this container's microtime.
	 * @return     ?float The microtime this container was initialized, or null
	 *                    if not yet initialized.
	 * @since      1.0.0
	 */
	public function getMicrotime()
	{
		return $this->microtime;
	}

	/**
	 * Retrieve this container's action instance.
	 * @return     \Quiote\Action\Action An action implementation instance.
	 * @since      1.0.0
	 */
	public function getActionInstance()
	{
		if($this->actionInstance === null) {
			$controller = $this->context->getController();
			
			$moduleName = $this->getModuleName();
			$actionName = $this->getActionName();
			
			$this->actionInstance = $controller->createActionInstance($moduleName, $actionName);

			// initialize the action
			$this->actionInstance->initialize($this->getInitContext());
		}

		return $this->actionInstance;
	}

	/**
	 * Retrieve the shared init context adapter for this container's action/view instances.
	 * @since      1.0.0
	 */
	protected function getInitContext(): \Quiote\Execution\LegacyContainerInitContext
	{
		if ($this->initContext === null) {
			$this->initContext = new \Quiote\Execution\LegacyContainerInitContext($this);
		}
		return $this->initContext;
	}

	/**
	 * Retrieve this container's view instance.
	 * @return     \Quiote\View\View A view implementation instance.
	 * @since      1.0.0
	 */
	public function getViewInstance()
	{
		if($this->viewInstance === null) {
			// get the view instance
			$this->viewInstance = $this->getContext()->getController()->createViewInstance($this->getViewModuleName(), $this->getViewName());
			// initialize the view
			$this->viewInstance->initialize($this->getInitContext());
		}
		
		return $this->viewInstance;
	}

	/**
	 * Set this container's view instance.
	 * @param      View $viewInstance A view implementation instance.
	 * @return     View The view instance that was set.
	 * @since      1.0.0
	 */
	public function setViewInstance($viewInstance)
	{
		return $this->viewInstance = $viewInstance;
	}

	/**
	 * Retrieve this container's module name.
	 * @return     string A module name.
	 * @since      1.0.0
	 */
	public function getModuleName()
	{
		return $this->moduleName;
	}

	/**
	 * Retrieve this container's action name.
	 * @return     string An action name.
	 * @since      1.0.0
	 */
	public function getActionName()
	{
		return $this->actionName;
	}

	/**
	 * Retrieve this container's view module name. This is the name of the module of
	 * the View returned by the Action.
	 * @return     string A view module name.
	 * @since      1.0.0
	 */
	public function getViewModuleName()
	{
		return $this->viewModuleName;
	}

	/**
	 * Retrieve this container's view name.
	 * @return     string A view name.
	 * @since      1.0.0
	 */
	public function getViewName()
	{
		return $this->viewName;
	}

	/**
	 * Set the module name for this container.
	 * @param      ?string $moduleName A module name.
	 * @return     void
	 * @since      1.0.0
	 */
	public function setModuleName($moduleName)
	{
		if(null === $moduleName) {
			$this->moduleName = null;
		} elseif(preg_match(self::SANE_MODULE_NAME, $moduleName)) {
			$this->moduleName = $moduleName;
		} else {
			throw new QuioteException(sprintf('Invalid module name "%1$s"', $moduleName));
		}
	}

	/**
	 * Set the action name for this container.
	 * @param      ?string $actionName An action name.
	 * @return     void
	 * @since      1.0.0
	 */
	public function setActionName($actionName)
	{
		if(null === $actionName) {
			$this->actionName = null;
		} elseif(preg_match(self::SANE_ACTION_NAME, $actionName)) {
			$actionName = Toolkit::canonicalName($actionName);
			$this->actionName = $actionName;
		} else {
			throw new QuioteException(sprintf('Invalid action name "%1$s"', $actionName));
		}
	}

	/**
	 * Set the view module name for this container.
	 * @param      ?string $viewModuleName A view module name.
	 * @return     void
	 * @since      1.0.0
	 */
	public function setViewModuleName($viewModuleName)
	{
		if(null === $viewModuleName) {
			$this->viewModuleName = null;
		} elseif(preg_match(self::SANE_MODULE_NAME, $viewModuleName)) {
			$this->viewModuleName = $viewModuleName;
		} else {
			throw new QuioteException(sprintf('Invalid view module name "%1$s"', $viewModuleName));
		}
	}

	/**
	 * Set the module name for this container.
	 * @param      ?string $viewName A view name.
	 * @return     void
	 * @since      1.0.0
	 */
	public function setViewName($viewName)
	{
		if(null === $viewName) {
			$this->viewName = null;
		} elseif(preg_match(self::SANE_VIEW_NAME, $viewName)) {
			$viewName = Toolkit::canonicalName($viewName);
			$this->viewName = $viewName;
		} else {
			throw new QuioteException(sprintf('Invalid view name "%1$s"', $viewName));
		}
	}

	 /**
	 * Check if a "next" container has been set.
	 * @return     bool True, if a container for eventual execution has been set.
	 * @since      1.0.0
	 */
	public function hasNext()
	{
		return $this->next !== null;
	}

	/**
	 * Get the "next" container.
	 * @return     ExecutionContainer The "next" container, of null if unset.
	 * @since      1.0.0
	 */
	public function getNext()
	{
		return $this->next;
	}

	/**
	 * Set the container that should be executed once this one finished running.
	 * @param      ExecutionContainer $container An execution container instance.
	 * @return     void
	 * @since      1.0.0
	 */
	public function setNext(ExecutionContainer $container)
	{
		$this->next = $container;
	}

	/**
	 * Remove a possibly set "next" container.
	 * @return     ExecutionContainer The removed "next" container, or null
	 *                                     if none had been set.
	 * @since      1.0.0
	 */
	public function clearNext()
	{
		$retval = $this->next;
		$this->next = null;
		return $retval;
	}

	/**
	 * Check if this container is the result of a security forward.
	 * @return bool
	 */
	public function isSecurityForwarded()
	{
		return $this->securityForwarded;
	}

	/**
	 * Mark this container as the result of a security forward.
	 * @param bool $flag
	 * @return void
	 */
	public function setSecurityForwarded($flag = true)
	{
		$this->securityForwarded = (bool)$flag;
	}

	/**
	 * Reset execution container state for FrankenPHP worker compatibility.
	 * Clears all request-specific properties that could leak between requests.
	 * @since      1.0.0
	 */
	#[\Override]
    public function reset(): void
	{
		// Reset execution state
		$this->contextName = null;
		$this->outputTypeName = null;
		$this->context = null;
		$this->validationManager = null;
		$this->requestMethod = null;
		$this->requestData = null;
		$this->globalRequestData = null;
		$this->arguments = null;
		$this->response = null;
		$this->outputType = null;
		$this->microtime = null;
		$this->actionInstance = null;
		$this->viewInstance = null;
		$this->initContext = null;
		$this->moduleName = null;
		$this->actionName = null;
		$this->viewModuleName = null;
		$this->viewName = null;
		$this->next = null;
		$this->securityForwarded = false;
		
		// Reset parent attribute holder state
		parent::clearAttributes();
	}
}

?>