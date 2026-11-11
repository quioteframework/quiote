<?php

declare(strict_types=1);

use Nyholm\Psr7\ServerRequest;
use Quiote\Controller\Controller;
use Quiote\Exception\ClassNotFoundException;
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
    ): View {
        ++$this->calls;

        throw new RuntimeException('view factory exploded');
    }
}

/** Builds the requested slot's view under a different name, as a misconfigured view name would. */
final class RenamingSlotViewFactory extends ViewFactory
{
    public function __construct(Controller $controller, private readonly string $viewName)
    {
        parent::__construct($controller);
    }

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
    ): View {
        return parent::create($viewModule, $this->viewName, $actionModule, $actionName, $outputType, $request, $actionAttributeSnapshot);
    }
}

/**
 * What a slot does when its view cannot be built.
 *
 * A slot whose action names a view that cannot be built fails, the same as a
 * page does: the exception reaches the error-handling middleware, which decides
 * what the client sees, instead of the slot rendering as an empty gap. A slot
 * with no view at all is View::NONE, which is not a failure.
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
     * A view name that resolves to no class -- a typo, or a module whose
     * quiote.view.name pattern no longer matches its views -- names the classes
     * it tried rather than rendering an empty slot.
     */
    public function testAMissingViewClassFailsTheSlot(): void
    {
        $factory = new RenamingSlotViewFactory($this->getContext()->getContainer()->get(Controller::class), '__DoesNotExist__');

        $this->expectException(ClassNotFoundException::class);
        $this->expectExceptionMessage('__DoesNotExist__View');
        $this->dispatcher($factory)->dispatch($this->slotRequest(), 'Cache', 'Cache');
    }

    public function testAViewThatFailsToInitializeFailsTheSlot(): void
    {
        $factory = new RenamingSlotViewFactory($this->getContext()->getContainer()->get(Controller::class), 'CacheBrokenInit');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('view initialize exploded');
        $this->dispatcher($factory)->dispatch($this->slotRequest(), 'Cache', 'Cache');
    }

    /** The default factory is used when none is injected, so the seam is opt-in. */
    public function testTheDefaultViewFactoryRendersTheSlotNormally(): void
    {
        $content = $this->dispatcher()->dispatch($this->slotRequest(), 'Cache', 'Cache');

        $this->assertStringContainsString('CACHE_', $content);
    }
}
