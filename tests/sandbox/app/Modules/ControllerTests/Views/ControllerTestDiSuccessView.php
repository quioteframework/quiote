<?php
namespace Sandbox\Modules\ControllerTests\Views;

use Quiote\View\View;
use Quiote\Request\WebRequest;
use Sandbox\Services\ControllerTestDiService;

class ControllerTestDiSuccessView extends View
{
    public function __construct(public ControllerTestDiService $service) {}

    public function execute(WebRequest $rd)
    {
        return 'Success';
    }
}
