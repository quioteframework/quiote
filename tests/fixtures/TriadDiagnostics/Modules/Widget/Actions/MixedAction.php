<?php
declare(strict_types=1);

namespace Sandbox\Modules\Widget\Actions;

use Quiote\Action\Action;

/**
 * View mixes an annotated executeJson() (no template needed) with an
 * unannotated executeHtml() that does have a template -- no diagnostic
 * expected.
 */
class MixedAction extends Action
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
