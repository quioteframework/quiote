<?php
declare(strict_types=1);

namespace Sandbox\Modules\Widget\Actions;

use Quiote\Action\Action;

/**
 * View's execute() has a non-nullable `string` return type and no
 * @quiote-viewmethod-has-no-template annotation -- the declared return type
 * alone should be enough to prove no template is needed.
 */
class AutoDetectAction extends Action
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
