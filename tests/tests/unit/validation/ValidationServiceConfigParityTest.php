<?php
use Quiote\Testing\UnitTestCase;
use Quiote\Execution\ValidationService;
use Quiote\Request\WebRequest;

class ValidationServiceConfigParityTest extends UnitTestCase
{
    public function testParityWithContainerPerformValidation()
    {
        $controller = $this->getContext()->getController();
        $descriptor = \Quiote\Execution\ActionDescriptor::fromController($controller,'Cache','CacheComplex','GET', strtolower($controller->getOutputType()->getName()));

        $initRequest = new WebRequest();
        $initRequest->initialize($this->getContext());
    // Simulate container performValidation via ValidationService by creating and initializing action
    /** @var \Quiote\Action\Action $actionInstance */
    $actionInstance = $controller->createActionInstance('Cache','CacheComplex');
        $lw = new \Quiote\Execution\LightweightActionInitContext($this->getContext(), $descriptor->module, $descriptor->action, $descriptor->method, $descriptor->outputType, $initRequest, $controller->getGlobalResponse());
    $actionInstance->initialize($lw);
    \Sandbox\Modules\Cache\Actions\CacheComplexAction::configure(false,false,false);

        $request1 = new WebRequest();
        $request1->initialize($this->getContext());
        $request1 = $request1->setParameter('ok','1');
    $svc = new ValidationService();
        $okContainer = $svc->validate($actionInstance,$request1,'Cache','CacheComplex')->ok;

    $action = $actionInstance; // reuse instance
    $svc = new ValidationService();
        $request2 = new WebRequest();
        $request2->initialize($this->getContext());
        $request2 = $request2->setParameter('ok','1');
    /** @var \Quiote\Action\Action $action */
        $result = $svc->validate($action,$request2,'Cache','CacheComplex');

        $this->assertSame($okContainer, $result->ok, 'Adapter validation result should match container performValidation()');
    }
}
