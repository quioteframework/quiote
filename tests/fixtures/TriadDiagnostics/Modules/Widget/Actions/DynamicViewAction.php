<?php
declare(strict_types=1);

namespace Sandbox\Modules\Widget\Actions;

use Quiote\Action\Action;
use Quiote\View\View;

/**
 * Returns only view names no token scan can decide. Proves the scanner stays
 * silent rather than guessing: every diagnostic it emits has to be traceable
 * to a name actually written in the source.
 */
class DynamicViewAction extends Action
{
	public function executeRead(): mixed
	{
		$view = 'ComputedAtRuntime';
		return $view;
	}

	public function executeWrite(): mixed
	{
		return $this->pickView();
	}

	public function executeDelete(): mixed
	{
		// View::NONE is null -- "render nothing", not a view name.
		return View::NONE;
	}

	public function executeCreate(): mixed
	{
		// Both of these literals belong to the scope that declares them, not
		// to the action, and must not be attributed to it.
		$callback = function (): string {
			return 'InsideAClosure';
		};
		$renderer = new class {
			public function execute(): string
			{
				return 'InsideAnAnonymousClass';
			}
		};
		return $callback() . $renderer->execute();
	}

	private function pickView(): string
	{
		return 'NotAnExecuteMethod';
	}
}
