<?php

declare(strict_types=1);

namespace WorkerProbe\Modules\Probe\Actions;

use Quiote\Action\Action;
use Quiote\Request\WebRequest;

/**
 * Touches nothing: no session read, no session write, no user mutation. The
 * shape of a health check, a bot hit, or a read-only JSON API call.
 *
 * Such a request used to still leave a session row behind and hand out a
 * Set-Cookie, because the user hierarchy wrote its (empty) state
 * unconditionally at the request boundary and Storage::store() lazily creates
 * a session for any write.
 */
final class QuietAction extends Action
{
	public function executeRead(WebRequest $rd): string
	{
		return 'Success';
	}

	public function getDefaultViewName(): string
	{
		return 'Success';
	}
}
