<?php
namespace Quiote\Action;
/**
 * Action allows you to separate application and business logic from your
 * presentation. By providing a core set of methods used by the framework,
 * automation in the form of security and validation can occur.
 * @since      1.0.0
 * @version    1.0.0
 */

use Quiote\Config\Config;
use Quiote\Context;
use Quiote\Execution\ActionInitContext;
use Quiote\Request\RequestDtoRegistry;
use Quiote\Request\Compiler\RequestDtoScanner;
use Quiote\Request\WebRequest;
use Quiote\Validator\Compiler\Runtime\CompiledValidatorRegistry;
use Quiote\Validator\IValidatorContainer;
use Symfony\Contracts\Service\ResetInterface;

/**
 * Base class for an application's actions: the unit that runs the business
 * logic of one routed request and names the view that presents the result.
 *
 * An application subclasses this and implements one or more `execute<Token>()`
 * methods -- `executeRead()`, `executeWrite()`, `executeUpdate()`,
 * `executeRemove()` for the tokens {@see \Quiote\Execution\HttpMethodMapper}
 * derives from the HTTP verb, plus any custom token that mapping is extended
 * with -- or a single `execute()` handling every verb. None of them is declared
 * here: {@see \Quiote\Execution\ActionResolver} invokes the first one the
 * subclass actually implements and falls back to {@see getDefaultViewName()}
 * when it finds none. Such a method takes the {@see WebRequest} and returns the
 * view to render, either as a name or as a `[module, view]` pair naming a view
 * in another module.
 *
 * The framework builds the instance through the container, so constructor
 * dependencies are autowired, then calls {@see initialize()} with the
 * {@see ActionInitContext} for this dispatch and consults the hooks a subclass
 * may override: {@see isSecure()} and {@see getCredentials()} for
 * authorization, {@see isSimple()}, {@see registerValidators()} and
 * {@see validate()} for validation, {@see handleError()} for the path taken
 * when validation fails, and {@see isCacheable()}, {@see cacheTtlSeconds()} and
 * {@see cacheVaryByUser()} for output caching. Every one has a working default,
 * so a subclass overrides only what it needs.
 *
 * {@see registerValidators()} is the one default with real behaviour: it loads
 * the module's compiled or hand-written validator-builder file for this action
 * and registers the validators derived from a `#[MapRequest]` DTO parameter, so
 * an override that still wants those must call `parent::registerValidators()`.
 *
 * An instance serves a single dispatch, and {@see reset()} drops the
 * request-scoped context so a persistent worker never carries one request's
 * state into the next -- per-request data belongs in local variables or on the
 * request, not in properties that outlive it.
 */
abstract class Action implements ResetInterface
{
	use \Quiote\Util\InitContextAttributeAccess;

	/**
	 * @var ActionInitContext|null Lightweight initialization context (replaces legacy execution container).
	 */
	protected $initContext = null;

	/**
	 * @var        ?Context An Context instance.
	 */
	protected $context = null;

	/**
	 * Retrieve the current application context.
	 * @return     ?Context The current Context instance.
	 * @since      1.0.0
	 */
	public final function getContext()
	{
		return $this->context;
	}

	/**
     * Backward compatible accessor (legacy name) for the init context.
     * @return ?ActionInitContext
     */
    #[\Deprecated(message: 'Will be removed once all userland code migrates to getInitContext().')]
    public final function getContainer()
	{
		return $this->initContext;
	}

	/**
	 * Retrieve the initialization context for this action.
	 */
	public final function getInitContext(): ?ActionInitContext
	{
		return $this->initContext;
	}

	/**
	 * Retrieve the credential required to access this action.
	 * @return     mixed Data that indicates the level of security for this
	 *                   action.
	 * @since      1.0.0
	 */
	public function getCredentials()
	{
		return null;
	}

	/**
	 * Execute any post-validation error application logic.
	 * @param      WebRequest $rd The action's request data holder.
	 * @return     mixed A string containing the view name associated with this
	 *                   action.
	 *                   Or an array with the following indices:
	 *                   - The parent module of the view that will be executed.
	 *                   - The view that will be executed.
	 * @since      1.0.0
	 */
	public function handleError(WebRequest $rd)
	{
		return 'Error';
	}

	/**
	 * Initialize this action with a lightweight initialization context.
	 * @return void
	 */
	public function initialize(ActionInitContext $context)
	{
		$this->initContext = $context;
		$this->context = $context->getContext();
	}

	/**
	 * Indicates that this action requires security.
	 * @return     bool true, if this action requires security, otherwise false.
	 * @since      1.0.0
	 */
	public function isSecure()
	{
		return false;
	}

