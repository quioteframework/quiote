<?php
use Quiote\Testing\UnitTestCase;
use Quiote\View\View;
use Quiote\Request\WebRequest;
use Nyholm\Psr7\ServerRequest as NyholmServerRequest;

class RenderSlotHelperTest extends UnitTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
    // Enable simple no-container slot execution path
    putenv('QUIOTE_SLOT_SIMPLE_NO_CONTAINER=1');
        $this->getContext()->getController()->initializeModule('Cache');
        $this->getContext()->getController()->createActionInstance('Cache','Cache');
    }

    private function makeView(): View
    {
    $view = new class extends View { public function execute(WebRequest $request) { return null; } };
        $controller = $this->getContext()->getController();
    $descriptor = new \Quiote\Execution\ActionDescriptor('Cache','Cache','GET', strtolower($controller->getOutputType()->getName()), true);
        $view = new class extends View { public function execute(WebRequest $request) { return null; } };
        $vic = new \Quiote\Execution\ImmutableViewInitContext($this->getContext(), 'Cache','CacheSuccess', strtolower($controller->getOutputType()->getName()), 'Cache','Cache', [], $controller->getGlobalResponse());
        $view->initialize($vic);
        // Ensure context request has the SlotStack attribute for slot execution
        // Since WebRequest extends ServerRequest, we can add attributes to it
        $ctxReq = $this->getContext()->getRequest();
        if ($ctxReq && !$ctxReq->getAttribute(\Quiote\Execution\SlotStack::class)) {
            $ctxReq = $ctxReq->withAttribute(\Quiote\Execution\SlotStack::class, new \Quiote\Execution\SlotStack());
            $this->getContext()->setRequest($ctxReq);
        } elseif (!$ctxReq) {
            $req = new NyholmServerRequest('GET','http://localhost/test');
            $req = $req->withAttribute(\Quiote\Execution\SlotStack::class, new \Quiote\Execution\SlotStack());
            $this->getContext()->setRequest($req);
        }
        // Strict validation: pre-whitelist potential slot argument names used in tests
        try {
            $ctxReq = $this->getContext()->getRequest();
            if($ctxReq instanceof WebRequest) {
                $this->getContext()->setRequest($ctxReq->enforceValidatedParameters(['foo','alpha','x','fail']));
            }
        } catch(\Throwable) {}
        return $view;
    }

    public function testRenderSlotReturnsStringContent()
    {
        $view = $this->makeView();
        $content = $view->renderSlot('Cache','Cache');
        $this->assertIsString($content);
        $this->assertStringContainsString('CACHE_', $content);
    }

    public function testRenderSlotWithArguments()
    {
        $view = $this->makeView();
        $content = $view->renderSlot('Cache','Cache', ['foo' => 'bar']);
        $this->assertStringContainsString('CACHE_', $content); // baseline; specific arg impact verified in dedicated tests if needed
    }

    public function testCreateSlotContentDirectAPI()
    {
        $view = $this->makeView();
        $slotContent = $view->createSlotContent('Cache','Cache', ['alpha' => 'beta']);
        $this->assertSame('Cache', $slotContent->getModule());
        $this->assertSame('Cache', $slotContent->getAction());
        $this->assertSame(['alpha' => 'beta'], $slotContent->getArguments());
        $this->assertStringContainsString('CACHE_', $slotContent->getContent());
    }
}
