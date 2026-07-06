<?php
use Quiote\Testing\UnitTestCase;
use Nyholm\Psr7\ServerRequest;
use Quiote\Execution\SlotStack;
use Quiote\Middleware\SlotMiddleware;
use Quiote\Execution\ActionExecutionContext;

class SlotDispatcherContextTest extends UnitTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        putenv('QUIOTE_SLOT_SIMPLE_NO_CONTAINER=1');
        $this->getContext()->getController()->createActionInstance('Cache','Cache');
    }
    protected function tearDown(): void
    {
        putenv('QUIOTE_SLOT_SIMPLE_NO_CONTAINER');
        parent::tearDown();
    }

    public function testDispatchWithContextSimple()
    {
        $req = (new ServerRequest('GET','http://localhost/'))
            ->withAttribute(SlotMiddleware::ATTR, new SlotStack());
        $dispatcher = $this->getContext()->getSlotDispatcher();
        $ctx = $dispatcher->dispatchWithContext($req,'Cache','Cache',[], 'Html');
        $this->assertInstanceOf(ActionExecutionContext::class, $ctx);
        $this->assertSame('Cache', $ctx->module);
        $this->assertSame('Cache', $ctx->actionName);
        $this->assertSame('Html', ucfirst($ctx->outputType));
        $this->assertNotEmpty($ctx->content);
        $this->assertStringContainsString('CACHE_', $ctx->content);
    }
}
