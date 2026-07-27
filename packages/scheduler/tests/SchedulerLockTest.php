<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use Quiote\Scheduler\SchedulerLock;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Cache\Psr16Cache;

final class SchedulerLockTest extends TestCase
{
    private function lock(): SchedulerLock
    {
        return new SchedulerLock(new Psr16Cache(new ArrayAdapter()));
    }

    public function testAcquireSucceedsThenFailsWhileHeld(): void
    {
        $lock = $this->lock();

        $this->assertTrue($lock->acquire('task-a', 60));
        $this->assertFalse($lock->acquire('task-a', 60));
    }

    public function testAcquireSucceedsAgainAfterRelease(): void
    {
        $lock = $this->lock();

        $lock->acquire('task-b', 60);
        $lock->release('task-b');

        $this->assertTrue($lock->acquire('task-b', 60));
    }

    public function testReleasingAKeyThatWasNeverAcquiredIsANoOp(): void
    {
        $lock = $this->lock();

        $lock->release('never-acquired');

        $this->assertTrue(true);
    }
}
