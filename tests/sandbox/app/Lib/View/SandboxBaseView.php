<?php
namespace Sandbox\Lib\View;

use Quiote\Exception\ViewException;
use Quiote\Request\WebRequest ;
use Quiote\View\View;

/**
 * The base view from which all project views inherit.
 */
class SandboxBaseView extends View
{
	/**
	 * Handles output types that are not handled elsewhere in the view. The
	 * default behavior is to simply throw an exception.
	 * @param      WebRequest $rd The request data associated with this execution.
	 * @throws     ViewException if the output type is not handled.
	 */
	public final function execute(WebRequest $rd): never
	{
		throw new ViewException(sprintf(
			'The view "%1$s" does not implement an "execute%3$s()" method to serve '.
			'the output type "%2$s", and the base view "%4$s" does not implement an '.
			'"execute%3$s()" method to handle this situation.',
			static::class,
			$this->getCurrentOutputType()->getName(),
			ucfirst(strtolower((string) $this->getCurrentOutputType()->getName())),
			self::class
		));
	}

	/**
	 * Prepares the HTML output type.
	 * @param      WebRequest $rd The request data associated with this execution.
	 * @param      ?string $layoutName The layout to load.
	 */
	public function setupHtml(WebRequest $rd, ?string $layoutName = null): void
	{
		$this->loadLayout($layoutName);
	}
}

?>