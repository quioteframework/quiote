<?php

// +---------------------------------------------------------------------------+
// | This file is part of the Agavi package.                                   |
// | Copyright (c) 2005-2011 the Agavi Project.                                |
// |                                                                           |
// | For the full copyright and license information, please view the LICENSE   |
// | file that was distributed with this source code. You can also view the    |
// | LICENSE file online at http://www.agavi.org/LICENSE.txt                   |
// |   vi: set noexpandtab:                                                    |
// |   Local Variables:                                                        |
// |   indent-tabs-mode: t                                                     |
// |   End:                                                                    |
// +---------------------------------------------------------------------------+
namespace Agavi\View;

use Agavi\AgaviContext;
use Agavi\Exception\AgaviException;
use Agavi\Renderer\AgaviRenderer;
use Agavi\Util\AgaviParameterHolder;
use Symfony\Contracts\Service\ResetInterface;

/**
 * A template layer wraps information necessary to render a template.
 *
 * @package    agavi
 * @subpackage view
 *
 * @author     David Zülke <dz@bitxtender.com>
 * @copyright  Authors
 * @copyright  The Agavi Project
 *
 * @since      0.11.0
 *
 * @version    $Id$
 */
abstract class AgaviTemplateLayer extends AgaviParameterHolder implements ResetInterface
{

	protected $contextName = null;
	
	/**
	 * @var        AgaviContext The current Context.
	 */
	protected $context = null;
	
	/**
	 * @var        AgaviRenderer The Renderer instance to be used for this layer.
	 */
	protected $renderer = null;
	
	/**
	 * @var        array An associative array of execution containers for slots.
	 */
	protected $slots = [];
	
	/**
	 * Constructor.
	 *
	 * @param      array Initial parameters.
	 *
	 * @author     David Zülke <dz@bitxtender.com>
	 * @since      0.11.0
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
	 *
	 * @param      string The method name.
	 * @param      array  The method arguments.
	 *
	 * @author     David Zülke <dz@bitxtender.com>
	 * @since      0.11.0
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
	 *
	 * Will set the name of the context and exclude the instance from serializing.
	 *
	 * @author     David Zülke <dz@bitxtender.com>
	 * @since      0.11.0
	 */
	public function __sleep()
	{
		$this->contextName = $this->context->getName();
		$arr = get_object_vars($this);
		unset($arr['context']);
		return array_keys($arr);
	}
	
	/**
	 * Post-unserialization callback.
	 *
	 * Will restore the context based on the names set by __sleep.
	 *
	 * @author     David Zülke <dz@bitxtender.com>
	 * @since      0.11.0
	 */
	public function __wakeup()
	{
		$this->context = AgaviContext::getInstance($this->contextName);
		unset($this->contextName);
	}
	
	/**
	 * Object cloning callback.
	 *
	 * Will clone each individual slot (which are execution containers).
	 *
	 * @author     David Zülke <dz@bitxtender.com>
	 * @since      0.11.0
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
	 *
	 * @param      AgaviRenderer An optional renderer instance that will be used
	 *                           instead of the one set on the layer.
	 *
	 * @return     string The rendered result.
	 *
	 * @author     David Zülke <dz@bitxtender.com>
	 * @since      0.11.0
	 */
	public function execute(?AgaviRenderer $renderer = null, array &$attributes = [], array &$moreAssigns = [])
	{
		$output = [];
		
		foreach($this->getSlots() as $slotName => $slotContainer) {
			if($slotContainer instanceof \Agavi\Execution\SlotRenderable) {
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
		
		if(!($renderer instanceof AgaviRenderer)) {
			throw new AgaviException('No renderer has been set or given.');
		}
		
		return $renderer->render($this, $attributes, $output, $moreAssigns);
	}
	
	/**
	 * Initialize the layer.
	 *
	 * @param      AgaviContext The current Context instance.
	 * @param      array        An array of initialization parameters.
	 *
	 * @author     David Zülke <dz@bitxtender.com>
	 * @since      0.11.0
	 */
	public function initialize(AgaviContext $context, array $parameters = [])
	{
		$this->context = $context;
		
		$this->setParameters($parameters);
	}
	
	/**
	 * Set a renderer instance to use for this layer.
	 *
	 * @param      AgaviRenderer A renderer instance.
	 *
	 * @author     David Zülke <dz@bitxtender.com>
	 * @since      0.11.0
	 */
	public function setRenderer(AgaviRenderer $renderer)
	{
		$this->renderer = $renderer;
	}
	
	/**
	 * Get the renderer instance used for this layer.
	 *
	 * @return     AgaviRenderer A renderer instance.
	 *
	 * @author     David Zülke <dz@bitxtender.com>
	 * @since      0.11.0
	 */
	public function getRenderer()
	{
		return $this->renderer;
	}
	
	/**
	 * Set a slot that is rendered along with and available inside this layer.
	 *
	 * @param      string                  The name of the slot.
	 * @param      \Agavi\Execution\SlotRenderable|string Deprecated legacy container parameter now supports SlotRenderable only.
	 *
	 * @author     David Zülke <dz@bitxtender.com>
	 * @since      0.11.0
	 */
	public function setSlot($name, $c)
	{
		// Accept only SlotRenderable (container removed). Legacy containers eliminated.
		if(!$c instanceof \Agavi\Execution\SlotRenderable) {
			throw new \InvalidArgumentException('Slot must implement SlotRenderable');
		}
		$this->slots[$name] = $c;
	}
	
	/**
	 * Get the execution container for a slot.
	 *
	 * @param      string The name of the slot.
	 *
	 * @return     AgaviExecutionContainer|\Agavi\Execution\SlotRenderable The slot's container or renderable surrogate.
	 *
	 * @author     David Zülke <dz@bitxtender.com>
	 * @since      0.11.0
	 */
	public function getSlot($name)
	{
		if(isset($this->slots[$name])) {
			return $this->slots[$name];
		}
	}
	
	/**
	 * Get all slots.
	 *
	 * @return     array An associative array of slot names and exec containers.
	 *
	 * @author     David Zülke <dz@bitxtender.com>
	 * @since      0.11.0
	 */
	public function getSlots()
	{
		return $this->slots;
	}
	
	/**
	 * Check whether or not a slot has been set.
	 *
	 * @param      string The name of the slot.
	 *
	 * @return     bool True if the slot exists, false otherwise.
	 *
	 * @author     David Zülke <dz@bitxtender.com>
	 * @since      0.11.0
	 */
	public function hasSlot($name)
	{
		return isset($this->slots[$name]);
	}
	
	/**
	 * Check if any slots have been set.
	 *
	 * @return     bool true if any slots are defined, false otherwise.
	 *
	 * @author     David Zülke <dz@bitxtender.com>
	 * @since      0.11.0
	 */
	public function hasSlots()
	{
		return (count($this->slots) > 0);
	}
	
	/**
	 * Remove a slot.
	 *
	 * @param      string The name of the slot.
	 *
	 * @author     David Zülke <dz@bitxtender.com>
	 * @since      0.11.0
	 */
	public function removeSlot($name)
	{
		if(isset($this->slots[$name])) {
			unset($this->slots[$name]);
		}
	}
	
	/**
	 * Get the full, resolved stream location name to the template resource.
	 *
	 * @return     string A PHP stream resource identifier.
	 *
	 * @throws     AgaviException If the template could not be found.
	 *
	 * @author     David Zülke <dz@bitxtender.com>
	 * @since      0.11.0
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
