<?php

declare(strict_types=1);

namespace WorkerProbe\Modules\Probe\Actions;

use Quiote\Action\Action;
use Quiote\Request\WebRequest;

/**
 * Reads a hit counter out of the legacy ext/session storage and increments it.
 * A client whose Set-Cookie was lost always sees hits=1, which is exactly the
 * failure NativeSessionCookieBridge exists to prevent.
 */
final class SessionAction extends Action
{
	public function executeRead(WebRequest $rd): string
	{
		$context = $this->getContext();
		if ($context === null) {
			return 'Success';
		}
		$storage = $context->getStorage();
		// store()/retrieve() are the app-level API; read()/write() on
		// SessionStorage are ext/session's own handler callbacks.
		$hits = $storage->retrieve('probe_hits');
		$hits = is_numeric($hits) ? (int) $hits + 1 : 1;
		$storage->store('probe_hits', $hits);

		$this->setAttribute('hits', $hits);

		return 'Success';
	}

	public function getDefaultViewName(): string
	{
		return 'Success';
	}
}
