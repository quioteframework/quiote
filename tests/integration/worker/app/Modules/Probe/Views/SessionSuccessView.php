<?php

declare(strict_types=1);

namespace WorkerProbe\Modules\Probe\Views;

use Quiote\Exception\ViewException;
use Quiote\Request\WebRequest;
use Quiote\View\View;

final class SessionSuccessView extends View
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
		$this->getResponse()->setContentType('application/json');
		// Read straight back out of the session rather than relying on the
		// action's attribute bag reaching the template.
		$context = $this->getContext();
		$hits = $context?->getSessionBag()->get('probe_hits');
		$this->setAttribute('hits', is_numeric($hits) ? (int) $hits : 0);
	}
}
