<?php
namespace Sandbox\Modules\Default\Views;

use Quiote\Renderer\PhpRenderer;
use Quiote\Request\WebRequest ;
use Quiote\View\View;

class WelcomeSuccessView extends View
{
	public function execute(WebRequest $rd): void
	{
		$context = $this->getContext();
		if ($context === null) {
			throw new \RuntimeException('WelcomeSuccessView requires an initialized Context.');
		}

		/* Create a PHP renderer and corresponding layer for this action. This way,
		   it is guaranteed to work across output type or renderer changes. */
		$renderer = new PhpRenderer();
		$renderer->initialize(context: $context, parameters: []);
		$this->appendLayer(layer: $this->createLayer(class: 'FileTemplateLayer', name: 'content', renderer: $renderer));
	}
}

?>