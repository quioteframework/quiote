<?php
use Agavi\Testing\AgaviUnitTestCase;
use Nyholm\Psr7\ServerRequest;
use Agavi\Execution\SlotStack;
use Agavi\Middleware\SlotMiddleware;

class SlotDispatcherSlotContentTest extends AgaviUnitTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $controller = $this->getContext()->getController();
        $controller->initializeModule('Cache');
        $controller->createActionInstance('Cache','Cache');
    if(class_exists('Sandbox\\Modules\\Cache\\Actions\\CacheAction')) { \Sandbox\Modules\Cache\Actions\CacheAction::$execCount = 0; }
    }

    public function testDispatchSlotContentReturnsValueObject()
    {
        $req = (new ServerRequest('GET','http://localhost/'))
            ->withAttribute(SlotMiddleware::ATTR, new SlotStack());
        // Strict validation: whitelist slot parameters that will be set
        $ctxReq = $this->getContext()->getRequest();
        if($ctxReq instanceof \Agavi\Request\AgaviWebRequest) { $ctxReq->enforceValidatedParameters(['x']); }
        $dispatcher = $this->getContext()->getSlotDispatcher();
        $sc = $dispatcher->dispatchSlotContent($req, 'Cache','Cache', ['x'=>1], 'html');
        $this->assertInstanceOf(\Agavi\Execution\SlotContent::class, $sc);
        $this->assertSame('Cache', $sc->getModule());
        $this->assertSame('Cache', $sc->getAction());
        $this->assertSame(['x'=>1], $sc->getArguments());
        $this->assertNotSame('', $sc->getContent());
    }
}
