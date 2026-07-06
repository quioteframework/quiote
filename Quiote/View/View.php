<?php
namespace Quiote\View;

/**
 * A view represents the presentation layer of an action. Output can be
 * customized by supplying attributes, which a template can manipulate and
 * display.
 * @since      1.0.0
 * @version    1.0.0
 */

use Quiote\Context;
use Quiote\Execution\ActionInitContext;
use Quiote\Execution\ViewInitContext;
use Quiote\Exception\ViewException;
use Quiote\Renderer\Renderer;
use Quiote\Execution\ForwardService;
use Quiote\Request\WebRequest;
use Symfony\Contracts\Service\ResetInterface;
use Quiote\Response\WebResponse;

abstract class View implements ResetInterface
{
	/**
	 * @since      1.0.0
	 */
	const NONE = null;

	/**
	 * @var ActionInitContext|ViewInitContext|null Initialization context (container-less pipeline).
	 */
	protected $initContext = null;

	/**
	 * @var array<int|string, mixed>|null Mutable attribute store for this view. Populated from
	 *                action attribute snapshot (ImmutableViewInitContext) or
	 *                from an attribute holder. Ensures view-set attributes
	 *                are visible to the renderer via getAttributes().
	 */
	protected $localAttributes = null;

	/**
	 * @var        ?Context The Context instance this View belongs to.
	 */
	protected $context = null;

	/**
	 * @var        array<int, TemplateLayer> An array of defined layers.
	 */
	protected $layers = [];

	/**
	 * Execute any presentation logic and set template attributes.
	 * @param      WebRequest $rd The action's request data holder.
	 * @return     mixed Array forward descriptor (legacy) or null.
	 * @since      1.0.0
	 */
	abstract function execute(WebRequest $rd);

	/**
	 * Render all configured template layers (in order) and return concatenated output.
	 * Legacy compatibility: classic views called setupHtml()/loadLayout() and returned null;
	 * older pipeline later rendered layers implicitly. New middleware/dispatch paths invoke
	 * this on-demand when execute* returns null and layers are present.
	 */
	public function renderLayers(): string
	{
		$logger = \Quiote\Logging\Log::for($this);
		if (empty($this->layers)) {
			if ($logger->isEnabled(\Quiote\Logging\Level::Debug)) {
				$logger->debug('[View] renderLayers no layers for ' . static::class);
			}
			return '';
		}
		$out = '';
		if ($logger->isEnabled(\Quiote\Logging\Level::Debug)) {
			$logger->debug('[View] renderLayers count=' . count($this->layers) . ' view=' . static::class);
		}
		foreach ($this->layers as $layer) {
			$attrsForLayer = $this->getAttributes();
			$attrsForLayer['inner'] = $out;
			$out = (string)$layer->execute(null, $attrsForLayer); // exceptions bubble naturally now
			if ($logger->isEnabled(\Quiote\Logging\Level::Debug)) {
				$logger->debug('[View] layer executed name=' . $layer->getName() . ' len=' . strlen($out));
			}
		}
		return $out;
	}

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
     * Backward compatibility accessor for legacy getContainer() usage.
     */
    /**
     * @return ActionInitContext|ViewInitContext|null
     */
    #[\Deprecated(message: 'Use getInitContext().')]
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
	 * @return     WebResponse The Response instance.
	 * @since      1.0.0
	 */
	public final function getResponse()
	{
		return $this->initContext->getResponse();
	}

	/**
	 * Build an RFC 9457 Problem Details body from the current request's validation
	 * errors, set the response status and `application/problem+json` content type,
	 * and return the JSON string — designed to be returned directly from an
	 * executeJson() (or any execute*()) method:
	 *   public function executeJson(WebRequest $rd)
	 *   {
	 *       return $this->returnProblemDetailsFromValidationIncidents(title: 'Invalid order');
	 *   }
	 * The `errors` map (field => messages) is taken from the live validation
	 * manager. Pass overrides for title/type/detail/status or extra top-level
	 * members via $extensions.
	 * @param array<string, mixed> $extensions Extra top-level Problem Details members.
	 */
	protected function returnProblemDetailsFromValidationIncidents(
		?string $title = null,
		int $status = 400,
		?string $type = null,
		?string $detail = null,
		array $extensions = []
	): string {
		$ic = $this->getInitContext();
		$vm = ($ic !== null && method_exists($ic, 'getValidationManager')) ? $ic->getValidationManager() : null;

		$instance = null;
		try {
			$request = $this->getContext()?->getRequest();
			if ($request !== null) {
				$instance = $request->getRequestUri();
			}
		} catch (\Throwable) {
		}

		$problem = \Quiote\Http\ProblemDetails::fromValidationManager(
			$vm,
			status: $status,
			title: $title,
			type: $type,
			detail: $detail,
			instance: is_string($instance) ? $instance : null,
			extensions: $extensions
		);

		try {
			$response = $this->getResponse();
			$response->setHttpStatusCode($status);
			$response->setContentType(\Quiote\Http\ProblemDetails::MEDIA_TYPE);
		} catch (\Throwable) {
		}

		return $problem->toJson();
	}

