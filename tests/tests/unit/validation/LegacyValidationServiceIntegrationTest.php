<?php

use Quiote\Testing\UnitTestCase;
use Quiote\Execution\ValidationService;
use Quiote\Execution\ValidationResult;
use Quiote\Request\WebRequest;
use Quiote\Action\Action;

class LegacyValidationServiceIntegrationTest extends UnitTestCase
{
    public function testSuccessPath()
    {
        \Sandbox\Modules\Cache\Actions\CacheComplexAction::configure(false, false, false);
        $controller = $this->getContext()->getController();
        $action = $controller->createActionInstance('Cache', 'CacheComplex');
        $descriptor = \Quiote\Execution\ActionDescriptor::fromController($controller, 'Cache', 'CacheComplex', 'GET', strtolower($controller->getOutputType()->getName()));
        $initRequest = new WebRequest();
        $initRequest->initialize($this->getContext());
        $lw = new \Quiote\Execution\LightweightActionInitContext($this->getContext(), $descriptor->module, $descriptor->action, $descriptor->method, $descriptor->outputType, $initRequest, $controller->getGlobalResponse());
        $action->initialize($lw);
        $this->assertInstanceOf(Action::class, $action);
        $request = new WebRequest();
        $request->initialize($this->getContext());
        $request = $request->setParameter('ok', '1');
        $svc = new ValidationService();
        /** @var Action $action */
        $result = $svc->validate($action, $request);
        $this->assertInstanceOf(ValidationResult::class, $result);
        $this->assertTrue($result->ok);
        $this->assertSame([], $result->getErrors());
    }
    public function testFailurePath()
    {
        $controller = $this->getContext()->getController();
        $action = $controller->createActionInstance('Cache', 'CacheComplex');
        $descriptor = \Quiote\Execution\ActionDescriptor::fromController($controller, 'Cache', 'CacheComplex', 'GET', strtolower($controller->getOutputType()->getName()));
        $initRequest = new WebRequest();
        $initRequest->initialize($this->getContext());
        $lw = new \Quiote\Execution\LightweightActionInitContext($this->getContext(), $descriptor->module, $descriptor->action, $descriptor->method, $descriptor->outputType, $initRequest, $controller->getGlobalResponse());
        $action->initialize($lw);
        $this->assertInstanceOf(Action::class, $action);
        \Sandbox\Modules\Cache\Actions\CacheComplexAction::configure(true, false, false);
        $request = new WebRequest();
        $request->initialize($this->getContext());
        $svc = new ValidationService();
        /** @var Action $action */
        $result = $svc->validate($action, $request);
        $this->assertFalse($result->ok);
        $this->assertSame([], $result->getErrors());
        \Sandbox\Modules\Cache\Actions\CacheComplexAction::configure(false, false, false);
    }
}
