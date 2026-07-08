<?php
declare(strict_types=1);

namespace Sandbox\Modules\Widget\Actions;

use Quiote\Action\Action;

/** Has both a matching view class and template file -- no diagnostic expected. */
class GoodAction extends Action
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
