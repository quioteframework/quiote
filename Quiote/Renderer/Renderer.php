<?php
namespace Quiote\Renderer;

use Quiote\Context;
use Quiote\Exception\QuioteException;
use Quiote\Util\ParameterHolder;
use Quiote\View\TemplateLayer;
use Symfony\Contracts\Service\ResetInterface;

/**
 * A renderer produces the output as defined by a View
 * @since      1.0.0
 * @version    1.0.0
 */
abstract class Renderer extends ParameterHolder implements ResetInterface
{
	/**
	 * @var        ?string
	 */
	protected final $contextName = null;
	/**
	 * @var        ?Context An Context instance.
	 */
	protected $context = null;
	
	/**
	 * @var        string A string with the default template file extension,
	 *                    including the dot.
	 */
	protected $defaultExtension = '';
	
	/**
	 * @var        string The name of the array that contains the template vars.
	 */
	protected $varName = 'template';
	
	/**
	 * @var        string The name of the array that contains the slots output.
	 */
	protected $slotsVarName = 'slots';
	
	/**
	 * @var        bool Whether or not the template vars should be extracted.
	 */
	protected $extractVars = false;
	
	/**
	 * @var        array<int|string, string> An array of objects to be exported for use in templates.
	 */
	protected $assigns = [];

	/**
	 * @var        array<int|string, mixed> An array of names for the "more" assigns.
	 */
	protected $moreAssignNames = [];
	
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
	 * Initialize this Renderer.
	 * @param      Context $context The current application context.
	 * @param      array<string, mixed> $parameters An associative array of initialization parameters.
	 * @return     void
	 * @since      1.0.0
	 */
	public function initialize(Context $context, array $parameters = [])
	{
		$this->context = $context;
		
		$this->setParameters($parameters);

		$varName = $this->getParameter('var_name', $this->varName);
		if(!is_string($varName)) {
			throw new QuioteException('The "var_name" parameter must be a string.');
		}
		$this->varName = $varName;

		$slotsVarName = $this->getParameter('slots_var_name', $this->slotsVarName);
		if(!is_string($slotsVarName)) {
			throw new QuioteException('The "slots_var_name" parameter must be a string.');
		}
		$this->slotsVarName = $slotsVarName;

		$extractVars = $this->getParameter('extract_vars', $this->extractVars);
		if(!is_bool($extractVars)) {
			throw new QuioteException('The "extract_vars" parameter must be a boolean.');
		}
		$this->extractVars = $extractVars;

		$defaultExtension = $this->getParameter('default_extension', $this->defaultExtension);
		if(!is_string($defaultExtension)) {
			throw new QuioteException('The "default_extension" parameter must be a string.');
		}
		$this->defaultExtension = $defaultExtension;

		if(!$this->extractVars && $this->varName == $this->slotsVarName) {
			throw new QuioteException('Template and Slots container variable names cannot be identical.');
		}

		$assigns = $this->getParameter('assigns', []);
		if(!is_array($assigns)) {
			throw new QuioteException('The "assigns" parameter must be an array.');
		}

		foreach($assigns as $item => $var) {
			$getter = 'get' . str_replace('_', '', (string) $item);
			if(is_callable([$this->context, $getter])) {
				if($var === null) {
					// the name is null, which means this one should not be assigned
					// we do this in here, not for the moreAssignNames, since those are checked later in the renderer
					continue;
				}
				if(!is_int($var) && !is_string($var)) {
					throw new QuioteException('An assign name must be a string or an integer.');
				}
				$this->assigns[$var] = $getter;
			} else {
				$this->moreAssignNames[$item] = $var;
			}
		}
	}
	
	/**
	 * Retrieve the current application context.
	 * @return     Context The current Context instance.
	 * @throws     QuioteException If this Renderer has not been initialize()d yet.
	 * @since      1.0.0
	 */
	public final function getContext()
	{
		if($this->context === null) {
			throw new QuioteException('Renderer has not been initialized: no Context is available');
		}
		return $this->context;
	}
	
	/**
	 * Get the template file extension
	 * @return     string The extension, including a leading dot.
	 * @since      1.0.0
	 */
	public function getDefaultExtension()
	{
		return $this->defaultExtension;
	}

	/**
	 * A minimal, syntactically valid starter template in this renderer's own
	 * templating syntax, rendering a "title" template variable -- or null if
	 * this renderer has no sensible starter to offer (the default).
	 * @return     ?string The starter template content, or null.
	 * @since      1.0.0
	 */
	public function getStarterTemplate(): ?string
	{
		return null;
	}
	
	/**
	 * Build an array of "more" assigns.
	 * @param      array<int|string, mixed> $moreAssigns The values to be assigned.
	 * @param      array<int|string, mixed> $moreAssignNames Assigns name map.
	 * @return     array<int|string, mixed> The data.
	 * @since      1.0.0
	 */
	protected static function &buildMoreAssigns(&$moreAssigns, $moreAssignNames)
	{
		$retval = [];
		
		foreach($moreAssigns as $name => &$value) {
			if(isset($moreAssignNames[$name])) {
				$mappedName = $moreAssignNames[$name];
				if(!is_int($mappedName) && !is_string($mappedName)) {
					throw new QuioteException('A "more assign" name must be a string or an integer.');
				}
				$name = $mappedName;
			} elseif(array_key_exists($name, $moreAssignNames)) {
				// the name is null, which means this one should not be assigned
				continue;
			}
			$retval[$name] =& $value;
		}
		
		return $retval;
	}
	
	/**
	 * Render the presentation and return the result.
	 * @param      TemplateLayer $layer The template layer to render.
	 * @param      array<string, mixed> $attributes The template variables.
	 * @param      array<string, mixed> $slots The slots.
	 * @param      array<int|string, mixed> $moreAssigns Associative array of additional assigns.
	 * @return     string A rendered result.
	 * @since      1.0.0
	 */
	abstract public function render(TemplateLayer $layer, array &$attributes = [], array &$slots = [], array &$moreAssigns = []);

	#[\Override]
    public function reset() : void
	{
		$this->context = null;
		$this->contextName = null;
		$this->varName = 'template';
		$this->slotsVarName = 'slots';
		$this->extractVars = false;
		$this->defaultExtension = '';
		
		$this->assigns = [];
		$this->moreAssignNames = [];
		
		parent::reset();
		
		unset($this->layer, $this->attributes, $this->slots, $this->moreAssigns);
	}
}

?>