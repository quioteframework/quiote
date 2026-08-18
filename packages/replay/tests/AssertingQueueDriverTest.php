<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use Quiote\Queue\JobPayload;
use Quiote\Replay\Cassette\EffectKind;
use Quiote\Replay\Replay\AssertingQueueDriver;
use Quiote\Replay\Replay\EffectLedger;

final class AssertingQueueDriverTest extends TestCase
{
    public function testPushRecordsTheJobForLaterInspection(): void
    {
        $driver = new AssertingQueueDriver();

        $driver->push(new JobPayload('App\\Job\\Send', ['id' => 42]));

        $this->assertCount(1, $driver->pushedJobs());
        $this->assertSame('App\\Job\\Send', $driver->pushedJobs()[0]->jobClass);
    }

    public function testPushedJobsPreservesOrderAcrossMultiplePushes(): void
    {
        $driver = new AssertingQueueDriver();

        $driver->push(new JobPayload('App\\Job\\A'));
        $driver->push(new JobPayload('App\\Job\\B'));

        $jobs = $driver->pushedJobs();
        $this->assertSame('App\\Job\\A', $jobs[0]->jobClass);
        $this->assertSame('App\\Job\\B', $jobs[1]->jobClass);
    }

    public function testWasJobPushedMatchesByClassAlone(): void
    {
        $driver = new AssertingQueueDriver();
        $driver->push(new JobPayload('App\\Job\\Send', ['id' => 42]));

        $this->assertTrue($driver->wasJobPushed('App\\Job\\Send'));
        $this->assertFalse($driver->wasJobPushed('App\\Job\\Other'));
    }

    public function testWasJobPushedMatchesByClassAndParamsWhenGiven(): void
    {
        $driver = new AssertingQueueDriver();
        $driver->push(new JobPayload('App\\Job\\Send', ['id' => 42]));

        $this->assertTrue($driver->wasJobPushed('App\\Job\\Send', ['id' => 42]));
        $this->assertFalse($driver->wasJobPushed('App\\Job\\Send', ['id' => 99]));
    }

    public function testNeverThrowsAndHasNothingPushedInitially(): void
    {
        $driver = new AssertingQueueDriver();

        $this->assertSame([], $driver->pushedJobs());
        $this->assertFalse($driver->wasJobPushed('App\\Job\\Anything'));
    }

    public function testAppendsToAnOptionalLedgerUsingTheSameFingerprintSchemeAsTheRecorder(): void
    {
        $ledger = new EffectLedger();
        $driver = new AssertingQueueDriver($ledger);

        $driver->push(new JobPayload('App\\Job\\Send', ['id' => 42]));

        $all = $ledger->all();
        $this->assertCount(1, $all);
        $this->assertSame(EffectKind::Queue, $all[0]->kind);
        $this->assertSame('App\\Job\\Send', $all[0]->call['jobClass']);
    }

    public function testWorksCorrectlyWithNoLedgerAtAll(): void
    {
        $driver = new AssertingQueueDriver(null);

        $driver->push(new JobPayload('App\\Job\\Send'));

        $this->assertTrue($driver->wasJobPushed('App\\Job\\Send'));
    }
}
