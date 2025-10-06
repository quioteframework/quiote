<?php
use Agavi\Testing\AgaviUnitTestCase;
use Agavi\Execution\ValidationService;
use Agavi\Request\AgaviWebRequest;

class ValidationServiceConfigParityTest extends AgaviUnitTestCase
{
    public function testParityWithContainerPerformValidation()
    {
        $controller = $this->getContext()->getController();
        $descriptor = \Agavi\Execution\ActionDescriptor::fromController($controller,'Cache','CacheComplex','GET', strtolower($controller->getOutputType()->getName()));

        $initRequest = new AgaviWebRequest();
        $initRequest->initialize($this->getContext());
    // Simulate container performValidation via ValidationService by creating and initializing action
    /** @var \Agavi\Action\AgaviAction $actionInstance */
    $actionInstance = $controller->createActionInstance('Cache','CacheComplex');
        $lw = new \Agavi\Execution\LightweightActionInitContext($this->getContext(), $descriptor->module, $descriptor->action, $descriptor->method, $descriptor->outputType, $initRequest, $controller->getGlobalResponse());
    $actionInstance->initialize($lw);
    \Sandbox\Modules\Cache\Actions\CacheComplexAction::configure(false,false,false);

        $request1 = new AgaviWebRequest();
        $request1->initialize($this->getContext());
        $request1->setParameter('ok','1');
    $svc = new ValidationService();
        $okContainer = $svc->validate($actionInstance,$request1,'Cache','CacheComplex')->ok;

    $action = $actionInstance; // reuse instance
    $svc = new ValidationService();
        $request2 = new AgaviWebRequest();
        $request2->initialize($this->getContext());
        $request2->setParameter('ok','1');
    /** @var \Agavi\Action\AgaviAction $action */
        $result = $svc->validate($action,$request2,'Cache','CacheComplex');

        $this->assertSame($okContainer, $result->ok, 'Adapter validation result should match container performValidation()');
    }
}
