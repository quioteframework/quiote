<?php

declare(strict_types=1);

namespace WorkerProbe\Modules\Probe\Views;

use Quiote\Exception\ViewException;
use Quiote\Request\WebRequest;
use Quiote\View\View;

final class LogoutSuccessView extends View
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
		$this->loadLayout();
		$this->getResponse()->setContentType('application/json');
	}
}
