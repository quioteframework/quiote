<?php
declare(strict_types=1);

namespace Sandbox\Modules\Widget\Actions;

use Quiote\Action\Action;

/**
 * View has an executeHtml() rendered through a non-PHP renderer (`.tal`)
 * and an executeJson() that opts out -- regression fixture for
 * AppIntrospectionCompiler resolving a per-output-type template file
 * instead of always assuming `.php`.
 */
class MultiOutputTypeAction extends Action
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
