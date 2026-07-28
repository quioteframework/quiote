<?php

declare(strict_types=1);

namespace WorkerProbe\Modules\Probe\Actions;

use Quiote\Action\Action;
use Quiote\Http\Sse\SseEvent;
use Quiote\Http\Sse\SseStreamingAction;
use Quiote\Request\WebRequest;

final class StreamAction extends Action implements SseStreamingAction
{
	public function streamEvents(WebRequest $request): iterable
	{
		for ($i = 1; $i <= 3; $i++) {
			yield SseEvent::of('tick-' . $i, event: 'tick');
		}
	}

	public function executeRead(WebRequest $rd): string
	{
		return 'Success';
	}

	public function getDefaultViewName(): string
	{
		return 'Success';
	}
}
