<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use Quiote\DI\Container;
use Quiote\Scheduler\InlineCallbackTask;

final class InlineCallbackTaskTest extends TestCase
{
    public function testRunInvokesTheCallbackExactlyOnceWithTheContainer(): void
    {
        $calls = [];
        $task = new InlineCallbackTask(function (Container $container) use (&$calls): void {
            $calls[] = $container;
        });

        $container = new Container();
        $task->run($container);

        $this->assertCount(1, $calls);
        $this->assertSame($container, $calls[0]);
    }

    public function testLabelIsInlineCallback(): void
    {
        $task = new InlineCallbackTask(static function (): void {});

        $this->assertSame('inline callback', $task->label());
    }

    public function testRunLetsCallbackExceptionsPropagate(): void
    {
        $task = new InlineCallbackTask(static function (): void {
            throw new \RuntimeException('boom');
        });

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('boom');
        $task->run(new Container());
    }
}
