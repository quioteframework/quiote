<?php
namespace Sandbox\Modules\ControllerTests\Actions;

use Quiote\Action\Action;
use Quiote\Renderer\Renderer;
use Sandbox\Services\ControllerTestDiService;

class ControllerTestDiAction extends Action
{
    public function __construct(public ControllerTestDiService $service) {}

    public function execute(?Renderer $renderer = null): string
    {
        return 'Success';
    }
}
?>
