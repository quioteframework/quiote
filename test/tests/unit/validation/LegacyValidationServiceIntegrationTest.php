<?php

use Agavi\Testing\AgaviUnitTestCase;
use Agavi\Execution\ValidationService;
use Agavi\Execution\ValidationResult;
use Agavi\Request\AgaviWebRequest;
use Agavi\Action\AgaviAction;

class LegacyValidationServiceIntegrationTest extends AgaviUnitTestCase
{
    public function testSuccessPath()
    {
        \Sandbox\Modules\Cache\Actions\CacheComplexAction::configure(false, false, false);
        $controller = $this->getContext()->getController();
        $action = $controller->createActionInstance('Cache', 'CacheComplex');
        $descriptor = \Agavi\Execution\ActionDescriptor::fromController($controller, 'Cache', 'CacheComplex', 'GET', strtolower($controller->getOutputType()->getName()));
        $initRequest = new AgaviWebRequest();
        $initRequest->initialize($this->getContext());
        $lw = new \Agavi\Execution\LightweightActionInitContext($this->getContext(), $descriptor->module, $descriptor->action, $descriptor->method, $descriptor->outputType, $initRequest, $controller->getGlobalResponse());
        $action->initialize($lw);
        $this->assertInstanceOf(AgaviAction::class, $action);
        $request = new AgaviWebRequest();
        $request->initialize($this->getContext());
        $request->setParameter('ok', '1');
        $svc = new ValidationService();
        /** @var AgaviAction $action */
        $result = $svc->validate($action, $request);
        $this->assertInstanceOf(ValidationResult::class, $result);
        $this->assertTrue($result->ok);
        $this->assertSame([], $result->getErrors());
    }
    public function testFailurePath()
    {
        $controller = $this->getContext()->getController();
        $action = $controller->createActionInstance('Cache', 'CacheComplex');
        $descriptor = \Agavi\Execution\ActionDescriptor::fromController($controller, 'Cache', 'CacheComplex', 'GET', strtolower($controller->getOutputType()->getName()));
        $initRequest = new AgaviWebRequest();
        $initRequest->initialize($this->getContext());
        $lw = new \Agavi\Execution\LightweightActionInitContext($this->getContext(), $descriptor->module, $descriptor->action, $descriptor->method, $descriptor->outputType, $initRequest, $controller->getGlobalResponse());
        $action->initialize($lw);
        $this->assertInstanceOf(AgaviAction::class, $action);
        \Sandbox\Modules\Cache\Actions\CacheComplexAction::configure(true, false, false);
        $request = new AgaviWebRequest();
        $request->initialize($this->getContext());
        $svc = new ValidationService();
        /** @var AgaviAction $action */
        $result = $svc->validate($action, $request);
        $this->assertFalse($result->ok);
        $this->assertSame([], $result->getErrors());
        \Sandbox\Modules\Cache\Actions\CacheComplexAction::configure(false, false, false);
    }
}
