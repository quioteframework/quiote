<?php

declare(strict_types=1);

namespace WorkerProbe\Modules\Probe\Actions;

use Quiote\Action\Action;
use Quiote\Request\WebRequest;
use Quiote\User\SecurityUser;
use WorkerProbe\Modules\Probe\IdentityPayload;

final class LogoutAction extends Action
{
	use IdentityPayload;

	public function executeRead(WebRequest $rd): string
	{
		$user = $this->getContext()?->getUser();

		if ($user instanceof SecurityUser) {
			$user->setAuthenticated(false);
		}

		$this->reportIdentity($this);

		return 'Success';
	}

	public function getDefaultViewName(): string
	{
		return 'Success';
	}
}
