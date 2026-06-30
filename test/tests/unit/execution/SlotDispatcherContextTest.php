<?php
use Agavi\Testing\AgaviUnitTestCase;
use Nyholm\Psr7\ServerRequest;
use Agavi\Execution\SlotStack;
use Agavi\Middleware\SlotMiddleware;
use Agavi\Execution\ActionExecutionContext;

class SlotDispatcherContextTest extends AgaviUnitTestCase
{
    public static function setUpBeforeClass(): void
    {
        set_error_handler(function($errno,$errstr,$errfile,$errline){
            fwrite(STDERR, "ERR[$errno] $errstr at $errfile:$errline\n");
            return false; // allow normal handling too
        });
        register_shutdown_function(function(): void{
            $e = error_get_last();
            if($e) { fwrite(STDERR, "SHUTDOWN: ".json_encode($e)."\n"); }
        });
    }
    protected function setUp(): void
    {
        parent::setUp();
        putenv('AGAVI_SLOT_SIMPLE_NO_CONTAINER=1');
        $this->getContext()->getController()->createActionInstance('Cache','Cache');
    }
    protected function tearDown(): void
    {
        putenv('AGAVI_SLOT_SIMPLE_NO_CONTAINER');
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
