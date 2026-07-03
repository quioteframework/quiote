<?php

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\After;
use PHPUnit\Framework\Attributes\Before;
use Quiote\Event\Event;
use Quiote\Event\Events;
use Quiote\Event\StoppableEvent;

/**
 * Covers the PSR-14 dispatcher/provider and the Events facade: priority
 * ordering, stoppable propagation, base-class/interface listener matching,
 * hasListeners() gating, and the fail-loud vs fail-safe (emit) distinction.
 */
class EventDispatcherTest extends TestCase
{
    #[Before]
    #[After]
    public function resetEvents(): void
    {
        Events::reset();
    }

    public function testListenerReceivesDispatchedEventAndDispatchReturnsIt(): void
    {
        $seen = null;
        Events::listen(SampleEvent::class, function (SampleEvent $e) use (&$seen): void {
            $seen = $e->value;
        });

        $event = new SampleEvent('hello');
        $returned = Events::dispatch($event);

        $this->assertSame('hello', $seen);
        $this->assertSame($event, $returned);
    }

    public function testListenersRunInPriorityOrderThenRegistrationOrder(): void
    {
        $order = [];
        Events::listen(SampleEvent::class, function () use (&$order): void { $order[] = 'low'; }, -10);
        Events::listen(SampleEvent::class, function () use (&$order): void { $order[] = 'high'; }, 100);
        Events::listen(SampleEvent::class, function () use (&$order): void { $order[] = 'mid-a'; }, 0);
        Events::listen(SampleEvent::class, function () use (&$order): void { $order[] = 'mid-b'; }, 0);

        Events::dispatch(new SampleEvent('x'));

        $this->assertSame(['high', 'mid-a', 'mid-b', 'low'], $order);
    }

    public function testStoppableEventHaltsPropagation(): void
    {
        $order = [];
        Events::listen(SampleStoppableEvent::class, function (SampleStoppableEvent $e) use (&$order): void {
            $order[] = 'first';
            $e->stopPropagation();
        }, 10);
        Events::listen(SampleStoppableEvent::class, function () use (&$order): void {
            $order[] = 'second';
        }, 0);

        Events::dispatch(new SampleStoppableEvent());

        $this->assertSame(['first'], $order);
    }

    public function testAlreadyStoppedEventInvokesNoListeners(): void
    {
        $called = false;
        Events::listen(SampleStoppableEvent::class, function () use (&$called): void { $called = true; });

        $event = new SampleStoppableEvent();
        $event->stopPropagation();
        Events::dispatch($event);

        $this->assertFalse($called);
    }

    public function testListenerOnBaseClassSeesSubclassEvent(): void
    {
        $seen = false;
        Events::listen(Event::class, function () use (&$seen): void { $seen = true; });

        Events::dispatch(new SampleEvent('x'));

        $this->assertTrue($seen);
    }

    public function testListenerOnInterfaceSeesImplementingEvent(): void
    {
        $seen = false;
        Events::listen(SampleInterface::class, function () use (&$seen): void { $seen = true; });

        Events::dispatch(new SampleInterfaceEvent());

        $this->assertTrue($seen);
    }

    public function testHasListenersReflectsRegistration(): void
    {
        $this->assertFalse(Events::hasListeners(SampleEvent::class));
        Events::listen(SampleEvent::class, fn() => null);
        $this->assertTrue(Events::hasListeners(SampleEvent::class));
    }

    public function testHasListenersMatchesViaBaseClass(): void
    {
        Events::listen(Event::class, fn() => null);
        $this->assertTrue(Events::hasListeners(SampleEvent::class));
    }

    public function testDispatchPropagatesListenerException(): void
    {
        Events::listen(SampleEvent::class, function (): void {
            throw new \RuntimeException('boom');
        });

        $this->expectException(\RuntimeException::class);
        Events::dispatch(new SampleEvent('x'));
    }

    public function testEmitSwallowsListenerExceptionAndReturnsEvent(): void
    {
        Events::listen(SampleEvent::class, function (): void {
            throw new \RuntimeException('boom');
        });

        $event = new SampleEvent('x');
        $returned = Events::emit($event);

        $this->assertSame($event, $returned);
    }

    public function testEmitWithoutListenersDoesNotThrowAndReturnsEvent(): void
    {
        $event = new SampleEvent('x');
        $this->assertSame($event, Events::emit($event));
    }

    public function testResetClearsListeners(): void
    {
        Events::listen(SampleEvent::class, fn() => null);
        Events::reset();
        $this->assertFalse(Events::hasListeners(SampleEvent::class));
    }
}

final class SampleEvent extends Event
{
    public function __construct(public readonly string $value) {}
}

final class SampleStoppableEvent extends StoppableEvent
{
}

interface SampleInterface
{
}

final class SampleInterfaceEvent extends Event implements SampleInterface
{
}
