<?php

declare(strict_types=1);

namespace WorkerProbe\Modules\Probe\Actions;

use Quiote\Action\Action;
use Quiote\Request\WebRequest;
use WorkerProbe\Modules\Probe\IdentityPayload;

/**
 * Reports the security user as this request sees it, reading only -- no
 * mutation. This is the endpoint that exposes the defect: after a login on
 * /login, the following requests here used to report authenticated=true with
 * no roles and no credentials, because auth was written eagerly while roles and
 * credentials were only written at a request boundary that ran after the
 * response had already been emitted.
 */
final class IdentityAction extends Action
{
	use IdentityPayload;

	public function executeRead(WebRequest $rd): string
	{
		$this->reportIdentity($this);

		return 'Success';
	}

	public function getDefaultViewName(): string
	{
		return 'Success';
	}
}
