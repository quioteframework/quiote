<?php
use Quiote\Testing\UnitTestCase;
use Nyholm\Psr7\ServerRequest;
use Quiote\Execution\SlotStack;
use Quiote\Middleware\SlotMiddleware;

class SlotExecutionContextTest extends UnitTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
    putenv('QUIOTE_SLOT_SIMPLE_NO_CONTAINER=1');
    putenv('QUIOTE_SLOT_NO_CONTAINER_ALL=1');
    }
    protected function tearDown(): void
    {
    putenv('QUIOTE_SLOT_SIMPLE_NO_CONTAINER');
    putenv('QUIOTE_SLOT_NO_CONTAINER_ALL');
        parent::tearDown();
    }

    public function testSimpleSlotReturnsContext()
    {
        $parent = (new ServerRequest('GET','http://localhost/'))
            ->withAttribute(SlotMiddleware::ATTR, new SlotStack());
        $ctx = $this->getContext();
        $dispatcher = $ctx->getSlotDispatcher();
    \Sandbox\Modules\Cache\Actions\CacheComplexAction::configure(false,false,false);
    $slotCtx = $dispatcher->dispatchSlotContext($parent,'Cache','CacheComplex');
    $this->assertSame('Cache', $slotCtx->module);
    $this->assertSame('CacheComplex', $slotCtx->actionName);
    $this->assertIsString($slotCtx->content);
    $this->assertNotSame('', $slotCtx->content);
    }
}
