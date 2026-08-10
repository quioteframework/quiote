<?php
namespace Quiote\Renderer;

use Quiote\View\TemplateLayer;
use Symfony\Contracts\Service\ResetInterface;

/**
 * A renderer produces the output as defined by a View
 * @since      1.0.0
 * @version    1.0.0
 */
class PhpRenderer extends Renderer implements IReusableRenderer, ResetInterface
{
	/**
	 * @var        string A string with the default template file extension,
	 *                    including the dot.
	 */
	protected $defaultExtension = '.php';
	
	/**
	 * @var        ?TemplateLayer Temporary storage for the template layer,
	 *                                used during rendering.
	 */
	private $layer = null;
	
	/**
	 * @var        ?array<string, mixed> Temporary storage for the template layer, used during
	 *                   rendering.
	 */
	private $attributes = null;

	/**
	 * @var        ?array<string, mixed> Temporary storage for the template layer, used during
	 *                   rendering.
	 */
	private $slots = null;

	/**
	 * @var        ?array<int|string, mixed> Temporary storage for additional assigns, used during
	 *                   rendering.
	 */
	private $moreAssigns = null;

	/**
	 * Render the presentation and return the result.
	 * @param      TemplateLayer $layer The template layer to render.
	 * @param      array<string, mixed> $attributes The template variables.
	 * @param      array<string, mixed> $slots The slots.
	 * @param      array<int|string, mixed> $moreAssigns Associative array of additional assigns.
	 * @return     string A rendered result.
	 * @since      1.0.0
	 */
	public function render(TemplateLayer $layer, array &$attributes = [], array &$slots = [], array &$moreAssigns = [])
	{
		// DO NOT USE VARIABLES IN HERE, THEY MIGHT INTERFERE WITH TEMPLATE VARS
		$this->layer = $layer;
		$this->attributes =& $attributes;
		$this->slots =& $slots;
		$this->moreAssigns =& self::buildMoreAssigns($moreAssigns, $this->moreAssignNames);
		unset($layer, $attributes, $slots, $moreAssigns);
		
		if($this->extractVars) {
			extract($this->attributes, EXTR_REFS | EXTR_PREFIX_INVALID, '_');
		}

		// Expose the attributes under the configured varName -- "template" unless the renderer's
		// var_name parameter says otherwise. It is the array itself, not a null-returning wrapper
		// around it, so reading a key the action never set is an undefined-key warning rather than
		// silence: that warning is how a typo in a template gets found. Bound by reference, so a
		// template writing to it writes back to the attributes the view passed in. Merging action
		// and view attributes has already happened by this point, in the layer/view code.
		${$this->varName} =& $this->attributes;

		${$this->slotsVarName} =& $this->slots; 
		
		foreach($this->assigns as $name => $resolve) {
			${$name} = $resolve();
		}
		unset($name, $resolve);
		
		extract($this->moreAssigns ?? [], EXTR_REFS | EXTR_PREFIX_INVALID, '_');
		// The layer knows which module and template it is rendering, but the attributes do not
		// carry either, so supply them as "moduleName" and "actionName" keys on the attributes
		// array a template already reads. An attribute the action set itself wins: this only
		// fills a gap, it never overwrites.
		$layerParams = $this->layer?->getParameters() ?? [];
		if (!isset($this->attributes['moduleName']) && isset($layerParams['module'])) {
			$this->attributes['moduleName'] = $layerParams['module'];
		}
		if (!isset($this->attributes['actionName']) && isset($layerParams['template'])) {
			$this->attributes['actionName'] = $layerParams['template'];
		}
		
		$baseLevel = ob_get_level();
		ob_start();
		$startedLevel = ob_get_level();

		// Some layer implementations may return null to indicate "no template".
		// Requiring an empty path causes a PHP warning/fatal: normalize that
		// case to an empty render result to keep rendering soft-failure safe.
		$resource = $this->layer?->getResourceStreamIdentifier();
		if ($resource === null || $resource === '') {
			// nothing to render for this layer
			$retval = '';
			ob_end_clean();
			unset($this->layer, $this->attributes, $this->slots, $this->moreAssigns);
			return $retval;
		}

		// Make $inner available to legacy templates: templates expect $inner to
		// contain the main content (combined layers/slots). Populate $inner from
		// the attributes array if present to preserve backward compatibility.
		$inner = null;
		if (array_key_exists('inner', $this->attributes ?? [])) {
			$inner = $this->attributes['inner'];
		}
		try {
			require($resource);
			$retval = ob_get_clean();
		} catch(\Throwable $e) {
			// Unwind only buffers we opened
			while(ob_get_level() >= $startedLevel && ob_get_level() > $baseLevel) { @ob_end_clean(); }
			throw $e; // bubble up; ErrorHandlingMiddleware will format response
		}
		
		unset($this->layer, $this->attributes, $this->slots, $this->moreAssigns);

		return $retval !== false ? $retval : '';
	}

	/**
	 * Returns the skeleton PHP template written for a newly scaffolded view.
	 *
	 * The echoed expression follows the renderer's current variable
	 * configuration: a bare `$title` when `extract_vars` is on, otherwise the
	 * `title` key of the configured template variable. The value is escaped in
	 * the template itself, since a plain PHP template has no auto-escaping.
	 */
	#[\Override]
	public function getStarterTemplate(): string
	{
		$expr = $this->extractVars ? '$title' : ('$' . $this->varName . "['title']");
		return "<p><?php echo htmlspecialchars({$expr} ?? '', ENT_QUOTES, 'UTF-8'); ?></p>\n";
	}

	/**
	 * Clears the per-render state held for the template being included.
	 *
	 * Only the layer, attributes, slots and more-assigns are dropped; the
	 * parent reset is deliberately not invoked, so the renderer's configured
	 * variable names, extraction flag and assigns survive for the next render.
	 */
	#[\Override]
    public function reset() : void {
		$this->layer = null;
		$this->attributes = null;
		$this->slots = null;
		$this->moreAssigns = null;
	}
}

?>