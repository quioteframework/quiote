<?php
namespace Sandbox\Modules\ControllerTests\Actions;

use Agavi\Action\AgaviAction;
use Agavi\Renderer\AgaviRenderer;
use Sandbox\Services\ControllerTestDiService;

class ControllerTestDiAction extends AgaviAction
{
    public function __construct(public ControllerTestDiService $service) {}

    public function execute(?AgaviRenderer $renderer = null)
    {
        return 'Success';
    }
}
?>
