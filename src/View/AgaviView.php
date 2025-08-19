<?php

// +---------------------------------------------------------------------------+
// | This file is part of the Agavi package.                                   |
// | Copyright (c) 2005-2011 the Agavi Project.                                |
// | Based on the Mojavi3 MVC Framework, Copyright (c) 2003-2005 Sean Kerr.    |
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

/**
 * A view represents the presentation layer of an action. Output can be
 * customized by supplying attributes, which a template can manipulate and
 * display.
 *
 * @package    agavi
 * @subpackage view
 *
 * @author     Sean Kerr <skerr@mojavi.org>
 * @author     David Zülke <dz@bitxtender.com>
 * @copyright  Authors
 * @copyright  The Agavi Project
 *
 * @since      0.9.0
 *
 * @version    $Id$
 */

use Agavi\AgaviContext;
use Agavi\Execution\ActionInitContext;
use Agavi\Execution\ViewInitContext;
use Agavi\Exception\AgaviViewException;
use Agavi\Renderer\AgaviRenderer;
use Agavi\Request\AgaviRequestDataHolder;
use Agavi\Execution\ForwardService;
use Symfony\Contracts\Service\ResetInterface;

abstract class AgaviView implements ResetInterface
{
	/**
	 * @since      0.9.0
	 */
	const NONE = null;

	/**
	 * @var ActionInitContext|ViewInitContext|null Initialization context (container-less pipeline).
	 */
	protected $initContext = null;

	/**
	 * @var        AgaviContext The AgaviContext instance this View belongs to.
	 */
	protected $context = null;

	/**
	 * @var        array An array of defined layers.
	 */
	protected $layers = [];

	/**
	 * Execute any presentation logic and set template attributes.
	 *
	 * @param      AgaviRequestDataHolder The action's request data holder.
	 *
	 * @return     mixed Array forward descriptor (legacy) or null.
	 *
	 * @author     Sean Kerr <skerr@mojavi.org>
	 * @author     David Zülke <dz@bitxtender.com>
	 * @since      0.9.0
	 */
	abstract function execute(AgaviRequestDataHolder $rd);

