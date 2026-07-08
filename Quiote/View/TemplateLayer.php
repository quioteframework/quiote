<?php
namespace Quiote\View;

use Quiote\Context;
use Quiote\Exception\QuioteException;
use Quiote\Renderer\Renderer;
use Quiote\Util\ParameterHolder;
use Symfony\Contracts\Service\ResetInterface;

/**
 * A template layer wraps information necessary to render a template.
 * @method string getName() Magic accessor (via __call()) for the 'name' parameter.
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
	 * @var        array<string, mixed> An associative array of execution containers for slots.
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
	 * Convenience overload for accessing parameters using a method.
	 * @param      string $name The method name.
	 * @param      array<int, mixed> $args The method arguments.
	 * @return     mixed
	 * @since      1.0.0
	 */
	public function __call($name, array $args)
	{
		$matches = [];
		if(preg_match('/^(has|get|set|remove)(.+)$/', (string) $name, $matches)) {
			$method = $matches[1] . 'Parameter';
			// transform "FooBarBaz" (from "setTemplateDir" etc) to "foo_bar_baz"
			$parameter = strtolower((string) preg_replace('/((?<!\A)[A-Z])/u', '_$1', $matches[2]));
			return call_user_func_array([$this, $method], array_merge([$parameter], $args));
		}
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
			if($slotContainer instanceof \Quiote\Execution\SlotRenderable) {
				$output[$slotName] = $slotContainer->getContent();
				continue;
			}
			$slotResponse = $slotContainer->execute();
			$output[$slotName] = $slotResponse->getContent();
		}
		
		// Merge this layer's configured parameters into the template attributes
		// so templates (which expect $t) receive the values defined on the
		// layer. Also provide backwards-compatible aliases used by
		// templates: moduleName/actionName.
		$layerParams = $this->getParameters();
		// Parameter names are always strings in practice; normalize the key type so
		// merging with $attributes (a string-keyed map) is type-safe.
		$layerParams = array_combine(array_map('strval', array_keys($layerParams)), $layerParams);
		if (isset($layerParams['module']) && !isset($layerParams['moduleName'])) {
			$layerParams['moduleName'] = $layerParams['module'];
		}
		if (isset($layerParams['template']) && !isset($layerParams['actionName'])) {
			$layerParams['actionName'] = $layerParams['template'];
		}
	// Merge: layer parameters provide defaults which can be overridden by
	// the caller via the $attributes argument. Use in-place addition to
	// preserve the reference of the $attributes array passed in so that
	// templates (which receive $t by reference) continue to mutate the
	// same underlying array instead of getting a copy.
	$attributes += $layerParams;

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
     * @return \Quiote\Controller\ExecutionContainer|\Quiote\Execution\SlotRenderable|null The slot's container or renderable surrogate, or null if no slot with that name is set.
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
	 * @return     array<string, mixed> An associative array of slot names and exec containers.
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
