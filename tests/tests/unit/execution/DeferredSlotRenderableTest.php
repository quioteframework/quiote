<?php

declare(strict_types=1);

use Quiote\Execution\DeferredSlotRenderable;
use Quiote\Execution\SlotStack;
use Quiote\Request\RequestState;
use Quiote\Request\WebRequest;
use Quiote\Testing\UnitTestCase;

/**
 * The lazy half of the slot API: `View::slot()` hands a template one of these
 * instead of dispatching, so a slot the template never prints costs nothing.
 * Printing it is what runs the action, and the result is memoized so a
 * template stringifying the same slot twice does not dispatch twice.
 */
final class DeferredSlotRenderableTest extends UnitTestCase
{
    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();

        $controller = $this->getContext()->getContainer()->get(\Quiote\Controller\Controller::class);
        $controller->initializeModule('Cache');

        // Slot dispatch reads the stack off the parent request to detect recursion.
        $request = $this->getContext()->getContainer()->get(WebRequest::class);
        if (!$request->getAttribute(SlotStack::class)) {
            $this->getContext()->getContainer()->get(RequestState::class)
                ->publish($request->withAttribute(SlotStack::class, new SlotStack()));
        }
    }

    /** @param array<string, mixed> $parameters */
    private function slot(string $module = 'Cache', string $action = 'Cache', array $parameters = [], ?string $outputType = null): DeferredSlotRenderable
    {
        return new DeferredSlotRenderable($this->getContext(), $module, $action, $parameters, $outputType);
    }

    // --- describing without dispatching ------------------------------------

    /**
     * The getters report the pending dispatch. If any of them triggered it,
     * inspecting a slot would cost the same as rendering it, and the laziness
     * this class exists for would be gone.
     */
    public function testTheGettersDescribeThePendingDispatchWithoutRunningIt(): void
    {
        $slot = $this->slot('Cache', 'Cache', ['foo' => 'bar'], 'html');

        $this->assertSame('Cache', $slot->getModule());
        $this->assertSame('Cache', $slot->getAction());
        $this->assertSame('html', $slot->getOutputType());
        $this->assertSame(['foo' => 'bar'], $slot->getArguments());

        $this->assertNull(
            (new ReflectionProperty(DeferredSlotRenderable::class, 'rendered'))->getValue($slot),
            'nothing may have been rendered yet',
        );
    }

    /** A null output type means "let dispatch decide", not "html". */
    public function testTheOutputTypeIsNullUntilDispatchPicksOne(): void
    {
        $this->assertNull($this->slot()->getOutputType());
    }

    public function testArgumentsDefaultToNone(): void
    {
        $this->assertSame([], $this->slot()->getArguments());
    }

    // --- dispatching -------------------------------------------------------

    public function testGetContentDispatchesTheSlotAction(): void
    {
        $this->assertStringContainsString('CACHE_', $this->slot()->getContent());
    }

    /** Being Stringable is what makes echoing the slot in a template dispatch it. */
    public function testStringifyingTheSlotDispatchesIt(): void
    {
        $this->assertStringContainsString('CACHE_', (string) $this->slot());
    }

    /**
     * Memoized, so a template that prints the same slot object twice runs the
     * action once -- otherwise a slot in a loop would multiply the work.
     */
    public function testTheContentIsRenderedOnceAndReused(): void
    {
        $slot = $this->slot();

        $first = $slot->getContent();
        $second = $slot->getContent();

        $this->assertSame($first, $second);
        $this->assertSame($first, (string) $slot);
    }

    public function testToArrayDescribesTheSlotAndItsRenderedLength(): void
    {
        $slot = $this->slot('Cache', 'Cache', ['foo' => 'bar'], 'html');

        $described = $slot->toArray();

        $this->assertSame('Cache', $described['module']);
        $this->assertSame('Cache', $described['action']);
        $this->assertSame('html', $described['output_type']);
        $this->assertSame(['foo' => 'bar'], $described['arguments']);
        $this->assertSame(strlen($slot->getContent()), $described['content_length']);
    }

    /** toArray() reports a content length, so unlike the getters it must dispatch. */
    public function testToArrayDispatchesBecauseItReportsALength(): void
    {
        $slot = $this->slot();

        $this->assertGreaterThan(0, $slot->toArray()['content_length']);
    }

    // --- failure -----------------------------------------------------------

    /**
     * A slot that cannot be dispatched rethrows, so the error-handling
     * middleware decides what the client sees rather than the template
     * silently rendering a gap where the slot should have been.
     */
    public function testAFailingSlotRethrowsRatherThanRenderingNothing(): void
    {
        $this->expectException(\Throwable::class);

        $this->slot('NoSuchModule', 'NoSuchAction')->getContent();
    }

    /**
     * Nothing is memoized on failure: memoizing would turn one transient
     * failure into a permanently empty slot for the life of the object.
     */
    public function testAFailedRenderIsNotMemoized(): void
    {
        $slot = $this->slot('NoSuchModule', 'NoSuchAction');

        foreach ([1, 2] as $attempt) {
            try {
                $slot->getContent();
                $this->fail('attempt ' . $attempt . ' should have failed');
            } catch (\Throwable) {
                // expected on both attempts
            }
        }

        $this->assertNull(
            (new ReflectionProperty(DeferredSlotRenderable::class, 'rendered'))->getValue($slot),
            'a failed render must leave nothing memoized',
        );
    }
}
