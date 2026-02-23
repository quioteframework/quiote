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
use Agavi\Execution\ForwardService;
use Agavi\Request\AgaviWebRequest;
use Symfony\Contracts\Service\ResetInterface;
use Agavi\Logging\AgaviDebugLogger;
use Agavi\Response\AgaviWebResponse;

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
	 * @var array|null Mutable attribute store for this view. Populated from
	 *                action attribute snapshot (ImmutableViewInitContext) or
	 *                from an attribute holder. Ensures view-set attributes
	 *                are visible to the renderer via getAttributes().
	 */
	protected $localAttributes = null;

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
	 * @param      AgaviWebRequest The action's request data holder.
	 *
	 * @return     mixed Array forward descriptor (legacy) or null.
	 *
	 * @author     Sean Kerr <skerr@mojavi.org>
	 * @author     David Zülke <dz@bitxtender.com>
	 * @since      0.9.0
	 */
	abstract function execute(AgaviWebRequest $rd);

	/**
	 * Render all configured template layers (in order) and return concatenated output.
	 * Legacy compatibility: classic views called setupHtml()/loadLayout() and returned null;
	 * older pipeline later rendered layers implicitly. New middleware/dispatch paths invoke
	 * this on-demand when execute* returns null and layers are present.
	 */
	public function renderLayers(): string
	{
		if (empty($this->layers)) {
			if (\Agavi\Util\DebugFlags::$view) {
				AgaviDebugLogger::debug('[AgaviView] renderLayers no layers for ' . get_class($this), $this->getContext());
			}
			return '';
		}
		$out = '';
		if (\Agavi\Util\DebugFlags::$view) {
			AgaviDebugLogger::debug('[AgaviView] renderLayers count=' . count($this->layers) . ' view=' . get_class($this), $this->getContext());
		}
		foreach ($this->layers as $layer) {
			$attrsSnapshot = $this->getAttributes();
			$attrsForLayer = is_array($attrsSnapshot) ? $attrsSnapshot : (array)$attrsSnapshot;
			$attrsForLayer['inner'] = $out;
			$out = (string)$layer->execute(null, $attrsForLayer); // exceptions bubble naturally now
			if (\Agavi\Util\DebugFlags::$view) {
				AgaviDebugLogger::debug('[AgaviView] layer executed name=' . $layer->getName() . ' len=' . strlen($out), $this->getContext());
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

	public final function getInitContext(): ActionInitContext|ViewInitContext|null
	{
		return $this->initContext;
	}

	/**
	 * Retrieve the Response instance for this View.
	 *
	 * @return     AgaviWebResponse The Response instance.
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

		// Prepare a mutable attributes store for the view. If the init context
		// carries an action attribute snapshot (ImmutableViewInitContext), use
		// that as the starting point so action-set attributes are visible to
		// templates via renderer's $t. View-set attributes will overwrite those
		// values in this local store.
		try {
			if ($context instanceof \Agavi\Execution\ImmutableViewInitContext) {
				$attrs = $context->getActionAttributes();
				$this->localAttributes = is_array($attrs) ? array_merge([], $attrs) : [];
			} elseif ($context instanceof \Agavi\Util\AgaviAttributeHolder) {
				$this->localAttributes = $context->getAttributes();
			} else {
				$this->localAttributes = [];
			}
		} catch (\Throwable $e) {
			$this->localAttributes = [];
		}
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
		if (!is_subclass_of($layer, 'Agavi\\View\\AgaviTemplateLayer')) {
			throw new AgaviViewException('Class "$class" is not a subclass of AgaviTemplateLayer');
		}
		// Try to resolve module/template names from the init context. Some
		// container-less paths don't expose these, so fall back to view-local
		// attributes when available.
		$moduleParam = null;
		$templateParam = null;
		if ($this->initContext instanceof ActionInitContext) {
			// Full action init context: use canonical view names
			$moduleParam = $this->initContext->getViewModuleName();
			$templateParam = $this->initContext->getViewName();
		} elseif ($this->initContext !== null) {
			// Container-less immutable init context (ViewInitContext or similar)
			// may still provide action/module names via a different API.
			// Prefer explicit view identifiers when available on the init context
			if (method_exists($this->initContext, 'getViewName')) {
				$templateParam = $this->initContext->getViewName();
			} elseif (method_exists($this->initContext, 'getActionName')) {
				$templateParam = $this->initContext->getActionName();
			}
			if (method_exists($this->initContext, 'getViewModuleName')) {
				$moduleParam = $this->initContext->getViewModuleName();
			} elseif (method_exists($this->initContext, 'getActionModuleName')) {
				$moduleParam = $this->initContext->getActionModuleName();
			}
			// If still not resolved, fall back to view attributes
			if (($moduleParam === null || $templateParam === null) && method_exists($this, 'getAttribute')) {
				$am = $this->getAttribute('moduleName', null);
				$aa = $this->getAttribute('actionName', null);
				if ($am !== null) {
					$moduleParam = $am;
				}
				if ($aa !== null) {
					$templateParam = $aa;
				}
			}
		} else {
			if (method_exists($this, 'getAttribute')) {
				$moduleParam = $this->getAttribute('moduleName', null);
				$templateParam = $this->getAttribute('actionName', null);
			}
		}
		// If templateParam is not set, prefer the resolved view name (container-less paths)
		if (empty($templateParam)) {
			$templateParam = $this->getResolvedViewName();
		}
		$layer->initialize($this->context, ['name' => $name, 'module' => $moduleParam, 'template' => $templateParam, 'output_type' => $this->getCurrentOutputType()->getName()]);
		if ($renderer instanceof AgaviRenderer) {
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
		if ($otherLayer !== null && !in_array($otherLayer, $this->layers, true)) {
			throw new AgaviViewException('Layer "' . $otherLayer->getName() . '" not in list');
		}

		if (($pos = array_search($layer, $this->layers, true)) !== false) {
			// given layer is already in the list, so we remove it first
			array_splice($this->layers, $pos, 1);
		}

		if ($otherLayer === null) {
			$dest = count($this->layers);
		} elseif ($otherLayer === $layer) {
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
		if ($otherLayer !== null && !in_array($otherLayer, $this->layers, true)) {
			throw new AgaviViewException('Layer "' . $otherLayer->getName() . '" not in list');
		}

		if (($pos = array_search($layer, $this->layers, true)) !== false) {
			// given layer is already in the list, so we remove it first
			array_splice($this->layers, $pos, 1);
		}

		if ($otherLayer === null) {
			$dest = 0;
		} elseif ($otherLayer === $layer) {
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
		if (($pos = array_search($layer, $this->layers, true)) === false) {
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
	 * @return     ?AgaviFileTemplateLayer|?AgaviTemplateLayer The layer instance, or null if not found.
	 *
	 * @author     David Zülke <dz@bitxtender.com>
	 * @since      0.11.0
	 */
	public function getLayer($name)
	{
		foreach ($this->layers as $layer) {
			if ($name == $layer->getName()) {
				return $layer;
			}
		}
		return null;
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

		foreach ($layout['layers'] as $name => $layer) {
			$l = $this->createLayer($layer['class'], $name, $layer['renderer']);
			$l->setParameters($layer['parameters']);
			foreach ($layer['slots'] as $slotName => $slot) {
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
	 * @param      mixed  Array of request parameters.
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
	 * Arguments is array
	 */
	public function renderSlot(string $moduleName, string $actionName, ?array $arguments = null, ?string $outputType = null): string
	{
		// Reuse createSlotContent (new API) to avoid duplication.
		$slotContent = $this->createSlotContent($moduleName, $actionName, $arguments, $outputType);
		return $slotContent->getContent();
	}

	/**
	 * New API returning SlotContent value object explicitly, bypassing container wrapper regardless of flag.
	 */
	public function createSlotContent(string $moduleName, string $actionName, $arguments = null, $outputType = null): \Agavi\Execution\SlotRenderable
	{
		$parameters = [];
		if ($arguments instanceof AgaviWebRequest) {
			$parameters = $arguments->getParameters();
		} elseif (is_array($arguments)) {
			$parameters = $arguments;
		} elseif ($arguments !== null) {
			throw new \RuntimeException('Unsupported slot argument type');
		}
		// Defensive short-circuit: if a layout slot points to the same module/action
		// as the current view, rendering it would reload the same layout and
		// potentially cause unbounded recursion. Return an empty SlotContent
		// immediately to avoid self-referential slot loops. This preserves the
		// slot metadata but produces no content.
		$currentModule = $this->getResolvedViewModule();
		$currentAction = $this->getResolvedViewName();
		// Some codepaths (legacy/container-less) populate the module/action as
		// attributes on the view. Try those as
		// fallback so the short-circuit works even when initContext doesn't
		// expose resolved names yet.
		if (($currentModule === null || $currentAction === null) && method_exists($this, 'getAttribute')) {
			try {
				$am = $this->getAttribute('moduleName', null);
				$aa = $this->getAttribute('actionName', null);
				if ($am !== null && $aa !== null) {
					$currentModule = $am;
					$currentAction = $aa;
				}
			} catch (\Throwable $e) {
				// ignore attribute lookup failures and fall back to resolved names
			}
		}
		if (
			$currentModule !== null && $currentAction !== null &&
			strtolower((string)$currentModule) === strtolower($moduleName) &&
			strtolower((string)$currentAction) === strtolower($actionName)
		) {
			return new \Agavi\Execution\SlotContent($moduleName, $actionName, $outputType, '', is_array($arguments) ? $arguments : []);
		}

		// Defer execution: return a SlotRenderable that will dispatch the slot
		// only when the renderer actually requests the content. This avoids
		// eager dispatch during layout construction and prevents recursion.
		$dispatcher = $this->context->getSlotDispatcher();
		$parentRequest = $this->context->getCurrentPsrRequest();
		if (!$parentRequest) {
			throw new \RuntimeException('No current PSR request available for slot dispatch');
		}
		// Instead of dispatching now, return a deferred renderable that will
		// dispatch during template rendering.
		return new \Agavi\Execution\DeferredSlotRenderable($this->context, $moduleName, $actionName, $parameters, $outputType);
	}

	/**
	 * Creates a new container with the same output type and request method as
	 * this view's container.
	 *
	 * This container will have a parameter called 'is_forward' set to true.
	 *
	 * @param      string The name of the module.
	 * @param      string The name of the action.
	 * @param      mixed  An array of request parameters.
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
	public function renderSystemForward(string $name, ?AgaviWebRequest $arguments = null, ?string $outputType = null): string
	{
		$name = strtolower($name);
		if (!in_array($name, ['login', 'secure'], true)) {
			throw new \InvalidArgumentException('Unsupported system forward name: ' . $name);
		}
		// Reuse canonical request instance when no explicit arguments provided.
		if ($arguments === null) {
			try {
				$arguments = $this->context->getRequest();
			} catch (\Throwable) {
				$arguments = null;
			}
			if (!($arguments instanceof AgaviWebRequest)) {
				throw new \RuntimeException('Canonical AgaviWebRequest missing for system forward');
			}
		}
		try {
			$fs = new ForwardService($this->context->getController());
			[$view, $vm, $vn, $content] = $fs->createSystemForwardView($name, $outputType ?? $this->context->getController()->getOutputType()->getName(), $arguments);
			return (string)$content;
		} catch (\Throwable $e) {
			// Fallback: legacy forward container path (will be removed). Ensure we also reuse canonical request.
			@trigger_error('ForwardService failed, falling back to legacy forward container: ' . $e->getMessage(), E_USER_DEPRECATED);
			$fc = $this->createForwardContainer(ucfirst($name), 'Success', $arguments, $outputType);
			$view = $fc->getViewInstance();
			$rd = $fc->getRequestData();
			if (!($rd instanceof AgaviWebRequest)) {
				try {
					$rd = $this->context->getRequest();
				} catch (\Throwable) {
					$rd = null;
				}
				if (!($rd instanceof AgaviWebRequest)) {
					throw new \RuntimeException('Canonical AgaviWebRequest missing in system forward fallback');
				}
			}
			$method = 'execute' . ucfirst($fc->getOutputType()->getName());
			if (!is_callable([$view, $method])) {
				$method = 'execute';
			}
			$res = $view->$method($rd);
			if ($res !== null) {
				return (string)$res;
			}
			if (method_exists($view, 'getLayers') && $view->getLayers()) {
				return $view->renderLayers();
			}
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
		if ($this->initContext instanceof \Agavi\Util\AgaviAttributeHolder) {
			$this->initContext->clearAttributes();
		}
	}

	/**
	 * @see        AgaviAttributeHolder::setAttributesByRef()
	 *
	 * @author     David Zülke <dz@bitxtender.com>
	 * @since      0.9.0
	 */
	public function &getAttribute($name, $default = null)
	{
		if ($this->initContext instanceof \Agavi\Util\AgaviAttributeHolder) {
			return $this->initContext->getAttribute($name, null, $default);
		}
		$null = null;
		return $null;
	}

	/**
	 * @see        AgaviAttributeHolder::setAttributesByRef()
	 *
	 * @author     David Zülke <dz@bitxtender.com>
	 * @since      0.9.0
	 */
	public function getAttributeNames()
	{
		if ($this->initContext instanceof \Agavi\Util\AgaviAttributeHolder) {
			return $this->initContext->getAttributeNames();
		}
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
		// Prefer the local mutable store if prepared; otherwise fall back to
		// the initContext attribute holder for legacy containers.
		if ($this->localAttributes !== null) {
			return $this->localAttributes;
		}
		if ($this->initContext instanceof \Agavi\Util\AgaviAttributeHolder) {
			return $this->initContext->getAttributes();
		}
		$empty = [];
		return $empty;
	}

	/**
	 * @see        AgaviAttributeHolder::setAttributesByRef()
	 *
	 * @author     David Zülke <dz@bitxtender.com>
	 * @since      0.9.0
	 */
	public function hasAttribute($name)
	{
		if ($this->initContext instanceof \Agavi\Util\AgaviAttributeHolder) {
			return $this->initContext->hasAttribute($name);
		}
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
		if ($this->initContext instanceof \Agavi\Util\AgaviAttributeHolder) {
			return $this->initContext->removeAttribute($name);
		}
		$null = null;
		return $null;
	}

	/**
	 * @see        AgaviAttributeHolder::setAttributesByRef()
	 *
	 * @author     David Zülke <dz@bitxtender.com>
	 * @since      0.9.0
	 */
	public function setAttribute($name, $value)
	{
		// If we have a local mutable attribute store (typical in container-less
		// pipeline), write into it so templates see the updated value. If the
		// initContext is a mutable AgaviAttributeHolder (legacy), forward to it.
		if ($this->localAttributes !== null) {
			$this->localAttributes[$name] = $value;
			return;
		}
		if ($this->initContext instanceof \Agavi\Execution\ViewInitContext) {
			\Agavi\Util\DeprecationSilencer::triggerOnce('setAttribute() ignored: immutable ViewInitContext snapshot');
			return;
		}
		if ($this->initContext instanceof \Agavi\Util\AgaviAttributeHolder) {
			$this->initContext->setAttribute($name, $value);
		}
	}

	/**
	 * @see        AgaviAttributeHolder::setAttributesByRef()
	 *
	 * @author     David Zülke <dz@bitxtender.com>
	 * @since      0.10.0
	 */
	public function appendAttribute($name, $value)
	{
		if ($this->initContext instanceof \Agavi\Execution\ViewInitContext) {
			\Agavi\Util\DeprecationSilencer::triggerOnce('appendAttribute() ignored under immutable snapshot');
			return;
		}
		if ($this->initContext instanceof \Agavi\Util\AgaviAttributeHolder) {
			$this->initContext->appendAttribute($name, $value);
		}
	}

	/**
	 * @see        AgaviAttributeHolder::setAttributesByRef()
	 *
	 * @author     David Zülke <dz@bitxtender.com>
	 * @since      0.9.0
	 */
	public function setAttributeByRef($name, &$value)
	{
		if ($this->initContext instanceof \Agavi\Execution\ViewInitContext) {
			\Agavi\Util\DeprecationSilencer::triggerOnce('setAttributeByRef() ignored under immutable snapshot');
			return;
		}
		if ($this->initContext instanceof \Agavi\Util\AgaviAttributeHolder) {
			$this->initContext->setAttributeByRef($name, $value);
		}
	}

	/**
	 * @see        AgaviAttributeHolder::setAttributesByRef()
	 *
	 * @author     David Zülke <dz@bitxtender.com>
	 * @since      0.10.0
	 */
	public function appendAttributeByRef($name, &$value)
	{
		if ($this->initContext instanceof \Agavi\Execution\ViewInitContext) {
			\Agavi\Util\DeprecationSilencer::triggerOnce('appendAttributeByRef() ignored under immutable snapshot');
			return;
		}
		if ($this->initContext instanceof \Agavi\Util\AgaviAttributeHolder) {
			$this->initContext->appendAttributeByRef($name, $value);
		}
	}

	/**
	 * @see        AgaviAttributeHolder::setAttributesByRef()
	 *
	 * @author     David Zülke <dz@bitxtender.com>
	 * @since      0.9.0
	 */
	public function setAttributes(array $attributes)
	{
		if ($this->initContext instanceof \Agavi\Execution\ViewInitContext) {
			\Agavi\Util\DeprecationSilencer::triggerOnce('setAttributes() ignored under immutable snapshot');
			return;
		}
		if ($this->initContext instanceof \Agavi\Util\AgaviAttributeHolder) {
			$this->initContext->setAttributes($attributes);
		}
	}

	/**
	 * @see        AgaviAttributeHolder::setAttributesByRef()
	 *
	 * @author     David Zülke <dz@bitxtender.com>
	 * @since      0.9.0
	 */
	public function setAttributesByRef(array &$attributes)
	{
		if ($this->initContext instanceof \Agavi\Execution\ViewInitContext) {
			\Agavi\Util\DeprecationSilencer::triggerOnce('setAttributesByRef() ignored under immutable snapshot');
			return;
		}
		if ($this->initContext instanceof \Agavi\Util\AgaviAttributeHolder) {
			$this->initContext->setAttributesByRef($attributes);
		}
	}

	public function reset(): void
	{
		$this->initContext = null;
		$this->context = null;
		$this->layers = [];
	}
}
