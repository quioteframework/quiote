<?php
declare(strict_types=1);

namespace Sandbox\Modules\AttrRouting\Actions;

use Quiote\Action\Action;

class UnroutedAction extends Action
{
	public function executeRead()
	{
		return 'Success';
	}
}
