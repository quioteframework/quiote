<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use Quiote\Replay\Adapter\Eloquent\EloquentMinimalEventDispatcher;

final class EloquentMinimalEventDispatcherTest extends TestCase
{
    public function testListenAndDispatchSpreadsThePayloadAsListenerArguments(): void
    {
        $dispatcher = new EloquentMinimalEventDispatcher();
        $received = null;
        $dispatcher->listen('my.event', function ($payload) use (&$received) {
            $received = $payload;
        });

        $dispatcher->dispatch('my.event', ['payload']);

        $this->assertSame('payload', $received);
    }

    public function testDispatchWithAnObjectEventPassesTheObjectItself(): void
    {
        $dispatcher = new EloquentMinimalEventDispatcher();
        $event = new stdClass();
        $received = null;
        $dispatcher->listen(stdClass::class, function ($e) use (&$received) {
            $received = $e;
        });

        $dispatcher->dispatch($event);

        $this->assertSame($event, $received);
    }

    public function testHasListenersReflectsRegisteredListeners(): void
    {
        $dispatcher = new EloquentMinimalEventDispatcher();

        $this->assertFalse($dispatcher->hasListeners('my.event'));

        $dispatcher->listen('my.event', fn() => null);

        $this->assertTrue($dispatcher->hasListeners('my.event'));
    }

    public function testDispatchWithNoListenersReturnsAnEmptyArray(): void
    {
        $dispatcher = new EloquentMinimalEventDispatcher();

        $this->assertSame([], $dispatcher->dispatch('my.event'));
    }

    public function testUntilReturnsTheFirstNonNullListenerResponse(): void
    {
        $dispatcher = new EloquentMinimalEventDispatcher();
        $dispatcher->listen('my.event', fn() => null);
        $dispatcher->listen('my.event', fn() => 'answer');

        $this->assertSame('answer', $dispatcher->until('my.event'));
    }

    public function testForgetRemovesListenersForThatEvent(): void
    {
        $dispatcher = new EloquentMinimalEventDispatcher();
        $dispatcher->listen('my.event', fn() => 'x');

        $dispatcher->forget('my.event');

        $this->assertFalse($dispatcher->hasListeners('my.event'));
    }

    public function testSubscribeThrows(): void
    {
        $dispatcher = new EloquentMinimalEventDispatcher();

        $this->expectException(\RuntimeException::class);

        $dispatcher->subscribe(new stdClass());
    }
}
