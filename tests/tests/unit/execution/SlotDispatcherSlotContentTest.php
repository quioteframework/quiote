<?php
use Quiote\Testing\UnitTestCase;
use Nyholm\Psr7\ServerRequest;
use Quiote\Execution\SlotStack;
use Quiote\Middleware\SlotMiddleware;

class SlotDispatcherSlotContentTest extends UnitTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $controller = $this->getContext()->getController();
        $controller->initializeModule('Cache');
        $controller->createActionInstance('Cache','Cache');
    if(class_exists(\Sandbox\Modules\Cache\Actions\CacheAction::class)) { \Sandbox\Modules\Cache\Actions\CacheAction::$execCount = 0; }
    }

    public function testDispatchSlotContentReturnsValueObject()
    {
        $req = (new ServerRequest('GET','http://localhost/'))
            ->withAttribute(SlotMiddleware::ATTR, new SlotStack());
        // Strict validation: whitelist slot parameters that will be set
        $ctxReq = $this->getContext()->getRequest();
        if($ctxReq instanceof \Quiote\Request\WebRequest) { $ctxReq->enforceValidatedParameters(['x']); }
        $dispatcher = $this->getContext()->getSlotDispatcher();
        $sc = $dispatcher->dispatchSlotContent($req, 'Cache','Cache', ['x'=>1], 'html');
        $this->assertInstanceOf(\Quiote\Execution\SlotContent::class, $sc);
        $this->assertSame('Cache', $sc->getModule());
        $this->assertSame('Cache', $sc->getAction());
        $this->assertSame(['x'=>1], $sc->getArguments());
        $this->assertNotSame('', $sc->getContent());
    }
}
