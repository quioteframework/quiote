<?php

declare(strict_types=1);

namespace WorkerProbe\Modules\Probe\Actions;

use Quiote\Action\Action;
use Quiote\Request\WebRequest;
use RuntimeException;

/** Proves a failed request produces a 500 without taking the worker down. */
final class BoomAction extends Action
{
	public function executeRead(WebRequest $rd): never
	{
		throw new RuntimeException('probe explosion');
	}

	public function getDefaultViewName(): string
	{
		return 'Success';
	}
}
