<?php
namespace Sandbox\Modules\Default\Views;

use Agavi\Renderer\AgaviPhpRenderer;
use Agavi\Request\AgaviWebRequest ;
use Agavi\View\AgaviView;

class WelcomeSuccessView extends AgaviView
{
	public function execute(AgaviWebRequest $rd)
	{
		/* Create a PHP renderer and corresponding layer for this action. This way,
		   it is guaranteed to work across output type or renderer changes. */
		$renderer = new AgaviPhpRenderer();
		$renderer->initialize($this->context, []);
		$this->appendLayer($this->createLayer('AgaviFileTemplateLayer', 'content', $renderer));
	}
}

?>