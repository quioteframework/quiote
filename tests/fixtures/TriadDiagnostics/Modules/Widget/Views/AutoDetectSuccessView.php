<?php
declare(strict_types=1);

namespace Sandbox\Modules\Widget\Views;

use Quiote\Request\WebRequest;
use Quiote\View\View;

class AutoDetectSuccessView extends View
{
	public function execute(WebRequest $rd): string
	{
		return '{"ok":true}';
	}
}
