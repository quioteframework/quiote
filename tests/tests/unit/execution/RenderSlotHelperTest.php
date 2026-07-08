<?php
use Quiote\Testing\UnitTestCase;
use Quiote\View\View;
use Quiote\Request\WebRequest;

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
    $view = new class extends View { public function execute(WebRequest $request): mixed { return null; } };
        $controller = $this->getContext()->getController();
    $descriptor = new \Quiote\Execution\ActionDescriptor('Cache','Cache','GET', strtolower($controller->getOutputType()->getName()), true);
        $view = new class extends View { public function execute(WebRequest $request): mixed { return null; } };
        $vic = new \Quiote\Execution\ImmutableViewInitContext($this->getContext(), 'Cache','CacheSuccess', strtolower($controller->getOutputType()->getName()), 'Cache','Cache', [], $controller->getGlobalResponse());
        $view->initialize($vic);
        // Ensure context request has the SlotStack attribute for slot execution.
        // Context::getRequest() always returns a WebRequest instance (lazily
        // re-initialized when null), so no null-guard is needed here.
        $ctxReq = $this->getContext()->getRequest();
        if (!$ctxReq->getAttribute(\Quiote\Execution\SlotStack::class)) {
            $ctxReq = $ctxReq->withAttribute(\Quiote\Execution\SlotStack::class, new \Quiote\Execution\SlotStack());
            $this->getContext()->setRequest($ctxReq);
        }
        // Strict validation: pre-whitelist potential slot argument names used in tests
        try {
            $ctxReq = $this->getContext()->getRequest();
            $this->getContext()->setRequest($ctxReq->enforceValidatedParameters(['foo','alpha','x','fail']));
        } catch(\Throwable) {}
        return $view;
    }

    public function testRenderSlotReturnsStringContent(): void
    {
        $view = $this->makeView();
        $content = $view->renderSlot('Cache','Cache');
        $this->assertNotSame('', $content, 'renderSlot() must produce non-empty content');
        $this->assertStringContainsString('CACHE_', $content);
    }

    public function testRenderSlotWithArguments(): void
    {
        $view = $this->makeView();
        $content = $view->renderSlot('Cache','Cache', ['foo' => 'bar']);
        $this->assertStringContainsString('CACHE_', $content); // baseline; specific arg impact verified in dedicated tests if needed
    }

    public function testCreateSlotContentDirectAPI(): void
    {
        $view = $this->makeView();
        $slotContent = $view->createSlotContent('Cache','Cache', ['alpha' => 'beta']);
        // createSlotContent() is typed to the SlotRenderable marker interface (only
        // getContent() guaranteed); both concrete implementations it can return
        // (SlotContent, DeferredSlotRenderable) additionally expose this same
        // module/action/arguments metadata API, so narrow before calling it.
        $this->assertTrue(
            $slotContent instanceof \Quiote\Execution\SlotContent || $slotContent instanceof \Quiote\Execution\DeferredSlotRenderable,
            'createSlotContent() should return SlotContent or DeferredSlotRenderable'
        );
        $this->assertSame('Cache', $slotContent->getModule());
        $this->assertSame('Cache', $slotContent->getAction());
        $this->assertSame(['alpha' => 'beta'], $slotContent->getArguments());
        $this->assertStringContainsString('CACHE_', $slotContent->getContent());
    }
}
