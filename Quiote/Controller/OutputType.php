<?php
namespace Quiote\Controller;

/**
 * This class holds information about an Output Type.
 * @since      1.0.0
 * @version    1.0.0
 */
use Quiote\Util\ParameterHolder;
use Quiote\Context;
use Quiote\Exception\QuioteException;
use Quiote\Renderer\IReusableRenderer;
use Quiote\Renderer\Renderer;
use Symfony\Contracts\Service\ResetInterface;
class OutputType extends ParameterHolder implements \Stringable, ResetInterface
{
	/**
	 * @var        ?Context The context instance.
	 */
	protected $context = null;
	
	/**
	 * @var        string The name of the Output Type.
	 */
	protected $name = '';
	
	/**
	 * @var        array<string, array<string, mixed>> An array of Renderers (settings and instances).
	 */
	protected $renderers = [];

	/**
	 * @var        ?string The name of the default Renderer, if set.
	 */
	protected $defaultRenderer = null;

	/**
	 * @var        array<string, array<string, mixed>> An array of configured layouts.
	 */
	protected $layouts = [];

	/**
	 * @var        ?string The name of the default layout, if set.
	 */
	protected $defaultLayout = null;

	/**
	 * @var        ?string The name of the exception template for this output type.
	 */
	protected $exceptionTemplate = null;

	/**
	 * Initialize the Output Type.
	 * @param      Context $context The current Context instance.
	 * @param      array<string, mixed> $parameters An array of initialization parameters.
	 * @param      string $name The name of the Output Type.
	 * @param      array<string, array<string, mixed>> $renderers An array of Renderers (settings and instances).
	 * @param      ?string $defaultRenderer The name of the default Renderer, if set.
	 * @param      array<string, array<string, mixed>> $layouts An array of configured layouts.
	 * @param      ?string $defaultLayout The name of the default layout, if set.
	 * @param      ?string $exceptionTemplate The name of the exception template for this output type.
	 * @return     void
	 * @since      1.0.0
	 */
	public function initialize(Context $context, array $parameters, $name, array $renderers, $defaultRenderer, array $layouts, $defaultLayout, $exceptionTemplate = null)
	{
		$this->context = $context;
		
		$this->parameters = $parameters;
		
		$this->name = $name;
		
		$this->renderers = $renderers;
		
		$this->defaultRenderer = $defaultRenderer;
		
		$this->layouts = $layouts;
		
		$this->defaultLayout = $defaultLayout;
		
		$this->exceptionTemplate = $exceptionTemplate;
	}
	
	/**
	 * Get the name of the Output Type.
	 * @return     string The name of the Output Type.
	 * @since      1.0.0
	 */
	public function getName()
	{
		return $this->name;
	}
	
	/**
	 * @see        OutputType::getName()
	 * @since      1.0.0
	 */
	public final function __toString(): string
	{
		return $this->getName();
	}
	
	/**
	 * Checks whether or not any renderers are defined for this Output Type.
	 * @return     bool True, if renderers are defined, false otherwise.
	 * @since      1.0.0
	 */
	public function hasRenderers()
	{
		return (count($this->renderers) > 0);
	}
	
	/**
	 * Get a renderer instance.
	 * @param      ?string $name The optional name of the Renderer to fetch.
	 * @return     ?Renderer A Renderer instance or null if none defined.
	 * @since      1.0.0
	 */
	public function getRenderer($name = null)
	{
		if(count($this->renderers) == 0) {
			return null;
		} elseif($name === null) {
			$name = $this->defaultRenderer;
		}
		if(isset($this->renderers[$name])) {
			if($this->renderers[$name]['instance'] === null) {
				$renderer = new $this->renderers[$name]['class']();
				$renderer->initialize($this->context, $this->renderers[$name]['parameters']);
				if(isset($this->renderers[$name]['extension'])) {
					$renderer->setExtension($this->renderers[$name]['extension']);
				}
				if($renderer instanceof IReusableRenderer) {
					$this->renderers[$name]['instance'] = $renderer;
				}
				return $renderer;
			} else {
				return $this->renderers[$name]['instance'];
			}
		} else {
			throw new QuioteException('Unknown renderer "' . $name . '"');
		}
	}
	
	/**
	 * Get the name of the default layout.
	 * @return     string The name of the default layout, or null if none defined.
	 * @since      1.0.0
	 */
	public function getDefaultLayoutName()
	{
		return $this->defaultLayout;
	}
	
	/**
	 * Get a layout.
	 * @param      ?string $name The optional name of the layout to fetch.
	 * @return     array<string, mixed> An array of layout information.
	 * @throws     QuioteException If the layout doesn't exist.
	 * @since      1.0.0
	 */
	public function getLayout($name = null)
	{
		if($name === null) {
			$name = $this->defaultLayout;
		}
		
		if(isset($this->layouts[$name])) {
			return $this->layouts[$name];
		} else {
			throw new QuioteException('Unknown layout "' . $name . '"');
		}
	}
	
	/**
	 * Get the exception template filename for this renderer.
	 * @return     string A path to the exception template, or null if undefined.
	 * @since      1.0.0
	 */
	public function getExceptionTemplate()
	{
		return $this->exceptionTemplate;
	}

	/**
	 * Reset output type state for FrankenPHP worker compatibility.
	 * Clears output type properties that could leak between requests.
	 * @since      1.0.0
	 */
	#[\Override]
    public function reset(): void
	{
		$this->context = null;
		// Note: name, renderers, defaultRenderer, layouts, defaultLayout, exceptionTemplate
		// are typically configuration-based and don't need to be reset
		
		// Reset parent parameter holder state
		parent::clearParameters();
	}
}

?>