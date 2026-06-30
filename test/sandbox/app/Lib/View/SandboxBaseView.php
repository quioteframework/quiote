<?php
namespace Sandbox\Lib\View;

use Agavi\Exception\AgaviViewException;
use Agavi\Request\AgaviWebRequest ;
use Agavi\View\AgaviView;

/**
 * The base view from which all project views inherit.
 */
class SandboxBaseView extends AgaviView
{
	/**
	 * Handles output types that are not handled elsewhere in the view. The
	 * default behavior is to simply throw an exception.
	 *
	 * @param      AgaviWebRequest  The request data associated with
	 *                                    this execution.
	 *
	 * @throws     AgaviViewException if the output type is not handled.
	 */
	public final function execute(AgaviWebRequest $rd): never
	{
		throw new AgaviViewException(sprintf(
			'The view "%1$s" does not implement an "execute%3$s()" method to serve '.
			'the output type "%2$s", and the base view "%4$s" does not implement an '.
			'"execute%3$s()" method to handle this situation.',
			static::class,
			$this->container->getOutputType()->getName(),
			ucfirst(strtolower((string) $this->container->getOutputType()->getName())),
			self::class
		));
	}

	/**
	 * Prepares the HTML output type.
	 *
	 * @param      AgaviWebRequest  The request data associated with
	 *                                    this execution.
	 * @param      string The layout to load.
	 */
	public function setupHtml(AgaviWebRequest $rd, $layoutName = null)
	{
		$this->loadLayout($layoutName);
	}
}

?>