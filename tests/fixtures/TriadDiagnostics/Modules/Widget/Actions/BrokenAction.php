<?php
declare(strict_types=1);

namespace Sandbox\Modules\Widget\Actions;

use Quiote\Action\Action;

/** Deliberately wrong class name for this file's path -- MISSING_ACTION_CLASS expected. */
class TypoedAction extends Action
{
	public function executeRead(): string
	{
		return 'Success';
	}
}
