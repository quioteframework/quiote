<?php
namespace Quiote\View;

use Quiote\Context;
use Quiote\Exception\QuioteException;
use Quiote\Renderer\Renderer;
use Quiote\Util\ParameterHolder;
use Symfony\Contracts\Service\ResetInterface;

/**
 * A template layer wraps information necessary to render a template.
 * @since      1.0.0
 * @version    1.0.0
 */
abstract class TemplateLayer extends ParameterHolder implements ResetInterface
{

	/**
	 * @var        ?string The name of the context, used to restore it on __wakeup.
	 */
	protected final $contextName = null;

	/**
	 * @var        ?Context The current Context.
	 */
	protected $context = null;

	/**
	 * @var        ?Renderer The Renderer instance to be used for this layer.
	 */
	protected $renderer = null;

	/**
	 * Slots are always {@see \Quiote\Execution\SlotRenderable}s: setSlot() is
	 * the only writer and rejects anything else outright (the legacy
	 * execution-container form is gone).
	 * @var        array<string, \Quiote\Execution\SlotRenderable> An associative array of slot renderables, keyed by slot name.
	 */
	protected $slots = [];

	/**
	 * Constructor.
	 * @param      array<string, mixed> $parameters Initial parameters.
	 * @since      1.0.0
	 */
	public function __construct(array $parameters = [])
	{
		parent::__construct(array_merge([
			'module' => null,
			'template' => null,
		], $parameters));
	}
	
	/**
	 * Get the name of this layer.
	 * @return     ?string The layer name, or null if not set.
	 * @throws     QuioteException If the "name" parameter is set but is not a string.
	 * @since      1.0.0
	 */
	public function getName(): ?string
	{
		return $this->requireStringParameter('name');
	}

	/**
	 * Set the name of this layer.
	 * @param      ?string $name The layer name.
	 * @return     void
	 * @since      1.0.0
	 */
	public function setName(?string $name): void
	{
		$this->setParameter('name', $name);
	}

	/**
	 * Get the name of the module this layer's template belongs to.
	 * @return     ?string The module name, or null if not set.
	 * @throws     QuioteException If the "module" parameter is set but is not a string.
	 * @since      1.0.0
	 */
	public function getModule(): ?string
	{
		return $this->requireStringParameter('module');
	}

	/**
	 * Set the name of the module this layer's template belongs to.
	 * @param      ?string $module The module name.
	 * @return     void
	 * @since      1.0.0
	 */
	public function setModule(?string $module): void
	{
		$this->setParameter('module', $module);
	}

	/**
	 * Get the name of the template to render.
	 * @return     ?string The template name, or null if not set.
	 * @throws     QuioteException If the "template" parameter is set but is not a string.
	 * @since      1.0.0
	 */
	public function getTemplate(): ?string
	{
		return $this->requireStringParameter('template');
	}

	/**
	 * Set the name of the template to render.
	 * @param      ?string $template The template name.
	 * @return     void
	 * @since      1.0.0
	 */
	public function setTemplate(?string $template): void
	{
		$this->setParameter('template', $template);
	}

	/**
	 * Check whether a template has been set.
	 * @return     bool True if a template is set, false otherwise.
	 * @since      1.0.0
	 */
	public function hasTemplate(): bool
	{
		return $this->getParameter('template') !== null;
	}

	/**
	 * Remove the configured template.
	 * @return     void
	 * @since      1.0.0
	 */
	public function removeTemplate(): void
	{
		$this->removeParameter('template');
	}

	/**
	 * Read a parameter that is expected to hold either a string or null.
	 * @param      string $name The parameter name.
	 * @return     ?string
	 * @throws     QuioteException If the parameter is set but is not a string.
	 * @since      1.0.0
	 */
	private function requireStringParameter(string $name): ?string
	{
		$value = $this->getParameter($name);
		if($value === null) {
			return null;
		}
		if(!is_string($value)) {
			throw new QuioteException('The "' . $name . '" parameter must be a string.');
		}
		return $value;
	}


	/**
	 * Pre-serialization callback.
	 * Will set the name of the context and exclude the instance from serializing.
	 * @since      1.0.0
	 */
	public function __sleep()
	{
		if($this->context !== null) {
			$this->contextName = $this->context->getName();
		}
		$arr = get_object_vars($this);
		unset($arr['context']);
		return array_keys($arr);
	}
	
	/**
	 * Post-unserialization callback.
	 * Will restore the context based on the names set by __sleep.
	 * @since      1.0.0
	 */
	public function __wakeup()
	{
		$this->context = Context::getInstance($this->contextName);
		unset($this->contextName);
	}
	
	/**
	 * Object cloning callback.
	 * Will clone each individual slot (which are execution containers).
	 * @since      1.0.0
	 */
	public function __clone()
	{
		foreach($this->slots as &$slot) {
			$slot = clone $slot;
		}
	}
	
