<?php

declare(strict_types=1);

namespace WorkerProbe\Modules\Probe\Actions;

use Quiote\Action\Action;
use Quiote\Request\WebRequest;

final class EchobackAction extends Action
{
	public function executeRead(WebRequest $rd): string
	{
		return 'Success';
	}

	public function executeWrite(WebRequest $rd): string
	{
		return 'Success';
	}

	public function getDefaultViewName(): string
	{
		return 'Success';
	}
}
