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
use Quiote\Request\WebRequest;
use Quiote\Validator\Compiler\Runtime\CompiledValidatorRegistry;
use Quiote\Validator\IValidatorContainer;
use Symfony\Contracts\Service\ResetInterface;
abstract class Action implements ResetInterface
{
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
	 * @return     Context The current Context instance.
	 * @since      1.0.0
	 */
	public final function getContext()
	{
		return $this->context;
	}

	/**
     * Backward compatible accessor (legacy name) for the init context.
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
	 * @since      1.0.0
	 */
	public function registerValidators()
	{
		$initContext = $this->initContext;
		if ($initContext === null) {
			return;
		}

		$validationManager = $initContext->getValidationManager();
		if (!$validationManager instanceof IValidatorContainer) {
			return;
		}

		(new CompiledValidatorRegistry())->apply(
			Config::get('core.module_dir'),
			$initContext->getModuleName(),
			$initContext->getActionName(),
			$validationManager,
			$this->context,
			$initContext->getRequestMethod()
		);
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
	 * @see        AttributeHolder::clearAttributes()
	 * @since      1.0.0
	 */
	public function clearAttributes()
	{
		if($this->initContext instanceof \Quiote\Util\AttributeHolder) {
			$this->initContext->clearAttributes();
		}
	}

	/**
	 * @see        AttributeHolder::getAttribute()
	 * @since      1.0.0
	 */
	public function &getAttribute($name, $default = null)
	{
		if($this->initContext instanceof \Quiote\Util\AttributeHolder) {
			return $this->initContext->getAttribute($name, null, $default);
		}
		return $default;
	}

	/**
	 * @see        AttributeHolder::getAttributeNames()
	 * @since      1.0.0
	 */
	public function getAttributeNames()
	{
		if($this->initContext instanceof \Quiote\Util\AttributeHolder) {
			return $this->initContext->getAttributeNames();
		}
		return [];
	}

	/**
	 * @see        AttributeHolder::getAttributes()
	 * @since      1.0.0
	 */
	public function &getAttributes()
	{
		if($this->initContext instanceof \Quiote\Util\AttributeHolder) {
			return $this->initContext->getAttributes();
		}
		$empty = [];
		return $empty;
	}

	/**
	 * @see        AttributeHolder::hasAttribute()
	 * @since      1.0.0
	 */
	public function hasAttribute($name)
	{
		if($this->initContext instanceof \Quiote\Util\AttributeHolder) {
			return $this->initContext->hasAttribute($name);
		}
		return false;
	}

	/**
	 * @see        AttributeHolder::removeAttribute()
	 * @since      1.0.0
	 */
	public function &removeAttribute($name)
	{
		if($this->initContext instanceof \Quiote\Util\AttributeHolder) {
			return $this->initContext->removeAttribute($name);
		}
		$null = null; return $null;
	}

	/**
	 * @see        AttributeHolder::setAttribute()
	 * @since      1.0.0
	 */
	public function setAttribute($name, $value)
	{
		if($this->initContext instanceof \Quiote\Util\AttributeHolder) {
			$this->initContext->setAttribute($name, $value);
		}
	}

	/**
	 * @see        AttributeHolder::appendAttribute()
	 * @since      1.0.0
	 */
	public function appendAttribute($name, $value)
	{
		if($this->initContext instanceof \Quiote\Util\AttributeHolder) {
			$this->initContext->appendAttribute($name, $value);
		}
	}

	/**
	 * @see        AttributeHolder::setAttributeByRef()
	 * @since      1.0.0
	 */
	public function setAttributeByRef($name, &$value)
	{
		if($this->initContext instanceof \Quiote\Util\AttributeHolder) {
			$this->initContext->setAttributeByRef($name, $value);
		}
	}

	/**
	 * @see        AttributeHolder::appendAttributeByRef()
	 * @since      1.0.0
	 */
	public function appendAttributeByRef($name, &$value)
	{
		if($this->initContext instanceof \Quiote\Util\AttributeHolder) {
			$this->initContext->appendAttributeByRef($name, $value);
		}
	}

	/**
	 * @see        AttributeHolder::setAttributes()
	 * @since      1.0.0
	 */
	public function setAttributes(array $attributes)
	{
		if($this->initContext instanceof \Quiote\Util\AttributeHolder) {
			$this->initContext->setAttributes($attributes);
		}
	}

	/**
	 * @see        AttributeHolder::setAttributesByRef()
	 * @since      1.0.0
	 */
	public function setAttributesByRef(array &$attributes)
	{
		if($this->initContext instanceof \Quiote\Util\AttributeHolder) {
			$this->initContext->setAttributesByRef($attributes);
		}
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