<?php
declare(strict_types=1);

namespace Sandbox\Modules\Widget\Views;

use Quiote\Request\WebRequest;
use Quiote\View\View;

class MixedSuccessView extends View
{
	/** @quiote-viewmethod-has-no-template */
	public function execute(WebRequest $rd): ?string
	{
		return null;
	}

	public function executeHtml(WebRequest $rd): ?string
	{
		return null;
	}

	/** @quiote-viewmethod-has-no-template */
	public function executeJson(WebRequest $rd): string
	{
		return '{}';
	}
}
