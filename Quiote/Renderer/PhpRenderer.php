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

		// Expose template attributes under $t (or configured varName).
		// To avoid PHP 8 undefined array key warnings in legacy templates when
		// accessing missing keys (e.g. $t['embeddedReports']), present $t
		// as an ArrayAccess/Iterator wrapper that returns null for missing
		// keys but still allows reads/writes to the underlying array.
	// Expose the attributes array directly under the configured varName so
	// templates access the real array and PHP will emit notices for
	// undefined keys (crucial debugging info). Action and view
	// attributes must already be merged into $this->attributes by the
	// layer/view code; use a reference so writes in templates propagate.
	${$this->varName} =& $this->attributes;
		
		${$this->slotsVarName} =& $this->slots; 
		
		foreach($this->assigns as $name => $getter) {
			${$name} = $this->context->$getter();
		}
		unset($name, $getter);
		
		extract($this->moreAssigns, EXTR_REFS | EXTR_PREFIX_INVALID, '_');
		// Provide backwards-compatible template variables: ensure moduleName
		// and actionName are present in the attributes array. These keys are
		// expected by many templates (available as $t['moduleName'] etc).
		$layerParams = $this->layer->getParameters();
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
		$resource = $this->layer->getResourceStreamIdentifier();
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
		if (array_key_exists('inner', $this->attributes)) {
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

	#[\Override]
	public function getStarterTemplate(): string
	{
		$expr = $this->extractVars ? '$title' : ('$' . $this->varName . "['title']");
		return "<p><?php echo htmlspecialchars({$expr} ?? '', ENT_QUOTES, 'UTF-8'); ?></p>\n";
	}

	#[\Override]
    public function reset() : void {
		$this->layer = null;
		$this->attributes = null;
		$this->slots = null;
		$this->moreAssigns = null;
	}
}

?>