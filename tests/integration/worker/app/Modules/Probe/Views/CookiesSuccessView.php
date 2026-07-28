<?php

declare(strict_types=1);

namespace WorkerProbe\Modules\Probe\Views;

use Quiote\Exception\ViewException;
use Quiote\Request\WebRequest;
use Quiote\View\View;

final class CookiesSuccessView extends View
{
	public function execute(WebRequest $rd): never
	{
		throw new ViewException(sprintf(
			'The view "%1$s" does not implement an "execute%2$s()" method for this output type.',
			static::class,
			ucfirst(strtolower($this->getCurrentOutputType()->getName()))
		));
	}

	public function executeHtml(WebRequest $rd): void
	{
		// loadLayout() is what wires output_types.xml's "content" layer to the
		// matching template; without it executeHtml() returning null yields an
		// empty body.
		$this->loadLayout();
		$response = $this->getResponse();
		$response->setContentType('application/json');
		// Two cookies from one response: Swoole's header() overwrites on repeat
		// calls unless the array form is used, so this is the regression net for that.
		$response->setHttpHeader('Set-Cookie', ['first=1; Path=/', 'second=2; Path=/']);
	}
}
