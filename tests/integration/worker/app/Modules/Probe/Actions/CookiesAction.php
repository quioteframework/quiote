<?php

declare(strict_types=1);

namespace WorkerProbe\Modules\Probe\Actions;

use Quiote\Action\Action;
use Quiote\Request\WebRequest;

/** Two Set-Cookie headers, to prove neither overwrites the other. */
final class CookiesAction extends Action
{
	public function executeRead(WebRequest $rd): string
	{
		// The headers themselves are set in the view, which is where getResponse()
		// is reachable.
		return 'Success';
	}

	public function getDefaultViewName(): string
	{
		return 'Success';
	}
}
