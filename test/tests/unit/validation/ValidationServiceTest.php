<?php
use Agavi\Testing\AgaviUnitTestCase;
use Agavi\Execution\ValidationService;
use Agavi\Execution\ValidationResult;
use Agavi\Request\AgaviRequestDataHolder;
use Agavi\Action\AgaviAction;

class ValidationServiceTest extends AgaviUnitTestCase
{
    public function testSuccessPath()
    {
    \Sandbox\Modules\Cache\Actions\CacheComplexAction::configure(false,false,false);
    $controller = $this->getContext()->getController();
    $action = $controller->createActionInstance('Cache','CacheComplex');
    $descriptor = \Agavi\Execution\ActionDescriptor::fromController($controller,'Cache','CacheComplex','GET', strtolower($controller->getOutputType()->getName()));
    $lw = new \Agavi\Execution\LightweightActionInitContext($this->getContext(), $descriptor->module, $descriptor->action, $descriptor->method, $descriptor->outputType, new AgaviRequestDataHolder(), $controller->getGlobalResponse());
    $action->initialize($lw);
    $this->assertInstanceOf(AgaviAction::class, $action);
        $rd = new AgaviRequestDataHolder();
        $rd->setParameter('ok','1');
        $svc = new ValidationService();
    /** @var AgaviAction $action */
    $result = $svc->validate($action,$rd);
    $this->assertInstanceOf(ValidationResult::class,$result);
    $this->assertTrue($result->ok);
    // No XML validators loaded, so either empty or unset
    $this->assertSame([], $result->getErrors());
    }
    public function testFailurePath()
    {
        $controller = $this->getContext()->getController();
    $action = $controller->createActionInstance('Cache','CacheComplex');
    $descriptor = \Agavi\Execution\ActionDescriptor::fromController($controller,'Cache','CacheComplex','GET', strtolower($controller->getOutputType()->getName()));
    $lw = new \Agavi\Execution\LightweightActionInitContext($this->getContext(), $descriptor->module, $descriptor->action, $descriptor->method, $descriptor->outputType, new AgaviRequestDataHolder(), $controller->getGlobalResponse());
    $action->initialize($lw);
    $this->assertInstanceOf(AgaviAction::class, $action);
    // Force failure via static flag (parameter path may be cleared by strict mode)
    \Sandbox\Modules\Cache\Actions\CacheComplexAction::configure(true,false,false);
    $rd = new AgaviRequestDataHolder();
        $svc = new ValidationService();
    /** @var AgaviAction $action */
    $result = $svc->validate($action,$rd);
    $this->assertFalse($result->ok);
    // Manual validation failure without XML validators yields empty errors
    $this->assertSame([], $result->getErrors());
    \Sandbox\Modules\Cache\Actions\CacheComplexAction::configure(false,false,false); // reset
    }
}
