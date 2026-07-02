<?php
namespace SampleApp\Modules\Default\Views;

use Quiote\Exception\ViewException;
use Quiote\Request\WebRequest;
use Quiote\View\View;

class AboutSuccessView extends View
{
	public function execute(WebRequest $rd): never
	{
		throw new ViewException(sprintf(
			'The view "%1$s" does not implement an "execute%2$s()" method for this output type.',
			static::class,
			ucfirst(strtolower($this->getCurrentOutputType()->getName()))
		));
	}

	public function executeHtml(WebRequest $rd)
	{
		// Populates the layers from output_types.xml's <layouts> so the "content"
		// layer's template actually gets rendered -- without this, executeHtml()
		// returning null falls through to an empty body.
		$this->loadLayout();
		$this->setAttribute('title', 'About');
	}
}
