<?php
declare(strict_types=1);

namespace Sandbox\Modules\McpActionTool\Views;

use Quiote\Request\WebRequest;
use Quiote\View\View;

class GreetSuccessView extends View
{
    public function execute(WebRequest $rd): string
    {
        return $this->executeHtml($rd);
    }

    public function executeHtml(WebRequest $rd): string
    {
        $name = (string) $rd->getParameter('name', 'World');

        return "Hello, {$name}!";
    }
}
