<?php
namespace Sandbox\Modules\ControllerTests\Views;

use Agavi\View\AgaviView;
use Agavi\Request\AgaviWebRequest;
use Sandbox\Services\ControllerTestDiService;

class ControllerTestDiSuccessView extends AgaviView
{
    public function __construct(public ControllerTestDiService $service) {}

    public function execute(AgaviWebRequest $rd)
    {
        return 'Success';
    }
}
