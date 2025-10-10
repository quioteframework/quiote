<?php
use Agavi\Testing\AgaviUnitTestCase;
use Agavi\View\AgaviView;
use Agavi\Request\AgaviWebRequest;
use Nyholm\Psr7\ServerRequest as NyholmServerRequest;

class RenderSlotHelperTest extends AgaviUnitTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
    // Enable simple no-container slot execution path
    putenv('AGAVI_SLOT_SIMPLE_NO_CONTAINER=1');
        $this->getContext()->getController()->initializeModule('Cache');
        $this->getContext()->getController()->createActionInstance('Cache','Cache');
    }

    private function makeView(): AgaviView
    {
    $view = new class extends AgaviView { public function execute(AgaviWebRequest $request) { return null; } };
        $controller = $this->getContext()->getController();
    $descriptor = new \Agavi\Execution\ActionDescriptor('Cache','Cache','GET', strtolower($controller->getOutputType()->getName()), true);
        $view = new class extends AgaviView { public function execute(AgaviWebRequest $request) { return null; } };
        $vic = new \Agavi\Execution\ImmutableViewInitContext($this->getContext(), 'Cache','CacheSuccess', strtolower($controller->getOutputType()->getName()), 'Cache','Cache', [], $controller->getGlobalResponse());
        $view->initialize($vic);
        // Ensure context has a current PSR request so createSlotContent fast path works
        if(!method_exists($this->getContext(), 'getCurrentPsrRequest') || !$this->getContext()->getCurrentPsrRequest()) {
            $req = new NyholmServerRequest('GET','http://localhost/test');
            $req = $req->withAttribute(\Agavi\Execution\SlotStack::class, new \Agavi\Execution\SlotStack());
            // Hack: set via reflection (Context doesn't expose public setter)
            $ref = new ReflectionClass($this->getContext());
            if($ref->hasProperty('currentPsrRequest')) {
                $p = $ref->getProperty('currentPsrRequest');
                $p->setAccessible(true);
                $p->setValue($this->getContext(), $req);
            }
        }
        // Strict validation: pre-whitelist potential slot argument names used in tests
        try {
            $ctxReq = $this->getContext()->getRequest();
            if($ctxReq instanceof AgaviWebRequest) {
                $ctxReq->enforceValidatedParameters(['foo','alpha','x','fail']);
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
