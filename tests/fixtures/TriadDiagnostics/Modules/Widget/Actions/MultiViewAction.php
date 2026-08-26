<?php
declare(strict_types=1);

namespace Sandbox\Modules\Widget\Actions;

use Quiote\Action\Action;

/**
 * Reaches a different view per branch and per method -- the shape a real
 * action has. Each distinct token is its own triad candidate, so each missing
 * one is reported separately instead of collapsing into a single diagnostic
 * that names only the first.
 */
class MultiViewAction extends Action
{
	public function executeRead(): string
	{
		return 'Listing';
	}

	public function executeWrite(): string
	{
		if ($this->getAttribute('failed') === true) {
			return 'Error';
		}
		return 'Listing';
	}
}
