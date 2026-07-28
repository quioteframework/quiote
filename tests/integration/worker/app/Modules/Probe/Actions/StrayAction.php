<?php

declare(strict_types=1);

namespace WorkerProbe\Modules\Probe\Actions;

use Quiote\Action\Action;
use Quiote\Request\WebRequest;

/**
 * Writes outside the response body, the way a stray debug statement or a
 * legacy template would. Off-SAPI this lands on the server's protocol channel
 * unless OutputCapture intercepts it.
 */
final class StrayAction extends Action
{
	public function executeRead(WebRequest $rd): string
	{
		echo 'STRAY-OUTPUT';

		return 'Success';
	}

	public function getDefaultViewName(): string
	{
		return 'Success';
	}
}
