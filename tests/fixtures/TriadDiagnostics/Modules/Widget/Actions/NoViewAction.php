<?php
declare(strict_types=1);

namespace Sandbox\Modules\Widget\Actions;

use Quiote\Action\Action;

/** Declares a default view with no matching view class/file -- MISSING_VIEW expected. */
class NoViewAction extends Action
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
