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
        $name = $rd->getParameter('name', 'World');
        if (!is_scalar($name) && !$name instanceof \Stringable) {
            throw new \InvalidArgumentException('GreetSuccessView expects a stringable "name" parameter.');
        }

        return "Hello, {$name}!";
    }
}