	/**
	 * Render all configured template layers (in order) and return concatenated output.
	 * Legacy compatibility: classic views called setupHtml()/loadLayout() and returned null;
	 * older pipeline later rendered layers implicitly. New middleware/dispatch paths invoke
	 * this on-demand when execute* returns null and layers are present.
	 */
	public function renderLayers(): string
	{
		if(empty($this->layers)) {
			if(getenv('AGAVI_DEBUG_VIEW')) { error_log('[AgaviView] renderLayers no layers for ' . get_class($this)); }
			return '';
		}
		$out = '';
		if(getenv('AGAVI_DEBUG_VIEW')) { error_log('[AgaviView] renderLayers count=' . count($this->layers) . ' view=' . get_class($this)); }
		foreach($this->layers as $layer) {
			try {
				$out .= (string)$layer->execute();
				if(getenv('AGAVI_DEBUG_VIEW')) { error_log('[AgaviView] layer executed name=' . $layer->getName() . ' len=' . strlen((string)$out)); }
			} catch(\Throwable $e) {
				// Fail soft: append diagnostic marker to aid debugging but keep rendering going
				$out .= '<!-- layer render error: ' . htmlspecialchars($e->getMessage(), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . ' -->';
				if(getenv('AGAVI_DEBUG_VIEW')) { error_log('[AgaviView] layer error name=' . $layer->getName() . ' msg=' . $e->getMessage()); }
			}
		}
		return $out;
	}

	/**
	 * Retrieve the current application context.
	 *
	 * @return     AgaviContext The current AgaviContext instance.
	 *
	 * @author     Sean Kerr <skerr@mojavi.org>
	 * @since      0.9.0
	 */
	public final function getContext()
	{
		return $this->context;
	}

	/**
	 * Backward compatibility accessor for legacy getContainer() usage.
	 * @deprecated Use getInitContext().
	 */
	public final function getContainer()
	{
		return $this->initContext;
	}

	public final function getInitContext(): ActionInitContext|ViewInitContext|null { return $this->initContext; }

	/**
	 * Retrieve the Response instance for this View.
	 *
	 * @return     AgaviResponse The Response instance.
	 *
	 * @author     David Zülke <dz@bitxtender.com>
	 * @since      0.11.0
	 */
	public final function getResponse()
	{
		return $this->initContext->getResponse();
	}

	/**
	 * Initialize this view.
	 *
	 * @param      ActionInitContext Initialization context.
	 *
	 * @author     David Zülke <dz@bitxtender.com>
	 * @since      0.9.0
	 */
	public function initialize(ActionInitContext|ViewInitContext $context)
	{
		$this->initContext = $context;
		$this->context = $context->getContext();
	}

	/**
	 * Resolve current AgaviOutputType regardless of whether container is legacy execution container
	 * or a lightweight ActionInitContext lacking getOutputType().
	 */
	protected function getCurrentOutputType(): \Agavi\Controller\AgaviOutputType
	{
		// Legacy execution container exposes getOutputType() returning AgaviOutputType directly.
		$name = $this->initContext instanceof ActionInitContext ? $this->initContext->getOutputTypeName() : null;
		return $this->context->getController()->getOutputType($name);
	}

	/**
	 * Convenience: unify access to resolved view module via ActionInitContext interface.
	 */
	protected function getResolvedViewModule(): ?string
	{
		return $this->initContext instanceof ActionInitContext ? $this->initContext->getViewModuleName() : null;
	}

	/**
	 * Convenience: unify access to resolved view name via ActionInitContext interface.
	 */
	protected function getResolvedViewName(): ?string
	{
		return $this->initContext instanceof ActionInitContext ? $this->initContext->getViewName() : null;
	}

	/**
	 * Create a new template layer object.
	 *
	 * This will automatically set the name of the layer, the current module, the
	 * current view name as the template, and the output type name.
	 *
	 * @param      string The class name of the AgaviTemplateLayer implementation.
	 * @param      string The name of the layer.
	 * @param      mixed  An optional name of the non-default renderer to use, or
	 *                    an AgaviRenderer instance to use.
	 *
	 * @return     AgaviTemplateLayer A template layer instance.
	 *
	 * @author     David Zülke <dz@bitxtender.com>
	 * @since      0.11.0
	 */
	public function createLayer($class, $name, $renderer = null)
	{
		$layer = new $class();
		if(!is_subclass_of($layer, 'Agavi\\View\\AgaviTemplateLayer')) {
			throw new AgaviViewException('Class "$class" is not a subclass of AgaviTemplateLayer');
		}
		$layer->initialize($this->context, ['name' => $name, 'module' => $this->initContext->getViewModuleName(), 'template' => $this->initContext->getViewName(), 'output_type' => $this->getCurrentOutputType()->getName()]);
		if($renderer instanceof AgaviRenderer) {
			$layer->setRenderer($renderer);
		} else {
			$layer->setRenderer($this->getCurrentOutputType()->getRenderer($renderer));
		}
		return $layer;
	}

	/**
	 * Append a layer to the list of layers.
	 *
	 * If no reference layer is given, the layer will be added to the end of the
	 * list.
	 *
	 * @param      AgaviTemplateLayer The layer to insert.
	 * @param      AgaviTemplateLayer An optional other layer to insert after.
	 *
	 * @return     AgaviTemplateLayer The template layer that was inserted.
	 *
	 * @author     David Zülke <dz@bitxtender.com>
	 * @since      0.11.0
	 */
	public function appendLayer(AgaviTemplateLayer $layer, ?AgaviTemplateLayer $otherLayer = null)
	{
		if($otherLayer !== null && !in_array($otherLayer, $this->layers, true)) {
			throw new AgaviViewException('Layer "' . $otherLayer->getName() . '" not in list');
		}

		if(($pos = array_search($layer, $this->layers, true)) !== false) {
			// given layer is already in the list, so we remove it first
			array_splice($this->layers, $pos, 1);
		}

		if($otherLayer === null) {
			$dest = count($this->layers);
		} elseif($otherLayer === $layer) {
			$dest = $pos;
		} else {
			$dest = array_search($otherLayer, $this->layers, true) + 1;
		}
		array_splice($this->layers, $dest, 0, [$layer]);

		return $layer;
	}

	/**
	 * Prepend a layer to the list of layers.
	 *
	 * If no reference layer is given, the layer will be added to the beginning of
	 * the list.
	 *
	 * @param      AgaviTemplateLayer The layer to insert.
	 * @param      AgaviTemplateLayer An optional other layer to insert before.
	 *
	 * @return     AgaviTemplateLayer The template layer that was inserted.
	 *
	 * @author     David Zülke <dz@bitxtender.com>
	 * @since      0.11.0
	 */
	public function prependLayer(AgaviTemplateLayer $layer, ?AgaviTemplateLayer $otherLayer = null)
	{
		if($otherLayer !== null && !in_array($otherLayer, $this->layers, true)) {
			throw new AgaviViewException('Layer "' . $otherLayer->getName() . '" not in list');
		}

		if(($pos = array_search($layer, $this->layers, true)) !== false) {
			// given layer is already in the list, so we remove it first
			array_splice($this->layers, $pos, 1);
		}

		if($otherLayer === null) {
			$dest = 0;
		} elseif($otherLayer === $layer) {
			$dest = $pos;
		} else {
			$dest = array_search($otherLayer, $this->layers, true);
		}
		array_splice($this->layers, $dest, 0, [$layer]);

		return $layer;
	}

	/**
	 * Remove a layer from the list.
	 *
	 * @param      AgaviTemplateLayer The layer to remove.
	 *
	 * @author     David Zülke <dz@bitxtender.com>
	 * @since      0.11.0
	 */
	public function removeLayer(AgaviTemplateLayer $layer)
	{
		if(($pos = array_search($layer, $this->layers, true)) === false) {
			throw new AgaviViewException('Layer "' . $layer->getName() . '" not in list');
		}
		array_splice($this->layers, $pos, 1);
	}

	/**
	 * Remove all layers from the list.
	 *
	 * @author     David Zülke <dz@bitxtender.com>
	 * @since      0.11.0
	 */
	public function clearLayers()
	{
		$this->layers = [];
	}

	/**
	 * Retrieve a layer from the list.
	 *
	 * @param      string The name of the layer.
	 *
	 * @return     AgaviTemplateLayer The layer instance, or null if not found.
	 *
	 * @author     David Zülke <dz@bitxtender.com>
	 * @since      0.11.0
	 */
	public function getLayer($name)
	{
		foreach($this->layers as $layer) {
			if($name == $layer->getName()) {
				return $layer;
			}
		}
	}

	/**
	 * Get all layers from the list.
	 *
	 * @return     array An array of template layer instances.
	 *
	 * @author     David Zülke <dz@bitxtender.com>
	 * @since      0.11.0
	 */
	public function getLayers()
	{
		return $this->layers;
	}

	/**
	 * Load a pre-configured layout.
	 *
	 * If no layout name is given, the default layout will be used.
	 *
	 * @param      string The (optional) name of the layout.
	 *
	 * @return     array An array of parameters set for the layout.
	 *
	 * @throws     AgaviException If the layout doesn't exist.
	 *
	 * @author     David Zülke <dz@bitxtender.com>
	 * @since      0.11.0
	 */
	public function loadLayout($layoutName = null)
	{
		$layout = $this->getCurrentOutputType()->getLayout($layoutName);

		$this->clearLayers();

		foreach($layout['layers'] as $name => $layer) {
			$l = $this->createLayer($layer['class'], $name, $layer['renderer']);
			$l->setParameters($layer['parameters']);
			foreach($layer['slots'] as $slotName => $slot) {
				// Use new slot content API (container-less). Legacy createSlotContainer() is deprecated.
				// request_method currently ignored in fast path; legacy path handled it for HTTP verb overrides.
				$l->setSlot($slotName, $this->createSlotContent($slot['module'], $slot['action'], $slot['parameters'], $slot['output_type']));
			}
			$this->appendLayer($l);
		}
		
		return $layout['parameters'];
	}

	/**
	 * Creates a new container with the same output type and request method as
	 * this view's container.
	 *
	 * This container will have a parameter called 'is_slot' set to true.
	 *
	 * @param      string The name of the module.
	 * @param      string The name of the action.
	 * @param      mixed  An AgaviRequestDataHolder instance with additional
	 *                    request arguments or an array of request parameters.
	 * @param      string Optional name of an initial output type to set.
	 * @param      string Optional name of the request method to be used in this
	 *                    container.
	 *
	 * @return     \Agavi\Execution\SlotContent Slot content value object.
	 * @deprecated Legacy container API removed; returns SlotContent.
	 *
	 * @author     David Zülke <dz@bitxtender.com>
	 * @since      0.11.0
	 */
	public function createSlotContainer($moduleName, $actionName, $arguments = null, $outputType = null, $requestMethod = null)
	{
		\Agavi\Util\DeprecationSilencer::triggerOnce(__METHOD__ . ' is removed: returning SlotContent value object.');
		return $this->createSlotContent($moduleName, $actionName, $arguments, $outputType);
	}

	/**
	 * Convenience helper: directly render a slot and return its string content.
	 *
	 * This bypasses legacy container creation and uses the SlotDispatcher fast path.
	 * Arguments may be array or AgaviRequestDataHolder (array preferred).
	 */
	public function renderSlot(string $moduleName, string $actionName, $arguments = null, ?string $outputType = null): string
	{
		// Reuse createSlotContent (new API) to avoid duplication.
		$slotContent = $this->createSlotContent($moduleName, $actionName, $arguments, $outputType);
		return $slotContent->getContent();
	}

	/**
	 * New API returning SlotContent value object explicitly, bypassing container wrapper regardless of flag.
	 */
	public function createSlotContent(string $moduleName, string $actionName, $arguments = null, $outputType = null): \Agavi\Execution\SlotContent
	{
		$parameters = [];
		if ($arguments instanceof AgaviRequestDataHolder) {
			$parameters = $arguments->getParameters();
		} elseif (is_array($arguments)) {
			$parameters = $arguments;
		} elseif ($arguments !== null) {
			throw new \RuntimeException('Unsupported slot argument type');
		}
		$dispatcher = $this->context->getSlotDispatcher();
		$parentRequest = $this->context->getCurrentPsrRequest();
		if(!$parentRequest) { throw new \RuntimeException('No current PSR request available for slot dispatch'); }
		$slotRequest = \Agavi\Execution\SlotRequestFactory::create($parentRequest, $moduleName, $actionName, $parameters, $outputType);
		return $dispatcher->dispatchSlotContent($slotRequest, $moduleName, $actionName, $parameters, $outputType);
	}

	/**
	 * Creates a new container with the same output type and request method as
	 * this view's container.
	 *
	 * This container will have a parameter called 'is_forward' set to true.
	 *
	 * @param      string The name of the module.
	 * @param      string The name of the action.
	 * @param      mixed  An AgaviRequestDataHolder instance with additional
	 *                    request arguments or an array of request parameters.
	 * @param      string Optional name of an initial output type to set.
	 * @param      string Optional name of the request method to be used in this
	 *                    container.
	 *
	 * @return     mixed Forward descriptor or content (string) depending on usage.
	 *
	 * @author     David Zülke <dz@bitxtender.com>
	 * @since      0.11.0
	 */
	public function createForwardContainer($moduleName, $actionName, $arguments = null, $outputType = null, $requestMethod = null)
	{
		\Agavi\Util\DeprecationSilencer::triggerOnce(__METHOD__ . ' removed under container-less pipeline; returning SlotContent.');
		return $this->createSlotContent($moduleName, $actionName, $arguments, $outputType);
	}

	/**
	 * Render a system forward (login or secure) using ForwardService without creating a forward container.
	 * Falls back to legacy createForwardContainer if ForwardService fails.
	 */
	public function renderSystemForward(string $name, ?AgaviRequestDataHolder $arguments = null, ?string $outputType = null): string
	{
		$name = strtolower($name);
		if(!in_array($name, ['login','secure'], true)) {
			throw new \InvalidArgumentException('Unsupported system forward name: ' . $name);
		}
		try {
			$fs = new ForwardService($this->context->getController());
			[$view,$vm,$vn,$content] = $fs->createSystemForwardView($name, $outputType ?? $this->context->getController()->getOutputType()->getName(), $arguments ?? new AgaviRequestDataHolder());
			return (string)$content;
		} catch(\Throwable $e) {
			// Fallback: legacy forward container path (will be removed)
			@trigger_error('ForwardService failed, falling back to legacy forward container: ' . $e->getMessage(), E_USER_DEPRECATED);
			$fc = $this->createForwardContainer(ucfirst($name), 'Success', $arguments, $outputType);
			$view = $fc->getViewInstance();
			$rd = $fc->getRequestData() ?? new AgaviRequestDataHolder();
			$method = 'execute' . ucfirst($fc->getOutputType()->getName());
			if(!is_callable([$view,$method])) { $method = 'execute'; }
			$res = $view->$method($rd);
			if($res !== null) { return (string)$res; }
			if(method_exists($view,'getLayers') && $view->getLayers()) { return $view->renderLayers(); }
			return '';
		}
	}

	/**
	 * @see        AgaviAttributeHolder::setAttributesByRef()
	 *
	 * @author     David Zülke <dz@bitxtender.com>
	 * @since      0.9.0
	 */
	public function clearAttributes()
	{
		if($this->initContext instanceof \Agavi\Util\AgaviAttributeHolder) { $this->initContext->clearAttributes(); }
	}

	/**
	 * @see        AgaviAttributeHolder::setAttributesByRef()
	 *
	 * @author     David Zülke <dz@bitxtender.com>
	 * @since      0.9.0
	 */
	public function &getAttribute($name, $default = null)
	{
		if($this->initContext instanceof \Agavi\Util\AgaviAttributeHolder) { return $this->initContext->getAttribute($name, null, $default); }
		$null = null; return $null;
	}

	/**
	 * @see        AgaviAttributeHolder::setAttributesByRef()
	 *
	 * @author     David Zülke <dz@bitxtender.com>
	 * @since      0.9.0
	 */
	public function getAttributeNames()
	{
		if($this->initContext instanceof \Agavi\Util\AgaviAttributeHolder) { return $this->initContext->getAttributeNames(); }
		return [];
	}

	/**
	 * @see        AgaviAttributeHolder::setAttributesByRef()
	 *
	 * @author     David Zülke <dz@bitxtender.com>
	 * @since      0.11.0
	 */
	public function &getAttributes()
	{
		if($this->initContext instanceof \Agavi\Util\AgaviAttributeHolder) { return $this->initContext->getAttributes(); }
		$empty = []; return $empty;
	}

	/**
	 * @see        AgaviAttributeHolder::setAttributesByRef()
	 *
	 * @author     David Zülke <dz@bitxtender.com>
	 * @since      0.9.0
	 */
	public function hasAttribute($name)
	{
		if($this->initContext instanceof \Agavi\Util\AgaviAttributeHolder) { return $this->initContext->hasAttribute($name); }
		return false;
	}

	/**
	 * @see        AgaviAttributeHolder::setAttributesByRef()
	 *
	 * @author     David Zülke <dz@bitxtender.com>
	 * @since      0.9.0
	 */
	public function &removeAttribute($name)
	{
		if($this->initContext instanceof \Agavi\Util\AgaviAttributeHolder) { return $this->initContext->removeAttribute($name); }
		$null = null; return $null;
	}

	/**
	 * @see        AgaviAttributeHolder::setAttributesByRef()
	 *
	 * @author     David Zülke <dz@bitxtender.com>
	 * @since      0.9.0
	 */
	public function setAttribute($name, $value)
	{
		if($this->initContext instanceof \Agavi\Execution\ViewInitContext) {\Agavi\Util\DeprecationSilencer::triggerOnce('setAttribute() ignored: immutable ViewInitContext snapshot'); return; }
		if($this->initContext instanceof \Agavi\Util\AgaviAttributeHolder) { $this->initContext->setAttribute($name,$value); }
	}

	/**
	 * @see        AgaviAttributeHolder::setAttributesByRef()
	 *
	 * @author     David Zülke <dz@bitxtender.com>
	 * @since      0.10.0
	 */
	public function appendAttribute($name, $value)
	{
		if($this->initContext instanceof \Agavi\Execution\ViewInitContext) { \Agavi\Util\DeprecationSilencer::triggerOnce('appendAttribute() ignored under immutable snapshot'); return; }
		if($this->initContext instanceof \Agavi\Util\AgaviAttributeHolder) { $this->initContext->appendAttribute($name,$value); }
	}

	/**
	 * @see        AgaviAttributeHolder::setAttributesByRef()
	 *
	 * @author     David Zülke <dz@bitxtender.com>
	 * @since      0.9.0
	 */
	public function setAttributeByRef($name, &$value)
	{
		if($this->initContext instanceof \Agavi\Execution\ViewInitContext) { \Agavi\Util\DeprecationSilencer::triggerOnce('setAttributeByRef() ignored under immutable snapshot'); return; }
		if($this->initContext instanceof \Agavi\Util\AgaviAttributeHolder) { $this->initContext->setAttributeByRef($name,$value); }
	}

	/**
	 * @see        AgaviAttributeHolder::setAttributesByRef()
	 *
	 * @author     David Zülke <dz@bitxtender.com>
	 * @since      0.10.0
	 */
	public function appendAttributeByRef($name, &$value)
	{
		if($this->initContext instanceof \Agavi\Execution\ViewInitContext) { \Agavi\Util\DeprecationSilencer::triggerOnce('appendAttributeByRef() ignored under immutable snapshot'); return; }
		if($this->initContext instanceof \Agavi\Util\AgaviAttributeHolder) { $this->initContext->appendAttributeByRef($name,$value); }
	}

	/**
	 * @see        AgaviAttributeHolder::setAttributesByRef()
	 *
	 * @author     David Zülke <dz@bitxtender.com>
	 * @since      0.9.0
	 */
	public function setAttributes(array $attributes)
	{
		if($this->initContext instanceof \Agavi\Execution\ViewInitContext) { \Agavi\Util\DeprecationSilencer::triggerOnce('setAttributes() ignored under immutable snapshot'); return; }
		if($this->initContext instanceof \Agavi\Util\AgaviAttributeHolder) { $this->initContext->setAttributes($attributes); }
	}

	/**
	 * @see        AgaviAttributeHolder::setAttributesByRef()
	 *
	 * @author     David Zülke <dz@bitxtender.com>
	 * @since      0.9.0
	 */
	public function setAttributesByRef(array &$attributes)
	{
		if($this->initContext instanceof \Agavi\Execution\ViewInitContext) { \Agavi\Util\DeprecationSilencer::triggerOnce('setAttributesByRef() ignored under immutable snapshot'); return; }
		if($this->initContext instanceof \Agavi\Util\AgaviAttributeHolder) { $this->initContext->setAttributesByRef($attributes); }
	}

	public function reset() : void
	{
		$this->initContext = null;
		$this->context = null;
		$this->layers = [];
	}
}

?>