	/**
	 * Whether or not this action is "simple", i.e. doesn't use validation etc.
	 * @return     bool true, if this action should act in simple mode, or false.
	 * @since      1.0.0
	 */
	public function isSimple()
	{
		return false;
	}

	/**
	 * Indicates whether this action's output may be cached. Default false.
	 * Framework middleware will call this unconditionally (no method_exists guard).
	 */
	public function isCacheable(?string $outputType = null): bool
	{
		return false;
	}

	/**
	 * TTL (seconds) for cached content when isCacheable() returns true. Default null (framework default handling).
	 */
	public function cacheTtlSeconds(?string $outputType = null): ?int
	{
		return null;
	}

	/**
	 * Whether cached output for this action must be partitioned per user.
	 *
	 * Only consulted for a secure action (see {@see isSecure()}); a non-secure
	 * action has no authenticated identity to vary on and always shares one
	 * entry. Defaults to true, which is the only safe default: a secure action
	 * renders for a specific identity, so a shared cache entry hands one user's
	 * rendered page to the next. Override to false ONLY when the output is
	 * genuinely identical for every user who is allowed to reach it -- an
	 * authenticated-only page whose content does not depend on *which* user is
	 * looking at it.
	 *
	 * @param      ?string $outputType The output type being rendered, or null.
	 * @return     bool True to partition the cache per user.
	 * @since      3.1.1
	 */
	public function cacheVaryByUser(?string $outputType = null): bool
	{
		return true;
	}

	/**
	 * Manually register validators for this action.
	 *
	 * The default implementation loads a compiled/hand-written PHP
	 * validator-builder file for this module/action, if one exists at
	 * %core.module_dir%/{Module}/Validate/{Action}.generated.php (or
	 * the hand-written .php variant of the same name) -- see
	 * CompiledValidatorRegistry. This runs alongside (not instead of) any
	 * XML validators.xml for the same
	 * action; both add to the same ValidationManager instance.
	 *
	 * Override this (or register[Method]Validators(), e.g.
	 * registerWriteValidators()) to register validators directly in PHP
	 * via Quiote\Validator\Compiler\Runtime\ValidatorBuilder without a
	 * generated file at all -- call parent::registerValidators() first if
	 * you still want the file-based ones loaded too.
	 * @return     void
	 * @since      1.0.0
	 */
	public function registerValidators()
	{
		$initContext = $this->initContext;
		if ($initContext === null) {
			return;
		}

		$context = $this->context;
		if ($context === null) {
			return;
		}

		$validationManager = $initContext->getValidationManager();
		if (!$validationManager instanceof IValidatorContainer) {
			return;
		}

		(new CompiledValidatorRegistry())->apply(
			Config::getString('core.module_dir'),
			$initContext->getModuleName(),
			$initContext->getActionName(),
			$validationManager,
			$context,
			$initContext->getRequestMethod()
		);

		$this->registerMapRequestValidators($initContext, $validationManager, $context);
	}

	/**
	 * If the execute*() method matching the current request declares a
	 * #[Quiote\Request\Attribute\MapRequest] DTO parameter, register that
	 * DTO's derived validators onto the same ValidationManager -- see
	 * RequestDtoScanner. ActionResolver constructs and injects the actual
	 * DTO instance later, once validation (including these validators) has
	 * passed.
	 * @return void
	 */
	private function registerMapRequestValidators(ActionInitContext $initContext, IValidatorContainer $validationManager, Context $context): void
	{
		$methodName = 'execute' . ucfirst($initContext->getRequestMethod());
		$dtoClass = RequestDtoRegistry::dtoClassForMethod(static::class, $methodName);
		if ($dtoClass === null) {
			return;
		}

		RequestDtoScanner::registerValidators($dtoClass, $validationManager, $context, $initContext->getRequestMethod());
	}

	/**
	 * Manually validate files and parameters.
	 * @param      WebRequest $request The action's request data holder.
	 * @return     bool true, if validation completed successfully, otherwise
	 *                  false.
	 * @since      1.0.0
	 */
	public function validate(WebRequest $request)
	{
		return true;
	}

	/**
	 * Get the default View name if this Action doesn't serve the Request method.
	 * @return     mixed A string containing the view name associated with this
	 *                   action.
	 *                   Or an array with the following indices:
	 *                   - The parent module of the view that will be executed.
	 *                   - The view that will be executed.
	 * @since      1.0.0
	 */
	public function getDefaultViewName()
	{
		return 'Input';
	}

	/**
	 * Reset action state for FrankenPHP worker compatibility.
	 * Clears request-specific properties that could leak between requests.
	 * @since      1.0.0
	 */
	public function reset(): void
	{
		$this->initContext = null;
		$this->context = null;
	}
}

?>