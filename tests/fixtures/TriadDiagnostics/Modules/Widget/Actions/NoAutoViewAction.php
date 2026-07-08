<?php
declare(strict_types=1);

namespace Sandbox\Modules\Widget\Actions;

use Quiote\Action\Action;

/**
 * Never overrides getDefaultViewName() -- inherits the base Action's
 * 'Input' constant. Used to prove the scanner does NOT flag this as
 * MISSING_VIEW: the inherited default is not a real declaration, and most
 * real actions resolve their view dynamically from what execute*() returns,
 * which this scanner cannot (and must not pretend to) see.
 */
class NoAutoViewAction extends Action
{
	public function executeRead(): string
	{
		return 'Success';
	}
}
