<?php

declare(strict_types=1);

use Nyholm\Psr7\ServerRequest;
use Quiote\Controller\Controller;
use Quiote\Execution\SlotDispatcher;
use Quiote\Execution\SlotStack;
use Quiote\Execution\ViewFactory;
use Quiote\Request\WebRequest;
use Quiote\Testing\UnitTestCase;
use Quiote\View\View;

/** A view factory that refuses to build anything, standing in for a broken view class. */
final class ThrowingSlotViewFactory extends ViewFactory
{
    public int $calls = 0;

    #[\Override]
    public function create(
        string $viewModule,
        string $viewName,
        string $actionModule,
        string $actionName,
        string $outputType,
        ?WebRequest $request,
        array $actionAttributeSnapshot,
        ?object $validationManager = null,
    ): ?View {
        ++$this->calls;

        throw new RuntimeException('view factory exploded');
    }
}

/** A view factory that builds nothing without failing, leaving the fallback to the controller. */
final class NullReturningSlotViewFactory extends ViewFactory
{
    public int $calls = 0;

    #[\Override]
    public function create(
        string $viewModule,
        string $viewName,
        string $actionModule,
        string $actionName,
        string $outputType,
        ?WebRequest $request,
        array $actionAttributeSnapshot,
        ?object $validationManager = null,
    ): ?View {
        ++$this->calls;

        return null;
    }
}

/**
 * What a slot does when its view cannot be built.
 *
 * The two outcomes are deliberately different. A factory that *throws* is a
 * broken view class, and the slot rethrows so the error-handling middleware
 * decides what the client sees. A factory that merely returns null is "I have
 * no view for this", which falls back to the controller and, failing that, to
 * rendering without a view -- a slot with no view is a normal thing.
 */
final class SlotDispatcherViewFailureTest extends UnitTestCase
{
    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();

        $controller = $this->getContext()->getContainer()->get(Controller::class);
        $controller->initializeModule('Cache');
        $controller->createActionInstance('Cache', 'Cache');
    }

    private function slotRequest(): ServerRequest
    {
        $request = new ServerRequest('GET', 'http://localhost/slot-view-failure');

        return $request->withAttribute(SlotStack::class, new SlotStack());
    }

    private function dispatcher(?ViewFactory $viewFactory = null): SlotDispatcher
    {
        return new SlotDispatcher(
            $this->getContext()->getContainer()->get(Controller::class),
            viewFactory: $viewFactory,
        );
    }

    /**
     * A view class that blows up on construction is a bug in the application,
     * not an empty slot -- swallowing it would render a silent gap where the
     * content should be and leave nothing to debug from.
     */
    public function testAViewFactoryThatThrowsPropagatesRatherThanRenderingAnEmptySlot(): void
    {
        $factory = new ThrowingSlotViewFactory($this->getContext()->getContainer()->get(Controller::class));

        try {
            $this->dispatcher($factory)->dispatch($this->slotRequest(), 'Cache', 'Cache');
            $this->fail('a failing view factory must not produce a silently empty slot');
        } catch (\Throwable $e) {
            $this->assertSame('view factory exploded', $e->getMessage());
        }

        $this->assertSame(1, $factory->calls, 'the factory was the thing that failed');
    }

    /**
     * Returning null is not a failure: the dispatcher falls back to the
     * controller's own view creation, and a slot still renders.
     */
    public function testAViewFactoryReturningNullFallsBackRatherThanFailing(): void
    {
        $factory = new NullReturningSlotViewFactory($this->getContext()->getContainer()->get(Controller::class));

        $content = $this->dispatcher($factory)->dispatch($this->slotRequest(), 'Cache', 'Cache');

        $this->assertSame(1, $factory->calls);
        $this->assertStringContainsString('CACHE_', $content, 'the controller fallback still produced the slot');
    }

    /** The default factory is used when none is injected, so the seam is opt-in. */
    public function testTheDefaultViewFactoryRendersTheSlotNormally(): void
    {
        $content = $this->dispatcher()->dispatch($this->slotRequest(), 'Cache', 'Cache');

        $this->assertStringContainsString('CACHE_', $content);
    }
}
