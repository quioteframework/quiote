<?php
use Agavi\Testing\AgaviUnitTestCase;
use Agavi\Execution\ValidationService;
use Agavi\Request\AgaviRequestDataHolder;

class ValidationServiceConfigParityTest extends AgaviUnitTestCase
{
    public function testParityWithContainerPerformValidation()
    {
        $controller = $this->getContext()->getController();
        $descriptor = \Agavi\Execution\ActionDescriptor::fromController($controller,'Cache','CacheComplex','GET', strtolower($controller->getOutputType()->getName()));
    $rd1 = new AgaviRequestDataHolder();
    $rd1->setParameter('ok','1');
    // Simulate container performValidation via ValidationService by creating and initializing action
    /** @var \Agavi\Action\AgaviAction $actionInstance */
    $actionInstance = $controller->createActionInstance('Cache','CacheComplex');
    $lw = new \Agavi\Execution\LightweightActionInitContext($this->getContext(), $descriptor->module, $descriptor->action, $descriptor->method, $descriptor->outputType, new AgaviRequestDataHolder(), $controller->getGlobalResponse());
    $actionInstance->initialize($lw);
    \Sandbox\Modules\Cache\Actions\CacheComplexAction::configure(false,false,false);
    $svc = new ValidationService();
    $okContainer = $svc->validate($actionInstance,$rd1,'Cache','CacheComplex')->ok;

    $action = $actionInstance; // reuse instance
    $svc = new ValidationService();
        $rd2 = new AgaviRequestDataHolder();
        $rd2->setParameter('ok','1');
    /** @var \Agavi\Action\AgaviAction $action */
    $result = $svc->validate($action,$rd2,'Cache','CacheComplex');

        $this->assertSame($okContainer, $result->ok, 'Adapter validation result should match container performValidation()');
    }
}