	/**
	 * A convenience function that renders all slots and then the main template.
	 * Useful in your custom models to render an email, for example.
	 * @param      Renderer $renderer An optional renderer instance that will be used
	 *                           instead of the one set on the layer.
	 * @param      array<string, mixed> $attributes The template variables.
	 * @param      array<int|string, mixed> $moreAssigns Associative array of additional assigns.
	 * @return     string The rendered result.
	 * @since      1.0.0
	 */
	public function execute(?Renderer $renderer = null, array &$attributes = [], array &$moreAssigns = [])
	{
		$output = [];
		
		foreach($this->getSlots() as $slotName => $slotContainer) {
			$output[$slotName] = $slotContainer->getContent();
		}
		
		// Merge this layer's configured parameters into the template attributes
		// so templates (which expect $t) receive the values defined on the
		// layer, plus the backwards-compatible moduleName/actionName aliases
		// templates use. Layer parameters are defaults: anything the caller
		// already put in $attributes wins, which is what the array-union
		// semantics below preserve (a key already present is never overwritten,
		// null values included).
		//
		// Written as a direct merge rather than building a normalized copy of
		// the parameters first: the copy existed only to string-key the
		// parameter names for a type-safe union, and it was rebuilt on every
		// layer execution. Hoisting that copy to layer-init instead was
		// rejected -- ParameterHolder's by-ref setters (setParameterByRef(),
		// setParametersByRef()) let a caller mutate parameters after the fact
		// without going through any method, so a cached copy has no sound
		// invalidation point. $attributes is assigned into in place so that
		// templates (which receive $t by reference) keep mutating the same
		// underlying array instead of a copy.
		$layerParams = $this->getParameters();
		foreach ($layerParams as $paramKey => $paramValue) {
			$paramKey = (string) $paramKey;
			if (!array_key_exists($paramKey, $attributes)) {
				$attributes[$paramKey] = $paramValue;
			}
		}
		// The aliases derive from the *layer's* module/template, not from
		// whatever the caller may have passed under those keys, and only when
		// the layer doesn't declare the alias itself -- matching the order the
		// previous build-then-union did it in.
		if (isset($layerParams['module']) && !isset($layerParams['moduleName']) && !array_key_exists('moduleName', $attributes)) {
			$attributes['moduleName'] = $layerParams['module'];
		}
		if (isset($layerParams['template']) && !isset($layerParams['actionName']) && !array_key_exists('actionName', $attributes)) {
			$attributes['actionName'] = $layerParams['template'];
		}

		if($renderer === null) {
			$renderer = $this->getRenderer();
		}
		
		if(!($renderer instanceof Renderer)) {
			throw new QuioteException('No renderer has been set or given.');
		}
		
		return $renderer->render($this, $attributes, $output, $moreAssigns);
	}
	
	/**
	 * Initialize the layer.
	 * @param      Context $context The current Context instance.
	 * @param      array<string, mixed> $parameters An array of initialization parameters.
	 * @return     void
	 * @since      1.0.0
	 */
	public function initialize(Context $context, array $parameters = [])
	{
		$this->context = $context;
		
		$this->setParameters($parameters);
	}
	
	/**
	 * Set a renderer instance to use for this layer.
	 * @param      Renderer $renderer A renderer instance.
	 * @return     void
	 * @since      1.0.0
	 */
	public function setRenderer(Renderer $renderer)
	{
		$this->renderer = $renderer;
	}
	
	/**
	 * Get the renderer instance used for this layer.
	 * @return     ?Renderer A renderer instance.
	 * @since      1.0.0
	 */
	public function getRenderer()
	{
		return $this->renderer;
	}
	
	/**
	 * Set a slot that is rendered along with and available inside this layer.
	 * @param      string $name The name of the slot.
	 * @param      \Quiote\Execution\SlotRenderable|string $c Deprecated legacy container parameter now supports SlotRenderable only.
	 * @return     void
	 * @since      1.0.0
	 */
	public function setSlot($name, $c)
	{
		// Accept only SlotRenderable (container removed). Legacy containers eliminated.
		if(!$c instanceof \Quiote\Execution\SlotRenderable) {
			throw new \InvalidArgumentException('Slot must implement SlotRenderable');
		}
		$this->slots[$name] = $c;
	}
	
	/**
     * Get the execution container for a slot.
     * @param      string $name The name of the slot.
     * @return \Quiote\Execution\SlotRenderable|null The slot's renderable, or null if no slot with that name is set.
     * @since      1.0.0
     */
    public function getSlot($name)
	{
		if(isset($this->slots[$name])) {
			return $this->slots[$name];
		}

		return null;
	}
	
	/**
	 * Get all slots.
	 * @return     array<string, \Quiote\Execution\SlotRenderable> An associative array of slot renderables, keyed by slot name.
	 * @since      1.0.0
	 */
	public function getSlots()
	{
		return $this->slots;
	}
	
	/**
	 * Check whether or not a slot has been set.
	 * @param      string $name The name of the slot.
	 * @return     bool True if the slot exists, false otherwise.
	 * @since      1.0.0
	 */
	public function hasSlot($name)
	{
		return isset($this->slots[$name]);
	}
	
	/**
	 * Check if any slots have been set.
	 * @return     bool true if any slots are defined, false otherwise.
	 * @since      1.0.0
	 */
	public function hasSlots()
	{
		return (count($this->slots) > 0);
	}
	
	/**
	 * Remove a slot.
	 * @param      string $name The name of the slot.
	 * @return     void
	 * @since      1.0.0
	 */
	public function removeSlot($name)
	{
		if(isset($this->slots[$name])) {
			unset($this->slots[$name]);
		}
	}
	
	/**
	 * Get the full, resolved stream location name to the template resource.
	 * @return     ?string A PHP stream resource identifier, or null if no template is set.
	 * @throws     \Exception If the template could not be found.
	 * @since      1.0.0
	 */
	abstract public function getResourceStreamIdentifier();

	/**
	 * Drops the per-request rendering state so the layer can be reused.
	 *
	 * Releases the context and its name, the renderer and any registered slots,
	 * then delegates to the parent for the parameter state and finally unsets the
	 * layer name, template attributes and extra assigns.
	 */
	#[\Override]
    public function reset() : void
	{
		$this->context = null;
		$this->contextName = null;
		$this->renderer = null;
		$this->slots = [];
		
		parent::reset();
		
		unset($this->layer, $this->attributes, $this->moreAssigns);
	}
}

?>