	/**
	 * Initialize this view.
	 * @param      ActionInitContext|ViewInitContext $context Initialization context.
	 * @return     void
	 * @since      1.0.0
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
			if ($context instanceof \Quiote\Execution\ImmutableViewInitContext) {
				$attrs = $context->getActionAttributes();
				$this->localAttributes = array_merge([], $attrs);
			} elseif ($context instanceof \Quiote\Util\AttributeHolder) {
				$this->localAttributes = $context->getAttributes();
			} else {
				$this->localAttributes = [];
			}
		} catch (\Throwable) {
			$this->localAttributes = [];
		}
	}

	/**
	 * Resolve current OutputType regardless of whether container is legacy execution container
	 * or a lightweight ActionInitContext lacking getOutputType().
	 */
	protected function getCurrentOutputType(): \Quiote\Controller\OutputType
	{
		// Legacy execution container exposes getOutputType() returning OutputType directly.
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
	 * This will automatically set the name of the layer, the current module, the
	 * current view name as the template, and the output type name.
	 * @param      string $class The class name of the TemplateLayer implementation.
	 * @param      string $name The name of the layer.
	 * @param      mixed $renderer An optional name of the non-default renderer to use, or
	 *                    an Renderer instance to use.
	 * @return     TemplateLayer A template layer instance.
	 * @since      1.0.0
	 */
	public function createLayer($class, $name, $renderer = null)
	{
		$layer = new $class();
		if (!is_subclass_of($layer, \Quiote\View\TemplateLayer::class)) {
			throw new ViewException('Class "$class" is not a subclass of TemplateLayer');
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
			// Container-less immutable init context (ViewInitContext): the
			// interface guarantees both accessors, so no further fallback is
			// needed here.
			$templateParam = $this->initContext->getViewName();
			$moduleParam = $this->initContext->getViewModuleName();
		} else {
			$moduleParam = $this->getAttribute('moduleName', null);
			$templateParam = $this->getAttribute('actionName', null);
		}
		// If templateParam is not set, prefer the resolved view name (container-less paths)
		if (empty($templateParam)) {
			$templateParam = $this->getResolvedViewName();
		}
		$layer->initialize($this->context, ['name' => $name, 'module' => $moduleParam, 'template' => $templateParam, 'output_type' => $this->getCurrentOutputType()->getName()]);
		if ($renderer instanceof Renderer) {
			$layer->setRenderer($renderer);
		} else {
			$layer->setRenderer($this->getCurrentOutputType()->getRenderer($renderer));
		}
		return $layer;
	}

	/**
	 * Append a layer to the list of layers.
	 * If no reference layer is given, the layer will be added to the end of the
	 * list.
	 * @param      TemplateLayer $layer The layer to insert.
	 * @param      TemplateLayer $otherLayer An optional other layer to insert after.
	 * @return     TemplateLayer The template layer that was inserted.
	 * @since      1.0.0
	 */
	public function appendLayer(TemplateLayer $layer, ?TemplateLayer $otherLayer = null)
	{
		if ($otherLayer !== null && !in_array($otherLayer, $this->layers, true)) {
			throw new ViewException('Layer "' . $otherLayer->getName() . '" not in list');
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
	 * If no reference layer is given, the layer will be added to the beginning of
	 * the list.
	 * @param      TemplateLayer $layer The layer to insert.
	 * @param      TemplateLayer $otherLayer An optional other layer to insert before.
	 * @return     TemplateLayer The template layer that was inserted.
	 * @since      1.0.0
	 */
	public function prependLayer(TemplateLayer $layer, ?TemplateLayer $otherLayer = null)
	{
		if ($otherLayer !== null && !in_array($otherLayer, $this->layers, true)) {
			throw new ViewException('Layer "' . $otherLayer->getName() . '" not in list');
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
	 * @param      TemplateLayer $layer The layer to remove.
	 * @return     void
	 * @since      1.0.0
	 */
	public function removeLayer(TemplateLayer $layer)
	{
		if (($pos = array_search($layer, $this->layers, true)) === false) {
			throw new ViewException('Layer "' . $layer->getName() . '" not in list');
		}
		array_splice($this->layers, $pos, 1);
	}

	/**
	 * Remove all layers from the list.
	 * @return     void
	 * @since      1.0.0
	 */
	public function clearLayers()
	{
		$this->layers = [];
	}

	/**
	 * Retrieve a layer from the list.
	 * @param      string $name The name of the layer.
	 * @return     FileTemplateLayer|TemplateLayer|null The layer instance, or null if not found.
	 * @since      1.0.0
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
	 * @return     array<int, TemplateLayer> An array of template layer instances.
	 * @since      1.0.0
	 */
	public function getLayers()
	{
		return $this->layers;
	}

	/**
	 * Load a pre-configured layout.
	 * If no layout name is given, the default layout will be used.
	 * @param      string $layoutName The (optional) name of the layout.
	 * @return     array<string, mixed> An array of parameters set for the layout.
	 * @throws     \Exception If the layout doesn't exist.
	 * @since      1.0.0
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
     * This container will have a parameter called 'is_slot' set to true.
     * @param      string $moduleName The name of the module.
     * @param      string $actionName The name of the action.
     * @param      mixed $arguments Array of request parameters.
     * @param      string $outputType Optional name of an initial output type to set.
     * @param      string $requestMethod Optional name of the request method to be used in this
     *                    container.
     * @return     \Quiote\Execution\SlotRenderable Slot content value object.
     * @since      1.0.0
     */
    #[\Deprecated(message: 'Legacy container API removed; returns SlotContent.')]
    public function createSlotContainer($moduleName, $actionName, $arguments = null, $outputType = null, $requestMethod = null)
	{
		\Quiote\Util\DeprecationSilencer::triggerOnce(__METHOD__ . ' is removed: returning SlotContent value object.');
		return $this->createSlotContent($moduleName, $actionName, $arguments, $outputType);
	}

	/**
	 * Convenience helper: directly render a slot and return its string content.
	 * This bypasses legacy container creation and uses the SlotDispatcher fast path.
	 * Arguments is array
	 * @param ?array<string, mixed> $arguments
	 */
	public function renderSlot(string $moduleName, string $actionName, ?array $arguments = null, ?string $outputType = null): string
	{
		// Reuse createSlotContent (new API) to avoid duplication.
		$slotContent = $this->createSlotContent($moduleName, $actionName, $arguments, $outputType);
		return $slotContent->getContent();
	}

	/**
	 * New API returning SlotContent value object explicitly, bypassing container wrapper regardless of flag.
	 * @param mixed $arguments
	 * @param ?string $outputType
	 */
	public function createSlotContent(string $moduleName, string $actionName, $arguments = null, $outputType = null): \Quiote\Execution\SlotRenderable
	{
		$parameters = [];
		if ($arguments instanceof WebRequest) {
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
		if ($currentModule === null || $currentAction === null) {
			try {
				$am = $this->getAttribute('moduleName', null);
				$aa = $this->getAttribute('actionName', null);
				if ($am !== null && $aa !== null) {
					$currentModule = $am;
					$currentAction = $aa;
				}
			} catch (\Throwable) {
				// ignore attribute lookup failures and fall back to resolved names
			}
		}
		if (
			$currentModule !== null && $currentAction !== null &&
			strtolower((string)$currentModule) === strtolower($moduleName) &&
			strtolower((string)$currentAction) === strtolower($actionName)
		) {
			return new \Quiote\Execution\SlotContent($moduleName, $actionName, $outputType, '', is_array($arguments) ? $arguments : []);
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
		return new \Quiote\Execution\DeferredSlotRenderable($this->context, $moduleName, $actionName, $parameters, $outputType);
	}

	/**
	 * Creates a new container with the same output type and request method as
	 * this view's container.
	 * This container will have a parameter called 'is_forward' set to true.
	 * @param      string $moduleName The name of the module.
	 * @param      string $actionName The name of the action.
	 * @param      mixed $arguments An array of request parameters.
	 * @param      string $outputType Optional name of an initial output type to set.
	 * @param      string $requestMethod Optional name of the request method to be used in this
	 *                    container.
	 * @return     mixed Forward descriptor or content (string) depending on usage.
	 * @since      1.0.0
	 */
	public function createForwardContainer($moduleName, $actionName, $arguments = null, $outputType = null, $requestMethod = null)
	{
		\Quiote\Util\DeprecationSilencer::triggerOnce(__METHOD__ . ' removed under container-less pipeline; returning SlotContent.');
		return $this->createSlotContent($moduleName, $actionName, $arguments, $outputType);
	}

	/**
	 * Render a system forward (login or secure) using ForwardService without creating a forward container.
	 * Falls back to legacy createForwardContainer if ForwardService fails.
	 */
	public function renderSystemForward(string $name, ?WebRequest $arguments = null, ?string $outputType = null): string
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
			if (!($arguments instanceof WebRequest)) {
				throw new \RuntimeException('Canonical WebRequest missing for system forward');
			}
		}
		try {
			$fs = new ForwardService($this->context->getController());
			[$view, $vm, $vn, $content] = $fs->createSystemForwardView($name, $outputType ?? $this->context->getController()->getOutputType()->getName(), $arguments);
			return (string)$content;
		} catch (\Throwable $e) {
			// The legacy forward-container fallback this used to defer to
			// returned an ExecutionContainer with getViewInstance()/getRequestData()/
			// getOutputType(); createForwardContainer() was migrated to the
			// container-less pipeline and now returns a SlotRenderable, which
			// exposes none of those methods. There is no functional legacy
			// path left to fall back to, so surface the original failure
			// instead of silently faulting on undefined method calls.
			throw new \RuntimeException('System forward "' . $name . '" failed: ' . $e->getMessage(), 0, $e);
		}
	}

	/**
	 * @see        AttributeHolder::setAttributesByRef()
	 * @return     void
	 * @since      1.0.0
	 */
	public function clearAttributes()
	{
		if ($this->initContext instanceof \Quiote\Util\AttributeHolder) {
			$this->initContext->clearAttributes();
		}
	}

	/**
	 * @see        AttributeHolder::setAttributesByRef()
	 * @param      string $name An attribute name.
	 * @param      mixed  $default A default attribute value.
	 * @return     mixed
	 * @since      1.0.0
	 */
	public function &getAttribute($name, $default = null)
	{
		if ($this->initContext instanceof \Quiote\Util\AttributeHolder) {
			return $this->initContext->getAttribute($name, null, $default);
		}
		$null = null;
		return $null;
	}

	/**
	 * @see        AttributeHolder::setAttributesByRef()
	 * @return     array<int, int|string>|null
	 * @since      1.0.0
	 */
	public function getAttributeNames()
	{
		if ($this->initContext instanceof \Quiote\Util\AttributeHolder) {
			return $this->initContext->getAttributeNames();
		}
		return [];
	}

	/**
	 * @see        AttributeHolder::setAttributesByRef()
	 * @return     array<int|string, mixed>
	 * @since      1.0.0
	 */
	public function &getAttributes()
	{
		// Prefer the local mutable store if prepared; otherwise fall back to
		// the initContext attribute holder for legacy containers.
		if ($this->localAttributes !== null) {
			return $this->localAttributes;
		}
		if ($this->initContext instanceof \Quiote\Util\AttributeHolder) {
			return $this->initContext->getAttributes();
		}
		$empty = [];
		return $empty;
	}

	/**
	 * @see        AttributeHolder::setAttributesByRef()
	 * @param      string $name An attribute name.
	 * @return     bool
	 * @since      1.0.0
	 */
	public function hasAttribute($name)
	{
		if ($this->initContext instanceof \Quiote\Util\AttributeHolder) {
			return $this->initContext->hasAttribute($name);
		}
		return false;
	}

	/**
	 * @see        AttributeHolder::setAttributesByRef()
	 * @param      string $name An attribute name.
	 * @return     mixed
	 * @since      1.0.0
	 */
	public function &removeAttribute($name)
	{
		if ($this->initContext instanceof \Quiote\Util\AttributeHolder) {
			return $this->initContext->removeAttribute($name);
		}
		$null = null;
		return $null;
	}

	/**
	 * @see        AttributeHolder::setAttributesByRef()
	 * @param      string $name An attribute name.
	 * @param      mixed  $value An attribute value.
	 * @return     void
	 * @since      1.0.0
	 */
	public function setAttribute($name, $value)
	{
		// If we have a local mutable attribute store (typical in container-less
		// pipeline), write into it so templates see the updated value. If the
		// initContext is a mutable AttributeHolder (legacy), forward to it.
		if ($this->localAttributes !== null) {
			$this->localAttributes[$name] = $value;
			return;
		}
		if ($this->initContext instanceof \Quiote\Execution\ViewInitContext) {
			\Quiote\Util\DeprecationSilencer::triggerOnce('setAttribute() ignored: immutable ViewInitContext snapshot');
			return;
		}
		if ($this->initContext instanceof \Quiote\Util\AttributeHolder) {
			$this->initContext->setAttribute($name, $value);
		}
	}

	/**
	 * @see        AttributeHolder::setAttributesByRef()
	 * @param      string $name An attribute name.
	 * @param      mixed  $value An attribute value.
	 * @return     void
	 * @since      1.0.0
	 */
	public function appendAttribute($name, $value)
	{
		if ($this->initContext instanceof \Quiote\Execution\ViewInitContext) {
			\Quiote\Util\DeprecationSilencer::triggerOnce('appendAttribute() ignored under immutable snapshot');
			return;
		}
		if ($this->initContext instanceof \Quiote\Util\AttributeHolder) {
			$this->initContext->appendAttribute($name, $value);
		}
	}

	/**
	 * Register a stylesheet for this page's render tree. Unlike
	 * appendAttribute(), this reaches Context::getAssetRegistry() directly,
	 * so it works from a top-level view or a slot-nested one alike, and is
	 * unaffected by the immutable-snapshot no-op that appendAttribute() hits
	 * under the modern container-less execution path.
	 */
	public function addCss(string $href): void
	{
		$this->getContext()?->getAssetRegistry()->addCss($href);
	}

	/**
	 * Register a script for this page's render tree. See addCss().
	 */
	public function addJavascript(string $src): void
	{
		$this->getContext()?->getAssetRegistry()->addJavascript($src);
	}

	/**
	 * @see        AttributeHolder::setAttributesByRef()
	 * @param      string $name An attribute name.
	 * @param      mixed  $value A reference to an attribute value.
	 * @return     void
	 * @since      1.0.0
	 */
	public function setAttributeByRef($name, &$value)
	{
		if ($this->initContext instanceof \Quiote\Execution\ViewInitContext) {
			\Quiote\Util\DeprecationSilencer::triggerOnce('setAttributeByRef() ignored under immutable snapshot');
			return;
		}
		if ($this->initContext instanceof \Quiote\Util\AttributeHolder) {
			$this->initContext->setAttributeByRef($name, $value);
		}
	}

	/**
	 * @see        AttributeHolder::setAttributesByRef()
	 * @param      string $name An attribute name.
	 * @param      mixed  $value A reference to an attribute value.
	 * @return     void
	 * @since      1.0.0
	 */
	public function appendAttributeByRef($name, &$value)
	{
		if ($this->initContext instanceof \Quiote\Execution\ViewInitContext) {
			\Quiote\Util\DeprecationSilencer::triggerOnce('appendAttributeByRef() ignored under immutable snapshot');
			return;
		}
		if ($this->initContext instanceof \Quiote\Util\AttributeHolder) {
			$this->initContext->appendAttributeByRef($name, $value);
		}
	}

	/**
	 * @see        AttributeHolder::setAttributesByRef()
	 * @param      array<int|string, mixed> $attributes
	 * @return     void
	 * @since      1.0.0
	 */
	public function setAttributes(array $attributes)
	{
		if ($this->initContext instanceof \Quiote\Execution\ViewInitContext) {
			\Quiote\Util\DeprecationSilencer::triggerOnce('setAttributes() ignored under immutable snapshot');
			return;
		}
		if ($this->initContext instanceof \Quiote\Util\AttributeHolder) {
			$this->initContext->setAttributes($attributes);
		}
	}

	/**
	 * @see        AttributeHolder::setAttributesByRef()
	 * @param      array<int|string, mixed> $attributes
	 * @return     void
	 * @since      1.0.0
	 */
	public function setAttributesByRef(array &$attributes)
	{
		if ($this->initContext instanceof \Quiote\Execution\ViewInitContext) {
			\Quiote\Util\DeprecationSilencer::triggerOnce('setAttributesByRef() ignored under immutable snapshot');
			return;
		}
		if ($this->initContext instanceof \Quiote\Util\AttributeHolder) {
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
