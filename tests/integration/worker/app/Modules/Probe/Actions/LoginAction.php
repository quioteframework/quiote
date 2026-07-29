<?php

declare(strict_types=1);

namespace WorkerProbe\Modules\Probe\Actions;

use Quiote\Action\Action;
use Quiote\Request\WebRequest;
use Quiote\User\RbacSecurityUser;
use Quiote\User\SecurityUser;
use WorkerProbe\Modules\Probe\IdentityPayload;

/**
 * Authenticates and grants a role, in the order AuthenticationManager::apply()
 * uses: authenticate first (which rotates the session id), then grant.
 */
final class LoginAction extends Action
{
	use IdentityPayload;

	public function executeRead(WebRequest $rd): string
	{
		$user = $this->getContext()?->getUser();

		if ($user instanceof SecurityUser) {
			$user->setAuthenticated(true);
			if ($user instanceof RbacSecurityUser) {
				$user->grantRole('administrator');
			}
			$user->setAttribute('display_name', 'Ada');
		}

		$this->reportIdentity($this);

		return 'Success';
	}

	public function getDefaultViewName(): string
	{
		return 'Success';
	}
}
