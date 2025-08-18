<?php
use Agavi\Testing\AgaviUnitTestCase;
use Agavi\Execution\ActionDescriptor;
use Agavi\Execution\ActionExecutor;
use Agavi\Execution\ExecutionState;
use Agavi\Request\AgaviRequestDataHolder;

class ActionExecutorDirectMethodValidationTest extends AgaviUnitTestCase
{
    public function testPostValidationFailureDirect()
    {
    $this->markTestSkipped('Obsolete: validation now handled exclusively in ValidationMiddleware before ActionExecutor.');
        $controller = $this->getContext()->getController();
        // Build descriptor (non-simple) with method Post
        $desc = ActionDescriptor::fromController($controller, 'Method','MethodHttp','Post','html');
        $this->assertFalse($desc->isSimple, 'Fixture should be non-simple');
    $rd = new AgaviRequestDataHolder();
    // Set parameter using parameter holder API (AgaviParameterHolder::setParameter exists via inheritance)
    $rd->setParameter('fail', '1');
    $this->assertTrue($rd->hasParameter('fail'), 'Pre-flight: RD should report fail parameter');
        \Sandbox\Modules\Method\Actions\MethodHttpAction::ensureReset();
        $executor = new ActionExecutor($controller);
        $state = new ExecutionState();
        $ctx = $executor->execute($desc, $rd, $state);
    $action = $ctx->action;
    $dbg = [];
    if(is_callable([$action,'getAttributes'])) { try { $dbg = $action->getAttributes(); } catch(\Throwable) { $dbg = []; } }
    $this->assertTrue($state->validationPerformed, 'Validation should have run. Debug=' . json_encode($dbg));
    $this->assertFalse($state->validationSucceeded, 'Validation should fail with fail=1. Debug=' . json_encode($dbg));
        $this->assertSame('MethodHttpPostError', $state->viewName, 'Error view should be selected');
        $this->assertStringContainsString('POST_ERROR', $ctx->content, 'Expected POST_ERROR content');
        $this->assertSame('handlePostError', \Sandbox\Modules\Method\Actions\MethodHttpAction::$last);
    }
}
