<?php

declare(strict_types=1);

namespace WorkerProbe\Modules\Probe\Actions;

use Quiote\Action\Action;
use Quiote\Request\WebRequest;

/**
 * Reads a hit counter out of the session and increments it. A client whose
 * Set-Cookie was lost, or whose session did not resume, always sees hits=1.
 */
final class SessionAction extends Action
{
	public function executeRead(WebRequest $rd): string
	{
		$context = $this->getContext();
		if ($context === null) {
			return 'Success';
		}
		$bag = $context->getSessionBag();
		$hits = $bag->get('probe_hits');
		$hits = is_numeric($hits) ? (int) $hits + 1 : 1;
		$bag->set('probe_hits', $hits);

		$this->setAttribute('hits', $hits);

		return 'Success';
	}

	public function getDefaultViewName(): string
	{
		return 'Success';
	}
}
