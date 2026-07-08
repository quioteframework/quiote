<?php
declare(strict_types=1);

namespace Sandbox\Modules\Widget\Actions;

use Quiote\Action\Action;

/**
 * View's execute() declares a nullable `?string` return -- the type alone
 * can't prove it never returns null, so MISSING_TEMPLATE must still fire
 * without an explicit @quiote-viewmethod-has-no-template opt-out.
 */
class AmbiguousReturnAction extends Action
{
	public function executeRead(): string
	{
		return 'Success';
	}

	#[\Override]
	public function getDefaultViewName(): string
	{
		return 'Success';
	}
}
