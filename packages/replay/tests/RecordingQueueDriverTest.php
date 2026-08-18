<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use Quiote\Queue\JobPayload;
use Quiote\Queue\QueueDriverInterface;
use Quiote\Replay\Cassette\EffectKind;
use Quiote\Replay\Queue\RecordingQueueDriver;
use Quiote\Replay\Replay\EffectLedger;

final class RecordingQueueDriverFakeDriver implements QueueDriverInterface
{
    /** @var list<JobPayload> */
    public array $pushed = [];

    public function __construct(private readonly ?\Throwable $throws = null)
    {
    }

    #[\Override]
    public function push(JobPayload $payload): void
    {
        if ($this->throws !== null) {
            throw $this->throws;
        }
        $this->pushed[] = $payload;
    }
}

final class RecordingQueueDriverTest extends TestCase
{
    public function testPushDelegatesToTheRealDriver(): void
    {
        $inner = new RecordingQueueDriverFakeDriver();
        $driver = new RecordingQueueDriver($inner, new EffectLedger());

        $driver->push(new JobPayload('App\\Job\\Send', ['id' => 42]));

        $this->assertCount(1, $inner->pushed);
        $this->assertSame('App\\Job\\Send', $inner->pushed[0]->jobClass);
        $this->assertSame(['id' => 42], $inner->pushed[0]->params);
    }

    public function testPushRecordsExactlyOneQueueEffect(): void
    {
        $ledger = new EffectLedger();
        $driver = new RecordingQueueDriver(new RecordingQueueDriverFakeDriver(), $ledger);

        $driver->push(new JobPayload('App\\Job\\Send', ['id' => 42]));

        $all = $ledger->all();
        $this->assertCount(1, $all);
        $this->assertSame(EffectKind::Queue, $all[0]->kind);
        $this->assertSame('App\\Job\\Send', $all[0]->call['jobClass']);
        $this->assertSame(['id' => 42], $all[0]->call['params']);
        $this->assertNull($all[0]->result);
    }

    public function testTwoPushesWithDifferentParamsProduceTwoDistinctOrderedEffects(): void
    {
        $ledger = new EffectLedger();
        $driver = new RecordingQueueDriver(new RecordingQueueDriverFakeDriver(), $ledger);

        $driver->push(new JobPayload('App\\Job\\Send', ['id' => 1]));
        $driver->push(new JobPayload('App\\Job\\Send', ['id' => 2]));

        $all = $ledger->all();
        $this->assertCount(2, $all);
        $this->assertNotSame($all[0]->fingerprint, $all[1]->fingerprint);
        $this->assertSame(['id' => 1], $all[0]->call['params']);
        $this->assertSame(['id' => 2], $all[1]->call['params']);
    }

    public function testTwoPushesOfAnIdenticalJobStillProduceTwoSeparateOrderedEffects(): void
    {
        $ledger = new EffectLedger();
        $driver = new RecordingQueueDriver(new RecordingQueueDriverFakeDriver(), $ledger);

        $driver->push(new JobPayload('App\\Job\\Send', ['id' => 1]));
        $driver->push(new JobPayload('App\\Job\\Send', ['id' => 1]));

        $all = $ledger->all();
        $this->assertCount(2, $all);
        $this->assertSame($all[0]->fingerprint, $all[1]->fingerprint);
        $this->assertSame(0, $all[0]->seq);
        $this->assertSame(1, $all[1]->seq);

        $first = $ledger->match(EffectKind::Queue, $all[0]->fingerprint);
        $second = $ledger->match(EffectKind::Queue, $all[0]->fingerprint);
        $this->assertNotNull($first);
        $this->assertNotNull($second);
        $this->assertSame(0, $first->seq);
        $this->assertSame(1, $second->seq);
    }

    public function testAFailingRealPushDoesNotRecordAnEffectAndPropagates(): void
    {
        $ledger = new EffectLedger();
        $driver = new RecordingQueueDriver(
            new RecordingQueueDriverFakeDriver(new RuntimeException('backend unavailable')),
            $ledger,
        );

        try {
            $driver->push(new JobPayload('App\\Job\\Send'));
            $this->fail('expected the real driver exception to propagate');
        } catch (RuntimeException $e) {
            $this->assertSame('backend unavailable', $e->getMessage());
        }

        $this->assertSame([], $ledger->all());
    }
}
